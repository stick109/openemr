<?php

/**
 * DisabledAgentLlmProvider
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

final class DisabledAgentLlmProvider implements AgentLlmProviderInterface
{
    public function __construct(
        private readonly string $providerName = AgentLlmConfig::PROVIDER_DISABLED,
        private readonly string $modelName = '',
        private readonly ?string $configurationIssue = 'provider_disabled'
    ) {
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return $this->providerName !== '' ? $this->providerName : AgentLlmConfig::PROVIDER_DISABLED;
    }

    public function getModelName(): string
    {
        return $this->modelName;
    }

    public function getConfigurationIssue(): ?string
    {
        return $this->configurationIssue;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestPayload(AgentLlmRequest $request): array
    {
        return [];
    }

    public function complete(AgentLlmRequest $request): AgentLlmResponse
    {
        throw new AgentLlmProviderException('Agent LLM provider is not configured.');
    }
}
