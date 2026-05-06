<?php

/**
 * QueryUtilsSqlExecutor
 *
 * Default {@see SqlExecutor} adapter that delegates to OpenEMR's
 * {@see QueryUtils}. Used in production wiring; tests substitute a fake.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Dispatcher;

use OpenEMR\Common\Database\QueryUtils;

final class QueryUtilsSqlExecutor implements SqlExecutor
{
    /**
     * @param list<scalar|null> $bindings
     */
    public function insert(string $sql, array $bindings): int
    {
        return QueryUtils::sqlInsert($sql, $bindings);
    }

    /**
     * @param list<scalar|null>     $bindings
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings): ?array
    {
        $records = QueryUtils::fetchRecords($sql, $bindings);
        if (!is_array($records) || $records === []) {
            return null;
        }

        $first = $records[0];

        return is_array($first) ? $first : null;
    }
}
