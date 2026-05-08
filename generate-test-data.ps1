param(
    [string]$ProjectName = "development-easy",

    [switch]$SkipWait
)

$ErrorActionPreference = "Stop"

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)

    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function ConvertTo-QuotedArgument {
    param([Parameter(Mandatory)][string]$Value)

    return '"' + ($Value -replace '"', '\"') + '"'
}

function Invoke-SelfElevated {
    $powerShellPath = (Get-Process -Id $PID).Path
    if (-not $powerShellPath) {
        $powerShellName = if ($PSVersionTable.PSEdition -eq "Core") { "pwsh.exe" } else { "powershell.exe" }
        $powerShellPath = Join-Path $PSHOME $powerShellName
    }

    $scriptArguments = @(
        "-File",
        (ConvertTo-QuotedArgument -Value $PSCommandPath),
        "-ProjectName",
        (ConvertTo-QuotedArgument -Value $ProjectName)
    )

    if ($SkipWait) {
        $scriptArguments += "-SkipWait"
    }

    Write-Host "WARNING: GENERATE-TEST-DATA.PS1 IS REQUESTING ADMIN APPROVAL TO RESET THE OPENEMR DEV DATABASE AND LOAD DEMO DATA. APPROVE THE UAC PROMPT TO CONTINUE."
    Start-Sleep -Milliseconds 2500
    try {
        $process = Start-Process -FilePath $powerShellPath -ArgumentList $scriptArguments -Verb RunAs -WorkingDirectory $PSScriptRoot -Wait -PassThru
    } catch {
        Write-Host "FAILURE: Elevated generate-test-data.ps1 did not start. $($_.Exception.Message)"
        exit 1
    }

    if ($null -eq $process.ExitCode) {
        Write-Host "FAILURE: Elevated generate-test-data.ps1 finished without an exit code."
        exit 1
    }

    if ($process.ExitCode -eq 0) {
        Write-Host "SUCCESS: Elevated generate-test-data.ps1 finished with exit code 0."
    } else {
        Write-Host "FAILURE: Elevated generate-test-data.ps1 finished with exit code $($process.ExitCode)."
    }

    exit $process.ExitCode
}

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

if (-not (Test-IsAdministrator)) {
    Invoke-SelfElevated
    exit
}

Confirm-DockerCompose

$repoRoot = $PSScriptRoot
$composeDirectory = Join-Path $repoRoot "docker\development-easy"
$composeFile = Join-Path $composeDirectory "docker-compose.yml"
$medicationSqlPath = Join-Path $repoRoot "sql\demo_current_medications.sql"
$allergySqlPath = Join-Path $repoRoot "sql\demo_current_allergies.sql"
$recentEventSqlPath = Join-Path $repoRoot "sql\demo_recent_events.sql"

if (-not (Test-Path $composeFile)) {
    throw "Compose file not found at $composeFile."
}

if (-not (Test-Path $medicationSqlPath)) {
    throw "Medication seed SQL not found at $medicationSqlPath."
}

if (-not (Test-Path $allergySqlPath)) {
    throw "Allergy seed SQL not found at $allergySqlPath."
}

if (-not (Test-Path $recentEventSqlPath)) {
    throw "Recent event seed SQL not found at $recentEventSqlPath."
}

$upArguments = @("up", "--detach")
if (-not $SkipWait) {
    $upArguments += "--wait"
}

$missingMedicationSql = "SELECT COUNT(*) FROM patient_data p WHERE NOT EXISTS (SELECT 1 FROM lists l WHERE l.pid = p.pid AND l.type = 'medication' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()));"
$medicationSummarySql = "SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, COUNT(l.id) AS active_medications FROM patient_data p LEFT JOIN lists l ON l.pid = p.pid AND l.type = 'medication' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()) GROUP BY p.pid, p.fname, p.lname ORDER BY p.pid;"
$loadMedicationSql = 'dbclient=mysql; if command -v mariadb >/dev/null 2>&1; then dbclient=mariadb; fi; "$dbclient" -hmysql -uopenemr -popenemr openemr < /openemr/sql/demo_current_medications.sql'
$missingAllergySql = "SELECT COUNT(*) FROM patient_data p WHERE NOT EXISTS (SELECT 1 FROM lists l WHERE l.pid = p.pid AND l.type = 'allergy' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()));"
$allergySummarySql = "SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, COUNT(l.id) AS active_allergies FROM patient_data p LEFT JOIN lists l ON l.pid = p.pid AND l.type = 'allergy' AND l.activity = 1 AND (l.enddate IS NULL OR l.enddate >= CURDATE()) GROUP BY p.pid, p.fname, p.lname ORDER BY p.pid;"
$loadAllergySql = 'dbclient=mysql; if command -v mariadb >/dev/null 2>&1; then dbclient=mariadb; fi; "$dbclient" -hmysql -uopenemr -popenemr openemr < /openemr/sql/demo_current_allergies.sql'
$fillPatientContactSql = @'
UPDATE patient_data
SET
    street = CASE WHEN TRIM(COALESCE(street, '')) = '' THEN CONCAT(1000 + pid, ' Demo Lane') ELSE street END,
    city = CASE WHEN TRIM(COALESCE(city, '')) = '' THEN 'Demo City' ELSE city END,
    state = CASE WHEN TRIM(COALESCE(state, '')) = '' THEN 'CA' ELSE state END,
    postal_code = CASE WHEN TRIM(COALESCE(postal_code, '')) = '' THEN LPAD(90000 + MOD(pid, 1000), 5, '0') ELSE postal_code END,
    country_code = CASE WHEN TRIM(COALESCE(country_code, '')) = '' THEN 'US' ELSE country_code END,
    phone_home = CASE
        WHEN TRIM(COALESCE(phone_home, '')) = ''
          AND TRIM(COALESCE(phone_cell, '')) = ''
          AND TRIM(COALESCE(phone_biz, '')) = ''
          AND TRIM(COALESCE(phone_contact, '')) = ''
        THEN CONCAT('(202) 555-01', LPAD(MOD(pid, 100), 2, '0'))
        ELSE phone_home
    END
WHERE TRIM(COALESCE(street, '')) = ''
   OR TRIM(COALESCE(city, '')) = ''
   OR TRIM(COALESCE(state, '')) = ''
   OR TRIM(COALESCE(postal_code, '')) = ''
   OR TRIM(COALESCE(country_code, '')) = ''
   OR (
       TRIM(COALESCE(phone_home, '')) = ''
       AND TRIM(COALESCE(phone_cell, '')) = ''
       AND TRIM(COALESCE(phone_biz, '')) = ''
       AND TRIM(COALESCE(phone_contact, '')) = ''
   );
'@
$missingAddressSql = "SELECT COUNT(*) FROM patient_data p WHERE TRIM(COALESCE(p.street, '')) = '' OR TRIM(COALESCE(p.city, '')) = '' OR TRIM(COALESCE(p.state, '')) = '' OR TRIM(COALESCE(p.postal_code, '')) = '' OR TRIM(COALESCE(p.country_code, '')) = '';"
$missingPhoneSql = "SELECT COUNT(*) FROM patient_data p WHERE TRIM(COALESCE(p.phone_home, '')) = '' AND TRIM(COALESCE(p.phone_cell, '')) = '' AND TRIM(COALESCE(p.phone_biz, '')) = '' AND TRIM(COALESCE(p.phone_contact, '')) = '';"
$patientContactSummarySql = "SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, CONCAT(p.street, ', ', p.city, ', ', p.state, ' ', p.postal_code, ', ', p.country_code) AS address, COALESCE(NULLIF(TRIM(p.phone_home), ''), NULLIF(TRIM(p.phone_cell), ''), NULLIF(TRIM(p.phone_biz), ''), NULLIF(TRIM(p.phone_contact), '')) AS phone FROM patient_data p ORDER BY p.pid;"
$missingRecentEventSql = "SELECT COUNT(*) FROM patient_data p WHERE NOT EXISTS (SELECT 1 FROM form_encounter fe WHERE fe.pid = p.pid AND fe.date >= CURDATE() - INTERVAL 30 DAY);"
$recentEventSummarySql = "SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, COUNT(fe.id) AS recent_events FROM patient_data p LEFT JOIN form_encounter fe ON fe.pid = p.pid AND fe.date >= CURDATE() - INTERVAL 30 DAY GROUP BY p.pid, p.fname, p.lname ORDER BY p.pid;"
$missingUserRecentEventSql = "SELECT COUNT(*) FROM users u WHERE u.active = 1 AND u.authorized = 1 AND NOT EXISTS (SELECT 1 FROM form_encounter fe WHERE fe.provider_id = u.id AND fe.date >= CURDATE() - INTERVAL 30 DAY);"
$userRecentEventSummarySql = "SELECT u.id, u.username, CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, '')) AS user, COUNT(fe.id) AS recent_events FROM users u LEFT JOIN form_encounter fe ON fe.provider_id = u.id AND fe.date >= CURDATE() - INTERVAL 30 DAY WHERE u.active = 1 AND u.authorized = 1 GROUP BY u.id, u.username, u.fname, u.lname ORDER BY u.id;"
$loadRecentEventSql = 'dbclient=mysql; if command -v mariadb >/dev/null 2>&1; then dbclient=mariadb; fi; "$dbclient" -hmysql -uopenemr -popenemr openemr < /openemr/sql/demo_recent_events.sql'

function ConvertTo-VerifiedCount {
    param(
        [AllowEmptyCollection()][AllowNull()][string[]]$Output,
        [Parameter(Mandatory)][string]$Description
    )

    $text = $Output | Where-Object { $_ -match "\S" } | Select-Object -Last 1
    $count = 0

    if ($null -eq $text -or -not [int]::TryParse($text.Trim(), [ref]$count)) {
        throw "Could not parse $Description verification output: $($Output -join ' ')"
    }

    return $count
}

Push-Location $composeDirectory
try {
    Write-Host "Step 0: starting development-easy stack for project '$ProjectName'..."
    Invoke-DockerCompose -ComposeArguments $upArguments

    Write-Host "Step 1: resetting the dev database and loading OpenEMR demo data..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "/root/devtools", "dev-reset-install-demodata")

    Write-Host "Adding demo current medications for patients missing active medications..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "sh", "-c", $loadMedicationSql)

    Write-Host "Adding demo current allergies for patients missing active allergies..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "sh", "-c", $loadAllergySql)

    Write-Host "Adding demo recent events for patients and active users missing recent events..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "openemr", "sh", "-c", $loadRecentEventSql)

    Write-Host "Adding demo addresses and phone numbers for patients missing contact details..."
    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $fillPatientContactSql)

    Write-Host "Verifying every patient has at least one active medication..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingMedicationSql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-medication"

    if ($missingCount -ne 0) {
        throw "Medication verification failed: $missingCount patient(s) still lack active medication entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $medicationSummarySql)

    Write-Host "Verifying every patient has at least one active allergy..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingAllergySql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-allergy"

    if ($missingCount -ne 0) {
        throw "Allergy verification failed: $missingCount patient(s) still lack active allergy entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $allergySummarySql)

    Write-Host "Verifying every patient has a complete address..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingAddressSql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-address"

    if ($missingCount -ne 0) {
        throw "Address verification failed: $missingCount patient(s) still lack complete address entries."
    }

    Write-Host "Verifying every patient has at least one phone number..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingPhoneSql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-phone"

    if ($missingCount -ne 0) {
        throw "Phone verification failed: $missingCount patient(s) still lack phone number entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $patientContactSummarySql)

    Write-Host "Verifying every patient has at least one recent event..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingRecentEventSql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-recent-event"

    if ($missingCount -ne 0) {
        throw "Recent event verification failed: $missingCount patient(s) still lack recent event entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $recentEventSummarySql)

    Write-Host "Verifying every active user has at least one recent event..."
    $missingOutput = Invoke-DockerComposeCapture -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "--batch", "--skip-column-names", "-e", $missingUserRecentEventSql)
    $missingCount = ConvertTo-VerifiedCount -Output $missingOutput -Description "missing-user-recent-event"

    if ($missingCount -ne 0) {
        throw "Recent event verification failed: $missingCount active user(s) still lack recent event entries."
    }

    Invoke-DockerCompose -ComposeArguments @("exec", "-T", "mysql", "mariadb", "-uroot", "-proot", "openemr", "-e", $userRecentEventSummarySql)

    Write-Host "Done. Demo data is loaded and every patient has an address, a phone number, and at least one active medication, allergy, and recent event."
}
finally {
    Pop-Location
}
