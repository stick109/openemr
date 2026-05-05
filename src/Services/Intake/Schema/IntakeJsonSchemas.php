<?php

/**
 * IntakeJsonSchemas
 *
 * JSON-schema fragments used in the OpenAI structured-output requests for
 * each intake-form type, plus the auto-classifier schema. Each `forX()` is a
 * pure function returning a fresh array so the same schema can be reused
 * across requests without aliasing.
 *
 * The shapes here mirror the "OpenAI schema fields" column in §3.1 of
 * `intake-forms-plan.md`. Every field is optional from the model's
 * perspective so it can return null when the PDF does not contain the data
 * — strict mode requires every property in `properties` to appear in the
 * response, but each value is nullable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Schema;

final class IntakeJsonSchemas
{
    /**
     * @deprecated Use {@see \OpenEMR\Services\Intake\Classifier\IntakeFormClassifierPrompt::classifierSchema()}
     *             which is the canonical schema and uses snake_case `form_type`
     *             matching the production classifier.
     *
     * @return array<string, mixed>
     */
    public static function classifier(): array
    {
        return \OpenEMR\Services\Intake\Classifier\IntakeFormClassifierPrompt::classifierSchema();
    }

    /**
     * @return array<string, mixed>
     */
    public static function demographics(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'firstName', 'lastName', 'dob', 'sex', 'address', 'phone',
                'email', 'emergencyContact', 'insurance',
            ],
            'properties' => [
                'firstName' => self::nullableString(),
                'lastName' => self::nullableString(),
                'dob' => self::nullableString('YYYY-MM-DD'),
                'sex' => [
                    'type' => ['string', 'null'],
                    'enum' => ['Male', 'Female', 'Other', 'Unknown', null],
                ],
                'address' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['street', 'city', 'state', 'zip'],
                    'properties' => [
                        'street' => self::nullableString(),
                        'city' => self::nullableString(),
                        'state' => self::nullableString(),
                        'zip' => self::nullableString(),
                    ],
                ],
                'phone' => self::nullableString(),
                'email' => self::nullableString(),
                'emergencyContact' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'relationship', 'phone'],
                    'properties' => [
                        'name' => self::nullableString(),
                        'relationship' => self::nullableString(),
                        'phone' => self::nullableString(),
                    ],
                ],
                'insurance' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['carrier', 'memberId', 'group', 'planType'],
                    'properties' => [
                        'carrier' => self::nullableString(),
                        'memberId' => self::nullableString(),
                        'group' => self::nullableString(),
                        'planType' => self::nullableString(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function medicalHistory(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'conditions', 'surgeries', 'medications', 'allergies',
                'familyHistory', 'social',
            ],
            'properties' => [
                'conditions' => self::stringArray(),
                'surgeries' => self::stringArray(),
                'medications' => self::stringArray(),
                'allergies' => self::stringArray(),
                'familyHistory' => self::stringArray(),
                'social' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['smoking', 'alcohol', 'drugs'],
                    'properties' => [
                        'smoking' => self::nullableString(),
                        'alcohol' => self::nullableString(),
                        'drugs' => self::nullableString(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function consent(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['patientName', 'signatureDate', 'consentSummary'],
            'properties' => [
                'patientName' => self::nullableString(),
                'signatureDate' => self::nullableString('YYYY-MM-DD'),
                'consentSummary' => self::nullableString('one-sentence summary of what is consented to'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nullableString(string $description = ''): array
    {
        $schema = ['type' => ['string', 'null']];
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringArray(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
    }
}
