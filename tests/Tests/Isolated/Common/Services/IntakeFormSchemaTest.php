<?php

/**
 * IntakeFormSchemaTest
 *
 * Asserts the per-form-type validator for the intake-form upload feature
 * (intake-forms-plan.md §3.4 dispatch + §3.1 generator schemas). For each
 * FormType (Demographics, MedicalHistory, Consent) the test verifies:
 *
 *   1. Required fields are present in the validator.
 *   2. A payload missing a required field produces an error keyed by that
 *      field.
 *   3. A complete payload validates without errors.
 *
 * Targets {@see IntakeFormSchemaValidator} directly — the validator is a
 * pure helper with no DB or HTTP dependencies.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

use OpenEMR\Services\Intake\Schema\IntakeFormSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('isolated')]
#[Group('intake-forms')]
class IntakeFormSchemaTest extends TestCase
{
    /**
     * Required fields per intake-forms-plan.md §3.1.
     *
     * Top-level keys only — the validator currently only enforces presence
     * at the top level; deeper structural checks remain OpenAI's
     * responsibility via `response_format` strict mode.
     */
    private const REQUIRED_FIELDS = [
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

    private IntakeFormSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IntakeFormSchemaValidator();
    }

    #[DataProvider('formTypeProvider')]
    public function testValidPayloadHasNoErrors(string $formType): void
    {
        $errors = $this->validator->validate($formType, $this->validPayload($formType));

        $this->assertSame([], $errors, "Expected no errors for valid {$formType} payload");
    }

    #[DataProvider('missingRequiredFieldProvider')]
    public function testMissingRequiredFieldProducesSchemaError(string $formType, string $missingField): void
    {
        $payload = $this->validPayload($formType);
        unset($payload[$missingField]);

        $errors = $this->validator->validate($formType, $payload);

        $this->assertNotEmpty(
            $errors,
            "Expected at least one error when {$missingField} is missing from {$formType}"
        );
        $errorKeys = array_map(static fn(array $error): string => $error['field'], $errors);
        $this->assertContains(
            $missingField,
            $errorKeys,
            "Expected error pointing at the missing '{$missingField}' field; got: "
            . implode(', ', $errorKeys)
        );
    }

    public function testUnknownFormTypeIsRejected(): void
    {
        $errors = $this->validator->validate('UnknownType', ['anything' => 'goes']);

        $this->assertNotEmpty($errors, 'Unknown form types must produce errors');
        $errorKeys = array_map(static fn(array $error): string => $error['field'], $errors);
        $this->assertContains('formType', $errorKeys);
    }

    public function testRequiredFieldsForExposesPlannedFieldList(): void
    {
        foreach (self::REQUIRED_FIELDS as $formType => $expectedFields) {
            $actualFields = IntakeFormSchemaValidator::requiredFieldsFor($formType);
            $this->assertSame(
                $expectedFields,
                $actualFields,
                "Required fields for {$formType} drifted from intake-forms-plan.md §3.1"
            );
        }

        $this->assertNull(
            IntakeFormSchemaValidator::requiredFieldsFor('Whatever'),
            'requiredFieldsFor must return null for unknown form types'
        );
    }

    public function testNullRequiredFieldIsRejected(): void
    {
        $payload = $this->validPayload('Consent');
        $payload['patientName'] = null;

        $errors = $this->validator->validate('Consent', $payload);

        $errorKeys = array_map(static fn(array $error): string => $error['field'], $errors);
        $this->assertContains('patientName', $errorKeys);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function formTypeProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::REQUIRED_FIELDS) as $type) {
            $cases[$type] = [$type];
        }
        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function missingRequiredFieldProvider(): array
    {
        $cases = [];
        foreach (self::REQUIRED_FIELDS as $type => $fields) {
            foreach ($fields as $field) {
                $cases["{$type}_missing_{$field}"] = [$type, $field];
            }
        }
        return $cases;
    }

    /**
     * Returns a fully-populated payload for the given form type that
     * satisfies the required-field contract from intake-forms-plan.md §3.1.
     *
     * @return array<string, mixed>
     */
    private function validPayload(string $formType): array
    {
        return match ($formType) {
            'Demographics' => [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'dob' => '1985-04-12',
                'sex' => 'F',
                'address' => [
                    'street' => '123 Main St',
                    'city' => 'Springfield',
                    'state' => 'IL',
                    'zip' => '62701',
                ],
                'phone' => '+12175550101',
                'email' => 'jane.doe@example.com',
                'emergencyContact' => [
                    'name' => 'John Doe',
                    'relationship' => 'Spouse',
                    'phone' => '+12175550102',
                ],
                'insurance' => [
                    'carrier' => 'Acme Health',
                    'memberId' => 'AC123456789',
                    'group' => 'GRP001',
                    'planType' => 'PPO',
                ],
            ],
            'MedicalHistory' => [
                'conditions' => ['Hypertension'],
                'surgeries' => ['Appendectomy 2010'],
                'medications' => ['Lisinopril 10mg daily'],
                'allergies' => ['Penicillin'],
                'familyHistory' => ['Father: diabetes'],
                'social' => [
                    'smoking' => 'Never',
                    'alcohol' => 'Occasional',
                    'drugs' => 'None',
                ],
            ],
            'Consent' => [
                'patientName' => 'Jane Doe',
                'signatureDate' => '2026-05-04',
            ],
            default => [],
        };
    }
}
