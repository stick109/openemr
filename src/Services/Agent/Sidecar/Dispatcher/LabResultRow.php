<?php

/**
 * LabResultRow
 *
 * Internal value object for a single parsed lab result row passed between
 * the parser and the inserter inside {@see LabPdfDispatcher}. Mirrors the
 * fields from `agent_service.schemas.lab_pdf.LabResult` after mapping the
 * abnormal flag enum to its HL7-style code.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Dispatcher;

final readonly class LabResultRow
{
    public function __construct(
        public string $testName,
        public string $value,
        public string $unit,
        public string $referenceRange,
        public ?string $collectionDate,
        public string $abnormalFlag,
        public string $loincCode,
    ) {
    }
}
