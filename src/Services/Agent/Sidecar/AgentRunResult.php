<?php

/**
 * AgentRunResult
 *
 * Typed DTO for a successful response from the Python agent-service
 * sidecar's `POST /api/agent/run` endpoint. The field names use camelCase
 * on the PHP side; the constructor accepts the snake_case wire format and
 * maps it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

final readonly class AgentRunResult
{
    /**
     * @param array<string, mixed>  $extracted            Structured extraction keyed by doc_type schema.
     * @param list<array<string, mixed>> $evidence        Retrieved guideline snippets with citation metadata.
     * @param string                $answer               Natural-language clinical summary.
     * @param list<array<string, mixed>> $citations       Citation objects linking answer to sources.
     * @param float                 $costUsd              Estimated run cost in USD.
     * @param array<string, int>    $latencyMsPerStep     Timing breakdown keyed by step name.
     * @param list<string>          $toolSequence         Ordered list of tool/worker names invoked.
     * @param float                 $extractionConfidence Model confidence in [0.0, 1.0].
     */
    public function __construct(
        public array $extracted,
        public array $evidence,
        public string $answer,
        public array $citations,
        public float $costUsd,
        public array $latencyMsPerStep,
        public array $toolSequence,
        public float $extractionConfidence,
    ) {
    }

    /**
     * Parse the sidecar's JSON response body (already decoded) into a
     * typed result. Unknown keys are silently ignored so the PHP side is
     * forward-compatible when the sidecar adds new telemetry fields.
     *
     * @param array<string, mixed> $data Decoded JSON from the sidecar.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            extracted: self::arrayField($data, 'extracted'),
            evidence: self::listField($data, 'evidence'),
            answer: self::stringField($data, 'answer'),
            citations: self::listField($data, 'citations'),
            costUsd: self::floatField($data, 'cost_usd'),
            latencyMsPerStep: self::arrayField($data, 'latency_ms_per_step'),
            toolSequence: self::stringListField($data, 'tool_sequence'),
            extractionConfidence: self::floatField($data, 'extraction_confidence'),
        );
    }

    // ------------------------------------------------------------------
    // Private parsing helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function arrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function listField(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function stringListField(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function floatField(array $data, string $key): float
    {
        $value = $data[$key] ?? 0.0;
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
