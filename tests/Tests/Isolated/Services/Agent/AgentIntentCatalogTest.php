<?php

/**
 * AgentIntentCatalogTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent;

use OpenEMR\Services\Agent\AgentIntentCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class AgentIntentCatalogTest extends TestCase
{
    public function testCatalogContainsPhaseOneMvpIntents(): void
    {
        $catalog = new AgentIntentCatalog();

        $this->assertSame([
            'basic_patient_data',
            'current_medications',
            'allergies_to_confirm',
            'recent_events',
            'intake_checklist',
            'changed_since_last_visit',
            'intake_handoff',
            'show_source',
        ], $catalog->intentIds());
    }

    public function testCatalogExposesButtonLabelsFromArchitecture(): void
    {
        $catalog = new AgentIntentCatalog();

        $labels = [];
        foreach ($catalog->all() as $intent) {
            $labels[$intent['intent_id']] = $intent['button_label'];
        }

        $this->assertSame('Basic patient data', $labels['basic_patient_data']);
        $this->assertSame('Current medications', $labels['current_medications']);
        $this->assertSame('Allergies to confirm', $labels['allergies_to_confirm']);
        $this->assertSame('Recent events', $labels['recent_events']);
        $this->assertSame('Intake checklist', $labels['intake_checklist']);
        $this->assertSame('Changed since last visit', $labels['changed_since_last_visit']);
        $this->assertSame('Intake handoff', $labels['intake_handoff']);
        $this->assertSame('Show source', $labels['show_source']);
    }
}
