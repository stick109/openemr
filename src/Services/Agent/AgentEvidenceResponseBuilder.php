<?php

/**
 * AgentEvidenceResponseBuilder
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use InvalidArgumentException;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;
use Psr\Log\LoggerInterface;
use Throwable;

final class AgentEvidenceResponseBuilder
{
    /**
     * Anonymized projection of the evidence packet, prepared exclusively for
     * `api_log`-style durable logging. The LLM is called separately under the
     * provider BAA with raw evidence, so this payload never participates in
     * model input.
     *
     * @var array<string, mixed>
     */
    private array $lastLogPayload = [];

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentIntentPlaceholderResponseBuilder $placeholderResponseBuilder = new AgentIntentPlaceholderResponseBuilder(),
        private readonly AgentEvidenceToolset $toolset = new AgentEvidenceToolset(),
        private readonly Anonymizer $anonymizer = new Anonymizer(),
        private readonly AgentLlmOrchestrator $llmOrchestrator = new AgentLlmOrchestrator(),
        private readonly LoggerInterface $logger = new SystemLogger()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $intentId, AgentAccessToken $accessToken, ?string $sourceId = null): array
    {
        $this->lastLogPayload = [];
        $this->logger->debug('agent.response.building', [
            'intent_id' => $intentId,
            'has_source_id' => $sourceId !== null && $sourceId !== '',
        ]);

        $intent = $this->intentCatalog->get($intentId);
        if ($intent === null) {
            $this->logger->warning('agent.response.unknown_intent', [
                'intent_id' => $intentId,
            ]);
            throw new InvalidArgumentException('Unknown agent intent_id.');
        }

        if (!$this->toolset->supportsIntent($intentId)) {
            $this->logger->info('agent.response.placeholder', [
                'intent_id' => $intentId,
            ]);
            return $this->placeholderResponseBuilder->build($intentId);
        }

        if ($intentId === AgentIntentCatalog::SHOW_SOURCE && ($sourceId === null || $sourceId === '')) {
            $this->logger->info('agent.response.source_required', [
                'intent_id' => $intentId,
            ]);
            return $this->sourceRequiredResponse($intent, $accessToken);
        }

        $packet = $this->toolset->buildPacket($intentId, $accessToken, $intent, $sourceId);
        $this->lastLogPayload = $this->safeBuildLogPayload($intent, $accessToken, $packet);

        $this->logger->info('agent.response.evidence_ready', [
            'intent_id' => $intentId,
            'request_id' => (string) ($packet['request_id'] ?? ''),
            'citation_count' => is_array($packet['sources'] ?? null) ? count($packet['sources']) : 0,
            'checked_evidence_count' => is_array($packet['checked_evidence'] ?? null) ? count($packet['checked_evidence']) : 0,
            'placeholder_count' => $this->anonymizer->placeholderCount($accessToken),
        ]);

        $verifiedResponse = $this->llmOrchestrator->buildVerifiedAnswer(
            $intent,
            $accessToken,
            $packet,
            $this->answerFromPacket($intent, $packet)
        );

        return [
            'status' => $verifiedResponse['status'],
            'response_generation' => $verifiedResponse['response_generation'],
            'answer' => $verifiedResponse['answer'],
            'citations' => $packet['sources'],
            'checked_evidence' => $packet['checked_evidence'],
            'verification' => $verifiedResponse['verification'],
            'llm' => $verifiedResponse['llm'],
            'evidence_packet' => $packet,
        ];
    }

    /**
     * Anonymized payload prepared for the most recent `build()` call, suitable
     * for `api_log` writes. Returns an empty array when no evidence was
     * produced (placeholder intents or source-required responses with no
     * resolved sources). Never used as model input — LLM calls run under the
     * provider BAA with raw evidence.
     *
     * @return array<string, mixed>
     */
    public function getLastLogPayload(): array
    {
        return $this->lastLogPayload;
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function sourceRequiredResponse(array $intent, AgentAccessToken $accessToken): array
    {
        $packet = [
            'request_id' => '',
            'intent_id' => $intent['intent_id'],
            'caps' => [
                'max_records' => $intent['max_records'],
                'max_documents' => $intent['max_documents'],
                'lookback_days' => $intent['lookback_days'],
            ],
            'sources' => [],
            'checked_evidence' => [],
            'tool_runs' => [],
        ];
        $this->lastLogPayload = $this->safeBuildLogPayload($intent, $accessToken, $packet);

        return [
            'status' => 'source_required',
            'response_generation' => 'deterministic_verified',
            'answer' => [
                'answer_blocks' => [
                    [
                        'heading' => (string) $intent['button_label'],
                        'claims' => [
                            [
                                'text' => 'Select a citation source to inspect the underlying record.',
                                'citation_ids' => [],
                                'certainty' => 'not_checked',
                            ],
                        ],
                    ],
                ],
                'missing_or_uncertain' => [],
            ],
            'citations' => [],
            'checked_evidence' => [],
            'verification' => [
                'status' => 'passed',
                'errors' => [],
                'warnings' => [],
                'unsupported_claim_count' => 0,
            ],
            'llm' => [
                'provider' => 'disabled',
                'model' => '',
                'configured' => false,
                'used' => false,
                'configuration_issue' => 'source_required',
                'usage' => [],
            ],
            'evidence_packet' => $packet,
        ];
    }

    /**
     * Build the anonymized log projection of an evidence packet. This is the
     * only call site that invokes the Anonymizer, and its output is consumed
     * solely by `ApiResponseLoggerListener` to keep raw PHI out of `api_log`.
     * The LLM orchestrator receives the raw `$packet` separately under the
     * provider BAA.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @return array<string, mixed>
     */
    private function buildLogPayload(array $intent, AgentAccessToken $accessToken, array $packet): array
    {
        return [
            'payload_version' => 'agent.log.v1',
            'intent_id' => (string) $intent['intent_id'],
            'prompt_text' => $this->anonymizer->anonymizePayload($accessToken, (string) $intent['prompt_text']),
            'evidence_packet' => $this->anonymizer->anonymizeEvidencePacket($accessToken, $packet),
            'placeholder_count' => $this->anonymizer->placeholderCount($accessToken),
        ];
    }

    /**
     * Wrap {@see buildLogPayload()} so that an Anonymizer failure cannot break
     * the request flow. Anonymization is for durable logging only; the LLM
     * call and verifier do not depend on this output. On failure, drop the
     * optional log entry and let `Anonymizer::reportFailure()` carry the
     * audit trail.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @return array<string, mixed>
     */
    private function safeBuildLogPayload(array $intent, AgentAccessToken $accessToken, array $packet): array
    {
        try {
            return $this->buildLogPayload($intent, $accessToken, $packet);
        } catch (Throwable $exception) {
            $this->logger->warning('agent.response.log_payload_skipped', [
                'intent_id' => (string) ($intent['intent_id'] ?? ''),
                'error_class' => $exception::class,
            ]);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @return array<string, mixed>
     */
    private function answerFromPacket(array $intent, array $packet): array
    {
        $sources = is_array($packet['sources'] ?? null) ? $packet['sources'] : [];
        $claims = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $claims[] = [
                'text' => $this->claimText((string) $intent['intent_id'], $source),
                'citation_ids' => [(string) ($source['source_id'] ?? '')],
                'certainty' => $this->certainty($source),
            ];
        }

        $missingOrUncertain = [];
        if ($claims === []) {
            $claims[] = [
                'text' => 'No matching records were found in checked evidence for this intent.',
                'citation_ids' => [],
                'certainty' => 'not_found',
            ];
            $missingOrUncertain[] = [
                'text' => 'This does not prove absence from the full chart; it only reflects the bounded evidence retrieved for this request.',
                'citation_ids' => [],
            ];
        }

        return [
            'answer_blocks' => [
                [
                    'heading' => (string) $intent['button_label'],
                    'claims' => $claims,
                ],
            ],
            'missing_or_uncertain' => $missingOrUncertain,
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function claimText(string $intentId, array $source): string
    {
        $display = trim((string) ($source['display'] ?? 'Source record'));

        if ($intentId === AgentIntentCatalog::SHOW_SOURCE) {
            $date = trim((string) ($source['date'] ?? ''));
            $status = trim((string) ($source['status'] ?? 'unknown'));
            $type = trim((string) ($source['source_type'] ?? 'source'));
            $prefix = 'Source ' . $type;
            if ($date !== '') {
                $prefix .= ' from ' . $date;
            }

            return $prefix . ' (' . $status . '): ' . $display;
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function certainty(array $source): string
    {
        $status = (string) ($source['status'] ?? 'unknown');
        return $status === '' ? 'source_record' : $status;
    }

}
