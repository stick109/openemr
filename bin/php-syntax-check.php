<?php

/**
 * Cross-platform replacement for the previous Unix-only shell pipeline:
 *   git ls-files -z '*.php' | xargs -0 php -l | grep -vF 'No syntax errors detected in'
 *
 * Runs `php -l` on every tracked .php file, batching ~100 files per
 * invocation so process-spawn overhead stays low on Windows. Prints
 * lint output for files with syntax errors and exits non-zero on the
 * first error.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Windows has an ~8191-char command-line limit; batch small enough to
// stay safely under it even with long absolute paths and quoting.
const BATCH_SIZE = 30;

$listing = [];
$listExit = 0;
exec('git ls-files "*.php"', $listing, $listExit);
if ($listExit !== 0) {
    fwrite(STDERR, "Failed to list git-tracked PHP files (exit {$listExit})\n");
    exit(1);
}

$files = array_values(array_filter(array_map('trim', $listing), static fn(string $f): bool => $f !== '' && is_file($f)));

$hasError = false;
foreach (array_chunk($files, BATCH_SIZE) as $batch) {
    $args = implode(' ', array_map('escapeshellarg', $batch));
    $output = [];
    $rc = 0;
    exec('php -l ' . $args . ' 2>&1', $output, $rc);
    foreach ($output as $line) {
        if ($line === '' || str_starts_with($line, 'No syntax errors detected in')) {
            continue;
        }
        echo $line . "\n";
    }
    if ($rc !== 0) {
        $hasError = true;
    }
}

exit($hasError ? 1 : 0);
