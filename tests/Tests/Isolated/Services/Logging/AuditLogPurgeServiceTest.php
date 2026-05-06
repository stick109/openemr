<?php

/**
 * Isolated tests for AuditLogPurgeService.
 *
 * Verifies the service issues a parameterised DELETE against the `log`
 * table using the configured retention window, that it can be
 * instantiated by the background-services runner without throwing, and
 * that bad retention values are rejected at construction time.
 *
 * The DELETE is intercepted by overriding the protected executeDelete()
 * hook on a tiny anonymous subclass — there is no real database in
 * isolated tests, and stubbing QueryUtils statically would couple the
 * test to ADOdb's bootstrap.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Logging;

use OpenEMR\Services\Logging\AuditLogPurgeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AuditLogPurgeServiceTest extends TestCase
{
    public function testPurgeIssuesDeleteAgainstLogTableWithDefaultRetention(): void
    {
        $recorder = new SqlRecorder(0);
        $service = $this->buildService($recorder);

        $deleted = $service->purge();

        self::assertSame(0, $deleted);
        self::assertSame(
            'DELETE FROM `log` WHERE `date` < (NOW() - INTERVAL ? HOUR)',
            $recorder->capturedSql,
        );
        self::assertSame([24], $recorder->capturedParams);
    }

    public function testPurgeUsesCustomRetentionHours(): void
    {
        $recorder = new SqlRecorder(0);
        $service = $this->buildService($recorder, retentionHours: 168);

        self::assertSame(168, $service->getRetentionHours());

        $service->purge();

        self::assertSame([168], $recorder->capturedParams);
    }

    public function testPurgeReturnsAffectedRowCountFromExecuteDelete(): void
    {
        $recorder = new SqlRecorder(42);
        $service = $this->buildService($recorder);

        self::assertSame(42, $service->purge());
    }

    public function testPurgeLogsRowsDeletedAtInfoLevel(): void
    {
        $logger = new RecordingLogger();
        $recorder = new SqlRecorder(7);
        $service = $this->buildService($recorder, logger: $logger);

        $service->purge();

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('audit_log_purge', $logger->records[0]['context']['service']);
        self::assertSame('log', $logger->records[0]['context']['table']);
        self::assertSame(24, $logger->records[0]['context']['retention_hours']);
        self::assertSame(7, $logger->records[0]['context']['rows_deleted']);
    }

    public function testConstructorRejectsZeroOrNegativeRetention(): void
    {
        $this->expectException(\DomainException::class);

        new AuditLogPurgeService(new NullLogger(), retentionHours: 0);
    }

    public function testServiceCanBeInstantiatedByBackgroundServicesRunner(): void
    {
        // The runner calls the global wrapper, which boils down to
        // `new AuditLogPurgeService()` with no arguments. Make sure that
        // path doesn't throw when ServiceContainer falls back to a
        // NullLogger (the PHPUNIT_COMPOSER_INSTALL branch).
        $service = new AuditLogPurgeService();

        self::assertSame(
            AuditLogPurgeService::DEFAULT_RETENTION_HOURS,
            $service->getRetentionHours(),
        );
    }

    private function buildService(
        SqlRecorder $recorder,
        ?LoggerInterface $logger = null,
        int $retentionHours = AuditLogPurgeService::DEFAULT_RETENTION_HOURS,
    ): AuditLogPurgeService {
        return new class ($recorder, $logger ?? new NullLogger(), $retentionHours) extends AuditLogPurgeService {
            public function __construct(
                private readonly SqlRecorder $recorder,
                LoggerInterface $logger,
                int $retentionHours,
            ) {
                parent::__construct($logger, $retentionHours);
            }

            protected function executeDelete(string $sql, array $params): int
            {
                return $this->recorder->record($sql, $params);
            }
        };
    }
}

/**
 * Captures the last DELETE issued by AuditLogPurgeService and returns a
 * canned affected-row count, replacing the real QueryUtils round-trip.
 */
final class SqlRecorder
{
    public ?string $capturedSql = null;

    /** @var array<int, mixed> */
    public array $capturedParams = [];

    public function __construct(private readonly int $affectedRows)
    {
    }

    /**
     * @param array<int, mixed> $params
     */
    public function record(string $sql, array $params): int
    {
        $this->capturedSql = $sql;
        $this->capturedParams = $params;
        return $this->affectedRows;
    }
}

/**
 * In-memory PSR-3 logger that captures every record written to it for
 * later assertion. Only `info` is exercised by the purge service today;
 * other levels delegate through `log()` for completeness.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // PSR-3 leaves `$level` as `mixed`; in practice it's a string or
        // Stringable, so coerce defensively for the test record.
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
