<?php

/**
 * IntakeFormSchemaValidator
 *
 * Minimal post-receipt validator for the structured payload OpenAI returns
 * after extracting an intake-form PDF. Where {@see IntakeJsonSchemas} drives
 * the *strict* prompt OpenAI must satisfy on the way out, this validator
 * sanity-checks what actually came back: required top-level fields are
 * present, and unknown form types are rejected.
 *
 * The validator is hand-rolled rather than wired through Opis JSON Schema
 * for two reasons: (1) we only need required-field presence — the strict
 * shape is already enforced upstream by OpenAI's `response_format`, and
 * (2) keeping it pure-PHP makes the isolated tests trivially fast and
 * runnable without a database or Composer dev-only dependencies.
 *
 * Returned errors have a uniform shape so the UI / tests can key off
 * `field`:
 *     ['field' => 'firstName', 'message' => 'Required field is missing.']
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Schema;

final class IntakeFormSchemaValidator
{
    /**
     * Required top-level fields per form type. Mirrors the structural
     * requirements documented in intake-forms-plan.md §3.1.
     *
     * Consent intentionally requires only `patientName` + `signatureDate` —
     * `consentSummary` is solicited from OpenAI but is not load-bearing for
     * downstream writes (the PDF itself is the canonical record of consent).
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_TOP_LEVEL_FIELDS = [
        'Demographics' => [
            'firstName',
            'lastName',
            'dob',
            'sex',
            'address',
            'phone',
            'email',
            'emergencyContact',
            'insurance',
        ],
        'MedicalHistory' => [
            'conditions',
            'surgeries',
            'medications',
            'allergies',
            'familyHistory',
            'social',
        ],
        'Consent' => [
            'patientName',
            'signatureDate',
        ],
    ];

    /**
     * Validate `$payload` against the required-field contract for `$formType`.
     *
     * Errors are returned as a list (potentially empty when the payload is
     * valid). An unknown `$formType` produces a single error pointing at
     * `formType` itself.
     *
     * @param array<string, mixed> $payload
     * @return list<array{field: string, message: string}>
     */
    public function validate(string $formType, array $payload): array
    {
        if (!array_key_exists($formType, self::REQUIRED_TOP_LEVEL_FIELDS)) {
            return [[
                'field' => 'formType',
                'message' => 'Unknown intake form type.',
            ]];
        }

        $errors = [];
        foreach (self::REQUIRED_TOP_LEVEL_FIELDS[$formType] as $field) {
            if (!array_key_exists($field, $payload)) {
                $errors[] = [
                    'field' => $field,
                    'message' => 'Required field is missing.',
                ];
                continue;
            }
            if ($payload[$field] === null) {
                $errors[] = [
                    'field' => $field,
                    'message' => 'Required field is null.',
                ];
            }
        }

        return $errors;
    }

    /**
     * Returns the list of required top-level fields for the given form
     * type, or `null` when the type is unknown.
     *
     * @return ?list<string>
     */
    public static function requiredFieldsFor(string $formType): ?array
    {
        return self::REQUIRED_TOP_LEVEL_FIELDS[$formType] ?? null;
    }
}
