<?php

/**
 * AgentLlmResponse
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

final class AgentLlmResponse
{
    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $usage
     * @param array{input_tokens: int, output_tokens: int, total_tokens: int}|null $tokenCounters
     * @param array<string, mixed>|null $costCounters
     */
    public function __construct(
        private readonly array $answer,
        private readonly string $providerName,
        private readonly string $modelName,
        private readonly array $usage,
        private readonly ?string $providerResponseId = null,
        private readonly ?array $tokenCounters = null,
        private readonly ?array $costCounters = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswer(): array
    {
        return $this->answer;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'provider' => $this->providerName,
            'model' => $this->modelName,
            'configured' => true,
            'used' => true,
            'provider_response_id' => $this->providerResponseId,
            'usage' => $this->usage,
            'token_counters' => $this->tokenCounters ?? AgentLlmUsage::tokenCounters($this->usage),
            'cost_counters' => $this->costCounters ?? AgentLlmUsage::emptyCostCounters(),
        ];
    }
}
