<?php

/**
 * IngestResult
 *
 * Immutable summary of a successful intake-form ingestion. Returned by
 * {@see IntakeFormIngestService::ingest()} and consumed by the encounter
 * form's `save.php` to render confirmation UI and link the encounter
 * timeline entry to the data that was created.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake;

final readonly class IngestResult
{
    /**
     * @param string $formType The resolved form-type label, one of
     *                         {@see IntakeFormType}'s case values.
     * @param int $documentId Foreign id into the `documents` table for the
     *                        original PDF (always saved, regardless of type).
     * @param ?int $insertedRowId Primary id of the type-specific row that
     *                            was created or updated:
     *                            - Demographics: `patient_data.id`
     *                            - MedicalHistory: `questionnaire_response.id`
     *                            - Consent: same as `$documentId`
     *                            Null when no row was created (Auto + reject).
     * @param list<array{field: string, old: ?string, new: ?string, applied: bool, reason: ?string}> $diffPreview
     *                            A field-level diff (one entry per field) the
     *                            UI can render to show what was applied vs
     *                            preserved.
     */
    public function __construct(
        public string $formType,
        public int $documentId,
        public ?int $insertedRowId,
        public array $diffPreview,
    ) {
    }
}
