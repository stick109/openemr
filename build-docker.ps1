param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "openemr",

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

Confirm-DockerCompose

$repoRoot = $PSScriptRoot
$composeDirectory = Get-ComposeDirectory -SelectedProfile $Profile
$composeFile = Join-Path $repoRoot (Join-Path $composeDirectory "docker-compose.yml")

if (-not (Test-Path $composeFile)) {
    throw "Compose file not found at $composeDirectory\docker-compose.yml."
}

Push-Location (Join-Path $repoRoot $composeDirectory)
try {
    Write-Host "Using compose profile: $composeDirectory"

    if (-not $NoPull) {
        Write-Host "Pulling container images..."
        Invoke-DockerCompose -ComposeArguments @("pull")
    }

    Write-Host "Building services that define local build contexts..."
    Invoke-DockerCompose -ComposeArguments @("build", "--pull")

    Write-Host "Docker images are ready. Run run-docker.ps1 from the repository root to start the app."
}
finally {
    Pop-Location
}
