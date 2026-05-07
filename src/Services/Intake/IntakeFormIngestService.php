<?php

/**
 * IntakeFormIngestService
 *
 * Server-side ingestion pipeline for the intake-form upload feature
 * (`interface/forms/upload_intake_form/`). Validates the uploaded PDF,
 * optionally runs the auto-classifier, calls OpenAI to extract a
 * type-specific structured payload, dispatches the result into the
 * appropriate OpenEMR write path (patient_data + insurance_data, FHIR
 * questionnaire response, or documents module), and finally records the
 * upload in `form_upload_intake_form` so it shows up in the encounter
 * timeline.
 *
 * CSRF and ACL checks are the form's responsibility (see §3.3 of
 * `intake-forms-plan.md`). This service trusts that the caller has already
 * authenticated and authorised the request — but it still validates the
 * uploaded file (PDF mime + size cap) and the request-time form-type
 * argument.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake;

use JsonException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\Intake\Classifier\IntakeFormClassifierPrompt;
use OpenEMR\Services\Intake\Dispatcher\ConsentDispatcher;
use OpenEMR\Services\Intake\Dispatcher\DemographicsDispatcher;
use OpenEMR\Services\Intake\Dispatcher\DiffEntry;
use OpenEMR\Services\Intake\Dispatcher\DispatchOutcome;
use OpenEMR\Services\Intake\Dispatcher\MedicalHistoryDispatcher;
use OpenEMR\Services\Intake\Exception\AmbiguousFormException;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use OpenEMR\Services\Intake\Exception\InvalidUploadException;
use OpenEMR\Services\Intake\OpenAi\Exception\OpenAIException;
use OpenEMR\Services\Intake\OpenAi\OpenAIClient;
use OpenEMR\Services\Intake\OpenAi\OpenAIStructuredRequest;
use OpenEMR\Services\Intake\Schema\IntakeFormSchemaValidator;
use OpenEMR\Services\Intake\Schema\IntakeJsonSchemas;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class IntakeFormIngestService
{
    private const MAX_PDF_BYTES = 10 * 1024 * 1024;
    private const PDF_MAGIC = '%PDF-';
    private const CLASSIFIER_THRESHOLD = 0.7;
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const CONSENT_CATEGORY_NAME = 'Consents';
    private const CONSENT_CATEGORY_PARENT_ID = 1;

    private IntakeFormSchemaValidator $schemaValidator;

    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private OpenAIClient $openAiClient,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private DemographicsDispatcher $demographicsDispatcher,
        private MedicalHistoryDispatcher $medicalHistoryDispatcher,
        private ConsentDispatcher $consentDispatcher,
        private ?SessionInterface $session = null,
        private string $model = self::DEFAULT_MODEL,
        private float $classifierThreshold = self::CLASSIFIER_THRESHOLD,
        ?IntakeFormSchemaValidator $schemaValidator = null,
    ) {
        $this->schemaValidator = $schemaValidator ?? new IntakeFormSchemaValidator();
    }

    /**
     * Ingest an uploaded intake-form PDF into OpenEMR.
     *
     * @param int $patientId The pid of the patient the encounter belongs to.
     * @param int $encounterId The current open encounter (required for
     *                         MedicalHistory; ignored by Demographics and
     *                         Consent for the encounter linkage but still
     *                         recorded against `form_upload_intake_form`).
     * @param string $uploadedPdfPath Absolute path to the uploaded PDF on
     *                                the host filesystem (typically
     *                                `$_FILES['file']['tmp_name']`).
     * @param string $formType One of: `Auto`, `Demographics`,
     *                         `MedicalHistory`, `Consent`.
     * @throws InvalidUploadException When the upload fails pre-flight checks.
     * @throws AmbiguousFormException When the auto-classifier is below the
     *                                confidence threshold.
     * @throws IngestionFailedException When extraction or the dispatch fails.
     */
    public function ingest(
        int $patientId,
        int $encounterId,
        string $uploadedPdfPath,
        string $formType,
    ): IngestResult {
        if ($patientId <= 0) {
            throw new IngestionFailedException('Patient id must be positive.');
        }

        // Wrap the pipeline so OpenAIException (which lives outside the
        // IntakeFormException hierarchy) reaches save.php as the documented
        // IngestionFailedException — keeping the user-friendly error path
        // and not bubbling a transport-level exception to the global handler.
        try {
            $pdfPath = $this->validateUpload($uploadedPdfPath);

            $requestedType = IntakeFormType::fromRequest($formType);

            $fileId = $this->openAiClient->uploadPdf(
                $pdfPath,
                $this->displayFilename($requestedType)
            );

            $resolvedType = $requestedType ?? $this->classify($fileId);

            $extracted = $this->extract($resolvedType, $fileId);
            $this->validateExtracted($resolvedType, $extracted);

            $documentId = $this->storeOriginalPdf(
                $patientId,
                $pdfPath,
                $resolvedType,
            );

            $authUserId = $this->resolveAuthUserId();

            $outcome = match ($resolvedType) {
                IntakeFormType::Demographics => $this->demographicsDispatcher->dispatch($patientId, $extracted),
                IntakeFormType::MedicalHistory => $this->medicalHistoryDispatcher->dispatch(
                    $patientId,
                    $encounterId,
                    $authUserId,
                    $extracted,
                ),
                IntakeFormType::Consent => $this->consentDispatcher->buildOutcome($documentId, $extracted),
            };

            $intakeRowId = $this->recordUpload(
                patientId: $patientId,
                encounterId: $encounterId,
                authUserId: $authUserId,
                type: $resolvedType,
                documentId: $documentId,
                outcome: $outcome,
            );
        } catch (OpenAIException $e) {
            throw new IngestionFailedException(
                'OpenAI extraction failed for intake form upload.',
                $e
            );
        }

        $this->logger->info('Intake form ingested', [
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'form_type' => $resolvedType->value,
            'document_id' => $documentId,
            'intake_row_id' => $intakeRowId,
            'dispatcher_row_id' => $outcome->insertedRowId,
        ]);

        return new IngestResult(
            formType: $resolvedType->value,
            documentId: $documentId,
            insertedRowId: $intakeRowId,
            diffPreview: array_map(static fn(DiffEntry $entry): array => $entry->toArray(), $outcome->diffPreview),
        );
    }

    /**
     * @return non-empty-string The validated path (unchanged but PHPStan-narrowed).
     */
    private function validateUpload(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            throw new InvalidUploadException('Uploaded file is missing.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new InvalidUploadException('Uploaded file is empty.');
        }
        if ($size > self::MAX_PDF_BYTES) {
            throw new InvalidUploadException('Uploaded file exceeds the 10 MB limit.');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidUploadException('Uploaded file is not readable.');
        }
        $magic = fread($handle, strlen(self::PDF_MAGIC));
        fclose($handle);
        if ($magic !== self::PDF_MAGIC) {
            throw new InvalidUploadException('Uploaded file is not a PDF.');
        }

        return $path;
    }

    private function classify(string $fileId): IntakeFormType
    {
        // Prompt construction is delegated to the pure
        // IntakeFormClassifierPrompt helper so the prompt + schema can be
        // exercised in isolated unit tests without touching the network.
        $request = new OpenAIStructuredRequest(
            model: $this->model,
            systemPrompt: IntakeFormClassifierPrompt::SYSTEM_PROMPT,
            userPrompt: IntakeFormClassifierPrompt::USER_PROMPT,
            fileIds: [$fileId],
            schemaName: IntakeFormClassifierPrompt::SCHEMA_NAME,
            schema: IntakeFormClassifierPrompt::classifierSchema(),
            maxTokens: 200,
        );

        $response = $this->openAiClient->complete($request);

        $confidence = $this->confidence($response);
        $typeValue = $response['form_type'] ?? null;

        if (!is_string($typeValue) || $confidence < $this->classifierThreshold) {
            throw new AmbiguousFormException(
                'Unable to confidently classify the uploaded form.'
            );
        }

        $resolved = IntakeFormType::tryFrom($typeValue);
        if ($resolved === null) {
            throw new AmbiguousFormException(
                'Classifier returned an unknown form type.'
            );
        }

        $this->logger->info('Intake form auto-classified', [
            'form_type' => $resolved->value,
            'confidence' => $confidence,
        ]);

        return $resolved;
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private function confidence(array $response): float
    {
        $confidence = $response['confidence'] ?? null;
        if (is_int($confidence)) {
            return (float) $confidence;
        }
        if (is_float($confidence)) {
            return $confidence;
        }

        return 0.0;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function extract(IntakeFormType $type, string $fileId): array
    {
        $request = match ($type) {
            IntakeFormType::Demographics => new OpenAIStructuredRequest(
                model: $this->model,
                systemPrompt: 'You extract patient registration / demographics fields from intake-form PDFs into strict JSON. Use null for any field you cannot read with confidence.',
                userPrompt: 'Extract the demographics + primary insurance fields from the attached PDF.',
                fileIds: [$fileId],
                schemaName: 'intake_demographics',
                schema: IntakeJsonSchemas::demographics(),
            ),
            IntakeFormType::MedicalHistory => new OpenAIStructuredRequest(
                model: $this->model,
                systemPrompt: 'You extract medical-history fields from intake-form PDFs into strict JSON. Each list field should contain only items present on the form.',
                userPrompt: 'Extract the medical-history sections from the attached PDF.',
                fileIds: [$fileId],
                schemaName: 'intake_medical_history',
                schema: IntakeJsonSchemas::medicalHistory(),
            ),
            IntakeFormType::Consent => new OpenAIStructuredRequest(
                model: $this->model,
                systemPrompt: 'You extract minimal metadata from a HIPAA / treatment consent PDF. Use null when the field is not present.',
                userPrompt: 'Extract patient name, signature date, and a one-sentence summary of the consent from the attached PDF.',
                fileIds: [$fileId],
                schemaName: 'intake_consent',
                schema: IntakeJsonSchemas::consent(),
                maxTokens: 400,
            ),
        };

        return $this->openAiClient->complete($request);
    }

    /**
     * Confirm OpenAI's structured response actually has the required
     * top-level fields the dispatcher expects. The strict response_format
     * already pins this on the OpenAI side, but a sanity-check defends
     * against schema drift and mocked clients in tests.
     *
     * @param array<array-key, mixed> $extracted
     */
    private function validateExtracted(IntakeFormType $type, array $extracted): void
    {
        $stringKeyed = [];
        foreach ($extracted as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        $errors = $this->schemaValidator->validate($type->value, $stringKeyed);
        if ($errors === []) {
            return;
        }

        $this->logger->warning('OpenAI extraction missing required fields', [
            'form_type' => $type->value,
            'missing_fields' => array_map(static fn(array $error): string => $error['field'], $errors),
        ]);
        throw new IngestionFailedException(
            'OpenAI extraction did not satisfy required intake-form fields.'
        );
    }

    private function storeOriginalPdf(
        int $patientId,
        string $sourcePath,
        IntakeFormType $type,
    ): int {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false || $bytes === '') {
            throw new IngestionFailedException('Failed to read uploaded PDF for storage.');
        }

        $categoryId = $this->resolveCategoryId($type);
        $filename = $this->displayFilename($type);

        // The legacy Document::createDocument receives $data by reference and
        // mutates the foreign-id state of the Document instance on success.
        $document = new \Document();
        $error = $document->createDocument(
            patient_id: (string) $patientId,
            category_id: $categoryId,
            filename: $filename,
            mimetype: 'application/pdf',
            data: $bytes,
        );

        if ($error !== '') {
            $this->logger->error('Document store rejected intake PDF', [
                'patient_id' => $patientId,
                'form_type' => $type->value,
                'error' => $error,
            ]);
            throw new IngestionFailedException('Failed to store the uploaded PDF.');
        }

        $documentId = $document->get_id();
        if (!is_int($documentId) && !is_numeric($documentId)) {
            throw new IngestionFailedException('Document was stored without a numeric id.');
        }

        return (int) $documentId;
    }

    /**
     * Resolve (or lazily create) the document category id for the given
     * form type. Demographics and MedicalHistory go under "Patient
     * Information"; Consent goes under "Consents" (created on first use).
     */
    private function resolveCategoryId(IntakeFormType $type): int
    {
        $name = match ($type) {
            IntakeFormType::Demographics => 'Patient Information',
            IntakeFormType::MedicalHistory => 'Medical Record',
            IntakeFormType::Consent => self::CONSENT_CATEGORY_NAME,
        };

        $existing = QueryUtils::fetchRecords(
            'SELECT id FROM `categories` WHERE name = ? LIMIT 1',
            [$name]
        );
        $row = $existing[0] ?? null;
        if (is_array($row) && isset($row['id']) && (is_int($row['id']) || is_numeric($row['id']))) {
            return (int) $row['id'];
        }

        if ($type !== IntakeFormType::Consent) {
            // Fall back to the root category if the named one is missing.
            // This keeps the upload flow working in fresh installs even if
            // the seed categories have been edited.
            return self::CONSENT_CATEGORY_PARENT_ID;
        }

        return $this->createConsentsCategory();
    }

    private function createConsentsCategory(): int
    {
        // Use MAX(rght)+1 to keep the nested-set tree consistent without a
        // full re-balance. Categories are read-only after install in
        // practice, so this lightweight append is acceptable.
        $maxRight = QueryUtils::fetchRecords(
            'SELECT COALESCE(MAX(rght), 0) AS max_right FROM `categories`',
            []
        );
        $row = $maxRight[0] ?? null;
        $rightStart = is_array($row) && isset($row['max_right']) && is_numeric($row['max_right'])
            ? (int) $row['max_right'] + 1
            : 1;

        $maxId = QueryUtils::fetchRecords(
            'SELECT COALESCE(MAX(id), 0) AS max_id FROM `categories`',
            []
        );
        $idRow = $maxId[0] ?? null;
        $newId = is_array($idRow) && isset($idRow['max_id']) && is_numeric($idRow['max_id'])
            ? (int) $idRow['max_id'] + 1
            : 1;

        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `categories` (id, name, value, parent, lft, rght, aco_spec, codes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $newId,
                self::CONSENT_CATEGORY_NAME,
                '',
                self::CONSENT_CATEGORY_PARENT_ID,
                $rightStart,
                $rightStart + 1,
                'patients|docs',
                '',
            ]
        );

        $this->logger->info('Created Consents document category', [
            'category_id' => $newId,
        ]);

        return $newId;
    }

    private function recordUpload(
        int $patientId,
        int $encounterId,
        int $authUserId,
        IntakeFormType $type,
        int $documentId,
        DispatchOutcome $outcome,
    ): int {
        try {
            $diffJson = json_encode(
                array_map(static fn(DiffEntry $entry): array => $entry->toArray(), $outcome->diffPreview),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            $this->logger->warning('Failed to serialise diff preview for upload log', [
                'exception' => $exception,
            ]);
            $diffJson = '[]';
        }

        // The schema (db/Migrations/Version20260504000001 + table.sql) is
        // canonical now: failure to INSERT here means the ingestion is
        // genuinely broken (missing migration, type mismatch, etc.). Let the
        // exception propagate so the caller surfaces it instead of silently
        // dropping the timeline row.
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        return QueryUtils::sqlInsert(
            'INSERT INTO `form_upload_intake_form`
                (`date`, `pid`, `encounter`, `user`, `groupname`, `authorized`, `activity`,
                 `form_type`, `document_id`, `inserted_row_id`, `diff_preview`)
                VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?)',
            [
                $now,
                $patientId,
                $encounterId,
                (string) $authUserId,
                'Default',
                $type->value,
                $documentId,
                $outcome->insertedRowId,
                $diffJson,
            ]
        );
    }

    private function resolveAuthUserId(): int
    {
        $session = $this->session ?? SessionWrapperFactory::getInstance()->getActiveSession();
        $value = $session->get('authUserID');
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Build a stable, human-readable filename for the upload that ALWAYS ends
     * with `.pdf`. This filename is used in two places:
     *
     *  - The multipart `filename=...` parameter sent to OpenAI's Files API.
     *    OpenAI's Files API infers the file's stored MIME type from this name
     *    rather than from the multipart `Content-Type` sub-header — if the
     *    filename does not end with `.pdf`, OpenAI registers MIME as `None`
     *    and the file_id is later rejected by `/v1/chat/completions` with
     *    `400: Invalid file data: ... unsupported MIME type 'None'`.
     *
     *  - The filename stored against the row in OpenEMR's `documents` module
     *    (see {@see IntakeFormIngestService::storeOriginalPdf()}).
     *
     * Earlier versions appended the basename of `$sourcePath`. That works for
     * CLI smoke harnesses (paths like `/tmp/c3.pdf`) but breaks under the
     * web-UI path: PHP/Symfony rename uploaded files to extensionless temp
     * paths like `/tmp/phpXXXXXX`, so the basename has no `.pdf` and OpenAI
     * mis-detects the MIME type. The source path's basename has no semantic
     * value (it's a server-side temp), so we no longer reference it.
     *
     * @return non-empty-string
     */
    private function displayFilename(?IntakeFormType $type): string
    {
        $stem = match ($type) {
            IntakeFormType::Demographics => 'demographics',
            IntakeFormType::MedicalHistory => 'medical-history',
            IntakeFormType::Consent => 'consent',
            null => 'intake',
        };
        $timestamp = $this->clock->now()->format('Ymd-His');

        return sprintf('intake-%s-%s.pdf', $stem, $timestamp);
    }
}
