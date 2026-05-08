param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "development-easy",

    [string]$LocalImageName = "openemr-local:latest",

    [string]$DockerfilePath = "Dockerfile.railway",

    [string]$LocalAgentImageName = "agent-service-local:latest",

    [string]$AgentDockerfilePath = "agent-service\Dockerfile",

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
        [string]$Context = ".",
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
    $arguments += $Context

    # Windows PowerShell 5.1 wraps each line a native command writes to
    # stderr as a NativeCommandError ErrorRecord. ``docker build`` prints
    # buildkit progress to stderr ("#0 building with...", "#1 [internal]
    # load build definition", etc.), so under ``$ErrorActionPreference =
    # 'Stop'`` (set at the top of this script) the first such line
    # terminates the pipeline before ``$LASTEXITCODE`` can be checked.
    # Switch to ``Continue`` for just the docker call so progress output
    # does not abort the build, then restore the prior preference and
    # rely on ``$LASTEXITCODE`` for the real success/failure signal.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        & docker @arguments
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($arguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Get-DockerComposeServices {
    $output = & docker compose --project-name $ProjectName config --services
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose config --services failed with exit code $LASTEXITCODE."
    }

    return @($output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

Confirm-DockerCompose

$repoRoot = $PSScriptRoot
$composeDirectory = Get-ComposeDirectory -SelectedProfile $Profile
$composeFile = Join-Path $repoRoot (Join-Path $composeDirectory "docker-compose.yml")
$localDockerfile = Join-Path $repoRoot $DockerfilePath
$localAgentDockerfile = Join-Path $repoRoot $AgentDockerfilePath
$localAgentContext = Split-Path -Parent $localAgentDockerfile

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

    Push-Location (Join-Path $repoRoot $composeDirectory)
    try {
        $services = Get-DockerComposeServices

        if (-not $NoPull) {
            Write-Host "Pulling compose dependency images..."
            Invoke-DockerCompose -ComposeArguments @("pull", "--ignore-buildable")
        }
    }
    finally {
        Pop-Location
    }

    $includeAgent = $services -contains "agent-service"
    if ($includeAgent) {
        if (-not (Test-Path $localAgentDockerfile)) {
            throw "Agent Dockerfile not found at $AgentDockerfilePath."
        }
        Write-Host "Using agent Dockerfile: $AgentDockerfilePath"
        Write-Host "Building local agent-service image: $LocalAgentImageName"
    }

    Invoke-DockerBuild -Dockerfile $localDockerfile -ImageName $LocalImageName -Pull:(-not $NoPull)

    if ($LocalImageName -ne "openemr-local:latest") {
        Write-Host "Set OPENEMR_LOCAL_IMAGE=$LocalImageName before running run-docker.ps1 so Compose uses this tag."
    }

    if ($includeAgent) {
        Invoke-DockerBuild -Dockerfile $localAgentDockerfile -ImageName $LocalAgentImageName -Context $localAgentContext -Pull:(-not $NoPull)

        if ($LocalAgentImageName -ne "agent-service-local:latest") {
            Write-Host "Set AGENT_SERVICE_LOCAL_IMAGE=$LocalAgentImageName before running run-docker.ps1 so Compose uses this tag."
        }
    }

    Write-Host "Local OpenEMR image is ready. Run run-docker.ps1 -Restart from the repository root to recreate the app container from it."
    if ($includeAgent) {
        Write-Host "Local agent-service image is ready as well."
    }
}
finally {
    Pop-Location
}
