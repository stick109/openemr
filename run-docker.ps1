param(
    [ValidateSet("development-easy", "development-easy-light", "development-easy-redis", "production")]
    [string]$Profile = "development-easy",

    [string]$ProjectName = "development-easy",

    [int]$DockerStartupTimeoutSeconds = 120,

    [int]$ReadinessPollSeconds = 10,

    [switch]$NoBuild,

    [switch]$Pull,

    [switch]$Foreground,

    [switch]$Restart,

    [string]$HttpPort,

    [string]$HttpsPort,

    [string]$PhpMyAdminPort,

    [string]$MysqlPort,

    [string]$AgentPort
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

function Invoke-DockerSoftCleanup {
    # Reclaim space from dangling (untagged) images and stale build cache.
    # Both prunes are non-destructive: tagged images, running containers,
    # named volumes, and in-use cache are left alone. Failures here should
    # never block a build, so exit codes are intentionally ignored.
    Write-Host "Pruning dangling images and stale build cache..."

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        & docker image prune --force | Out-Host
        & docker builder prune --force | Out-Host
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
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

    # Inject the repo-root .env file when present so secrets like
    # DASHBOARD_OIDC_CLIENT_ID survive the script's cd into the compose
    # subdir (Compose v2 looks for .env in the working directory only).
    $envFilePath = Join-Path $PSScriptRoot ".env"
    if (Test-Path $envFilePath) {
        $ComposeArguments = @("--env-file", $envFilePath) + $ComposeArguments
    }

    # Windows PowerShell 5.1 wraps each native-command stderr line as a
    # NativeCommandError. ``docker compose up`` prints progress messages
    # ("Network ... Created", "Container ... Started") to stderr, which
    # would terminate the pipeline under the script's
    # ``$ErrorActionPreference = 'Stop'``. Drop to ``Continue`` for just
    # the compose call and use ``$LASTEXITCODE`` for the real outcome.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        & docker compose --project-name $ProjectName @ComposeArguments
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
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
    $output = & docker compose --project-name $ProjectName ps --all --format json
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps --all --format json failed with exit code $LASTEXITCODE."
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

function Get-DockerComposeServiceBlockers {
    param([string[]]$ExpectedServices)

    $containers = @(Get-DockerComposeContainers)
    $blockers = @()

    foreach ($service in $ExpectedServices) {
        $serviceContainers = @($containers | Where-Object { $_.Service -eq $service })
        if ($serviceContainers.Count -eq 0) {
            $blockers += "$service=missing"
            continue
        }

        $runningContainers = @($serviceContainers | Where-Object { $_.State -eq "running" })
        if ($runningContainers.Count -eq 0) {
            $latestContainer = $serviceContainers | Select-Object -First 1
            $blockers += "$service=$($latestContainer.State) ($($latestContainer.Status))"
            continue
        }

        $unhealthyContainers = @($runningContainers | Where-Object { $_.Health -eq "unhealthy" })
        if ($unhealthyContainers.Count -gt 0) {
            $blockers += "$service=unhealthy ($($unhealthyContainers[0].Status))"
        }
    }

    if ($blockers.Count -eq 0) {
        return "all compose services are running"
    }

    return ($blockers -join "; ")
}

function Test-TcpPort {
    param(
        [string]$HostName,
        [int]$Port,
        [int]$TimeoutMilliseconds = 2000
    )

    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $connect = $client.BeginConnect($HostName, $Port, $null, $null)
        if (-not $connect.AsyncWaitHandle.WaitOne($TimeoutMilliseconds, $false)) {
            return [pscustomobject]@{
                Ready = $false
                Detail = "TCP connect timed out"
            }
        }

        $client.EndConnect($connect)
        return [pscustomobject]@{
            Ready = $true
            Detail = "TCP connect succeeded"
        }
    }
    catch {
        return [pscustomobject]@{
            Ready = $false
            Detail = $_.Exception.Message
        }
    }
    finally {
        $client.Close()
    }
}

function Test-HttpEndpoint {
    param(
        [string]$Uri,
        [int]$TimeoutSeconds = 10
    )

    $previousCertificateCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
    try {
        if ($Uri.StartsWith("https://", [System.StringComparison]::OrdinalIgnoreCase)) {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        }

        $request = [System.Net.WebRequest]::Create($Uri)
        $request.Method = "GET"
        $request.AllowAutoRedirect = $false
        $request.Timeout = $TimeoutSeconds * 1000

        $response = $request.GetResponse()
        try {
            $statusCode = [int]$response.StatusCode
            $ready = ($statusCode -ge 200 -and $statusCode -lt 400)
            return [pscustomobject]@{
                Ready = $ready
                Detail = "HTTP $statusCode $($response.StatusDescription)"
            }
        }
        finally {
            $response.Close()
        }
    }
    catch [System.Net.WebException] {
        $response = $_.Exception.Response
        if ($null -ne $response) {
            try {
                $statusCode = [int]$response.StatusCode
                return [pscustomobject]@{
                    Ready = $false
                    Detail = "HTTP $statusCode $($response.StatusDescription)"
                }
            }
            finally {
                $response.Close()
            }
        }

        return [pscustomobject]@{
            Ready = $false
            Detail = $_.Exception.Message
        }
    }
    catch {
        return [pscustomobject]@{
            Ready = $false
            Detail = $_.Exception.Message
        }
    }
    finally {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $previousCertificateCallback
    }
}

function Get-OpenEmrHttpEndpoint {
    if ($Profile -eq "production") {
        return "http://localhost/"
    }

    return "http://localhost:$(Get-PortValue -Name "WT_HTTP_PORT" -DefaultValue "8300")/"
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

function Get-AgentServiceBaseUrl {
    return "http://localhost:$(Get-PortValue -Name "WT_AGENT_PORT" -DefaultValue "8010")/"
}

function Get-AgentServiceHealthEndpoint {
    return "$(Get-AgentServiceBaseUrl)healthz"
}

function Get-EndpointPort {
    param([string]$Uri)

    $parsedUri = [System.Uri]$Uri
    return $parsedUri.Port
}

function Get-OpenEmrStartupDetail {
    $openEmrContainer = @(Get-DockerComposeContainers | Where-Object { $_.Service -eq "openemr" } | Select-Object -First 1)
    if ($openEmrContainer.Count -eq 0) {
        return "openemr container is missing"
    }

    $details = @("openemr container: state=$($openEmrContainer[0].State), health=$($openEmrContainer[0].Health), status=$($openEmrContainer[0].Status)")
    if ($openEmrContainer[0].State -ne "running") {
        return ($details -join "; ")
    }

    $processes = & docker compose --project-name $ProjectName exec -T openemr ps aux 2>$null
    if ($LASTEXITCODE -ne 0) {
        $details += "unable to inspect openemr processes"
        return ($details -join "; ")
    }

    $processText = ($processes -join "`n")
    if ($processText -match "rsync .* /openemr ") {
        $details += "startup is still copying the mounted checkout with rsync"
    }
    elseif ($processText -match "httpd|apache2") {
        $details += "Apache appears to be running"
    }
    else {
        $details += "Apache has not appeared in the process list yet"
    }

    return ($details -join "; ")
}

function Get-AgentServiceStartupDetail {
    $container = @(Get-DockerComposeContainers | Where-Object { $_.Service -eq "agent-service" } | Select-Object -First 1)
    if ($container.Count -eq 0) {
        return "agent-service container is missing"
    }

    return "agent-service container: state=$($container[0].State), health=$($container[0].Health), status=$($container[0].Status)"
}

function Wait-OpenEmrEndpoints {
    param(
        [string]$HttpEndpoint,
        [string]$HttpsEndpoint,
        [string]$AgentEndpoint,
        [string[]]$ExpectedServices,
        [int]$PollSeconds
    )

    $hasAgent = -not [string]::IsNullOrWhiteSpace($AgentEndpoint)

    Write-Host ""
    if ($hasAgent) {
        Write-Host "Waiting for OpenEMR HTTP, HTTPS, and agent-service endpoints to serve normally..."
    }
    else {
        Write-Host "Waiting for OpenEMR HTTP and HTTPS endpoints to serve normally..."
    }

    while ($true) {
        $httpPort = Get-EndpointPort -Uri $HttpEndpoint
        $httpsPort = Get-EndpointPort -Uri $HttpsEndpoint

        $httpTcp = Test-TcpPort -HostName "localhost" -Port $httpPort
        $httpsTcp = Test-TcpPort -HostName "localhost" -Port $httpsPort

        $httpProbe = if ($httpTcp.Ready) { Test-HttpEndpoint -Uri $HttpEndpoint } else { [pscustomobject]@{ Ready = $false; Detail = "not attempted because TCP is unavailable" } }
        $httpsProbe = if ($httpsTcp.Ready) { Test-HttpEndpoint -Uri $HttpsEndpoint } else { [pscustomobject]@{ Ready = $false; Detail = "not attempted because TCP is unavailable" } }

        $agentReady = $true
        $agentPort = $null
        $agentTcp = $null
        $agentProbe = $null
        if ($hasAgent) {
            $agentPort = Get-EndpointPort -Uri $AgentEndpoint
            $agentTcp = Test-TcpPort -HostName "localhost" -Port $agentPort
            $agentProbe = if ($agentTcp.Ready) { Test-HttpEndpoint -Uri $AgentEndpoint } else { [pscustomobject]@{ Ready = $false; Detail = "not attempted because TCP is unavailable" } }
            $agentReady = $agentProbe.Ready
        }

        if ($httpProbe.Ready -and $httpsProbe.Ready -and $agentReady) {
            Write-Host "OpenEMR endpoints are ready."
            Write-Host "HTTP:  $HttpEndpoint ($($httpProbe.Detail))"
            Write-Host "HTTPS: $HttpsEndpoint ($($httpsProbe.Detail))"
            if ($hasAgent) {
                Write-Host "Agent: $AgentEndpoint ($($agentProbe.Detail))"
            }
            return
        }

        Write-Host ""
        Write-Host "OpenEMR is not ready yet. Next check in $PollSeconds seconds."
        Write-Host "HTTP port $httpPort TCP:  $($httpTcp.Detail)"
        Write-Host "HTTP endpoint:       $($httpProbe.Detail)"
        Write-Host "HTTPS port $httpsPort TCP: $($httpsTcp.Detail)"
        Write-Host "HTTPS endpoint:      $($httpsProbe.Detail)"
        if ($hasAgent) {
            Write-Host "Agent port $agentPort TCP: $($agentTcp.Detail)"
            Write-Host "Agent endpoint:      $($agentProbe.Detail)"
            Write-Host "Agent blocker:       $(Get-AgentServiceStartupDetail)"
        }
        Write-Host "Compose services:    $(Get-DockerComposeServiceBlockers -ExpectedServices $ExpectedServices)"
        Write-Host "Blocker: $(Get-OpenEmrStartupDetail)"

        Start-Sleep -Seconds $PollSeconds
    }
}

Confirm-DockerCompose
Invoke-DockerSoftCleanup

Set-PortOverride -Name "WT_HTTP_PORT" -Value $HttpPort
Set-PortOverride -Name "WT_HTTPS_PORT" -Value $HttpsPort
Set-PortOverride -Name "WT_PMA_PORT" -Value $PhpMyAdminPort
Set-PortOverride -Name "WT_MYSQL_PORT" -Value $MysqlPort
Set-PortOverride -Name "WT_AGENT_PORT" -Value $AgentPort

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
if (-not $NoBuild) {
    $upArguments += "--build"
}
if ($Pull) {
    $upArguments += "--pull"
    $upArguments += "always"
}

Push-Location (Join-Path $repoRoot $composeDirectory)
try {
    Write-Host "Using compose profile: $composeDirectory"
    $httpEndpoint = Get-OpenEmrHttpEndpoint
    $httpsEndpoint = Get-OpenEmrHttpsEndpoint
    $expectedServices = Get-DockerComposeServices
    $includeAgent = $expectedServices -contains "agent-service"
    $agentEndpoint = if ($includeAgent) { Get-AgentServiceHealthEndpoint } else { $null }
    $containers = @(Get-DockerComposeContainers)
    $stackRunning = Test-DockerComposeStackRunning -ExpectedServices $expectedServices -Containers $containers

    if ($stackRunning) {
        Write-Host "Compose stack '$ProjectName' is already running."
        Write-DockerComposeStatus -ExpectedServices $expectedServices -Containers $containers | Format-Table -AutoSize

        if (-not $Restart) {
            Write-Host "No stack changes made. Use -Restart to stop and start the running stack."
            if (-not $Foreground) {
                Wait-OpenEmrEndpoints -HttpEndpoint $httpEndpoint -HttpsEndpoint $httpsEndpoint -AgentEndpoint $agentEndpoint -ExpectedServices $expectedServices -PollSeconds $ReadinessPollSeconds
            }
            return
        }

        Write-Host "Restart requested. Stopping running stack..."
        Invoke-DockerCompose -ComposeArguments @("stop")
    }

    Invoke-DockerCompose -ComposeArguments $upArguments

    if (-not $Foreground) {
        Write-Host ""
        Write-Host "OpenEMR is starting in the background."

        if ($Profile -eq "production") {
            Write-Host "OpenEMR HTTP:  $httpEndpoint"
            Write-Host "OpenEMR HTTPS: $httpsEndpoint"
        }
        else {
            Write-Host "OpenEMR HTTP:  $httpEndpoint"
            Write-Host "OpenEMR HTTPS: $httpsEndpoint"
        }

        Write-Host "Login: admin / pass"

        if ($Profile -ne "production") {
            Write-Host "phpMyAdmin:    http://localhost:$(Get-PortValue -Name "WT_PMA_PORT" -DefaultValue "8310")/"
            Write-Host "MySQL:         localhost:$(Get-PortValue -Name "WT_MYSQL_PORT" -DefaultValue "8320")"
        }

        if ($includeAgent) {
            Write-Host "Agent service: $(Get-AgentServiceBaseUrl)"
        }

        Wait-OpenEmrEndpoints -HttpEndpoint $httpEndpoint -HttpsEndpoint $httpsEndpoint -AgentEndpoint $agentEndpoint -ExpectedServices $expectedServices -PollSeconds $ReadinessPollSeconds

        Write-Host "Opening HTTPS endpoint in the default browser..."
        Start-Process -FilePath $httpsEndpoint
    }
}
finally {
    Pop-Location
}
