<?php

/**
 * dedup_upload_intake_form_registry.php
 *
 * One-shot data fix: collapse any duplicate `registry` rows for
 * `directory='upload_intake_form'` down to a single row.  The rest of the
 * deploy pipeline (sql/8_1_0-to-8_1_1_upgrade.sql + Doctrine migration
 * Version20260504000001) is now idempotent at the SQL level, so once this
 * has been run on every environment it never needs to be re-run.
 *
 * Why duplicates ever existed: in May 2026 a Railway redeploy produced two
 * "Upload Document (Co-Pilot)" entries in the encounter Administrative
 * dropdown.  The upgrade SQL's `#IfNotRow2D` guard was supposed to prevent
 * the INSERT from running twice, but did not — likely because the prior
 * deploy renamed an "Upload Intake Form" row to "Upload Document
 * (Co-Pilot)" while a parallel install path inserted a fresh row.  Either
 * way, prod ended up with two byte-identical rows for the same form
 * directory.  This script is the one-time cleanup; the upgrade SQL
 * dedup-then-insert pattern committed alongside it prevents recurrence.
 *
 * Run flow:
 *   - Local dev:  docker exec development-easy-openemr-1 \
 *                   php /var/www/localhost/htdocs/openemr/bin/dedup_upload_intake_form_registry.php
 *   - Railway:    railway ssh --service openemr-web -- \
 *                   php /var/www/localhost/htdocs/openemr/bin/dedup_upload_intake_form_registry.php
 *
 * Idempotent: if there is exactly one row (or zero), the script reports
 * the state and exits 0 without touching the table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

const TRIM_MASK = " \t\n\r\0\x0B\xEF\xBB\xBF";

require_once __DIR__ . '/../vendor/autoload.php';

function readEnvTrim(string $key): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return null;
    }
    $trimmed = trim($value, TRIM_MASK);
    return $trimmed === '' ? null : $trimmed;
}

$host = readEnvTrim('MYSQL_HOST') ?? 'localhost';
$port = readEnvTrim('MYSQL_PORT') ?? '3306';
$database = readEnvTrim('MYSQL_DATABASE') ?? 'openemr';
$user = readEnvTrim('MYSQL_USER') ?? 'openemr';
$password = readEnvTrim('MYSQL_PASS') ?? 'openemr';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "[dedup-upload-intake-registry] failed to connect: " . $e->getMessage() . "\n");
    exit(1);
}

$beforeRows = $pdo
    ->query("SELECT id, name FROM registry WHERE directory = 'upload_intake_form' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

$beforeCount = count($beforeRows);
echo "[dedup-upload-intake-registry] before: $beforeCount row(s) for directory='upload_intake_form'\n";
foreach ($beforeRows as $row) {
    echo "  id={$row['id']} name=\"{$row['name']}\"\n";
}

if ($beforeCount <= 1) {
    echo "[dedup-upload-intake-registry] nothing to dedup; exiting clean.\n";
    exit(0);
}

// Keep the lowest id (earliest insert), drop the rest.  The form has no
// patient-level rows that reference registry.id (forms.formdir is keyed by
// directory string, not by registry.id), so this is safe regardless of how
// many encounters already use the form.
$deleteSql = <<<'SQL'
DELETE r1
  FROM `registry` r1
  JOIN `registry` r2
    ON r1.`directory` = r2.`directory`
   AND r1.`id` > r2.`id`
 WHERE r1.`directory` = 'upload_intake_form'
SQL;

$deleted = $pdo->exec($deleteSql);
echo "[dedup-upload-intake-registry] deleted $deleted duplicate row(s).\n";

// Make sure the survivor uses the canonical display name.
$normalizeSql = <<<'SQL'
UPDATE `registry`
   SET `name` = 'Upload Document (Co-Pilot)'
 WHERE `directory` = 'upload_intake_form'
   AND `name` <> 'Upload Document (Co-Pilot)'
SQL;
$renamed = $pdo->exec($normalizeSql);
if ($renamed > 0) {
    echo "[dedup-upload-intake-registry] normalized $renamed row name(s) to 'Upload Document (Co-Pilot)'.\n";
}

$afterRows = $pdo
    ->query("SELECT id, name FROM registry WHERE directory = 'upload_intake_form' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);
echo "[dedup-upload-intake-registry] after: " . count($afterRows) . " row(s)\n";
foreach ($afterRows as $row) {
    echo "  id={$row['id']} name=\"{$row['name']}\"\n";
}

exit(0);
