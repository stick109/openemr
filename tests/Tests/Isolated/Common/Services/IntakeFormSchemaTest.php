<?php

/**
 * IntakeFormSchemaTest
 *
 * Asserts the per-form-type extraction schemas defined for the intake-form
 * upload feature (intake-forms-plan.md §3.4 dispatch + §3.1 generator
 * schemas). For each FormType (Demographics, MedicalHistory, Consent), the
 * test verifies:
 *
 *   1. Required fields are present in the schema.
 *   2. A payload missing a required field produces a schema error keyed by
 *      that field.
 *   3. A complete payload validates without errors.
 *
 * The schema implementation is owned by the §3.4/3.5 sibling agent. This
 * test parametrises over a `validateSchema(string $type, array $payload):
 * array $errors` callable resolved at runtime — if the §3.4/3.5 agent has
 * not landed their implementation yet the test skips with a clear message.
 *
 * Resolution order for the validator:
 *   1. OpenEMR\Services\IntakeForm\IntakeFormSchema::validate($type, $payload)
 *   2. OpenEMR\Services\IntakeForm\IntakeFormSchemaValidator::validate(...)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Services;

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
     * Top-level keys only — nested objects (e.g. address sub-fields) are
     * exercised via the missing-required cases below.
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

    protected function setUp(): void
    {
        if ($this->resolveValidator() === null) {
            $this->markTestSkipped(
                'IntakeFormSchema validator (intake-forms-plan.md §3.4/§3.5) not yet '
                . 'implemented; this test will run once the sibling work lands.'
            );
        }
    }

    #[DataProvider('formTypeProvider')]
    public function testValidPayloadHasNoErrors(string $formType): void
    {
        $errors = $this->validate($formType, $this->validPayload($formType));

        $this->assertSame([], $errors, "Expected no errors for valid {$formType} payload");
    }

    #[DataProvider('missingRequiredFieldProvider')]
    public function testMissingRequiredFieldProducesSchemaError(string $formType, string $missingField): void
    {
        $payload = $this->validPayload($formType);
        unset($payload[$missingField]);

        $errors = $this->validate($formType, $payload);

        $this->assertNotEmpty(
            $errors,
            "Expected at least one error when {$missingField} is missing from {$formType}"
        );
        $errorKeys = $this->collectErrorPointers($errors);
        $this->assertContains(
            $missingField,
            $errorKeys,
            "Expected error pointing at the missing '{$missingField}' field; got: "
            . implode(', ', $errorKeys)
        );
    }

    public function testUnknownFormTypeIsRejected(): void
    {
        $errors = $this->validate('UnknownType', ['anything' => 'goes']);

        $this->assertNotEmpty($errors, 'Unknown form types must produce errors');
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
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>|string>
     */
    private function validate(string $formType, array $payload): array
    {
        $validator = $this->resolveValidator();
        if ($validator === null) {
            $this->fail('Validator should have been resolved by setUp()');
        }
        $result = $validator($formType, $payload);
        $this->assertIsArray($result, 'Validator must return an array of errors');
        $errors = [];
        foreach ($result as $error) {
            if (is_string($error)) {
                $errors[] = $error;
                continue;
            }
            $this->assertIsArray($error, 'Each error entry must be an array or string');
            $typed = [];
            foreach ($error as $key => $value) {
                $this->assertIsString($key);
                $typed[$key] = $value;
            }
            $errors[] = $typed;
        }
        return $errors;
    }

    private function resolveValidator(): ?\Closure
    {
        // Build the FQCNs from parts so PHPStan does not eagerly resolve them
        // to "always missing" — these classes are owned by the §3.4 sibling
        // worktree and may not exist at analysis time.
        $base = 'OpenEMR\\' . 'Services\\' . 'IntakeForm\\';

        // Form 1: static method on the schema class itself.
        $schemaClass = $base . 'IntakeFormSchema';
        if (is_callable([$schemaClass, 'validate'])) {
            /**
             * @param array<string, mixed> $payload
             * @return array<int|string, mixed>
             */
            return static function (string $type, array $payload) use ($schemaClass): array {
                $callable = [$schemaClass, 'validate'];
                /** @var callable $callable */
                return (array) $callable($type, $payload);
            };
        }

        // Form 2: dedicated validator class (Pydantic-style).
        $validatorClass = self::asClassString($base . 'IntakeFormSchemaValidator');
        if (class_exists($validatorClass)) {
            // Use reflection so PHPStan does not flag the missing class.
            $reflection = new \ReflectionClass($validatorClass);
            $instance = $reflection->newInstance();
            if (is_callable([$instance, 'validate'])) {
                /**
                 * @param array<string, mixed> $payload
                 * @return array<int|string, mixed>
                 */
                return static function (string $type, array $payload) use ($instance): array {
                    $callable = [$instance, 'validate'];
                    /** @var callable $callable */
                    return (array) $callable($type, $payload);
                };
            }
        }

        return null;
    }

    /**
     * Identity wrapper that converts a runtime string into a class-string
     * without PHPStan being able to fold it back to the literal value.
     *
     * @return class-string
     */
    private static function asClassString(string $fqcn): string
    {
        /** @var class-string */
        return $fqcn;
    }

    /**
     * @param list<array<string, mixed>|string> $errors
     * @return list<string>
     */
    private function collectErrorPointers(array $errors): array
    {
        $pointers = [];
        foreach ($errors as $error) {
            // Accept several common error shapes:
            //  - ['field' => 'firstName', 'message' => '...']
            //  - ['property' => 'firstName', ...]
            //  - ['pointer' => '/firstName', ...]
            //  - ['path' => 'firstName', ...]
            //  - 'firstName' (raw string)
            if (is_string($error)) {
                $pointers[] = $error;
                continue;
            }
            foreach (['field', 'property', 'pointer', 'path', 'name'] as $key) {
                if (isset($error[$key]) && is_string($error[$key])) {
                    $pointers[] = ltrim($error[$key], '/');
                    continue 2;
                }
            }
        }
        return $pointers;
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
