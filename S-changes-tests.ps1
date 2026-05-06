# =============================================================================
# S-changes-tests.ps1
# =============================================================================
# Runs every cmdline-testable check that validates the Week 2 sidecar work
# (steps S0..S26) plus the session follow-ups (mariadb CLI fix, pre-push hook
# auto-unset of OPENAI_API_KEY, dev-easy auto-registration of the upload form).
#
# Run from the repo root:
#     .\S-changes-tests.ps1
#
# Useful flags:
#     -SkipDocker        Skip every test that needs Docker
#     -SkipPhpTests      Skip PHP isolated tests
#     -SkipPythonTests   Skip Python tests (eval, observability, pytest)
#     -StopOnFailure     Stop on the first failed test (default: continue)
#     -OnlyStatic        Run only the fast static/grep checks
#
# Exit code: 0 iff every executed test passed; 1 otherwise.
#
# -----------------------------------------------------------------------------
# Manual UI checks NOT covered here (open the deployed/local app and verify):
# -----------------------------------------------------------------------------
#  1. (If demo container is not running)
#         docker compose --project-name openemr down -v
#         docker compose --project-name openemr up -d
#     Wait until `docker compose ps` shows openemr + agent-service healthy.
#  2. Browse to https://localhost:9300/, log in as admin / pass.
#  3. Open a (synthetic/demo) patient -> open an encounter.
#  4. Click "Add Form" in the encounter -> confirm
#     "Upload Document (Co-Pilot)" appears in the picker.
#     (S14 + Option B; if Option B has not landed, you may need
#     Admin -> Forms -> Forms Administration -> Register the form first.)
#  5. Choose "Upload Document (Co-Pilot)" -> upload one of the synthetic
#     lab PDFs you keep for demos (e.g., generated via generate-lab-pdf.ps1).
#  6. Confirm processing completes without errors and the form row appears
#     on the encounter timeline.
#  7. Open the form row -> the view page should render the PDF on the
#     left (pdf.js) and extracted fields on the right (S18).
#  8. Hover an extracted field -> a bbox overlay should appear over the
#     PDF region the value came from.
#  9. Click an extracted field -> the PDF should scroll to the page and
#     flash the overlay.
# 10. Click a guideline citation chip -> a side panel should open showing
#     the snippet and source URL.
# 11. (Optional) Upload one intake form fixture -> confirm the legacy
#     intake path still works.
# 12. Tail sidecar logs in another terminal:
#         docker compose --project-name openemr logs -f agent-service
#     Confirm tool_sequence, latency, and cost fields appear, and that
#     the log lines contain no raw PHI (no names, no SSN-like strings).
# -----------------------------------------------------------------------------

[CmdletBinding()]
param(
    [switch]$SkipDocker,
    [switch]$SkipPhpTests,
    [switch]$SkipPythonTests,
    [switch]$StopOnFailure,
    [switch]$OnlyStatic
)

$ErrorActionPreference = 'Continue'

$repoRoot = $PSScriptRoot
if (-not $repoRoot) { $repoRoot = (Get-Location).Path }
Set-Location $repoRoot

$results = New-Object System.Collections.Generic.List[object]

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------

function Test-Step {
    param(
        [string]$Name,
        [scriptblock]$Action
    )
    Write-Host ""
    Write-Host "=== $Name ===" -ForegroundColor Cyan
    $start = Get-Date
    $passed = $true
    $errorText = $null
    try {
        & $Action
        if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) {
            $passed = $false
            $errorText = "exit $LASTEXITCODE"
        }
    } catch {
        $passed = $false
        $errorText = $_.Exception.Message
        Write-Host "  ERROR: $errorText" -ForegroundColor Red
    }
    $elapsed = ((Get-Date) - $start).TotalSeconds
    $status = if ($passed) { 'PASS' } else { 'FAIL' }
    $color = if ($passed) { 'Green' } else { 'Red' }
    Write-Host ("  [{0}] {1} ({2:N1}s)" -f $status, $Name, $elapsed) -ForegroundColor $color
    $results.Add([PSCustomObject]@{
        Name    = $Name
        Status  = $status
        Seconds = [math]::Round($elapsed, 1)
        Error   = $errorText
    })
    if (-not $passed -and $StopOnFailure) {
        Write-Host "Stopping on first failure (-StopOnFailure)." -ForegroundColor Yellow
        Show-Summary
        exit 1
    }
}

function Skip-Step {
    param([string]$Name, [string]$Reason)
    Write-Host ""
    Write-Host "=== $Name ===" -ForegroundColor DarkGray
    Write-Host "  [SKIP] $Reason" -ForegroundColor DarkGray
    $results.Add([PSCustomObject]@{
        Name    = $Name
        Status  = 'SKIP'
        Seconds = 0
        Error   = $Reason
    })
}

function Assert-FileContains {
    param([string]$Path, [string]$Pattern, [string]$Description)
    if (-not (Test-Path $Path)) {
        throw "$Description failed: file not found: $Path"
    }
    if (-not (Select-String -Path $Path -Pattern $Pattern -Quiet)) {
        throw "$Description failed: pattern '$Pattern' not in $Path"
    }
    Write-Host "  ok: $Description"
}

function Assert-FileNotContains {
    param([string]$Path, [string]$Pattern, [string]$Description)
    if (-not (Test-Path $Path)) {
        throw "$Description failed: file not found: $Path"
    }
    if (Select-String -Path $Path -Pattern $Pattern -Quiet) {
        throw "$Description failed: forbidden pattern '$Pattern' still present in $Path"
    }
    Write-Host "  ok: $Description"
}

function Have-Command {
    param([string]$Cmd)
    $null -ne (Get-Command $Cmd -ErrorAction SilentlyContinue)
}

function Show-Summary {
    Write-Host ""
    Write-Host "================== Summary ==================" -ForegroundColor Cyan
    $pass = ($results | Where-Object { $_.Status -eq 'PASS' }).Count
    $fail = ($results | Where-Object { $_.Status -eq 'FAIL' }).Count
    $skip = ($results | Where-Object { $_.Status -eq 'SKIP' }).Count
    $results | Format-Table -AutoSize | Out-String | Write-Host
    Write-Host ("PASS: {0}   FAIL: {1}   SKIP: {2}" -f $pass, $fail, $skip)
    if ($fail -gt 0) {
        Write-Host "FAILED" -ForegroundColor Red
    } else {
        Write-Host "OK" -ForegroundColor Green
    }
}

# -----------------------------------------------------------------------------
# Section 1 - static checks (fast, no external deps)
# -----------------------------------------------------------------------------

Test-Step "Static: HTTP contract doc exists (S1)" {
    if (-not (Test-Path "agent-service/CONTRACT.md")) { throw "agent-service/CONTRACT.md missing" }
    Assert-FileContains "agent-service/CONTRACT.md" "POST /api/agent/run" "endpoint listed"
    Assert-FileContains "agent-service/CONTRACT.md" "X-Agent-Secret" "auth header listed"
}

Test-Step "Static: agent-service package layout (S2)" {
    foreach ($p in @(
        "agent-service/pyproject.toml",
        "agent-service/agent_service/__init__.py",
        "agent-service/agent_service/schemas/__init__.py",
        "agent-service/agent_service/workers/__init__.py",
        "agent-service/agent_service/rag/__init__.py",
        "agent-service/agent_service/eval/__init__.py"
    )) {
        if (-not (Test-Path $p)) { throw "missing: $p" }
    }
    Write-Host "  ok: package layout"
}

Test-Step "Static: clinical schemas (S5)" {
    foreach ($p in @(
        "agent-service/agent_service/schemas/citation.py",
        "agent-service/agent_service/schemas/lab_pdf.py",
        "agent-service/agent_service/schemas/intake_form.py"
    )) {
        if (-not (Test-Path $p)) { throw "missing: $p" }
    }
    Write-Host "  ok: schema modules present"
}

Test-Step "Static: 50 eval fixtures + manifest (S19)" {
    $labs   = (Get-ChildItem "agent-service/agent_service/eval/fixtures/lab_*.json" -ErrorAction Stop).Count
    $intake = (Get-ChildItem "agent-service/agent_service/eval/fixtures/intake_*.json" -ErrorAction Stop).Count
    if ($labs -ne 25)   { throw "expected 25 lab fixtures, found $labs" }
    if ($intake -ne 25) { throw "expected 25 intake fixtures, found $intake" }
    if (-not (Test-Path "agent-service/agent_service/eval/fixtures/manifest.json")) { throw "manifest missing" }
    Write-Host "  ok: 25 lab + 25 intake + manifest"
}

Test-Step "Static: pre-push hook + workflow + installer (S21)" {
    if (-not (Test-Path "scripts/hooks/pre-push"))         { throw "scripts/hooks/pre-push missing" }
    if (-not (Test-Path "scripts/install-eval-hook.ps1"))  { throw "Windows installer missing" }
    if (-not (Test-Path "scripts/install-eval-hook.sh"))   { throw "Unix installer missing" }
    if (-not (Test-Path ".github/workflows/agent-eval.yml")) { throw "agent-eval.yml workflow missing" }
}

Test-Step "Static: agent-eval.yml is valid YAML (S21)" {
    if (Have-Command 'py') {
        & py -c "import yaml,sys; yaml.safe_load(open('.github/workflows/agent-eval.yml'))" 2>&1 | Write-Host
        if ($LASTEXITCODE -ne 0) { throw "agent-eval.yml is not valid YAML" }
    } else {
        Skip-Step "Static: agent-eval.yml is valid YAML (S21)" "no py interpreter"
    }
}

Test-Step "Static: pre-push hook auto-unsets OPENAI_API_KEY (session fix)" {
    Assert-FileContains "scripts/hooks/pre-push" "unset OPENAI_API_KEY" "unset present in hook"
    Assert-FileContains "scripts/hooks/pre-push" "SKIP_EVAL_HOOK" "emergency bypass preserved"
}

Test-Step "Static: dev-easy DB verification commands use mariadb CLI (session fix)" {
    Assert-FileNotContains "sidecar-detailed-steps.md"                                                   "exec -T mysql mysql -u" "sidecar-detailed-steps.md no longer uses mysql CLI"
    Assert-FileNotContains "tests/Tests/Isolated/Services/Agent/Sidecar/CitationPersistenceServiceTest.php" "    mysql -uroot"        "Citation test file no longer uses mysql CLI"
    Assert-FileNotContains "interface/forms/upload_intake_form/table.sql"                                "    mysql -uroot"        "table.sql no longer uses mysql CLI"
}

Test-Step "Static: observability + eval modules (S20, S22, S25)" {
    foreach ($p in @(
        "agent-service/agent_service/eval/runner.py",
        "agent-service/agent_service/eval/__main__.py",
        "agent-service/agent_service/eval/baseline.json",
        "agent-service/agent_service/observability/run_record.py",
        "agent-service/agent_service/observability/report.py"
    )) {
        if (-not (Test-Path $p)) { throw "missing: $p" }
    }
}

Test-Step "Static: sidecar Dockerfile + .dockerignore (S23)" {
    if (-not (Test-Path "agent-service/Dockerfile"))      { throw "agent-service/Dockerfile missing" }
    if (-not (Test-Path "agent-service/.dockerignore"))   { throw "agent-service/.dockerignore missing" }
    Assert-FileContains "agent-service/Dockerfile" "uvicorn" "Dockerfile mentions uvicorn"
    Assert-FileContains "docker/development-easy/docker-compose.yml" "agent-service" "compose mentions agent-service"
}

Test-Step "Static: Week 2 deployment guide (S24)" {
    if (-not (Test-Path "docs/WEEK2_SIDECAR.md"))            { throw "docs/WEEK2_SIDECAR.md missing" }
    if (-not (Test-Path "docs/WEEK2_DEMO_VERIFICATION.md"))  { throw "docs/WEEK2_DEMO_VERIFICATION.md missing" }
    Assert-FileContains "docs/WEEK2_SIDECAR.md" "AGENT_SHARED_SECRET" "shared secret documented"
    Assert-FileContains "docs/WEEK2_SIDECAR.md" "OPENEMR_AGENT_SIDECAR_URL" "sidecar URL documented"
}

if ($OnlyStatic) {
    Show-Summary
    exit ($(if (($results | Where-Object { $_.Status -eq 'FAIL' }).Count -gt 0) { 1 } else { 0 }))
}

# -----------------------------------------------------------------------------
# Section 2 - Python (pytest + eval gate + cost/latency report)
# -----------------------------------------------------------------------------

if ($SkipPythonTests) {
    Skip-Step "Python: pytest suite"        "-SkipPythonTests"
    Skip-Step "Python: eval baseline"       "-SkipPythonTests"
    Skip-Step "Python: regression injection" "-SkipPythonTests"
    Skip-Step "Python: cost/latency report" "-SkipPythonTests"
    Skip-Step "Python: pre-push hook smoke" "-SkipPythonTests"
} elseif (-not (Have-Command 'py')) {
    Skip-Step "Python: pytest suite"         "py interpreter not on PATH"
    Skip-Step "Python: eval baseline"        "py interpreter not on PATH"
    Skip-Step "Python: regression injection" "py interpreter not on PATH"
    Skip-Step "Python: cost/latency report"  "py interpreter not on PATH"
    Skip-Step "Python: pre-push hook smoke"  "py interpreter not on PATH"
} else {

    Test-Step "Python: pytest suite (full agent-service)" {
        Push-Location "agent-service"
        try {
            & py -m pytest -q
        } finally { Pop-Location }
    }

    Test-Step "Python: eval baseline passes 100% (S20)" {
        Push-Location "agent-service"
        try {
            & py -m agent_service.eval --baseline agent_service/eval/baseline.json
        } finally { Pop-Location }
    }

    Test-Step "Python: regression-injection drop-citations FAILS (S22)" {
        Push-Location "agent-service"
        try {
            & py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression drop-citations
            if ($LASTEXITCODE -eq 0) { throw "expected non-zero exit, got 0 (regression should have failed the gate)" }
            $global:LASTEXITCODE = 0
        } finally { Pop-Location }
    }

    Test-Step "Python: regression-injection wrong-value FAILS (S22)" {
        Push-Location "agent-service"
        try {
            & py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression wrong-value
            if ($LASTEXITCODE -eq 0) { throw "expected non-zero exit, got 0" }
            $global:LASTEXITCODE = 0
        } finally { Pop-Location }
    }

    Test-Step "Python: regression-injection flip-abnormal-flags FAILS (S22)" {
        Push-Location "agent-service"
        try {
            & py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression flip-abnormal-flags
            if ($LASTEXITCODE -eq 0) { throw "expected non-zero exit, got 0" }
            $global:LASTEXITCODE = 0
        } finally { Pop-Location }
    }

    Test-Step "Python: cost/latency report generates (S25)" {
        Push-Location "agent-service"
        try {
            $records = "agent_service/eval/run-records.jsonl"
            if (Test-Path $records) { Remove-Item $records -Force }
            & py -m agent_service.eval --baseline agent_service/eval/baseline.json --record-runs $records
            if ($LASTEXITCODE -ne 0) { throw "eval --record-runs failed" }
            $report = Join-Path $repoRoot "cost-latency-report.md"
            if (Test-Path $report) { Remove-Item $report -Force }
            & py -m agent_service.observability.report --records $records --out $report
            if ($LASTEXITCODE -ne 0) { throw "observability.report failed" }
            if (-not (Test-Path $report)) { throw "report file not created" }
            $content = Get-Content $report -Raw
            foreach ($section in @("p50", "p95", "Cost", "Bottleneck", "100", "1000", "10000")) {
                if ($content -notmatch [regex]::Escape($section)) {
                    throw "report missing expected section/keyword: $section"
                }
            }
            Write-Host "  ok: report has all expected sections"
        } finally { Pop-Location }
    }

    Test-Step "Pre-push hook: passes with OPENAI_API_KEY set (session fix)" {
        $saved = $env:OPENAI_API_KEY
        try {
            $env:OPENAI_API_KEY = "sk-test-fake-value-for-S-changes-tests"
            $hook = "scripts/hooks/pre-push"
            if (-not (Test-Path $hook)) { throw "hook script missing" }
            # Invoke directly via bash; hook doesn't read stdin during fast path.
            & bash $hook origin "https://example.invalid/openemr.git"
            if ($LASTEXITCODE -ne 0) { throw "hook exited $LASTEXITCODE" }
            if ($env:OPENAI_API_KEY -ne "sk-test-fake-value-for-S-changes-tests") {
                throw "hook leaked unset to outer shell -- subshell isolation broken"
            }
            Write-Host "  ok: hook ran cleanly with key set; outer shell preserved"
        } finally {
            $env:OPENAI_API_KEY = $saved
        }
    }
}

# -----------------------------------------------------------------------------
# Section 3 - PHP isolated tests
# -----------------------------------------------------------------------------

if ($SkipPhpTests) {
    Skip-Step "PHP: isolated tests (sidecar+citation+lab dispatcher)" "-SkipPhpTests"
} elseif (-not (Test-Path "vendor/bin/phpunit") -and -not (Test-Path "vendor/bin/phpunit.bat")) {
    Skip-Step "PHP: isolated tests" "vendor/bin/phpunit not present (run composer install)"
} else {
    Test-Step "PHP: isolated tests for sidecar/citation/lab dispatcher (S12-S18)" {
        $phpunit = "vendor/bin/phpunit"
        if (Test-Path "vendor/bin/phpunit.bat") { $phpunit = "vendor/bin/phpunit.bat" }
        & $phpunit -c phpunit-isolated.xml --filter "(AgentSidecar|SharedUpload|AgentServiceClient|LabPdf|Citation|UploadIntakeForm)"
    }
}

# -----------------------------------------------------------------------------
# Section 4 - Docker (compose validation, sidecar health)
# -----------------------------------------------------------------------------

if ($SkipDocker) {
    Skip-Step "Docker: compose config validation" "-SkipDocker"
    Skip-Step "Docker: sidecar /healthz"          "-SkipDocker"
    Skip-Step "Docker: form_upload_intake_form_citation table"  "-SkipDocker"
    Skip-Step "Docker: registry row for upload_intake_form"     "-SkipDocker"
} elseif (-not (Have-Command 'docker')) {
    Skip-Step "Docker: compose config validation" "docker not on PATH"
    Skip-Step "Docker: sidecar /healthz"          "docker not on PATH"
    Skip-Step "Docker: form_upload_intake_form_citation table"  "docker not on PATH"
    Skip-Step "Docker: registry row for upload_intake_form"     "docker not on PATH"
} else {
    Test-Step "Docker: compose config validates (S23)" {
        Push-Location "docker/development-easy"
        try {
            & docker compose --project-name openemr config --quiet
            if ($LASTEXITCODE -ne 0) { throw "compose config returned $LASTEXITCODE" }
        } finally { Pop-Location }
    }

    # Try /healthz only if the agent-service container is actually running.
    $sidecarRunning = $false
    try {
        $ps = & docker compose --project-name openemr ps --services --filter "status=running" 2>$null
        if ($ps -match 'agent-service') { $sidecarRunning = $true }
    } catch {}

    if ($sidecarRunning) {
        Test-Step "Docker: sidecar /healthz returns ok (S3, S23)" {
            $resp = Invoke-RestMethod -Uri "http://127.0.0.1:8010/healthz" -TimeoutSec 5
            if ($resp.status -ne 'ok') { throw "expected status=ok, got $($resp.status)" }
            Write-Host "  ok: /healthz -> status=$($resp.status)"
        }
    } else {
        Skip-Step "Docker: sidecar /healthz returns ok (S3, S23)" "agent-service container not running"
    }

    # DB checks - require mysql container running
    $mysqlRunning = $false
    try {
        $ps = & docker compose --project-name openemr ps --services --filter "status=running" 2>$null
        if ($ps -match '^mysql$') { $mysqlRunning = $true }
    } catch {}

    if ($mysqlRunning) {
        Test-Step "Docker: form_upload_intake_form_citation table exists (S17 / Option B)" {
            $out = & docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr -e "SHOW TABLES LIKE 'form_upload_intake_form_citation';" 2>&1
            if ($LASTEXITCODE -ne 0) { throw "mariadb query failed: $out" }
            if ($out -notmatch 'form_upload_intake_form_citation') {
                throw "table form_upload_intake_form_citation not found - if Option B has not been merged, register the form via Admin -> Forms -> Forms Administration"
            }
            Write-Host "  ok: citation table exists"
        }

        Test-Step "Docker: registry row for upload_intake_form (S14 / Option B)" {
            $out = & docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr -N -B -e "SELECT name, state FROM registry WHERE directory='upload_intake_form';" 2>&1
            if ($LASTEXITCODE -ne 0) { throw "mariadb query failed: $out" }
            if (-not $out -or $out -notmatch '\S') {
                throw "no registry row for upload_intake_form - if Option B has not been merged, register the form via Admin -> Forms -> Forms Administration"
            }
            Write-Host "  ok: registry row -> $out"
        }
    } else {
        Skip-Step "Docker: form_upload_intake_form_citation table exists (S17 / Option B)" "mysql container not running"
        Skip-Step "Docker: registry row for upload_intake_form (S14 / Option B)"          "mysql container not running"
    }
}

# -----------------------------------------------------------------------------
Show-Summary
$failed = ($results | Where-Object { $_.Status -eq 'FAIL' }).Count
exit ($(if ($failed -gt 0) { 1 } else { 0 }))
