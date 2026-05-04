<?php

/**
 * ConsentDispatcher
 *
 * Stores the original consent PDF in the OpenEMR `documents` module under
 * a "Consents" category (created on first use if it does not exist). The
 * actual PDF write is handled in
 * {@see \OpenEMR\Services\Intake\IntakeFormIngestService} via the document
 * module; this dispatcher only surfaces a diff entry to the UI describing
 * what was extracted.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Dispatcher;

final class ConsentDispatcher
{
    /**
     * Build a diff preview for a Consent ingestion. The PDF itself has
     * already been stored by the time this is called; the document id is
     * recorded by the caller.
     *
     * @param array<array-key, mixed> $extracted
     */
    public function buildOutcome(int $documentId, array $extracted): DispatchOutcome
    {
        $diff = [];
        foreach (['patientName', 'signatureDate', 'consentSummary'] as $field) {
            $value = $extracted[$field] ?? null;
            $stringValue = is_string($value) ? trim($value) : null;
            $diff[] = new DiffEntry(
                field: $field,
                oldValue: null,
                newValue: $stringValue !== null && $stringValue !== '' ? $stringValue : null,
                applied: $stringValue !== null && $stringValue !== '',
            );
        }

        return new DispatchOutcome(
            insertedRowId: $documentId,
            diffPreview: $diff,
        );
    }
}
