<?php

/**
 * DemographicsDispatcher
 *
 * Writes the demographics + primary insurance fields extracted from a PDF
 * into `patient_data` and `insurance_data`. Operates in fill-only-empty
 * mode by default (per CLAUDE-instructed default while plan Q2 is open):
 * an existing non-empty column is never overwritten.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Intake\Dispatcher;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use Psr\Log\LoggerInterface;

final class DemographicsDispatcher
{
    /**
     * Map from JSON field path to `patient_data` column name. Nested keys
     * use dotted-path notation that {@see lookupNested()} resolves.
     *
     * Emergency contact name + phone are NOT in this map — `patient_data`
     * has no dedicated name column, so the two fields are combined into
     * `phone_contact` by {@see composePhoneContact()} and applied as a
     * synthetic field after the rest of the map runs.
     *
     * @var array<string, string>
     */
    private const PATIENT_FIELD_MAP = [
        'firstName' => 'fname',
        'lastName' => 'lname',
        'dob' => 'DOB',
        'sex' => 'sex',
        'address.street' => 'street',
        'address.city' => 'city',
        'address.state' => 'state',
        'address.zip' => 'postal_code',
        'phone' => 'phone_home',
        'email' => 'email',
        'emergencyContact.relationship' => 'contact_relationship',
    ];

    /**
     * Map from JSON field path to `insurance_data` column name (primary
     * insurance only).
     *
     * @var array<string, string>
     */
    private const INSURANCE_FIELD_MAP = [
        'insurance.carrier' => 'provider',
        'insurance.memberId' => 'policy_number',
        'insurance.group' => 'group_number',
        'insurance.planType' => 'plan_name',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $fillOnlyEmpty = true,
    ) {
    }

    /**
     * @param array<array-key, mixed> $extracted Decoded JSON returned by OpenAI
     */
    public function dispatch(int $patientId, array $extracted): DispatchOutcome
    {
        $existing = $this->fetchExistingPatient($patientId);

        $patientUpdates = [];
        $diff = [];

        foreach (self::PATIENT_FIELD_MAP as $jsonPath => $column) {
            $newValue = $this->normaliseString($this->lookupNested($extracted, $jsonPath));
            $oldValue = $this->normaliseString($existing[$column] ?? null);

            $entry = $this->buildEntry($column, $oldValue, $newValue);
            $diff[] = $entry;

            if ($entry->applied && $newValue !== null) {
                $patientUpdates[$column] = $newValue;
            }
        }

        $emergencyName = $this->normaliseString(
            $this->lookupNested($extracted, 'emergencyContact.name')
        );
        $emergencyPhone = $this->normaliseString(
            $this->lookupNested($extracted, 'emergencyContact.phone')
        );
        $phoneContactValue = self::composePhoneContact($emergencyName, $emergencyPhone);
        $phoneContactOld = $this->normaliseString($existing['phone_contact'] ?? null);
        $phoneContactEntry = $this->buildEntry('phone_contact', $phoneContactOld, $phoneContactValue);
        $diff[] = $phoneContactEntry;
        if ($phoneContactEntry->applied && $phoneContactValue !== null) {
            $patientUpdates['phone_contact'] = $phoneContactValue;
        }

        if ($patientUpdates !== []) {
            $this->updatePatient($patientId, $patientUpdates);
        }

        $insuranceUpdates = [];
        foreach (self::INSURANCE_FIELD_MAP as $jsonPath => $column) {
            $newValue = $this->normaliseString($this->lookupNested($extracted, $jsonPath));
            $insuranceUpdates[$column] = $newValue;
        }

        $insuranceDiff = $this->upsertPrimaryInsurance($patientId, $insuranceUpdates);
        foreach ($insuranceDiff as $entry) {
            $diff[] = $entry;
        }

        return new DispatchOutcome(
            insertedRowId: $patientId,
            diffPreview: $diff,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function fetchExistingPatient(int $patientId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT * FROM `patient_data` WHERE pid = ? LIMIT 1',
            [$patientId]
        );
        if ($rows === []) {
            throw new IngestionFailedException('Patient not found.');
        }

        return $rows[0];
    }

    /**
     * @param array<string, string> $updates Column => new value
     */
    private function updatePatient(int $patientId, array $updates): void
    {
        $assignments = [];
        $bindings = [];
        foreach ($updates as $column => $value) {
            $assignments[] = '`' . $column . '` = ?';
            $bindings[] = $value;
        }
        $bindings[] = $patientId;

        $sql = 'UPDATE `patient_data` SET ' . implode(', ', $assignments) . ' WHERE pid = ?';
        QueryUtils::sqlStatementThrowException($sql, $bindings);
    }

    /**
     * @param array<string, ?string> $updates
     * @return list<DiffEntry>
     */
    private function upsertPrimaryInsurance(int $patientId, array $updates): array
    {
        $existing = QueryUtils::fetchRecords(
            'SELECT * FROM `insurance_data` WHERE pid = ? AND `type` = ? LIMIT 1',
            [$patientId, 'primary']
        );
        $existingRow = $existing[0] ?? null;

        $hasMeaningfulData = false;
        foreach ($updates as $value) {
            if ($value !== null && $value !== '') {
                $hasMeaningfulData = true;
                break;
            }
        }
        if (!$hasMeaningfulData) {
            return [];
        }

        $diff = [];
        $writes = [];
        foreach ($updates as $column => $newValue) {
            $oldValue = $this->normaliseString($existingRow[$column] ?? null);
            $entry = $this->buildEntry('insurance.' . $column, $oldValue, $newValue);
            $diff[] = $entry;
            if ($entry->applied) {
                $writes[$column] = $newValue;
            }
        }

        if ($writes === []) {
            return $diff;
        }

        if ($existingRow === null) {
            $columns = ['pid', 'type', 'date'];
            $placeholders = ['?', '?', 'CURRENT_DATE()'];
            $bindings = [$patientId, 'primary'];
            foreach ($writes as $column => $value) {
                $columns[] = '`' . $column . '`';
                $placeholders[] = '?';
                $bindings[] = $value;
            }

            $sql = 'INSERT INTO `insurance_data` ('
                . implode(', ', $columns)
                . ') VALUES (' . implode(', ', $placeholders) . ')';
            QueryUtils::sqlInsert($sql, $bindings);

            return $diff;
        }

        $assignments = [];
        $bindings = [];
        foreach ($writes as $column => $value) {
            $assignments[] = '`' . $column . '` = ?';
            $bindings[] = $value;
        }
        $idValue = $existingRow['id'] ?? null;
        if (!is_int($idValue) && !is_string($idValue)) {
            throw new IngestionFailedException('Existing insurance row had no usable id.');
        }
        $bindings[] = $idValue;

        $sql = 'UPDATE `insurance_data` SET ' . implode(', ', $assignments) . ' WHERE id = ?';
        QueryUtils::sqlStatementThrowException($sql, $bindings);

        return $diff;
    }

    private function buildEntry(string $field, ?string $oldValue, ?string $newValue): DiffEntry
    {
        if ($newValue === null || $newValue === '') {
            return new DiffEntry($field, $oldValue, $newValue, false, 'no value extracted');
        }

        $existingHasValue = $oldValue !== null && $oldValue !== '';
        if ($this->fillOnlyEmpty && $existingHasValue) {
            return new DiffEntry($field, $oldValue, $newValue, false, 'existing value preserved');
        }

        if ($existingHasValue && $oldValue === $newValue) {
            return new DiffEntry($field, $oldValue, $newValue, false, 'value unchanged');
        }

        return new DiffEntry($field, $oldValue, $newValue, true);
    }

    /**
     * Build the value to write into `patient_data.phone_contact`.
     *
     * `patient_data` has no dedicated column for the emergency contact's
     * name, so the OpenAI-extracted name and phone are combined into a
     * single string. The format `Name <phone>` is unambiguous and
     * trivially parseable by downstream consumers (one regex on the angle
     * brackets recovers both halves), and degrades gracefully when only
     * one half is present:
     *
     *   - both present  → `Jane Smith <555-123-4567>`
     *   - only name     → `Jane Smith`
     *   - only phone    → `555-123-4567`
     *   - neither       → `null` (caller skips the column)
     */
    public static function composePhoneContact(?string $name, ?string $phone): ?string
    {
        $name = ($name === null || $name === '') ? null : $name;
        $phone = ($phone === null || $phone === '') ? null : $phone;

        if ($name === null && $phone === null) {
            return null;
        }
        if ($name === null) {
            return $phone;
        }
        if ($phone === null) {
            return $name;
        }
        return $name . ' <' . $phone . '>';
    }

    /**
     * @param array<array-key, mixed> $haystack
     */
    private function lookupNested(array $haystack, string $dottedPath): mixed
    {
        $cursor = $haystack;
        foreach (explode('.', $dottedPath) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function normaliseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $this->logger->warning('Demographics field had unexpected type', [
            'type' => get_debug_type($value),
        ]);

        return null;
    }
}
