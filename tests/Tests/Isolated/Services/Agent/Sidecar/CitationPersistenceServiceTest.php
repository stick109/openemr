<?php

/**
 * CitationPersistenceServiceTest
 *
 * Isolated tests for {@see CitationPersistenceService}. The service's DB
 * interaction is intercepted by overriding the protected executeInsert()
 * and runInTransaction() hooks on a tiny anonymous subclass — there is no
 * real database in isolated tests, and stubbing QueryUtils statically
 * would couple the test to ADOdb's bootstrap.
 *
 * Manual DB verification (run from the project root):
 *   docker compose --project-name openemr exec -T mysql \
 *     mariadb -uroot -proot openemr \
 *     -e "DESCRIBE form_upload_intake_form_citation;"
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar;

use OpenEMR\Services\Agent\Sidecar\CitationPersistenceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
class CitationPersistenceServiceTest extends TestCase
{
    public function testEmptyCitationsListIsNoOp(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $service->persist(formId: 42, citations: []);

        self::assertSame([], $recorder->rows);
        self::assertSame(0, $recorder->transactionStarts);
    }

    public function testPdfBboxCitationPersistsWithCorrectColumns(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $service->persist(
            formId: 7,
            citations: [
                [
                    'source_type' => 'pdf_bbox',
                    'page' => 1,
                    'bbox' => [72.0, 200.0, 540.0, 230.5],
                    'field_name' => 'hemoglobin',
                ],
            ],
        );

        self::assertCount(1, $recorder->rows);
        self::assertSame(1, $recorder->transactionStarts);

        $row = $recorder->rows[0];
        self::assertSame(7, $row['form_id']);
        self::assertSame('pdf_bbox', $row['source_type']);
        self::assertSame('hemoglobin', $row['field_name']);
        self::assertSame(1, $row['page']);
        self::assertSame(72.0, $row['bbox_x0']);
        self::assertSame(200.0, $row['bbox_y0']);
        self::assertSame(540.0, $row['bbox_x1']);
        self::assertSame(230.5, $row['bbox_y1']);
        self::assertNull($row['chunk_id']);
        self::assertNull($row['source_url']);
        self::assertNull($row['snippet']);
        self::assertNull($row['section']);
    }

    public function testPdfBboxCitationAcceptsMissingFieldName(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        // CONTRACT.md's PdfBboxCitation does not require `field_name`;
        // only the internal SourceCitation does. The persistence layer
        // must accept rows without it.
        $service->persist(
            formId: 11,
            citations: [
                [
                    'source_type' => 'pdf_bbox',
                    'page' => 2,
                    'bbox' => [0, 0, 100, 50], // ints — must coerce cleanly
                ],
            ],
        );

        self::assertCount(1, $recorder->rows);
        self::assertNull($recorder->rows[0]['field_name']);
        self::assertSame(0.0, $recorder->rows[0]['bbox_x0']);
        self::assertSame(100.0, $recorder->rows[0]['bbox_x1']);
    }

    public function testGuidelineCitationPersistsWithCorrectColumns(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $service->persist(
            formId: 99,
            citations: [
                [
                    'source_type' => 'guideline',
                    'chunk_id' => 'ama-lab-ref-2025-cbc-003',
                    'source_url' => 'https://guidelines.example.org/ama-lab-ref-2025',
                    'snippet' => 'Normal hemoglobin for adult males: 13.5-17.5 g/dL',
                    'section' => 'CBC',
                ],
            ],
        );

        self::assertCount(1, $recorder->rows);

        $row = $recorder->rows[0];
        self::assertSame(99, $row['form_id']);
        self::assertSame('guideline', $row['source_type']);
        self::assertNull($row['field_name']);
        self::assertNull($row['page']);
        self::assertNull($row['bbox_x0']);
        self::assertNull($row['bbox_y1']);
        self::assertSame('ama-lab-ref-2025-cbc-003', $row['chunk_id']);
        self::assertSame('https://guidelines.example.org/ama-lab-ref-2025', $row['source_url']);
        self::assertSame('Normal hemoglobin for adult males: 13.5-17.5 g/dL', $row['snippet']);
        self::assertSame('CBC', $row['section']);
    }

    public function testGuidelineCitationAcceptsMissingSection(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        // CONTRACT.md's GuidelineCitation does not include `section`;
        // it's an optional column we carry for downstream UX.
        $service->persist(
            formId: 11,
            citations: [
                [
                    'source_type' => 'guideline',
                    'chunk_id' => 'c1',
                    'source_url' => 'https://example.com',
                    'snippet' => 'hello',
                ],
            ],
        );

        self::assertCount(1, $recorder->rows);
        self::assertNull($recorder->rows[0]['section']);
    }

    public function testMixedCitationListPersistsAllRowsInOrder(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $service->persist(
            formId: 5,
            citations: [
                [
                    'source_type' => 'pdf_bbox',
                    'page' => 1,
                    'bbox' => [10.0, 20.0, 30.0, 40.0],
                ],
                [
                    'source_type' => 'guideline',
                    'chunk_id' => 'g-1',
                    'source_url' => 'https://example.com/1',
                    'snippet' => 'snippet-1',
                ],
                [
                    'source_type' => 'pdf_bbox',
                    'page' => 3,
                    'bbox' => [11.0, 22.0, 33.0, 44.0],
                    'field_name' => 'wbc',
                ],
            ],
        );

        self::assertCount(3, $recorder->rows);
        self::assertSame(1, $recorder->transactionStarts);
        self::assertSame('pdf_bbox', $recorder->rows[0]['source_type']);
        self::assertSame('guideline', $recorder->rows[1]['source_type']);
        self::assertSame('pdf_bbox', $recorder->rows[2]['source_type']);
        self::assertSame('wbc', $recorder->rows[2]['field_name']);
    }

    public function testInvalidSourceTypeRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported source_type');

        $service->persist(
            formId: 1,
            citations: [
                ['source_type' => 'web_search', 'snippet' => 'x'],
            ],
        );
    }

    public function testMissingSourceTypeRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source_type');

        $service->persist(
            formId: 1,
            citations: [
                ['page' => 1, 'bbox' => [0, 0, 1, 1]],
            ],
        );
    }

    public function testPdfBboxMissingPageRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('page');

        $service->persist(
            formId: 1,
            citations: [
                ['source_type' => 'pdf_bbox', 'bbox' => [0, 0, 1, 1]],
            ],
        );
    }

    public function testPdfBboxWrongBboxLengthRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bbox');

        $service->persist(
            formId: 1,
            citations: [
                ['source_type' => 'pdf_bbox', 'page' => 1, 'bbox' => [0, 0, 1]],
            ],
        );
    }

    public function testGuidelineMissingChunkIdRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunk_id');

        $service->persist(
            formId: 1,
            citations: [
                [
                    'source_type' => 'guideline',
                    'source_url' => 'https://example.com',
                    'snippet' => 'x',
                ],
            ],
        );
    }

    public function testValidationFailureRollsBackEntireBatch(): void
    {
        // The first citation is valid; the second is malformed. The
        // service must validate everything before opening a transaction
        // so no partial inserts ever land in the database.
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        try {
            $service->persist(
                formId: 1,
                citations: [
                    [
                        'source_type' => 'pdf_bbox',
                        'page' => 1,
                        'bbox' => [0.0, 0.0, 10.0, 10.0],
                    ],
                    ['source_type' => 'mystery'],
                ],
            );
            self::fail('Expected InvalidArgumentException to be thrown.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $recorder->rows);
        self::assertSame(0, $recorder->transactionStarts);
    }

    public function testNonPositiveFormIdRaisesException(): void
    {
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('form_id');

        $service->persist(formId: 0, citations: [
            ['source_type' => 'guideline', 'chunk_id' => 'c', 'source_url' => 'u', 'snippet' => 's'],
        ]);
    }

    public function testPersistLogsCountOnSuccess(): void
    {
        $logger = new RecordingLogger();
        $recorder = new InsertRecorder();
        $service = $this->buildService($recorder, $logger);

        $service->persist(
            formId: 7,
            citations: [
                [
                    'source_type' => 'guideline',
                    'chunk_id' => 'c',
                    'source_url' => 'https://example.com',
                    'snippet' => 's',
                ],
            ],
        );

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(7, $logger->records[0]['context']['form_id']);
        self::assertSame(1, $logger->records[0]['context']['count']);
    }

    private function buildService(
        InsertRecorder $recorder,
        ?LoggerInterface $logger = null,
    ): CitationPersistenceService {
        return new class ($recorder, $logger ?? new NullLogger()) extends CitationPersistenceService {
            public function __construct(
                private readonly InsertRecorder $recorder,
                LoggerInterface $logger,
            ) {
                parent::__construct($logger);
            }

            protected function executeInsert(array $row): void
            {
                $this->recorder->record($row);
            }

            protected function runInTransaction(callable $action): void
            {
                $this->recorder->transactionStarts++;
                $action();
            }
        };
    }
}

/**
 * Captures every INSERT row issued by CitationPersistenceService and
 * counts transaction starts, replacing the real QueryUtils round-trip.
 */
final class InsertRecorder
{
    /** @var list<array<string, int|string|float|null>> */
    public array $rows = [];

    public int $transactionStarts = 0;

    /**
     * @param array<string, int|string|float|null> $row
     */
    public function record(array $row): void
    {
        $this->rows[] = $row;
    }
}

/**
 * In-memory PSR-3 logger used to verify the service's success log entry.
 */
final class RecordingLogger extends AbstractLogger
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
