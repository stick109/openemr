<?php

/**
 * LabDispatchResult
 *
 * Typed return value from {@see LabPdfDispatcher::dispatch()}. Carries the
 * primary keys of the rows persisted into the OpenEMR procedure tables so
 * downstream code (e.g. the citation persister in S17) can link extracted
 * citations back to the rows they describe.
 *
 * The `created` flag distinguishes a fresh insert from an idempotent re-use
 * of a previously dispatched lab order keyed by `traceId`.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Dispatcher;

final readonly class LabDispatchResult
{
    /**
     * @param int          $procedureOrderId  Primary id of the inserted (or pre-existing) procedure_order row.
     * @param int          $procedureReportId Primary id of the procedure_report row tied to the order.
     * @param list<int>    $procedureResultIds Primary ids of every procedure_result row for this report, in
     *                                         the same order as the input results.
     * @param string       $traceId           Sidecar trace id this dispatch was keyed by.
     * @param bool         $created           True when this call inserted new rows, false when an existing
     *                                        order keyed by traceId was returned untouched.
     */
    public function __construct(
        public int $procedureOrderId,
        public int $procedureReportId,
        public array $procedureResultIds,
        public string $traceId,
        public bool $created,
    ) {
    }
}
