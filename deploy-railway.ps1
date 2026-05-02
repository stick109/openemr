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

    [string]$MysqlPort,

    [string]$MysqlRootUser = "root",

    [string]$MysqlDatabase = "openemr",

    [string]$MysqlUser = "openemr",

    [string]$MysqlPassword,

    [string]$OpenEmrAdminUser = "admin",

    [switch]$Detach,

    [switch]$Ci,

    [string]$EnvFile = ".env",

    [switch]$SkipEnvSync,

    [switch]$AllowEmptyEnvValues,

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

function ConvertTo-NativeArgument {
    param([string]$Argument)

    if ($null -eq $Argument -or $Argument.Length -eq 0) {
        return '""'
    }

    if ($Argument -notmatch '[\s"]') {
        return $Argument
    }

    $builder = New-Object System.Text.StringBuilder
    $null = $builder.Append('"')
    $backslashCount = 0

    foreach ($character in $Argument.ToCharArray()) {
        if ($character -eq [char]92) {
            $backslashCount++
            continue
        }

        if ($character -eq [char]34) {
            if ($backslashCount -gt 0) {
                $null = $builder.Append('\' * (($backslashCount * 2) + 1))
                $backslashCount = 0
            }
            else {
                $null = $builder.Append('\')
            }

            $null = $builder.Append('"')
            continue
        }

        if ($backslashCount -gt 0) {
            $null = $builder.Append('\' * $backslashCount)
            $backslashCount = 0
        }

        $null = $builder.Append($character)
    }

    if ($backslashCount -gt 0) {
        $null = $builder.Append('\' * ($backslashCount * 2))
    }

    $null = $builder.Append('"')
    return $builder.ToString()
}

function ConvertTo-NativeArgumentString {
    param([string[]]$Arguments)

    return (($Arguments | ForEach-Object { ConvertTo-NativeArgument -Argument $_ }) -join " ")
}

function Resolve-RailwayExecutable {
    $railwayCommand = Get-Command railway -ErrorAction Stop
    $railwayExecutable = $railwayCommand.Source

    if ([string]::IsNullOrWhiteSpace($railwayExecutable)) {
        return "railway"
    }

    if (
        $railwayExecutable.EndsWith(".ps1", [System.StringComparison]::OrdinalIgnoreCase) -or
        $railwayExecutable.EndsWith(".cmd", [System.StringComparison]::OrdinalIgnoreCase)
    ) {
        $shimDirectory = Split-Path -Parent $railwayExecutable
        $nativeExecutable = Join-Path $shimDirectory "node_modules\@railway\cli\bin\railway.exe"
        if (Test-Path -LiteralPath $nativeExecutable) {
            return $nativeExecutable
        }

        if ($railwayExecutable.EndsWith(".ps1", [System.StringComparison]::OrdinalIgnoreCase)) {
            $cmdShim = [System.IO.Path]::ChangeExtension($railwayExecutable, ".cmd")
            if (Test-Path -LiteralPath $cmdShim) {
                return $cmdShim
            }
        }
    }

    return $railwayExecutable
}

function Set-ProcessStartInfoArguments {
    param(
        [System.Diagnostics.ProcessStartInfo]$StartInfo,
        [string[]]$Arguments
    )

    $argumentListProperty = $StartInfo.PSObject.Properties["ArgumentList"]
    if ($null -ne $argumentListProperty) {
        foreach ($argument in $Arguments) {
            $StartInfo.ArgumentList.Add($argument)
        }

        return
    }

    $StartInfo.Arguments = ConvertTo-NativeArgumentString -Arguments $Arguments
}

function Invoke-RailwayWithInput {
    param(
        [string[]]$Arguments,
        [securestring]$Secret,
        [switch]$SuppressSuccessOutput
    )

    $railwayExecutable = Resolve-RailwayExecutable

    $credential = New-Object System.Net.NetworkCredential("", $Secret)
    $plainText = $credential.Password
    $process = $null
    try {
        $startInfo = New-Object System.Diagnostics.ProcessStartInfo
        $startInfo.FileName = $railwayExecutable
        $startInfo.WorkingDirectory = (Get-Location).ProviderPath
        Set-ProcessStartInfoArguments -StartInfo $startInfo -Arguments $Arguments
        $startInfo.RedirectStandardInput = $true
        $startInfo.RedirectStandardOutput = $true
        $startInfo.RedirectStandardError = $true
        $startInfo.UseShellExecute = $false

        $process = [System.Diagnostics.Process]::Start($startInfo)
        $process.StandardInput.Write($plainText)
        $process.StandardInput.Close()

        $standardOutput = $process.StandardOutput.ReadToEnd()
        $standardError = $process.StandardError.ReadToEnd()
        $process.WaitForExit()

        if ($process.ExitCode -ne 0) {
            if (-not [string]::IsNullOrWhiteSpace($standardOutput)) {
                Write-Host $standardOutput.TrimEnd()
            }
            if (-not [string]::IsNullOrWhiteSpace($standardError)) {
                Write-Error $standardError.TrimEnd()
            }
            throw "railway $($Arguments -join ' ') failed with exit code $($process.ExitCode)."
        }
        if (-not $SuppressSuccessOutput) {
            if (-not [string]::IsNullOrWhiteSpace($standardOutput)) {
                Write-Host $standardOutput.TrimEnd()
            }
            if (-not [string]::IsNullOrWhiteSpace($standardError)) {
                Write-Host $standardError.TrimEnd()
            }
        }
    }
    finally {
        $plainText = $null
        $credential = $null
        if ($null -ne $process) {
            $process.Dispose()
        }
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

function New-RailwayRedeployScopeArguments {
    $scopeArguments = @()

    if (-not [string]::IsNullOrWhiteSpace($Service)) {
        $scopeArguments += @("--service", $Service)
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
    $statusOutput = & railway status
    $statusOutput | Out-Host
    if ($LASTEXITCODE -eq 0) {
        Set-RailwayDefaultScopeFromStatusOutput -StatusOutput $statusOutput
        return
    }

    if (-not $ConfigureVariables -and -not $CreateSitesVolume -and -not $script:ShouldSyncEnvFile -and -not [string]::IsNullOrWhiteSpace($Project)) {
        Write-Warning "No local Railway link was found. Continuing because -Project was supplied and this run does not need service variables or volumes."
        return
    }

    throw "Railway project/service is not linked. Run 'railway link', or pass -Project only for deploy-only runs."
}

function Set-RailwayDefaultScopeFromStatusOutput {
    param([string[]]$StatusOutput)

    foreach ($line in $StatusOutput) {
        if ([string]::IsNullOrWhiteSpace($script:Environment) -and $line -match "^Environment:\s*(?<Value>.+?)\s*$") {
            $script:Environment = $Matches.Value.Trim()
            continue
        }

        if ([string]::IsNullOrWhiteSpace($script:Service) -and $line -match "^Service:\s*(?<Value>.+?)\s*$") {
            $script:Service = $Matches.Value.Trim()
            continue
        }
    }
}

function Read-RequiredSecureString {
    param([string]$Prompt)

    $secret = Read-Host -Prompt $Prompt -AsSecureString
    if ($secret.Length -eq 0) {
        throw "$Prompt is required."
    }

    return $secret
}

function ConvertFrom-DotEnvValue {
    param(
        [string]$RawValue,
        [string]$Path,
        [int]$LineNumber
    )

    $value = $RawValue.Trim()
    if ($value.Length -eq 0) {
        return ""
    }

    $doubleQuote = [char]34
    $singleQuote = [char]39
    $backslash = [char]92

    if ($value[0] -eq $doubleQuote -or $value[0] -eq $singleQuote) {
        $quote = $value[0]
        $builder = New-Object System.Text.StringBuilder
        $escaped = $false

        for ($i = 1; $i -lt $value.Length; $i++) {
            $character = $value[$i]

            if ($quote -eq $doubleQuote -and $escaped) {
                switch ($character) {
                    "n" { $null = $builder.Append("`n") }
                    "r" { $null = $builder.Append("`r") }
                    "t" { $null = $builder.Append("`t") }
                    default { $null = $builder.Append($character) }
                }
                $escaped = $false
                continue
            }

            if ($quote -eq $doubleQuote -and $character -eq $backslash) {
                $escaped = $true
                continue
            }

            if ($character -eq $quote) {
                $remainder = $value.Substring($i + 1).Trim()
                if ($remainder.Length -gt 0 -and -not $remainder.StartsWith("#")) {
                    throw "Invalid .env syntax in $Path at line ${LineNumber}: unexpected characters after quoted value."
                }

                return $builder.ToString()
            }

            $null = $builder.Append($character)
        }

        throw "Invalid .env syntax in $Path at line ${LineNumber}: quoted value is not terminated."
    }

    return ($value -replace "\s+#.*$", "").Trim()
}

function ConvertFrom-DotEnvLine {
    param(
        [string]$Line,
        [string]$Path,
        [int]$LineNumber
    )

    $trimmed = $Line.Trim()
    if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith("#")) {
        return $null
    }

    if ($trimmed -notmatch "^(?:export\s+)?(?<Name>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?<Value>.*)$") {
        throw "Invalid .env syntax in $Path at line ${LineNumber}: expected KEY=VALUE."
    }

    return [pscustomobject]@{
        Name = $Matches.Name
        Value = ConvertFrom-DotEnvValue -RawValue $Matches.Value -Path $Path -LineNumber $LineNumber
    }
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

    Invoke-RailwayWithInput -Arguments $arguments -Secret $Secret -SuppressSuccessOutput
}

function Set-RailwayDotEnvVariables {
    param([string]$Path)

    $resolvedPath = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $variables = [ordered]@{}
    $skippedEmptyVariables = New-Object System.Collections.Generic.List[string]
    $lineNumber = 0

    foreach ($line in [System.IO.File]::ReadLines($resolvedPath)) {
        $lineNumber++
        $entry = ConvertFrom-DotEnvLine -Line $line -Path $resolvedPath -LineNumber $lineNumber
        if ($null -eq $entry) {
            continue
        }

        $variables[$entry.Name] = $entry.Value
    }

    if ($variables.Count -eq 0) {
        Write-Host "No variables found in $resolvedPath."
        return
    }

    Write-Host "Syncing variables from $resolvedPath to Railway. Values will not be printed."
    foreach ($name in $variables.Keys) {
        $value = $variables[$name]
        if (-not $AllowEmptyEnvValues -and [string]::IsNullOrEmpty($value)) {
            $skippedEmptyVariables.Add($name)
            continue
        }

        $secret = ConvertTo-SecureString -String $value -AsPlainText -Force
        Set-RailwaySecretVariable -Name $name -Secret $secret
        $script:SyncedDotEnvVariableCount++
        Write-Host "Synced Railway variable $name."
    }

    if ($skippedEmptyVariables.Count -gt 0) {
        Write-Host "Skipped empty .env values: $($skippedEmptyVariables -join ', '). Use -AllowEmptyEnvValues to deploy empty values intentionally."
    }

    Write-Host "Synced $script:SyncedDotEnvVariableCount variable(s) from $resolvedPath."
}

function Set-OpenEmrVariables {
    $openEmrAdminPass = Read-RequiredSecureString -Prompt "Initial OpenEMR admin password"

    if ([string]::IsNullOrWhiteSpace($MysqlHost)) {
        $MysqlHost = '${{' + "$MysqlServiceName.MYSQLHOST" + '}}'
        $script:MysqlHost = $MysqlHost
    }

    if ([string]::IsNullOrWhiteSpace($MysqlPort)) {
        $MysqlPort = '${{' + "$MysqlServiceName.MYSQLPORT" + '}}'
        $script:MysqlPort = $MysqlPort
    }

    if ([string]::IsNullOrWhiteSpace($MysqlPassword)) {
        $MysqlPassword = '${{' + "$MysqlServiceName.MYSQLPASSWORD" + '}}'
        $script:MysqlPassword = $MysqlPassword
    }

    $mysqlRootPass = '${{' + "$MysqlServiceName.MYSQL_ROOT_PASSWORD" + '}}'

    Set-RailwayVariable -Name "PORT" -Value "80"
    Set-RailwayVariable -Name "RAILWAY_RUN_UID" -Value "0"
    Set-RailwayVariable -Name "MYSQL_HOST" -Value $MysqlHost
    Set-RailwayVariable -Name "MYSQL_PORT" -Value $MysqlPort
    Set-RailwayVariable -Name "MYSQL_ROOT_USER" -Value $MysqlRootUser
    Set-RailwayVariable -Name "MYSQL_ROOT_PASS" -Value $mysqlRootPass
    Set-RailwayVariable -Name "MYSQL_DATABASE" -Value $MysqlDatabase
    Set-RailwayVariable -Name "MYSQL_USER" -Value $MysqlUser
    Set-RailwayVariable -Name "MYSQL_PASS" -Value $MysqlPassword
    Set-RailwayVariable -Name "OE_USER" -Value $OpenEmrAdminUser
    Set-RailwaySecretVariable -Name "OE_PASS" -Secret $openEmrAdminPass

    Write-Host "Variables staged. Railway may require you to review/deploy staged variable changes in the dashboard."
}

function New-OpenEmrSitesVolume {
    Write-Host "Creating Railway volume at $SitesVolumeMountPath."
    Invoke-Railway -Arguments @("volume", "add", "--mount-path", $SitesVolumeMountPath)
    Set-RailwayVariable -Name "SWARM_MODE" -Value "yes"
}

function Invoke-RailwayDeploy {
    $arguments = @("up")
    $arguments += New-RailwayDeployScopeArguments
    $arguments += "--message"
    $arguments += "Deploy OpenEMR source overlay"

    if ($Detach) {
        $arguments += "--detach"
    }
    if ($Ci) {
        $arguments += "--ci"
    }

    Invoke-Railway -Arguments $arguments
}

$script:SyncedDotEnvVariableCount = 0
$script:ShouldSyncEnvFile = (-not $SkipEnvSync -and (Test-Path -LiteralPath $EnvFile))

function Get-LatestRailwayDeployment {
    $arguments = @("deployment", "list")
    $arguments += New-RailwayServiceScopeArguments
    $arguments += "--limit"
    $arguments += "1"
    $arguments += "--json"

    $json = & railway @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "railway $($arguments -join ' ') failed with exit code $LASTEXITCODE."
    }

    $deployments = $json | ConvertFrom-Json
    if ($null -eq $deployments -or $deployments.Count -eq 0) {
        return $null
    }

    return @($deployments)[0]
}

function Invoke-RailwayRedeploy {
    $arguments = @("redeploy")
    $arguments += New-RailwayRedeployScopeArguments
    $arguments += "--yes"

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

if ($SkipEnvSync) {
    Write-Host "Skipping .env variable sync because -SkipEnvSync was supplied."
}
elseif (Test-Path -LiteralPath $EnvFile) {
    Set-RailwayDotEnvVariables -Path $EnvFile
}
else {
    Write-Host "No $EnvFile file found. Skipping .env variable sync."
}

if ($CreateSitesVolume) {
    New-OpenEmrSitesVolume
}

if ($SkipDeploy) {
    Write-Host "Skipping deployment because -SkipDeploy was supplied."
    exit 0
}

Invoke-RailwayDeploy

if ($script:SyncedDotEnvVariableCount -gt 0) {
    $latestDeployment = Get-LatestRailwayDeployment
    if ($null -ne $latestDeployment -and $latestDeployment.status -eq "SKIPPED") {
        Write-Host "Railway skipped the source upload. Redeploying the latest image so synced variables take effect."
        Invoke-RailwayRedeploy
    }
}
