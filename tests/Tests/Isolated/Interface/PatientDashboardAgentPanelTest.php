<?php

/**
 * PatientDashboardAgentPanelTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\PatientDashboard;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class PatientDashboardAgentPanelTest extends TestCase
{
    private string $dashboardContent = '';

    private string $templateContent = '';

    protected function setUp(): void
    {
        $dashboardPath = realpath(__DIR__ . '/../../../../interface/patient_file/summary/demographics.php');
        $templatePath = realpath(__DIR__ . '/../../../../templates/patient/card/agent_panel.html.twig');
        if (!is_string($dashboardPath) || !is_string($templatePath)) {
            $this->markTestSkipped('Agent panel files not found');
        }

        $dashboardContent = file_get_contents($dashboardPath);
        $templateContent = file_get_contents($templatePath);
        if ($dashboardContent === false || $templateContent === false) {
            $this->markTestSkipped('Failed to read agent panel files');
        }

        $this->dashboardContent = $dashboardContent;
        $this->templateContent = $templateContent;
    }

    public function testPatientDashboardRendersAuthenticatedAgentPanelCard(): void
    {
        $this->assertStringContainsString('use OpenEMR\Services\Agent\AgentIntentCatalog;', $this->dashboardContent);
        $this->assertStringContainsString('new AgentIntentCatalog()', $this->dashboardContent);
        $this->assertStringContainsString('patient/card/agent_panel.html.twig', $this->dashboardContent);
        $this->assertStringContainsString("CsrfUtils::collectCsrfToken(\$session, 'api')", $this->dashboardContent);
        $this->assertStringContainsString("/api/agent/intent", $this->dashboardContent);
    }

    public function testAgentPanelIsButtonOnlyAndCallsClosedIntentEndpoint(): void
    {
        $this->assertStringContainsString('data-intent-id="{{ intent.intent_id|attr }}"', $this->templateContent);
        $this->assertStringContainsString("'APICSRFTOKEN': apiCsrfToken", $this->templateContent);
        $this->assertStringContainsString("active_patient_context: 'server-session'", $this->templateContent);
        $this->assertStringContainsString('fetch(apiUrl', $this->templateContent);
        $this->assertStringNotContainsString('<textarea', $this->templateContent);
        $this->assertStringNotContainsString('type="text"', $this->templateContent);
        $this->assertStringNotContainsString('contenteditable', $this->templateContent);
    }
}
