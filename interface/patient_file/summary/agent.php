<?php

/**
 * Clinical Co-Pilot patient chart tab.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\Services\Agent\AgentIntentCatalog;

if (!AclMain::aclCheckCore('patients', 'demo')) {
    AccessDeniedHelper::denyWithTemplate("ACL check failed for patients/demo: Clinical Co-Pilot", xl("Clinical Co-Pilot"));
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$twig = new TwigContainer(null, OEGlobalsBag::getInstance()->getKernel());

$arrOeUiSettings = [
    'page_id' => 'core.agent',
    'heading_title' => xl('Clinical Co-Pilot'),
    'include_patient_name' => true,
    'expandable' => false,
    'expandable_files' => [],
    'action' => '',
    'action_title' => '',
    'action_href' => '',
    'show_help_icon' => false,
    'help_file_name' => ''
];
$oemr_ui = new OemrUI($arrOeUiSettings);
$agentCatalog = new AgentIntentCatalog();
$displayIntents = array_values(array_filter(
    $agentCatalog->all(),
    static fn (array $intent): bool => ($intent['intent_id'] ?? null) !== AgentIntentCatalog::SHOW_SOURCE
));
$sourceIntent = $agentCatalog->get(AgentIntentCatalog::SHOW_SOURCE);
$agentSiteId = (string) ($session->get('site_id') ?? 'default');
$agentPanelScriptPath = __DIR__ . '/agent_panel.js';
$agentPanelAssetVersion = is_file($agentPanelScriptPath) ? (string) filemtime($agentPanelScriptPath) : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("Clinical Co-Pilot"); ?></title>
    <?php Header::setupHeader(['common', 'utility']); ?>
</head>
<body>
    <div id="container_div" class="<?php echo $oemr_ui->oeContainer(); ?> mt-3">
        <div class="row">
            <div class="col-sm-12">
                <?php require_once("$include_root/patient_file/summary/dashboard_header.php"); ?>
            </div>
        </div>
        <?php
        $list_id = "clinical_copilot";
        $menuPatient = new PatientMenuRole();
        $menuPatient->displayHorizNavBarMenu();

        echo $twig->getTwig()->render('patient/agent_panel.html.twig', [
            'intents' => $displayIntents,
            'sourceIntent' => $sourceIntent,
            'apiUrl' => OEGlobalsBag::getInstance()->getWebRoot()
                . '/apis/'
                . rawurlencode($agentSiteId)
                . '/api/agent/intent',
            'apiCsrfToken' => CsrfUtils::collectCsrfToken($session, 'api'),
            'agentPanelAssetVersion' => $agentPanelAssetVersion,
        ]);
        ?>
    </div>
    <?php $oemr_ui->oeBelowContainerDiv(); ?>
    <script>
        var listId = '#' + <?php echo js_escape($list_id); ?>;
        $(function () {
            $(listId).addClass("active");
        });
    </script>
</body>
</html>
