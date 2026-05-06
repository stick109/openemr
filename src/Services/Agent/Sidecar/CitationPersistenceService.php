<?php

/**
 * CitationPersistenceService
 *
 * Persists Citation objects returned by the Python agent-service sidecar
 * into the `form_upload_intake_form_citation` table. The wire-format
 * citation array is a discriminated union keyed on `source_type` — see
 * agent-service/CONTRACT.md and agent-service/agent_service/schemas/api.py
 * for the canonical shape, and src/Services/Agent/Sidecar/AgentRunResult.php
 * for the PHP-side DTO that carries it across the network boundary.
 *
 * The persist operation runs inside a single transaction so a partially
 * applied citation set never leaves the database in an inconsistent state.
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
use Psr\Log\LoggerInterface;

class CitationPersistenceService
{
    public const TABLE_NAME = 'form_upload_intake_form_citation';

    private const SOURCE_TYPE_PDF_BBOX = 'pdf_bbox';
    private const SOURCE_TYPE_GUIDELINE = 'guideline';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Persist a list of Citation objects (in wire format) for the given
     * form_upload_intake_form row. An empty list is a no-op.
     *
     * The whole batch runs in a single transaction. If any citation in the
     * batch fails validation or insertion, every row inserted by this call
     * is rolled back and the original exception is re-thrown.
     *
     * @param int                        $formId    PK on form_upload_intake_form.
     * @param list<array<string, mixed>> $citations Citation objects from
     *                                              {@see AgentRunResult::$citations}.
     *
     * @throws \InvalidArgumentException When a citation has an unknown or
     *                                    missing `source_type`, or when
     *                                    required discriminator-specific
     *                                    fields are missing or wrongly typed.
     * @throws \DomainException           When `$formId` is not positive.
     * @throws \Throwable                 Any exception from the underlying
     *                                    DB layer is re-thrown after rollback.
     */
    public function persist(int $formId, array $citations): void
    {
        if ($formId <= 0) {
            throw new \DomainException('form_id must be a positive integer.');
        }

        if ($citations === []) {
            return;
        }

        // Validate everything up-front so rollback does not happen mid-batch.
        $rows = [];
        foreach ($citations as $index => $citation) {
            $rows[] = $this->buildRow($formId, $citation, $index);
        }

        $this->runInTransaction(function () use ($rows, $formId): void {
            foreach ($rows as $row) {
                $this->executeInsert($row);
            }

            $this->logger->info(
                'Persisted citations for upload_intake_form row.',
                [
                    'form_id' => $formId,
                    'count' => count($rows),
                ],
            );
        });
    }

    /**
     * Build the parameter row for a single citation.
     *
     * @param array<string, mixed> $citation
     *
     * @return array<string, int|string|float|null>
     */
    private function buildRow(int $formId, array $citation, int $index): array
    {
        $sourceType = $citation['source_type'] ?? null;
        if (!is_string($sourceType) || $sourceType === '') {
            throw new \InvalidArgumentException(sprintf(
                'Citation at index %d is missing required field "source_type".',
                $index,
            ));
        }

        return match ($sourceType) {
            self::SOURCE_TYPE_PDF_BBOX => $this->buildPdfBboxRow($formId, $citation, $index),
            self::SOURCE_TYPE_GUIDELINE => $this->buildGuidelineRow($formId, $citation, $index),
            default => throw new \InvalidArgumentException(sprintf(
                'Citation at index %d has unsupported source_type %s.',
                $index,
                var_export($sourceType, true),
            )),
        };
    }

    /**
     * @param array<string, mixed> $citation
     *
     * @return array<string, int|string|float|null>
     */
    private function buildPdfBboxRow(int $formId, array $citation, int $index): array
    {
        $page = $citation['page'] ?? null;
        if (!is_int($page) || $page < 1) {
            throw new \InvalidArgumentException(sprintf(
                'pdf_bbox citation at index %d has missing or invalid "page".',
                $index,
            ));
        }

        $bbox = $citation['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4) {
            throw new \InvalidArgumentException(sprintf(
                'pdf_bbox citation at index %d must have exactly 4 bbox coordinates.',
                $index,
            ));
        }

        // Index-based access tolerates both lists and dict-shaped JSON.
        $coords = array_values($bbox);
        foreach ($coords as $position => $coord) {
            if (!is_int($coord) && !is_float($coord)) {
                throw new \InvalidArgumentException(sprintf(
                    'pdf_bbox citation at index %d has non-numeric bbox[%d].',
                    $index,
                    $position,
                ));
            }
        }

        $fieldName = $citation['field_name'] ?? null;
        if ($fieldName !== null && !is_string($fieldName)) {
            throw new \InvalidArgumentException(sprintf(
                'pdf_bbox citation at index %d has non-string "field_name".',
                $index,
            ));
        }

        return [
            'form_id' => $formId,
            'source_type' => self::SOURCE_TYPE_PDF_BBOX,
            'field_name' => $fieldName,
            'page' => $page,
            'bbox_x0' => (float) $coords[0],
            'bbox_y0' => (float) $coords[1],
            'bbox_x1' => (float) $coords[2],
            'bbox_y1' => (float) $coords[3],
            'chunk_id' => null,
            'source_url' => null,
            'snippet' => null,
            'section' => null,
        ];
    }

    /**
     * @param array<string, mixed> $citation
     *
     * @return array<string, int|string|float|null>
     */
    private function buildGuidelineRow(int $formId, array $citation, int $index): array
    {
        $chunkId = $citation['chunk_id'] ?? null;
        if (!is_string($chunkId) || $chunkId === '') {
            throw new \InvalidArgumentException(sprintf(
                'guideline citation at index %d has missing or invalid "chunk_id".',
                $index,
            ));
        }

        $sourceUrl = $citation['source_url'] ?? null;
        if (!is_string($sourceUrl) || $sourceUrl === '') {
            throw new \InvalidArgumentException(sprintf(
                'guideline citation at index %d has missing or invalid "source_url".',
                $index,
            ));
        }

        $snippet = $citation['snippet'] ?? null;
        if (!is_string($snippet) || $snippet === '') {
            throw new \InvalidArgumentException(sprintf(
                'guideline citation at index %d has missing or invalid "snippet".',
                $index,
            ));
        }

        $section = $citation['section'] ?? null;
        if ($section !== null && !is_string($section)) {
            throw new \InvalidArgumentException(sprintf(
                'guideline citation at index %d has non-string "section".',
                $index,
            ));
        }

        return [
            'form_id' => $formId,
            'source_type' => self::SOURCE_TYPE_GUIDELINE,
            'field_name' => null,
            'page' => null,
            'bbox_x0' => null,
            'bbox_y0' => null,
            'bbox_x1' => null,
            'bbox_y1' => null,
            'chunk_id' => $chunkId,
            'source_url' => $sourceUrl,
            'snippet' => $snippet,
            'section' => $section,
        ];
    }

    /**
     * Execute the INSERT for a single row. Extracted so isolated tests can
     * subclass and stub the database interaction without spinning up a
     * real connection (mirrors the pattern used by AuditLogPurgeService).
     *
     * @param array<string, int|string|float|null> $row
     */
    protected function executeInsert(array $row): void
    {
        QueryUtils::sqlInsert(
            'INSERT INTO `' . self::TABLE_NAME . '`
                (`form_id`, `source_type`, `field_name`, `page`,
                 `bbox_x0`, `bbox_y0`, `bbox_x1`, `bbox_y1`,
                 `chunk_id`, `source_url`, `snippet`, `section`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['form_id'],
                $row['source_type'],
                $row['field_name'],
                $row['page'],
                $row['bbox_x0'],
                $row['bbox_y0'],
                $row['bbox_x1'],
                $row['bbox_y1'],
                $row['chunk_id'],
                $row['source_url'],
                $row['snippet'],
                $row['section'],
            ],
        );
    }

    /**
     * Run $action inside a database transaction. Extracted as a hook so
     * isolated tests can verify transactional semantics without a live
     * MySQL connection.
     *
     * @param callable(): void $action
     */
    protected function runInTransaction(callable $action): void
    {
        QueryUtils::inTransaction(function () use ($action): bool {
            $action();
            // QueryUtils::inTransaction is generic over the callback return
            // type; return a sentinel so its template parameter is bound.
            return true;
        });
    }
}
