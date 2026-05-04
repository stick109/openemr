<?php

/**
 * Upload Intake Form - report.php
 *
 * Renders the encounter-timeline entry for an uploaded intake form.
 * Shows the form type, when it was uploaded, and a link to retrieve the
 * underlying PDF from the documents module (when one was stored).
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

use OpenEMR\Core\OEGlobalsBag;

/**
 * Render the encounter-timeline summary for a single upload_intake_form row.
 *
 * @param int|string $pid       The patient id (passed by the timeline renderer).
 * @param int|string $encounter The encounter id (passed by the timeline renderer).
 * @param int|string $cols      Reserved for the timeline renderer's column hint.
 * @param int|string $id        The form_upload_intake_form row id.
 */
function upload_intake_form_report($pid, $encounter, $cols, $id): void
{
    $rowId = is_numeric($id) ? (int) $id : 0;
    if ($rowId <= 0) {
        return;
    }

    $row = formFetch('form_upload_intake_form', (string) $rowId);
    if (!is_array($row)) {
        return;
    }

    $type = isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
    $documentId = isset($row['document_id']) && is_numeric($row['document_id'])
        ? (int) $row['document_id']
        : 0;
    $patientId = isset($row['pid']) && is_numeric($row['pid']) ? (int) $row['pid'] : 0;
    $createdAt = isset($row['created_at']) && is_string($row['created_at'])
        ? $row['created_at']
        : '';

    echo '<table class="table table-sm border-0 mb-0"><tbody><tr>';
    echo '<td class="border-0"><span class="bold">'
        . xlt('Form type')
        . ':</span> <span class="text">'
        . text($type !== '' ? $type : '—')
        . '</span></td>';
    echo '<td class="border-0"><span class="bold">'
        . xlt('Uploaded')
        . ':</span> <span class="text">'
        . text($createdAt !== '' ? $createdAt : '—')
        . '</span></td>';

    if ($documentId > 0 && $patientId > 0) {
        $webRoot = OEGlobalsBag::getInstance()->getKernel()->getWebRoot();
        $href = $webRoot
            . '/controller.php?document&retrieve&patient_id=' . attr_url((string) $patientId)
            . '&document_id=' . attr_url((string) $documentId)
            . '&as_file=true';
        echo '<td class="border-0"><a href="' . attr($href) . '" target="_blank" rel="noopener">'
            . xlt('View uploaded PDF')
            . '</a></td>';
    } else {
        echo '<td class="border-0"><span class="text-muted">'
            . xlt('No document attached')
            . '</span></td>';
    }

    echo '</tr></tbody></table>';
}
