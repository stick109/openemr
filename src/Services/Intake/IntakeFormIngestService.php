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
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\Intake\Dispatcher\ConsentDispatcher;
use OpenEMR\Services\Intake\Dispatcher\DemographicsDispatcher;
use OpenEMR\Services\Intake\Dispatcher\DiffEntry;
use OpenEMR\Services\Intake\Dispatcher\DispatchOutcome;
use OpenEMR\Services\Intake\Dispatcher\MedicalHistoryDispatcher;
use OpenEMR\Services\Intake\Exception\AmbiguousFormException;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use OpenEMR\Services\Intake\Exception\InvalidUploadException;
use OpenEMR\Services\Intake\OpenAi\OpenAIClient;
use OpenEMR\Services\Intake\OpenAi\OpenAIStructuredRequest;
use OpenEMR\Services\Intake\Schema\IntakeJsonSchemas;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class IntakeFormIngestService
{
    private const MAX_PDF_BYTES = 10 * 1024 * 1024;
    private const PDF_MAGIC = '%PDF-';
    private const CLASSIFIER_THRESHOLD = 0.6;
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const CONSENT_CATEGORY_NAME = 'Consents';
    private const CONSENT_CATEGORY_PARENT_ID = 1;

    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private readonly OpenAIClient $openAiClient,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly DemographicsDispatcher $demographicsDispatcher,
        private readonly MedicalHistoryDispatcher $medicalHistoryDispatcher,
        private readonly ConsentDispatcher $consentDispatcher,
        private readonly ?SessionInterface $session = null,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly float $classifierThreshold = self::CLASSIFIER_THRESHOLD,
    ) {
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

        $pdfPath = $this->validateUpload($uploadedPdfPath);

        $requestedType = IntakeFormType::fromRequest($formType);

        $fileId = $this->openAiClient->uploadPdf(
            $pdfPath,
            $this->displayFilename($pdfPath, $requestedType)
        );

        $resolvedType = $requestedType ?? $this->classify($fileId);

        $extracted = $this->extract($resolvedType, $fileId);

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

        $this->recordUpload(
            patientId: $patientId,
            encounterId: $encounterId,
            authUserId: $authUserId,
            type: $resolvedType,
            documentId: $documentId,
            outcome: $outcome,
        );

        $this->logger->info('Intake form ingested', [
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'form_type' => $resolvedType->value,
            'document_id' => $documentId,
            'inserted_row_id' => $outcome->insertedRowId,
        ]);

        return new IngestResult(
            formType: $resolvedType->value,
            documentId: $documentId,
            insertedRowId: $outcome->insertedRowId,
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
        $request = new OpenAIStructuredRequest(
            model: $this->model,
            systemPrompt: 'You classify clinical-intake PDF forms. Choose exactly one of: Demographics, MedicalHistory, Consent, Unknown. Return strict JSON.',
            userPrompt: 'Identify the form type for the attached PDF. Provide a confidence score in [0, 1].',
            fileIds: [$fileId],
            schemaName: 'intake_form_classifier',
            schema: IntakeJsonSchemas::classifier(),
            maxTokens: 200,
        );

        $response = $this->openAiClient->complete($request);

        $confidence = $this->confidence($response);
        $typeValue = $response['formType'] ?? null;

        if (!is_string($typeValue) || $typeValue === 'Unknown' || $confidence < $this->classifierThreshold) {
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
        $filename = $this->displayFilename($sourcePath, $type);

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
    ): void {
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

        try {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            QueryUtils::sqlInsert(
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
        } catch (SqlQueryException $exception) {
            // The §3.8 migration creates this table; if it does not exist
            // yet we still want the rest of the ingestion to count as a
            // success. Log loudly and continue.
            $this->logger->error('Failed to record intake upload row', [
                'exception' => $exception,
                'patient_id' => $patientId,
                'encounter_id' => $encounterId,
                'form_type' => $type->value,
            ]);
        }
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
     * @return non-empty-string
     */
    private function displayFilename(string $sourcePath, ?IntakeFormType $type): string
    {
        $stem = match ($type) {
            IntakeFormType::Demographics => 'demographics',
            IntakeFormType::MedicalHistory => 'medical-history',
            IntakeFormType::Consent => 'consent',
            null => 'intake',
        };
        $timestamp = $this->clock->now()->format('Ymd-His');
        $original = pathinfo($sourcePath, PATHINFO_BASENAME);
        $sanitised = preg_replace('/[^A-Za-z0-9._-]+/', '-', $original);
        if (!is_string($sanitised) || $sanitised === '') {
            $sanitised = 'upload.pdf';
        }

        return sprintf('intake-%s-%s-%s', $stem, $timestamp, $sanitised);
    }
}
