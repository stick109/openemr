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
    public function build(
        string $intentId,
        AgentAccessToken $accessToken,
        ?string $sourceId = null,
        ?string $requestId = null
    ): array {
        $this->lastLogPayload = [];
        $this->logger->debug('agent.response.building', [
            'request_id' => $requestId,
            'intent_id' => $intentId,
            'has_source_id' => $sourceId !== null && $sourceId !== '',
        ]);

        $intent = $this->intentCatalog->get($intentId);
        if ($intent === null) {
            $this->logger->warning('agent.response.unknown_intent', [
                'request_id' => $requestId,
                'intent_id' => $intentId,
            ]);
            throw new InvalidArgumentException('Unknown agent intent_id.');
        }

        if (!$this->toolset->supportsIntent($intentId)) {
            $this->logger->info('agent.response.placeholder', [
                'request_id' => $requestId,
                'intent_id' => $intentId,
            ]);
            return $this->placeholderResponseBuilder->build($intentId);
        }

        if ($intentId === AgentIntentCatalog::SHOW_SOURCE && ($sourceId === null || $sourceId === '')) {
            $this->logger->info('agent.response.source_required', [
                'request_id' => $requestId,
                'intent_id' => $intentId,
            ]);
            return $this->sourceRequiredResponse($intent, $accessToken, $requestId);
        }

        $packet = $this->toolset->buildPacket($intentId, $accessToken, $intent, $sourceId, $requestId);
        $this->lastLogPayload = $this->safeBuildLogPayload($intent, $accessToken, $packet);

        $this->logger->info('agent.response.evidence_ready', [
            'request_id' => (string) ($packet['request_id'] ?? ''),
            'intent_id' => $intentId,
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
    private function sourceRequiredResponse(array $intent, AgentAccessToken $accessToken, ?string $requestId): array
    {
        $packet = [
            'request_id' => $requestId ?? '',
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
        $anonymizedPrompt = $this->anonymizer->anonymizePayload($accessToken, (string) $intent['prompt_text']);
        $promptMetrics = $this->anonymizer->getLastMetrics();
        $anonymizedPacket = $this->anonymizer->anonymizeEvidencePacket($accessToken, $packet);
        $packetMetrics = $this->anonymizer->getLastMetrics();

        return [
            'payload_version' => 'agent.log.v1',
            'request_id' => (string) ($packet['request_id'] ?? ''),
            'intent_id' => (string) $intent['intent_id'],
            'prompt_text' => $anonymizedPrompt,
            'evidence_packet' => $anonymizedPacket,
            'placeholder_count' => $this->anonymizer->placeholderCount($accessToken),
            'redaction' => [
                'status' => 'anonymized',
                'prompt' => $promptMetrics,
                'evidence_packet' => $packetMetrics,
                'replacement_count' => $this->sumMetric($promptMetrics, 'replacement_count')
                    + $this->sumMetric($packetMetrics, 'replacement_count'),
                'category_counts' => $this->mergeCategoryCounts(
                    is_array($promptMetrics['category_counts'] ?? null) ? $promptMetrics['category_counts'] : [],
                    is_array($packetMetrics['category_counts'] ?? null) ? $packetMetrics['category_counts'] : []
                ),
            ],
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
                'request_id' => (string) ($packet['request_id'] ?? ''),
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

            foreach ($this->claimTexts((string) $intent['intent_id'], $source, count($sources)) as $claimText) {
                $claims[] = [
                    'text' => $claimText,
                    'citation_ids' => [(string) ($source['source_id'] ?? '')],
                    'certainty' => $this->certainty($source),
                ];
            }
        }

        if ((string) ($intent['intent_id'] ?? '') === AgentIntentCatalog::CURRENT_MEDICATIONS) {
            $hasMedicationRecord = false;
            $hasReviewMarker = false;
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $sourceType = (string) ($source['source_type'] ?? '');
                $hasMedicationRecord = $hasMedicationRecord || $sourceType === 'medication';
                $hasReviewMarker = $hasReviewMarker || $sourceType === 'medication_review';
            }

            if (!$hasMedicationRecord && $hasReviewMarker) {
                array_unshift($claims, [
                    'text' => 'Current medication records were not found in checked evidence.',
                    'citation_ids' => [],
                    'certainty' => 'not_found',
                ]);
            }
        }

        if ((string) ($intent['intent_id'] ?? '') === AgentIntentCatalog::ALLERGIES_TO_CONFIRM) {
            $hasAllergyRecord = false;
            $hasReviewMarker = false;
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $sourceType = (string) ($source['source_type'] ?? '');
                $hasAllergyRecord = $hasAllergyRecord || $sourceType === 'allergy';
                $hasReviewMarker = $hasReviewMarker || $sourceType === 'allergy_review';
            }

            if (!$hasAllergyRecord && $hasReviewMarker) {
                array_unshift($claims, [
                    'text' => 'Current allergy records were not found in checked evidence.',
                    'citation_ids' => [],
                    'certainty' => 'not_found',
                ]);
            }
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

        if ((string) ($intent['intent_id'] ?? '') === AgentIntentCatalog::BASIC_PATIENT_DATA && $sources !== []) {
            $this->addBasicPatientDataMissingness($claims, $missingOrUncertain);
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
     * @param list<array{text: string, citation_ids: list<string>, certainty: string}> $claims
     * @param list<array{text: string, citation_ids: list<string>}> $missingOrUncertain
     */
    private function addBasicPatientDataMissingness(array $claims, array &$missingOrUncertain): void
    {
        $claimText = strtolower(implode(' ', array_map(
            static fn (array $claim): string => (string) ($claim['text'] ?? ''),
            $claims
        )));

        if (!preg_match('/\b(address|street|postal|zip)\b/', $claimText)) {
            $missingOrUncertain[] = [
                'text' => 'Address was not found in checked evidence from patient_data or structured contact address records.',
                'citation_ids' => [],
            ];
        }

        if (!preg_match('/\b(phone|mobile|telecom)\b/', $claimText)) {
            $missingOrUncertain[] = [
                'text' => 'Phone was not found in checked evidence from patient_data or structured contact telecom records.',
                'citation_ids' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return list<string>
     */
    private function claimTexts(string $intentId, array $source, int $sourceCount = 0): array
    {
        if ($intentId === AgentIntentCatalog::ALLERGIES_TO_CONFIRM) {
            return $this->allergyClaimTexts($source, $sourceCount);
        }

        $text = $this->claimText($intentId, $source);
        if ($intentId !== AgentIntentCatalog::BASIC_PATIENT_DATA) {
            return [$text];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(';', $text)),
            static fn (string $part): bool => $part !== ''
        ));

        return $parts === [] ? [$text] : $parts;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function claimText(string $intentId, array $source): string
    {
        $display = trim((string) ($source['display'] ?? 'Source record'));

        if ($intentId === AgentIntentCatalog::CURRENT_MEDICATIONS) {
            return $this->currentMedicationClaimText($source, $display);
        }

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
     * Keep the visible deterministic medication answer short enough for the
     * verifier while preserving the expanded source packet for citations and
     * source drilldown.
     *
     * @param array<string, mixed> $source
     */
    private function currentMedicationClaimText(array $source, string $display): string
    {
        if ((string) ($source['source_type'] ?? '') === 'medication_review') {
            return $this->truncateClaimText($display, 140);
        }

        $segments = array_values(array_filter(
            array_map('trim', explode(';', $display)),
            static fn (string $segment): bool => $segment !== ''
        ));

        $preferredPrefixes = [
            'medication:',
            'prescription drug:',
            'linked prescription drug:',
            'status:',
            'dosage instructions:',
            'prescription dosage instructions:',
            'dosage:',
            'route:',
            'quantity:',
            'rxnorm:',
            'usage category:',
            'adherence value:',
            'record type:',
        ];

        $selected = [];
        foreach ($preferredPrefixes as $prefix) {
            foreach ($segments as $segment) {
                if (stripos($segment, $prefix) === 0) {
                    $selected[] = $this->currentMedicationClaimSegment($segment, $prefix);
                    break;
                }
            }
        }

        if ($selected === []) {
            $selected = $segments === [] ? [$display] : [reset($segments)];
        }

        return $this->truncateClaimText($this->joinClaimSegments($selected), 140);
    }

    private function currentMedicationClaimSegment(string $segment, string $matchedPrefix): string
    {
        if (strtolower($matchedPrefix) !== 'medication:') {
            return $segment;
        }

        return trim(substr($segment, strlen($matchedPrefix)));
    }

    /**
     * @param array<string, mixed> $source
     * @return list<string>
     */
    private function allergyClaimTexts(array $source, int $sourceCount): array
    {
        $display = trim((string) ($source['display'] ?? 'Source record'));
        if ((string) ($source['source_type'] ?? '') === 'allergy_review') {
            return [$this->truncateClaimText($display, 140)];
        }

        $segments = array_values(array_filter(
            array_map('trim', explode(';', $display)),
            static fn (string $segment): bool => $segment !== ''
        ));

        $preferredPrefixes = [
            'allergen:',
            'reaction:',
            'severity:',
            'verification status:',
            'current status:',
        ];
        if ($sourceCount <= 10) {
            array_splice($preferredPrefixes, 1, 0, ['coded allergen:']);
        }

        $selected = [];
        foreach ($preferredPrefixes as $prefix) {
            foreach ($segments as $segment) {
                if (stripos($segment, $prefix) === 0) {
                    $selected[] = $segment;
                    break;
                }
            }
        }

        if ($selected === []) {
            $selected = $segments === [] ? [$display] : [reset($segments)];
        }

        return array_values(array_map(
            fn (string $segment): string => $this->truncateClaimText($segment, 140),
            array_unique($selected)
        ));
    }

    /**
     * @param list<string> $segments
     */
    private function joinClaimSegments(array $segments): string
    {
        return implode('; ', array_values(array_unique(array_filter(
            $segments,
            static fn (string $segment): bool => trim($segment) !== ''
        ))));
    }

    private function truncateClaimText(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '' || strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function certainty(array $source): string
    {
        $status = strtolower(trim((string) ($source['status'] ?? '')));
        return match ($status) {
            'active',
            'inactive',
            'unknown',
            'conflicting',
            'not_found',
            'not_checked',
            'supported',
            'source_record' => $status,
            default => 'source_record',
        };
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function sumMetric(array $metrics, string $name): int
    {
        return is_int($metrics[$name] ?? null) ? $metrics[$name] : 0;
    }

    /**
     * @param array<string, int> $first
     * @param array<string, int> $second
     * @return array<string, int>
     */
    private function mergeCategoryCounts(array $first, array $second): array
    {
        $merged = $first;
        foreach ($second as $category => $count) {
            $merged[$category] = ($merged[$category] ?? 0) + $count;
        }

        ksort($merged);

        return $merged;
    }

}
