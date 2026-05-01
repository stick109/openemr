<?php

/**
 * AgentLlmUsage
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

final class AgentLlmUsage
{
    /**
     * @param array<string, mixed> $usage
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public static function tokenCounters(array $usage): array
    {
        $inputTokens = self::intFromKeys($usage, ['input_tokens', 'prompt_tokens']);
        $outputTokens = self::intFromKeys($usage, ['output_tokens', 'completion_tokens']);
        $totalTokens = self::intFromKeys($usage, ['total_tokens']);

        if ($totalTokens === 0 && ($inputTokens > 0 || $outputTokens > 0)) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * @param array{input_tokens: int, output_tokens: int, total_tokens: int} $tokenCounters
     * @return array{
     *     currency: string,
     *     rates_configured: bool,
     *     input_cost_per_1m_tokens: float,
     *     output_cost_per_1m_tokens: float,
     *     input_cost_usd: float,
     *     output_cost_usd: float,
     *     total_cost_usd: float
     * }
     */
    public static function costCounters(
        array $tokenCounters,
        float $inputCostPer1MTokens,
        float $outputCostPer1MTokens
    ): array {
        $inputCost = self::cost($tokenCounters['input_tokens'], $inputCostPer1MTokens);
        $outputCost = self::cost($tokenCounters['output_tokens'], $outputCostPer1MTokens);

        return [
            'currency' => 'USD',
            'rates_configured' => $inputCostPer1MTokens > 0.0 || $outputCostPer1MTokens > 0.0,
            'input_cost_per_1m_tokens' => $inputCostPer1MTokens,
            'output_cost_per_1m_tokens' => $outputCostPer1MTokens,
            'input_cost_usd' => $inputCost,
            'output_cost_usd' => $outputCost,
            'total_cost_usd' => round($inputCost + $outputCost, 8),
        ];
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public static function emptyTokenCounters(): array
    {
        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    /**
     * @return array{
     *     currency: string,
     *     rates_configured: bool,
     *     input_cost_per_1m_tokens: float,
     *     output_cost_per_1m_tokens: float,
     *     input_cost_usd: float,
     *     output_cost_usd: float,
     *     total_cost_usd: float
     * }
     */
    public static function emptyCostCounters(): array
    {
        return self::costCounters(self::emptyTokenCounters(), 0.0, 0.0);
    }

    /**
     * @param array<string, mixed> $usage
     * @param list<string> $keys
     */
    private static function intFromKeys(array $usage, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $usage[$key] ?? null;
            if (is_int($value)) {
                return max(0, $value);
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private static function cost(int $tokens, float $costPer1MTokens): float
    {
        if ($tokens <= 0 || $costPer1MTokens <= 0.0) {
            return 0.0;
        }

        return round(($tokens / 1000000) * $costPer1MTokens, 8);
    }
}
