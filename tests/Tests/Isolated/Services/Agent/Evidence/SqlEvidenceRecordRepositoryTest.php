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
            $recordId = (int) ($params[1] ?? 0);
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
            $this->assertStringContainsString('name: Jane Quinn Doe', $records[0]['display']);
            $this->assertStringContainsString('preferred name: Janie', $records[0]['display']);
            $this->assertStringContainsString('date of birth: 1974-04-15', $records[0]['display']);
            $this->assertStringContainsString('sex at birth: Female', $records[0]['display']);
            $this->assertStringContainsString('address: 123 Main St, Boston MA 02111', $records[0]['display']);
            $this->assertStringContainsString('mobile phone:', $records[0]['display']);
            $this->assertStringContainsString('email: jane.doe@example.test', $records[0]['display']);
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
    }
}
