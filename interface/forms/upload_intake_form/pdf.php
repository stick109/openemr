<?php

/**
 * Upload Intake Form - pdf.php
 *
 * Streams the original uploaded PDF for a single form_upload_intake_form
 * row. Used by view.php's pdf.js-powered click-to-source overlay (S18).
 *
 * Why a dedicated endpoint instead of the standard documents controller:
 * lab_pdf uploads (the case S18 most cares about) do NOT register a
 * document_id in form_upload_intake_form — see
 * interface/forms/upload_intake_form/save.php for the lab_pdf branch
 * which sets `document_id` to 0 and instead records a `sidecar_trace_id`
 * in the diff_preview JSON. The PDF on disk lives in the shared upload
 * volume managed by {@see \OpenEMR\Services\Agent\Sidecar\SharedUploadManager},
 * not in OpenEMR's documents tree, so the standard
 * `controller.php?document&retrieve` flow does not apply.
 *
 * For non-lab_pdf rows (Demographics / MedicalHistory / Consent — though
 * those do not currently store PDFs), this endpoint falls back to
 * returning 404. View.php is expected to render a "no source PDF" message
 * for those rows rather than embed the iframe.
 *
 * Access control: requires the same admin/super ACL as new.php, save.php,
 * and view.php in this directory. The encounter context is not enforced
 * here because intake-form rows are reachable from any encounter on any
 * patient an admin can already view.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../globals.php';
require_once \OpenEMR\Core\OEGlobalsBag::getInstance()->getSrcDir() . '/api.inc.php';

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Services\Agent\Sidecar\SharedUploadManager;

/**
 * Render a plain-text status response and terminate. Centralised so the
 * happy path stays linear and we never `exit` from inside a catch block
 * (see custom phpstan rule openemr.exitInCatchOrFinally).
 */
$emit = static function (int $statusCode, string $message): never {
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
};

if (!AclMain::aclCheckCore('admin', 'super')) {
    $emit(403, 'Forbidden');
}

$rawId = filter_input(INPUT_GET, 'id', FILTER_DEFAULT);
$formRowId = is_numeric($rawId) ? (int) $rawId : 0;
if ($formRowId <= 0) {
    $emit(400, 'Invalid form id');
}

$row = formFetch('form_upload_intake_form', (string) $formRowId);
if (!is_array($row)) {
    $emit(404, 'Form not found');
}

// The trace id is stored on the lab_pdf branch as a JSON-encoded blob in
// the diff_preview LONGTEXT column. See save.php (line ~250) for the
// canonical writer.
$traceId = '';
$diffPreview = isset($row['diff_preview']) && is_string($row['diff_preview'])
    ? $row['diff_preview']
    : '';
if ($diffPreview !== '') {
    $decoded = json_decode($diffPreview, true);
    if (is_array($decoded) && isset($decoded['sidecar_trace_id']) && is_string($decoded['sidecar_trace_id'])) {
        $traceId = $decoded['sidecar_trace_id'];
    }
}

if ($traceId === '') {
    $emit(404, 'No source PDF available for this form');
}

$uploadManager = new SharedUploadManager(ServiceContainer::getLogger());

// SharedUploadManager throws InvalidArgumentException for empty/illegal
// trace ids. We pre-checked $traceId !== '' above, but the manager also
// strips unsafe characters and may end up with an empty string for a
// malformed trace id like "////". Capture-and-emit is the right shape
// here: the manager has already logged the rejection for operators, and
// we just need a clean 400 for the client.
$pdfPath = null;
try {
    $pdfPath = $uploadManager->buildSharedPath($traceId, 'pdf');
} catch (\InvalidArgumentException $exception) {
    // Set $pdfPath to null so the post-try block can emit cleanly without
    // calling exit/die from inside the catch (custom phpstan rule).
    unset($exception);
    $pdfPath = null;
}
if ($pdfPath === null) {
    $emit(400, 'Invalid trace id');
}

if (!is_file($pdfPath) || !is_readable($pdfPath)) {
    $emit(404, 'Source PDF not available');
}

$size = filesize($pdfPath);
if ($size === false) {
    $emit(500, 'Failed to stat PDF');
}

// Streaming headers — keep `inline` so the embedded pdf.js viewer can
// fetch the file without forcing a download. The filename below is only
// for the case where a user follows the direct URL outside of view.php.
header('Content-Type: application/pdf');
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="upload-intake-' . $formRowId . '.pdf"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($pdfPath);
exit;
