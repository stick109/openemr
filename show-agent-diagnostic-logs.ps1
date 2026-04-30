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
$culture = [System.Globalization.CultureInfo]::InvariantCulture

# The Apache error log timestamps are in container-local time, so anchor the
# cutoff to the container's clock instead of the Windows host clock.
$nowRaw = & docker compose --project-name $ProjectName exec -T openemr date "+%Y-%m-%d %H:%M:%S"
if ($LASTEXITCODE -ne 0) {
    throw "Failed to read container time (exit $LASTEXITCODE)."
}

$nowLine = $nowRaw | Where-Object { $_ -match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$' } | Select-Object -Last 1
if (-not $nowLine) {
    throw "Could not parse container time from output: $($nowRaw -join '|')"
}

$containerNow = [DateTime]::ParseExact($nowLine, "yyyy-MM-dd HH:mm:ss", $culture)
$cutoff = $containerNow.AddHours(-$Hours)

$rawLines = & docker compose --project-name $ProjectName exec -T openemr /root/devtools php-log
if ($LASTEXITCODE -ne 0) {
    throw "Failed to read PHP error log (exit $LASTEXITCODE)."
}

# Apache error log line prefix: [Thu Apr 30 00:19:54.433214 2026]
$timestampPattern = '^\[(?<dow>[A-Z][a-z]{2}) (?<mon>[A-Z][a-z]{2})\s+(?<day>\d{1,2}) (?<time>\d{2}:\d{2}:\d{2})(?:\.\d+)? (?<year>\d{4})\]'

# Agent code emits PSR-3 messages through OpenEMR\Common\Logging\SystemLogger,
# which formats them as "OpenEMR.<LEVEL>: <message>". Agent emissions use a
# dotted "agent." message prefix (see AgentEvidenceToolset::timedRead).
$agentPattern = 'OpenEMR\.[A-Z]+: agent\.'

$emitted = 0
foreach ($line in $rawLines) {
    if ($line -notmatch $agentPattern) { continue }

    if ($line -match $timestampPattern) {
        $tsString = "$($Matches.mon) $($Matches.day) $($Matches.year) $($Matches.time)"
        try {
            $entryTime = [DateTime]::ParseExact($tsString, "MMM d yyyy HH:mm:ss", $culture)
            if ($entryTime -lt $cutoff) { continue }
        } catch {
            # Could not parse a timestamp we matched; emit anyway so nothing is lost silently.
        }
    }

    Write-Output $line
    $emitted++
}

if ($emitted -eq 0) {
    Write-Host "No agent diagnostic log records in the last $Hours hour(s)." -ForegroundColor Yellow
}
