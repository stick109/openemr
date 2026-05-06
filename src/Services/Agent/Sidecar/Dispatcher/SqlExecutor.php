<?php

/**
 * SqlExecutor
 *
 * Narrow database surface used by sidecar dispatchers so they can be unit
 * tested without a live MySQL connection. The default
 * {@see QueryUtilsSqlExecutor} adapter wraps {@see QueryUtils}; tests provide
 * an in-memory fake.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar\Dispatcher;

interface SqlExecutor
{
    /**
     * Execute an INSERT statement and return the new auto-increment id.
     *
     * @param list<scalar|null> $bindings
     */
    public function insert(string $sql, array $bindings): int;

    /**
     * Fetch a single row, or null if no rows match.
     *
     * @param list<scalar|null>     $bindings
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings): ?array;
}
