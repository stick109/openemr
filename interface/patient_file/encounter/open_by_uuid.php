<?php

declare(strict_types=1);

/**
 * Resolves a FHIR Encounter UUID to its legacy form_encounter row and
 * 302-redirects to encounter_top.php with the active patient and encounter
 * set via the standard set_pid / set_encounter GET handshake.
 *
 * Used by the .NET Modern Dashboard's "Open" button on the Encounters card.
 * The dashboard renders FHIR resource UUIDs but has no view of OpenEMR's
 * legacy numeric encounter id, which is what encounter_top.php requires —
 * this shim sits in the OpenEMR session and bridges the two id spaces.
 *
 * Auth is enforced by globals.php (auth.inc.php). The pid query param is
 * required and must match the encounter's pid: a stranger handing out
 * encounter UUIDs cannot use this endpoint to learn which patient they
 * belong to.
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
    echo 'Encounter open shim requires uuid and pid query params.';
    exit;
}

$row = sqlQuery(
    'SELECT encounter FROM form_encounter WHERE uuid = ? AND pid = ?',
    [UuidRegistry::uuidToBytes($uuid), $pid]
);

if (empty($row['encounter'])) {
    http_response_code(404);
    echo 'No encounter found for the supplied uuid and pid.';
    exit;
}

$encounterId = (int) $row['encounter'];

header(
    'Location: ' . $web_root
    . '/interface/patient_file/encounter/encounter_top.php'
    . '?set_pid=' . urlencode((string) $pid)
    . '&set_encounter=' . urlencode((string) $encounterId)
);
exit();
