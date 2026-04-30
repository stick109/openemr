<?php

/**
 * AgentAnswerSchema
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

final class AgentAnswerSchema
{
    /**
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $citationIds = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['answer_blocks', 'missing_or_uncertain', 'followup_intents'],
            'properties' => [
                'answer_blocks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['heading', 'claims'],
                        'properties' => [
                            'heading' => ['type' => 'string'],
                            'claims' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['text', 'citation_ids', 'certainty'],
                                    'properties' => [
                                        'text' => ['type' => 'string'],
                                        'citation_ids' => $citationIds,
                                        'certainty' => [
                                            'type' => 'string',
                                            'enum' => [
                                                'supported',
                                                'conflicting',
                                                'not_found',
                                                'not_checked',
                                                'source_record',
                                                'active',
                                                'inactive',
                                                'unknown',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'missing_or_uncertain' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'citation_ids'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'citation_ids' => $citationIds,
                        ],
                    ],
                ],
                'followup_intents' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    public function normalize(array $answer): array
    {
        return [
            'answer_blocks' => $this->answerBlocks($answer['answer_blocks'] ?? []),
            'missing_or_uncertain' => $this->missingOrUncertain($answer['missing_or_uncertain'] ?? []),
            'followup_intents' => $this->stringList($answer['followup_intents'] ?? []),
        ];
    }

    /**
     * @return list<array{heading: string, claims: list<array{text: string, citation_ids: list<string>, certainty: string}>}>
     */
    private function answerBlocks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $blocks = [];
        foreach ($value as $block) {
            if (!is_array($block)) {
                continue;
            }

            $claims = [];
            if (is_array($block['claims'] ?? null)) {
                foreach ($block['claims'] as $claim) {
                    if (!is_array($claim)) {
                        continue;
                    }

                    $claims[] = [
                        'text' => $this->stringValue($claim['text'] ?? ''),
                        'citation_ids' => $this->stringList($claim['citation_ids'] ?? []),
                        'certainty' => $this->stringValue($claim['certainty'] ?? 'unknown'),
                    ];
                }
            }

            $blocks[] = [
                'heading' => $this->stringValue($block['heading'] ?? ''),
                'claims' => $claims,
            ];
        }

        return $blocks;
    }

    /**
     * @return list<array{text: string, citation_ids: list<string>}>
     */
    private function missingOrUncertain(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = [
                'text' => $this->stringValue($item['text'] ?? ''),
                'citation_ids' => $this->stringList($item['citation_ids'] ?? []),
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = $this->stringValue($item);
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
