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

    public function __construct(
        ?AgentLlmProviderInterface $provider = null,
        private readonly AgentAnswerVerifier $verifier = new AgentAnswerVerifier(),
        private readonly AgentAnswerSchema $answerSchema = new AgentAnswerSchema(),
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly LoggerInterface $logger = new SystemLogger()
    ) {
        $this->provider = $provider ?? (new AgentLlmProviderFactory())->create();
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
                    jsonSchema: $this->answerSchema->jsonSchema(),
                    allowedFollowupIntents: $this->intentCatalog->intentIds()
                );
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
                ]);

                if ($verification->passed()) {
                    return $this->verifiedResponse(
                        answer: $answer,
                        responseGeneration: 'llm_structured_verified',
                        verification: $verification,
                        llmMetadata: $llmMetadata
                    );
                }

                $this->logger->warning('agent.verification.failed', [
                    'request_id' => (string) ($packet['request_id'] ?? ''),
                    'intent_id' => (string) ($intent['intent_id'] ?? ''),
                    'provider' => $this->provider->getProviderName(),
                    'error_count' => count($verification->errors()),
                ]);
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
            'followup_intents' => [],
        ];
    }
}
