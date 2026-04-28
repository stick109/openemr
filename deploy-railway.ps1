[CmdletBinding()]
param(
    [string]$Project,

    [string]$Service,

    [string]$Environment,

    [switch]$ConfigureVariables,

    [switch]$CreateSitesVolume,

    [string]$SitesVolumeMountPath = "/var/www/localhost/htdocs/openemr/sites",

    [string]$MysqlServiceName = "MySQL",

    [string]$MysqlHost,

    [string]$MysqlPort = "3306",

    [string]$MysqlRootUser = "root",

    [string]$MysqlDatabase = "openemr",

    [string]$MysqlUser = "openemr",

    [string]$OpenEmrAdminUser = "admin",

    [switch]$Detach,

    [switch]$Ci,

    [switch]$SkipDeploy
)

$ErrorActionPreference = "Stop"

function Confirm-RailwayCli {
    if (-not (Get-Command railway -ErrorAction SilentlyContinue)) {
        throw "Railway CLI was not found. Install it with npm, Scoop, or the Railway binary, then run this script again."
    }

    & railway --version | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to run Railway CLI."
    }
}

function Invoke-Railway {
    param([string[]]$Arguments)

    & railway @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "railway $($Arguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Invoke-RailwayWithInput {
    param(
        [string[]]$Arguments,
        [securestring]$Secret
    )

    $credential = New-Object System.Net.NetworkCredential("", $Secret)
    $plainText = $credential.Password
    try {
        $plainText | & railway @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "railway $($Arguments -join ' ') failed with exit code $LASTEXITCODE."
        }
    }
    finally {
        $plainText = $null
        $credential = $null
    }
}

function New-RailwayDeployScopeArguments {
    $scopeArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($Project)) {
        $scopeArguments += @("--project", $Project)
    }
    if (-not [string]::IsNullOrWhiteSpace($Service)) {
        $scopeArguments += @("--service", $Service)
    }
    if (-not [string]::IsNullOrWhiteSpace($Environment)) {
        $scopeArguments += @("--environment", $Environment)
    }

    return $scopeArguments
}

function New-RailwayServiceScopeArguments {
    $scopeArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($Service)) {
        $scopeArguments += @("--service", $Service)
    }
    if (-not [string]::IsNullOrWhiteSpace($Environment)) {
        $scopeArguments += @("--environment", $Environment)
    }

    return $scopeArguments
}

function Confirm-RailwayLogin {
    & railway whoami | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Railway is not logged in. Run 'railway login', then rerun this script."
    }
}

function Confirm-RailwayProject {
    & railway status | Out-Host
    if ($LASTEXITCODE -eq 0) {
        return
    }

    if (-not $ConfigureVariables -and -not $CreateSitesVolume -and -not [string]::IsNullOrWhiteSpace($Project)) {
        Write-Warning "No local Railway link was found. Continuing because -Project was supplied and this run does not need service variables or volumes."
        return
    }

    throw "Railway project/service is not linked. Run 'railway link', or pass -Project only for deploy-only runs."
}

function Read-RequiredSecureString {
    param([string]$Prompt)

    $secret = Read-Host -Prompt $Prompt -AsSecureString
    if ($secret.Length -eq 0) {
        throw "$Prompt is required."
    }

    return $secret
}

function Set-RailwayVariable {
    param(
        [string]$Name,
        [string]$Value
    )

    $arguments = @("variable", "set")
    $arguments += New-RailwayServiceScopeArguments
    $arguments += "--skip-deploys"
    $arguments += "$Name=$Value"

    Invoke-Railway -Arguments $arguments
}

function Set-RailwaySecretVariable {
    param(
        [string]$Name,
        [securestring]$Secret
    )

    $arguments = @("variable", "set")
    $arguments += New-RailwayServiceScopeArguments
    $arguments += "--skip-deploys"
    $arguments += "--stdin"
    $arguments += $Name

    Invoke-RailwayWithInput -Arguments $arguments -Secret $Secret
}

function Set-OpenEmrVariables {
    $mysqlRootPass = Read-RequiredSecureString -Prompt "MySQL root password for OpenEMR initialization"
    $mysqlPass = Read-RequiredSecureString -Prompt "OpenEMR database user password"
    $openEmrAdminPass = Read-RequiredSecureString -Prompt "Initial OpenEMR admin password"

    if ([string]::IsNullOrWhiteSpace($MysqlHost)) {
        $MysqlHost = '${{' + "$MysqlServiceName.MYSQLHOST" + '}}'
        $script:MysqlHost = $MysqlHost
    }

    if ([string]::IsNullOrWhiteSpace($MysqlPort)) {
        $MysqlPort = '${{' + "$MysqlServiceName.MYSQLPORT" + '}}'
        $script:MysqlPort = $MysqlPort
    }

    Set-RailwayVariable -Name "PORT" -Value "80"
    Set-RailwayVariable -Name "RAILWAY_RUN_UID" -Value "0"
    Set-RailwayVariable -Name "MYSQL_HOST" -Value $MysqlHost
    Set-RailwayVariable -Name "MYSQL_PORT" -Value $MysqlPort
    Set-RailwayVariable -Name "MYSQL_ROOT_USER" -Value $MysqlRootUser
    Set-RailwaySecretVariable -Name "MYSQL_ROOT_PASS" -Secret $mysqlRootPass
    Set-RailwayVariable -Name "MYSQL_DATABASE" -Value $MysqlDatabase
    Set-RailwayVariable -Name "MYSQL_USER" -Value $MysqlUser
    Set-RailwaySecretVariable -Name "MYSQL_PASS" -Secret $mysqlPass
    Set-RailwayVariable -Name "OE_USER" -Value $OpenEmrAdminUser
    Set-RailwaySecretVariable -Name "OE_PASS" -Secret $openEmrAdminPass

    Write-Host "Variables staged. Railway may require you to review/deploy staged variable changes in the dashboard."
}

function New-OpenEmrSitesVolume {
    Write-Host "Creating Railway volume at $SitesVolumeMountPath."
    Invoke-Railway -Arguments @("volume", "add", "--mount-path", $SitesVolumeMountPath)
}

function Invoke-RailwayDeploy {
    $arguments = @("up")
    $arguments += New-RailwayDeployScopeArguments
    $arguments += "--message"
    $arguments += "Deploy OpenEMR production image"

    if ($Detach) {
        $arguments += "--detach"
    }
    if ($Ci) {
        $arguments += "--ci"
    }

    Invoke-Railway -Arguments $arguments
}

Confirm-RailwayCli
Confirm-RailwayLogin
Confirm-RailwayProject

if ($ConfigureVariables) {
    Set-OpenEmrVariables
}
else {
    Write-Host "Skipping variable setup. Use -ConfigureVariables when you are ready to set OpenEMR and MySQL secrets."
}

if ($CreateSitesVolume) {
    New-OpenEmrSitesVolume
}

if ($SkipDeploy) {
    Write-Host "Skipping deployment because -SkipDeploy was supplied."
    exit 0
}

Invoke-RailwayDeploy
