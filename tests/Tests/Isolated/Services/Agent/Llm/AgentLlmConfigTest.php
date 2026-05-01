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
            'OPENEMR_AGENT_LLM_MODEL' => 'gpt-test',
            'OPENAI_API_KEY' => '',
            'OPENEMR_AGENT_LLM_API_KEY' => 'server-side-secret',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertSame('server-side-secret', $config->getApiKey());
    }
}
