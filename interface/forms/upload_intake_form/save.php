<?php

/**
 * Upload Intake Form - save.php
 *
 * Handles the multipart upload from new.php. Validates CSRF, ACL and the
 * uploaded PDF, then routes the file to the appropriate extraction back-end:
 *
 *  - **lab_pdf** documents are sent to the Python agent-service sidecar via
 *    {@see AgentServiceClient} (Week 2). The sidecar handles PDF parsing,
 *    extraction, guideline retrieval, and summary generation.
 *
 *  - **Demographics / MedicalHistory / Consent** (and Auto-classified)
 *    continue to use {@see IntakeFormIngestService} with the direct OpenAI
 *    extraction path.
 *
 * Backward compatibility: when the sidecar is not configured (missing env
 * vars), lab_pdf uploads fail gracefully with a user-facing error instead
 * of silently falling through.
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
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Services\Agent\Sidecar\AgentRunResult;
use OpenEMR\Services\Agent\Sidecar\AgentServiceClient;
use OpenEMR\Services\Agent\Sidecar\AgentServiceException;
use OpenEMR\Services\Agent\Sidecar\AgentSidecarConfig;
use OpenEMR\Services\Agent\Sidecar\SharedUploadManager;
use OpenEMR\Services\FormService;
use OpenEMR\Services\Intake\Dispatcher\ConsentDispatcher;
use OpenEMR\Services\Intake\Dispatcher\DemographicsDispatcher;
use OpenEMR\Services\Intake\Dispatcher\MedicalHistoryDispatcher;
use OpenEMR\Services\Intake\Exception\IntakeFormException;
use OpenEMR\Services\Intake\IntakeFormIngestService;
use OpenEMR\Services\Intake\OpenAi\OpenAIClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized.');
    exit;
}

const UPLOAD_INTAKE_FORM_DIRECTORY = 'upload_intake_form';
const UPLOAD_INTAKE_FORM_DISPLAY_NAME = 'Upload Document (Co-Pilot)';
const UPLOAD_INTAKE_FORM_MAX_BYTES = 10 * 1024 * 1024;
// The values that survive the round trip from the dropdown into the service.
// `Auto` triggers the classifier; the other three intake types are passed
// through verbatim. `lab_pdf` is the wire value for lab-report uploads
// (see agent-service contract). Display labels live in new.php, not here.
const UPLOAD_INTAKE_FORM_VALID_TYPES = [
    'Auto',
    'Demographics',
    'MedicalHistory',
    'Consent',
    'lab_pdf',
];

$logger = ServiceContainer::getLogger();

$renderFailure = static function (string $message): never {
    formHeader(xl('Upload Intake Form'));
    echo '<div class="container mt-3"><div class="alert alert-danger">'
        . text($message)
        . '</div>';
    echo '<a href="javascript:parent.closeTab(window.name, false);" class="btn btn-secondary">'
        . xlt('Close')
        . '</a></div>';
    formFooter();
    exit;
};

$request = Request::createFromGlobals();

$rawFormType = $request->request->get('form_type', '');
$formType = is_string($rawFormType) ? $rawFormType : '';
if (!in_array($formType, UPLOAD_INTAKE_FORM_VALID_TYPES, true)) {
    $renderFailure(xl('Invalid form type selected.'));
}

$uploadedFile = $request->files->get('intake_pdf');
if (!$uploadedFile instanceof UploadedFile) {
    $renderFailure(xl('No file was uploaded.'));
}

if (!$uploadedFile->isValid()) {
    $renderFailure(xl('The file upload failed. Please try again.'));
}

$tmpPath = $uploadedFile->getPathname();
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    $renderFailure(xl('The uploaded file could not be read.'));
}

$size = $uploadedFile->getSize();
if (!is_int($size) || $size <= 0 || $size > UPLOAD_INTAKE_FORM_MAX_BYTES) {
    $renderFailure(xl('The uploaded PDF must be greater than 0 bytes and at most 10 MB.'));
}

// MIME / extension sanity check. Do not trust the browser-supplied type alone.
// `UploadedFile::getMimeType()` requires symfony/mime, which is not in the
// production vendor tree; use the libmagic-backed builtin to match the rest
// of this codebase (see interface/billing/edi_271.php).
$detectedMime = mime_content_type($tmpPath);
$extension = strtolower($uploadedFile->getClientOriginalExtension());
if ($detectedMime !== 'application/pdf' || $extension !== 'pdf') {
    $renderFailure(xl('Only PDF files are accepted.'));
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$rawPid = $session->get('pid');
$rawEncounter = $session->get('encounter');
$pidInt = is_numeric($rawPid) ? (int) $rawPid : 0;
$encounterInt = is_numeric($rawEncounter) ? (int) $rawEncounter : 0;
if ($pidInt <= 0 || $encounterInt <= 0) {
    $renderFailure(xl('No active patient or encounter context.'));
}

// -----------------------------------------------------------------------
// Routing: lab_pdf -> sidecar; intake form types -> IntakeFormIngestService
// -----------------------------------------------------------------------
$useSidecar = $formType === 'lab_pdf';

if ($useSidecar) {
    // -- Sidecar path (lab_pdf, and potentially intake_form in Week 2) ---
    $sidecarConfig = AgentSidecarConfig::fromEnvironment(OEEnvBag::getInstance());

    if (!$sidecarConfig->isConfigured()) {
        $logger->error('Sidecar not configured for lab_pdf upload.', [
            'issue' => $sidecarConfig->getConfigurationIssue(),
        ]);
        $renderFailure(xl('Lab report processing is not available. The agent service is not configured.'));
    }

    $traceId = bin2hex(random_bytes(16));
    $traceId = substr($traceId, 0, 8) . '-' . substr($traceId, 8, 4) . '-'
        . '4' . substr($traceId, 13, 3) . '-'
        . dechex(8 | (hexdec(substr($traceId, 16, 1)) & 0x3)) . substr($traceId, 17, 3) . '-'
        . substr($traceId, 20, 12);

    $uploadManager = new SharedUploadManager($logger);
    $sidecarClient = new AgentServiceClient($sidecarConfig, $logger);

    try {
        $sharedPath = $uploadManager->store(
            $tmpPath,
            $traceId,
            $uploadedFile->getClientOriginalName(),
        );

        $sidecarResult = $sidecarClient->run(
            patientId: $pidInt,
            filePath: $sharedPath,
            docType: $formType,
            encounterId: $encounterInt,
            traceId: $traceId,
        );
    } catch (AgentServiceException $e) {
        $logger->error('Agent sidecar call failed.', [
            'pid' => $pidInt,
            'encounter' => $encounterInt,
            'form_type' => $formType,
            'trace_id' => $traceId,
            'error_code' => $e->errorCode,
            'detail' => $e->detail,
            'exception' => $e,
        ]);
        // Sidecar error/refusal blocks persistence — no DB writes happen.
        $renderFailure(xl('The document could not be processed. Please retry or contact support.'));
    } catch (\RuntimeException $e) {
        $logger->error('Sidecar upload preparation failed.', [
            'pid' => $pidInt,
            'encounter' => $encounterInt,
            'trace_id' => $traceId,
            'exception' => $e,
        ]);
        $renderFailure(xl('The document could not be processed. Please retry or contact support.'));
    }

    $logger->info('Sidecar extraction completed for lab_pdf.', [
        'pid' => $pidInt,
        'encounter' => $encounterInt,
        'trace_id' => $traceId,
        'confidence' => $sidecarResult->extractionConfidence,
        'cost_usd' => $sidecarResult->costUsd,
    ]);

    // TODO(S16+): persist sidecar extraction result into OpenEMR tables.
    // For now the sidecar result is logged; downstream persistence is the
    // responsibility of subsequent implementation steps. The form row is
    // still recorded below so the encounter timeline entry exists.
    $authUserIdRaw = $session->get('authUserID');
    $authUserIdString = is_scalar($authUserIdRaw) ? (string) $authUserIdRaw : '0';
    $insertedRowId = \OpenEMR\Common\Database\QueryUtils::sqlInsert(
        'INSERT INTO `form_upload_intake_form`
            (`date`, `pid`, `encounter`, `user`, `groupname`, `authorized`, `activity`,
             `form_type`, `document_id`, `inserted_row_id`, `diff_preview`)
            VALUES (?, ?, ?, ?, ?, 1, 1, ?, 0, 0, ?)',
        [
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $pidInt,
            $encounterInt,
            $authUserIdString,
            'Default',
            $formType,
            json_encode([
                'sidecar_trace_id' => $traceId,
                'extraction_confidence' => $sidecarResult->extractionConfidence,
                'cost_usd' => $sidecarResult->costUsd,
                'tool_sequence' => $sidecarResult->toolSequence,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]
    );

    $result = new \OpenEMR\Services\Intake\IngestResult(
        formType: $formType,
        documentId: 0,
        insertedRowId: $insertedRowId,
        diffPreview: [],
    );
} else {
    // -- Legacy intake-form path (Demographics, MedicalHistory, Consent) --
    // Contract: IntakeFormIngestService throws IntakeFormException (a
    // \RuntimeException subclass) for any recoverable failure — validation,
    // OpenAI errors, downstream DB problems. Anything outside that hierarchy
    // (\Error, \TypeError, etc.) is genuinely unexpected and is allowed to
    // propagate to the global handler. The service returns an IngestResult
    // DTO; `insertedRowId` is the form_upload_intake_form row id that
    // FormService::addForm() needs to wire the encounter timeline entry.
    try {
        $service = new IntakeFormIngestService(
            openAiClient: new OpenAIClient($logger, OEEnvBag::getInstance()),
            logger: $logger,
            clock: ServiceContainer::getClock(),
            demographicsDispatcher: new DemographicsDispatcher($logger),
            medicalHistoryDispatcher: new MedicalHistoryDispatcher($logger, ServiceContainer::getClock()),
            consentDispatcher: new ConsentDispatcher(),
            session: $session,
        );
        $result = $service->ingest($pidInt, $encounterInt, $tmpPath, $formType);
    } catch (IntakeFormException $e) {
        $logger->error('IntakeFormIngestService::ingest failed.', [
            'pid' => $pidInt,
            'encounter' => $encounterInt,
            'form_type' => $formType,
            'exception' => $e,
        ]);
        $renderFailure(xl('The intake form could not be processed. Please retry or contact support.'));
    }
}

if ($result->insertedRowId === null || $result->insertedRowId <= 0) {
    $logger->error('Ingestion returned an invalid result.', [
        'pid' => $pidInt,
        'encounter' => $encounterInt,
        'form_type' => $formType,
    ]);
    $renderFailure(xl('The intake form could not be processed. Please retry or contact support.'));
}

$formService = new FormService();
$formService->addForm(
    $encounterInt,
    UPLOAD_INTAKE_FORM_DISPLAY_NAME,
    $result->insertedRowId,
    UPLOAD_INTAKE_FORM_DIRECTORY,
    $pidInt,
    is_numeric($userauthorized ?? null) ? (int) $userauthorized : 0
);

formHeader('Redirecting....');
formJump();
formFooter();
