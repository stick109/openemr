<?php

/**
 * Upload Intake Form - new.php
 *
 * Encounter-form upload UI for ingesting completed intake-form PDFs.
 * Front-desk staff pick a PDF (Demographics, Medical History, or Consent),
 * optionally let the system auto-classify it, and submit. The PDF is then
 * dispatched to the IntakeFormIngestService for OpenAI-backed extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov <surkov@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__FILE__, 3) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo '<html><body><p>' . xlt('You are not authorized to upload intake forms.') . '</p></body></html>';
    exit;
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$rootDir = OEGlobalsBag::getInstance()->getString('rootdir');

$formName = 'upload_intake_form';
// Wire-level value => human display label. The wire-level value is what the
// service expects (see IntakeFormType + save.php); the label is what users see.
$formTypes = [
    'Auto'           => xl('Auto-detect'),
    'Demographics'   => xl('Demographics'),
    'MedicalHistory' => xl('Medical History'),
    'Consent'        => xl('Consent'),
];
?>
<html>
<head>
    <title><?php echo xlt('Upload Intake Form'); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body>
<div class="container mt-3">
    <div class="row">
        <div class="col-12">
            <h2><?php echo xlt('Upload Intake Form'); ?></h2>
            <p class="text-muted">
                <?php echo xlt('Upload a completed intake-form PDF (Demographics, Medical History, or Consent). Maximum size 10 MB.'); ?>
            </p>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <form
                name="upload_intake_form"
                id="upload_intake_form"
                method="post"
                enctype="multipart/form-data"
                action="<?php echo attr($rootDir); ?>/forms/<?php echo attr_url($formName); ?>/save.php?mode=new"
                onsubmit="return top.restoreSession()"
            >
                <input
                    type="hidden"
                    name="csrf_token_form"
                    value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>"
                />

                <fieldset>
                    <legend><?php echo xlt('Form Details'); ?></legend>

                    <div class="form-group">
                        <label for="form_type">
                            <?php echo xlt('Form type'); ?>
                        </label>
                        <select class="form-control" name="form_type" id="form_type">
                            <?php foreach ($formTypes as $value => $label) { ?>
                                <option value="<?php echo attr($value); ?>"><?php echo text($label); ?></option>
                            <?php } ?>
                        </select>
                        <small class="form-text text-muted">
                            <?php echo xlt('Choose Auto-detect to let the system classify the PDF.'); ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="intake_pdf">
                            <?php echo xlt('PDF file'); ?>
                        </label>
                        <input
                            type="file"
                            class="form-control-file"
                            name="intake_pdf"
                            id="intake_pdf"
                            accept="application/pdf,.pdf"
                            required
                        />
                        <small class="form-text text-muted">
                            <?php echo xlt('PDF only, up to 10 MB.'); ?>
                        </small>
                    </div>
                </fieldset>

                <div class="form-group">
                    <div class="btn-group" role="group">
                        <button
                            type="submit"
                            class="btn btn-primary btn-save"
                            onclick="top.restoreSession()"
                        >
                            <?php echo xlt('Upload'); ?>
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary btn-cancel"
                            onclick="top.restoreSession(); parent.closeTab(window.name, false);"
                        >
                            <?php echo xlt('Cancel'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
