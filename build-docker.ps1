param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "openemr",

    [string]$LocalImageName = "openemr-local:latest",

    [string]$DockerfilePath = "Dockerfile.railway",

    [switch]$NoPull
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

function Get-ComposeDirectory {
    param([string]$SelectedProfile)

    if ($SelectedProfile -eq "production") {
        return "docker\production"
    }

    return "docker\$SelectedProfile"
}

function Invoke-DockerCompose {
    param([string[]]$ComposeArguments)

    & docker compose --project-name $ProjectName @ComposeArguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose $($ComposeArguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Invoke-DockerBuild {
    param(
        [string]$Dockerfile,
        [string]$ImageName,
        [switch]$Pull
    )

    $arguments = @("build")
    if ($Pull) {
        $arguments += "--pull"
    }
    $arguments += "--file"
    $arguments += $Dockerfile
    $arguments += "--tag"
    $arguments += $ImageName
    $arguments += "."

    & docker @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($arguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

Confirm-DockerCompose

$repoRoot = $PSScriptRoot
$composeDirectory = Get-ComposeDirectory -SelectedProfile $Profile
$composeFile = Join-Path $repoRoot (Join-Path $composeDirectory "docker-compose.yml")
$localDockerfile = Join-Path $repoRoot $DockerfilePath

if (-not (Test-Path $composeFile)) {
    throw "Compose file not found at $composeDirectory\docker-compose.yml."
}
if (-not (Test-Path $localDockerfile)) {
    throw "Dockerfile not found at $DockerfilePath."
}

Push-Location $repoRoot
try {
    Write-Host "Using compose profile: $composeDirectory"
    Write-Host "Using Dockerfile: $DockerfilePath"
    Write-Host "Building local OpenEMR image: $LocalImageName"

    if (-not $NoPull) {
        Push-Location (Join-Path $repoRoot $composeDirectory)
        try {
            Write-Host "Pulling compose dependency images..."
            Invoke-DockerCompose -ComposeArguments @("pull", "--ignore-buildable")
        }
        finally {
            Pop-Location
        }
    }

    Invoke-DockerBuild -Dockerfile $localDockerfile -ImageName $LocalImageName -Pull:(-not $NoPull)

    if ($LocalImageName -ne "openemr-local:latest") {
        Write-Host "Set OPENEMR_LOCAL_IMAGE=$LocalImageName before running run-docker.ps1 so Compose uses this tag."
    }

    Write-Host "Local OpenEMR image is ready. Run run-docker.ps1 -Restart from the repository root to recreate the app container from it."
}
finally {
    Pop-Location
}
