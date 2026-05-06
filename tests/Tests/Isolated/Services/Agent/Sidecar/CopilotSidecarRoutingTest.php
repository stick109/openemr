<?php

/**
 * CopilotSidecarRoutingTest
 *
 * Isolated coverage for the M19 per-intent cutover routing.
 *
 * Validates the precedence rules in :class:`CopilotSidecarRouting`:
 * emergency disable beats per-intent overrides which beat the default
 * mode. Backwards compatibility with the M17/M18 boolean flags is
 * exercised so legacy configurations keep working without manual
 * migration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use OpenEMR\Services\Agent\AgentIntentCatalog;
use OpenEMR\Services\Agent\Sidecar\CopilotSidecarRouting;
use OpenEMR\Services\Agent\Sidecar\IntentMode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
final class CopilotSidecarRoutingTest extends TestCase
{
    public function testDefaultConfigRoutesEveryIntentToPhp(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([]);

        foreach ((new AgentIntentCatalog())->intentIds() as $intentId) {
            $this->assertSame(
                IntentMode::Php,
                $routing->modeFor($intentId),
                sprintf('Intent %s must default to PHP mode when nothing is configured', $intentId),
            );
        }
        $this->assertFalse($routing->emergencyDisable);
        $this->assertSame([], $routing->perIntent);
        $this->assertSame(IntentMode::Php, $routing->defaultMode);
    }

    public function testDefaultModeSidecarRoutesEveryIntentToSidecar(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'sidecar',
        ]);

        $this->assertSame(IntentMode::Sidecar, $routing->defaultMode);
        foreach ((new AgentIntentCatalog())->intentIds() as $intentId) {
            $this->assertSame(IntentMode::Sidecar, $routing->modeFor($intentId));
        }
    }

    public function testPerIntentOverrideTakesPrecedenceOverDefault(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'php',
            'OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA' => 'sidecar',
        ]);

        $this->assertSame(
            IntentMode::Sidecar,
            $routing->modeFor(AgentIntentCatalog::BASIC_PATIENT_DATA),
            'Per-intent override must win over the default',
        );
        $this->assertSame(
            IntentMode::Php,
            $routing->modeFor(AgentIntentCatalog::CURRENT_MEDICATIONS),
            'Other intents must keep the default mode',
        );
        $this->assertSame(
            IntentMode::Php,
            $routing->modeFor(AgentIntentCatalog::ALLERGIES_TO_CONFIRM),
        );
    }

    public function testEmergencyDisableForcesEveryIntentToPhp(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_EMERGENCY_DISABLE' => '1',
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'sidecar',
            'OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA' => 'sidecar',
            'OPENEMR_COPILOT_INTENT_MODE_CURRENT_MEDICATIONS' => 'shadow',
        ]);

        $this->assertTrue($routing->emergencyDisable);
        foreach ((new AgentIntentCatalog())->intentIds() as $intentId) {
            $this->assertSame(
                IntentMode::Php,
                $routing->modeFor($intentId),
                sprintf('Emergency disable must force %s back to PHP', $intentId),
            );
        }
    }

    public function testLegacyProxyFlagMapsToSidecarDefaultMode(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED' => '1',
        ]);

        $this->assertSame(IntentMode::Sidecar, $routing->defaultMode);
        $this->assertSame(
            IntentMode::Sidecar,
            $routing->modeFor(AgentIntentCatalog::CURRENT_MEDICATIONS),
        );
    }

    public function testLegacyShadowFlagMapsToShadowDefaultMode(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED' => '1',
        ]);

        $this->assertSame(IntentMode::Shadow, $routing->defaultMode);
        $this->assertSame(
            IntentMode::Shadow,
            $routing->modeFor(AgentIntentCatalog::BASIC_PATIENT_DATA),
        );
    }

    public function testLegacyProxyAndShadowFlagsBothSetPrefersProxy(): void
    {
        // When both legacy flags are set the proxy flag wins because the
        // sidecar is already authoritative -- shadow comparison would be
        // moot in that situation.
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED' => '1',
            'OPENEMR_COPILOT_SIDECAR_SHADOW_ENABLED' => '1',
        ]);

        $this->assertSame(IntentMode::Sidecar, $routing->defaultMode);
    }

    public function testExplicitDefaultModeWinsOverLegacyFlags(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'php',
            'OPENEMR_COPILOT_SIDECAR_PROXY_ENABLED' => '1',
        ]);

        $this->assertSame(
            IntentMode::Php,
            $routing->defaultMode,
            'Explicit OPENEMR_COPILOT_DEFAULT_MODE must take precedence over legacy boolean flags',
        );
    }

    public function testMixedConfigCombinesDefaultAndPerIntent(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'shadow',
            'OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA' => 'sidecar',
            'OPENEMR_COPILOT_INTENT_MODE_RECENT_EVENTS' => 'php',
        ]);

        $this->assertSame(
            IntentMode::Sidecar,
            $routing->modeFor(AgentIntentCatalog::BASIC_PATIENT_DATA),
        );
        $this->assertSame(
            IntentMode::Php,
            $routing->modeFor(AgentIntentCatalog::RECENT_EVENTS),
        );
        $this->assertSame(
            IntentMode::Shadow,
            $routing->modeFor(AgentIntentCatalog::CURRENT_MEDICATIONS),
            'Intents with no override must inherit the default mode',
        );
    }

    public function testInvalidPerIntentValueFallsBackToDefaultMode(): void
    {
        // An operator misconfiguration must not crash the route. Choose
        // safety over guessing -- skip the bad entry and use the default.
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'php',
            'OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA' => 'garbage',
        ]);

        $this->assertSame(
            IntentMode::Php,
            $routing->modeFor(AgentIntentCatalog::BASIC_PATIENT_DATA),
            'A malformed per-intent value must fall back to the default mode',
        );
        $this->assertArrayNotHasKey(
            AgentIntentCatalog::BASIC_PATIENT_DATA,
            $routing->perIntent,
            'Malformed per-intent values must NOT be persisted in the per-intent map',
        );
    }

    public function testInvalidDefaultModeFallsBackToPhp(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'oops',
        ]);

        $this->assertSame(IntentMode::Php, $routing->defaultMode);
    }

    public function testCaseInsensitiveModeParsing(): void
    {
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'SiDeCaR',
            'OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA' => '  shadow ',
        ]);

        $this->assertSame(IntentMode::Sidecar, $routing->defaultMode);
        $this->assertSame(
            IntentMode::Shadow,
            $routing->modeFor(AgentIntentCatalog::BASIC_PATIENT_DATA),
        );
    }

    public function testIntentIdMatchingIsCaseFolded(): void
    {
        // Per-intent env vars are upper-snake by convention; the lookup
        // happens against the lower-snake intent IDs in the catalog.
        $routing = CopilotSidecarRouting::fromEnv([
            'OPENEMR_COPILOT_INTENT_MODE_CHANGED_SINCE_LAST_VISIT' => 'sidecar',
        ]);

        $this->assertSame(
            IntentMode::Sidecar,
            $routing->modeFor(AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT),
        );
    }

    public function testEmergencyDisableAcceptsCommonTruthyValues(): void
    {
        foreach (['1', 'true', 'on', 'yes', 'TRUE', ' YES '] as $truthy) {
            $routing = CopilotSidecarRouting::fromEnv([
                'OPENEMR_COPILOT_EMERGENCY_DISABLE' => $truthy,
                'OPENEMR_COPILOT_DEFAULT_MODE' => 'sidecar',
            ]);
            $this->assertTrue(
                $routing->emergencyDisable,
                sprintf('Value %s must be parsed as a truthy emergency-disable flag', var_export($truthy, true)),
            );
            $this->assertSame(
                IntentMode::Php,
                $routing->modeFor(AgentIntentCatalog::CURRENT_MEDICATIONS),
            );
        }
    }
}
