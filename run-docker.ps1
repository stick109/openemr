param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "openemr",

    [int]$DockerStartupTimeoutSeconds = 120,

    [switch]$Build,

    [switch]$Pull,

    [switch]$Foreground,

    [switch]$Restart,

    [string]$HttpPort,

    [string]$HttpsPort,

    [string]$PhpMyAdminPort,

    [string]$MysqlPort
)

$ErrorActionPreference = "Stop"

function Test-DockerDaemon {
    $ErrorActionPreference = "Continue"
    try {
        & docker info 1>$null 2>$null
        return ($LASTEXITCODE -eq 0)
    }
    catch {
        return $false
    }
}

function Start-DockerDesktop {
    $candidates = @(
        (Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"),
        (Join-Path ${env:ProgramFiles(x86)} "Docker\Docker\Docker Desktop.exe"),
        (Join-Path $env:LocalAppData "Docker\Docker Desktop.exe")
    )

    $dockerDesktop = $candidates | Where-Object { -not [string]::IsNullOrWhiteSpace($_) -and (Test-Path $_) } | Select-Object -First 1
    if ($null -eq $dockerDesktop) {
        return $false
    }

    Write-Host "Docker daemon is not running. Starting Docker Desktop..."
    Start-Process -FilePath $dockerDesktop
    return $true
}

function Wait-DockerDaemon {
    param([int]$TimeoutSeconds)

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-DockerDaemon) {
            return $true
        }

        Start-Sleep -Seconds 2
    }

    return $false
}

function Confirm-DockerCompose {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "Docker CLI was not found. Install Docker Desktop, then run this script again."
    }

    & docker compose version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose v2 was not found. Install or enable the Docker Compose plugin, then run this script again."
    }

    if (Test-DockerDaemon) {
        return
    }

    $startedDockerDesktop = Start-DockerDesktop
    if ($startedDockerDesktop) {
        Write-Host "Waiting up to $DockerStartupTimeoutSeconds seconds for Docker Desktop..."
        if (Wait-DockerDaemon -TimeoutSeconds $DockerStartupTimeoutSeconds) {
            return
        }

        throw "Docker Desktop was started, but the Docker daemon was not ready after $DockerStartupTimeoutSeconds seconds. Wait for Docker Desktop to finish starting, then run this script again."
    }

    throw "Docker CLI is installed, but the Docker daemon is not running. Start Docker Desktop, then run this script again."
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

function Get-DockerComposeServices {
    $output = & docker compose --project-name $ProjectName config --services
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose config --services failed with exit code $LASTEXITCODE."
    }

    return @($output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Get-DockerComposeContainers {
    $output = & docker compose --project-name $ProjectName ps --format json
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps --format json failed with exit code $LASTEXITCODE."
    }

    $containers = @()
    foreach ($line in @($output)) {
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        $containers += ($line | ConvertFrom-Json)
    }

    return $containers
}

function Test-DockerComposeStackRunning {
    param(
        [string[]]$ExpectedServices,
        [object[]]$Containers
    )

    if ($ExpectedServices.Count -eq 0 -or $Containers.Count -eq 0) {
        return $false
    }

    foreach ($service in $ExpectedServices) {
        $matchingContainers = @($Containers | Where-Object { $_.Service -eq $service })
        if ($matchingContainers.Count -eq 0) {
            return $false
        }

        $runningContainers = @($matchingContainers | Where-Object { $_.State -eq "running" })
        if ($runningContainers.Count -eq 0) {
            return $false
        }
    }

    return $true
}

function Write-DockerComposeStatus {
    param(
        [string[]]$ExpectedServices,
        [object[]]$Containers
    )

    if ($Containers.Count -eq 0) {
        Write-Host "No containers found for compose project '$ProjectName'."
        return
    }

    foreach ($service in $ExpectedServices) {
        $serviceContainers = @($Containers | Where-Object { $_.Service -eq $service })
        if ($serviceContainers.Count -eq 0) {
            [pscustomobject]@{
                Service = $service
                State = "missing"
                Health = ""
                Status = ""
            }
            continue
        }

        foreach ($container in $serviceContainers) {
            [pscustomobject]@{
                Service = $container.Service
                State = $container.State
                Health = $container.Health
                Status = $container.Status
            }
        }
    }
}

function Set-PortOverride {
    param(
        [string]$Name,
        [string]$Value
    )

    if (-not [string]::IsNullOrWhiteSpace($Value)) {
        Set-Item -Path "Env:$Name" -Value $Value
    }
}

function Get-PortValue {
    param(
        [string]$Name,
        [string]$DefaultValue
    )

    $value = Get-Item -Path "Env:$Name" -ErrorAction SilentlyContinue
    if ($null -eq $value -or [string]::IsNullOrWhiteSpace($value.Value)) {
        return $DefaultValue
    }

    return $value.Value
}

function Get-OpenEmrHttpsEndpoint {
    if ($Profile -eq "production") {
        return "https://localhost/"
    }

    return "https://localhost:$(Get-PortValue -Name "WT_HTTPS_PORT" -DefaultValue "9300")/"
}

Confirm-DockerCompose

Set-PortOverride -Name "WT_HTTP_PORT" -Value $HttpPort
Set-PortOverride -Name "WT_HTTPS_PORT" -Value $HttpsPort
Set-PortOverride -Name "WT_PMA_PORT" -Value $PhpMyAdminPort
Set-PortOverride -Name "WT_MYSQL_PORT" -Value $MysqlPort

$repoRoot = $PSScriptRoot
$composeDirectory = Get-ComposeDirectory -SelectedProfile $Profile
$composeFile = Join-Path $repoRoot (Join-Path $composeDirectory "docker-compose.yml")

if (-not (Test-Path $composeFile)) {
    throw "Compose file not found at $composeDirectory\docker-compose.yml."
}

$upArguments = @("up")
if (-not $Foreground) {
    $upArguments += "-d"
}
if ($Build) {
    $upArguments += "--build"
}
if ($Pull) {
    $upArguments += "--pull"
    $upArguments += "always"
}

Push-Location (Join-Path $repoRoot $composeDirectory)
try {
    Write-Host "Using compose profile: $composeDirectory"
    $expectedServices = Get-DockerComposeServices
    $containers = @(Get-DockerComposeContainers)
    $stackRunning = Test-DockerComposeStackRunning -ExpectedServices $expectedServices -Containers $containers

    if ($stackRunning) {
        Write-Host "Compose stack '$ProjectName' is already running."
        Write-DockerComposeStatus -ExpectedServices $expectedServices -Containers $containers | Format-Table -AutoSize

        if (-not $Restart) {
            Write-Host "No changes made. Use -Restart to stop and start the running stack."
            return
        }

        Write-Host "Restart requested. Stopping running stack..."
        Invoke-DockerCompose -ComposeArguments @("stop")
    }

    Invoke-DockerCompose -ComposeArguments $upArguments

    if (-not $Foreground) {
        $httpsEndpoint = Get-OpenEmrHttpsEndpoint

        Write-Host ""
        Write-Host "OpenEMR is starting in the background."

        if ($Profile -eq "production") {
            Write-Host "OpenEMR HTTP:  http://localhost/"
            Write-Host "OpenEMR HTTPS: $httpsEndpoint"
        }
        else {
            Write-Host "OpenEMR HTTP:  http://localhost:$(Get-PortValue -Name "WT_HTTP_PORT" -DefaultValue "8300")/"
            Write-Host "OpenEMR HTTPS: $httpsEndpoint"
        }

        Write-Host "Login: admin / pass"

        if ($Profile -ne "production") {
            Write-Host "phpMyAdmin:    http://localhost:$(Get-PortValue -Name "WT_PMA_PORT" -DefaultValue "8310")/"
            Write-Host "MySQL:         localhost:$(Get-PortValue -Name "WT_MYSQL_PORT" -DefaultValue "8320")"
        }

        Write-Host "Opening HTTPS endpoint in the default browser..."
        Start-Process -FilePath $httpsEndpoint
    }
}
finally {
    Pop-Location
}
