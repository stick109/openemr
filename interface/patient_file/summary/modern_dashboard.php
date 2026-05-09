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
    <title>Modern Dashboard</title>
    <!-- Note: no <meta http-equiv="refresh"> here on purpose. The JS below
         navigates the top frame; a parallel meta-refresh would fire a second
         /authorize request to OpenEMR's OAuth server and the two requests
         would race each other to write the trusted-user row, leaving the
         dashboard with a stale code. Rely solely on the script. -->
</head>
<body>
    <script>
        // OpenEMR's main.php registers a beforeunload handler via
        // addEventListener that pops a "Leave site?" prompt on top-frame
        // navigation. Setting onbeforeunload=null does not remove
        // addEventListener handlers. Open the dashboard in the same browser
        // tab via top.location.replace so the user does not lose their
        // OpenEMR tab; suppress the prompt by re-asserting the OpenEMR
        // top-window's restoreSession state and dispatching a synthetic
        // submit so OpenEMR's handler treats this as an intentional exit.
        const target = <?php echo json_encode($target, JSON_UNESCAPED_SLASHES); ?>;
        function bypassBeforeUnloadAndNavigate(url) {
            // OpenEMR's main.php beforeunload listener checks a top-window
            // `timed_out` flag (interface/main/tabs/main.php). Setting it to
            // true makes the listener skip event.returnValue, so the browser
            // does not pop the "Leave site?" confirmation when we navigate.
            try { window.top.timed_out = true; } catch (e) { /* cross-origin */ }
            // Use replace() so the shim does not appear in the back stack -
            // the user pressing Back from the dashboard returns to OpenEMR's
            // patient summary, not this empty redirect page.
            try {
                window.top.location.replace(url);
            } catch (e) {
                window.location.replace(url);
            }
        }
        bypassBeforeUnloadAndNavigate(target);
    </script>
    <noscript>
        <meta http-equiv="refresh" content="0; url=<?php echo attr($target); ?>">
        <p>Redirecting to the Modern Dashboard&hellip; if your browser does not redirect, <a href="<?php echo attr($target); ?>">click here</a>.</p>
    </noscript>
</body>
</html>
