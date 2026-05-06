<?php

/**
 * Register the audit_log_purge background service.
 *
 * Inserts a row into `background_services` so the runner picks up the
 * AuditLogPurgeService on its hourly schedule. Default retention is 24
 * hours, suitable for a demo install. See AuditLogPurgeService docblock
 * for the HIPAA caveat — production deployments must either raise the
 * retention or disable this row before going live.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register the audit_log_purge background service '
            . '(hourly DELETE on the `log` table, 24-hour retention).';
    }

    public function up(Schema $schema): void
    {
        // Idempotent insert: name is the primary key so re-running the
        // migration after a manual cleanup is harmless.
        $this->addSql(
            "INSERT IGNORE INTO `background_services`"
            . " (`name`, `title`, `active`, `running`, `next_run`,"
            . "  `execute_interval`, `function`, `require_once`, `sort_order`)"
            . " VALUES"
            . " ('audit_log_purge', 'Audit Log Retention Purge', 1, 0, NOW(),"
            . "  60, 'auditLogPurgeServiceRun', '/library/audit_log_purge.php', 200)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM `background_services` WHERE `name` = 'audit_log_purge'"
        );
    }
}
