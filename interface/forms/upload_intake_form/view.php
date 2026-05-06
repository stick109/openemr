<?php

/**
 * Upload Intake Form - view.php
 *
 * Read-only view of a previously uploaded intake form. The upload itself is
 * not editable: re-running ingestion would mean re-uploading the PDF, which
 * is what new.php is for. This page renders a two-column layout:
 *
 *   - Left column: the original PDF rendered with pdf.js, with bbox
 *     overlays drawn from form_upload_intake_form_citation rows.
 *   - Right column: extracted fields (lab results for lab_pdf uploads,
 *     summary metadata for the legacy intake-form types) and a guideline
 *     citation panel.
 *
 * Hovering an extracted field highlights its bounding box on the PDF;
 * clicking the field scrolls the PDF to the page containing the citation
 * and flashes the overlay. Guideline citations open a side panel with the
 * source URL and snippet text.
 *
 * The lab data itself lives in procedure_order/report/result (see
 * {@see \OpenEMR\Services\Agent\Sidecar\Dispatcher\LabPdfDispatcher}); this
 * view fetches the persisted rows by joining on the row IDs the dispatcher
 * stashed in the form's diff_preview JSON when save.php ran.
 *
 * Manual verification:
 *   1. Upload a lab fixture PDF via interface/forms/upload_intake_form/new.php.
 *   2. Open the encounter timeline and click the "Upload Document (Co-Pilot)"
 *      entry. The page that opens is this file.
 *   3. Hover an extracted lab value (e.g. "hemoglobin") and confirm the
 *      bounding box flashes on the corresponding PDF page.
 *   4. Click a guideline citation chip and confirm the side panel opens
 *      with the snippet, section label, and source_url anchor.
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

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\Agent\Sidecar\Citation\CitationCollection;
use OpenEMR\Services\Agent\Sidecar\CitationReader;

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

$diffPreviewRaw = is_array($row) && isset($row['diff_preview']) && is_string($row['diff_preview'])
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
$procedureReportId = isset($diffPreviewDecoded['procedure_report_id']) && is_numeric($diffPreviewDecoded['procedure_report_id'])
    ? (int) $diffPreviewDecoded['procedure_report_id']
    : 0;

$webRoot = OEGlobalsBag::getInstance()->getKernel()->getWebRoot();

// The legacy "Source PDF" link still points to the documents controller for
// non-lab uploads; lab_pdf uploads (document_id = 0) use the new pdf.php
// endpoint that streams from the shared sidecar volume.
$documentHref = '';
if ($documentId > 0 && $patientId > 0) {
    $documentHref = $webRoot
        . '/controller.php?document&retrieve&patient_id=' . attr_url((string) $patientId)
        . '&document_id=' . attr_url((string) $documentId)
        . '&as_file=true';
} elseif ($traceId !== '') {
    $documentHref = $webRoot
        . '/interface/forms/upload_intake_form/pdf.php?id=' . attr_url((string) $formRowId);
}

$hasInlinePdf = $traceId !== '' && $documentId === 0;
$pdfStreamHref = $hasInlinePdf
    ? $webRoot . '/interface/forms/upload_intake_form/pdf.php?id=' . attr_url((string) $formRowId)
    : '';

// ----------------------------------------------------------------------
// Citation rows for the bbox overlay and the guideline side panel.
// ----------------------------------------------------------------------
$citations = CitationCollection::empty();
if ($formRowId > 0) {
    try {
        $reader = new CitationReader(ServiceContainer::getLogger());
        $citations = $reader->readByFormId($formRowId);
    } catch (\Throwable $citationError) {
        ServiceContainer::getLogger()->error('CitationReader::readByFormId failed.', [
            'form_id' => $formRowId,
            'exception' => $citationError,
        ]);
    }
}

// ----------------------------------------------------------------------
// Lab results: pull the persisted procedure_result rows and pair each
// row's test_name with the matching pdf_bbox citation by case-insensitive
// field_name. The order matters — the citation IDs become the
// data-citation-id values the JS overlay binds to.
// ----------------------------------------------------------------------
/** @var list<array{test_name: string, value: string, unit: string, range: string, citation_id: ?int}> $labRows */
$labRows = [];
if ($procedureReportId > 0) {
    $rawResults = [];
    try {
        $rawResults = QueryUtils::fetchRecords(
            'SELECT `result_text` AS `test_name`, `result` AS `value`,
                    `units` AS `unit`, `range` AS `range`, `abnormal`
             FROM `procedure_result`
             WHERE `procedure_report_id` = ?
             ORDER BY `procedure_result_id` ASC',
            [$procedureReportId],
        );
    } catch (\Throwable $labError) {
        ServiceContainer::getLogger()->error('Lab result fetch failed for view.php.', [
            'procedure_report_id' => $procedureReportId,
            'exception' => $labError,
        ]);
    }

    // Build a quick lookup: field_name (lower-case) -> queue of citation ids.
    // A queue (rather than a single id) lets repeated field names — common in
    // multi-panel labs — bind to distinct PDF locations in order.
    /** @var array<string, list<int>> $bboxByField */
    $bboxByField = [];
    foreach ($citations->pdfBboxCitations as $bbox) {
        $key = strtolower(trim($bbox->fieldName ?? ''));
        if ($key === '') {
            continue;
        }
        $bboxByField[$key][] = $bbox->id;
    }

    foreach ($rawResults as $rawResult) {
        if (!is_array($rawResult)) {
            continue;
        }
        $testName = isset($rawResult['test_name']) && is_string($rawResult['test_name'])
            ? $rawResult['test_name']
            : '';
        $value = isset($rawResult['value']) && is_string($rawResult['value']) ? $rawResult['value'] : '';
        $unit = isset($rawResult['unit']) && is_string($rawResult['unit']) ? $rawResult['unit'] : '';
        $rangeText = isset($rawResult['range']) && is_string($rawResult['range']) ? $rawResult['range'] : '';
        $key = strtolower(trim($testName));

        $citationId = null;
        if ($key !== '' && !empty($bboxByField[$key])) {
            $citationId = (int) array_shift($bboxByField[$key]);
            if (empty($bboxByField[$key])) {
                unset($bboxByField[$key]);
            }
        }

        $labRows[] = [
            'test_name' => $testName,
            'value' => $value,
            'unit' => $unit,
            'range' => $rangeText,
            'citation_id' => $citationId,
        ];
    }
}

// ----------------------------------------------------------------------
// Build the JS-facing citation index. Bboxes are stored in PDF points
// with origin at the bottom-left (PDF default coordinate space — see
// agent-service/agent_service/schemas/citation.py). The pdf.js viewport
// uses top-left origin, so the JS layer flips Y when drawing.
// ----------------------------------------------------------------------
$pdfBboxCitationsForJs = [];
foreach ($citations->pdfBboxCitations as $bbox) {
    $pdfBboxCitationsForJs[] = [
        'id' => $bbox->id,
        'field_name' => $bbox->fieldName,
        'page' => $bbox->page,
        'bbox' => [$bbox->bboxX0, $bbox->bboxY0, $bbox->bboxX1, $bbox->bboxY1],
    ];
}
$guidelineCitationsForJs = [];
foreach ($citations->guidelineCitations as $guideline) {
    $guidelineCitationsForJs[] = [
        'id' => $guideline->id,
        'chunk_id' => $guideline->chunkId,
        'source_url' => $guideline->sourceUrl,
        'snippet' => $guideline->snippet,
        'section' => $guideline->section,
    ];
}

$citationPayloadJson = json_encode([
    'pdf_bbox' => $pdfBboxCitationsForJs,
    'guideline' => $guidelineCitationsForJs,
    'pdf_url' => $pdfStreamHref,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
?>
<html>
<head>
    <title><?php echo xlt('Upload Document (Co-Pilot)'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .upload-intake-pdf-pane {
            position: relative;
            background: #f6f7f9;
            border: 1px solid #d3d3d3;
            border-radius: 4px;
            padding: 0.5rem;
            min-height: 60vh;
            max-height: 80vh;
            overflow: auto;
        }
        .upload-intake-pdf-page {
            position: relative;
            margin: 0 auto 0.75rem;
            display: block;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            background: white;
        }
        .upload-intake-pdf-page canvas {
            display: block;
        }
        .upload-intake-bbox-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .upload-intake-bbox-rect {
            position: absolute;
            border: 2px solid rgba(213, 94, 0, 0.85);
            background: rgba(213, 94, 0, 0.18);
            border-radius: 2px;
            transition: opacity 120ms ease-in-out, transform 120ms ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
        .upload-intake-bbox-rect.is-active {
            opacity: 1;
        }
        .upload-intake-bbox-rect.is-flashing {
            animation: upload-intake-bbox-flash 1.2s ease-in-out;
        }
        @keyframes upload-intake-bbox-flash {
            0%   { box-shadow: 0 0 0 0 rgba(213, 94, 0, 0.6); transform: scale(1); }
            40%  { box-shadow: 0 0 0 8px rgba(213, 94, 0, 0.0); transform: scale(1.04); }
            100% { box-shadow: 0 0 0 0 rgba(213, 94, 0, 0.0); transform: scale(1); }
        }
        .upload-intake-field {
            cursor: pointer;
            border-left: 3px solid transparent;
            padding: 0.35rem 0.5rem;
        }
        .upload-intake-field:hover,
        .upload-intake-field:focus,
        .upload-intake-field.is-active {
            border-left-color: rgba(213, 94, 0, 0.85);
            background: rgba(213, 94, 0, 0.08);
        }
        .upload-intake-field[data-citation-id="0"],
        .upload-intake-field:not([data-citation-id]) {
            cursor: default;
        }
        .upload-intake-guideline-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin: 0.25rem 0.25rem 0.25rem 0;
            padding: 0.25rem 0.5rem;
            border: 1px solid #c5c8cd;
            border-radius: 999px;
            background: #ffffff;
            cursor: pointer;
            font-size: 0.85em;
        }
        .upload-intake-guideline-chip:hover,
        .upload-intake-guideline-chip:focus {
            background: #e9f1ff;
            border-color: #1f6feb;
        }
        .upload-intake-guideline-panel {
            display: none;
            margin-top: 0.75rem;
            padding: 0.75rem 1rem;
            border-left: 3px solid #1f6feb;
            background: #f3f7ff;
            border-radius: 4px;
        }
        .upload-intake-guideline-panel.is-open {
            display: block;
        }
        .upload-intake-guideline-panel pre {
            white-space: pre-wrap;
            margin-bottom: 0.5rem;
            font-family: inherit;
            font-size: 0.95em;
        }
    </style>
</head>
<body>
<div class="container-fluid mt-3">
    <div class="row">
        <div class="col-12">
            <h2><?php echo xlt('Upload Document (Co-Pilot)'); ?></h2>
        </div>
    </div>

    <?php if (!is_array($row)) { ?>
        <div class="alert alert-warning">
            <?php echo xlt('No matching upload found.'); ?>
        </div>
    <?php } else { ?>
        <dl class="row">
            <dt class="col-sm-2"><?php echo xlt('Form type'); ?></dt>
            <dd class="col-sm-10"><?php echo text($type !== '' ? $type : '—'); ?></dd>

            <dt class="col-sm-2"><?php echo xlt('Uploaded'); ?></dt>
            <dd class="col-sm-10"><?php echo text($createdAt !== '' ? $createdAt : '—'); ?></dd>

            <dt class="col-sm-2"><?php echo xlt('Source PDF'); ?></dt>
            <dd class="col-sm-10">
                <?php if ($documentHref !== '') { ?>
                    <a href="<?php echo attr($documentHref); ?>" target="_blank" rel="noopener">
                        <?php echo xlt('Download'); ?>
                    </a>
                <?php } else { ?>
                    <span class="text-muted"><?php echo xlt('No document attached'); ?></span>
                <?php } ?>
            </dd>
        </dl>

        <div class="row">
            <div class="col-md-7">
                <h4><?php echo xlt('Source document'); ?></h4>
                <?php if ($hasInlinePdf) { ?>
                    <div
                        id="upload-intake-pdf-pane"
                        class="upload-intake-pdf-pane"
                        data-pdf-url="<?php echo attr($pdfStreamHref); ?>"
                    >
                        <p class="text-muted text-center mt-3">
                            <?php echo xlt('Loading PDF…'); ?>
                        </p>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-secondary">
                        <?php echo xlt('Inline PDF preview is only available for sidecar uploads (lab_pdf).'); ?>
                    </div>
                <?php } ?>
            </div>

            <div class="col-md-5">
                <h4><?php echo xlt('Extracted fields'); ?></h4>
                <?php if ($labRows === []) { ?>
                    <div class="text-muted">
                        <?php echo xlt('No structured fields are available for this upload.'); ?>
                    </div>
                <?php } else { ?>
                    <ul class="list-group" id="upload-intake-field-list">
                        <?php foreach ($labRows as $labRow) { ?>
                            <li
                                class="list-group-item upload-intake-field"
                                <?php if ($labRow['citation_id'] !== null) { ?>
                                    data-citation-id="<?php echo attr((string) $labRow['citation_id']); ?>"
                                    tabindex="0"
                                <?php } ?>
                            >
                                <div class="d-flex justify-content-between">
                                    <strong><?php echo text($labRow['test_name']); ?></strong>
                                    <span>
                                        <?php echo text($labRow['value']); ?>
                                        <?php if ($labRow['unit'] !== '') { ?>
                                            <small class="text-muted"><?php echo text($labRow['unit']); ?></small>
                                        <?php } ?>
                                    </span>
                                </div>
                                <?php if ($labRow['range'] !== '') { ?>
                                    <small class="text-muted">
                                        <?php echo xlt('Reference range'); ?>: <?php echo text($labRow['range']); ?>
                                    </small>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>

                <?php if ($citations->guidelineCitations !== []) { ?>
                    <h5 class="mt-3"><?php echo xlt('Guideline citations'); ?></h5>
                    <div id="upload-intake-guideline-list">
                        <?php foreach ($citations->guidelineCitations as $guideline) { ?>
                            <button
                                type="button"
                                class="upload-intake-guideline-chip"
                                data-citation-id="<?php echo attr((string) $guideline->id); ?>"
                            >
                                <span aria-hidden="true">&#x1F4D6;</span>
                                <?php echo text($guideline->section ?? $guideline->chunkId); ?>
                            </button>
                        <?php } ?>
                    </div>
                    <div
                        id="upload-intake-guideline-panel"
                        class="upload-intake-guideline-panel"
                        role="dialog"
                        aria-live="polite"
                    >
                        <pre id="upload-intake-guideline-snippet"></pre>
                        <div class="text-muted small mb-1" id="upload-intake-guideline-section"></div>
                        <a
                            href="#"
                            id="upload-intake-guideline-source"
                            target="_blank"
                            rel="noopener"
                        ><?php echo xlt('Open source'); ?></a>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <div class="form-group mt-3">
        <button
            type="button"
            class="btn btn-secondary"
            onclick="top.restoreSession(); parent.closeTab(window.name, false);"
        >
            <?php echo xlt('Close'); ?>
        </button>
    </div>
</div>

<script id="upload-intake-citation-data" type="application/json"><?php echo $citationPayloadJson; ?></script>
<?php if ($hasInlinePdf) { ?>
    <!--
        TODO: vendor pdf.js locally (npm `pdfjs-dist`) once Week-2 lands the
        offline-only constraint. The CDN reference below is an interim choice
        so S18's overlay UI is testable without a full asset rebuild.
    -->
    <script
        src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
    ></script>
    <script src="<?php echo attr($webRoot); ?>/interface/forms/upload_intake_form/citation_overlay.js?v=1"></script>
<?php } ?>
</body>
</html>
