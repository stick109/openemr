<?php

/**
 * CopilotRunResponseDto
 *
 * Read-only DTO mirroring the Python ``CopilotRunResponse`` schema in
 * ``agent-service/agent_service/schemas/copilot.py``. The PHP UI proxy
 * decodes the sidecar's JSON response into this DTO so renderers can
 * work with typed objects rather than nested arrays.
 *
 * Unknown keys are silently ignored so the PHP side stays
 * forward-compatible when the sidecar adds new telemetry fields.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

final readonly class CopilotRunResponseDto
{
    public const VERIFICATION_STATUSES = ['passed', 'refused', 'error'];

    /**
     * @param list<AnswerBlockDto>     $answerBlocks
     * @param list<string>             $missingOrUncertain
     * @param list<CitationDto>        $citations
     * @param list<ToolCallRecordDto>  $toolSequence
     * @param string                   $verificationStatus  One of ``passed``, ``refused``, ``error``.
     * @param float                    $costUsd
     * @param array<string, int>       $latencyMsPerStep
     * @param string                   $traceId
     */
    public function __construct(
        public array $answerBlocks,
        public array $missingOrUncertain,
        public array $citations,
        public array $toolSequence,
        public string $verificationStatus,
        public float $costUsd,
        public array $latencyMsPerStep,
        public string $traceId,
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded JSON body from the sidecar.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            answerBlocks: self::parseAnswerBlocks($data['answer_blocks'] ?? []),
            missingOrUncertain: self::parseStringList($data['missing_or_uncertain'] ?? []),
            citations: self::parseCitations($data['citations'] ?? []),
            toolSequence: self::parseToolSequence($data['tool_sequence'] ?? []),
            verificationStatus: self::parseStatus($data['verification_status'] ?? null),
            costUsd: self::parseFloat($data['cost_usd'] ?? 0.0),
            latencyMsPerStep: self::parseLatencyMap($data['latency_ms_per_step'] ?? []),
            traceId: is_string($data['trace_id'] ?? null) ? $data['trace_id'] : '',
        );
    }

    // ------------------------------------------------------------------
    // Private parsing helpers
    // ------------------------------------------------------------------

    /**
     * @param mixed $raw
     * @return list<AnswerBlockDto>
     */
    private static function parseAnswerBlocks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $blocks = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $blocks[] = AnswerBlockDto::fromArray($item);
            }
        }
        return $blocks;
    }

    /**
     * @param mixed $raw
     * @return list<CitationDto>
     */
    private static function parseCitations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $citations = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $citations[] = CitationDto::fromArray($item);
            }
        }
        return $citations;
    }

    /**
     * @param mixed $raw
     * @return list<ToolCallRecordDto>
     */
    private static function parseToolSequence(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $records[] = ToolCallRecordDto::fromArray($item);
            }
        }
        return $records;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function parseStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $strings = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }
        return $strings;
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private static function parseLatencyMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $map[$key] = $value;
            }
        }
        return $map;
    }

    private static function parseFloat(mixed $raw): float
    {
        if (is_float($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return (float) $raw;
        }
        return 0.0;
    }

    private static function parseStatus(mixed $raw): string
    {
        if (is_string($raw) && in_array($raw, self::VERIFICATION_STATUSES, true)) {
            return $raw;
        }
        return 'error';
    }
}
