<?php

/**
 * AgentIntentCatalog
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent;

final class AgentIntentCatalog
{
    public const BASIC_PATIENT_DATA = 'basic_patient_data';
    public const CURRENT_MEDICATIONS = 'current_medications';
    public const ALLERGIES_TO_CONFIRM = 'allergies_to_confirm';
    public const RECENT_EVENTS = 'recent_events';
    public const CHANGED_SINCE_LAST_VISIT = 'changed_since_last_visit';
    public const SHOW_SOURCE = 'show_source';
    public const FREE_TEXT = 'free_text';

    /**
     * @var array<string, array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * }>
     */
    private const INTENTS = [
        self::BASIC_PATIENT_DATA => [
            'intent_id' => self::BASIC_PATIENT_DATA,
            'button_label' => 'Basic patient data',
            'prompt_text' => 'Show me basic patient data.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D1'],
            'max_records' => 10,
            'max_documents' => 0,
            'lookback_days' => 0,
        ],
        self::CURRENT_MEDICATIONS => [
            'intent_id' => self::CURRENT_MEDICATIONS,
            'button_label' => 'Current medications',
            'prompt_text' => 'Show me current medications.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D1'],
            'max_records' => 25,
            'max_documents' => 0,
            'lookback_days' => 365,
        ],
        self::ALLERGIES_TO_CONFIRM => [
            'intent_id' => self::ALLERGIES_TO_CONFIRM,
            'button_label' => 'Allergies to confirm',
            'prompt_text' => 'Show me allergies to confirm.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D1'],
            'max_records' => 25,
            'max_documents' => 0,
            'lookback_days' => 365,
        ],
        self::RECENT_EVENTS => [
            'intent_id' => self::RECENT_EVENTS,
            'button_label' => 'Recent events',
            'prompt_text' => 'Show me recent events.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D1', 'D2'],
            'max_records' => 30,
            'max_documents' => 5,
            'lookback_days' => 180,
        ],
        self::CHANGED_SINCE_LAST_VISIT => [
            'intent_id' => self::CHANGED_SINCE_LAST_VISIT,
            'button_label' => 'Changed since last visit',
            'prompt_text' => 'Explain what changed since the last visit.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D2'],
            'max_records' => 30,
            'max_documents' => 5,
            'lookback_days' => 365,
        ],
        self::SHOW_SOURCE => [
            'intent_id' => self::SHOW_SOURCE,
            'button_label' => 'Show source',
            'prompt_text' => 'Show source evidence behind this claim.',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D4'],
            'max_records' => 1,
            'max_documents' => 1,
            'lookback_days' => 0,
        ],
        self::FREE_TEXT => [
            'intent_id' => self::FREE_TEXT,
            'button_label' => 'Free text',
            'prompt_text' => '',
            'primary_users' => ['doctor'],
            'use_case_traces' => ['D1', 'D2'],
            'max_records' => 30,
            'max_documents' => 5,
            'lookback_days' => 365,
        ],
    ];

    /**
     * @return list<array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * }>
     */
    public function all(): array
    {
        return array_values(self::INTENTS);
    }

    public function has(string $intentId): bool
    {
        return isset(self::INTENTS[$intentId]);
    }

    /**
     * @return array{
     *     intent_id: string,
     *     button_label: string,
     *     prompt_text: string,
     *     primary_users: list<string>,
     *     use_case_traces: list<string>,
     *     max_records: int,
     *     max_documents: int,
     *     lookback_days: int
     * }|null
     */
    public function get(string $intentId): ?array
    {
        return self::INTENTS[$intentId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function intentIds(): array
    {
        return array_keys(self::INTENTS);
    }
}
