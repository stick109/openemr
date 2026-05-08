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

    $type = isset($row['form_type']) && is_string($row['form_type']) ? $row['form_type'] : '';
    $documentId = isset($row['document_id']) && is_numeric($row['document_id'])
        ? (int) $row['document_id']
        : 0;
    $patientId = isset($row['pid']) && is_numeric($row['pid']) ? (int) $row['pid'] : 0;
    $createdAt = isset($row['date']) && is_string($row['date'])
        ? $row['date']
        : '';

    // lab_pdf uploads have document_id=0 but stream from pdf.php via the
    // sidecar trace_id stashed in diff_preview — mirror view.php's fallback.
    $diffPreviewRaw = isset($row['diff_preview']) && is_string($row['diff_preview'])
        ? $row['diff_preview']
        : '';
    $diffPreviewDecoded = [];
    if ($diffPreviewRaw !== '') {
        $maybe = json_decode($diffPreviewRaw, true);
        if (is_array($maybe)) {
            $diffPreviewDecoded = $maybe;
        }
    }
    $traceId = isset($diffPreviewDecoded['sidecar_trace_id']) && is_string($diffPreviewDecoded['sidecar_trace_id'])
        ? $diffPreviewDecoded['sidecar_trace_id']
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
    } elseif ($traceId !== '') {
        $webRoot = OEGlobalsBag::getInstance()->getKernel()->getWebRoot();
        $href = $webRoot
            . '/interface/forms/upload_intake_form/pdf.php?id=' . attr_url((string) $rowId);
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
