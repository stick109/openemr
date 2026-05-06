<?php

/**
 * ToolCallRecordDto
 *
 * Read-only DTO mirroring the Python ``ToolCallRecord`` schema in
 * ``agent-service/agent_service/schemas/copilot.py``. Carries the
 * PHI-safe trace of one tool invocation in the agent loop. Critically,
 * ``argumentsKeys`` records keys only -- never values -- so this DTO is
 * always safe to log.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

final readonly class ToolCallRecordDto
{
    /**
     * @param string       $toolName       Tool name as registered in the Python registry.
     * @param list<string> $argumentsKeys  Whitelisted argument keys (no values).
     * @param int|null     $resultCount    Row/result count, or null when not applicable.
     * @param int          $latencyMs      Wall-clock latency in milliseconds (>=0).
     * @param string|null  $errorClass     Fully-qualified exception class on failure, null on success.
     */
    public function __construct(
        public string $toolName,
        public array $argumentsKeys,
        public ?int $resultCount,
        public int $latencyMs,
        public ?string $errorClass,
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded ``tool_sequence[i]`` payload.
     */
    public static function fromArray(array $data): self
    {
        $rawKeys = $data['arguments_keys'] ?? [];
        $keys = [];
        if (is_array($rawKeys)) {
            foreach ($rawKeys as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        }

        $resultCount = $data['result_count'] ?? null;
        $resultCountTyped = is_int($resultCount) ? $resultCount : null;

        $latencyMs = $data['latency_ms'] ?? 0;
        $latencyTyped = is_int($latencyMs) ? $latencyMs : 0;

        return new self(
            toolName: is_string($data['tool_name'] ?? null) ? $data['tool_name'] : '',
            argumentsKeys: $keys,
            resultCount: $resultCountTyped,
            latencyMs: $latencyTyped,
            errorClass: isset($data['error_class']) && is_string($data['error_class']) ? $data['error_class'] : null,
        );
    }
}
