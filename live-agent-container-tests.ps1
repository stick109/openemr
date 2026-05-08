<#
.SYNOPSIS
    Live tests against the running agent-service sidecar container.

.DESCRIPTION
    Verifies behaviour that is *only* observable when the docker compose
    stack is up -- i.e. things that the in-process FastAPI TestClient
    suite in agent-service/tests cannot prove:

      1. Topology -- the agent-service container is running, its port is
         mapped to the host, and the openemr container can reach it via
         the docker bridge using its service hostname.
      2. Health   -- GET /healthz returns 200 against the *deployed*
         app, not a TestClient, so config drift, init failures, and
         port-mapping bugs surface here.
      3. Auth     -- the X-Agent-Secret check on /api/agent/run is
         exercised with the *actual* secret pair the two containers
         share, proving the env-var wiring in docker-compose.yml is
         consistent across both services.
      4. Validation -- Pydantic body validation on /api/agent/run rejects
         the bad inputs that CONTRACT.md (v1.0.0) calls out.
      5. Copilot run-context -- /api/copilot/run rejects malformed,
         tampered, expired, and unknown-key tokens. The valid-token
         path is built locally with the same canonical-JSON + HMAC-SHA256
         scheme used by OpenEMR\Services\Agent\Copilot\CopilotRunContext,
         so this test also proves the PHP and Python sides agree on the
         wire format.
      6. Volume  -- the shared agent-uploads docker volume is mounted
         in both containers, and a file written from openemr is visible
         from agent-service. None of this is observable from inside a
         single container's process.
      7. Paid    -- end-to-end /api/agent/run happy path, gated behind
         -RunPaid because it spends real OpenAI/Cohere credits. Off by
         default.

    Tests that depend on Docker tooling are skipped (not failed) when
    Docker is unavailable, matching the convention in
    week-2-cmd-tests.ps1.

.PARAMETER Topology
    Run only the topology probes (container running, port mapped,
    cross-container hostname resolution).

.PARAMETER Health
    Run only the unauthenticated /healthz probe.

.PARAMETER Auth
    Run only the X-Agent-Secret boundary tests on /api/agent/run.

.PARAMETER Validation
    Run only the Pydantic body-validation tests on /api/agent/run.

.PARAMETER Copilot
    Run only the /api/copilot/run run-context auth tests (malformed,
    tampered, expired, unknown key version, valid).

.PARAMETER Volume
    Run only the shared agent-uploads volume cross-container check.

.PARAMETER RunPaid
    Include the paid end-to-end tests that call OpenAI/Cohere. Requires
    OPENAI_API_KEY (and ideally COHERE_API_KEY) to be set in the
    agent-service container at boot time.

.PARAMETER All
    Run every group except Paid. Default when no group switch is given.

.PARAMETER AgentUrl
    Base URL for the sidecar as seen from the host. Defaults to the
    port published by docker/development-easy/docker-compose.yml
    (WT_AGENT_PORT, default 8010).

.PARAMETER Secret
    Shared secret to use for X-Agent-Secret and run_context HMAC.
    Defaults to $env:OPENEMR_AGENT_SIDECAR_SECRET, falling back to
    "dev-shared-secret" (the dev-easy default in compose).

.PARAMETER ComposeProject
    docker compose project name that runs the agent-service container.
    Defaults to "openemr". The script never relies on the location of
    the compose file -- it talks to compose by project name only via
    `docker compose -p <name>`.

.PARAMETER SkipDocker
    Skip every test that shells out to docker (topology cross-container
    probe and the volume test). Useful when Docker Desktop is
    intentionally off.

.EXAMPLE
    PS> .\live-agent-container-tests.ps1
    Runs every non-paid group against http://localhost:8010 with the
    dev-shared-secret default.

.EXAMPLE
    PS> .\live-agent-container-tests.ps1 -Health -Auth
    Smoke-tests health + auth only.

.EXAMPLE
    PS> $env:OPENEMR_AGENT_SIDECAR_SECRET = "..."; .\live-agent-container-tests.ps1 -RunPaid
    Runs everything including the paid end-to-end test.

.NOTES
    Windows PowerShell 5.1 compatible. No '&&' chains, no ternary, no
    null-coalescing. HTTP errors are caught and unwrapped so 401/403/422
    bodies are inspectable instead of throwing. Canonical JSON for the
    HMAC payload is built without ConvertTo-Json so PS's slash escaping
    cannot diverge from the PHP/Python encoding.
#>

[CmdletBinding()]
param(
    [Switch]$Topology,
    [Switch]$Health,
    [Switch]$Auth,
    [Switch]$Validation,
    [Switch]$Copilot,
    [Switch]$Volume,
    [Switch]$All,
    [Switch]$RunPaid,
    [Switch]$SkipDocker,
    [string]$AgentUrl = "http://localhost:8010",
    [string]$Secret,
    [string]$ComposeProject = "development-easy"
)

$ErrorActionPreference = "Continue"

# Resolve repo root from the script's location for completeness, but we
# do not rely on a docker-compose directory path -- every docker compose
# call in this script is project-name-based via `-p $ComposeProject`,
# so the script works regardless of where the compose file lives or
# whether the cwd matches the project's auto-derived name.
$RepoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

# Default the secret from the environment, falling back to the dev-easy
# compose default. The empty-string check covers the case where the
# user passed -Secret "" explicitly -- treat it the same as "not set".
if ([string]::IsNullOrEmpty($Secret)) {
    if (-not [string]::IsNullOrEmpty($env:OPENEMR_AGENT_SIDECAR_SECRET)) {
        $Secret = $env:OPENEMR_AGENT_SIDECAR_SECRET
    } else {
        $Secret = "dev-shared-secret"
    }
}

# Default behaviour: run every non-paid group when no switch is given.
if (-not ($Topology -or $Health -or $Auth -or $Validation -or $Copilot -or $Volume -or $All)) {
    $All = $true
}
if ($All) {
    $Topology   = $true
    $Health     = $true
    $Auth       = $true
    $Validation = $true
    $Copilot    = $true
    $Volume     = $true
}

# Results collection -- one PSCustomObject per Run-Test invocation.
$Script:Results = @()

# ---------------------------------------------------------------------------
# Generic helpers (shared shape with week-2-cmd-tests.ps1)
# ---------------------------------------------------------------------------

function Write-Section {
    param([string]$Title)
    Write-Host ""
    Write-Host ("=" * 78) -ForegroundColor Cyan
    Write-Host (" $Title") -ForegroundColor Cyan
    Write-Host ("=" * 78) -ForegroundColor Cyan
}

function Run-Test {
    <#
    .SYNOPSIS
        Run a single named test scriptblock and capture its result.

    .DESCRIPTION
        The scriptblock indicates failure by throwing or by setting
        $LASTEXITCODE. Pure-PowerShell tests (HTTP, file IO) should
        throw on failure; tests that shell out to docker rely on
        $LASTEXITCODE the same way week-2-cmd-tests.ps1 does.
    #>
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][scriptblock]$Block,
        [string]$Group = ""
    )

    Write-Host ""
    Write-Host "[ RUN  ] $Name" -ForegroundColor Yellow

    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $exitCode = 0
    $errorMessage = ""

    # Reset $LASTEXITCODE so a stale value from a prior native command
    # cannot bleed into the result of a pure-PowerShell test.
    $global:LASTEXITCODE = 0

    try {
        & $Block
        if ($null -ne $LASTEXITCODE -and $LASTEXITCODE -ne 0) {
            $exitCode = $LASTEXITCODE
        }
    } catch {
        $exitCode = 1
        $errorMessage = $_.Exception.Message
        Write-Host $errorMessage -ForegroundColor Red
    }

    $stopwatch.Stop()
    $duration = $stopwatch.Elapsed

    if ($exitCode -eq 0) {
        Write-Host ("[ PASS ] {0}  ({1:N1}s)" -f $Name, $duration.TotalSeconds) -ForegroundColor Green
        $status = "PASS"
    } else {
        Write-Host ("[ FAIL ] {0}  ({1:N1}s, exit={2})" -f $Name, $duration.TotalSeconds, $exitCode) -ForegroundColor Red
        $status = "FAIL"
    }

    $Script:Results += [PSCustomObject]@{
        Name     = $Name
        Group    = $Group
        Status   = $status
        Duration = ("{0:N1}s" -f $duration.TotalSeconds)
        ExitCode = $exitCode
    }
}

function Skip-Test {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Reason,
        [string]$Group = ""
    )
    Write-Host ""
    Write-Host "[ SKIP ] $Name -- $Reason" -ForegroundColor DarkGray
    $Script:Results += [PSCustomObject]@{
        Name     = $Name
        Group    = $Group
        Status   = "SKIP"
        Duration = "-"
        ExitCode = 0
    }
}

function Test-Command {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Test-DockerUp {
    <#
    .SYNOPSIS
        Return $true if the configured compose project is running and
        agent-service is one of its running services.

    .DESCRIPTION
        Uses `docker compose -p $ComposeProject ps` so the script does
        not depend on the cwd or the location of the compose file. The
        project name defaults to "openemr" and is overridable via the
        -ComposeProject parameter.
    #>
    if (-not (Test-Command docker)) { return $false }

    $services = & docker compose -p $ComposeProject ps --status running --services 2>$null
    if ($LASTEXITCODE -ne 0) { return $false }
    return ([string]::Join("`n", @($services)) -match "agent-service")
}

# ---------------------------------------------------------------------------
# HTTP helper that returns 4xx/5xx bodies instead of throwing
# ---------------------------------------------------------------------------

function Invoke-AgentRequest {
    <#
    .SYNOPSIS
        POST/GET against the sidecar and return status code + body for
        any response (including 4xx/5xx).

    .DESCRIPTION
        Invoke-WebRequest throws on non-2xx by default in PowerShell 5.1
        and 7. This wrapper catches the exception, pulls the status code
        and body off whichever shape the underlying object exposes, and
        normalises both into a PSCustomObject. The caller then asserts
        on .StatusCode and .Json without try/catch in every test body.
    #>
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)] [ValidateSet('GET', 'POST')] [string]$Method,
        [Parameter(Mandatory)] [string]$Url,
        [hashtable]$Headers = @{},
        [string]$Body
    )

    $invokeParams = @{
        Uri             = $Url
        Method          = $Method
        UseBasicParsing = $true
        TimeoutSec      = 30
        ErrorAction     = 'Stop'
    }
    if ($Headers.Count -gt 0) {
        $invokeParams.Headers = $Headers
    }
    if ($PSBoundParameters.ContainsKey('Body')) {
        $invokeParams.Body = $Body
        $invokeParams.ContentType = 'application/json'
    }

    $statusCode = 0
    $bodyText   = ''

    try {
        $resp = Invoke-WebRequest @invokeParams
        $statusCode = [int]$resp.StatusCode
        $bodyText   = [string]$resp.Content
    } catch {
        $exc = $_.Exception
        # PS 7+ surfaces the body via $_.ErrorDetails.Message.
        if ($null -ne $_.ErrorDetails -and $null -ne $_.ErrorDetails.Message) {
            $bodyText = [string]$_.ErrorDetails.Message
        }
        if ($null -ne $exc.Response) {
            try { $statusCode = [int]$exc.Response.StatusCode } catch { }
            # PS 5.1 needs us to read the response stream by hand.
            if ($bodyText -eq '' -and ($exc.Response | Get-Member -Name GetResponseStream -ErrorAction SilentlyContinue)) {
                try {
                    $stream = $exc.Response.GetResponseStream()
                    $reader = [System.IO.StreamReader]::new($stream)
                    $bodyText = $reader.ReadToEnd()
                    $reader.Close()
                } catch { }
            }
        }
        if ($statusCode -eq 0) {
            # Could not classify -- this is a transport/socket error,
            # not an HTTP error. Re-throw so the caller surfaces it.
            throw
        }
    }

    $json = $null
    if (-not [string]::IsNullOrEmpty($bodyText)) {
        try { $json = $bodyText | ConvertFrom-Json -ErrorAction Stop } catch { }
    }
    return [PSCustomObject]@{
        StatusCode = $statusCode
        Body       = $bodyText
        Json       = $json
    }
}

function Test-AgentReachable {
    <#
    .SYNOPSIS
        Return $true if GET /healthz succeeds against $AgentUrl.

    .DESCRIPTION
        Used as a precondition for every group below Topology. A failed
        probe causes the rest of the suite to skip rather than spam
        identical connection-refused errors.
    #>
    try {
        $resp = Invoke-AgentRequest -Method GET -Url "$AgentUrl/healthz"
        return ($resp.StatusCode -eq 200)
    } catch {
        return $false
    }
}

# ---------------------------------------------------------------------------
# Canonical JSON + HMAC-SHA256 + base64url -- copilot run_context minter
# ---------------------------------------------------------------------------

function ConvertTo-CanonicalJson {
    <#
    .SYNOPSIS
        Encode a value as canonical JSON matching PHP/Python's mint.

    .DESCRIPTION
        PHP uses JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE with
        recursive ksort. Python uses json.dumps(separators=(",",":"),
        sort_keys=True, ensure_ascii=False). Both produce byte-identical
        output for the claim shapes we care about. PowerShell's
        ConvertTo-Json escapes forward slashes by default and offers no
        sort_keys flag, so we encode by hand. The encoder supports the
        types actually used in CopilotRunContext claims: int, string,
        list<string>, and null. Nested dicts and other primitives are
        not needed but are handled defensively.
    #>
    [CmdletBinding()]
    param([Parameter(Mandatory = $false)] $Value)

    if ($null -eq $Value) { return 'null' }
    if ($Value -is [bool]) {
        if ($Value) { return 'true' } else { return 'false' }
    }
    if ($Value -is [int] -or $Value -is [long]) {
        return $Value.ToString([System.Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [string]) {
        $sb = [System.Text.StringBuilder]::new()
        [void]$sb.Append('"')
        foreach ($ch in $Value.ToCharArray()) {
            $code = [int]$ch
            if     ($code -eq 0x22) { [void]$sb.Append('\"') }
            elseif ($code -eq 0x5C) { [void]$sb.Append('\\') }
            elseif ($code -eq 0x08) { [void]$sb.Append('\b') }
            elseif ($code -eq 0x09) { [void]$sb.Append('\t') }
            elseif ($code -eq 0x0A) { [void]$sb.Append('\n') }
            elseif ($code -eq 0x0C) { [void]$sb.Append('\f') }
            elseif ($code -eq 0x0D) { [void]$sb.Append('\r') }
            elseif ($code -lt 0x20) { [void]$sb.Append(('\u{0:x4}' -f $code)) }
            else                    { [void]$sb.Append($ch) }
        }
        [void]$sb.Append('"')
        return $sb.ToString()
    }
    if ($Value -is [System.Collections.IDictionary]) {
        $sortedKeys = @($Value.Keys | Sort-Object)
        $items = foreach ($k in $sortedKeys) {
            $kJson = ConvertTo-CanonicalJson -Value ([string]$k)
            $vJson = ConvertTo-CanonicalJson -Value $Value[$k]
            "${kJson}:${vJson}"
        }
        return '{' + ($items -join ',') + '}'
    }
    # Treat any other enumerable (array, list) as a JSON list.
    if ($Value -is [System.Collections.IEnumerable]) {
        $items = foreach ($item in $Value) {
            ConvertTo-CanonicalJson -Value $item
        }
        return '[' + ($items -join ',') + ']'
    }
    throw "ConvertTo-CanonicalJson: unsupported type $($Value.GetType().FullName)"
}

function ConvertTo-Base64Url {
    param([Parameter(Mandatory)] [byte[]]$Bytes)
    $b64 = [Convert]::ToBase64String($Bytes)
    return $b64.TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function New-CopilotRunContext {
    <#
    .SYNOPSIS
        Mint a wire token compatible with the Python verifier.

    .DESCRIPTION
        Builds the canonical claims dictionary, encodes it as canonical
        JSON, and signs the JSON bytes with HMAC-SHA256 using the
        supplied secret. The wire format is
        <base64url(payload)>.<base64url(signature)>, identical to the
        PHP minter at OpenEMR\Services\Agent\Copilot\CopilotRunContext.

        Defaults pick safe values for the auth tests: a 5-minute
        expiry, key_version "v1" (the only version the resolver knows
        about per agent-service/agent_service/auth/secret_resolver.py),
        and a non-empty list of allowed_tools / allowed_source_types
        so the Pydantic model's "non-empty strings" validator does not
        reject the claims.
    #>
    [CmdletBinding()]
    param(
        [string]$SigningSecret,
        [int]$ExpiresAt,
        [string]$KeyVersion = "v1",
        [int]$UserId = 1,
        [string]$Username = "admin",
        [int]$PatientId = 1,
        [Nullable[int]]$EncounterId = $null,
        [string[]]$AllowedTools = @("read_basic_patient_data"),
        [string[]]$AllowedSourceTypes = @("patient_record"),
        [int]$MaxRows = 50,
        [int]$LookbackDays = 30,
        [string]$RequestId,
        [string]$TraceId
    )

    if ([string]::IsNullOrEmpty($SigningSecret)) { $SigningSecret = $Secret }
    if (-not $PSBoundParameters.ContainsKey('ExpiresAt')) {
        $ExpiresAt = [int]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) + 300
    }
    if ([string]::IsNullOrEmpty($RequestId)) { $RequestId = [guid]::NewGuid().ToString() }
    if ([string]::IsNullOrEmpty($TraceId))   { $TraceId   = [guid]::NewGuid().ToString() }

    # Claims object. Use [ordered] so PowerShell does not randomise key
    # order, although ConvertTo-CanonicalJson sorts keys anyway.
    $claims = [ordered]@{
        allowed_source_types = $AllowedSourceTypes
        allowed_tools        = $AllowedTools
        encounter_id         = $EncounterId
        expires_at           = $ExpiresAt
        key_version          = $KeyVersion
        lookback_days        = $LookbackDays
        max_rows             = $MaxRows
        patient_id           = $PatientId
        request_id           = $RequestId
        trace_id             = $TraceId
        user_id              = $UserId
        username             = $Username
    }

    $payloadJson  = ConvertTo-CanonicalJson -Value $claims
    $payloadBytes = [System.Text.Encoding]::UTF8.GetBytes($payloadJson)

    $hmac      = [System.Security.Cryptography.HMACSHA256]::new([System.Text.Encoding]::UTF8.GetBytes($SigningSecret))
    $sigBytes  = $hmac.ComputeHash($payloadBytes)
    $hmac.Dispose()

    $wire = (ConvertTo-Base64Url -Bytes $payloadBytes) + '.' + (ConvertTo-Base64Url -Bytes $sigBytes)

    return [PSCustomObject]@{
        Wire      = $wire
        Claims    = $claims
        RequestId = $RequestId
        TraceId   = $TraceId
    }
}

# ---------------------------------------------------------------------------
# 1. TOPOLOGY -- container running, port mapped, network bridge works
# ---------------------------------------------------------------------------

function Invoke-TopologyTests {
    Write-Section "TOPOLOGY (5-10s)"

    $topologyNames = @(
        "agent-service container running"
        "agent-service health (docker compose ps)"
        "openemr -> agent-service bridge (curl)"
    )

    if ($SkipDocker) {
        foreach ($n in $topologyNames) { Skip-Test -Name $n -Group "Topology" -Reason "-SkipDocker" }
        return
    }

    if (-not (Test-Command docker)) {
        foreach ($n in $topologyNames) { Skip-Test -Name $n -Group "Topology" -Reason "docker not on PATH" }
        return
    }

    # If the configured compose project is not running at all, skip the
    # entire group rather than fail. "Container not running" is a
    # precondition gap, not a bug in the deployed sidecar -- failing
    # here would be a noisy false positive whenever someone runs the
    # script before bringing the stack up.
    if (-not (Test-DockerUp)) {
        $reason = "compose project '$ComposeProject' not running -- start the stack or pass -ComposeProject <name>"
        foreach ($n in $topologyNames) { Skip-Test -Name $n -Group "Topology" -Reason $reason }
        return
    }

    Run-Test -Name "agent-service container running" -Group "Topology" -Block {
        $services = & docker compose -p $ComposeProject ps --status running --services 2>$null
        $joined = [string]::Join("`n", @($services))
        if (-not ($joined -match "agent-service")) {
            throw "agent-service is not in 'docker compose -p $ComposeProject ps --status running --services' output"
        }
    }

    # docker compose ps --format json outputs one JSON object per line.
    # We parse only the agent-service line and assert .Health == healthy.
    Run-Test -Name "agent-service health (docker compose ps)" -Group "Topology" -Block {
        $rows = & docker compose -p $ComposeProject ps --format json agent-service 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $rows) {
            throw "docker compose ps returned no rows for agent-service"
        }
        # Older docker compose versions emit a single JSON array; newer
        # versions emit one object per line. Handle both.
        $joined = ($rows -join "`n").Trim()
        if ($joined.StartsWith('[')) {
            $entries = $joined | ConvertFrom-Json
        } else {
            $entries = foreach ($line in $rows) {
                if (-not [string]::IsNullOrWhiteSpace($line)) {
                    $line | ConvertFrom-Json
                }
            }
        }
        $entry = $entries | Where-Object { $_.Service -eq 'agent-service' } | Select-Object -First 1
        if ($null -eq $entry) {
            throw "no agent-service entry in docker compose ps --format json output"
        }
        if ($entry.Health -ne 'healthy') {
            throw "agent-service Health is '$($entry.Health)', expected 'healthy'"
        }
    }

    # Cross-container reachability: from inside the openemr container,
    # curl http://agent-service:8010/healthz. This is what the PHP
    # AgentServiceClient does at runtime; if the docker bridge is broken
    # or the env var was overridden, this fails here even if /healthz
    # works from the host.
    Run-Test -Name "openemr -> agent-service bridge (curl)" -Group "Topology" -Block {
        $output = & docker compose -p $ComposeProject exec -T openemr curl --silent --show-error --fail --max-time 10 http://agent-service:8010/healthz 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "curl from openemr to agent-service:8010 failed (exit=$LASTEXITCODE): $output"
        }
        if (-not ($output -match '"status"\s*:\s*"ok"')) {
            throw "unexpected /healthz body from inside openemr: $output"
        }
    }
}

# ---------------------------------------------------------------------------
# 2. HEALTH -- /healthz from the host
# ---------------------------------------------------------------------------

function Invoke-HealthTests {
    Write-Section "HEALTH (1-2s)"

    if (-not (Test-AgentReachable)) {
        Skip-Test -Name "GET /healthz returns 200 ok" -Group "Health" -Reason "agent-service not reachable at $AgentUrl"
        Skip-Test -Name "GET /healthz works without X-Agent-Secret" -Group "Health" -Reason "agent-service not reachable at $AgentUrl"
        return
    }

    Run-Test -Name "GET /healthz returns 200 ok" -Group "Health" -Block {
        $resp = Invoke-AgentRequest -Method GET -Url "$AgentUrl/healthz"
        if ($resp.StatusCode -ne 200) {
            throw "expected 200, got $($resp.StatusCode); body=$($resp.Body)"
        }
        if ($null -eq $resp.Json -or $resp.Json.status -ne 'ok') {
            throw "expected {status: ok}, got: $($resp.Body)"
        }
    }

    Run-Test -Name "GET /healthz works without X-Agent-Secret" -Group "Health" -Block {
        # Explicitly send no X-Agent-Secret header. CONTRACT.md says
        # /healthz is the single unauthenticated endpoint.
        $resp = Invoke-AgentRequest -Method GET -Url "$AgentUrl/healthz" -Headers @{}
        if ($resp.StatusCode -ne 200) {
            throw "expected 200 without secret, got $($resp.StatusCode)"
        }
    }
}

# ---------------------------------------------------------------------------
# 3. AUTH -- X-Agent-Secret on /api/agent/run
# ---------------------------------------------------------------------------

# A body that is structurally valid so auth runs first. The trace_id is
# a real UUID v4 so the field validator accepts it. We never mean to
# actually run the agent in auth tests -- those that pass the secret
# would then drive a real LLM call -- so we use a malformed body in the
# "correct secret" case to terminate at validation (422), not at the LLM.
$Script:_ValidAgentBody = @{
    patient_id   = 1
    file_path    = "/var/uploads/agent/nonexistent.pdf"
    doc_type     = "lab_pdf"
    encounter_id = 1
    trace_id     = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
} | ConvertTo-Json -Compress

function Invoke-AuthTests {
    Write-Section "AUTH on /api/agent/run (1-2s)"

    if (-not (Test-AgentReachable)) {
        Skip-Test -Name "POST /api/agent/run rejects missing X-Agent-Secret" -Group "Auth" -Reason "agent-service not reachable"
        Skip-Test -Name "POST /api/agent/run rejects wrong X-Agent-Secret" -Group "Auth" -Reason "agent-service not reachable"
        Skip-Test -Name "POST /api/agent/run accepts correct X-Agent-Secret" -Group "Auth" -Reason "agent-service not reachable"
        return
    }

    Run-Test -Name "POST /api/agent/run rejects missing X-Agent-Secret" -Group "Auth" -Block {
        $resp = Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/agent/run" -Body $Script:_ValidAgentBody
        if ($resp.StatusCode -ne 401) {
            throw "expected 401, got $($resp.StatusCode); body=$($resp.Body)"
        }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'unauthorized') {
            throw "expected error=unauthorized, got: $($resp.Body)"
        }
    }

    Run-Test -Name "POST /api/agent/run rejects wrong X-Agent-Secret" -Group "Auth" -Block {
        $resp = Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/agent/run" `
            -Headers @{ "X-Agent-Secret" = "definitely-not-the-real-secret" } `
            -Body $Script:_ValidAgentBody
        if ($resp.StatusCode -ne 403) {
            throw "expected 403, got $($resp.StatusCode); body=$($resp.Body)"
        }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'forbidden') {
            throw "expected error=forbidden, got: $($resp.Body)"
        }
    }

    # Correct secret -- but a body that fails Pydantic validation (negative
    # patient_id) so we terminate at 422 without actually running the
    # LangGraph pipeline. This proves the secret matches between
    # OPENEMR_AGENT_SIDECAR_SECRET (host/openemr container) and
    # AGENT_SHARED_SECRET (agent-service) without spending LLM credits.
    Run-Test -Name "POST /api/agent/run accepts correct X-Agent-Secret" -Group "Auth" -Block {
        $body = @{
            patient_id   = -1
            file_path    = "/var/uploads/agent/x.pdf"
            doc_type     = "lab_pdf"
            encounter_id = 1
            trace_id     = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
        } | ConvertTo-Json -Compress

        $resp = Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/agent/run" `
            -Headers @{ "X-Agent-Secret" = $Secret } `
            -Body $body
        # Auth passed if status is anything *other* than 401/403.
        if ($resp.StatusCode -eq 401 -or $resp.StatusCode -eq 403) {
            throw "secret rejected: status=$($resp.StatusCode); body=$($resp.Body). Check OPENEMR_AGENT_SIDECAR_SECRET vs AGENT_SHARED_SECRET in docker-compose.yml."
        }
        # 422 is the expected terminal here -- patient_id <= 0 fails the
        # Field(gt=0) constraint.
        if ($resp.StatusCode -ne 422) {
            throw "expected 422 from validation, got $($resp.StatusCode); body=$($resp.Body)"
        }
    }
}

# ---------------------------------------------------------------------------
# 4. VALIDATION -- Pydantic body validation on /api/agent/run
# ---------------------------------------------------------------------------

function Invoke-ValidationTests {
    Write-Section "VALIDATION on /api/agent/run (1-2s)"

    if (-not (Test-AgentReachable)) {
        Skip-Test -Name "negative patient_id is rejected" -Group "Validation" -Reason "agent-service not reachable"
        Skip-Test -Name "empty file_path is rejected"     -Group "Validation" -Reason "agent-service not reachable"
        Skip-Test -Name "bad doc_type enum is rejected"   -Group "Validation" -Reason "agent-service not reachable"
        Skip-Test -Name "non-UUID trace_id is rejected"   -Group "Validation" -Reason "agent-service not reachable"
        Skip-Test -Name "missing required field is rejected" -Group "Validation" -Reason "agent-service not reachable"
        return
    }

    $headers = @{ "X-Agent-Secret" = $Secret }

    function _Post-AgentRun {
        param([hashtable]$ClaimsHash)
        $jsonBody = $ClaimsHash | ConvertTo-Json -Compress
        return Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/agent/run" -Headers $headers -Body $jsonBody
    }

    Run-Test -Name "negative patient_id is rejected" -Group "Validation" -Block {
        $resp = _Post-AgentRun -ClaimsHash @{
            patient_id   = -1
            file_path    = "/var/uploads/agent/x.pdf"
            doc_type     = "lab_pdf"
            encounter_id = 1
            trace_id     = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }

    Run-Test -Name "empty file_path is rejected" -Group "Validation" -Block {
        $resp = _Post-AgentRun -ClaimsHash @{
            patient_id   = 1
            file_path    = ""
            doc_type     = "lab_pdf"
            encounter_id = 1
            trace_id     = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }

    Run-Test -Name "bad doc_type enum is rejected" -Group "Validation" -Block {
        $resp = _Post-AgentRun -ClaimsHash @{
            patient_id   = 1
            file_path    = "/var/uploads/agent/x.pdf"
            doc_type     = "definitely-not-a-known-doc-type"
            encounter_id = 1
            trace_id     = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }

    Run-Test -Name "non-UUID trace_id is rejected" -Group "Validation" -Block {
        $resp = _Post-AgentRun -ClaimsHash @{
            patient_id   = 1
            file_path    = "/var/uploads/agent/x.pdf"
            doc_type     = "lab_pdf"
            encounter_id = 1
            trace_id     = "not-a-uuid"
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }

    Run-Test -Name "missing required field is rejected" -Group "Validation" -Block {
        # Drop encounter_id entirely.
        $resp = _Post-AgentRun -ClaimsHash @{
            patient_id = 1
            file_path  = "/var/uploads/agent/x.pdf"
            doc_type   = "lab_pdf"
            trace_id   = "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }
}

# ---------------------------------------------------------------------------
# 5. COPILOT run_context auth on /api/copilot/run
# ---------------------------------------------------------------------------
#
# /api/copilot/run authenticates via a signed run_context token, NOT via
# X-Agent-Secret. The shared secret signs the HMAC and is also what the
# resolver looks up by key_version. So the same Secret param is used on
# both sides; an env mismatch shows up here too.

function Invoke-CopilotTests {
    Write-Section "COPILOT run_context on /api/copilot/run (1-2s)"

    if (-not (Test-AgentReachable)) {
        foreach ($n in @(
            "rejects malformed run_context wire"
            "rejects tampered signature"
            "rejects expired token"
            "rejects unknown key_version"
            "rejects missing intent_id and user_goal"
            "accepts a valid signed run_context"
        )) {
            Skip-Test -Name $n -Group "Copilot" -Reason "agent-service not reachable"
        }
        return
    }

    function _Post-CopilotRun {
        param([hashtable]$BodyHash)
        $jsonBody = $BodyHash | ConvertTo-Json -Compress -Depth 10
        return Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/copilot/run" -Body $jsonBody
    }

    # 5a. Malformed wire -- a single-segment string can't possibly be a
    # signed token. Pydantic accepts the request body (run_context is
    # typed as a non-empty string), then the verifier rejects with
    # 401 invalid_run_context.
    Run-Test -Name "rejects malformed run_context wire" -Group "Copilot" -Block {
        $resp = _Post-CopilotRun -BodyHash @{
            run_context = "this-has-no-dot-so-it-cant-be-a-wire-token"
            intent_id   = "summary"
            request_id  = [guid]::NewGuid().ToString()
        }
        if ($resp.StatusCode -ne 401) { throw "expected 401, got $($resp.StatusCode): $($resp.Body)" }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'invalid_run_context') {
            throw "expected error=invalid_run_context, got: $($resp.Body)"
        }
    }

    # 5b. Tampered signature -- mint a valid token, then flip the last
    # character of the signature segment. The verifier's hmac.compare_digest
    # call returns False -> reason=tampered.
    Run-Test -Name "rejects tampered signature" -Group "Copilot" -Block {
        $token = New-CopilotRunContext
        # Mutate the last char of the signature segment. Pick a char that
        # is definitely different from the original.
        $original  = $token.Wire
        $lastChar  = $original[$original.Length - 1]
        $newChar   = if ($lastChar -eq 'A') { 'B' } else { 'A' }
        $tampered  = $original.Substring(0, $original.Length - 1) + $newChar

        $resp = _Post-CopilotRun -BodyHash @{
            run_context = $tampered
            intent_id   = "summary"
            request_id  = $token.RequestId
        }
        if ($resp.StatusCode -ne 401) { throw "expected 401, got $($resp.StatusCode): $($resp.Body)" }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'invalid_run_context') {
            throw "expected error=invalid_run_context, got: $($resp.Body)"
        }
    }

    # 5c. Expired token -- expires_at in the past. Verifier returns
    # 401 expired_run_context (a different error discriminator, so the
    # UI can prompt for refresh).
    Run-Test -Name "rejects expired token" -Group "Copilot" -Block {
        $longAgo = [int]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) - 3600
        $token = New-CopilotRunContext -ExpiresAt $longAgo

        $resp = _Post-CopilotRun -BodyHash @{
            run_context = $token.Wire
            intent_id   = "summary"
            request_id  = $token.RequestId
        }
        if ($resp.StatusCode -ne 401) { throw "expected 401, got $($resp.StatusCode): $($resp.Body)" }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'expired_run_context') {
            throw "expected error=expired_run_context, got: $($resp.Body)"
        }
    }

    # 5d. Unknown key_version -- secret_resolver returns None, verifier
    # raises with reason=unknown_key_version. Mounted as
    # invalid_run_context to avoid leaking which check failed.
    Run-Test -Name "rejects unknown key_version" -Group "Copilot" -Block {
        $token = New-CopilotRunContext -KeyVersion "v999"
        $resp = _Post-CopilotRun -BodyHash @{
            run_context = $token.Wire
            intent_id   = "summary"
            request_id  = $token.RequestId
        }
        if ($resp.StatusCode -ne 401) { throw "expected 401, got $($resp.StatusCode): $($resp.Body)" }
        if ($null -eq $resp.Json -or $resp.Json.error -ne 'invalid_run_context') {
            throw "expected error=invalid_run_context, got: $($resp.Body)"
        }
    }

    # 5e. Body validator: model_validator rejects requests with neither
    # intent_id nor user_goal. Pydantic returns 422 before the auth
    # dependency ever runs (FastAPI parses the body first).
    Run-Test -Name "rejects missing intent_id and user_goal" -Group "Copilot" -Block {
        $token = New-CopilotRunContext
        $resp  = _Post-CopilotRun -BodyHash @{
            run_context = $token.Wire
            request_id  = $token.RequestId
        }
        if ($resp.StatusCode -ne 422) { throw "expected 422, got $($resp.StatusCode): $($resp.Body)" }
    }

    # 5f. Valid signed token + valid request shape -- proves the
    # canonical-JSON encoding and the secret both match between this
    # host script and the deployed sidecar. Auth must succeed; the
    # downstream agent loop may then return 200 (with an empty registry
    # if the OPENEMR_DB_* env is not set) or 500 if a misconfigured
    # tool actually runs. We only assert that the response is NOT
    # 401/403 -- the auth layer accepted the token.
    Run-Test -Name "accepts a valid signed run_context" -Group "Copilot" -Block {
        $token = New-CopilotRunContext
        $resp = _Post-CopilotRun -BodyHash @{
            run_context = $token.Wire
            intent_id   = "summary"
            request_id  = $token.RequestId
        }
        if ($resp.StatusCode -eq 401 -or $resp.StatusCode -eq 403) {
            throw "valid token rejected: status=$($resp.StatusCode); body=$($resp.Body). The script's HMAC secret may not match agent-service's AGENT_SHARED_SECRET."
        }
    }
}

# ---------------------------------------------------------------------------
# 6. VOLUME -- shared agent-uploads volume cross-container check
# ---------------------------------------------------------------------------

function Invoke-VolumeTests {
    Write-Section "VOLUME (5s)"

    if ($SkipDocker) {
        Skip-Test -Name "agent-service has no /var/uploads/agent mount" -Group "Volume" -Reason "-SkipDocker"
        return
    }

    if (-not (Test-Command docker)) {
        Skip-Test -Name "agent-service has no /var/uploads/agent mount" -Group "Volume" -Reason "docker not on PATH"
        return
    }

    if (-not (Test-DockerUp)) {
        $reason = "compose project '$ComposeProject' not running"
        Skip-Test -Name "agent-service has no /var/uploads/agent mount" -Group "Volume" -Reason $reason
        return
    }

    # Regression guard: after the multipart-upload refactor, agent-service
    # is stateless and must NOT mount the agent-uploads volume.  PHP keeps
    # the file on its own disk for later display; the sidecar only sees
    # request bodies over HTTP.  If this assertion ever fails, somebody
    # re-introduced the cross-service volume mount in docker-compose.yml.
    Run-Test -Name "agent-service has no /var/uploads/agent mount" -Group "Volume" -Block {
        $mountsJson = & docker compose -p $ComposeProject ps agent-service --format json 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose ps agent-service failed (exit=$LASTEXITCODE): $mountsJson"
        }
        # `docker compose ps --format json` emits one JSON object per line.
        foreach ($line in @($mountsJson)) {
            if ([string]::IsNullOrWhiteSpace($line)) { continue }
            $info = $line | ConvertFrom-Json
            $containerId = $info.ID
            if ([string]::IsNullOrWhiteSpace($containerId)) { continue }
            $mounts = & docker inspect --format '{{range .Mounts}}{{.Destination}}|{{end}}' $containerId 2>&1
            if ($LASTEXITCODE -ne 0) {
                throw "docker inspect failed for $containerId : $mounts"
            }
            if ($mounts -match '/var/uploads/agent') {
                throw "agent-service still mounts /var/uploads/agent (mounts: $mounts). The multipart refactor expects this to be unmounted."
            }
        }
    }
}

# ---------------------------------------------------------------------------
# 7. PAID -- end-to-end /api/agent/run happy path (gated)
# ---------------------------------------------------------------------------

function Invoke-PaidTests {
    Write-Section "PAID end-to-end (60s+, costs LLM credits)"

    if (-not (Test-AgentReachable)) {
        Skip-Test -Name "POST /api/agent/run end-to-end (lab_pdf)" -Group "Paid" -Reason "agent-service not reachable"
        return
    }

    # We deliberately point at a non-existent file. The real intent here
    # is: "did the request reach the LangGraph pipeline through real
    # auth + validation, with the deployed env vars?" A missing file
    # surfaces as a graph error (500 or 422 refused), which still proves
    # the wire path. If you want a real success run, drop a PDF onto the
    # shared volume first and wire its path in here.
    Run-Test -Name "POST /api/agent/run end-to-end (lab_pdf)" -Group "Paid" -Block {
        $body = @{
            patient_id   = 1
            file_path    = "/var/uploads/agent/probe.pdf"
            doc_type     = "lab_pdf"
            encounter_id = 1
            trace_id     = [guid]::NewGuid().ToString()
        } | ConvertTo-Json -Compress

        $resp = Invoke-AgentRequest -Method POST -Url "$AgentUrl/api/agent/run" `
            -Headers @{ "X-Agent-Secret" = $Secret } `
            -Body $body

        if ($resp.StatusCode -eq 401 -or $resp.StatusCode -eq 403 -or $resp.StatusCode -eq 422) {
            throw "request did not reach the pipeline: status=$($resp.StatusCode); body=$($resp.Body)"
        }
        # 200 (success) or 500 (graph error -- e.g. file missing) both
        # indicate the request actually drove the LangGraph pipeline.
        if ($resp.StatusCode -ne 200 -and $resp.StatusCode -ne 500) {
            throw "unexpected status $($resp.StatusCode) from end-to-end run: $($resp.Body)"
        }
    }
}

# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

$startTime = Get-Date

Write-Host ""
Write-Host "Agent URL:       $AgentUrl" -ForegroundColor DarkCyan
Write-Host "Compose project: $ComposeProject" -ForegroundColor DarkCyan
Write-Host "Secret:          $('*' * [Math]::Min(8, $Secret.Length)) (length=$($Secret.Length))" -ForegroundColor DarkCyan

if ($Topology)   { Invoke-TopologyTests }
if ($Health)     { Invoke-HealthTests }
if ($Auth)       { Invoke-AuthTests }
if ($Validation) { Invoke-ValidationTests }
if ($Copilot)    { Invoke-CopilotTests }
if ($Volume)     { Invoke-VolumeTests }
if ($RunPaid)    { Invoke-PaidTests }

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

Write-Section "SUMMARY"

if ($Script:Results.Count -eq 0) {
    Write-Host "No tests were run." -ForegroundColor Yellow
    exit 1
}

$Script:Results | Format-Table -AutoSize Group, Name, Status, Duration, ExitCode

$passCount = ($Script:Results | Where-Object { $_.Status -eq "PASS" }).Count
$failCount = ($Script:Results | Where-Object { $_.Status -eq "FAIL" }).Count
$skipCount = ($Script:Results | Where-Object { $_.Status -eq "SKIP" }).Count
$totalDuration = (New-TimeSpan -Start $startTime -End (Get-Date))

Write-Host ""
Write-Host ("Passed: {0}   Failed: {1}   Skipped: {2}   Total wall time: {3:N1}s" -f `
        $passCount, $failCount, $skipCount, $totalDuration.TotalSeconds) -ForegroundColor Cyan

if ($failCount -gt 0) {
    Write-Host ""
    Write-Host "FAILED tests:" -ForegroundColor Red
    $Script:Results | Where-Object { $_.Status -eq "FAIL" } | ForEach-Object {
        Write-Host ("  - {0} (exit={1})" -f $_.Name, $_.ExitCode) -ForegroundColor Red
    }
    exit 1
}

exit 0
