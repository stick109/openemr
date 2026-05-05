<?php

/**
 * OpenAIClient
 *
 * Minimal OpenAI HTTP client used by the intake-form ingestion pipeline. It
 * supports two operations:
 *
 *  - Uploading a PDF via the Files API with `purpose=user_data`, returning
 *    the resulting file id; the file id can then be referenced in chat
 *    messages so the model "sees" the document content.
 *  - Calling `chat/completions` with `response_format=json_schema` (strict)
 *    and returning the parsed JSON object the model produced.
 *
 * The client surfaces three typed exceptions callers should care about:
 * {@see OpenAIMissingKeyException} (no `OPENAI_API_KEY`),
 * {@see OpenAIRateLimitException} (HTTP 429), and
 * {@see OpenAISchemaMismatchException} (the model's reply was not JSON or
 * did not satisfy the requested schema). All other errors surface as
 * {@see OpenAIRequestFailedException}.
 *
 * Note: this is a separate, scope-narrow client. The repo already contains
 * `OpenEMR\Services\Agent\Llm\OpenAiResponsesAgentLlmProvider`, but that
 * class targets the `responses` endpoint and is tightly coupled to the
 * Clinical Co-Pilot's request/response shape; it has no PDF Files-API
 * support. Extending it would either bend its public surface out of shape
 * or pull intake-form-specific concerns into the agent module, so a small
 * dedicated client is preferable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\OpenAi;

use JsonException;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAIMissingKeyException;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAIRateLimitException;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAIRequestFailedException;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAISchemaMismatchException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIClient
{
    private const DEFAULT_BASE_URI = 'https://api.openai.com/v1/';
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * @param non-empty-string $baseUri
     * @param int<1, max> $timeoutSeconds
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly OEEnvBag $env,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Upload a PDF to the OpenAI Files API with `purpose=user_data` and
     * return the resulting file id (e.g. "file-abc123"). The returned id
     * can be passed to {@see complete()} via a structured request so the
     * model receives the PDF as input.
     *
     * After a successful upload this method fetches the file's metadata
     * (`GET /v1/files/{id}`) and verifies that the `status` field is
     * `"processed"`. If status is anything else (e.g. `"pending"`,
     * `"error"`) the file is not yet usable at chat-completion time and
     * OpenAI will reject the `file_id` reference with
     * `400: ... got unsupported MIME type 'None'`.
     *
     * Limitation: the `GET /v1/files/{id}` response for `purpose=user_data`
     * files does **not** include a `mime_type` field, so we cannot verify the
     * registered MIME type directly. The only available signal is `status`.
     * If OpenAI ever returns `status=processed` but still misregisters the
     * MIME type, the error will still surface at chat-completion time.
     *
     * @param non-empty-string $filePath Absolute path to a readable PDF
     * @param non-empty-string $filename Display filename to record in OpenAI
     * @return non-empty-string The OpenAI file id
     * @throws OpenAIMissingKeyException
     * @throws OpenAIRateLimitException
     * @throws OpenAIRequestFailedException
     */
    public function uploadPdf(string $filePath, string $filename): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new OpenAIRequestFailedException(
                'Cannot upload PDF to OpenAI: file is not readable.'
            );
        }

        $apiKey = $this->getApiKey();

        $boundary = '----oemr-intake-' . bin2hex(random_bytes(16));
        $multipartBody = $this->buildMultipartBody($boundary, $filePath, $filename);

        try {
            $response = $this->client()->request('POST', $this->url('files'), [
                'auth_bearer' => $apiKey,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ],
                'body' => $multipartBody,
                'timeout' => $this->timeoutSeconds,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('OpenAI file upload transport failure', [
                'exception' => $exception,
            ]);
            throw new OpenAIRequestFailedException(
                'OpenAI file upload failed.',
                $exception
            );
        }

        $this->guardStatusCode($statusCode, $body, 'file upload');

        $payload = $this->decodeJsonObject($body, 'file upload');
        $fileId = $payload['id'] ?? null;
        if (!is_string($fileId) || $fileId === '') {
            throw new OpenAIRequestFailedException('OpenAI file upload returned no file id.');
        }

        $this->verifyUploadedFile($fileId, $apiKey);

        return $fileId;
    }

    /**
     * Fetch file metadata and verify the file reached `processed` status.
     * OpenAI's Files API can accept an upload and return a file id, but
     * asynchronously fail to register the MIME type — the `status` field
     * surfaces this as `"pending"` or `"error"` rather than `"processed"`.
     * Attempting to reference such a file id in `chat/completions` produces
     * `400: ... got unsupported MIME type 'None'`.
     *
     * On a bad status this method:
     *   1. Logs the full metadata response at error level.
     *   2. Best-effort DELETEs the orphan file (swallowed — not worth
     *      masking the primary error).
     *   3. Throws {@see OpenAIRequestFailedException} naming the status.
     *
     * Note: for `purpose=user_data` files the GET response does NOT include
     * a `mime_type` field, so we cannot verify the MIME type directly.
     *
     * @param non-empty-string $fileId
     * @param non-empty-string $apiKey
     */
    private function verifyUploadedFile(string $fileId, string $apiKey): void
    {
        try {
            $metaResponse = $this->client()->request('GET', $this->url('files/' . $fileId), [
                'auth_bearer' => $apiKey,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->timeoutSeconds,
            ]);

            $metaStatus = $metaResponse->getStatusCode();
            $metaBody   = $metaResponse->getContent(false);
        } catch (HttpClientExceptionInterface $exception) {
            // A transport failure here is non-fatal for the upload itself;
            // log and return so the caller can attempt the completions call.
            $this->logger->warning('OpenAI file metadata fetch failed; skipping status check', [
                'file_id'   => $fileId,
                'exception' => $exception,
            ]);
            return;
        }

        if ($metaStatus < 200 || $metaStatus >= 300) {
            $this->logger->warning('OpenAI file metadata returned non-2xx; skipping status check', [
                'file_id'     => $fileId,
                'status_code' => $metaStatus,
            ]);
            return;
        }

        $meta = $this->decodeJsonObject($metaBody, 'file metadata');

        $fileStatus = $meta['status'] ?? null;
        if ($fileStatus === 'processed') {
            // Happy path — file is ready.
            return;
        }

        // File is in a bad state. Log everything OpenAI returned so the
        // operator can see the raw metadata (status, status_details, etc.).
        $this->logger->error('OpenAI uploaded file is not in processed state; aborting ingestion', [
            'file_id'       => $fileId,
            'file_status'   => $fileStatus,
            'file_metadata' => $meta,
        ]);

        // Best-effort cleanup — do not let a DELETE failure mask the real error.
        try {
            $this->client()->request('DELETE', $this->url('files/' . $fileId), [
                'auth_bearer' => $apiKey,
                'headers'     => ['Accept' => 'application/json'],
                'timeout'     => $this->timeoutSeconds,
            ])->getStatusCode();
        } catch (HttpClientExceptionInterface) {
            // Swallow — orphan cleanup is best-effort.
        }

        $registeredStatus = is_string($fileStatus) ? $fileStatus : 'unknown';
        throw new OpenAIRequestFailedException(
            "OpenAI file upload did not reach processed state (status: {$registeredStatus})."
            . ' The file_id has been deleted. Re-uploading the PDF may resolve the issue.'
        );
    }

    /**
     * Send a chat completion with strict JSON-schema response formatting and
     * decode the model's JSON reply. Any uploaded file ids referenced on the
     * request are attached to the user message as `file` content parts.
     *
     * The returned array is an associative JSON object satisfying the request
     * schema. Callers narrow individual fields at use sites; the OpenAI
     * response is fundamentally untyped to PHPStan.
     *
     * @return array<array-key, mixed>
     * @throws OpenAIMissingKeyException
     * @throws OpenAIRateLimitException
     * @throws OpenAISchemaMismatchException
     * @throws OpenAIRequestFailedException
     */
    public function complete(OpenAIStructuredRequest $request): array
    {
        $apiKey = $this->getApiKey();

        $payload = $this->buildChatPayload($request);

        try {
            $response = $this->client()->request('POST', $this->url('chat/completions'), [
                'auth_bearer' => $apiKey,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('OpenAI chat completion transport failure', [
                'exception' => $exception,
                'model' => $request->model,
            ]);
            throw new OpenAIRequestFailedException(
                'OpenAI chat completion failed.',
                $exception
            );
        }

        $this->guardStatusCode($statusCode, $body, 'chat completion');

        $envelope = $this->decodeJsonObject($body, 'chat completion');

        $content = $this->extractMessageContent($envelope);

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->warning('OpenAI returned content that was not JSON', [
                'exception' => $exception,
                'model' => $request->model,
                'schema' => $request->schemaName,
            ]);
            throw new OpenAISchemaMismatchException(
                'OpenAI returned non-JSON content for a structured request.',
                $exception
            );
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new OpenAISchemaMismatchException(
                'OpenAI structured output was not a JSON object.'
            );
        }

        return $decoded;
    }

    /**
     * @return non-empty-string
     */
    private function getApiKey(): string
    {
        $key = trim($this->env->getString('OPENAI_API_KEY'));
        if ($key === '') {
            throw new OpenAIMissingKeyException(
                'OPENAI_API_KEY environment variable is not set.'
            );
        }

        return $key;
    }

    private function client(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create([
            'timeout' => $this->timeoutSeconds,
        ]);
    }

    /**
     * @return non-empty-string
     */
    private function url(string $path): string
    {
        return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChatPayload(OpenAIStructuredRequest $request): array
    {
        $userContent = [
            ['type' => 'text', 'text' => $request->userPrompt],
        ];
        foreach ($request->fileIds as $fileId) {
            $userContent[] = [
                'type' => 'file',
                'file' => ['file_id' => $fileId],
            ];
        }

        return [
            'model' => $request->model,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->schemaName,
                    'strict' => true,
                    'schema' => $request->schema,
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $envelope
     */
    private function extractMessageContent(array $envelope): string
    {
        $choices = $envelope['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw new OpenAISchemaMismatchException('OpenAI response had no choices.');
        }

        $firstChoice = $choices[0] ?? null;
        if (!is_array($firstChoice)) {
            throw new OpenAISchemaMismatchException('OpenAI choice was not an object.');
        }

        $finishReason = $firstChoice['finish_reason'] ?? null;
        if ($finishReason === 'length') {
            throw new OpenAISchemaMismatchException(
                'OpenAI response was truncated by max_tokens.'
            );
        }

        $message = $firstChoice['message'] ?? null;
        if (!is_array($message)) {
            throw new OpenAISchemaMismatchException('OpenAI message missing or malformed.');
        }

        $refusal = $message['refusal'] ?? null;
        if (is_string($refusal) && $refusal !== '') {
            throw new OpenAISchemaMismatchException('OpenAI refused the request.');
        }

        $content = $message['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new OpenAISchemaMismatchException('OpenAI returned empty content.');
        }

        return $content;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonObject(string $body, string $context): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->error('OpenAI response was not JSON', [
                'exception' => $exception,
                'context' => $context,
            ]);
            throw new OpenAIRequestFailedException(
                'OpenAI response was not valid JSON.',
                $exception
            );
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new OpenAIRequestFailedException(
                'OpenAI response was not a JSON object.'
            );
        }

        return $decoded;
    }

    private function guardStatusCode(int $statusCode, string $body, string $context): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $apiError = $this->extractApiErrorMessage($body);

        if ($statusCode === 429) {
            $this->logger->warning('OpenAI rate-limited', [
                'context' => $context,
                'status_code' => $statusCode,
                'api_error' => $apiError,
            ]);
            throw new OpenAIRateLimitException('OpenAI rate-limited the request.');
        }

        if ($statusCode === 400 && $this->looksLikeSchemaError($apiError)) {
            $this->logger->warning('OpenAI rejected structured request', [
                'context' => $context,
                'status_code' => $statusCode,
                'api_error' => $apiError,
            ]);
            throw new OpenAISchemaMismatchException(
                'OpenAI rejected the structured request schema.'
            );
        }

        $this->logger->error('OpenAI request failed', [
            'context' => $context,
            'status_code' => $statusCode,
            'api_error' => $apiError,
        ]);
        throw new OpenAIRequestFailedException('OpenAI request failed.');
    }

    private function extractApiErrorMessage(#[SensitiveParameter] string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    private function looksLikeSchemaError(?string $apiError): bool
    {
        if ($apiError === null) {
            return false;
        }

        $needles = ['response_format', 'json_schema', 'schema'];
        $lower = strtolower($apiError);
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a multipart/form-data body containing two parts: the literal
     * `purpose` field and the PDF file. Returned as a string body so the
     * Symfony HTTP client streams it as a single buffered request.
     *
     * @param non-empty-string $boundary
     * @param non-empty-string $filePath
     * @param non-empty-string $filename
     */
    private function buildMultipartBody(string $boundary, string $filePath, string $filename): string
    {
        $fileContents = @file_get_contents($filePath);
        if ($fileContents === false) {
            throw new OpenAIRequestFailedException(
                'Cannot read PDF for OpenAI upload.'
            );
        }

        $crlf = "\r\n";
        $sanitizedFilename = $this->sanitizeForHeader($filename);
        $body = '--' . $boundary . $crlf
            . 'Content-Disposition: form-data; name="purpose"' . $crlf
            . $crlf
            . 'user_data' . $crlf
            . '--' . $boundary . $crlf
            . 'Content-Disposition: form-data; name="file"; filename="' . $sanitizedFilename . '"' . $crlf
            . 'Content-Type: application/pdf' . $crlf
            . $crlf
            . $fileContents . $crlf
            . '--' . $boundary . '--' . $crlf;

        return $body;
    }

    private function sanitizeForHeader(string $value): string
    {
        // Strip CR/LF and quotes to keep the multipart header well-formed.
        return preg_replace('/[\r\n"]/', '_', $value) ?? 'file.pdf';
    }
}
