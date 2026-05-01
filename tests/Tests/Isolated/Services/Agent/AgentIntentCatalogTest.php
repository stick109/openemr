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
            'changed_since_last_visit',
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
        $this->assertSame('Changed since last visit', $labels['changed_since_last_visit']);
        $this->assertSame('Show source', $labels['show_source']);
    }

    public function testCatalogDefinesPerIntentEvidenceCaps(): void
    {
        $catalog = new AgentIntentCatalog();

        foreach ($catalog->all() as $intent) {
            $this->assertArrayHasKey('max_records', $intent);
            $this->assertArrayHasKey('max_documents', $intent);
            $this->assertArrayHasKey('lookback_days', $intent);
            $this->assertGreaterThan(0, $intent['max_records']);
            $this->assertGreaterThanOrEqual(0, $intent['max_documents']);
            $this->assertGreaterThanOrEqual(0, $intent['lookback_days']);
        }

        $this->assertSame(10, $catalog->get(AgentIntentCatalog::BASIC_PATIENT_DATA)['max_records'] ?? null);
        $this->assertSame(25, $catalog->get(AgentIntentCatalog::CURRENT_MEDICATIONS)['max_records'] ?? null);
        $this->assertSame(30, $catalog->get(AgentIntentCatalog::RECENT_EVENTS)['max_records'] ?? null);
        $this->assertSame(1, $catalog->get(AgentIntentCatalog::SHOW_SOURCE)['max_records'] ?? null);
    }
}
