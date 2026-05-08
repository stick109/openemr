<?php

/**
 * AgentServiceClient
 *
 * PHP HTTP client for the Python agent-service sidecar. Sends a document
 * extraction request to `POST /api/agent/run` and returns a typed
 * {@see AgentRunResult} on success, or throws {@see AgentServiceException}
 * on any sidecar or network error.
 *
 * Uses Guzzle (already a project dependency) so that tests can inject a
 * MockHandler without touching the network. Raw `curl_*` functions are
 * forbidden by the project's PHPStan rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Log\LoggerInterface;

final class AgentServiceClient
{
    private const ENDPOINT = '/api/agent/run';

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly AgentSidecarConfig $config,
        private readonly LoggerInterface $logger,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Submit a document for extraction via the sidecar.
     *
     * The file bytes are streamed in the HTTP request body as a multipart
     * "file" part; the sidecar writes them to a temporary file on its own
     * filesystem and processes them there. PHP and the sidecar therefore no
     * longer share a Docker volume — the only handoff is over HTTP. PHP must
     * still keep the file on its own local disk if other code (e.g.
     * upload_intake_form/pdf.php) needs to read it back later.
     *
     * @param int    $patientId   OpenEMR patient pid (positive).
     * @param string $filePath    Absolute path to a readable file on the
     *                            PHP host's local disk.
     * @param string $docType     One of: lab_pdf, intake_form, auto.
     * @param int    $encounterId OpenEMR encounter ID (positive).
     * @param string $traceId     UUID v4 correlation ID.
     *
     * @throws AgentServiceException On sidecar error, auth failure, network
     *                               timeout, or unreadable upload file.
     */
    public function run(
        int $patientId,
        string $filePath,
        string $docType,
        int $encounterId,
        string $traceId,
    ): AgentRunResult {
        $this->guardConfigured();

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new AgentServiceException(
                'Upload file is missing or not readable.',
                errorCode: 'upload_unreadable',
                detail: $filePath,
                traceId: $traceId,
            );
        }

        $fileStream = @fopen($filePath, 'r');
        if ($fileStream === false) {
            throw new AgentServiceException(
                'Failed to open upload file for streaming.',
                errorCode: 'upload_unreadable',
                detail: $filePath,
                traceId: $traceId,
            );
        }

        $url = $this->config->getUrl() . self::ENDPOINT;
        $multipart = [
            ['name' => 'patient_id', 'contents' => (string) $patientId],
            ['name' => 'doc_type', 'contents' => $docType],
            ['name' => 'encounter_id', 'contents' => (string) $encounterId],
            ['name' => 'trace_id', 'contents' => $traceId],
            [
                'name' => 'file',
                'contents' => $fileStream,
                'filename' => basename($filePath),
            ],
        ];

        $this->logger->info('Calling agent sidecar', [
            'url' => $url,
            'trace_id' => $traceId,
            'doc_type' => $docType,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'multipart' => $multipart,
                'headers' => [
                    'X-Agent-Secret' => $this->config->getSharedSecret(),
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->config->getTimeoutSeconds(),
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            $this->logger->error('Agent sidecar connection failed', [
                'trace_id' => $traceId,
                'exception' => $e,
            ]);
            throw new AgentServiceException(
                'Agent sidecar is unreachable.',
                errorCode: 'connection_failed',
                traceId: $traceId,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            $this->logger->error('Agent sidecar request failed', [
                'trace_id' => $traceId,
                'exception' => $e,
            ]);
            throw new AgentServiceException(
                'Agent sidecar request failed.',
                errorCode: 'request_failed',
                traceId: $traceId,
                previous: $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Agent sidecar returned invalid JSON', [
                'trace_id' => $traceId,
                'status_code' => $statusCode,
                'body_prefix' => substr($body, 0, 200),
            ]);
            throw new AgentServiceException(
                'Agent sidecar returned invalid JSON.',
                errorCode: 'invalid_response',
                traceId: $traceId,
                httpStatus: $statusCode,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new AgentServiceException(
                'Agent sidecar returned a non-object JSON response.',
                errorCode: 'invalid_response',
                traceId: $traceId,
                httpStatus: $statusCode,
            );
        }

        if ($statusCode >= 400) {
            $this->handleErrorResponse($statusCode, $decoded, $traceId);
        }

        $this->logger->info('Agent sidecar returned successfully', [
            'trace_id' => $traceId,
            'status_code' => $statusCode,
        ]);

        return AgentRunResult::fromArray($decoded);
    }

    /**
     * @throws AgentServiceException When the sidecar URL or secret is missing.
     */
    private function guardConfigured(): void
    {
        if ($this->config->isConfigured()) {
            return;
        }

        $issue = $this->config->getConfigurationIssue() ?? 'unknown';
        throw new AgentServiceException(
            'Agent sidecar is not configured: ' . $issue,
            errorCode: 'not_configured',
            detail: $issue,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @throws AgentServiceException Always.
     */
    private function handleErrorResponse(int $statusCode, array $decoded, string $traceId): never
    {
        $errorCode = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown';
        $detail = is_string($decoded['detail'] ?? null) ? $decoded['detail'] : '';
        $echoedTraceId = is_string($decoded['trace_id'] ?? null) ? $decoded['trace_id'] : $traceId;

        $this->logger->error('Agent sidecar returned an error', [
            'trace_id' => $echoedTraceId,
            'status_code' => $statusCode,
            'error' => $errorCode,
            'detail' => $detail,
        ]);

        throw new AgentServiceException(
            'Agent sidecar error: ' . $errorCode,
            errorCode: $errorCode,
            detail: $detail,
            traceId: $echoedTraceId,
            httpStatus: $statusCode,
        );
    }
}
