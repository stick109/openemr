<?php

/**
 * Upload Intake Form - view.php
 *
 * Read-only view of a previously uploaded intake form. The upload itself is
 * not editable: re-running ingestion would mean re-uploading the PDF, which
 * is what new.php is for. This page just shows what was captured and
 * provides a link to retrieve the underlying PDF (when available).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov <surkov@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../globals.php';
require_once \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir() . '/api.inc.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo '<html><body><p>' . xlt('You are not authorized to view intake forms.') . '</p></body></html>';
    exit;
}

$rawId = filter_input(INPUT_GET, 'id', FILTER_DEFAULT);
$formRowId = is_numeric($rawId) ? (int) $rawId : 0;

$row = $formRowId > 0
    ? formFetch('form_upload_intake_form', (string) $formRowId)
    : null;

$type = is_array($row) && isset($row['form_type']) && is_string($row['form_type']) ? $row['form_type'] : '';
$documentId = is_array($row) && isset($row['document_id']) && is_numeric($row['document_id'])
    ? (int) $row['document_id']
    : 0;
$patientId = is_array($row) && isset($row['pid']) && is_numeric($row['pid']) ? (int) $row['pid'] : 0;
$createdAt = is_array($row) && isset($row['date']) && is_string($row['date'])
    ? $row['date']
    : '';

$documentHref = '';
if ($documentId > 0 && $patientId > 0) {
    $webRoot = OEGlobalsBag::getInstance()->getKernel()->getWebRoot();
    $documentHref = $webRoot
        . '/controller.php?document&retrieve&patient_id=' . attr_url((string) $patientId)
        . '&document_id=' . attr_url((string) $documentId)
        . '&as_file=true';
}
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
        </div>
    </div>

    <?php if (!is_array($row)) { ?>
        <div class="alert alert-warning">
            <?php echo xlt('No matching upload found.'); ?>
        </div>
    <?php } else { ?>
        <dl class="row">
            <dt class="col-sm-3"><?php echo xlt('Form type'); ?></dt>
            <dd class="col-sm-9"><?php echo text($type !== '' ? $type : '—'); ?></dd>

            <dt class="col-sm-3"><?php echo xlt('Uploaded'); ?></dt>
            <dd class="col-sm-9"><?php echo text($createdAt !== '' ? $createdAt : '—'); ?></dd>

            <dt class="col-sm-3"><?php echo xlt('Source PDF'); ?></dt>
            <dd class="col-sm-9">
                <?php if ($documentHref !== '') { ?>
                    <a href="<?php echo attr($documentHref); ?>" target="_blank" rel="noopener">
                        <?php echo xlt('Download'); ?>
                    </a>
                <?php } else { ?>
                    <span class="text-muted"><?php echo xlt('No document attached'); ?></span>
                <?php } ?>
            </dd>
        </dl>
    <?php } ?>

    <div class="form-group">
        <button
            type="button"
            class="btn btn-secondary"
            onclick="top.restoreSession(); parent.closeTab(window.name, false);"
        >
            <?php echo xlt('Close'); ?>
        </button>
    </div>
</div>
</body>
</html>
