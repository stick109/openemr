<?php

/**
 * AgentLlmProviderFactory
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Llm;

final class AgentLlmProviderFactory
{
    public function create(?AgentLlmConfig $config = null): AgentLlmProviderInterface
    {
        $config ??= AgentLlmConfig::fromEnvironment();

        if ($config->isConfigured()) {
            return match ($config->getProvider()) {
                AgentLlmConfig::PROVIDER_OPENAI => new OpenAiResponsesAgentLlmProvider($config),
                default => new DisabledAgentLlmProvider(
                    $config->getProvider(),
                    $config->getModel(),
                    'unsupported_provider'
                ),
            };
        }

        return new DisabledAgentLlmProvider(
            $config->getProvider(),
            $config->getModel(),
            $config->getConfigurationIssue()
        );
    }
}
