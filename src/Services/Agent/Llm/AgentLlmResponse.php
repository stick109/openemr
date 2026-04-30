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
     */
    public function __construct(
        private readonly array $answer,
        private readonly string $providerName,
        private readonly string $modelName,
        private readonly array $usage,
        private readonly ?string $providerResponseId = null
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
        ];
    }
}
