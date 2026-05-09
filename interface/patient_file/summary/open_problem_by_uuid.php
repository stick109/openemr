<?php

declare(strict_types=1);

/**
 * Resolves a FHIR Condition UUID to its legacy lists row id and 302-redirects
 * to add_edit_issue.php with the numeric issue id.
 *
 * Used by the .NET Modern Dashboard's "Open" button on the Problem List card.
 * The dashboard renders FHIR resource UUIDs but add_edit_issue.php keys on
 * the numeric lists.id. With no UUID -> id resolver in the dashboard, this
 * shim sits in the OpenEMR session and bridges the two id spaces.
 *
 * Auth is enforced by globals.php (auth.inc.php). The pid query param is
 * required and matched against lists.pid so a stranger with a UUID cannot
 * learn which patient it belongs to.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Uuid\UuidRegistry;

$uuid = (string) ($_GET['uuid'] ?? '');
$pid = (int) ($_GET['pid'] ?? 0);

if ($uuid === '' || $pid <= 0 || !UuidRegistry::isValidStringUUID($uuid)) {
    http_response_code(400);
    echo 'Problem open shim requires uuid and pid query params.';
    exit;
}

$row = sqlQuery(
    "SELECT id FROM lists WHERE uuid = ? AND pid = ? AND type = 'medical_problem'",
    [UuidRegistry::uuidToBytes($uuid), $pid]
);

if (empty($row['id'])) {
    http_response_code(404);
    echo 'No problem found for the supplied uuid and pid.';
    exit;
}

$issueId = (int) $row['id'];

header(
    'Location: ' . $web_root
    . '/interface/patient_file/summary/add_edit_issue.php'
    . '?issue=' . urlencode((string) $issueId)
    . '&thispid=' . urlencode((string) $pid)
    . '&thistype=medical_problem'
);
exit();
