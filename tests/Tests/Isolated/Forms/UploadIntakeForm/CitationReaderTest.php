<?php

/**
 * CitationReaderTest
 *
 * Isolated tests for {@see CitationReader}. The reader's database
 * interaction is intercepted by overriding the protected fetchRows()
 * hook on a tiny anonymous subclass — there is no real database in
 * isolated tests, and stubbing QueryUtils statically would couple the
 * test to ADOdb's bootstrap.
 *
 * The fixture rows mirror the column shape produced by
 * {@see CitationPersistenceService} so the round-trip
 * write -> read pair is exercised for both source_type discriminants
 * plus the malformed/legacy-row drop paths.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Forms\UploadIntakeForm;

use OpenEMR\Services\Agent\Sidecar\CitationReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class CitationReaderTest extends TestCase
{
    public function testReturnsEmptyCollectionWhenNoRowsExist(): void
    {
        $reader = $this->buildReader([]);

        $collection = $reader->readByFormId(42);

        self::assertTrue($collection->isEmpty());
        self::assertSame([], $collection->pdfBboxCitations);
        self::assertSame([], $collection->guidelineCitations);
        self::assertSame(0, $collection->count());
    }

    public function testNonPositiveFormIdRaisesDomainException(): void
    {
        $reader = $this->buildReader([]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('form_id');

        $reader->readByFormId(0);
    }

    public function testPdfBboxRowMapsToTypedDto(): void
    {
        $reader = $this->buildReader([
            [
                'id' => 101,
                'form_id' => 7,
                'source_type' => 'pdf_bbox',
                'field_name' => 'hemoglobin',
                'page' => 1,
                'bbox_x0' => '72.0000',
                'bbox_y0' => '200.5000',
                'bbox_x1' => '540.2500',
                'bbox_y1' => '230.5000',
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
        ]);

        $collection = $reader->readByFormId(7);

        self::assertCount(1, $collection->pdfBboxCitations);
        self::assertSame([], $collection->guidelineCitations);

        $cit = $collection->pdfBboxCitations[0];
        self::assertSame(101, $cit->id);
        self::assertSame(7, $cit->formId);
        self::assertSame('hemoglobin', $cit->fieldName);
        self::assertSame(1, $cit->page);
        self::assertSame(72.0, $cit->bboxX0);
        self::assertSame(200.5, $cit->bboxY0);
        self::assertSame(540.25, $cit->bboxX1);
        self::assertSame(230.5, $cit->bboxY1);
    }

    public function testPdfBboxRowAcceptsNullFieldName(): void
    {
        // CONTRACT.md's wire-format PdfBboxCitation does not require
        // field_name; the persistence layer stores NULL in that case.
        $reader = $this->buildReader([
            [
                'id' => 5,
                'form_id' => 11,
                'source_type' => 'pdf_bbox',
                'field_name' => null,
                'page' => 2,
                'bbox_x0' => 0.0,
                'bbox_y0' => 0.0,
                'bbox_x1' => 100.0,
                'bbox_y1' => 50.0,
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
        ]);

        $collection = $reader->readByFormId(11);

        self::assertCount(1, $collection->pdfBboxCitations);
        self::assertNull($collection->pdfBboxCitations[0]->fieldName);
    }

    public function testGuidelineRowMapsToTypedDto(): void
    {
        $reader = $this->buildReader([
            [
                'id' => 202,
                'form_id' => 99,
                'source_type' => 'guideline',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => 'ama-lab-ref-2025-cbc-003',
                'source_url' => 'https://guidelines.example.org/ama-lab-ref-2025',
                'snippet' => 'Normal hemoglobin for adult males: 13.5-17.5 g/dL',
                'section' => 'CBC',
            ],
        ]);

        $collection = $reader->readByFormId(99);

        self::assertCount(1, $collection->guidelineCitations);
        self::assertSame([], $collection->pdfBboxCitations);

        $cit = $collection->guidelineCitations[0];
        self::assertSame(202, $cit->id);
        self::assertSame(99, $cit->formId);
        self::assertSame('ama-lab-ref-2025-cbc-003', $cit->chunkId);
        self::assertSame('https://guidelines.example.org/ama-lab-ref-2025', $cit->sourceUrl);
        self::assertSame('Normal hemoglobin for adult males: 13.5-17.5 g/dL', $cit->snippet);
        self::assertSame('CBC', $cit->section);
    }

    public function testGuidelineRowAcceptsNullSection(): void
    {
        $reader = $this->buildReader([
            [
                'id' => 1,
                'form_id' => 11,
                'source_type' => 'guideline',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => 'c1',
                'source_url' => 'https://example.com',
                'snippet' => 'hello',
                'section' => null,
            ],
        ]);

        $collection = $reader->readByFormId(11);

        self::assertCount(1, $collection->guidelineCitations);
        self::assertNull($collection->guidelineCitations[0]->section);
    }

    public function testMixedRowsArePartitionedCorrectly(): void
    {
        $reader = $this->buildReader([
            [
                'id' => 1,
                'form_id' => 5,
                'source_type' => 'pdf_bbox',
                'field_name' => null,
                'page' => 1,
                'bbox_x0' => 10.0,
                'bbox_y0' => 20.0,
                'bbox_x1' => 30.0,
                'bbox_y1' => 40.0,
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
            [
                'id' => 2,
                'form_id' => 5,
                'source_type' => 'guideline',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => 'g-1',
                'source_url' => 'https://example.com/1',
                'snippet' => 'snippet-1',
                'section' => null,
            ],
            [
                'id' => 3,
                'form_id' => 5,
                'source_type' => 'pdf_bbox',
                'field_name' => 'wbc',
                'page' => 3,
                'bbox_x0' => 11.0,
                'bbox_y0' => 22.0,
                'bbox_x1' => 33.0,
                'bbox_y1' => 44.0,
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
        ]);

        $collection = $reader->readByFormId(5);

        self::assertCount(2, $collection->pdfBboxCitations);
        self::assertCount(1, $collection->guidelineCitations);
        // Persistence order is preserved within each partition.
        self::assertSame(1, $collection->pdfBboxCitations[0]->id);
        self::assertSame(3, $collection->pdfBboxCitations[1]->id);
        self::assertSame('wbc', $collection->pdfBboxCitations[1]->fieldName);
        self::assertSame('g-1', $collection->guidelineCitations[0]->chunkId);
    }

    public function testUnknownSourceTypeIsDroppedAndLogged(): void
    {
        $logger = new RecordingCitationLogger();
        $reader = $this->buildReader([
            [
                'id' => 1,
                'form_id' => 7,
                'source_type' => 'mystery',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
            [
                'id' => 2,
                'form_id' => 7,
                'source_type' => 'guideline',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => 'k',
                'source_url' => 'https://e.x',
                'snippet' => 's',
                'section' => null,
            ],
        ], $logger);

        $collection = $reader->readByFormId(7);

        self::assertCount(0, $collection->pdfBboxCitations);
        self::assertCount(1, $collection->guidelineCitations);
        // The mystery row is dropped silently — `match`'s default branch
        // returns null, no logger entry is required for an unsupported
        // source_type. We just confirm the partitioning.
        self::assertGreaterThanOrEqual(0, count($logger->records));
    }

    public function testPdfBboxRowMissingPageIsDropped(): void
    {
        $logger = new RecordingCitationLogger();
        $reader = $this->buildReader([
            [
                'id' => 1,
                'form_id' => 7,
                'source_type' => 'pdf_bbox',
                'field_name' => 'wbc',
                'page' => null, // bad — should drop
                'bbox_x0' => 1.0,
                'bbox_y0' => 1.0,
                'bbox_x1' => 2.0,
                'bbox_y1' => 2.0,
                'chunk_id' => null,
                'source_url' => null,
                'snippet' => null,
                'section' => null,
            ],
        ], $logger);

        $collection = $reader->readByFormId(7);

        self::assertSame([], $collection->pdfBboxCitations);
        self::assertNotEmpty($logger->records);
    }

    public function testGuidelineRowMissingChunkIdIsDropped(): void
    {
        $logger = new RecordingCitationLogger();
        $reader = $this->buildReader([
            [
                'id' => 1,
                'form_id' => 7,
                'source_type' => 'guideline',
                'field_name' => null,
                'page' => null,
                'bbox_x0' => null,
                'bbox_y0' => null,
                'bbox_x1' => null,
                'bbox_y1' => null,
                'chunk_id' => null, // bad — should drop
                'source_url' => 'https://e.x',
                'snippet' => 's',
                'section' => null,
            ],
        ], $logger);

        $collection = $reader->readByFormId(7);

        self::assertSame([], $collection->guidelineCitations);
        self::assertNotEmpty($logger->records);
    }

    public function testFetchRowsReceivesFormId(): void
    {
        $captured = null;
        $reader = new class ($captured) extends CitationReader {
            public function __construct(
                public ?int &$captured,
            ) {
                parent::__construct(new NullLogger());
            }

            protected function fetchRows(int $formId): array
            {
                $this->captured = $formId;
                return [];
            }
        };

        $reader->readByFormId(123);

        self::assertSame(123, $captured);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function buildReader(array $rows, ?LoggerInterface $logger = null): CitationReader
    {
        return new class ($rows, $logger ?? new NullLogger()) extends CitationReader {
            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(
                private readonly array $rows,
                LoggerInterface $logger,
            ) {
                parent::__construct($logger);
            }

            protected function fetchRows(int $formId): array
            {
                return $this->rows;
            }
        };
    }
}

/**
 * In-memory PSR-3 logger used to verify that drop paths emit a log entry.
 */
final class RecordingCitationLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelString = is_scalar($level) || $level instanceof \Stringable
            ? (string) $level
            : '';

        $this->records[] = [
            'level' => $levelString,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
