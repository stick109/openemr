param(
    [string]$ProjectName = "openemr"
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

function Confirm-MysqlContainerRunning {
    $running = & docker compose --project-name $ProjectName ps --services --filter "status=running"
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps failed with exit code $LASTEXITCODE. Is the '$ProjectName' stack started?"
    }

    if (-not ($running -contains "mysql")) {
        throw "The 'mysql' service is not running for project '$ProjectName'. Start it with: docker compose --project-name $ProjectName up --detach --wait"
    }
}

Confirm-DockerCompose
Confirm-MysqlContainerRunning

$listPatientsSql = @'
SELECT
    pid,
    TRIM(BOTH ' ' FROM REGEXP_REPLACE(CONCAT_WS(' ', NULLIF(TRIM(fname), ''), NULLIF(TRIM(mname), ''), NULLIF(TRIM(lname), '')), '[[:space:]]+', ' ')) AS full_name,
    DATE_FORMAT(DOB, '%Y-%m-%d') AS dob
FROM patient_data
ORDER BY pid;
'@

$rawOutput = & docker compose --project-name $ProjectName exec -T mysql mariadb -uopenemr -popenemr openemr --batch --skip-column-names -e $listPatientsSql
if ($LASTEXITCODE -ne 0) {
    throw "Patient query failed with exit code $LASTEXITCODE."
}

$patients = foreach ($line in $rawOutput) {
    if ([string]::IsNullOrWhiteSpace($line)) { continue }

    $fields = $line -split "`t", 3
    if ($fields.Count -lt 3) { continue }

    [pscustomobject]@{
        ID       = [int]$fields[0]
        FullName = $fields[1]
        DOB      = if ($fields[2] -eq "NULL" -or [string]::IsNullOrWhiteSpace($fields[2])) { $null } else { $fields[2] }
    }
}

if ($null -eq $patients -or @($patients).Count -eq 0) {
    Write-Host "No patients found in the openemr database."
    return
}

$patients | Format-Table -AutoSize
Write-Host "Total patients: $(@($patients).Count)"
