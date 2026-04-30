param(
    [string]$ProjectName = "openemr",

    [switch]$ConfirmReset,

    [switch]$SkipWait
)

$ErrorActionPreference = "Stop"

function Confirm-DockerCompose {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "Docker CLI was not found. Install Docker Desktop, then run this script again."
    }

    & docker compose version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose v2 was not found. Install or enable the Docker Compose plugin, then run this script again."
    }
}

function Invoke-DockerCompose {
    param([string[]]$ComposeArguments)

    & docker compose --project-name $ProjectName @ComposeArguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose $($ComposeArguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Invoke-DockerComposeCapture {
    param([string[]]$ComposeArguments)

    $output = & docker compose --project-name $ProjectName @ComposeArguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose $($ComposeArguments -join ' ') failed with exit code $LASTEXITCODE."
    }

    return $output
}

if (-not $ConfirmReset) {
    throw "This script resets the OpenEMR dev database and loads demo data. Rerun with -ConfirmReset to explicitly allow the destructive reset."
}

Confirm-DockerCompose

$repoRoot = $PSScriptRoot
$composeDirectory = Join-Path $repoRoot "docker\development-easy"
$composeFile = Join-Path $composeDirectory "docker-compose.yml"
$medicationSqlPath = Join-Path $repoRoot "sql\demo_current_medications.sql"

if (-not (Test-Path $composeFile)) {
    throw "Compose file not found at $composeFile."
}

if (-not (Test-Path $medicationSqlPath)) {
    throw "Medication seed SQL not found at $medicationSqlPath."
}

$upArguments = @("up", "--detach")
if (-not $SkipWait) {
    $upArguments += "--wait"
}

$missingMedicationSql = "SELECT COUNT(*) FROM patient_data p WHERE NOT EXISTS (SELECT 1 FROM lists l WHERE l.pid = p.pid AND l.type = 'medication' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()));"
$medicationSummarySql = "SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, COUNT(l.id) AS active_medications FROM patient_data p LEFT JOIN lists l ON l.pid = p.pid AND l.type = 'medication' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()) GROUP BY p.pid, p.fname, p.lname ORDER BY p.pid;"
$loadMedicationSql = 'dbclient=mysql; if command -v mariadb >/dev/null 2>&1; then dbclient=mariadb; fi; "$dbclient" -hmysql -uopenemr -popenemr openemr < /openemr/sql/demo_current_medications.sql'

Push-Location $composeDirectory
try {
    Write-Host "Step 0: starting development-easy stack for project '$ProjectName'..."
    Invoke-DockerCompose -ComposeArguments $upArguments

    Write-Host "Step 1: resetting the dev database and loading OpenEMR demo data..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "/root/devtools", "dev-reset-install-demodata")

    Write-Host "Adding demo current medications for patients missing active medications..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "sh", "-c", $loadMedicationSql)

    Write-Host "Verifying every patient has at least one active medication..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingMedicationSql)
    $missingText = $missingOutput | Where-Object { $_ -match "\S" } | Select-Object -Last 1
    $missingCount = 0

    if ($null -eq $missingText -or -not [int]::TryParse($missingText.Trim(), [ref]$missingCount)) {
        throw "Could not parse missing-medication verification output: $($missingOutput -join ' ')"
    }

    if ($missingCount -ne 0) {
        throw "Medication verification failed: $missingCount patient(s) still lack active medication entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $medicationSummarySql)

    Write-Host "Done. Demo data is loaded and every patient has at least one active medication."
}
finally {
    Pop-Location
}
