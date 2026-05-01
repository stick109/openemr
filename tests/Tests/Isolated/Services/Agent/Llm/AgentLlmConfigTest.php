<?php

/**
 * AgentLlmConfigTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Llm;

use OpenEMR\Core\OEEnvBag;
use OpenEMR\Services\Agent\Llm\AgentLlmConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentLlmConfigTest extends TestCase
{
    public function testBlankOptionalTimeoutFallsBackToDefault(): void
    {
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => 'true',
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => 'server-side-secret',
            'OPENEMR_AGENT_LLM_TIMEOUT_SECONDS' => '',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame(20, $config->getTimeoutSeconds());
    }

    public function testInvalidOptionalTimeoutFallsBackToDefault(): void
    {
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => 'true',
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => 'server-side-secret',
            'OPENEMR_AGENT_LLM_TIMEOUT_SECONDS' => 'not-a-number',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame(20, $config->getTimeoutSeconds());
    }

    public function testDedicatedAgentApiKeyIsAcceptedAsOpenAiFallback(): void
    {
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => 'true',
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => '',
            'OPENEMR_AGENT_LLM_API_KEY' => 'server-side-secret',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame('server-side-secret', $config->getApiKey());
    }

    public function testLeadingBomIsRemovedFromEnvironmentValues(): void
    {
        $bom = "\u{FEFF}";
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => $bom . 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => $bom . 'true',
            'OPENEMR_AGENT_LLM_MODEL' => $bom . 'gpt-test',
            'OPENAI_API_KEY' => $bom . 'server-side-secret',
            'OPENEMR_AGENT_LLM_TIMEOUT_SECONDS' => $bom . '10',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame('openai', $config->getProvider());
        $this->assertSame('gpt-test', $config->getModel());
        $this->assertSame('server-side-secret', $config->getApiKey());
        $this->assertSame(10, $config->getTimeoutSeconds());
    }

    public function testExternalCallsKillSwitchDisablesConfiguredProvider(): void
    {
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => 'false',
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => 'server-side-secret',
        ]));

        $this->assertFalse($config->isConfigured());
        $this->assertSame('external_calls_disabled', $config->getConfigurationIssue());
    }

    public function testReadsConfiguredTokenCostRates(): void
    {
        $config = AgentLlmConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_LLM_PROVIDER' => 'openai',
            'OPENEMR_AGENT_LLM_EXTERNAL_CALLS_ENABLED' => 'true',
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => 'server-side-secret',
            'OPENEMR_AGENT_LLM_INPUT_COST_PER_1M_TOKENS' => '2.50',
            'OPENEMR_AGENT_LLM_OUTPUT_COST_PER_1M_TOKENS' => '10',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame(2.5, $config->getInputCostPer1MTokens());
        $this->assertSame(10.0, $config->getOutputCostPer1MTokens());
    }
}
