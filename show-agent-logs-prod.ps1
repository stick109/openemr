param(
    [int]$Lines = 30
)

$ErrorActionPreference = "Stop"

if ($Lines -le 0) {
    throw "-Lines must be a positive integer."
}

if (-not (Get-Command railway -ErrorAction SilentlyContinue)) {
    throw "Railway CLI was not found. Install it with npm, Scoop, or the Railway binary, then run this script again."
}

$ServiceName = "agent-service"

# `railway logs --lines N` fetches historical container stdout/stderr (the
# Docker console stream Railway captures) without streaming, so the script
# returns instead of tailing.  --service pins this run to the agent sidecar
# regardless of which service the local checkout is linked to.
& railway logs --service $ServiceName --lines $Lines
if ($LASTEXITCODE -ne 0) {
    throw "railway logs --service $ServiceName --lines $Lines failed (exit $LASTEXITCODE)."
}
