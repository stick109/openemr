<?php

/**
 * Modern Dashboard redirect shim.
 *
 * The patient menu's "Modern Dashboard" entry points here. The shim runs in
 * OpenEMR's session/origin so it can read the active patient pid and the
 * dashboard URL from environment, then breaks out of the OpenEMR top frame
 * with a top.location.href assignment so the cross-origin .NET dashboard
 * loads in the same browser tab.
 *
 * The dashboard base URL is read from the OPENEMR_DASHBOARD_URL env var.
 * Dev-easy sets it to http://localhost:8400; production sets it to the
 * Railway dashboard URL.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Konstantin Surkov <surkov@gmail.com>
 * @copyright Copyright (c) 2026 Konstantin Surkov
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../globals.php');

// Resolve the active patient. The menu appends pid via "pid": "true"; the
// entry url ends in "modern_dashboard.php?pid=", so $_GET['pid'] holds the
// local pid. Fall back to the session pid if missing.
$pid = $_GET['pid'] ?? null;
if (empty($pid) && !empty($_SESSION['pid'])) {
    $pid = $_SESSION['pid'];
}
$pid = is_numeric($pid) ? (int) $pid : null;
if ($pid === null) {
    http_response_code(400);
    echo 'Modern Dashboard requires an active patient (no pid in request or session).';
    exit;
}

// Resolve the dashboard base URL from env, fall back to dev-easy default.
$dashboardBaseUrl = getenv('OPENEMR_DASHBOARD_URL') ?: 'http://localhost:8400';
$dashboardBaseUrl = rtrim($dashboardBaseUrl, '/');

$target = $dashboardBaseUrl . '/Patient/' . $pid;

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0; url=<?php echo attr($target); ?>">
    <title>Modern Dashboard</title>
</head>
<body>
    <script>
        // Break out of the OpenEMR top frame so the cross-origin dashboard
        // loads in the browser tab rather than inside the legacy iframe
        // (the iframe would block OIDC redirect chains and cookie scoping).
        try {
            window.top.location.href = <?php echo json_encode($target, JSON_UNESCAPED_SLASHES); ?>;
        } catch (e) {
            window.location.href = <?php echo json_encode($target, JSON_UNESCAPED_SLASHES); ?>;
        }
    </script>
    <noscript>
        <p>Redirecting to the Modern Dashboard&hellip; if your browser does not redirect, <a href="<?php echo attr($target); ?>">click here</a>.</p>
    </noscript>
</body>
</html>
