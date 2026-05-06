<?php

/**
 * PdfBboxCitation
 *
 * Read-only DTO for a `pdf_bbox` row from form_upload_intake_form_citation.
 * Pairs an extracted field with a 1-based page number and a [x0, y0, x1, y1]
 * bounding box in PDF points (as produced by the agent-service sidecar — see
 * agent-service/agent_service/schemas/citation.py).
 *
 * The bbox uses the standard PDF coordinate space (origin at the bottom-left
 * of the page, units in points); UI consumers must flip the Y axis to render
 * over a top-left rasterised viewport.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Citation;

final readonly class PdfBboxCitation
{
    public function __construct(
        public int $id,
        public int $formId,
        public ?string $fieldName,
        public int $page,
        public float $bboxX0,
        public float $bboxY0,
        public float $bboxX1,
        public float $bboxY1,
    ) {
    }
}
