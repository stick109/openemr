<?php

declare(strict_types=1);

/**
 * Resolves a FHIR MedicationRequest UUID to its legacy editor URL and
 * 302-redirects there.
 *
 * The FHIR MedicationRequest stream is a UNION of two physical tables
 * (see PrescriptionService::getSelectSQL): rows with intent=order come
 * from `prescriptions`, while active medications come from `lists` with
 * type='medication'. The two tables have separate editors, so this shim
 * dispatches based on which table actually owns the supplied UUID.
 *
 * Used by the .NET Modern Dashboard's "Open" buttons on the Medications
 * (active) and Prescriptions (history) cards. The dashboard renders FHIR
 * UUIDs but cannot construct legacy editor URLs because (a) it does not
 * know which physical table the row lives in, and (b) it does not have a
 * UUID -> numeric id resolver. This shim sits in the OpenEMR session and
 * does both lookups in one trip.
 *
 * Auth is enforced by globals.php (auth.inc.php). The pid query param is
 * required and is matched against the row's owner column so a stranger
 * with a UUID cannot learn which patient it belongs to.
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
    echo 'Medication open shim requires uuid and pid query params.';
    exit;
}

$uuidBytes = UuidRegistry::uuidToBytes($uuid);

$prescription = sqlQuery(
    'SELECT id FROM prescriptions WHERE uuid = ? AND patient_id = ?',
    [$uuidBytes, $pid]
);

if (!empty($prescription['id'])) {
    $prescriptionId = (int) $prescription['id'];
    header(
        'Location: ' . $web_root
        . '/controller.php?prescription&edit'
        . '&id=' . urlencode((string) $prescriptionId)
        . '&pid=' . urlencode((string) $pid)
    );
    exit();
}

$listIssue = sqlQuery(
    "SELECT id FROM lists WHERE uuid = ? AND pid = ? AND type = 'medication'",
    [$uuidBytes, $pid]
);

if (!empty($listIssue['id'])) {
    $issueId = (int) $listIssue['id'];
    header(
        'Location: ' . $web_root
        . '/interface/patient_file/summary/add_edit_issue.php'
        . '?issue=' . urlencode((string) $issueId)
        . '&thispid=' . urlencode((string) $pid)
        . '&thistype=medication'
    );
    exit();
}

http_response_code(404);
echo 'No medication found for the supplied uuid and pid.';
exit;
