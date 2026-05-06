<?php

/**
 * CitationCollection
 *
 * Read-only value object that groups citations by source type. Returned by
 * {@see \OpenEMR\Services\Agent\Sidecar\CitationReader} so callers can
 * iterate pdf_bbox rows independently of guideline rows without scanning
 * the full set twice.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Citation;

final readonly class CitationCollection
{
    /**
     * @param list<PdfBboxCitation>   $pdfBboxCitations Bounding-box pointers to extracted fields.
     * @param list<GuidelineCitation> $guidelineCitations Guideline references with snippets.
     */
    public function __construct(
        public array $pdfBboxCitations,
        public array $guidelineCitations,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return $this->pdfBboxCitations === [] && $this->guidelineCitations === [];
    }

    public function count(): int
    {
        return count($this->pdfBboxCitations) + count($this->guidelineCitations);
    }
}
