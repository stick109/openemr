<?php

/**
 * AgentIntentPlaceholderResponseBuilder
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

use InvalidArgumentException;

final class AgentIntentPlaceholderResponseBuilder
{
    /**
     * @var array<string, array{
     *     heading: string,
     *     text: string,
     *     missing: string,
     *     followup_intents: list<string>
     * }>
     */
    private const PLACEHOLDERS = [
        AgentIntentCatalog::BASIC_PATIENT_DATA => [
            'heading' => 'Basic patient data',
            'text' => 'Basic patient demographics and visit context were not checked by this phase-one placeholder.',
            'missing' => 'Demographics evidence is not connected yet; no chart facts were evaluated.',
            'followup_intents' => [
                AgentIntentCatalog::CURRENT_MEDICATIONS,
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
            ],
        ],
        AgentIntentCatalog::CURRENT_MEDICATIONS => [
            'heading' => 'Current medications',
            'text' => 'Current medication evidence is not connected yet; no medication claims are made.',
            'missing' => 'Medication records were not checked by this placeholder response.',
            'followup_intents' => [
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
        ],
        AgentIntentCatalog::ALLERGIES_TO_CONFIRM => [
            'heading' => 'Allergies to confirm',
            'text' => 'Allergy evidence is not connected yet; no allergy claims are made.',
            'missing' => 'Allergy records were not checked by this placeholder response.',
            'followup_intents' => [
                AgentIntentCatalog::CURRENT_MEDICATIONS,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
        ],
        AgentIntentCatalog::RECENT_EVENTS => [
            'heading' => 'Recent events',
            'text' => 'Recent encounters, results, documents, and intake events are not connected yet.',
            'missing' => 'Timeline evidence was not checked by this placeholder response.',
            'followup_intents' => [
                AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
        ],
        AgentIntentCatalog::INTAKE_CHECKLIST => [
            'heading' => 'Intake checklist',
            'text' => 'The intake checklist tool is not connected yet; no rooming tasks are generated from chart evidence.',
            'missing' => 'Appointment, medication, allergy, vital, and intake evidence were not checked.',
            'followup_intents' => [
                AgentIntentCatalog::CURRENT_MEDICATIONS,
                AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
            ],
        ],
        AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT => [
            'heading' => 'Changed since last visit',
            'text' => 'The change-comparison tool is not connected yet; no chart changes are reported.',
            'missing' => 'Last-visit and newer evidence were not checked by this placeholder response.',
            'followup_intents' => [
                AgentIntentCatalog::RECENT_EVENTS,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
        ],
        AgentIntentCatalog::INTAKE_HANDOFF => [
            'heading' => 'Intake handoff',
            'text' => 'The intake handoff tool is not connected yet; no nurse intake flags are summarized.',
            'missing' => 'Nurse intake, vital, medication, and allergy evidence were not checked.',
            'followup_intents' => [
                AgentIntentCatalog::INTAKE_CHECKLIST,
                AgentIntentCatalog::SHOW_SOURCE,
            ],
        ],
        AgentIntentCatalog::SHOW_SOURCE => [
            'heading' => 'Show source',
            'text' => 'Source drilldown is not connected yet; no citation record was requested or evaluated.',
            'missing' => 'No source record was checked by this placeholder response.',
            'followup_intents' => [],
        ],
    ];

    public function hasPlaceholderFor(string $intentId): bool
    {
        return isset(self::PLACEHOLDERS[$intentId]);
    }

    /**
     * @return array{
     *     status: string,
     *     response_generation: string,
     *     answer: array{
     *         answer_blocks: list<array{
     *             heading: string,
     *             claims: list<array{
     *                 text: string,
     *                 citation_ids: list<string>,
     *                 certainty: string
     *             }>
     *         }>,
     *         missing_or_uncertain: list<array{
     *             text: string,
     *             citation_ids: list<string>
     *         }>,
     *         followup_intents: list<string>
     *     },
     *     citations: list<array<string, mixed>>,
     *     checked_evidence: list<string>
     * }
     */
    public function build(string $intentId): array
    {
        $placeholder = self::PLACEHOLDERS[$intentId] ?? null;
        if ($placeholder === null) {
            throw new InvalidArgumentException('Unknown placeholder intent_id.');
        }

        return [
            'status' => 'placeholder',
            'response_generation' => 'deterministic_placeholder',
            'answer' => [
                'answer_blocks' => [
                    [
                        'heading' => $placeholder['heading'],
                        'claims' => [
                            [
                                'text' => $placeholder['text'],
                                'citation_ids' => [],
                                'certainty' => 'not_checked',
                            ],
                        ],
                    ],
                ],
                'missing_or_uncertain' => [
                    [
                        'text' => $placeholder['missing'],
                        'citation_ids' => [],
                    ],
                ],
                'followup_intents' => $placeholder['followup_intents'],
            ],
            'citations' => [],
            'checked_evidence' => [],
        ];
    }
}
