<?php

/**
 * LabPdfDispatcherTest
 *
 * Isolated unit tests for {@see LabPdfDispatcher}. Uses an in-memory
 * {@see SqlExecutor} fake so the dispatcher's SQL contract can be exercised
 * without a live MySQL instance.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Agent\Sidecar\Dispatcher;

use DateTimeImmutable;
use DateTimeZone;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\LabPdfDispatcher;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\SqlExecutor;
use OpenEMR\Services\Intake\Exception\IngestionFailedException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[Group('isolated')]
#[Group('agent')]
final class LabPdfDispatcherTest extends TestCase
{
    private const TRACE_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    public function testDispatchInsertsOneOrderOneReportAndOneResultPerLabRow(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $result = $dispatcher->dispatch(
            patientId: 42,
            encounterId: 7,
            extracted: $this->extracted(5),
            traceId: self::TRACE_ID,
        );

        self::assertTrue($result->created);
        self::assertSame(self::TRACE_ID, $result->traceId);
        self::assertGreaterThan(0, $result->procedureOrderId);
        self::assertGreaterThan(0, $result->procedureReportId);
        self::assertCount(5, $result->procedureResultIds);

        self::assertCount(1, $sql->insertsForTable('procedure_order'));
        self::assertCount(1, $sql->insertsForTable('procedure_report'));
        self::assertCount(5, $sql->insertsForTable('procedure_result'));
    }

    public function testDispatchPersistsTraceIdAsControlIdForIdempotency(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $dispatcher->dispatch(1, 2, $this->extracted(1), self::TRACE_ID);

        $orderInserts = $sql->insertsForTable('procedure_order');
        self::assertCount(1, $orderInserts);
        $bindings = $orderInserts[0]['bindings'];
        self::assertContains(self::TRACE_ID, $bindings, 'control_id binding must carry the trace id');
    }

    public function testDispatchPreservesAllResultFields(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $dispatcher->dispatch(
            patientId: 42,
            encounterId: 7,
            extracted: [
                'results' => [
                    [
                        'test_name' => 'Hemoglobin',
                        'value' => '9.8',
                        'unit' => 'g/dL',
                        'reference_range' => '12.0-16.0',
                        'collection_date' => '2025-04-01',
                        'abnormal_flag' => 'low',
                        'loinc_code' => '718-7',
                    ],
                ],
            ],
            traceId: self::TRACE_ID,
        );

        $resultInserts = $sql->insertsForTable('procedure_result');
        self::assertCount(1, $resultInserts);
        $bindings = $resultInserts[0]['bindings'];

        self::assertContains('Hemoglobin', $bindings);
        self::assertContains('9.8', $bindings);
        self::assertContains('g/dL', $bindings);
        self::assertContains('12.0-16.0', $bindings);
        self::assertContains('2025-04-01', $bindings);
        self::assertContains('L', $bindings, 'low must be mapped to HL7 code L');
        self::assertContains('718-7', $bindings, 'LOINC code must round-trip into result_code');
    }

    /**
     * @param non-empty-string $rawFlag
     * @param non-empty-string $expectedCode
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('abnormalFlagProvider')]
    public function testAbnormalFlagMapping(string $rawFlag, string $expectedCode): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $dispatcher->dispatch(
            patientId: 1,
            encounterId: 1,
            extracted: [
                'results' => [
                    [
                        'test_name' => 'Glucose',
                        'value' => '150',
                        'unit' => 'mg/dL',
                        'reference_range' => '70-100',
                        'collection_date' => '2025-04-01',
                        'abnormal_flag' => $rawFlag,
                    ],
                ],
            ],
            traceId: self::TRACE_ID,
        );

        $bindings = $sql->insertsForTable('procedure_result')[0]['bindings'];
        self::assertContains($expectedCode, $bindings);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function abnormalFlagProvider(): array
    {
        return [
            'normal -> N' => ['normal', 'N'],
            'high -> H' => ['high', 'H'],
            'low -> L' => ['low', 'L'],
            'critical_high -> HH' => ['critical_high', 'HH'],
            'critical_low -> LL' => ['critical_low', 'LL'],
            'abnormal -> A' => ['abnormal', 'A'],
            'unknown defaults to N' => ['something_else', 'N'],
        ];
    }

    public function testDispatchIsIdempotentOnSameTraceId(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $first = $dispatcher->dispatch(42, 7, $this->extracted(3), self::TRACE_ID);
        self::assertTrue($first->created);

        $second = $dispatcher->dispatch(42, 7, $this->extracted(3), self::TRACE_ID);

        self::assertFalse($second->created, 'second call must be detected as a duplicate');
        self::assertSame($first->procedureOrderId, $second->procedureOrderId);
        self::assertSame($first->procedureReportId, $second->procedureReportId);
        self::assertSame($first->procedureResultIds, $second->procedureResultIds);

        // No new inserts must have been issued the second time around.
        self::assertCount(1, $sql->insertsForTable('procedure_order'));
        self::assertCount(1, $sql->insertsForTable('procedure_report'));
        self::assertCount(3, $sql->insertsForTable('procedure_result'));
    }

    public function testEmptyResultsArrayThrows(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $this->expectException(IngestionFailedException::class);
        $this->expectExceptionMessage('at least one result row');

        $dispatcher->dispatch(
            patientId: 1,
            encounterId: 1,
            extracted: ['results' => []],
            traceId: self::TRACE_ID,
        );
    }

    public function testMissingResultsKeyThrows(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $this->expectException(IngestionFailedException::class);

        $dispatcher->dispatch(
            patientId: 1,
            encounterId: 1,
            extracted: [],
            traceId: self::TRACE_ID,
        );
    }

    public function testInvalidPatientIdThrows(): void
    {
        $dispatcher = new LabPdfDispatcher(new InMemorySqlExecutor(), new NullLogger(), $this->clock());

        $this->expectException(IngestionFailedException::class);
        $dispatcher->dispatch(0, 1, $this->extracted(1), self::TRACE_ID);
    }

    public function testInvalidEncounterIdThrows(): void
    {
        $dispatcher = new LabPdfDispatcher(new InMemorySqlExecutor(), new NullLogger(), $this->clock());

        $this->expectException(IngestionFailedException::class);
        $dispatcher->dispatch(1, 0, $this->extracted(1), self::TRACE_ID);
    }

    public function testEmptyTraceIdThrows(): void
    {
        $dispatcher = new LabPdfDispatcher(new InMemorySqlExecutor(), new NullLogger(), $this->clock());

        $this->expectException(IngestionFailedException::class);
        $dispatcher->dispatch(1, 1, $this->extracted(1), '');
    }

    public function testReportIsLinkedToOrder(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $result = $dispatcher->dispatch(1, 1, $this->extracted(2), self::TRACE_ID);

        $reportInsert = $sql->insertsForTable('procedure_report')[0];
        self::assertContains($result->procedureOrderId, $reportInsert['bindings']);

        foreach ($sql->insertsForTable('procedure_result') as $resultInsert) {
            self::assertContains($result->procedureReportId, $resultInsert['bindings']);
        }
    }

    public function testDispatchReturnsResultIdsInInputOrder(): void
    {
        $sql = new InMemorySqlExecutor();
        $dispatcher = new LabPdfDispatcher($sql, new NullLogger(), $this->clock());

        $result = $dispatcher->dispatch(1, 1, $this->extracted(4), self::TRACE_ID);

        $expected = [];
        foreach ($sql->insertsForTable('procedure_result') as $insert) {
            $expected[] = $insert['returnedId'];
        }
        self::assertSame($expected, $result->procedureResultIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function extracted(int $resultCount): array
    {
        $results = [];
        for ($i = 0; $i < $resultCount; $i++) {
            $results[] = [
                'test_name' => 'Test ' . $i,
                'value' => (string) (10 + $i),
                'unit' => 'mg/dL',
                'reference_range' => '0-100',
                'collection_date' => '2025-04-0' . (1 + ($i % 9)),
                'abnormal_flag' => 'normal',
            ];
        }

        return [
            'results' => $results,
            'extraction_confidence' => 0.9,
            'lab_name' => 'Acme Diagnostics',
        ];
    }

    private function clock(): ClockInterface
    {
        return new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2025-04-15 10:30:00', new DateTimeZone('UTC'));
            }
        };
    }
}

/**
 * @internal
 *
 * @codeCoverageIgnore Test fixture, not production code.
 */
final class InMemorySqlExecutor implements SqlExecutor
{
    /**
     * @var array<string, list<array{sql: string, bindings: list<scalar|null>, returnedId: int}>>
     */
    private array $inserts = [];

    /**
     * Map of next auto-increment id per table.
     *
     * @var array<string, int>
     */
    private array $nextId = [];

    /**
     * @param list<scalar|null> $bindings
     */
    public function insert(string $sql, array $bindings): int
    {
        $table = $this->extractTable($sql);
        $next = $this->nextId[$table] ?? 100;
        $id = $next + 1;
        $this->nextId[$table] = $id;

        $this->inserts[$table][] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'returnedId' => $id,
        ];

        return $id;
    }

    /**
     * @param list<scalar|null>     $bindings
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings): ?array
    {
        // Trace-id idempotency lookup against procedure_order.
        if (str_contains($sql, 'FROM `procedure_order`')) {
            $traceId = $bindings[0] ?? null;
            foreach ($this->inserts['procedure_order'] ?? [] as $insert) {
                if (in_array($traceId, $insert['bindings'], true)) {
                    return ['procedure_order_id' => $insert['returnedId']];
                }
            }

            return null;
        }

        if (str_contains($sql, 'FROM `procedure_report`')) {
            $orderId = $bindings[0] ?? null;
            foreach ($this->inserts['procedure_report'] ?? [] as $insert) {
                if (in_array($orderId, $insert['bindings'], true)) {
                    return ['procedure_report_id' => $insert['returnedId']];
                }
            }

            return null;
        }

        if (str_contains($sql, 'FROM `procedure_result`')) {
            $reportId = $bindings[0] ?? null;
            $matching = [];
            foreach ($this->inserts['procedure_result'] ?? [] as $insert) {
                if (in_array($reportId, $insert['bindings'], true)) {
                    $matching[] = ['procedure_result_id' => $insert['returnedId']];
                }
            }

            // Honour LIMIT 1 OFFSET N pagination by parsing the SQL.
            $offset = 0;
            if (preg_match('/OFFSET (\d+)/', $sql, $matches) === 1) {
                $offset = (int) $matches[1];
            }

            return $matching[$offset] ?? null;
        }

        return null;
    }

    /**
     * @return list<array{sql: string, bindings: list<scalar|null>, returnedId: int}>
     */
    public function insertsForTable(string $table): array
    {
        return $this->inserts[$table] ?? [];
    }

    private function extractTable(string $sql): string
    {
        if (preg_match('/INSERT INTO `([a-z_]+)`/i', $sql, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }
}
