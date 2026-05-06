<?php

/**
 * LabPdfDispatcher
 *
 * Persists a `LabPdf` extraction (from the Python agent-service sidecar)
 * into the canonical OpenEMR procedure tables:
 *
 *  - one `procedure_order` row representing the lab panel/order,
 *  - one `procedure_report` row tied to that order, and
 *  - one `procedure_result` row per extracted lab test.
 *
 * The dispatcher is idempotent on the sidecar trace id: re-dispatching the
 * same trace id (e.g. on a form retry) will return the existing row IDs
 * without writing duplicate rows. The trace id is recorded in
 * `procedure_order.control_id`, a 255-char column documented as "CONTROL ID
 * sent back from lab" — semantically the closest existing field.
 *
 * On any SQL failure the partial transaction state is the responsibility of
 * the caller: the dispatcher throws {@see IngestionFailedException} so the
 * upload form can surface a generic error instead of leaving the user with
 * a half-written encounter.
 *
 * Citation persistence (linking each result to its source page/bbox) is
 * handled by a separate dispatcher (S17) which reads the IDs returned in
 * {@see LabDispatchResult}.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Dispatcher;

use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class LabPdfDispatcher
{
    private const ORDER_STATUS_COMPLETE = 'complete';
    private const REPORT_STATUS_COMPLETE = 'complete';
    private const REVIEW_STATUS_RECEIVED = 'received';
    private const RESULT_STATUS_FINAL = 'final';
    private const RESULT_DATA_TYPE_STRING = 'S';

    /**
     * Map sidecar `AbnormalFlag` enum values (snake_case strings on the wire)
     * to HL7-style codes that downstream OpenEMR UI displays as colour tags.
     * The keys mirror {@see agent_service.schemas.lab_pdf.AbnormalFlag}.
     */
    private const ABNORMAL_FLAG_MAP = [
        'normal' => 'N',
        'high' => 'H',
        'low' => 'L',
        'critical_high' => 'HH',
        'critical_low' => 'LL',
        'abnormal' => 'A',
    ];

    public function __construct(
        private readonly SqlExecutor $sql,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Persist the extracted `LabPdf` payload. Re-running with the same
     * `traceId` returns the existing row IDs without writing duplicates.
     *
     * @param int                   $patientId   OpenEMR patient pid.
     * @param int                   $encounterId form_encounter.encounter id.
     * @param array<string, mixed>  $extracted   Sidecar `LabPdf` payload.
     * @param string                $traceId     UUID-shaped trace id used as the idempotency key.
     *
     * @throws IngestionFailedException When the payload is invalid or a SQL write fails.
     */
    public function dispatch(int $patientId, int $encounterId, array $extracted, string $traceId): LabDispatchResult
    {
        if ($patientId <= 0) {
            throw new IngestionFailedException('Lab dispatch requires a positive patient id.');
        }
        if ($encounterId <= 0) {
            throw new IngestionFailedException('Lab dispatch requires a positive encounter id.');
        }
        if ($traceId === '') {
            throw new IngestionFailedException('Lab dispatch requires a non-empty trace id.');
        }

        $results = $this->parseResults($extracted);
        if ($results === []) {
            throw new IngestionFailedException('Lab dispatch requires at least one result row.');
        }

        try {
            $existing = $this->findExistingOrderByTraceId($traceId);
        } catch (Throwable $exception) {
            throw new IngestionFailedException(
                'Failed to look up existing lab order by trace id.',
                $exception,
            );
        }

        if ($existing !== null) {
            $this->logger->info('Lab dispatch idempotent re-use', [
                'trace_id' => $traceId,
                'procedure_order_id' => $existing->procedureOrderId,
                'procedure_report_id' => $existing->procedureReportId,
                'result_count' => count($existing->procedureResultIds),
            ]);

            return new LabDispatchResult(
                procedureOrderId: $existing->procedureOrderId,
                procedureReportId: $existing->procedureReportId,
                procedureResultIds: $existing->procedureResultIds,
                traceId: $traceId,
                created: false,
            );
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $earliestCollectionDate = $this->earliestCollectionDate($results) ?? $now;
        $labName = $this->scalarString($extracted, 'lab_name');

        try {
            $procedureOrderId = $this->insertProcedureOrder(
                patientId: $patientId,
                encounterId: $encounterId,
                traceId: $traceId,
                dateCollected: $earliestCollectionDate,
                dateOrdered: $now,
                clinicalHistory: $labName ?? '',
            );

            $procedureReportId = $this->insertProcedureReport(
                procedureOrderId: $procedureOrderId,
                dateCollected: $earliestCollectionDate,
                dateReport: $now,
                reportNotes: $labName !== null ? 'Lab: ' . $labName : '',
            );

            $procedureResultIds = [];
            foreach ($results as $row) {
                $procedureResultIds[] = $this->insertProcedureResult(
                    procedureReportId: $procedureReportId,
                    result: $row,
                );
            }
        } catch (IngestionFailedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new IngestionFailedException(
                'Failed to persist lab dispatch rows.',
                $exception,
            );
        }

        $this->logger->info('Lab dispatch persisted', [
            'trace_id' => $traceId,
            'procedure_order_id' => $procedureOrderId,
            'procedure_report_id' => $procedureReportId,
            'result_count' => count($procedureResultIds),
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
        ]);

        return new LabDispatchResult(
            procedureOrderId: $procedureOrderId,
            procedureReportId: $procedureReportId,
            procedureResultIds: $procedureResultIds,
            traceId: $traceId,
            created: true,
        );
    }

    // ------------------------------------------------------------------
    // Idempotency lookup
    // ------------------------------------------------------------------

    private function findExistingOrderByTraceId(string $traceId): ?LabDispatchResult
    {
        $orderRow = $this->sql->fetchOne(
            'SELECT `procedure_order_id` FROM `procedure_order` WHERE `control_id` = ? LIMIT 1',
            [$traceId],
        );
        if ($orderRow === null) {
            return null;
        }

        $procedureOrderId = $this->intColumn($orderRow, 'procedure_order_id');
        if ($procedureOrderId <= 0) {
            return null;
        }

        $reportRow = $this->sql->fetchOne(
            'SELECT `procedure_report_id` FROM `procedure_report`
             WHERE `procedure_order_id` = ? ORDER BY `procedure_report_id` ASC LIMIT 1',
            [$procedureOrderId],
        );
        if ($reportRow === null) {
            // Order exists but report did not get written — treat as
            // non-existent so the caller retries the full dispatch.
            return null;
        }

        $procedureReportId = $this->intColumn($reportRow, 'procedure_report_id');
        if ($procedureReportId <= 0) {
            return null;
        }

        $resultIds = $this->fetchResultIds($procedureReportId);

        return new LabDispatchResult(
            procedureOrderId: $procedureOrderId,
            procedureReportId: $procedureReportId,
            procedureResultIds: $resultIds,
            traceId: $traceId,
            created: false,
        );
    }

    /**
     * @return list<int>
     */
    private function fetchResultIds(int $procedureReportId): array
    {
        $ids = [];
        $offset = 0;
        // Pull rows one-by-one so the SqlExecutor surface stays minimal
        // (no streaming / cursor support required from adapters).
        while (true) {
            $row = $this->sql->fetchOne(
                'SELECT `procedure_result_id` FROM `procedure_result`
                 WHERE `procedure_report_id` = ?
                 ORDER BY `procedure_result_id` ASC
                 LIMIT 1 OFFSET ' . $offset,
                [$procedureReportId],
            );
            if ($row === null) {
                break;
            }
            $id = $this->intColumn($row, 'procedure_result_id');
            if ($id <= 0) {
                break;
            }
            $ids[] = $id;
            $offset++;
            if ($offset > 1000) {
                // Safety guard: a single lab panel should never exceed this.
                break;
            }
        }

        return $ids;
    }

    // ------------------------------------------------------------------
    // Inserts
    // ------------------------------------------------------------------

    private function insertProcedureOrder(
        int $patientId,
        int $encounterId,
        string $traceId,
        string $dateCollected,
        string $dateOrdered,
        string $clinicalHistory,
    ): int {
        return $this->sql->insert(
            'INSERT INTO `procedure_order`
                (`provider_id`, `patient_id`, `encounter_id`,
                 `date_collected`, `date_ordered`,
                 `order_status`, `activity`, `control_id`,
                 `clinical_hx`, `procedure_order_type`, `order_intent`)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [
                0,
                $patientId,
                $encounterId,
                $dateCollected,
                $dateOrdered,
                self::ORDER_STATUS_COMPLETE,
                $traceId,
                $clinicalHistory,
                'laboratory_test',
                'order',
            ],
        );
    }

    private function insertProcedureReport(
        int $procedureOrderId,
        string $dateCollected,
        string $dateReport,
        string $reportNotes,
    ): int {
        return $this->sql->insert(
            'INSERT INTO `procedure_report`
                (`procedure_order_id`, `procedure_order_seq`, `date_collected`, `date_report`,
                 `source`, `report_status`, `review_status`, `report_notes`)
             VALUES (?, 1, ?, ?, 0, ?, ?, ?)',
            [
                $procedureOrderId,
                $dateCollected,
                $dateReport,
                self::REPORT_STATUS_COMPLETE,
                self::REVIEW_STATUS_RECEIVED,
                $reportNotes,
            ],
        );
    }

    private function insertProcedureResult(int $procedureReportId, LabResultRow $result): int
    {
        return $this->sql->insert(
            'INSERT INTO `procedure_result`
                (`procedure_report_id`, `result_data_type`, `result_code`,
                 `result_text`, `date`, `units`, `result`, `range`,
                 `abnormal`, `result_status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $procedureReportId,
                self::RESULT_DATA_TYPE_STRING,
                $result->loincCode,
                $result->testName,
                $result->collectionDate,
                $result->unit,
                $result->value,
                $result->referenceRange,
                $result->abnormalFlag,
                self::RESULT_STATUS_FINAL,
            ],
        );
    }

    // ------------------------------------------------------------------
    // Payload parsing
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extracted
     * @return list<LabResultRow>
     */
    private function parseResults(array $extracted): array
    {
        $rawResults = $extracted['results'] ?? null;
        if (!is_array($rawResults)) {
            return [];
        }

        $rows = [];
        foreach ($rawResults as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $testName = $this->scalarString($raw, 'test_name');
            $value = $this->scalarString($raw, 'value');
            $unit = $this->scalarString($raw, 'unit');
            $range = $this->scalarString($raw, 'reference_range');
            $collectionDate = $this->scalarString($raw, 'collection_date');
            $abnormalRaw = $this->scalarString($raw, 'abnormal_flag') ?? 'normal';
            $loincCode = $this->scalarString($raw, 'loinc_code') ?? '';

            if ($testName === null || $value === null) {
                // Skip rows missing the two truly mandatory fields. Sidecar's
                // pydantic schema enforces min_length=1 already, so this is a
                // defensive guard against malformed manual payloads.
                continue;
            }

            $rows[] = new LabResultRow(
                testName: $testName,
                value: $value,
                unit: $unit ?? '',
                referenceRange: $range ?? '',
                collectionDate: $this->normaliseDate($collectionDate),
                abnormalFlag: $this->mapAbnormalFlag($abnormalRaw),
                loincCode: $loincCode,
            );
        }

        return $rows;
    }

    private function mapAbnormalFlag(string $raw): string
    {
        $normalised = strtolower(trim($raw));

        return self::ABNORMAL_FLAG_MAP[$normalised] ?? self::ABNORMAL_FLAG_MAP['normal'];
    }

    private function normaliseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param list<LabResultRow> $results
     */
    private function earliestCollectionDate(array $results): ?string
    {
        $earliest = null;
        foreach ($results as $row) {
            if ($row->collectionDate === null) {
                continue;
            }
            if ($earliest === null || strcmp($row->collectionDate, $earliest) < 0) {
                $earliest = $row->collectionDate;
            }
        }

        return $earliest;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<string, mixed>|array<array-key, mixed> $bag
     */
    private function scalarString(array $bag, string $key): ?string
    {
        $value = $bag[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
