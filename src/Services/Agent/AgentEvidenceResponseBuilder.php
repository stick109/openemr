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
use OpenEMR\Services\Agent\Evidence\AgentEvidenceToolset;

final class AgentEvidenceResponseBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $lastAnonymizedPayload = [];

    public function __construct(
        private readonly AgentIntentCatalog $intentCatalog = new AgentIntentCatalog(),
        private readonly AgentIntentPlaceholderResponseBuilder $placeholderResponseBuilder = new AgentIntentPlaceholderResponseBuilder(),
        private readonly AgentEvidenceToolset $toolset = new AgentEvidenceToolset(),
        private readonly Anonymizer $anonymizer = new Anonymizer()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $intentId, AgentAccessToken $accessToken, ?string $sourceId = null): array
    {
        $this->lastAnonymizedPayload = [];
        $intent = $this->intentCatalog->get($intentId);
        if ($intent === null) {
            throw new InvalidArgumentException('Unknown agent intent_id.');
        }

        if (!$this->toolset->supportsIntent($intentId)) {
            return $this->placeholderResponseBuilder->build($intentId);
        }

        if ($intentId === AgentIntentCatalog::SHOW_SOURCE && ($sourceId === null || $sourceId === '')) {
            return $this->sourceRequiredResponse($intent, $accessToken);
        }

        $packet = $this->toolset->buildPacket($intentId, $accessToken, $intent, $sourceId);
        $this->lastAnonymizedPayload = $this->anonymizedPayload($intent, $accessToken, $packet);

        return [
            'status' => 'evidence_ready',
            'response_generation' => 'deterministic_evidence_packet',
            'answer' => $this->answerFromPacket($intent, $packet),
            'citations' => $packet['sources'],
            'checked_evidence' => $packet['checked_evidence'],
            'evidence_packet' => $packet,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastAnonymizedPayload(): array
    {
        return $this->lastAnonymizedPayload;
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
        $this->lastAnonymizedPayload = $this->anonymizedPayload($intent, $accessToken, $packet);

        return [
            'status' => 'source_required',
            'response_generation' => 'deterministic_evidence_packet',
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
                'followup_intents' => [],
            ],
            'citations' => [],
            'checked_evidence' => [],
            'evidence_packet' => $packet,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $packet
     * @return array<string, mixed>
     */
    private function anonymizedPayload(array $intent, AgentAccessToken $accessToken, array $packet): array
    {
        return [
            'payload_version' => 'agent.anonymized.v1',
            'intent_id' => (string) $intent['intent_id'],
            'prompt_text' => $this->anonymizer->anonymizePayload($accessToken, (string) $intent['prompt_text']),
            'evidence_packet' => $this->anonymizer->anonymizeEvidencePacket($accessToken, $packet),
            'placeholder_count' => $this->anonymizer->placeholderCount($accessToken),
        ];
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
                'text' => 'No matching records were found in the bounded evidence checked for this intent.',
                'citation_ids' => [],
                'certainty' => 'not_found',
            ];
            $missingOrUncertain[] = [
                'text' => 'This does not prove absence from the full chart; it only reflects the bounded evidence retrieved for this request.',
                'citation_ids' => [],
            ];
        } elseif ((string) $intent['intent_id'] !== AgentIntentCatalog::SHOW_SOURCE) {
            $missingOrUncertain[] = [
                'text' => 'This phase-three response lists source records only; LLM synthesis and verification are not connected yet.',
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
            'followup_intents' => $this->followupIntents((string) $intent['intent_id']),
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

    /**
     * @return list<string>
     */
    private function followupIntents(string $intentId): array
    {
        return match ($intentId) {
            AgentIntentCatalog::BASIC_PATIENT_DATA => [
                AgentIntentCatalog::CURRENT_MEDICATIONS,
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
            ],
            AgentIntentCatalog::CURRENT_MEDICATIONS => [
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
            AgentIntentCatalog::ALLERGIES_TO_CONFIRM => [
                AgentIntentCatalog::CURRENT_MEDICATIONS,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
            AgentIntentCatalog::RECENT_EVENTS => [
                AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
            default => [],
        };
    }
}
