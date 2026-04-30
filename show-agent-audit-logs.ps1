param(
    [int]$Hours = 24
)

$ErrorActionPreference = "Stop"

if ($Hours -le 0) {
    throw "-Hours must be a positive integer."
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker CLI was not found. Install Docker Desktop, then run this script again."
}

$ProjectName = "openemr"

# Both queries anchor their cutoff in MySQL via NOW() - INTERVAL so we do not
# have to reconcile the host clock against the container clock.
#
# `log.comments` is always base64-encoded by EventAuditLogger when encryption
# is off (so binary UUIDs survive the round-trip and the audit checksum stays
# stable) and ciphertext when encryption is on. We JOIN log_comment_encrypt to
# decide which case applies and decode for display only — the underlying
# storage is untouched. FROM_BASE64 returns NULL on invalid input, so the
# COALESCE falls back to the raw value.
#
# Agent-emitted audit events:
#   - 'agent-access'              from AgentAccessBroker (allow/deny)
#   - 'agent-anonymizer-failure'  from Anonymizer (PHI redaction failure)
$logQuery = @"
SELECT
    log.id,
    log.date,
    log.event,
    log.user,
    log.groupname,
    log.patient_id,
    log.success,
    CASE
        WHEN lce.encrypt = 'Yes' THEN '<encrypted>'
        ELSE COALESCE(CONVERT(FROM_BASE64(log.comments) USING utf8mb4), log.comments)
    END AS comments
FROM log
LEFT JOIN log_comment_encrypt lce ON lce.log_id = log.id
WHERE log.event IN ('agent-access', 'agent-anonymizer-failure')
  AND log.date >= NOW() - INTERVAL $Hours HOUR
ORDER BY log.date DESC, log.id DESC;
"@

# AgentIntentRestController routes through ApiResponseLoggerListener, which
# writes the anonymized request/response payload to the `api_log` table on
# every /api/agent/* call (see src/RestControllers/Subscriber/ApiResponseLoggerListener.php).
# api_log fields are only encrypted when encryption is on, so no decode is
# needed in the default dev configuration.
$apiQuery = @"
SELECT id, created_time, user_id, patient_id, method, request_url, response
FROM api_log
WHERE request_url LIKE '%/api/agent/%'
  AND created_time >= NOW() - INTERVAL $Hours HOUR
ORDER BY created_time DESC, id DESC;
"@

function Invoke-AuditQuery {
    param(
        [string]$Sql,
        [string]$Heading
    )

    Write-Host ""
    Write-Host "=== $Heading ===" -ForegroundColor Cyan
    & docker compose --project-name $ProjectName exec -T mysql mariadb -uroot -proot openemr --table -e $Sql
    if ($LASTEXITCODE -ne 0) {
        throw "$Heading query failed (exit $LASTEXITCODE)."
    }
}

Invoke-AuditQuery -Sql $logQuery -Heading "log table (agent-* events, last $Hours h)"
Invoke-AuditQuery -Sql $apiQuery -Heading "api_log table (request_url LIKE '%/api/agent/%', last $Hours h)"
