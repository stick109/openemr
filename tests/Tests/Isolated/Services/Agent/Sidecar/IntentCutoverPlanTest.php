<?php

/**
 * IntentCutoverPlanTest
 *
 * Isolated coverage for the M20 cutover plan: drives every read-only
 * intent from PHP mode to sidecar mode using the M19 per-intent flags
 * and asserts the plan-level invariants the migration document pins:
 *
 *   1. Each intent in the documented cutover order can be flipped to
 *      sidecar mode independently via its own
 *      ``OPENEMR_COPILOT_INTENT_MODE_<INTENT_ID>=sidecar`` env var, and
 *      :meth:`CopilotSidecarRouting::modeFor` reports
 *      :case:`IntentMode::Sidecar` for that intent.
 *   2. Flipping all six intents to sidecar mode at once leaves every
 *      cataloged intent in :case:`IntentMode::Sidecar`.
 *   3. ``OPENEMR_COPILOT_EMERGENCY_DISABLE`` forces every intent back to
 *      :case:`IntentMode::Php` regardless of per-intent flags or the
 *      default mode.
 *   4. The cutover order this suite exercises is the canonical list the
 *      :class:`AgentIntentCatalog` knows about -- if a future change
 *      adds or removes a closed intent, this test fails so the
 *      migration plan stays in sync with the catalog.
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
final class IntentCutoverPlanTest extends TestCase
{
    /**
     * Cutover order pinned in
     * ``Clinical Co-Pilot Migration to Python Sidecar.md`` step M20.
     *
     * @var list<string>
     */
    private const CUTOVER_ORDER = [
        AgentIntentCatalog::BASIC_PATIENT_DATA,
        AgentIntentCatalog::CURRENT_MEDICATIONS,
        AgentIntentCatalog::ALLERGIES_TO_CONFIRM,
        AgentIntentCatalog::RECENT_EVENTS,
        AgentIntentCatalog::CHANGED_SINCE_LAST_VISIT,
        AgentIntentCatalog::SHOW_SOURCE,
    ];

    public function testDocumentedCutoverOrderMatchesCatalog(): void
    {
        $catalogIds = (new AgentIntentCatalog())->intentIds();

        sort($catalogIds);
        $cutover = self::CUTOVER_ORDER;
        sort($cutover);

        $this->assertSame(
            $cutover,
            $catalogIds,
            'M20 cutover order must list every cataloged intent exactly once. '
            . 'If a new closed intent was added, update CUTOVER_ORDER and the '
            . 'migration plan in lockstep.',
        );
    }

    /**
     * Each intent can be flipped to sidecar mode in isolation while every
     * other intent remains in PHP mode. This is the per-intent cutover
     * pattern the migration plan documents.
     */
    public function testEachIntentCanBeFlippedToSidecarIndependently(): void
    {
        foreach (self::CUTOVER_ORDER as $intentId) {
            $env = [
                self::flagFor($intentId) => 'sidecar',
            ];
            $routing = CopilotSidecarRouting::fromEnv($env);

            $this->assertSame(
                IntentMode::Sidecar,
                $routing->modeFor($intentId),
                sprintf('Intent %s must route to sidecar when its per-intent flag is set', $intentId),
            );

            // Every other intent must remain in the default PHP mode --
            // proves the per-intent flag does not bleed into other
            // intents during a partial cutover.
            foreach (self::CUTOVER_ORDER as $otherIntent) {
                if ($otherIntent === $intentId) {
                    continue;
                }
                $this->assertSame(
                    IntentMode::Php,
                    $routing->modeFor($otherIntent),
                    sprintf(
                        'Intent %s must stay in PHP mode while only %s is flipped',
                        $otherIntent,
                        $intentId,
                    ),
                );
            }
        }
    }

    /**
     * After the documented cutover completes -- every per-intent flag
     * set to ``sidecar`` -- :meth:`CopilotSidecarRouting::modeFor` must
     * report :case:`IntentMode::Sidecar` for every cataloged intent.
     * This is the M20 pass criterion: "Every read-only intent is
     * served by Python sidecar."
     */
    public function testFullyCutOverConfigRoutesEveryIntentToSidecar(): void
    {
        $env = [];
        foreach (self::CUTOVER_ORDER as $intentId) {
            $env[self::flagFor($intentId)] = 'sidecar';
        }

        $routing = CopilotSidecarRouting::fromEnv($env);

        foreach ((new AgentIntentCatalog())->intentIds() as $intentId) {
            $this->assertSame(
                IntentMode::Sidecar,
                $routing->modeFor($intentId),
                sprintf(
                    'Intent %s must serve from the sidecar after the M20 cutover '
                    . 'completes (all per-intent flags set to sidecar)',
                    $intentId,
                ),
            );
        }
    }

    /**
     * Emergency disable is the operator-facing rollback switch. It must
     * win over both the default mode AND every per-intent override --
     * even after a full cutover -- so a single env var rolls every
     * intent back to PHP without touching individual flags.
     */
    public function testEmergencyDisableOverridesEveryPerIntentSidecarFlag(): void
    {
        $env = [
            'OPENEMR_COPILOT_EMERGENCY_DISABLE' => '1',
            'OPENEMR_COPILOT_DEFAULT_MODE' => 'sidecar',
        ];
        foreach (self::CUTOVER_ORDER as $intentId) {
            $env[self::flagFor($intentId)] = 'sidecar';
        }

        $routing = CopilotSidecarRouting::fromEnv($env);

        $this->assertTrue(
            $routing->emergencyDisable,
            'Emergency disable must be reported on the routing object',
        );
        foreach (self::CUTOVER_ORDER as $intentId) {
            $this->assertSame(
                IntentMode::Php,
                $routing->modeFor($intentId),
                sprintf(
                    'Emergency disable must force %s back to PHP regardless of '
                    . 'per-intent or default-mode flags',
                    $intentId,
                ),
            );
        }
    }

    /**
     * Build the per-intent env-var key for a cataloged intent_id (e.g.
     * ``basic_patient_data`` -> ``OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA``).
     */
    private static function flagFor(string $intentId): string
    {
        return 'OPENEMR_COPILOT_INTENT_MODE_' . strtoupper($intentId);
    }
}
