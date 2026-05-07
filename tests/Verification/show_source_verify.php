<?php

/**
 * show_source verification harness (run inside the openemr container).
 *
 * Mimics the controller path: mints a CopilotRunContext using the same
 * shared secret the runtime uses, then posts to the sidecar's
 * ``/api/copilot/run`` endpoint with ``source_id`` populated. Prints the
 * sidecar response so the verifier can confirm
 * ``answer.answer_blocks`` is non-empty.
 */

declare(strict_types=1);

$autoload = '/var/www/localhost/htdocs/openemr/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing -- run from inside the container\n");
    exit(2);
}
require $autoload;

use OpenEMR\Services\Agent\Copilot\CopilotRunContext;

$secret = getenv('OPENEMR_AGENT_SIDECAR_SECRET');
if ($secret === false || $secret === '') {
    $secret = getenv('AGENT_SHARED_SECRET') ?: '';
}
if ($secret === '') {
    fwrite(STDERR, "no OPENEMR_AGENT_SIDECAR_SECRET / AGENT_SHARED_SECRET in env\n");
    exit(2);
}

$sidecarBase = getenv('OPENEMR_AGENT_SIDECAR_URL') ?: 'http://agent-service:8010';

$intentId = $argv[1] ?? 'show_source';
$sourceId = $argv[2] ?? 'medication:lists:2';
$patientId = (int) ($argv[3] ?? 1);

$now = time();
$claims = [
    'user_id' => 1,
    'username' => 'session-user',
    'patient_id' => $patientId,
    'encounter_id' => null,
    'allowed_tools' => [
        'get_basic_patient_data',
        'get_current_medications',
        'get_active_allergies',
        'get_recent_events',
        'get_changes_since_last_visit',
        'get_source_detail',
    ],
    'allowed_source_types' => [
        'patient_record', 'encounters', 'labs', 'vitals', 'procedures',
        'medications', 'allergies', 'problems', 'document',
        // singular citation-prefix forms used by get_source_detail.
        'demographics', 'medication', 'medication_review',
        'allergy', 'allergy_review', 'problem', 'result', 'encounter',
    ],
    'max_rows' => 25,
    'lookback_days' => 365,
    'expires_at' => $now + 60,
    'request_id' => '00000000-0000-4000-8000-000000000001',
    'trace_id' => '00000000-0000-4000-8000-000000000001',
];

$wireToken = CopilotRunContext::mint($claims, $secret, 'v1');

$body = [
    'run_context' => $wireToken,
    'intent_id' => $intentId,
    'user_goal' => null,
    'request_id' => '00000000-0000-4000-8000-000000000001',
    'conversation_state' => null,
    'source_id' => $sourceId,
];

$ch = curl_init($sidecarBase . '/api/copilot/run');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    fwrite(STDERR, "curl failed: $err\n");
    exit(2);
}

echo "HTTP $status\n";
echo $raw . "\n";
