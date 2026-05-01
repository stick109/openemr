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
    private const BASIC_PATIENT_EMPLOYER_LIMIT = 1;
    private const BASIC_PATIENT_EMPLOYER_CANDIDATE_LIMIT = 5;

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
                pd.pid,
                pd.uuid,
                pd.title,
                pd.fname,
                pd.mname,
                pd.lname,
                pd.suffix,
                pd.preferred_name,
                pd.birth_fname,
                pd.birth_mname,
                pd.birth_lname,
                pd.DOB,
                pd.sex,
                pd.sex_identified,
                pd.gender_identity,
                pd.sexual_orientation,
                pd.pronoun,
                pd.status,
                pd.deceased_date,
                pd.deceased_reason,
                pd.language,
                pd.interpreter,
                pd.interpreter_needed,
                pd.race,
                pd.ethnicity,
                pd.ethnoracial,
                pd.religion,
                pd.nationality_country,
                pd.tribal_affiliations,
                pd.street,
                pd.street_line_2,
                pd.city,
                pd.state,
                pd.postal_code,
                pd.county,
                pd.country_code,
                pd.phone_home,
                pd.phone_biz,
                pd.phone_contact,
                pd.phone_cell,
                pd.email,
                pd.email_direct,
                pd.contact_relationship,
                pd.date,
                pd.regdate,
                pd.providerID,
                primary_provider.title AS primary_provider_title,
                primary_provider.fname AS primary_provider_fname,
                primary_provider.mname AS primary_provider_mname,
                primary_provider.lname AS primary_provider_lname,
                primary_provider.suffix AS primary_provider_suffix,
                primary_provider.specialty AS primary_provider_specialty,
                primary_provider.npi AS primary_provider_npi,
                pd.ref_providerID,
                pd.referrer,
                pd.referrerID,
                pd.pharmacy_id,
                pd.allow_patient_portal,
                pd.care_team_provider,
                pd.care_team_facility,
                pd.care_team_status,
                pd.provider_since_date
             FROM patient_data pd
             LEFT JOIN users primary_provider
                ON primary_provider.id = pd.providerID
             WHERE pd.pid = ?
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

        $employerLimit = min(self::BASIC_PATIENT_EMPLOYER_LIMIT, $remaining);
        if ($employerLimit > 0) {
            $employersAdded = 0;
            $employerCandidateLimit = max($employerLimit, self::BASIC_PATIENT_EMPLOYER_CANDIDATE_LIMIT);
            foreach ($this->fetchPatientEmployerRows($pid, $employerCandidateLimit) as $employerRow) {
                if (!$this->hasEmployerDisplayData($employerRow)) {
                    continue;
                }

                $records[] = $this->mapEmployerRecord($employerRow);
                $remaining--;
                $employersAdded++;
                if ($remaining <= 0 || $employersAdded >= $employerLimit) {
                    break;
                }
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

        $medicationRows = $this->fetchRows(
            "SELECT
                l.id AS list_id,
                l.uuid AS list_uuid,
                l.pid AS patient_id,
                l.date,
                l.begdate,
                l.enddate,
                l.subtype,
                l.title,
                l.diagnosis AS list_diagnosis,
                l.external_id AS list_external_id,
                l.list_option_id,
                l.erx_source AS list_erx_source,
                l.erx_uploaded AS list_erx_uploaded,
                l.activity,
                l.comments,
                l.modifydate,
                lm.id AS medication_issue_id,
                lm.list_id AS medication_list_id,
                lm.drug_dosage_instructions,
                lm.usage_category,
                lm.usage_category_title,
                lm.request_intent,
                lm.request_intent_title,
                lm.medication_adherence_information_source,
                lm.medication_adherence,
                lm.medication_adherence_date_asserted,
                lm.prescription_id AS linked_prescription_id,
                lm.is_primary_record,
                lm.reporting_source_record_id,
                " . $this->prescriptionSelectColumns('p') . "
             FROM lists l
             LEFT JOIN lists_medication lm ON lm.list_id = l.id
             LEFT JOIN prescriptions p
                ON p.id = lm.prescription_id
                    AND p.patient_id = ?
             WHERE l.pid = ?
                AND l.type = 'medication'
                AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate = '0000-00-00' OR l.enddate >= CURDATE())
             ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC
             LIMIT " . $limit,
            [$pid, $pid]
        );

        $records = array_map(fn (array $row): array => $this->mapMedicationRecord($row), $medicationRows);
        $remaining = $limit - count($records);

        if ($remaining > 0) {
            foreach ($this->fetchStandaloneCurrentPrescriptionRows($pid, $remaining) as $row) {
                $records[] = $this->mapPrescriptionRecord($row);
            }
            $remaining = $limit - count($records);
        }

        if ($records === [] && $remaining > 0) {
            $reviewRow = $this->fetchMedicationReviewRow($pid);
            if ($reviewRow !== null) {
                $records[] = $this->mapMedicationReviewRecord($reviewRow);
            }
        }

        return array_slice($records, 0, $limit);
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
                l.list_option_id,
                l.external_allergyid,
                l.external_id AS list_external_id,
                l.erx_source AS list_erx_source,
                l.erx_uploaded AS list_erx_uploaded,
                l.subtype,
                l.diagnosis AS list_diagnosis,
                l.activity,
                l.comments,
                l.reaction,
                l.verification,
                l.severity_al,
                l.modifydate,
                reaction.title AS reaction_title,
                coded_allergen.title AS coded_allergen_title,
                coded_allergen.codes AS coded_allergen_codes,
                severity.title AS severity_title,
                severity.codes AS severity_codes,
                verification.title AS verification_title
             FROM lists l
             LEFT JOIN list_options reaction
                ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction'
             LEFT JOIN list_options coded_allergen
                ON coded_allergen.option_id = l.list_option_id
                    AND coded_allergen.list_id = 'allergy_issue_list'
             LEFT JOIN list_options severity
                ON severity.option_id = l.severity_al
                    AND severity.list_id = 'severity_ccda'
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

        $records = array_map(fn (array $row): array => $this->mapAllergyRecord($row), $rows);
        if ($records === []) {
            $reviewRow = $this->fetchAllergyReviewRow($pid);
            if ($reviewRow !== null) {
                $records[] = $this->mapAllergyReviewRecord($reviewRow);
            }
        }

        return array_slice($records, 0, $limit);
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
        if (preg_match('/\A([A-Za-z0-9_]+):([A-Za-z0-9_]+):([0-9]+)\z/', $sourceId, $matches) !== 1) {
            return null;
        }

        $sourceGroup = $matches[1];
        $table = $matches[2];
        $recordId = (int) $matches[3];
        if ($recordId <= 0) {
            return null;
        }

        return match ($table) {
            'patient_data' => $this->fetchPatientSource($pid, $recordId),
            'addresses' => $this->fetchAddressSource($pid, $recordId),
            'contact_telecom' => $this->fetchContactTelecomSource($pid, $recordId),
            'employer_data' => $this->fetchEmployerSource($pid, $recordId),
            'lists' => $this->fetchListSource($pid, $recordId, $sourceGroup),
            'lists_medication' => $sourceGroup === 'medication' ? $this->fetchMedicationIssueSource($pid, $recordId) : null,
            'prescriptions' => $sourceGroup === 'medication' ? $this->fetchPrescriptionSource($pid, $recordId) : null,
            'lists_touch' => match ($sourceGroup) {
                'medication' => $this->fetchMedicationReviewSource($pid, $recordId),
                'allergy' => $this->fetchAllergyReviewSource($pid, $recordId),
                default => null,
            },
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
    private function fetchStandaloneCurrentPrescriptionRows(int $pid, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->fetchRows(
            "SELECT
                p.patient_id AS patient_id,
                " . $this->prescriptionSelectColumns('p') . "
             FROM prescriptions p
             WHERE p.patient_id = ?
                AND p.active = 1
                AND (p.end_date IS NULL OR p.end_date = '0000-00-00' OR p.end_date >= CURDATE())
                AND NOT EXISTS (
                    SELECT 1
                    FROM lists l
                    INNER JOIN lists_medication lm ON lm.list_id = l.id
                    WHERE l.pid = p.patient_id
                        AND l.type = 'medication'
                        AND lm.prescription_id = p.id
                        AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate = '0000-00-00' OR l.enddate >= CURDATE())
                )
             ORDER BY COALESCE(p.date_modified, p.date_added, p.datetime, p.start_date, p.txDate) DESC, p.id DESC
             LIMIT " . $limit,
            [$pid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMedicationReviewRow(int $pid): ?array
    {
        $row = sqlQuery(
            "SELECT
                pid AS patient_id,
                type,
                date
             FROM lists_touch
             WHERE pid = ?
                AND type = 'medication'
             LIMIT 1",
            [$pid]
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAllergyReviewRow(int $pid): ?array
    {
        $row = sqlQuery(
            "SELECT
                pid AS patient_id,
                type,
                date
             FROM lists_touch
             WHERE pid = ?
                AND type = 'allergy'
             ORDER BY date DESC
             LIMIT 1",
            [$pid]
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    private function prescriptionSelectColumns(string $alias): string
    {
        return "{$alias}.id AS prescription_record_id,
                {$alias}.uuid AS prescription_uuid,
                {$alias}.patient_id AS prescription_patient_id,
                {$alias}.encounter AS prescription_encounter,
                {$alias}.provider_id AS prescription_provider_id,
                {$alias}.filled_by_id AS prescription_filled_by_id,
                {$alias}.pharmacy_id AS prescription_pharmacy_id,
                {$alias}.drug AS prescription_drug,
                {$alias}.drug_id AS prescription_drug_id,
                {$alias}.rxnorm_drugcode AS prescription_rxnorm_drugcode,
                {$alias}.medication AS prescription_medication,
                {$alias}.date_added AS prescription_date_added,
                {$alias}.date_modified AS prescription_date_modified,
                {$alias}.start_date AS prescription_start_date,
                {$alias}.end_date AS prescription_end_date,
                {$alias}.filled_date AS prescription_filled_date,
                {$alias}.datetime AS prescription_datetime,
                {$alias}.active AS prescription_active,
                {$alias}.txDate AS prescription_txDate,
                {$alias}.drug_dosage_instructions AS prescription_drug_dosage_instructions,
                {$alias}.dosage AS prescription_dosage,
                {$alias}.quantity AS prescription_quantity,
                {$alias}.size AS prescription_size,
                {$alias}.unit AS prescription_unit,
                {$alias}.route AS prescription_route,
                {$alias}.`interval` AS prescription_interval,
                {$alias}.form AS prescription_form,
                {$alias}.substitute AS prescription_substitute,
                {$alias}.refills AS prescription_refills,
                {$alias}.per_refill AS prescription_per_refill,
                {$alias}.prn AS prescription_prn,
                {$alias}.note AS prescription_note,
                {$alias}.usage_category AS prescription_usage_category,
                {$alias}.usage_category_title AS prescription_usage_category_title,
                {$alias}.request_intent AS prescription_request_intent,
                {$alias}.request_intent_title AS prescription_request_intent_title,
                {$alias}.indication AS prescription_indication,
                {$alias}.diagnosis AS prescription_diagnosis,
                {$alias}.erx_source AS prescription_erx_source,
                {$alias}.erx_uploaded AS prescription_erx_uploaded,
                {$alias}.external_id AS prescription_external_id,
                {$alias}.prescriptionguid AS prescription_guid";
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
    private function fetchPatientEmployerRows(int $pid, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->fetchRows(
            "SELECT
                emp.id AS employer_data_id,
                emp.uuid AS employer_uuid,
                emp.name,
                emp.street,
                emp.street_line_2,
                emp.postal_code,
                emp.city,
                emp.state,
                emp.country,
                emp.date,
                emp.pid AS patient_id,
                emp.start_date,
                emp.end_date,
                emp.occupation,
                occupation.title AS occupation_title,
                emp.industry,
                industry.title AS industry_title
             FROM employer_data emp
             LEFT JOIN list_options occupation
                ON occupation.list_id = 'OccupationODH'
                    AND occupation.option_id = emp.occupation
             LEFT JOIN list_options industry
                ON industry.list_id = 'IndustryODH'
                    AND industry.option_id = emp.industry
             WHERE emp.pid = ?
             ORDER BY COALESCE(emp.date, '1000-01-01 00:00:00') DESC, emp.id DESC
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
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'primary provider',
            $this->providerName($row, 'primary_provider'),
            $this->providerFields($row, 'providerID', [
                'primary_provider_title',
                'primary_provider_fname',
                'primary_provider_mname',
                'primary_provider_lname',
                'primary_provider_suffix',
            ])
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'primary provider specialty',
            $row['primary_provider_specialty'] ?? null,
            $this->providerFields($row, 'providerID', ['primary_provider_specialty'])
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'primary provider NPI',
            $row['primary_provider_npi'] ?? null,
            $this->providerFields($row, 'providerID', ['primary_provider_npi'])
        );
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
            'display' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Patient demographic record')),
            'excerpt' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Patient demographic record')),
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
            'display' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Structured patient address')),
            'excerpt' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Structured patient address')),
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
            'display' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Structured patient contact')),
            'excerpt' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Structured patient contact')),
            'fields_used' => $fieldsUsed === [] ? ['id'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_patient_contact',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEmployerRecord(array $row): array
    {
        $displayParts = [];
        $fieldsUsed = [];

        $this->addDisplayPart($displayParts, $fieldsUsed, 'employer', $row['name'] ?? null, ['name']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'employer address', $this->formatEmployerAddress($row), $this->filledFields($row, [
            'street',
            'street_line_2',
            'city',
            'state',
            'postal_code',
            'country',
        ]));
        $this->addDisplayPart($displayParts, $fieldsUsed, 'occupation', $this->optionLabel($row['occupation'] ?? null, $row['occupation_title'] ?? null), ['occupation']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'industry', $this->optionLabel($row['industry'] ?? null, $row['industry_title'] ?? null), ['industry']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'employment start', $this->dateValue($row, ['start_date']), ['start_date']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'employment end', $this->dateValue($row, ['end_date']), ['end_date']);

        return [
            'source_id' => 'demographics:employer_data:' . (int) $row['employer_data_id'],
            'source_type' => 'employer',
            'data_class' => 'demographics',
            'table' => 'employer_data',
            'record_id' => (string) (int) $row['employer_data_id'],
            'record_uuid' => $this->uuidToString($row['employer_uuid'] ?? null),
            'patient_id' => (int) $row['patient_id'],
            'date' => $this->dateValue($row, ['date']),
            'status' => 'available',
            'display' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Patient employer record')),
            'excerpt' => $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Patient employer record')),
            'fields_used' => $fieldsUsed === [] ? ['id'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_patient_employer',
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
        $status = $this->medicationListStatus($row);
        $displayParts = [];
        $fieldsUsed = [];

        $this->addDisplayPart($displayParts, $fieldsUsed, 'medication', $row['title'] ?? null, ['title']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'status', $status === 'active' ? 'active' : '', ['activity']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'start date', $this->dateValue($row, ['begdate']), ['begdate']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'end date', $this->dateValue($row, ['enddate']), ['enddate']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'subtype', $row['subtype'] ?? null, ['subtype']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'diagnosis', $row['list_diagnosis'] ?? null, ['diagnosis']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'dosage instructions',
            $this->boundedText($row['drug_dosage_instructions'] ?? null, 220),
            ['drug_dosage_instructions']
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'usage category',
            $this->codedOptionLabel($row['usage_category'] ?? null, $row['usage_category_title'] ?? null),
            $this->filledFields($row, ['usage_category', 'usage_category_title'])
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'request intent',
            $this->codedOptionLabel($row['request_intent'] ?? null, $row['request_intent_title'] ?? null),
            $this->filledFields($row, ['request_intent', 'request_intent_title'])
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'adherence value', $row['medication_adherence'] ?? null, ['medication_adherence']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'adherence information source',
            $row['medication_adherence_information_source'] ?? null,
            ['medication_adherence_information_source']
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'adherence asserted',
            $this->dateValue($row, ['medication_adherence_date_asserted']),
            ['medication_adherence_date_asserted']
        );
        if ($this->yesNoValue($row['is_primary_record'] ?? null) === 'no') {
            $this->addDisplayPart($displayParts, $fieldsUsed, 'record type', 'reported/non-primary medication', ['is_primary_record']);
            $this->addDisplayPart(
                $displayParts,
                $fieldsUsed,
                'reporting source record id',
                $this->nonZeroValue($row['reporting_source_record_id'] ?? null),
                ['reporting_source_record_id']
            );
        }

        if ($this->filled($row['prescription_record_id'] ?? null) !== '') {
            $this->addPrescriptionDisplayParts($row, $displayParts, $fieldsUsed, false);
        } elseif ($this->filled($row['linked_prescription_id'] ?? ($row['prescription_id'] ?? null)) !== '') {
            $this->addDisplayPart(
                $displayParts,
                $fieldsUsed,
                'linked prescription evidence',
                'unavailable in checked patient-owned prescriptions',
                ['prescription_id']
            );
        }

        $this->addDisplayPart($displayParts, $fieldsUsed, 'list eRx source', $this->erxSourceLabel($row['list_erx_source'] ?? null), ['erx_source']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'list eRx uploaded', $this->yesNoValue($row['list_erx_uploaded'] ?? null), ['erx_uploaded']);

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
            'fields_used' => $fieldsUsed === [] ? ['title'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_active_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPrescriptionRecord(array $row): array
    {
        $displayParts = [];
        $fieldsUsed = [];
        $this->addPrescriptionDisplayParts($row, $displayParts, $fieldsUsed, true);

        $recordId = (int) ($row['prescription_record_id'] ?? 0);
        $patientId = (int) ($row['patient_id'] ?? ($row['prescription_patient_id'] ?? 0));

        return [
            'source_id' => 'medication:prescriptions:' . $recordId,
            'source_type' => 'medication',
            'data_class' => 'medications',
            'table' => 'prescriptions',
            'record_id' => (string) $recordId,
            'record_uuid' => $this->uuidToString($row['prescription_uuid'] ?? null),
            'patient_id' => $patientId,
            'date' => $this->dateValue($row, [
                'prescription_date_modified',
                'prescription_date_added',
                'prescription_datetime',
                'prescription_start_date',
                'prescription_txDate',
            ]),
            'status' => $this->prescriptionStatus($row),
            'display' => $this->joinDisplay($displayParts, 'Prescription record'),
            'excerpt' => $this->joinDisplay($displayParts, 'Prescription record'),
            'fields_used' => $fieldsUsed === [] ? ['id'] : array_values(array_unique($fieldsUsed)),
            'reliability' => 'structured_prescription_record',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapMedicationReviewRecord(array $row): array
    {
        $date = $this->dateValue($row, ['date']);
        $display = $date === null
            ? 'Medication list review marker'
            : 'Medication list review marker: reviewed/touched on ' . $date;

        return [
            'source_id' => 'medication:lists_touch:' . (int) $row['patient_id'],
            'source_type' => 'medication_review',
            'data_class' => 'medications',
            'table' => 'lists_touch',
            'record_id' => (string) (int) $row['patient_id'],
            'patient_id' => (int) $row['patient_id'],
            'date' => $date,
            'status' => 'reviewed',
            'display' => $display,
            'excerpt' => $display,
            'fields_used' => ['pid', 'type', 'date'],
            'reliability' => 'structured_medication_review_marker',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAllergyReviewRecord(array $row): array
    {
        $date = $this->dateValue($row, ['date']);
        $display = $date === null
            ? 'Allergy list review marker'
            : 'Allergy list review marker: reviewed/touched on ' . $date;

        return [
            'source_id' => 'allergy:lists_touch:' . (int) $row['patient_id'],
            'source_type' => 'allergy_review',
            'data_class' => 'allergies',
            'table' => 'lists_touch',
            'record_id' => (string) (int) $row['patient_id'],
            'patient_id' => (int) $row['patient_id'],
            'date' => $date,
            'status' => 'reviewed',
            'display' => $display,
            'excerpt' => $display,
            'fields_used' => ['pid', 'type', 'date'],
            'reliability' => 'structured_allergy_review_marker',
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
        $displayParts = [];
        $fieldsUsed = [];

        $this->addDisplayPart($displayParts, $fieldsUsed, 'allergen', $row['title'] ?? null, ['title']);
        $codedAllergenLabel = $this->filled($row['coded_allergen_title'] ?? null) !== ''
            ? $this->codedOptionLabel($row['list_option_id'] ?? null, $row['coded_allergen_title'] ?? null, true)
            : '';
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'coded allergen',
            $codedAllergenLabel,
            $this->filledFields($row, ['list_option_id', 'coded_allergen_title'])
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'coded allergen codes', $row['coded_allergen_codes'] ?? null, ['coded_allergen_codes']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'reaction',
            $this->codedOptionLabel($row['reaction'] ?? null, $row['reaction_title'] ?? null, true),
            $this->filledFields($row, ['reaction', 'reaction_title'])
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'severity',
            $this->codedOptionLabel($row['severity_al'] ?? null, $row['severity_title'] ?? null, true),
            $this->filledFields($row, ['severity_al', 'severity_title'])
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'severity codes', $row['severity_codes'] ?? null, ['severity_codes']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'verification status',
            $this->codedOptionLabel($row['verification'] ?? null, $row['verification_title'] ?? null, true),
            $this->filledFields($row, ['verification', 'verification_title'])
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'current status', ((string) ($row['activity'] ?? '') === '1') ? 'current' : '', ['activity']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'begin date', $this->dateValue($row, ['begdate']), ['begdate']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'end date', $this->dateValue($row, ['enddate']), ['enddate']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'subtype', $row['subtype'] ?? null, ['subtype']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'diagnosis', $this->boundedText($row['list_diagnosis'] ?? null, 180), ['diagnosis']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'allergy eRx source', $this->erxSourceLabel($row['list_erx_source'] ?? null), ['erx_source']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'allergy eRx uploaded', $this->yesNoValue($row['list_erx_uploaded'] ?? null), ['erx_uploaded']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'external allergy id', $row['external_allergyid'] ?? null, ['external_allergyid']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'external list id', $row['list_external_id'] ?? null, ['external_id']);

        $display = $this->displayWithCapitalizedProperties($this->joinDisplay($displayParts, 'Allergy record'));
        $excerpt = $this->boundedText($row['comments'] ?? null, 280) ?: $display;

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
            'display' => $display,
            'excerpt' => $excerpt,
            'fields_used' => $fieldsUsed === [] ? ['title'] : array_values(array_unique($fieldsUsed)),
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

    private function fetchEmployerSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                emp.id AS employer_data_id,
                emp.uuid AS employer_uuid,
                emp.name,
                emp.street,
                emp.street_line_2,
                emp.postal_code,
                emp.city,
                emp.state,
                emp.country,
                emp.date,
                emp.pid AS patient_id,
                emp.start_date,
                emp.end_date,
                emp.occupation,
                occupation.title AS occupation_title,
                emp.industry,
                industry.title AS industry_title
             FROM employer_data emp
             LEFT JOIN list_options occupation
                ON occupation.list_id = 'OccupationODH'
                    AND occupation.option_id = emp.occupation
             LEFT JOIN list_options industry
                ON industry.list_id = 'IndustryODH'
                    AND industry.option_id = emp.industry
             WHERE emp.pid = ?
                AND emp.id = ?
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] && $this->hasEmployerDisplayData($row)
            ? $this->mapEmployerRecord($row)
            : null;
    }

    private function fetchListSource(int $pid, int $recordId, string $sourceGroup): ?array
    {
        $row = sqlQuery(
            "SELECT
                l.*,
                l.pid AS patient_id,
                l.external_id AS list_external_id,
                l.erx_source AS list_erx_source,
                l.erx_uploaded AS list_erx_uploaded,
                l.diagnosis AS list_diagnosis,
                reaction.title AS reaction_title,
                coded_allergen.title AS coded_allergen_title,
                coded_allergen.codes AS coded_allergen_codes,
                severity.title AS severity_title,
                severity.codes AS severity_codes,
                verification.title AS verification_title
             FROM lists l
             LEFT JOIN list_options reaction
                ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction'
             LEFT JOIN list_options coded_allergen
                ON coded_allergen.option_id = l.list_option_id
                    AND coded_allergen.list_id = 'allergy_issue_list'
             LEFT JOIN list_options severity
                ON severity.option_id = l.severity_al
                    AND severity.list_id = 'severity_ccda'
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

        if (($row['type'] ?? '') === 'allergy' && $sourceGroup === 'allergy') {
            return $this->mapAllergyRecord($row);
        }

        if (($row['type'] ?? '') !== 'medication' || $sourceGroup !== 'medication') {
            return null;
        }

        return $this->mapMedicationRecord([
            'list_id' => $row['id'],
            'list_uuid' => $row['uuid'],
            'patient_id' => $row['patient_id'],
            'list_diagnosis' => $row['diagnosis'] ?? null,
            'list_external_id' => $row['external_id'] ?? null,
            'list_erx_source' => $row['erx_source'] ?? null,
            'list_erx_uploaded' => $row['erx_uploaded'] ?? null,
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
                l.subtype,
                l.title,
                l.diagnosis AS list_diagnosis,
                l.external_id AS list_external_id,
                l.list_option_id,
                l.erx_source AS list_erx_source,
                l.erx_uploaded AS list_erx_uploaded,
                l.activity,
                l.comments,
                l.modifydate,
                lm.id AS medication_issue_id,
                lm.list_id AS medication_list_id,
                lm.drug_dosage_instructions,
                lm.usage_category,
                lm.usage_category_title,
                lm.request_intent,
                lm.request_intent_title,
                lm.medication_adherence_information_source,
                lm.medication_adherence,
                lm.medication_adherence_date_asserted,
                lm.prescription_id AS linked_prescription_id,
                lm.is_primary_record,
                lm.reporting_source_record_id,
                " . $this->prescriptionSelectColumns('p') . "
             FROM lists_medication lm
             INNER JOIN lists l ON l.id = lm.list_id
             LEFT JOIN prescriptions p
                ON p.id = lm.prescription_id
                    AND p.patient_id = ?
             WHERE l.pid = ?
                AND l.type = 'medication'
                AND lm.id = ?
             LIMIT 1",
            [$pid, $pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapMedicationRecord($row) : null;
    }

    private function fetchPrescriptionSource(int $pid, int $recordId): ?array
    {
        $row = sqlQuery(
            "SELECT
                p.patient_id AS patient_id,
                " . $this->prescriptionSelectColumns('p') . "
             FROM prescriptions p
             WHERE p.patient_id = ?
                AND p.id = ?
             LIMIT 1",
            [$pid, $recordId]
        );

        return is_array($row) && $row !== [] ? $this->mapPrescriptionRecord($row) : null;
    }

    private function fetchMedicationReviewSource(int $pid, int $recordId): ?array
    {
        if ($pid !== $recordId) {
            return null;
        }

        $row = $this->fetchMedicationReviewRow($pid);
        return $row !== null ? $this->mapMedicationReviewRecord($row) : null;
    }

    private function fetchAllergyReviewSource(int $pid, int $recordId): ?array
    {
        if ($pid !== $recordId) {
            return null;
        }

        $row = $this->fetchAllergyReviewRow($pid);
        return $row !== null ? $this->mapAllergyReviewRecord($row) : null;
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
    private function providerName(array $row, string $prefix): string
    {
        return $this->formatAddressFromValues([
            $row[$prefix . '_title'] ?? null,
            $row[$prefix . '_fname'] ?? null,
            $row[$prefix . '_mname'] ?? null,
            $row[$prefix . '_lname'] ?? null,
            $row[$prefix . '_suffix'] ?? null,
        ], ' ');
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $detailFields
     * @return list<string>
     */
    private function providerFields(array $row, string $relationshipField, array $detailFields): array
    {
        $fields = $this->filledFields($row, $detailFields);
        return $fields === [] ? [] : array_merge([$relationshipField], $fields);
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
     * @param array<string, mixed> $row
     */
    private function formatEmployerAddress(array $row): string
    {
        return $this->formatAddressFromValues([
            $row['street'] ?? null,
            $row['street_line_2'] ?? null,
            $this->localityLine($row['city'] ?? null, $row['state'] ?? null, $row['postal_code'] ?? null),
            $row['country'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasEmployerDisplayData(array $row): bool
    {
        return $this->filled($row['name'] ?? null) !== ''
            || $this->formatEmployerAddress($row) !== ''
            || $this->optionLabel($row['occupation'] ?? null, $row['occupation_title'] ?? null) !== ''
            || $this->optionLabel($row['industry'] ?? null, $row['industry_title'] ?? null) !== ''
            || $this->dateValue($row, ['start_date']) !== null
            || $this->dateValue($row, ['end_date']) !== null;
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

    private function codedOptionLabel(mixed $value, mixed $title, bool $hideMatchingCode = false): string
    {
        $value = $this->filled($value);
        $title = $this->filled($title);
        if (
            $value !== ''
            && $title !== ''
            && (
                $hideMatchingCode
                    ? $this->normalizedOptionText($value) !== $this->normalizedOptionText($title)
                    : $value !== $title
            )
        ) {
            return $title . ' (' . $value . ')';
        }

        return $title !== '' ? $title : $value;
    }

    private function normalizedOptionText(string $value): string
    {
        $normalized = strtolower(str_replace(['_', '-'], ' ', $value));
        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $displayParts
     * @param list<string> $fieldsUsed
     */
    private function addPrescriptionDisplayParts(
        array $row,
        array &$displayParts,
        array &$fieldsUsed,
        bool $includeDrugName
    ): void {
        if ($includeDrugName) {
            $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription drug', $row['prescription_drug'] ?? null, ['drug']);
        } else {
            $this->addDisplayPart($displayParts, $fieldsUsed, 'linked prescription drug', $row['prescription_drug'] ?? null, ['drug']);
        }

        $this->addDisplayPart($displayParts, $fieldsUsed, 'rxnorm', $row['prescription_rxnorm_drugcode'] ?? null, ['rxnorm_drugcode']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription start date', $this->dateValue($row, ['prescription_start_date']), ['start_date']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription end date', $this->dateValue($row, ['prescription_end_date']), ['end_date']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'filled date', $this->dateValue($row, ['prescription_filled_date']), ['filled_date']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'prescription dosage instructions',
            $this->boundedText($row['prescription_drug_dosage_instructions'] ?? null, 220),
            ['drug_dosage_instructions']
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'dosage', $row['prescription_dosage'] ?? null, ['dosage']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'quantity', $row['prescription_quantity'] ?? null, ['quantity']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'size', $row['prescription_size'] ?? null, ['size']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'unit', $row['prescription_unit'] ?? null, ['unit']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'route', $row['prescription_route'] ?? null, ['route']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'interval', $row['prescription_interval'] ?? null, ['interval']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'form', $row['prescription_form'] ?? null, ['form']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'substitute', $this->yesNoValue($row['prescription_substitute'] ?? null), ['substitute']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'refills', $row['prescription_refills'] ?? null, ['refills']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'per refill', $row['prescription_per_refill'] ?? null, ['per_refill']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prn', $row['prescription_prn'] ?? null, ['prn']);
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'prescription usage category',
            $this->codedOptionLabel($row['prescription_usage_category'] ?? null, $row['prescription_usage_category_title'] ?? null),
            $this->filledAliasFields($row, [
                'prescription_usage_category' => 'usage_category',
                'prescription_usage_category_title' => 'usage_category_title',
            ])
        );
        $this->addDisplayPart(
            $displayParts,
            $fieldsUsed,
            'prescription request intent',
            $this->codedOptionLabel($row['prescription_request_intent'] ?? null, $row['prescription_request_intent_title'] ?? null),
            $this->filledAliasFields($row, [
                'prescription_request_intent' => 'request_intent',
                'prescription_request_intent_title' => 'request_intent_title',
            ])
        );
        $this->addDisplayPart($displayParts, $fieldsUsed, 'indication', $this->boundedText($row['prescription_indication'] ?? null, 180), ['indication']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'diagnosis', $this->boundedText($row['prescription_diagnosis'] ?? null, 180), ['diagnosis']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription note', $this->boundedText($row['prescription_note'] ?? null, 180), ['note']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription eRx source', $this->erxSourceLabel($row['prescription_erx_source'] ?? null), ['erx_source']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescription eRx uploaded', $this->yesNoValue($row['prescription_erx_uploaded'] ?? null), ['erx_uploaded']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'external prescription id', $row['prescription_external_id'] ?? null, ['external_id']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'prescribing provider id', $this->nonZeroValue($row['prescription_provider_id'] ?? null), ['provider_id']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'filled by id', $this->nonZeroValue($row['prescription_filled_by_id'] ?? null), ['filled_by_id']);
        $this->addDisplayPart($displayParts, $fieldsUsed, 'pharmacy id', $this->nonZeroValue($row['prescription_pharmacy_id'] ?? null), ['pharmacy_id']);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $aliasesToFields
     * @return list<string>
     */
    private function filledAliasFields(array $row, array $aliasesToFields): array
    {
        $fields = [];
        foreach ($aliasesToFields as $alias => $field) {
            if ($this->filled($row[$alias] ?? null) !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function medicationListStatus(array $row): string
    {
        return ((string) ($row['activity'] ?? '') === '1') ? 'active' : 'unknown';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function prescriptionStatus(array $row): string
    {
        if ((string) ($row['prescription_active'] ?? '') === '0') {
            return 'inactive';
        }

        $endDate = $this->dateValue($row, ['prescription_end_date']);
        if ($endDate !== null && $this->dateIsPast($endDate)) {
            return 'inactive';
        }

        return ((string) ($row['prescription_active'] ?? '') === '1') ? 'active' : 'unknown';
    }

    private function dateIsPast(string $date): bool
    {
        try {
            return new DateTimeImmutable($date) < new DateTimeImmutable('today');
        } catch (Throwable) {
            return false;
        }
    }

    private function erxSourceLabel(mixed $value): string
    {
        return match ($this->filled($value)) {
            '0' => 'OpenEMR',
            '1' => 'external/eRx',
            default => $this->filled($value),
        };
    }

    private function boundedText(mixed $value, int $limit): string
    {
        $text = $this->filled($value);
        if ($text === '' || strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
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

    private function displayWithCapitalizedProperties(string $display): string
    {
        $segments = explode('; ', $display);
        foreach ($segments as $index => $segment) {
            $segments[$index] = preg_replace_callback(
                '/\A([a-z])([^:]{0,80}:)/',
                static fn (array $matches): string => strtoupper($matches[1]) . $matches[2],
                $segment,
                1
            ) ?? $segment;
        }

        return implode('; ', $segments);
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
