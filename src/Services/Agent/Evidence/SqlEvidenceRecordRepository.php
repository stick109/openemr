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
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchBasicPatientData(int $pid, EvidenceCaps $caps): array
    {
        $row = sqlQuery(
            "SELECT pid, uuid, DOB, sex, status, date
             FROM patient_data
             WHERE pid = ?
             LIMIT 1",
            [$pid]
        );

        if (!is_array($row) || $row === []) {
            return [];
        }

        return [$this->mapPatientRecord($row)];
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
        $displayParts = [
            $this->filled($row['sex'] ?? null) !== '' ? 'sex: ' . $this->filled($row['sex']) : '',
            $this->ageFromDob($row['DOB'] ?? null),
            $this->filled($row['status'] ?? null) !== '' ? 'status: ' . $this->filled($row['status']) : '',
        ];

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
            'fields_used' => ['DOB', 'sex', 'status'],
            'reliability' => 'structured_patient_record',
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
