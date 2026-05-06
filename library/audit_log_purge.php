<?php

/**
 * Global entry-point used by the `audit_log_purge` background service.
 *
 * The background-services runner expects a global function name in the
 * `function` column of the `background_services` row. This file is the
 * thin glue that constructs the AuditLogPurgeService and calls purge().
 * All policy lives in the service class — this file is intentionally
 * trivial so the require_once / function_exists handshake stays cheap.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Services\Logging\AuditLogPurgeService;

function auditLogPurgeServiceRun(): void
{
    (new AuditLogPurgeService())->purge();
}
