<?php

/**
 * IntakeFormType
 *
 * Enumeration of the intake-form types this service understands. Backed by
 * string values because the value is persisted into the
 * `form_upload_intake_form` row that records each upload, and serialized to
 * JSON when surfacing diff previews to the UI.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake;

use OpenEMR\Services\Intake\Exception\InvalidFormTypeException;

enum IntakeFormType: string
{
    case Demographics = 'Demographics';
    case MedicalHistory = 'MedicalHistory';
    case Consent = 'Consent';
    case LabPdf = 'lab_pdf';

    /**
     * Parse a request-time form-type string into either a concrete
     * {@see IntakeFormType} (when the caller has chosen one explicitly) or
     * `null` (when the caller selected `Auto` and wants the classifier to
     * decide). The accepted vocabulary is exactly the five wire-level values
     * `Auto`, `Demographics`, `MedicalHistory`, `Consent`, `lab_pdf`.
     * Display labels (e.g. "Medical History" with a space, "Auto-detect")
     * live in the UI dropdown; they must never reach the service. Anything
     * else throws.
     */
    public static function fromRequest(string $value): ?self
    {
        if ($value === 'Auto') {
            return null;
        }

        $resolved = self::tryFrom($value);
        if ($resolved === null) {
            throw new InvalidFormTypeException('Unknown intake form type.');
        }

        return $resolved;
    }
}
