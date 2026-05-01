<?php

/**
 * AgentLlmOrchestrator
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\Llm\AgentAnswerSchema;
use OpenEMR\Services\Agent\Llm\AgentLlmProviderFactory;
use OpenEMR\Services\Agent\Llm\AgentLlmProviderInterface;
use OpenEMR\Services\Agent\Llm\AgentLlmRequest;
use OpenEMR\Services\Agent\Verification\AgentAnswerVerifier;
use OpenEMR\Services\Agent\Verification\AgentVerificationResult;
use Psr\Log\LoggerInterface;
use Throwable;

final class AgentLlmOrchestrator
{
    private readonly AgentLlmProviderInterface $provider;
    private readonly Anonymizer $anonymizer;

    public function __construct(
        ?AgentLlmProviderInterface $provider = null,
        private readonly AgentAnswerVerifier $verifier = new AgentAnswerVerifier(),
        private readonly AgentAnswerSchema $answerSchema = new AgentAnswerSchema(),
        private readonly LoggerInterface $logger = new SystemLogger(),
        ?Anonymizer $anonymizer = null
    ) {
        $this->provider = $provider ?? (new AgentLlmProviderFactory())->create();
        $this->anonymizer = $anonymizer ?? new Anonymizer(logger: $this->logger);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @param array<string, mixed> $deterministicAnswer
     * @return array<string, mixed>
     */
    public function buildVerifiedAnswer(
        array $intent,
        AgentAccessToken $accessToken,
        array $packet,
        array $deterministicAnswer
    ): array {
        $llmMetadata = $this->baseLlmMetadata();

        if ($this->provider->isConfigured()) {
            try {
                $request = new AgentLlmRequest(
                    intent: $intent,
                    evidencePacket: $packet,
                    jsonSchema: $this->answerSchema->jsonSchema()
                );
                $this->logLlmRequest($intent, $accessToken, $packet, $request);
                $llmResponse = $this->provider->complete($request);
                $answer = $this->answerSchema->normalize($llmResponse->getAnswer());
                $verification = $this->verifier->verify($answer, $accessToken, $packet);

                $llmMetadata = $llmResponse->toMetadata();
                $this->logger->info('agent.llm.finished', [
                    'request_id' => (string) ($packet['request_id'] ?? ''),
                    'intent_id' => (string) ($intent['intent_id'] ?? ''),
                    'provider' => (string) ($llmMetadata['provider'] ?? ''),
                    'model' => (string) ($llmMetadata['model'] ?? ''),
                    'verification_status' => $verification->passed() ? 'passed' : 'failed',
                    'llm_response' => $this->anonymizedLlmResponse($accessToken, $answer),
                ]);

                if ($verification->passed()) {
                    return $this->verifiedResponse(
                        answer: $answer,
                        responseGeneration: 'llm_structured_verified',
                        verification: $verification,
                        llmMetadata: $llmMetadata
                    );
                }

                $this->logVerificationFailure($intent, $packet, $verification);
                $llmMetadata['fallback_reason'] = 'verification_failed';
            } catch (Throwable $throwable) {
                $this->logger->warning('agent.llm.failed', [
                    'request_id' => (string) ($packet['request_id'] ?? ''),
                    'intent_id' => (string) ($intent['intent_id'] ?? ''),
                    'provider' => $this->provider->getProviderName(),
                    'error_class' => $throwable::class,
                ]);
                $llmMetadata['fallback_reason'] = 'provider_error';
            }
        } else {
            $llmMetadata['fallback_reason'] = $this->provider->getConfigurationIssue() ?? 'provider_unconfigured';
        }

        $verification = $this->verifier->verify($deterministicAnswer, $accessToken, $packet);
        if (!$verification->passed()) {
            $this->logger->error('agent.verification.deterministic_fallback_failed', [
                'request_id' => (string) ($packet['request_id'] ?? ''),
                'intent_id' => (string) ($intent['intent_id'] ?? ''),
                'error_count' => count($verification->errors()),
            ]);
            return $this->verifiedResponse(
                answer: $this->systemRefusal((string) ($intent['button_label'] ?? 'Clinical Co-Pilot')),
                responseGeneration: 'verified_refusal',
                verification: new AgentVerificationResult(true, [], ['system_refusal']),
                llmMetadata: $llmMetadata
            );
        }

        return $this->verifiedResponse(
            answer: $deterministicAnswer,
            responseGeneration: ($llmMetadata['fallback_reason'] ?? '') === 'provider_disabled'
                || ($llmMetadata['fallback_reason'] ?? '') === 'missing_api_key'
                || ($llmMetadata['fallback_reason'] ?? '') === 'missing_model'
                || ($llmMetadata['fallback_reason'] ?? '') === 'provider_unconfigured'
                    ? 'deterministic_verified'
                    : 'deterministic_verified_fallback',
            verification: $verification,
            llmMetadata: $llmMetadata
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseLlmMetadata(): array
    {
        return [
            'provider' => $this->provider->getProviderName(),
            'model' => $this->provider->getModelName(),
            'configured' => $this->provider->isConfigured(),
            'used' => false,
            'configuration_issue' => $this->provider->getConfigurationIssue(),
            'usage' => [],
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     */
    private function logLlmRequest(
        array $intent,
        AgentAccessToken $accessToken,
        array $packet,
        AgentLlmRequest $request
    ): void {
        $llmRequest = $this->anonymizedLlmRequest($accessToken, $request);
        $this->logger->info('agent.llm.started', [
            'request_id' => (string) ($packet['request_id'] ?? ''),
            'intent_id' => (string) ($intent['intent_id'] ?? ''),
            'provider' => $this->provider->getProviderName(),
            'model' => $this->provider->getModelName(),
            'llm_request_log' => 'agent.llm.request_readable',
        ]);
        $this->logger->info($this->readableLlmRequestLogMessage($intent, $packet, $llmRequest));
    }

    /**
     * @return array<string, mixed>
     */
    private function anonymizedLlmRequest(AgentAccessToken $accessToken, AgentLlmRequest $request): array
    {
        try {
            $requestPayload = $this->provider->getRequestPayload($request);
            if (is_string($requestPayload['instructions'] ?? null)) {
                $requestPayload['instructions'] = $this->splitLines($requestPayload['instructions']);
            }

            if (is_string($requestPayload['input'] ?? null)) {
                $requestPayload['input'] = $this->decodedJsonString($requestPayload['input']);
            }

            $payload = $this->anonymizer->anonymizePayload($accessToken, $requestPayload);
            return is_array($payload) ? $payload : [
                'redaction_status' => 'unexpected_payload_type',
                'llm_request_omitted' => true,
            ];
        } catch (Throwable $throwable) {
            return [
                'redaction_status' => 'failed',
                'redaction_error_class' => $throwable::class,
                'llm_request_omitted' => true,
            ];
        }
    }

    private function decodedJsonString(string $json): mixed
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $json;
        } catch (Throwable) {
            return $json;
        }
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @param array<string, mixed> $requestPayload
     */
    private function readableLlmRequestLogMessage(array $intent, array $packet, array $requestPayload): string
    {
        $lines = [
            'agent.llm.request_readable',
            'request_id: ' . (string) ($packet['request_id'] ?? ''),
            'intent_id: ' . (string) ($intent['intent_id'] ?? ''),
            'provider: ' . $this->provider->getProviderName(),
            'model: ' . $this->provider->getModelName(),
            'llm_request:',
        ];

        foreach ($requestPayload as $key => $value) {
            $this->appendReadableField($lines, (string) $key, $value, '  ');
        }

        return implode("\r\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function appendReadableField(array &$lines, string $key, mixed $value, string $indent): void
    {
        if ($key === 'instructions' && is_array($value)) {
            $lines[] = $indent . 'instructions:';
            foreach ($value as $line) {
                $lines[] = $indent . '  ' . $this->readableLogText((string) $line);
            }
            return;
        }

        if (is_array($value)) {
            $lines[] = $indent . $key . ':';
            foreach ($this->splitLines($this->prettyJson($this->normalizeReadableValue($value))) as $line) {
                $lines[] = $indent . '  ' . $line;
            }
            return;
        }

        $lines[] = $indent . $key . ': ' . $this->readableScalar($value);
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $value): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        return explode("\n", $normalized);
    }

    private function readableScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->readableLogText((string) $value);
    }

    private function readableLogText(string $value): string
    {
        $value = str_replace(
            ['\u0026quot;', '\u0026#039;', '&quot;', '&#039;', '&#x27;'],
            ['"', "'", '"', "'", "'"],
            $value
        );

        $value = str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
        return str_replace(["\r\n", "\r", "\n"], ' ', $value);
    }

    private function normalizeReadableValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeReadableValue($item);
            }
            return $normalized;
        }

        return is_string($value) ? $this->readableLogText($value) : $value;
    }

    private function prettyJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (Throwable) {
            return '[unserializable]';
        }
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     */
    private function logVerificationFailure(
        array $intent,
        array $packet,
        AgentVerificationResult $verification
    ): void {
        $this->logger->warning('agent.verification.failed', [
            'request_id' => (string) ($packet['request_id'] ?? ''),
            'intent_id' => (string) ($intent['intent_id'] ?? ''),
            'provider' => $this->provider->getProviderName(),
            'error_count' => count($verification->errors()),
            'verification_errors' => $verification->errors(),
        ]);
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function anonymizedLlmResponse(AgentAccessToken $accessToken, array $answer): array
    {
        try {
            $payload = $this->anonymizer->anonymizePayload($accessToken, $answer);
            return is_array($payload) ? $payload : [
                'redaction_status' => 'unexpected_payload_type',
                'llm_response_omitted' => true,
            ];
        } catch (Throwable $throwable) {
            return [
                'redaction_status' => 'failed',
                'redaction_error_class' => $throwable::class,
                'llm_response_omitted' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $llmMetadata
     * @return array<string, mixed>
     */
    private function verifiedResponse(
        array $answer,
        string $responseGeneration,
        AgentVerificationResult $verification,
        array $llmMetadata
    ): array {
        return [
            'status' => 'verified',
            'response_generation' => $responseGeneration,
            'answer' => $answer,
            'verification' => $verification->toArray(),
            'llm' => $llmMetadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemRefusal(string $heading): array
    {
        return [
            'answer_blocks' => [
                [
                    'heading' => $heading,
                    'claims' => [
                        [
                            'text' => 'A verified answer is not available from the checked evidence for this request.',
                            'citation_ids' => [],
                            'certainty' => 'not_checked',
                        ],
                    ],
                ],
            ],
            'missing_or_uncertain' => [],
        ];
    }
}
