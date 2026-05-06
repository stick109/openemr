<?php

/**
 * AuditLogPurgeService
 *
 * Deletes rows from the `log` table older than a configurable retention
 * window. Wired into OpenEMR's `background_services` runner so a small
 * MariaDB volume cannot be filled by the audit log alone.
 *
 * ## Scope
 *
 * Only the `log` table is purged. The HIPAA audit trail (`audit_master` /
 * `audit_details`) is intentionally NOT touched: `audit_master.id` is
 * referenced from `documents.audit_master_id`, so purging by
 * `audit_master.created_time` would orphan document approval state. If the
 * HIPAA detail tables also grow without bound, they need their own purge
 * design that respects those references — this service does not.
 *
 * ## Retention default and HIPAA caveat
 *
 * The default retention is 24 hours. This is appropriate for a
 * demo/development install where the `log` table fills the disk faster
 * than operators can rotate it. **Production HIPAA installs MUST either
 * raise this retention to whatever their compliance program requires, or
 * disable this background service entirely** (`UPDATE background_services
 * SET active = 0 WHERE name = 'audit_log_purge'`). HIPAA's audit log
 * retention requirement is six years; 24 hours is many orders of
 * magnitude short of that.
 *
 * ## Why DELETE and not TRUNCATE
 *
 * The disk that bit us was 99% full when the log table tipped it over.
 * TRUNCATE on InnoDB rebuilds the .ibd file, which can require enough
 * free space to hold a copy of the data file — exactly what's not
 * available on a full disk. DELETE leaves the .ibd in place and lets
 * InnoDB re-use freed pages on subsequent inserts, which is what we want
 * for steady-state operation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Logging;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use Psr\Log\LoggerInterface;

class AuditLogPurgeService
{
    /**
     * Default retention window in hours. See class docblock for the HIPAA
     * caveat — this default is for a demo/development environment and
     * MUST be changed for production deployments.
     */
    public const DEFAULT_RETENTION_HOURS = 24;

    private readonly LoggerInterface $logger;
    private readonly int $retentionHours;

    public function __construct(
        ?LoggerInterface $logger = null,
        int $retentionHours = self::DEFAULT_RETENTION_HOURS,
    ) {
        if ($retentionHours < 1) {
            throw new \DomainException(sprintf(
                'AuditLogPurgeService retention must be >= 1 hour, got %d.',
                $retentionHours,
            ));
        }
        $this->logger = $logger ?? ServiceContainer::getLogger();
        $this->retentionHours = $retentionHours;
    }

    /**
     * Delete `log` rows older than the configured retention window.
     *
     * The DELETE is parameterised on the retention interval and uses the
     * MariaDB server's `NOW()` for the cutoff, so this method is safe to
     * run from any timezone. Returns the number of rows deleted so callers
     * (mainly tests) can verify behaviour without scraping logs.
     *
     * Errors propagate as `SqlQueryException` — the background-services
     * runner is responsible for catching and recording them; we deliberately
     * do not catch-log-continue here, otherwise a missing column or a
     * permissions failure would silently keep filling the disk.
     */
    public function purge(): int
    {
        $deleted = $this->executeDelete(
            'DELETE FROM `log` WHERE `date` < (NOW() - INTERVAL ? HOUR)',
            [$this->retentionHours],
        );

        $this->logger->info(
            'Audit log purge complete.',
            [
                'service' => 'audit_log_purge',
                'table' => 'log',
                'retention_hours' => $this->retentionHours,
                'rows_deleted' => $deleted,
            ],
        );

        return $deleted;
    }

    /**
     * Execute the DELETE and return the number of affected rows.
     *
     * Extracted so isolated tests can subclass and stub the database
     * interaction without spinning up a real connection. The `noLog`
     * argument is non-negotiable: if this DELETE got logged through the
     * normal audit pipeline it would write back into the `log` table
     * we are trying to drain, defeating the entire purpose of the
     * service.
     *
     * @param array<int, int|string> $params
     *
     * @throws SqlQueryException when the underlying statement fails.
     */
    protected function executeDelete(string $sql, array $params): int
    {
        QueryUtils::sqlStatementThrowException($sql, $params, true);
        $affected = QueryUtils::affectedRows();
        return is_int($affected) ? $affected : 0;
    }

    public function getRetentionHours(): int
    {
        return $this->retentionHours;
    }
}
