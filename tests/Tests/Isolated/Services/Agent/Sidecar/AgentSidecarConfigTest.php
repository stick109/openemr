<?php

/**
 * AgentSidecarConfigTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use OpenEMR\Core\OEEnvBag;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentSidecarConfigTest extends TestCase
{
    public function testDefaultValuesWhenNoEnvVarsAreSet(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([]));

        $this->assertSame('http://agent-service:8010', $config->getUrl());
        $this->assertSame('', $config->getSharedSecret());
        $this->assertSame(60, $config->getTimeoutSeconds());
        $this->assertSame('', $config->getCohereApiKey());
        $this->assertSame('', $config->getHoneycombApiKey());
    }

    public function testIsConfiguredRequiresUrlAndSecret(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_URL' => 'http://localhost:8010',
            'OPENEMR_AGENT_SIDECAR_SECRET' => 'test-secret',
        ]));

        $this->assertTrue($config->isConfigured());
        $this->assertNull($config->getConfigurationIssue());
    }

    public function testMissingSecretReportsConfigurationIssue(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_URL' => 'http://localhost:8010',
        ]));

        $this->assertFalse($config->isConfigured());
        $this->assertSame('missing_shared_secret', $config->getConfigurationIssue());
    }

    public function testBlankTimeoutFallsBackToDefault(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS' => '',
        ]));

        $this->assertSame(60, $config->getTimeoutSeconds());
    }

    public function testInvalidTimeoutFallsBackToDefault(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS' => 'not-a-number',
        ]));

        $this->assertSame(60, $config->getTimeoutSeconds());
    }

    public function testCustomTimeoutIsAccepted(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS' => '120',
        ]));

        $this->assertSame(120, $config->getTimeoutSeconds());
    }

    public function testLeadingBomIsRemovedFromValues(): void
    {
        $bom = "\u{FEFF}";
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_URL' => $bom . 'http://sidecar:9000',
            'OPENEMR_AGENT_SIDECAR_SECRET' => $bom . 'my-secret',
            'OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS' => $bom . '30',
            'COHERE_API_KEY' => $bom . 'cohere-key',
            'HONEYCOMB_API_KEY' => $bom . 'honeycomb-key',
        ]));

        $this->assertSame('http://sidecar:9000', $config->getUrl());
        $this->assertSame('my-secret', $config->getSharedSecret());
        $this->assertSame(30, $config->getTimeoutSeconds());
        $this->assertSame('cohere-key', $config->getCohereApiKey());
        $this->assertSame('honeycomb-key', $config->getHoneycombApiKey());
    }

    public function testTrailingSlashIsStrippedFromUrl(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_URL' => 'http://localhost:8010/',
        ]));

        $this->assertSame('http://localhost:8010', $config->getUrl());
    }

    public function testAllSecretsAreReadFromEnvironment(): void
    {
        $config = AgentSidecarConfig::fromEnvironment(new OEEnvBag([
            'OPENEMR_AGENT_SIDECAR_URL' => 'http://sidecar:8010',
            'OPENEMR_AGENT_SIDECAR_SECRET' => 'shared-secret-value',
            'COHERE_API_KEY' => 'cohere-api-key-value',
            'HONEYCOMB_API_KEY' => 'honeycomb-api-key-value',
        ]));

        $this->assertSame('shared-secret-value', $config->getSharedSecret());
        $this->assertSame('cohere-api-key-value', $config->getCohereApiKey());
        $this->assertSame('honeycomb-api-key-value', $config->getHoneycombApiKey());
    }
}
