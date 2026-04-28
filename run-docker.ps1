param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "openemr",

    [switch]$Build,

    [switch]$Pull,

    [switch]$Foreground,

    [string]$HttpPort,

    [string]$HttpsPort,

    [string]$PhpMyAdminPort,

    [string]$MysqlPort
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
    Invoke-DockerCompose -ComposeArguments $upArguments

    if (-not $Foreground) {
        Write-Host ""
        Write-Host "OpenEMR is starting in the background."

        if ($Profile -eq "production") {
            Write-Host "OpenEMR HTTP:  http://localhost/"
            Write-Host "OpenEMR HTTPS: https://localhost/"
        }
        else {
            Write-Host "OpenEMR HTTP:  http://localhost:$(Get-PortValue -Name "WT_HTTP_PORT" -DefaultValue "8300")/"
            Write-Host "OpenEMR HTTPS: https://localhost:$(Get-PortValue -Name "WT_HTTPS_PORT" -DefaultValue "9300")/"
        }

        Write-Host "Login: admin / pass"

        if ($Profile -ne "production") {
            Write-Host "phpMyAdmin:    http://localhost:$(Get-PortValue -Name "WT_PMA_PORT" -DefaultValue "8310")/"
            Write-Host "MySQL:         localhost:$(Get-PortValue -Name "WT_MYSQL_PORT" -DefaultValue "8320")"
        }
    }
}
finally {
    Pop-Location
}
