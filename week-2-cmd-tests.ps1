<#
.SYNOPSIS
    Run every command-line test that verifies Week 2 work on this repo.

.DESCRIPTION
    Aggregates fast / medium / slow test entry points discovered in the
    repository so a single command can validate Week 2 deliverables.

    Test entry points were derived from:
        - composer.json scripts (phpunit-isolated, phpstan, phpcs,
          rector-check, php-syntax-check)
        - agent-service/pyproject.toml + tests/ directory (pytest)
        - agent-service/agent_service/eval/__main__.py (50-case eval)
        - .git/hooks/pre-push (HARD GATE: 50-case agent eval)
        - package.json (lint:js, stylelint, test:js)
        - CLAUDE.md devtools commands (unit-test, services-test, api-test)

    The 50-case agent eval is the assignment's HARD GATE. It is included
    in the Slow group and runs the same command the pre-push hook runs.

.PARAMETER Fast
    Run only the fast tests (<30s each, no Docker, no network).

.PARAMETER Medium
    Run only the medium tests (30s-2min, includes phpstan and pytest).

.PARAMETER Slow
    Run only the slow tests (2min+, includes the 50-case eval and any
    Docker-backed PHPUnit suites).

.PARAMETER All
    Run every group. This is the default if no group switch is supplied.

.PARAMETER SkipDocker
    Skip every test that requires Docker, even within the Slow group.
    Useful when Docker Desktop is intentionally off.

.EXAMPLE
    PS> .\week-2-cmd-tests.ps1
    Runs every test (Fast + Medium + Slow).

.EXAMPLE
    PS> .\week-2-cmd-tests.ps1 -Fast
    Runs only the fast suite.

.EXAMPLE
    PS> .\week-2-cmd-tests.ps1 -Slow -SkipDocker
    Runs the slow suite but skips Docker-backed tests, leaving the
    50-case agent eval as the hard gate.

.NOTES
    Windows PowerShell 5.1 compatible. No '&&' chains; uses
    `if ($LASTEXITCODE -eq 0) { ... }` or `; if ($?) { ... }` style
    sequencing. File writes inside the script use `-Encoding utf8`.
#>

[CmdletBinding()]
param(
    [Switch]$Fast,
    [Switch]$Medium,
    [Switch]$Slow,
    [Switch]$All,
    [Switch]$SkipDocker
)

$ErrorActionPreference = "Continue"

# Resolve repo root from the script's location so the script can be invoked
# from any working directory.
$RepoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$AgentDir = Join-Path -Path $RepoRoot -ChildPath "agent-service"
$DockerDir = Join-Path -Path $RepoRoot -ChildPath "docker\development-easy"

# Default behaviour: if the caller passed no group switches, run All.
if (-not ($Fast -or $Medium -or $Slow -or $All)) {
    $All = $true
}
if ($All) {
    $Fast = $true
    $Medium = $true
    $Slow = $true
}

# Results collection -- one PSCustomObject per Run-Test invocation.
$Script:Results = @()

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
        Prints [ RUN  ] <name>, executes the scriptblock, and prints
        [ PASS ] or [ FAIL ] with timing. Records the outcome on
        $Script:Results so the end-of-run summary can render a table.
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

    try {
        & $Block
        if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) {
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

function Test-DockerUp {
    <#
    .SYNOPSIS
        Return $true if the OpenEMR dev compose stack is up and the
        openemr container is reachable for `docker compose exec`.
    #>
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        return $false
    }
    if (-not (Test-Path -LiteralPath $DockerDir)) {
        return $false
    }
    Push-Location $DockerDir
    try {
        $output = & docker compose ps --status running --services 2>$null
        if ($LASTEXITCODE -ne 0) { return $false }
        if ($output -match "openemr") { return $true }
        return $false
    } finally {
        Pop-Location
    }
}

function Test-Command {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Get-PythonCmd {
    <#
    .SYNOPSIS
        Pick the same Python launcher the pre-push hook prefers:
        py > python3 > python. Returns $null if none is available.
    #>
    foreach ($candidate in @("py", "python3", "python")) {
        if (Test-Command $candidate) { return $candidate }
    }
    return $null
}

# ---------------------------------------------------------------------------
# FAST tests (<30s, no Docker, no network)
# ---------------------------------------------------------------------------
function Invoke-FastTests {
    Write-Section "FAST tests (<30s)"

    # PHP syntax check via the composer script (php -l on every tracked .php).
    if (Test-Command composer) {
        Run-Test -Name "composer php-syntax-check" -Group "Fast" -Block {
            Push-Location $RepoRoot
            try {
                & composer php-syntax-check
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "composer php-syntax-check" -Group "Fast" -Reason "composer not on PATH"
    }

    # PHPUnit isolated suite (host-only, no DB, no Docker).
    if (Test-Command composer) {
        Run-Test -Name "composer phpunit-isolated" -Group "Fast" -Block {
            Push-Location $RepoRoot
            try {
                & composer phpunit-isolated
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "composer phpunit-isolated" -Group "Fast" -Reason "composer not on PATH"
    }

    # ESLint (quick lint pass).
    if (Test-Command npm) {
        Run-Test -Name "npm run lint:js" -Group "Fast" -Block {
            Push-Location $RepoRoot
            try {
                & npm run lint:js --silent
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "npm run lint:js" -Group "Fast" -Reason "npm not on PATH"
    }
}

# ---------------------------------------------------------------------------
# MEDIUM tests (30s-2min)
# ---------------------------------------------------------------------------
function Invoke-MediumTests {
    Write-Section "MEDIUM tests (30s-2min)"

    # agent-service pytest -- runs from agent-service/ as documented in
    # the pyproject.toml [tool.pytest.ini_options] testpaths = ["tests"].
    $pythonCmd = Get-PythonCmd
    if ($pythonCmd -and (Test-Path -LiteralPath $AgentDir)) {
        Run-Test -Name "agent-service pytest" -Group "Medium" -Block {
            Push-Location $AgentDir
            try {
                & $pythonCmd -m pytest
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "agent-service pytest" -Group "Medium" -Reason "no python launcher or agent-service dir"
    }

    # PHPStan static analysis (level 10) -- composer script applies the
    # 4G memory limit.
    if (Test-Command composer) {
        Run-Test -Name "composer phpstan" -Group "Medium" -Block {
            Push-Location $RepoRoot
            try {
                & composer phpstan
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "composer phpstan" -Group "Medium" -Reason "composer not on PATH"
    }

    # PHP CodeSniffer.
    if (Test-Command composer) {
        Run-Test -Name "composer phpcs" -Group "Medium" -Block {
            Push-Location $RepoRoot
            try {
                & composer phpcs
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "composer phpcs" -Group "Medium" -Reason "composer not on PATH"
    }

    # Rector dry-run (modernization checks).
    if (Test-Command composer) {
        Run-Test -Name "composer rector-check" -Group "Medium" -Block {
            Push-Location $RepoRoot
            try {
                & composer rector-check
            } finally {
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "composer rector-check" -Group "Medium" -Reason "composer not on PATH"
    }
}

# ---------------------------------------------------------------------------
# SLOW tests (2min+) -- includes the HARD GATE (50-case agent eval).
# ---------------------------------------------------------------------------
function Invoke-SlowTests {
    Write-Section "SLOW tests (2min+)"

    # HARD GATE: the offline 50-case agent eval. Same invocation as the
    # pre-push hook in scripts/hooks/pre-push, run from agent-service/.
    # FakeLLMClient refuses to construct while OPENAI_API_KEY is set, so
    # we scope-clear it in a child process by clearing the variable for
    # the lifetime of this invocation.
    $pythonCmd = Get-PythonCmd
    if ($pythonCmd -and (Test-Path -LiteralPath $AgentDir)) {
        Run-Test -Name "agent eval (50-case HARD GATE)" -Group "Slow" -Block {
            Push-Location $AgentDir
            $savedKey = $env:OPENAI_API_KEY
            try {
                Remove-Item Env:OPENAI_API_KEY -ErrorAction SilentlyContinue
                & $pythonCmd -m agent_service.eval --baseline agent_service/eval/baseline.json
            } finally {
                if ($null -ne $savedKey) { $env:OPENAI_API_KEY = $savedKey }
                Pop-Location
            }
        }

        # M22 copilot-tools suite -- second eval suite exposed by the
        # same CLI.
        Run-Test -Name "agent eval (copilot-tools suite)" -Group "Slow" -Block {
            Push-Location $AgentDir
            $savedKey = $env:OPENAI_API_KEY
            try {
                Remove-Item Env:OPENAI_API_KEY -ErrorAction SilentlyContinue
                & $pythonCmd -m agent_service.eval --suite copilot-tools
            } finally {
                if ($null -ne $savedKey) { $env:OPENAI_API_KEY = $savedKey }
                Pop-Location
            }
        }
    } else {
        Skip-Test -Name "agent eval (50-case HARD GATE)" -Group "Slow" -Reason "no python launcher or agent-service dir"
        Skip-Test -Name "agent eval (copilot-tools suite)" -Group "Slow" -Reason "no python launcher or agent-service dir"
    }

    # Docker-backed devtools suites (unit-test, services-test, api-test).
    # CLAUDE.md documents these as the Docker-required test commands.
    $dockerSuites = @("unit-test", "services-test", "api-test")

    if ($SkipDocker) {
        foreach ($suite in $dockerSuites) {
            Skip-Test -Name "devtools $suite" -Group "Slow" -Reason "-SkipDocker"
        }
        return
    }

    if (-not (Test-DockerUp)) {
        foreach ($suite in $dockerSuites) {
            Skip-Test -Name "devtools $suite" -Group "Slow" -Reason "openemr container not running -- start with: docker compose up -d --wait"
        }
        return
    }

    foreach ($suite in $dockerSuites) {
        $name = "devtools $suite"
        $suiteCopy = $suite  # capture for closure
        Run-Test -Name $name -Group "Slow" -Block {
            Push-Location $DockerDir
            try {
                & docker compose exec -T openemr /root/devtools $suiteCopy
            } finally {
                Pop-Location
            }
        }
    }
}

# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------
$startTime = Get-Date

if ($Fast)   { Invoke-FastTests }
if ($Medium) { Invoke-MediumTests }
if ($Slow)   { Invoke-SlowTests }

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
