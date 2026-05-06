<?php

/**
 * CitationReader
 *
 * Read-side counterpart to {@see CitationPersistenceService}. Returns the
 * citations recorded for one form_upload_intake_form row as typed DTOs:
 *
 *  - `pdf_bbox` rows become {@see Citation\PdfBboxCitation}
 *  - `guideline` rows become {@see Citation\GuidelineCitation}
 *
 * Used by interface/forms/upload_intake_form/view.php to render the
 * click-to-source overlay and side panel (S18).
 *
 * Rows that violate the table's structural contract (e.g. a pdf_bbox row
 * with NULL page) are dropped rather than thrown — the view should always
 * render *something*. The drop is logged so an operator can investigate.
 *
 * Database access is isolated to {@see CitationReader::fetchRows()}, an
 * overridable hook that mirrors the executeInsert/runInTransaction pattern
 * on {@see CitationPersistenceService} so isolated tests can exercise the
 * mapper without a live MySQL connection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Agent\Sidecar\Citation\CitationCollection;
use OpenEMR\Services\Agent\Sidecar\Citation\GuidelineCitation;
use OpenEMR\Services\Agent\Sidecar\Citation\PdfBboxCitation;
use Psr\Log\LoggerInterface;

class CitationReader
{
    public const TABLE_NAME = CitationPersistenceService::TABLE_NAME;

    private const SOURCE_TYPE_PDF_BBOX = 'pdf_bbox';
    private const SOURCE_TYPE_GUIDELINE = 'guideline';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch every citation associated with a form_upload_intake_form row.
     *
     * @throws \DomainException When $formId is not positive.
     */
    public function readByFormId(int $formId): CitationCollection
    {
        if ($formId <= 0) {
            throw new \DomainException('form_id must be a positive integer.');
        }

        $rows = $this->fetchRows($formId);
        if ($rows === []) {
            return CitationCollection::empty();
        }

        $pdfBboxCitations = [];
        $guidelineCitations = [];

        foreach ($rows as $index => $row) {
            $sourceType = $row['source_type'] ?? null;
            if (!is_string($sourceType)) {
                $this->logger->warning('Citation row has non-string source_type; skipping.', [
                    'form_id' => $formId,
                    'row_index' => $index,
                ]);
                continue;
            }

            $citation = match ($sourceType) {
                self::SOURCE_TYPE_PDF_BBOX => $this->mapPdfBboxRow($row, $formId, $index),
                self::SOURCE_TYPE_GUIDELINE => $this->mapGuidelineRow($row, $formId, $index),
                default => null,
            };

            if ($citation === null) {
                continue;
            }

            if ($citation instanceof PdfBboxCitation) {
                $pdfBboxCitations[] = $citation;
            } else {
                $guidelineCitations[] = $citation;
            }
        }

        return new CitationCollection($pdfBboxCitations, $guidelineCitations);
    }

    /**
     * Fetch raw citation rows for $formId. Extracted as a hook so isolated
     * tests can supply fixture rows without spinning up a database.
     *
     * The wider `array<mixed>` row shape mirrors {@see QueryUtils::fetchRecords}
     * — narrowing to a string-keyed shape happens inside the mapPdfBboxRow /
     * mapGuidelineRow methods which only consume specific column names.
     *
     * @return list<array<mixed>>
     */
    protected function fetchRows(int $formId): array
    {
        // The id ordering keeps citations in their persistence order, which
        // matches the order the sidecar emitted them; downstream UI relies
        // on that to associate fields with the same field_name to the
        // correct chronological occurrence on the PDF.
        return QueryUtils::fetchRecords(
            'SELECT `id`, `form_id`, `source_type`, `field_name`, `page`,
                    `bbox_x0`, `bbox_y0`, `bbox_x1`, `bbox_y1`,
                    `chunk_id`, `source_url`, `snippet`, `section`
             FROM `' . self::TABLE_NAME . '`
             WHERE `form_id` = ?
             ORDER BY `id` ASC',
            [$formId],
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function mapPdfBboxRow(array $row, int $formId, int|string $index): ?PdfBboxCitation
    {
        $id = $this->intColumn($row, 'id');
        $page = $this->intColumn($row, 'page');
        if ($id <= 0 || $page < 1) {
            $this->logDropped('pdf_bbox missing id/page', $formId, $index, $row);
            return null;
        }

        $x0 = $this->floatColumnOrNull($row, 'bbox_x0');
        $y0 = $this->floatColumnOrNull($row, 'bbox_y0');
        $x1 = $this->floatColumnOrNull($row, 'bbox_x1');
        $y1 = $this->floatColumnOrNull($row, 'bbox_y1');
        if ($x0 === null || $y0 === null || $x1 === null || $y1 === null) {
            $this->logDropped('pdf_bbox missing bbox coordinates', $formId, $index, $row);
            return null;
        }

        $fieldName = $this->stringColumnOrNull($row, 'field_name');

        return new PdfBboxCitation(
            id: $id,
            formId: $formId,
            fieldName: $fieldName,
            page: $page,
            bboxX0: $x0,
            bboxY0: $y0,
            bboxX1: $x1,
            bboxY1: $y1,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function mapGuidelineRow(array $row, int $formId, int|string $index): ?GuidelineCitation
    {
        $id = $this->intColumn($row, 'id');
        $chunkId = $this->stringColumnOrNull($row, 'chunk_id');
        $sourceUrl = $this->stringColumnOrNull($row, 'source_url');
        $snippet = $this->stringColumnOrNull($row, 'snippet');
        if ($id <= 0 || $chunkId === null || $sourceUrl === null || $snippet === null) {
            $this->logDropped('guideline row missing chunk_id/source_url/snippet', $formId, $index, $row);
            return null;
        }

        return new GuidelineCitation(
            id: $id,
            formId: $formId,
            chunkId: $chunkId,
            sourceUrl: $sourceUrl,
            snippet: $snippet,
            section: $this->stringColumnOrNull($row, 'section'),
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<mixed> $row
     */
    private function floatColumnOrNull(array $row, string $column): ?float
    {
        $value = $row[$column] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<mixed> $row
     */
    private function stringColumnOrNull(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param array<mixed> $row
     */
    private function logDropped(string $reason, int $formId, int|string $index, array $row): void
    {
        $this->logger->warning('Citation row dropped; skipping.', [
            'reason' => $reason,
            'form_id' => $formId,
            'row_index' => $index,
            'row_id' => $row['id'] ?? null,
            'source_type' => $row['source_type'] ?? null,
        ]);
    }
}
