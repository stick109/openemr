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

    /**
     * Parse a request-time form-type string into either a concrete
     * {@see IntakeFormType} (when the caller has chosen one explicitly) or
     * `null` (when the caller has selected `Auto` and wants the classifier
     * to decide). Anything else throws.
     */
    public static function fromRequest(string $value): ?self
    {
        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'Auto') === 0) {
            return null;
        }

        // Allow the values used in the UI dropdown that contain spaces.
        $normalised = match (strcasecmp($value, 'Medical History') === 0) {
            true => 'MedicalHistory',
            false => $value,
        };

        $resolved = self::tryFrom($normalised);
        if ($resolved === null) {
            throw new InvalidFormTypeException('Unknown intake form type.');
        }

        return $resolved;
    }
}
