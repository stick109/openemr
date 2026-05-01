<?php

/**
 * SqlEvidenceRecordRepository
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Evidence;

use DateTimeImmutable;
use OpenEMR\Common\Uuid\UuidRegistry;
use Throwable;

final class SqlEvidenceRecordRepository implements EvidenceRecordRepositoryInterface
{
    private const BASIC_PATIENT_ADDRESS_LIMIT = 3;
    private const BASIC_PATIENT_TELECOM_LIMIT = 5;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        $limit = $caps->clampRecords();
        if ($limit <= 0) {
            return [];
        }

        $row = sqlQuery(
            "SELECT
                pid,
                uuid,
                title,
                fname,
                mname,
                lname,
                suffix,
                preferred_name,
                birth_fname,
                birth_mname,
                birth_lname,
                DOB,
                sex,
                sex_identified,
                gender_identity,
                sexual_orientation,
                pronoun,
                status,
                deceased_date,
                deceased_reason,
                language,
                interpreter,
                interpreter_needed,
                race,
                ethnicity,
                ethnoracial,
                religion,
                nationality_country,
                tribal_affiliations,
                street,
                street_line_2,
                city,
                state,
                postal_code,
                county,
                country_code,
                phone_home,
                phone_biz,
                phone_contact,
                phone_cell,
                email,
                email_direct,
                contact_relationship,
                date,
                regdate,
                providerID,
                ref_providerID,
                referrer,
                referrerID,
                pharmacy_id,
                allow_patient_portal,
                care_team_provider,
                care_team_facility,
                care_team_status,
                provider_since_date
             FROM patient_data
             WHERE pid = ?
             LIMIT 1",
            [$pid]
        );

        if (!is_array($row) || $row === []) {
            return [];
        }

        $records = [$this->mapPatientRecord($row)];
        $remaining = $limit - 1;
        if ($remaining <= 0) {
            return $records;
        }

        $patientAddressKey = $this->addressKeyFromPatientRow($row);
        $addressLimit = min(self::BASIC_PATIENT_ADDRESS_LIMIT, $remaining);
        foreach ($this->fetchPatientContactAddressRows($pid, $addressLimit) as $addressRow) {
            $addressKey = $this->addressKeyFromAddressRow($addressRow);
            if ($addressKey === '' || ($patientAddressKey !== '' && $addressKey === $patientAddressKey)) {
                continue;
            }

            $records[] = $this->mapAddressRecord($addressRow);
            $remaining--;
            if ($remaining <= 0) {
                return $records;
            }
        }

        $patientContactKeys = $this->contactKeysFromPatientRow($row);
        $telecomLimit = min(self::BASIC_PATIENT_TELECOM_LIMIT, $remaining);
        foreach ($this->fetchPatientContactTelecomRows($pid, $telecomLimit) as $telecomRow) {
            $contactKey = $this->contactKeyFromTelecomRow($telecomRow);
            if ($contactKey === '' || isset($patientContactKeys[$contactKey])) {
                continue;
            }

            $records[] = $this->mapTelecomRecord($telecomRow);
            $remaining--;
            if ($remaining <= 0) {
                break;
            }
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCurrentMedications(int $pid, EvidenceCaps $caps): array
    {
        $limit = $caps->clampRecords();
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->fetchRows(
            "SELECT
                l.id AS list_id,
                l.uuid AS list_uuid,
                l.pid AS patient_id,
                l.date,
                l.begdate,
                l.enddate,
                l.title,
                l.activity,
                l.comments,
                l.modifydate,
                lm.id AS medication_issue_id,
                lm.drug_dosage_instructions,
                lm.usage_category_title,
                lm.request_intent_title,
                lm.medication_adherence,
                lm.medication_adherence_date_asserted,
                lm.prescription_id
             FROM lists l
             LEFT JOIN lists_medication lm ON lm.list_id = l.id
             WHERE l.pid = ?
                AND l.type = 'medication'
                AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate >= CURDATE())
             ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC
             LIMIT " . $limit,
            [$pid]
        );

        return array_map(fn (array $row): array => $this->mapMedicationRecord($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllergiesToConfirm(int $pid, EvidenceCaps $caps): array
    {
        $limit = $caps->clampRecords();
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->fetchRows(
            "SELECT
                l.id,
                l.uuid,
                l.pid AS patient_id,
                l.date,
                l.begdate,
                l.enddate,
                l.title,
                l.activity,
                l.comments,
                l.reaction,
                l.verification,
                l.severity_al,
                l.modifydate,
                reaction.title AS reaction_title,
                verification.title AS verification_title
             FROM lists l
             LEFT JOIN list_options reaction
                ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction'
             LEFT JOIN list_options verification
                ON verification.option_id = l.verification
                    AND verification.list_id = 'allergyintolerance-verification'
             WHERE l.pid = ?
                AND l.type = 'allergy'
                AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate >= CURDATE())
             ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC
             LIMIT " . $limit,
            [$pid]
        );

        return array_map(fn (array $row): array => $this->mapAllergyRecord($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRecentEvents(int $pid, EvidenceCaps $caps): array
    {
        $maxRecords = $caps->clampRecords();
        $maxDocuments = $caps->clampDocuments();
        if ($maxRecords <= 0) {
            return [];
        }

        $since = $caps->getLookbackStart(new DateTimeImmutable());
        $sinceDateTime = $since?->format('Y-m-d H:i:s') ?? '1000-01-01 00:00:00';

        $encounterRows = $this->fetchRows(
            "SELECT id, uuid, pid AS patient_id, date, reason, facility, encounter, class_code, last_update
             FROM form_encounter
             WHERE pid = ? AND date >= ?
             ORDER BY date DESC, id DESC
             LIMIT " . $maxRecords,
            [$pid, $sinceDateTime]
        );

        $records = array_map(fn (array $row): array => $this->mapEncounterRecord($row), $encounterRows);

        if ($maxDocuments > 0) {
            $documentRows = $this->fetchRows(
                "SELECT id, uuid, foreign_id AS patient_id, docdate, date, name, mimetype, hash, encounter_id
                 FROM documents
                 WHERE foreign_id = ? AND deleted = 0 AND COALESCE(docdate, date) >= ?
                 ORDER BY COALESCE(docdate, date) DESC, id DESC
                 LIMIT " . $maxDocuments,
                [$pid, $sinceDateTime]
            );

            foreach ($documentRows as $row) {
                $records[] = $this->mapDocumentRecord($row);
            }
        }

        usort(
            $records,
            static fn (array $left, array $right): int => strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? ''))
        );

        return array_slice($records, 0, $maxRecords);
    }

    /**
     * @param list<string> $grantedDataClasses
     * @return list<array<string, mixed>>
     */
    public function fetchChangedSinceLastVisit(int $pid, EvidenceCaps $caps, array $grantedDataClasses): array
    {
        $maxRecords = $caps->clampRecords();
        if ($maxRecords <= 0) {
            return [];
        }

        $sinceDateTime = $this->changedSinceBaseline($pid, $caps);
        $records = [];

        if (in_array('recent_events', $grantedDataClasses, true)) {
            $encounterRows = $this->fetchRows(
                "SELECT id, uuid, pid AS patient_id, date, reason, facility, encounter, class_code, last_update
                 FROM form_encounter
                 WHERE pid = ? AND date > ?
                 ORDER BY date DESC, id DESC
                 LIMIT " . $maxRecords,
                [$pid, $sinceDateTime]
            );
            foreach ($encounterRows as $row) {
                $records[] = $this->mapEncounterRecord($row);
            }

            $documentLimit = $caps->clampDocuments();
            if ($documentLimit > 0) {
                $documentRows = $this->fetchRows(
                    "SELECT id, uuid, foreign_id AS patient_id, docdate, date, name, mimetype, hash, encounter_id
                     FROM documents
                     WHERE foreign_id = ? AND deleted = 0 AND COALESCE(docdate, date) > ?
                     ORDER BY COALESCE(docdate, date) DESC, id DESC
                     LIMIT " . $documentLimit,
                    [$pid, $sinceDateTime]
                );
                foreach ($documentRows as $row) {
                    $records[] = $this->mapDocumentRecord($row);
                }
            }
        }

        if (in_array('medications', $grantedDataClasses, true)) {
            $medicationRows = $this->fetchRows(
                "SELECT
                    l.id AS list_id,
                    l.uuid AS list_uuid,
                    l.pid AS patient_id,
                    l.date,
                    l.begdate,
                    l.enddate,
                    l.title,
                    l.activity,
                    l.comments,
                    l.modifydate,
                    lm.id AS medication_issue_id,
                    lm.drug_dosage_instructions,
                    lm.usage_category_title,
                    lm.request_intent_title,
                    lm.medication_adherence,
                    lm.medication_adherence_date_asserted,
                    lm.prescription_id
                 FROM lists l
                 LEFT JOIN lists_medication lm ON lm.list_id = l.id
                 WHERE l.pid = ?
                    AND l.type = 'medication'
                    AND COALESCE(l.modifydate, l.date, l.begdate) > ?
                 ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC
                 LIMIT " . $maxRecords,
                [$pid, $sinceDateTime]
            );
            foreach ($medicationRows as $row) {
                $records[] = $this->mapMedicationRecord($row);
            }
        }

        if (in_array('allergies', $grantedDataClasses, true)) {
            $allergyRows = $this->fetchRows(
                "SELECT
                    l.id,
                    l.uuid,
                    l.pid AS patient_id,
                    l.date,
                    l.begdate,
                    l.enddate,
                    l.title,
                    l.activity,
                    l.comments,
                    l.reaction,
                    l.verification,
                    l.severity_al,
                    l.modifydate,
                    reaction.title AS reaction_title,
                    verification.title AS verification_title
                 FROM lists l
                 LEFT JOIN list_options reaction
                    ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction'
                 LEFT JOIN list_options verification
                    ON verification.option_id = l.verification
                        AND verification.list_id = 'allergyintolerance-verification'
                 WHERE l.pid = ?
                    AND l.type = 'allergy'
                    AND COALESCE(l.modifydate, l.date, l.begdate) > ?
                 ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC
                 LIMIT " . $maxRecords,
                [$pid, $sinceDateTime]
            );
            foreach ($allergyRows as $row) {
                $records[] = $this->mapAllergyRecord($row);
            }
        }

        usort(
            $records,
            static fn (array $left, array $right): int => strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? ''))
        );

        return array_slice($records, 0, $maxRecords);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchSourceRecord(int $pid, string $sourceId, EvidenceCaps $caps): ?array
    {
        if (preg_match('/\A[A-Za-z0-9_]+:([A-Za-z0-9_]+):([0-9]+)\z/', $sourceId, $matches) !== 1) {
            return null;
        }

        $table = $matches[1];
        $recordId = (int) $matches[2];
        if ($recordId <= 0) {
            return null;
        }

        return match ($table) {
            'patient_data' => $this->fetchPatientSource($pid, $recordId),
            'addresses' => $this->fetchAddressSource($pid, $recordId),
            'contact_telecom' => $this->fetchContactTelecomSource($pid, $recordId),
            'lists' => $this->fetchListSource($pid, $recordId),
            'lists_medication' => $this->fetchMedicationIssueSource($pid, $recordId),
            'form_encounter' => $this->fetchEncounterSource($pid, $recordId),
            'documents' => $this->fetchDocumentSource($pid, $recordId),
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $sql, array $params): array
    {
        $statement = sqlStatement($sql, $params);
        if (!$statement) {
            return [];
        }

        $rows = [];
        while ($row = sqlFetchArray($statement)) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPatientContactAddressRows(int $pid, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->fetchRows(
            "SELECT
                a.id AS address_id,
                a.line1,
                a.line2,
                a.city,
                a.state,
                a.zip,
                a.plus_four,
                a.country,
                a.district,
                c.foreign_id AS patient_id,
                ca.id AS contact_address_id,
                ca.priority,
                ca.`type` AS address_type,
                address_type.title AS address_type_title,
                ca.`use` AS address_use,
                address_use.title AS address_use_title,
                ca.status AS address_status,
                ca.is_primary,
                ca.period_start,
                ca.period_end,
                ca.created_date
             FROM contact c
             INNER JOIN contact_address ca ON ca.contact_id = c.id
             INNER JOIN addresses a ON a.id = ca.address_id
             LEFT JOIN list_options address_type
                ON address_type.list_id = 'address-types'
                    AND address_type.option_id = ca.`type`
             LEFT JOIN list_options address_use
                ON address_use.list_id = 'address-uses'
                    AND address_use.option_id = ca.`use`
             WHERE c.foreign_table_name = 'patient_data'
                AND c.foreign_id = ?
                AND ca.status = 'A'
             ORDER BY ca.is_primary DESC, COALESCE(ca.priority, 999999) ASC, ca.id ASC
             LIMIT " . $limit,
            [$pid]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPatientContactTelecomRows(int $pid, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->fetchRows(
            "SELECT
                ct.id AS contact_telecom_id,
                ct.contact_id,
                c.foreign_id AS patient_id,
                ct.rank,
                ct.system,
                telecom_system.title AS telecom_system_title,
                ct.`use` AS telecom_use,
                telecom_use.title AS telecom_use_title,
                ct.value,
                ct.status AS telecom_status,
                ct.is_primary,
                ct.period_start,
                ct.period_end,
                ct.created_date
             FROM contact c
             INNER JOIN contact_telecom ct ON ct.contact_id = c.id
             LEFT JOIN list_options telecom_system
                ON telecom_system.list_id = 'telecom_systems'
                    AND telecom_system.option_id = ct.system
             LEFT JOIN list_options telecom_use
                ON telecom_use.list_id = 'telecom_uses'
                    AND telecom_use.option_id = ct.`use`
             WHERE c.foreign_table_name = 'patient_data'
                AND c.foreign_id = ?
                AND ct.status = 'A'
                AND ct.system IN ('phone', 'sms', 'fax', 'email')
             ORDER BY ct.is_primary DESC, COALESCE(ct.rank, 999999) ASC, ct.id ASC
             LIMIT " . $limit,
            [$pid]
        );
    }

    private function changedSinceBaseline(int $pid, EvidenceCaps $caps): string
    {
        $row = sqlQuery(
            "SELECT date
             FROM form_encounter
             WHERE pid = ?
             ORDER BY date DESC, id DESC
             LIMIT 1 OFFSET 1",
            [$pid]
        );

        $date = is_array($row) ? $this->dateValue($row, ['date']) : null;
        if ($date !== null) {
            return $date;
        }

        $lookbackStart = $caps->getLookbackStart(new DateTimeImmutable());
        return $lookbackStart?->format('Y-m-d H:i:s') ?? '1000-01-01 00:00:00';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPatientRecord(array $row): array
    {
        $displayParts = [];
        $fieldsUsed = [];

        $this->addDisplayPart($displayParts, $fieldsUsed, 'name', $this->personName($row), $this->filledFields($row, [
            'title',
            'fname',
            'mname',
            'lname',
            'suffix',
        ]));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'preferred name', $row['preferred_name'] ?? null, ['preferred_name']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'birth name', $this->birthName($row), $this->filledFields($row, [
            'birth_fname',
            'birth_mname',
            'birth_lname',
        ]));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'date of birth', $this->dateValue($row, ['DOB']), ['DOB']);
        $this->addRawDisplayPart($displayParts, $fieldsUsed, $this->ageFromDob($row['DOB'] ?? null), ['DOB']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'sex at birth', $row['sex'] ?? null, ['sex']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'current sex', $row['sex_identified'] ?? null, ['sex_identified']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'gender identity', $row['gender_identity'] ?? null, ['gender_identity']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'sexual orientation', $row['sexual_orientation'] ?? null, ['sexual_orientation']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'pronoun', $row['pronoun'] ?? null, ['pronoun']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'status', $row['status'] ?? null, ['status']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'deceased date', $this->dateValue($row, ['deceased_date']), ['deceased_date']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'deceased reason', $row['deceased_reason'] ?? null, ['deceased_reason']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'language', $row['language'] ?? null, ['language']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'interpreter needed', $row['interpreter_needed'] ?? null, ['interpreter_needed']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'interpreter', $row['interpreter'] ?? null, ['interpreter']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'race', $row['race'] ?? null, ['race']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'ethnicity', $row['ethnicity'] ?? null, ['ethnicity']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'ethnoracial', $row['ethnoracial'] ?? null, ['ethnoracial']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'religion', $row['religion'] ?? null, ['religion']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'nationality country', $row['nationality_country'] ?? null, ['nationality_country']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'tribal affiliations', $row['tribal_affiliations'] ?? null, ['tribal_affiliations']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'address', $this->formatPatientAddress($row), $this->filledFields($row, [
            'street',
            'street_line_2',
            'city',
            'state',
            'postal_code',
            'county',
            'country_code',
        ]));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'home phone', $this->formatPhoneValue($row['phone_home'] ?? null), ['phone_home']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'work phone', $this->formatPhoneValue($row['phone_biz'] ?? null), ['phone_biz']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'alternate phone', $this->formatPhoneValue($row['phone_contact'] ?? null), ['phone_contact']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'mobile phone', $this->formatPhoneValue($row['phone_cell'] ?? null), ['phone_cell']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'email', $row['email'] ?? null, ['email']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'direct email', $row['email_direct'] ?? null, ['email_direct']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'contact relationship', $row['contact_relationship'] ?? null, ['contact_relationship']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'registration date', $this->dateValue($row, ['regdate']), ['regdate']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'primary provider id', $this->nonZeroValue($row['providerID'] ?? null), ['providerID']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'referring provider id', $this->nonZeroValue($row['ref_providerID'] ?? null), ['ref_providerID']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'referrer', $row['referrer'] ?? null, ['referrer']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'referrer id', $row['referrerID'] ?? null, ['referrerID']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'pharmacy id', $this->nonZeroValue($row['pharmacy_id'] ?? null), ['pharmacy_id']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'patient portal allowed', $row['allow_patient_portal'] ?? null, ['allow_patient_portal']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'care team provider', $row['care_team_provider'] ?? null, ['care_team_provider']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'care team facility', $row['care_team_facility'] ?? null, ['care_team_facility']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'care team status', $row['care_team_status'] ?? null, ['care_team_status']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'provider since', $this->dateValue($row, ['provider_since_date']), ['provider_since_date']);

        return [
            'source_id' => 'demographics:patient_data:' . (int) $row['pid'],
            'source_type' => 'demographics',
            'data_class' => 'demographics',
            'table' => 'patient_data',
            'record_id' => (string) (int) $row['pid'],
            'record_uuid' => $this->uuidToString($row['uuid'] ?? null),
            'patient_id' => (int) $row['pid'],
            'patient_uuid' => $this->uuidToString($row['uuid'] ?? null),
            'date' => $this->dateValue($row, ['date']),
            'status' => 'available',
            'display' => $this->joinDisplay($displayParts, 'Patient demographic record'),
            'excerpt' => $this->joinDisplay($displayParts, 'Patient demographic record'),
            'fields_used' => $fieldsUsed === [] ? ['pid'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_patient_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAddressRecord(array $row): array
    {
        $displayParts = [];
        $fieldsUsed = [];

        $this->addDisplayPart($displayParts, $fieldsUsed, 'structured address', $this->formatAddressFromValues([
            $row['line1'] ?? null,
            $row['line2'] ?? null,
            $this->localityLine($row['city'] ?? null, $row['state'] ?? null, $this->formatPostalCode($row['zip'] ?? null, $row['plus_four'] ?? null)),
            $row['district'] ?? null,
            $row['country'] ?? null,
        ]), $this->filledFields($row, [
            'line1',
            'line2',
            'city',
            'state',
            'zip',
            'plus_four',
            'district',
            'country',
        ]));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'address use', $this->optionLabel($row['address_use'] ?? null, $row['address_use_title'] ?? null), ['use']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'address type', $this->optionLabel($row['address_type'] ?? null, $row['address_type_title'] ?? null), ['type']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'primary address', $this->yesNoValue($row['is_primary'] ?? null), ['is_primary']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'address period start', $this->dateValue($row, ['period_start']), ['period_start']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'address period end', $this->dateValue($row, ['period_end']), ['period_end']);

        return [
            'source_id' => 'demographics:addresses:' . (int) $row['address_id'],
            'source_type' => 'address',
            'data_class' => 'demographics',
            'table' => 'addresses',
            'record_id' => (string) (int) $row['address_id'],
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['period_start', 'created_date']),
            'status' => 'available',
            'display' => $this->joinDisplay($displayParts, 'Structured patient address'),
            'excerpt' => $this->joinDisplay($displayParts, 'Structured patient address'),
            'fields_used' => $fieldsUsed === [] ? ['id'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_patient_address',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapTelecomRecord(array $row): array
    {
        $displayParts = [];
        $fieldsUsed = [];
        $system = strtolower($this->filled($row['system'] ?? null));
        $systemLabel = $this->optionLabel($row['system'] ?? null, $row['telecom_system_title'] ?? null);
        $useLabel = $this->optionLabel($row['telecom_use'] ?? null, $row['telecom_use_title'] ?? null);
        $value = $system === 'phone' || $system === 'sms' || $system === 'fax'
            ? $this->formatPhoneValue($row['value'] ?? null)
            : $this->filled($row['value'] ?? null);

        $this->addDisplayPart($displayParts, $fieldsUsed, 'structured contact', $this->formatAddressFromValues([
            $systemLabel,
            $useLabel,
            $value,
        ]), $this->filledFields($row, ['system', 'telecom_use', 'value']));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'primary contact', $this->yesNoValue($row['is_primary'] ?? null), ['is_primary']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'contact rank', $row['rank'] ?? null, ['rank']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'contact period start', $this->dateValue($row, ['period_start']), ['period_start']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'contact period end', $this->dateValue($row, ['period_end']), ['period_end']);

        return [
            'source_id' => 'demographics:contact_telecom:' . (int) $row['contact_telecom_id'],
            'source_type' => 'telecom',
            'data_class' => 'demographics',
            'table' => 'contact_telecom',
            'record_id' => (string) (int) $row['contact_telecom_id'],
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['period_start', 'created_date']),
            'status' => 'available',
            'display' => $this->joinDisplay($displayParts, 'Structured patient contact'),
            'excerpt' => $this->joinDisplay($displayParts, 'Structured patient contact'),
            'fields_used' => $fieldsUsed === [] ? ['id'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_patient_contact',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapMedicationRecord(array $row): array
    {
        $sourceTable = !empty($row['medication_issue_id']) ? 'lists_medication' : 'lists';
        $recordId = !empty($row['medication_issue_id']) ? (int) $row['medication_issue_id'] : (int) $row['list_id'];
        $status = ((string) ($row['activity'] ?? '') === '1') ? 'active' : 'unknown';
        $displayParts = [
            $this->filled($row['title'] ?? null),
            $this->filled($row['drug_dosage_instructions'] ?? null),
            $this->filled($row['usage_category_title'] ?? null),
            $this->filled($row['request_intent_title'] ?? null),
        ];

        return [
            'source_id' => 'medication:' . $sourceTable . ':' . $recordId,
            'source_type' => 'medication',
            'data_class' => 'medications',
            'table' => $sourceTable,
            'record_id' => (string) $recordId,
            'record_uuid' => $this->uuidToString($row['list_uuid'] ?? null),
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['medication_adherence_date_asserted', 'modifydate', 'date', 'begdate']),
            'status' => $status,
            'display' => $this->joinDisplay($displayParts, 'Medication record'),
            'excerpt' => $this->filled($row['comments'] ?? null) ?: $this->joinDisplay($displayParts, 'Medication record'),
            'fields_used' => ['title', 'activity', 'begdate', 'enddate', 'drug_dosage_instructions', 'usage_category_title'],
            'reliability' => 'structured_active_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAllergyRecord(array $row): array
    {
        $status = $this->filled($row['verification_title'] ?? null)
            ?: (((string) ($row['activity'] ?? '') === '1') ? 'active' : 'unknown');
        $displayParts = [
            $this->filled($row['title'] ?? null),
            $this->filled($row['reaction_title'] ?? null) !== '' ? 'reaction ' . $this->filled($row['reaction_title']) : '',
            $this->filled($row['severity_al'] ?? null) !== '' ? 'severity ' . $this->filled($row['severity_al']) : '',
        ];

        return [
            'source_id' => 'allergy:lists:' . (int) $row['id'],
            'source_type' => 'allergy',
            'data_class' => 'allergies',
            'table' => 'lists',
            'record_id' => (string) (int) $row['id'],
            'record_uuid' => $this->uuidToString($row['uuid'] ?? null),
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['modifydate', 'date', 'begdate']),
            'status' => $status,
            'display' => $this->joinDisplay($displayParts, 'Allergy record'),
            'excerpt' => $this->filled($row['comments'] ?? null) ?: $this->joinDisplay($displayParts, 'Allergy record'),
            'fields_used' => ['title', 'activity', 'reaction', 'verification', 'severity_al'],
            'reliability' => 'structured_active_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEncounterRecord(array $row): array
    {
        $displayParts = [
            'Encounter ' . $this->filled($row['encounter'] ?? null),
            $this->filled($row['reason'] ?? null),
            $this->filled($row['facility'] ?? null),
        ];

        return [
            'source_id' => 'encounter:form_encounter:' . (int) $row['id'],
            'source_type' => 'encounter',
            'data_class' => 'recent_events',
            'table' => 'form_encounter',
            'record_id' => (string) (int) $row['id'],
            'record_uuid' => $this->uuidToString($row['uuid'] ?? null),
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['date', 'last_update']),
            'status' => $this->filled($row['class_code'] ?? null) ?: 'encounter',
            'display' => $this->joinDisplay($displayParts, 'Encounter record'),
            'excerpt' => $this->filled($row['reason'] ?? null) ?: $this->joinDisplay($displayParts, 'Encounter record'),
            'fields_used' => ['date', 'reason', 'facility', 'encounter', 'class_code'],
            'reliability' => 'structured_event_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDocumentRecord(array $row): array
    {
        $displayParts = [
            $this->filled($row['name'] ?? null),
            $this->filled($row['mimetype'] ?? null),
        ];

        return [
            'source_id' => 'document:documents:' . (int) $row['id'],
            'source_type' => 'document',
            'data_class' => 'recent_events',
            'table' => 'documents',
            'record_id' => (string) (int) $row['id'],
            'record_uuid' => $this->uuidToString($row['uuid'] ?? null),
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['docdate', 'date']),
            'status' => 'available',
            'display' => $this->joinDisplay($displayParts, 'Document record'),
            'excerpt' => $this->joinDisplay($displayParts, 'Document record'),
            'fields_used' => ['docdate', 'date', 'name', 'mimetype', 'hash'],
            'reliability' => 'structured_document_metadata',
        ];
    }

    private function fetchPatientSource(int $pid, int $recordId): ?array
    {
        if ($pid !== $recordId) {
            return null;
        }

        $records = $this->fetchBasicPatientData($pid, new EvidenceCaps(1, 0, 0));
        return $records[0] ?? null;
    }

    private function fetchAddressSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                a.id AS address_id,
                a.line1,
                a.line2,
                a.city,
                a.state,
                a.zip,
                a.plus_four,
                a.country,
                a.district,
                c.foreign_id AS patient_id,
                ca.id AS contact_address_id,
                ca.priority,
                ca.`type` AS address_type,
                address_type.title AS address_type_title,
                ca.`use` AS address_use,
                address_use.title AS address_use_title,
                ca.status AS address_status,
                ca.is_primary,
                ca.period_start,
                ca.period_end,
                ca.created_date
             FROM contact c
             INNER JOIN contact_address ca ON ca.contact_id = c.id
             INNER JOIN addresses a ON a.id = ca.address_id
             LEFT JOIN list_options address_type
                ON address_type.list_id = 'address-types'
                    AND address_type.option_id = ca.`type`
             LEFT JOIN list_options address_use
                ON address_use.list_id = 'address-uses'
                    AND address_use.option_id = ca.`use`
             WHERE c.foreign_table_name = 'patient_data'
                AND c.foreign_id = ?
                AND a.id = ?
                AND ca.status = 'A'
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapAddressRecord($row) : null;
    }

    private function fetchContactTelecomSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                ct.id AS contact_telecom_id,
                ct.contact_id,
                c.foreign_id AS patient_id,
                ct.rank,
                ct.system,
                telecom_system.title AS telecom_system_title,
                ct.`use` AS telecom_use,
                telecom_use.title AS telecom_use_title,
                ct.value,
                ct.status AS telecom_status,
                ct.is_primary,
                ct.period_start,
                ct.period_end,
                ct.created_date
             FROM contact c
             INNER JOIN contact_telecom ct ON ct.contact_id = c.id
             LEFT JOIN list_options telecom_system
                ON telecom_system.list_id = 'telecom_systems'
                    AND telecom_system.option_id = ct.system
             LEFT JOIN list_options telecom_use
                ON telecom_use.list_id = 'telecom_uses'
                    AND telecom_use.option_id = ct.`use`
             WHERE c.foreign_table_name = 'patient_data'
                AND c.foreign_id = ?
                AND ct.id = ?
                AND ct.status = 'A'
                AND ct.system IN ('phone', 'sms', 'fax', 'email')
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapTelecomRecord($row) : null;
    }

    private function fetchListSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                l.*,
                l.pid AS patient_id,
                reaction.title AS reaction_title,
                verification.title AS verification_title
             FROM lists l
             LEFT JOIN list_options reaction
                ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction'
             LEFT JOIN list_options verification
                ON verification.option_id = l.verification
                    AND verification.list_id = 'allergyintolerance-verification'
             WHERE l.pid = ? AND l.id = ?
             LIMIT 1",
            [$pid, $recordId]
        );

        if (!is_array($row) || $row === []) {
            return null;
        }

        return ($row['type'] ?? '') === 'allergy'
            ? $this->mapAllergyRecord($row)
            : $this->mapMedicationRecord([
                'list_id' => $row['id'],
                'list_uuid' => $row['uuid'],
                'patient_id' => $row['patient_id'],
            ] + $row);
    }

    private function fetchMedicationIssueSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                l.id AS list_id,
                l.uuid AS list_uuid,
                l.pid AS patient_id,
                l.date,
                l.begdate,
                l.enddate,
                l.title,
                l.activity,
                l.comments,
                l.modifydate,
                lm.id AS medication_issue_id,
                lm.drug_dosage_instructions,
                lm.usage_category_title,
                lm.request_intent_title,
                lm.medication_adherence,
                lm.medication_adherence_date_asserted,
                lm.prescription_id
             FROM lists_medication lm
             INNER JOIN lists l ON l.id = lm.list_id
             WHERE l.pid = ? AND lm.id = ?
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapMedicationRecord($row) : null;
    }

    private function fetchEncounterSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT id, uuid, pid AS patient_id, date, reason, facility, encounter, class_code, last_update
             FROM form_encounter
             WHERE pid = ? AND id = ?
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapEncounterRecord($row) : null;
    }

    private function fetchDocumentSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT id, uuid, foreign_id AS patient_id, docdate, date, name, mimetype, hash, encounter_id
             FROM documents
             WHERE foreign_id = ? AND id = ? AND deleted = 0
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapDocumentRecord($row) : null;
    }

    /**
     * @param list<string> $parts
     * @param list<string> $fieldsUsed
     * @param list<string> $fields
     */
    private function addDisplayPart(array &$parts, array &$fieldsUsed, string $label, mixed $value, array $fields): void
    {
        $filled = $this->filled($value);
        if ($filled === '') {
            return;
        }

        $parts[] = $label . ': ' . $filled;
        foreach ($fields as $field) {
            if ($field !== '') {
                $fieldsUsed[] = $field;
            }
        }
    }

    /**
     * @param list<string> $parts
     * @param list<string> $fieldsUsed
     * @param list<string> $fields
     */
    private function addRawDisplayPart(array &$parts, array &$fieldsUsed, string $value, array $fields): void
    {
        $filled = $this->filled($value);
        if ($filled === '') {
            return;
        }

        $parts[] = $filled;
        foreach ($fields as $field) {
            if ($field !== '') {
                $fieldsUsed[] = $field;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return list<string>
     */
    private function filledFields(array $row, array $fields): array
    {
        $filled = [];
        foreach ($fields as $field) {
            if ($this->filled($row[$field] ?? null) !== '') {
                $filled[] = $field;
            }
        }

        return $filled;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function personName(array $row): string
    {
        return $this->formatAddressFromValues([
            $row['title'] ?? null,
            $row['fname'] ?? null,
            $row['mname'] ?? null,
            $row['lname'] ?? null,
            $row['suffix'] ?? null,
        ], ' ');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function birthName(array $row): string
    {
        return $this->formatAddressFromValues([
            $row['birth_fname'] ?? null,
            $row['birth_mname'] ?? null,
            $row['birth_lname'] ?? null,
        ], ' ');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatPatientAddress(array $row): string
    {
        return $this->formatAddressFromValues([
            $row['street'] ?? null,
            $row['street_line_2'] ?? null,
            $this->localityLine($row['city'] ?? null, $row['state'] ?? null, $row['postal_code'] ?? null),
            $row['county'] ?? null,
            $row['country_code'] ?? null,
        ]);
    }

    /**
     * @param list<mixed> $values
     */
    private function formatAddressFromValues(array $values, string $separator = ', '): string
    {
        $parts = [];
        foreach ($values as $value) {
            $filled = $this->filled($value);
            if ($filled !== '') {
                $parts[] = $filled;
            }
        }

        return implode($separator, array_unique($parts));
    }

    private function localityLine(mixed $city, mixed $state, mixed $postalCode): string
    {
        return $this->formatAddressFromValues([$city, $state, $postalCode], ' ');
    }

    private function formatPostalCode(mixed $zip, mixed $plusFour): string
    {
        $zip = $this->filled($zip);
        $plusFour = $this->filled($plusFour);
        if ($zip === '' || $plusFour === '' || str_contains($zip, $plusFour)) {
            return $zip;
        }

        return $zip . '-' . $plusFour;
    }

    private function optionLabel(mixed $value, mixed $title): string
    {
        $title = $this->filled($title);
        if ($title !== '') {
            return $title;
        }

        return $this->filled($value);
    }

    private function yesNoValue(mixed $value): string
    {
        return match (strtoupper($this->filled($value))) {
            'Y', 'YES', '1', 'TRUE' => 'yes',
            'N', 'NO', '0', 'FALSE' => 'no',
            default => '',
        };
    }

    private function nonZeroValue(mixed $value): string
    {
        $filled = $this->filled($value);
        return $filled === '0' ? '' : $filled;
    }

    private function formatPhoneValue(mixed $value): string
    {
        $phone = $this->filled($value);
        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }

        return $phone;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addressKeyFromPatientRow(array $row): string
    {
        return $this->addressKey([
            $row['street'] ?? null,
            $row['street_line_2'] ?? null,
            $row['city'] ?? null,
            $row['state'] ?? null,
            $row['postal_code'] ?? null,
            $row['county'] ?? null,
            $row['country_code'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addressKeyFromAddressRow(array $row): string
    {
        return $this->addressKey([
            $row['line1'] ?? null,
            $row['line2'] ?? null,
            $row['city'] ?? null,
            $row['state'] ?? null,
            $this->formatPostalCode($row['zip'] ?? null, $row['plus_four'] ?? null),
            $row['district'] ?? null,
            $row['country'] ?? null,
        ]);
    }

    /**
     * @param list<mixed> $values
     */
    private function addressKey(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $filled = strtolower($this->filled($value));
            if ($filled !== '') {
                $parts[] = preg_replace('/[^a-z0-9]+/', '', $filled) ?? '';
            }
        }

        return implode('|', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    private function contactKeysFromPatientRow(array $row): array
    {
        $keys = [];
        foreach (['phone_home', 'phone_biz', 'phone_contact', 'phone_cell'] as $field) {
            $key = $this->contactKey('phone', $row[$field] ?? null);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        foreach (['email', 'email_direct'] as $field) {
            $key = $this->contactKey('email', $row[$field] ?? null);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function contactKeyFromTelecomRow(array $row): string
    {
        return $this->contactKey($row['system'] ?? null, $row['value'] ?? null);
    }

    private function contactKey(mixed $system, mixed $value): string
    {
        $value = $this->filled($value);
        if ($value === '') {
            return '';
        }

        $system = strtolower($this->filled($system));
        if ($system === 'email') {
            return 'email:' . strtolower($value);
        }

        if ($system === 'phone' || $system === 'sms' || $system === 'fax') {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $digits = substr($digits, 1);
            }
            return $digits === '' ? '' : 'phone:' . $digits;
        }

        return $system . ':' . strtolower($value);
    }

    private function uuidToString(mixed $uuid): ?string
    {
        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        try {
            return UuidRegistry::uuidToString($uuid);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private function dateValue(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $this->filled($row[$field] ?? null);
            if ($value !== '' && $value !== '0000-00-00' && $value !== '0000-00-00 00:00:00') {
                return substr($value, 0, 19);
            }
        }

        return null;
    }

    private function filled(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param list<string> $parts
     */
    private function joinDisplay(array $parts, string $fallback): string
    {
        $filled = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
        return $filled === [] ? $fallback : implode('; ', array_unique($filled));
    }

    private function ageFromDob(mixed $dob): string
    {
        $value = $this->filled($dob);
        if ($value === '' || $value === '0000-00-00') {
            return '';
        }

        try {
            $birthDate = new DateTimeImmutable($value);
            return 'age: ' . $birthDate->diff(new DateTimeImmutable())->y;
        } catch (Throwable) {
            return '';
        }
    }
}
