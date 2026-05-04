<?php

/**
 * Upload Intake Form - save.php
 *
 * Handles the multipart upload from new.php. Validates CSRF, ACL and the
 * uploaded PDF, then hands the file off to IntakeFormIngestService which
 * does the OpenAI-backed extraction and writes the appropriate target rows
 * (patient_data, questionnaire_response, documents). The service is also
 * responsible for inserting the row into form_upload_intake_form and
 * returning that row's id so this script can register the form against
 * the encounter via FormService::addForm().
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
use OpenEMR\Services\FormService;
use OpenEMR\Services\IntakeFormIngestService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo xlt('Not authorized.');
    exit;
}

const UPLOAD_INTAKE_FORM_DIRECTORY = 'upload_intake_form';
const UPLOAD_INTAKE_FORM_DISPLAY_NAME = 'Upload Intake Form';
const UPLOAD_INTAKE_FORM_MAX_BYTES = 10 * 1024 * 1024;
const UPLOAD_INTAKE_FORM_VALID_TYPES = [
    'Auto-detect',
    'Demographics',
    'Medical History',
    'Consent',
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
$detectedMime = $uploadedFile->getMimeType();
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

if (!class_exists(IntakeFormIngestService::class)) {
    $logger->error('IntakeFormIngestService is not available; intake-form upload aborted.', [
        'pid' => $pidInt,
        'encounter' => $encounterInt,
        'form_type' => $formType,
    ]);
    $renderFailure(xl('Service not yet wired: IntakeFormIngestService is not available.'));
}

// Contract: IntakeFormIngestService throws \RuntimeException (or a subclass)
// for any recoverable failure - validation, OpenAI errors, downstream DB
// problems. Anything outside that hierarchy (\Error, \TypeError, etc.) is
// genuinely unexpected and is allowed to propagate to the global handler.
try {
    $service = new IntakeFormIngestService();
    $result = $service->ingest($pidInt, $encounterInt, $tmpPath, $formType);
} catch (\RuntimeException $e) {
    $logger->error('IntakeFormIngestService::ingest failed.', [
        'pid' => $pidInt,
        'encounter' => $encounterInt,
        'form_type' => $formType,
        'exception' => $e,
    ]);
    $renderFailure(xl('The intake form could not be processed. Please retry or contact support.'));
}

if (!is_array($result) || !isset($result['form_id']) || !is_int($result['form_id']) || $result['form_id'] <= 0) {
    $logger->error('IntakeFormIngestService::ingest returned an invalid result.', [
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
    $result['form_id'],
    UPLOAD_INTAKE_FORM_DIRECTORY,
    $pidInt,
    is_numeric($userauthorized ?? null) ? (int) $userauthorized : 0
);

formHeader('Redirecting....');
formJump();
formFooter();
