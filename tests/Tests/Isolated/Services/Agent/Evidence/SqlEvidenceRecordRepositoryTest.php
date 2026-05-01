<?php

/**
 * SqlEvidenceRecordRepositoryTest
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence {
    function sqlQuery(string $sql, array $params = []): mixed
    {
        return SqlEvidenceRecordRepositorySqlFixture::sqlQuery($sql, $params);
    }

    function sqlStatement(string $sql, array $params = []): mixed
    {
        return SqlEvidenceRecordRepositorySqlFixture::sqlStatement($sql, $params);
    }

    function sqlFetchArray(mixed $statement): mixed
    {
        return SqlEvidenceRecordRepositorySqlFixture::sqlFetchArray($statement);
    }

    final class SqlEvidenceRecordRepositorySqlFixture
    {
        /**
         * @var array<int, array<string, mixed>>
         */
        public static array $patientRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $addressRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $telecomRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $employerRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $medicationRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $allergyRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $prescriptionRows = [];

        /**
         * @var list<array<string, mixed>>
         */
        public static array $listsTouchRows = [];

        /**
         * @var list<array{sql: string, params: array<int, mixed>}>
         */
        public static array $queries = [];

        /**
         * @var array<string, list<array<string, mixed>>>
         */
        private static array $statements = [];

        private static int $statementCount = 0;

        public static function reset(): void
        {
            self::$patientRows = [];
            self::$addressRows = [];
            self::$telecomRows = [];
            self::$employerRows = [];
            self::$medicationRows = [];
            self::$allergyRows = [];
            self::$prescriptionRows = [];
            self::$listsTouchRows = [];
            self::$queries = [];
            self::$statements = [];
            self::$statementCount = 0;
        }

        public static function sqlQuery(string $sql, array $params): mixed
        {
            self::$queries[] = ['sql' => $sql, 'params' => $params];

            if (str_contains($sql, 'FROM patient_data')) {
                return self::$patientRows[(int) ($params[0] ?? 0)] ?? false;
            }

            if (str_contains($sql, 'INNER JOIN addresses')) {
                return self::firstOwnedRow(self::$addressRows, 'address_id', $params);
            }

            if (str_contains($sql, 'INNER JOIN contact_telecom')) {
                return self::firstOwnedRow(self::$telecomRows, 'contact_telecom_id', $params);
            }

            if (str_contains($sql, 'FROM employer_data')) {
                return self::firstOwnedRow(self::$employerRows, 'employer_data_id', $params);
            }

            if (str_contains($sql, 'FROM lists_medication')) {
                return self::firstOwnedRow(self::$medicationRows, 'medication_issue_id', $params);
            }

            if (str_contains($sql, 'FROM prescriptions p')) {
                return self::firstOwnedRow(self::$prescriptionRows, 'prescription_record_id', $params);
            }

            if (str_contains($sql, 'FROM lists_touch')) {
                $pid = (int) ($params[0] ?? 0);
                $type = str_contains($sql, "type = 'allergy'") ? 'allergy' : 'medication';
                foreach (self::$listsTouchRows as $row) {
                    if ((int) ($row['patient_id'] ?? 0) === $pid && ($row['type'] ?? '') === $type) {
                        return $row;
                    }
                }
            }

            if (str_contains($sql, 'FROM lists l')) {
                return self::firstOwnedRow(array_merge(self::$allergyRows, self::$medicationRows), 'id', $params);
            }

            return false;
        }

        public static function sqlStatement(string $sql, array $params): mixed
        {
            self::$queries[] = ['sql' => $sql, 'params' => $params];

            $rows = [];
            if (str_contains($sql, 'INNER JOIN addresses')) {
                $rows = self::ownedRows(self::$addressRows, (int) ($params[0] ?? 0), self::limitFromSql($sql));
            } elseif (str_contains($sql, 'INNER JOIN contact_telecom')) {
                $rows = self::ownedRows(self::$telecomRows, (int) ($params[0] ?? 0), self::limitFromSql($sql));
            } elseif (str_contains($sql, 'FROM employer_data')) {
                $rows = self::ownedRows(self::$employerRows, (int) ($params[0] ?? 0), self::limitFromSql($sql));
            } elseif (str_contains($sql, 'FROM prescriptions p')) {
                $rows = self::standalonePrescriptionRows((int) ($params[0] ?? 0), self::limitFromSql($sql));
            } elseif (str_contains($sql, 'FROM lists l')) {
                $pid = str_contains($sql, 'LEFT JOIN prescriptions p') ? (int) ($params[1] ?? 0) : (int) ($params[0] ?? 0);
                $sourceRows = str_contains($sql, "l.type = 'allergy'") ? self::$allergyRows : self::$medicationRows;
                $rows = self::ownedRows($sourceRows, $pid, self::limitFromSql($sql));
            }

            $id = 'stmt-' . (++self::$statementCount);
            self::$statements[$id] = $rows;

            return $id;
        }

        public static function sqlFetchArray(mixed $statement): mixed
        {
            if (!is_string($statement) || !isset(self::$statements[$statement])) {
                return false;
            }

            return array_shift(self::$statements[$statement]) ?: false;
        }

        /**
         * @param list<array<string, mixed>> $rows
         * @param array<int, mixed> $params
         * @return array<string, mixed>|false
         */
        private static function firstOwnedRow(array $rows, string $idField, array $params): array|false
        {
            $pid = (int) ($params[0] ?? 0);
            $recordId = (int) ($params === [] ? 0 : $params[array_key_last($params)]);
            foreach ($rows as $row) {
                if ((int) ($row['patient_id'] ?? 0) === $pid && (int) ($row[$idField] ?? 0) === $recordId) {
                    return $row;
                }
            }

            return false;
        }

        /**
         * @param list<array<string, mixed>> $rows
         * @return list<array<string, mixed>>
         */
        private static function ownedRows(array $rows, int $pid, int $limit): array
        {
            $ownedRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['patient_id'] ?? 0) === $pid
            ));

            return array_slice($ownedRows, 0, $limit);
        }

        private static function limitFromSql(string $sql): int
        {
            return preg_match('/LIMIT\s+([0-9]+)/i', $sql, $matches) === 1 ? (int) $matches[1] : 100;
        }

        /**
         * @return list<array<string, mixed>>
         */
        private static function standalonePrescriptionRows(int $pid, int $limit): array
        {
            $linkedPrescriptionIds = [];
            foreach (self::$medicationRows as $row) {
                if ((int) ($row['patient_id'] ?? 0) !== $pid) {
                    continue;
                }
                $linkedPrescriptionId = (int) ($row['linked_prescription_id'] ?? 0);
                if ($linkedPrescriptionId > 0) {
                    $linkedPrescriptionIds[$linkedPrescriptionId] = true;
                }
            }

            $rows = array_values(array_filter(
                self::$prescriptionRows,
                static function (array $row) use ($pid, $linkedPrescriptionIds): bool {
                    $recordId = (int) ($row['prescription_record_id'] ?? 0);
                    $endDate = (string) ($row['prescription_end_date'] ?? '');
                    return (int) ($row['patient_id'] ?? 0) === $pid
                        && (string) ($row['prescription_active'] ?? '') === '1'
                        && ($endDate === '' || $endDate === '0000-00-00' || $endDate >= date('Y-m-d'))
                        && !isset($linkedPrescriptionIds[$recordId]);
                }
            ));

            return array_slice($rows, 0, $limit);
        }
    }
}

namespace OpenEMR\Tests\Isolated\Services\Agent\Evidence {
    use OpenEMR\Services\Agent\Evidence\EvidenceCaps;
    use OpenEMR\Services\Agent\Evidence\SqlEvidenceRecordRepository;
    use OpenEMR\Services\Agent\Evidence\SqlEvidenceRecordRepositorySqlFixture;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    #[Group('isolated')]
    #[Group('agent')]
    class SqlEvidenceRecordRepositoryTest extends TestCase
    {
        protected function setUp(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::reset();
        }

        public function testFetchBasicPatientDataReturnsRicherPatientSnapshotWithoutHighRiskIdentifiers(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$patientRows[123] = $this->patientRow([
                'pubpid' => 'MRN-12345',
                'fname' => 'Jane',
                'mname' => 'Quinn',
                'lname' => 'Doe',
                'preferred_name' => 'Janie',
                'DOB' => '1974-04-15',
                'sex' => 'Female',
                'sex_identified' => 'Female',
                'gender_identity' => 'woman',
                'language' => 'English',
                'race' => 'White',
                'ethnicity' => 'Not Hispanic or Latino',
                'street' => '123 Main St',
                'city' => 'Boston',
                'state' => 'MA',
                'postal_code' => '02111',
                'phone_cell' => '555-111-2222',
                'email' => 'jane.doe@example.test',
                'last_updated' => '2026-04-29 12:34:56',
                'ss' => '123-45-6789',
                'drivers_license' => 'D1234567',
            ]);

            $records = (new SqlEvidenceRecordRepository())->fetchBasicPatientData(123, new EvidenceCaps(10, 0, 0));

            $this->assertCount(1, $records);
            $this->assertSame('demographics:patient_data:123', $records[0]['source_id']);
            $this->assertStringContainsString('Name: Jane Quinn Doe', $records[0]['display']);
            $this->assertStringContainsString('Preferred name: Janie', $records[0]['display']);
            $this->assertStringContainsString('Date of birth: 1974-04-15', $records[0]['display']);
            $this->assertStringContainsString('Sex at birth: Female', $records[0]['display']);
            $this->assertStringContainsString('Address: 123 Main St, Boston MA 02111', $records[0]['display']);
            $this->assertStringContainsString('Mobile phone:', $records[0]['display']);
            $this->assertStringContainsString('Email: jane.doe@example.test', $records[0]['display']);
            $this->assertStringNotContainsString('public patient id', $records[0]['display']);
            $this->assertStringNotContainsString('MRN-12345', $records[0]['display']);
            $this->assertStringNotContainsString('last updated', $records[0]['display']);
            $this->assertStringNotContainsString('2026-04-29', $records[0]['display']);
            $this->assertStringNotContainsString('123-45-6789', $records[0]['display']);
            $this->assertStringNotContainsString('D1234567', $records[0]['display']);
            $this->assertNotContains('pubpid', $records[0]['fields_used']);
            $this->assertNotContains('last_updated', $records[0]['fields_used']);
            $this->assertNotContains('ss', $records[0]['fields_used']);
            $this->assertNotContains('drivers_license', $records[0]['fields_used']);
            $this->assertStringNotContainsString('pubpid', SqlEvidenceRecordRepositorySqlFixture::$queries[0]['sql']);
            $this->assertStringNotContainsString('last_updated', SqlEvidenceRecordRepositorySqlFixture::$queries[0]['sql']);
            $this->assertSame(0, preg_match('/\bss\b/i', SqlEvidenceRecordRepositorySqlFixture::$queries[0]['sql']));
            $this->assertStringNotContainsString('drivers_license', SqlEvidenceRecordRepositorySqlFixture::$queries[0]['sql']);
        }

        public function testStructuredAddressAndTelecomRowsArePatientScopedAndDeduplicated(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$patientRows[123] = $this->patientRow([
                'street' => '123 Main St',
                'city' => 'Boston',
                'state' => 'MA',
                'postal_code' => '02111',
                'phone_cell' => '555-111-2222',
            ]);
            SqlEvidenceRecordRepositorySqlFixture::$addressRows = [
                $this->addressRow(501, 123, ['line1' => '123 Main St', 'city' => 'Boston', 'state' => 'MA', 'zip' => '02111']),
                $this->addressRow(502, 123, ['line1' => '456 Oak Ave', 'city' => 'Cambridge', 'state' => 'MA', 'zip' => '02139']),
                $this->addressRow(503, 999, ['line1' => '999 Other Patient Rd']),
            ];
            SqlEvidenceRecordRepositorySqlFixture::$telecomRows = [
                $this->telecomRow(601, 123, ['value' => '555-111-2222']),
                $this->telecomRow(602, 123, ['value' => '555-333-4444']),
                $this->telecomRow(603, 999, ['value' => '555-999-0000']),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchBasicPatientData(123, new EvidenceCaps(10, 0, 0));
            $sourceIds = array_column($records, 'source_id');
            $sql = implode("\n", array_column(SqlEvidenceRecordRepositorySqlFixture::$queries, 'sql'));

            $this->assertContains('demographics:addresses:502', $sourceIds);
            $this->assertContains('demographics:contact_telecom:602', $sourceIds);
            $this->assertNotContains('demographics:addresses:501', $sourceIds);
            $this->assertNotContains('demographics:contact_telecom:601', $sourceIds);
            $this->assertNotContains('demographics:addresses:503', $sourceIds);
            $this->assertNotContains('demographics:contact_telecom:603', $sourceIds);
            $this->assertStringContainsString("c.foreign_table_name = 'patient_data'", $sql);
            $this->assertStringNotContainsString('phone_numbers', $sql);
        }

        public function testEmployerRowsArePatientScopedAndDrilldownableWhenTheyContainVisibleData(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$patientRows[123] = $this->patientRow();
            SqlEvidenceRecordRepositorySqlFixture::$employerRows = [
                $this->employerRow(803, 123, ['date' => '2026-02-01 00:00:00']),
                $this->employerRow(801, 123, [
                    'name' => 'Acme Health',
                    'street' => '99 Work Rd',
                    'city' => 'Boston',
                    'state' => 'MA',
                    'postal_code' => '02110',
                    'occupation' => '15-1252.00',
                    'occupation_title' => 'Software Developers',
                    'industry' => '541511',
                    'industry_title' => 'Custom Computer Programming Services',
                    'start_date' => '2020-01-01 00:00:00',
                ]),
                $this->employerRow(802, 999, ['name' => 'Other Patient Employer']),
            ];
            $repository = new SqlEvidenceRecordRepository();

            $records = $repository->fetchBasicPatientData(123, new EvidenceCaps(10, 0, 0));
            $sourceIds = array_column($records, 'source_id');
            $employer = $repository->fetchSourceRecord(123, 'demographics:employer_data:801', new EvidenceCaps(10, 0, 0));

            $this->assertContains('demographics:employer_data:801', $sourceIds);
            $this->assertNotContains('demographics:employer_data:803', $sourceIds);
            $this->assertNotContains('demographics:employer_data:802', $sourceIds);
            $this->assertSame('demographics:employer_data:801', $employer['source_id'] ?? null);
            $this->assertStringContainsString('Employer: Acme Health', $employer['display'] ?? '');
            $this->assertStringContainsString('Employer address: 99 Work Rd, Boston MA 02110', $employer['display'] ?? '');
            $this->assertStringContainsString('Occupation: Software Developers', $employer['display'] ?? '');
            $this->assertStringContainsString('Industry: Custom Computer Programming Services', $employer['display'] ?? '');
            $this->assertNull($repository->fetchSourceRecord(999, 'demographics:employer_data:801', new EvidenceCaps(10, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(123, 'demographics:contact:701', new EvidenceCaps(10, 0, 0)));
        }

        public function testEmptyEmployerRowsDoNotEmitFallbackClaims(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$patientRows[123] = $this->patientRow();
            SqlEvidenceRecordRepositorySqlFixture::$employerRows = [
                $this->employerRow(801, 123),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchBasicPatientData(123, new EvidenceCaps(10, 0, 0));
            $displays = implode("\n", array_column($records, 'display'));

            $this->assertNotContains('demographics:employer_data:801', array_column($records, 'source_id'));
            $this->assertStringNotContainsString('Patient employer record', $displays);
            $this->assertNull((new SqlEvidenceRecordRepository())->fetchSourceRecord(123, 'demographics:employer_data:801', new EvidenceCaps(10, 0, 0)));
        }

        public function testBasicPatientDataCapsChildSources(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$patientRows[123] = $this->patientRow();
            SqlEvidenceRecordRepositorySqlFixture::$addressRows = [
                $this->addressRow(501, 123, ['line1' => '456 Oak Ave']),
                $this->addressRow(502, 123, ['line1' => '789 Pine St']),
            ];
            SqlEvidenceRecordRepositorySqlFixture::$telecomRows = [
                $this->telecomRow(601, 123, ['value' => '555-333-4444']),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchBasicPatientData(123, new EvidenceCaps(2, 0, 0));

            $this->assertSame([
                'demographics:patient_data:123',
                'demographics:addresses:501',
            ], array_column($records, 'source_id'));
        }

        public function testSourceDrilldownRequiresCurrentPatientOwnershipForNewSources(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$addressRows = [
                $this->addressRow(502, 123, ['line1' => '456 Oak Ave']),
            ];
            SqlEvidenceRecordRepositorySqlFixture::$telecomRows = [
                $this->telecomRow(602, 123, ['value' => '555-333-4444']),
            ];
            $repository = new SqlEvidenceRecordRepository();

            $address = $repository->fetchSourceRecord(123, 'demographics:addresses:502', new EvidenceCaps(10, 0, 0));
            $telecom = $repository->fetchSourceRecord(123, 'demographics:contact_telecom:602', new EvidenceCaps(10, 0, 0));

            $this->assertSame('demographics:addresses:502', $address['source_id'] ?? null);
            $this->assertSame('demographics:contact_telecom:602', $telecom['source_id'] ?? null);
            $this->assertNull($repository->fetchSourceRecord(999, 'demographics:addresses:502', new EvidenceCaps(10, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(999, 'demographics:contact_telecom:602', new EvidenceCaps(10, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(123, 'demographics:phone_numbers:602', new EvidenceCaps(10, 0, 0)));
        }

        public function testCurrentMedicationsIncludeExpandedListMedicationAndLinkedPrescriptionFields(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$medicationRows = [
                $this->medicationRow(77, 123, [
                    'title' => 'Metformin',
                    'drug_dosage_instructions' => 'Take 500 mg by mouth twice daily',
                    'usage_category' => 'outpatient',
                    'usage_category_title' => 'Outpatient medication',
                    'request_intent' => 'order',
                    'request_intent_title' => 'Order',
                    'medication_adherence' => 'taking',
                    'medication_adherence_information_source' => 'patient',
                    'medication_adherence_date_asserted' => '2026-04-29 09:00:00',
                    'is_primary_record' => 0,
                    'reporting_source_record_id' => 321,
                    'linked_prescription_id' => 9001,
                    'prescription_record_id' => 9001,
                    'prescription_drug' => 'Metformin 500 mg tablet',
                    'prescription_rxnorm_drugcode' => '860975',
                    'prescription_route' => 'oral',
                    'prescription_refills' => 3,
                    'prescription_erx_source' => 1,
                ]),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchCurrentMedications(123, new EvidenceCaps(25, 0, 365));
            $sql = implode("\n", array_column(SqlEvidenceRecordRepositorySqlFixture::$queries, 'sql'));

            $this->assertCount(1, $records);
            $this->assertSame('medication:lists_medication:77', $records[0]['source_id']);
            $this->assertStringContainsString('medication: Metformin', $records[0]['display']);
            $this->assertStringContainsString('usage category: Outpatient medication (outpatient)', $records[0]['display']);
            $this->assertStringContainsString('request intent: Order (order)', $records[0]['display']);
            $this->assertStringContainsString('adherence information source: patient', $records[0]['display']);
            $this->assertStringContainsString('record type: reported/non-primary medication', $records[0]['display']);
            $this->assertStringContainsString('linked prescription drug: Metformin 500 mg tablet', $records[0]['display']);
            $this->assertStringContainsString('rxnorm: 860975', $records[0]['display']);
            $this->assertStringContainsString('prescription eRx source: external/eRx', $records[0]['display']);
            $this->assertContains('usage_category', $records[0]['fields_used']);
            $this->assertContains('request_intent', $records[0]['fields_used']);
            $this->assertContains('medication_adherence_information_source', $records[0]['fields_used']);
            $this->assertContains('is_primary_record', $records[0]['fields_used']);
            $this->assertContains('reporting_source_record_id', $records[0]['fields_used']);
            $this->assertStringContainsString('lm.usage_category', $sql);
            $this->assertStringContainsString('lm.medication_adherence_information_source', $sql);
            $this->assertStringContainsString('p.patient_id = ?', $sql);
            $this->assertStringNotContainsString('drug_info_erx', $sql);
        }

        public function testStandaloneCurrentPrescriptionsAreIncludedWithoutEndedOrLinkedDuplicates(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$medicationRows = [
                $this->medicationRow(77, 123, [
                    'linked_prescription_id' => 9001,
                    'prescription_record_id' => 9001,
                ]),
            ];
            SqlEvidenceRecordRepositorySqlFixture::$prescriptionRows = [
                $this->prescriptionRow(9001, 123, ['prescription_drug' => 'Linked Metformin']),
                $this->prescriptionRow(9002, 123, [
                    'prescription_drug' => 'Atorvastatin',
                    'prescription_drug_dosage_instructions' => 'Take 20 mg nightly',
                    'prescription_end_date' => null,
                ]),
                $this->prescriptionRow(9003, 123, [
                    'prescription_drug' => 'Ended Drug',
                    'prescription_end_date' => '2020-01-01',
                ]),
                $this->prescriptionRow(9004, 999, ['prescription_drug' => 'Other Patient Drug']),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchCurrentMedications(123, new EvidenceCaps(25, 0, 365));
            $sourceIds = array_column($records, 'source_id');

            $this->assertContains('medication:lists_medication:77', $sourceIds);
            $this->assertContains('medication:prescriptions:9002', $sourceIds);
            $this->assertNotContains('medication:prescriptions:9001', $sourceIds);
            $this->assertNotContains('medication:prescriptions:9003', $sourceIds);
            $this->assertNotContains('medication:prescriptions:9004', $sourceIds);
        }

        public function testMedicationReviewMarkerIsIncludedWhenNoCurrentMedicationRecordsExist(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$listsTouchRows = [
                [
                    'patient_id' => 123,
                    'type' => 'medication',
                    'date' => '2026-04-30 11:22:33',
                ],
                [
                    'patient_id' => 999,
                    'type' => 'medication',
                    'date' => '2026-04-30 11:22:33',
                ],
            ];

            $repository = new SqlEvidenceRecordRepository();
            $records = $repository->fetchCurrentMedications(123, new EvidenceCaps(25, 0, 365));
            $review = $repository->fetchSourceRecord(123, 'medication:lists_touch:123', new EvidenceCaps(1, 0, 0));

            $this->assertCount(1, $records);
            $this->assertSame('medication:lists_touch:123', $records[0]['source_id']);
            $this->assertSame('medication_review', $records[0]['source_type']);
            $this->assertStringContainsString('reviewed/touched on 2026-04-30 11:22:33', $records[0]['display']);
            $this->assertSame('medication:lists_touch:123', $review['source_id'] ?? null);
            $this->assertNull($repository->fetchSourceRecord(999, 'medication:lists_touch:123', new EvidenceCaps(1, 0, 0)));
        }

        public function testPrescriptionSourceDrilldownRequiresCurrentPatientOwnership(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$prescriptionRows = [
                $this->prescriptionRow(9002, 123, ['prescription_drug' => 'Atorvastatin']),
            ];
            $repository = new SqlEvidenceRecordRepository();

            $source = $repository->fetchSourceRecord(123, 'medication:prescriptions:9002', new EvidenceCaps(1, 0, 0));

            $this->assertSame('medication:prescriptions:9002', $source['source_id'] ?? null);
            $this->assertStringContainsString('prescription drug: Atorvastatin', $source['display'] ?? '');
            $this->assertNull($repository->fetchSourceRecord(999, 'medication:prescriptions:9002', new EvidenceCaps(1, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(123, 'demographics:prescriptions:9002', new EvidenceCaps(1, 0, 0)));
        }

        public function testAllergiesToConfirmIncludesExpandedListFieldsAndSafeCodedLookups(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$allergyRows = [
                $this->allergyRow(88, 123, [
                    'title' => 'Penicillin allergy',
                    'list_option_id' => 'penicillin',
                    'coded_allergen_title' => 'Penicillin',
                    'coded_allergen_codes' => 'RxNorm:7980',
                    'reaction' => 'hives',
                    'reaction_title' => 'Hives',
                    'severity_al' => 'mild',
                    'severity_title' => 'Mild',
                    'severity_codes' => 'SNOMED-CT:255604002',
                    'verification' => 'confirmed',
                    'verification_title' => 'Confirmed',
                    'external_allergyid' => 55501,
                    'list_external_id' => 'ext-allergy-88',
                    'list_erx_source' => '1',
                    'list_erx_uploaded' => '1',
                    'subtype' => 'drug',
                    'list_diagnosis' => 'Z88.0',
                    'comments' => str_repeat('Comment ', 80),
                ]),
                $this->allergyRow(89, 999, ['title' => 'Other patient allergy']),
            ];

            $records = (new SqlEvidenceRecordRepository())->fetchAllergiesToConfirm(123, new EvidenceCaps(25, 0, 365));
            $sql = implode("\n", array_column(SqlEvidenceRecordRepositorySqlFixture::$queries, 'sql'));

            $this->assertCount(1, $records);
            $this->assertSame('allergy:lists:88', $records[0]['source_id']);
            $this->assertSame('allergies', $records[0]['data_class']);
            $this->assertStringContainsString('Allergen: Penicillin allergy', $records[0]['display']);
            $this->assertStringContainsString('Coded allergen: Penicillin', $records[0]['display']);
            $this->assertStringContainsString('Coded allergen codes: RxNorm:7980', $records[0]['display']);
            $this->assertStringContainsString('Reaction: Hives', $records[0]['display']);
            $this->assertStringContainsString('Severity: Mild', $records[0]['display']);
            $this->assertStringContainsString('Severity codes: SNOMED-CT:255604002', $records[0]['display']);
            $this->assertStringContainsString('Verification status: Confirmed', $records[0]['display']);
            $this->assertStringContainsString('Current status: current', $records[0]['display']);
            $this->assertStringContainsString('Subtype: drug', $records[0]['display']);
            $this->assertStringContainsString('Diagnosis: Z88.0', $records[0]['display']);
            $this->assertStringContainsString('Allergy eRx source: external/eRx', $records[0]['display']);
            $this->assertStringContainsString('Allergy eRx uploaded: yes', $records[0]['display']);
            $this->assertStringContainsString('External allergy id: 55501', $records[0]['display']);
            $this->assertStringContainsString('External list id: ext-allergy-88', $records[0]['display']);
            $this->assertContains('list_option_id', $records[0]['fields_used']);
            $this->assertContains('external_allergyid', $records[0]['fields_used']);
            $this->assertContains('external_id', $records[0]['fields_used']);
            $this->assertContains('erx_source', $records[0]['fields_used']);
            $this->assertContains('erx_uploaded', $records[0]['fields_used']);
            $this->assertContains('subtype', $records[0]['fields_used']);
            $this->assertContains('diagnosis', $records[0]['fields_used']);
            $this->assertLessThanOrEqual(280, strlen($records[0]['excerpt']));
            $this->assertStringContainsString("coded_allergen.list_id = 'allergy_issue_list'", $sql);
            $this->assertStringContainsString("severity.list_id = 'severity_ccda'", $sql);
            $this->assertStringNotContainsString('prescriptions', $sql);
        }

        public function testAllergyReviewMarkerIsIncludedWhenNoCurrentAllergyRecordsExist(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$listsTouchRows = [
                [
                    'patient_id' => 123,
                    'type' => 'allergy',
                    'date' => '2026-04-30 11:22:33',
                ],
                [
                    'patient_id' => 999,
                    'type' => 'allergy',
                    'date' => '2026-04-30 11:22:33',
                ],
                [
                    'patient_id' => 123,
                    'type' => 'medical_problem',
                    'date' => '2026-04-29 10:00:00',
                ],
            ];

            $repository = new SqlEvidenceRecordRepository();
            $records = $repository->fetchAllergiesToConfirm(123, new EvidenceCaps(25, 0, 365));
            $review = $repository->fetchSourceRecord(123, 'allergy:lists_touch:123', new EvidenceCaps(1, 0, 0));
            $sql = implode("\n", array_column(SqlEvidenceRecordRepositorySqlFixture::$queries, 'sql'));

            $this->assertCount(1, $records);
            $this->assertSame('allergy:lists_touch:123', $records[0]['source_id']);
            $this->assertSame('allergy_review', $records[0]['source_type']);
            $this->assertSame('allergies', $records[0]['data_class']);
            $this->assertStringContainsString('reviewed/touched on 2026-04-30 11:22:33', $records[0]['display']);
            $this->assertStringContainsString('ORDER BY date DESC', $sql);
            $this->assertSame('allergy:lists_touch:123', $review['source_id'] ?? null);
            $this->assertNull($repository->fetchSourceRecord(999, 'allergy:lists_touch:123', new EvidenceCaps(1, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(123, 'medication:lists_touch:123', new EvidenceCaps(1, 0, 0)));
        }

        public function testAllergySourceDrilldownRequiresCurrentPatientOwnership(): void
        {
            SqlEvidenceRecordRepositorySqlFixture::$allergyRows = [
                $this->allergyRow(88, 123, [
                    'title' => 'Sulfa allergy',
                    'list_option_id' => 'sulfa',
                    'coded_allergen_title' => 'Sulfa',
                    'severity_al' => 'severe',
                    'severity_title' => 'Severe',
                ]),
            ];
            $repository = new SqlEvidenceRecordRepository();

            $source = $repository->fetchSourceRecord(123, 'allergy:lists:88', new EvidenceCaps(1, 0, 0));

            $this->assertSame('allergy:lists:88', $source['source_id'] ?? null);
            $this->assertStringContainsString('Coded allergen: Sulfa', $source['display'] ?? '');
            $this->assertStringContainsString('Severity: Severe', $source['display'] ?? '');
            $this->assertNull($repository->fetchSourceRecord(999, 'allergy:lists:88', new EvidenceCaps(1, 0, 0)));
            $this->assertNull($repository->fetchSourceRecord(123, 'medication:lists:88', new EvidenceCaps(1, 0, 0)));
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function patientRow(array $overrides = []): array
        {
            return $overrides + [
                'pid' => 123,
                'uuid' => null,
                'pubpid' => '',
                'title' => '',
                'fname' => '',
                'mname' => '',
                'lname' => '',
                'suffix' => '',
                'preferred_name' => '',
                'birth_fname' => '',
                'birth_mname' => '',
                'birth_lname' => '',
                'DOB' => null,
                'sex' => '',
                'sex_identified' => '',
                'gender_identity' => '',
                'sexual_orientation' => '',
                'pronoun' => '',
                'status' => 'single',
                'deceased_date' => null,
                'deceased_reason' => '',
                'language' => '',
                'interpreter' => '',
                'interpreter_needed' => '',
                'race' => '',
                'ethnicity' => '',
                'ethnoracial' => '',
                'religion' => '',
                'nationality_country' => '',
                'tribal_affiliations' => '',
                'street' => '',
                'street_line_2' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
                'county' => '',
                'country_code' => '',
                'phone_home' => '',
                'phone_biz' => '',
                'phone_contact' => '',
                'phone_cell' => '',
                'email' => '',
                'email_direct' => '',
                'contact_relationship' => '',
                'date' => '2026-04-20 10:00:00',
                'regdate' => null,
                'last_updated' => null,
                'providerID' => null,
                'ref_providerID' => null,
                'referrer' => '',
                'referrerID' => '',
                'pharmacy_id' => 0,
                'allow_patient_portal' => '',
                'care_team_provider' => '',
                'care_team_facility' => '',
                'care_team_status' => '',
                'provider_since_date' => null,
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function addressRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'address_id' => $id,
                'patient_id' => $pid,
                'line1' => '',
                'line2' => '',
                'city' => '',
                'state' => '',
                'zip' => '',
                'plus_four' => '',
                'country' => '',
                'district' => '',
                'contact_address_id' => $id + 1000,
                'priority' => 1,
                'address_type' => 'both',
                'address_type_title' => 'Postal & Physical',
                'address_use' => 'home',
                'address_use_title' => 'Home',
                'address_status' => 'A',
                'is_primary' => 'Y',
                'period_start' => '2026-01-01 00:00:00',
                'period_end' => null,
                'created_date' => '2026-01-01 00:00:00',
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function telecomRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'contact_telecom_id' => $id,
                'contact_id' => 42,
                'patient_id' => $pid,
                'rank' => 1,
                'system' => 'phone',
                'telecom_system_title' => 'Phone',
                'telecom_use' => 'mobile',
                'telecom_use_title' => 'Mobile',
                'value' => '',
                'telecom_status' => 'A',
                'is_primary' => 'Y',
                'period_start' => '2026-01-01 00:00:00',
                'period_end' => null,
                'created_date' => '2026-01-01 00:00:00',
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function employerRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'employer_data_id' => $id,
                'employer_uuid' => null,
                'name' => '',
                'street' => '',
                'street_line_2' => '',
                'postal_code' => '',
                'city' => '',
                'state' => '',
                'country' => '',
                'date' => '2026-01-01 00:00:00',
                'patient_id' => $pid,
                'start_date' => null,
                'end_date' => null,
                'occupation' => '',
                'occupation_title' => '',
                'industry' => '',
                'industry_title' => '',
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function medicationRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'list_id' => 700 + $id,
                'list_uuid' => null,
                'patient_id' => $pid,
                'date' => '2026-04-20 10:00:00',
                'begdate' => '2026-04-01 00:00:00',
                'enddate' => null,
                'subtype' => '',
                'title' => 'Medication ' . $id,
                'list_diagnosis' => '',
                'list_external_id' => '',
                'list_option_id' => '',
                'list_erx_source' => '0',
                'list_erx_uploaded' => '0',
                'activity' => 1,
                'comments' => '',
                'modifydate' => '2026-04-28 10:00:00',
                'medication_issue_id' => $id,
                'medication_list_id' => 700 + $id,
                'drug_dosage_instructions' => '',
                'usage_category' => '',
                'usage_category_title' => '',
                'request_intent' => '',
                'request_intent_title' => '',
                'medication_adherence_information_source' => '',
                'medication_adherence' => '',
                'medication_adherence_date_asserted' => null,
                'linked_prescription_id' => null,
                'is_primary_record' => 1,
                'reporting_source_record_id' => null,
                'prescription_record_id' => null,
                'prescription_uuid' => null,
                'prescription_patient_id' => null,
                'prescription_encounter' => null,
                'prescription_provider_id' => null,
                'prescription_filled_by_id' => null,
                'prescription_pharmacy_id' => null,
                'prescription_drug' => '',
                'prescription_drug_id' => 0,
                'prescription_rxnorm_drugcode' => '',
                'prescription_medication' => null,
                'prescription_date_added' => null,
                'prescription_date_modified' => null,
                'prescription_start_date' => null,
                'prescription_end_date' => null,
                'prescription_filled_date' => null,
                'prescription_datetime' => null,
                'prescription_active' => null,
                'prescription_txDate' => null,
                'prescription_drug_dosage_instructions' => '',
                'prescription_dosage' => '',
                'prescription_quantity' => '',
                'prescription_size' => '',
                'prescription_unit' => '',
                'prescription_route' => '',
                'prescription_interval' => '',
                'prescription_form' => '',
                'prescription_substitute' => '',
                'prescription_refills' => '',
                'prescription_per_refill' => '',
                'prescription_prn' => '',
                'prescription_note' => '',
                'prescription_usage_category' => '',
                'prescription_usage_category_title' => '',
                'prescription_request_intent' => '',
                'prescription_request_intent_title' => '',
                'prescription_indication' => '',
                'prescription_diagnosis' => '',
                'prescription_erx_source' => '',
                'prescription_erx_uploaded' => '',
                'prescription_external_id' => '',
                'prescription_guid' => '',
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function allergyRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'id' => $id,
                'uuid' => null,
                'patient_id' => $pid,
                'pid' => $pid,
                'type' => 'allergy',
                'date' => '2026-04-20 10:00:00',
                'begdate' => '2026-04-01 00:00:00',
                'enddate' => null,
                'title' => 'Allergy ' . $id,
                'list_option_id' => '',
                'coded_allergen_title' => '',
                'coded_allergen_codes' => '',
                'external_allergyid' => null,
                'list_external_id' => '',
                'external_id' => '',
                'list_erx_source' => '0',
                'erx_source' => '0',
                'list_erx_uploaded' => '0',
                'erx_uploaded' => '0',
                'subtype' => '',
                'list_diagnosis' => '',
                'diagnosis' => '',
                'activity' => 1,
                'comments' => '',
                'reaction' => '',
                'reaction_title' => '',
                'verification' => '',
                'verification_title' => '',
                'severity_al' => '',
                'severity_title' => '',
                'severity_codes' => '',
                'modifydate' => '2026-04-28 10:00:00',
            ];
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function prescriptionRow(int $id, int $pid, array $overrides = []): array
        {
            return $overrides + [
                'patient_id' => $pid,
                'prescription_record_id' => $id,
                'prescription_uuid' => null,
                'prescription_patient_id' => $pid,
                'prescription_encounter' => null,
                'prescription_provider_id' => null,
                'prescription_filled_by_id' => null,
                'prescription_pharmacy_id' => null,
                'prescription_drug' => 'Prescription ' . $id,
                'prescription_drug_id' => 0,
                'prescription_rxnorm_drugcode' => '',
                'prescription_medication' => null,
                'prescription_date_added' => '2026-04-01 00:00:00',
                'prescription_date_modified' => '2026-04-29 00:00:00',
                'prescription_start_date' => '2026-04-01',
                'prescription_end_date' => null,
                'prescription_filled_date' => null,
                'prescription_datetime' => null,
                'prescription_active' => 1,
                'prescription_txDate' => '2026-04-01',
                'prescription_drug_dosage_instructions' => '',
                'prescription_dosage' => '',
                'prescription_quantity' => '',
                'prescription_size' => '',
                'prescription_unit' => '',
                'prescription_route' => '',
                'prescription_interval' => '',
                'prescription_form' => '',
                'prescription_substitute' => '',
                'prescription_refills' => '',
                'prescription_per_refill' => '',
                'prescription_prn' => '',
                'prescription_note' => '',
                'prescription_usage_category' => '',
                'prescription_usage_category_title' => '',
                'prescription_request_intent' => '',
                'prescription_request_intent_title' => '',
                'prescription_indication' => '',
                'prescription_diagnosis' => '',
                'prescription_erx_source' => '0',
                'prescription_erx_uploaded' => '0',
                'prescription_external_id' => '',
                'prescription_guid' => '',
            ];
        }
    }
}
