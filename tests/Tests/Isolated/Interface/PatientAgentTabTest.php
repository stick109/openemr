<?php

/**
 * PatientAgentTabTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\PatientAgentTab;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('agent')]
class PatientAgentTabTest extends TestCase
{
    private string $dashboardContent = '';

    private string $pageContent = '';

    private string $templateContent = '';

    private string $scriptContent = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $patientMenu = [];

    protected function setUp(): void
    {
        $dashboardPath = realpath(__DIR__ . '/../../../../interface/patient_file/summary/demographics.php');
        $pagePath = realpath(__DIR__ . '/../../../../interface/patient_file/summary/agent.php');
        $templatePath = realpath(__DIR__ . '/../../../../templates/patient/agent_panel.html.twig');
        $scriptPath = realpath(__DIR__ . '/../../../../interface/patient_file/summary/agent_panel.js');
        $menuPath = realpath(__DIR__ . '/../../../../interface/main/tabs/menu/menus/patient_menus/standard.json');
        if (
            !is_string($dashboardPath)
            || !is_string($pagePath)
            || !is_string($templatePath)
            || !is_string($scriptPath)
            || !is_string($menuPath)
        ) {
            $this->markTestSkipped('Agent tab files not found');
        }

        $dashboardContent = file_get_contents($dashboardPath);
        $pageContent = file_get_contents($pagePath);
        $templateContent = file_get_contents($templatePath);
        $scriptContent = file_get_contents($scriptPath);
        $menuContent = file_get_contents($menuPath);
        if (
            $dashboardContent === false
            || $pageContent === false
            || $templateContent === false
            || $scriptContent === false
            || $menuContent === false
        ) {
            $this->markTestSkipped('Failed to read agent tab files');
        }

        $this->dashboardContent = $dashboardContent;
        $this->pageContent = $pageContent;
        $this->templateContent = $templateContent;
        $this->scriptContent = $scriptContent;
        $this->patientMenu = json_decode($menuContent, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testPatientMenuPlacesAgentTabAfterDashboard(): void
    {
        $menuIds = array_column($this->patientMenu, 'menu_id');
        $dashboardIndex = array_search('dashboard', $menuIds, true);
        $this->assertIsInt($dashboardIndex);

        $agentItem = $this->patientMenu[$dashboardIndex + 1] ?? null;
        $this->assertIsArray($agentItem);
        $this->assertSame('Clinical Co-Pilot', $agentItem['label']);
        $this->assertSame('clinical_copilot', $agentItem['menu_id']);
        $this->assertSame('interface/patient_file/summary/agent.php', $agentItem['url']);
        $this->assertSame('false', $agentItem['pid']);
    }

    public function testPatientAgentTabRendersAuthenticatedClosedIntentPanel(): void
    {
        $this->assertStringContainsString("AclMain::aclCheckCore('patients', 'demo')", $this->pageContent);
        $this->assertStringContainsString('use OpenEMR\Services\Agent\AgentIntentCatalog;', $this->pageContent);
        $this->assertStringContainsString('new AgentIntentCatalog()', $this->pageContent);
        $this->assertStringContainsString('patient/agent_panel.html.twig', $this->pageContent);
        $this->assertStringContainsString('AgentIntentCatalog::SHOW_SOURCE', $this->pageContent);
        $this->assertStringContainsString("'intents' => \$displayIntents", $this->pageContent);
        $this->assertStringContainsString("'sourceIntent' => \$sourceIntent", $this->pageContent);
        $this->assertStringContainsString("CsrfUtils::collectCsrfToken(\$session, 'api')", $this->pageContent);
        $this->assertStringContainsString("/api/agent/intent", $this->pageContent);
        $this->assertStringContainsString('$list_id = "clinical_copilot";', $this->pageContent);
        $this->assertStringContainsString('displayHorizNavBarMenu()', $this->pageContent);
    }

    public function testPatientDashboardNoLongerRendersAgentPanelCard(): void
    {
        $this->assertStringNotContainsString('card_agent_panel', $this->dashboardContent);
        $this->assertStringNotContainsString('patient/card/agent_panel.html.twig', $this->dashboardContent);
    }

    public function testAgentPanelIsButtonOnlyAndCallsClosedIntentEndpoint(): void
    {
        $this->assertStringContainsString('data-intent-id="{{ intent.intent_id|attr }}"', $this->templateContent);
        $this->assertStringContainsString('data-prompt-text="{{ intent.prompt_text|attr }}"', $this->templateContent);
        $this->assertStringContainsString('data-api-url="{{ apiUrl|attr }}"', $this->templateContent);
        $this->assertStringContainsString('data-api-csrf-token="{{ apiCsrfToken|attr }}"', $this->templateContent);
        $this->assertStringContainsString('/interface/patient_file/summary/agent_panel.js?v={{ assetVersion|attr_url }}', $this->templateContent);
        $this->assertStringContainsString('APICSRFTOKEN: apiCsrfToken', $this->scriptContent);
        $this->assertStringContainsString("active_patient_context: 'server-session'", $this->scriptContent);
        $this->assertStringContainsString('fetch(apiUrl', $this->scriptContent);
        $this->assertStringContainsString('js-agent-prompt-preview', $this->templateContent);
        $this->assertStringContainsString('readonly', $this->templateContent);
        $this->assertStringContainsString('aria-readonly="true"', $this->templateContent);
        $this->assertStringContainsString('disabled>{{ "Send"|xlt }}</button>', $this->templateContent);
        $this->assertStringContainsString('var intentPrompts = new Map', $this->scriptContent);
        $this->assertStringContainsString("intentPrompts.get(intentId) || ''", $this->scriptContent);
        $this->assertStringContainsString('data-loading-label="{{ "LOADING"|xla }}..."', $this->templateContent);
        $this->assertStringContainsString('data-source-label="{{ "source"|xla }}"', $this->templateContent);
        $this->assertStringContainsString('data-source-aria-label="{{ "Show source"|xla }}"', $this->templateContent);
        $this->assertStringContainsString("data-source-prompt-text=\"{{ sourceIntent.prompt_text|default('')|attr }}\"", $this->templateContent);
        $this->assertStringContainsString("panel.classList.toggle('is-agent-loading', loading)", $this->scriptContent);
        $this->assertStringContainsString("document.body.classList.toggle('agent-loading-cursor', loading)", $this->scriptContent);
        $this->assertStringContainsString('cursor: wait !important;', $this->templateContent);
        $this->assertStringContainsString('outputNode.hidden = loading', $this->scriptContent);
        $this->assertStringContainsString("panel.querySelectorAll('button')", $this->scriptContent);
        $this->assertStringContainsString('button.dataset.agentWasDisabled', $this->scriptContent);
        $this->assertStringContainsString("panel.setAttribute('aria-busy', loading ? 'true' : 'false')", $this->scriptContent);
        $this->assertStringContainsString('function appendCitationLinks', $this->scriptContent);
        $this->assertStringContainsString("sourceLink.textContent = citationIds.length === 1", $this->scriptContent);
        $this->assertStringContainsString("sourceLink.dataset.sourceId = citationId", $this->scriptContent);
        $this->assertStringContainsString('sourcePrompt: panel.dataset.sourcePromptText', $this->scriptContent);
        $this->assertStringContainsString("promptPreviewNode.value = intentId === 'show_source' && sourceId", $this->scriptContent);
        $this->assertStringNotContainsString('function renderAnswer', $this->templateContent);
        $this->assertStringNotContainsString('function renderValidationErrors', $this->templateContent);
        $this->assertStringNotContainsString('const apiUrl =', $this->templateContent);
        $this->assertStringNotContainsString('const intents =', $this->templateContent);
        $this->assertStringNotContainsString('<textarea', $this->templateContent);
        $this->assertStringNotContainsString('contenteditable', $this->templateContent);
        $this->assertStringNotContainsString('llm_user_text', $this->templateContent);
        $this->assertStringNotContainsString('llm_user_text', $this->scriptContent);
        $this->assertStringNotContainsString('prompt_text: promptPreviewNode.value', $this->scriptContent);
        $this->assertStringNotContainsString('prompt_text:', $this->scriptContent);
        $this->assertStringNotContainsString('name="prompt', $this->templateContent);
    }
}
