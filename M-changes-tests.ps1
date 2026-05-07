# =============================================================================
# M-changes-tests.ps1
# =============================================================================
# Runs every cmdline-testable check that validates the Clinical Co-Pilot
# migration to Python sidecar (steps M0..M25). Companion to S-changes-tests.ps1
# (which covers Week 2 sidecar steps S0..S26).
#
# Run from the repo root:
#     .\M-changes-tests.ps1
#
# Useful flags:
#     -SkipDocker        Skip every test that needs Docker
#     -SkipPhpTests      Skip PHP isolated tests
#     -SkipPythonTests   Skip Python tests (pytest + eval suites)
#     -StopOnFailure     Stop on the first failed test (default: continue)
#     -OnlyStatic        Run only the fast static/grep checks
#
# Exit code: 0 iff every executed test passed; 1 otherwise.
#
# Manual UI steps that cannot be exercised from cmdline live in
# M-changes-UI-tests.md alongside this script. Run this script first,
# then walk that checklist in the browser.

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

function Assert-FileExists {
    param([string]$Path, [string]$Description)
    if (-not (Test-Path $Path)) {
        throw "$Description failed: file not found: $Path"
    }
    Write-Host "  ok: $Description"
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

function Assert-NoMatchInTree {
    param([string[]]$Roots, [string]$Pattern, [string]$Description)
    foreach ($root in $Roots) {
        if (-not (Test-Path $root)) { continue }
        $hits = Select-String -Path (Join-Path $root '*') -Recurse -Pattern $Pattern -ErrorAction SilentlyContinue
        if ($hits) {
            $sample = $hits | Select-Object -First 5 | ForEach-Object { "  - $($_.Path):$($_.LineNumber): $($_.Line.Trim())" }
            throw "$Description failed: forbidden pattern '$Pattern' still present under $root`n$($sample -join "`n")"
        }
    }
    Write-Host "  ok: $Description"
}

function Invoke-WithoutOpenAIKey {
    param([scriptblock]$Action)
    $saved = [Environment]::GetEnvironmentVariable('OPENAI_API_KEY', 'Process')
    try {
        [Environment]::SetEnvironmentVariable('OPENAI_API_KEY', $null, 'Process')
        & $Action
    } finally {
        [Environment]::SetEnvironmentVariable('OPENAI_API_KEY', $saved, 'Process')
    }
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

Test-Step "Static M0: ownership contract recorded in W2_ARCHITECTURE.md" {
    Assert-FileExists "W2_ARCHITECTURE.md" "architecture doc present"
    Assert-FileContains "W2_ARCHITECTURE.md" "LLM chooses tools"     "M0: LLM tool choice recorded"
    Assert-FileContains "W2_ARCHITECTURE.md" "CopilotRunContext"     "M0: CopilotRunContext mentioned"
    Assert-FileContains "W2_ARCHITECTURE.md" "runtime-allowed"       "M0: runtime-allowed mentioned"
    Assert-FileContains "W2_ARCHITECTURE.md" "authoritative"         "M0: authoritative mentioned"
    Assert-FileExists "Clinical Co-Pilot Migration to Python Sidecar.md" "migration plan present"
}

Test-Step "Static M1: 32 PHP-to-Python parity fixtures + README" {
    $base = "agent-service/tests/fixtures/copilot_parity"
    Assert-FileExists $base                                       "parity fixtures dir present"
    Assert-FileExists "$base/README.md"                           "fixtures README present"
    foreach ($intent in @(
        "basic_patient_data",
        "current_medications",
        "allergies_to_confirm",
        "recent_events",
        "changed_since_last_visit"
    )) {
        $count = (Get-ChildItem "$base/$intent/*.json" -ErrorAction SilentlyContinue).Count
        if ($count -lt 5) { throw "intent $intent : expected >=5 fixture files, found $count" }
    }
    $showSourceCount = (Get-ChildItem "$base/show_source/*.json" -ErrorAction SilentlyContinue).Count
    if ($showSourceCount -lt 7) { throw "show_source: expected >=7 fixture files, found $showSourceCount" }
    Write-Host "  ok: per-intent fixture counts"
}

Test-Step "Static M2: copilot run-contract schemas + stub endpoint + DTOs" {
    Assert-FileExists "agent-service/agent_service/schemas/copilot.py"          "Python copilot schemas present"
    Assert-FileExists "agent-service/agent_service/api/__init__.py"             "Python API package present"
    Assert-FileExists "agent-service/agent_service/api/copilot.py"              "Python /api/copilot router present"
    Assert-FileExists "src/Services/Agent/Sidecar/CopilotRunRequestDto.php"     "PHP request DTO present"
    Assert-FileExists "src/Services/Agent/Sidecar/CopilotRunResponseDto.php"    "PHP response DTO present"
    Assert-FileExists "src/Services/Agent/Sidecar/AnswerBlockDto.php"           "PHP AnswerBlock DTO present"
    Assert-FileExists "src/Services/Agent/Sidecar/CitationDto.php"              "PHP Citation DTO present"
    Assert-FileExists "src/Services/Agent/Sidecar/ToolCallRecordDto.php"        "PHP ToolCallRecord DTO present"
    Assert-FileContains "agent-service/agent_service/schemas/copilot.py" "CopilotRunRequest"  "M2 request model defined"
    Assert-FileContains "agent-service/agent_service/schemas/copilot.py" "CopilotRunResponse" "M2 response model defined"
    Assert-FileContains "agent-service/agent_service/api/copilot.py"    "/run"                "M2 /run route registered"
}

Test-Step "Static M3: signed CopilotRunContext (PHP + Python)" {
    Assert-FileExists "src/Services/Agent/Copilot/CopilotRunContext.php"                                 "PHP CopilotRunContext present"
    Assert-FileExists "tests/Tests/Isolated/Services/Agent/Copilot/CopilotRunContextTest.php"            "PHP CopilotRunContext test present"
    Assert-FileExists "agent-service/agent_service/auth/__init__.py"                                     "Python auth package present"
    Assert-FileExists "agent-service/agent_service/auth/copilot_run_context.py"                          "Python verifier present"
    Assert-FileExists "agent-service/tests/test_copilot_run_context.py"                                  "Python verifier test present"
    Assert-FileContains "src/Services/Agent/Copilot/CopilotRunContext.php"            "key_version"     "M3: key_version field"
    Assert-FileContains "agent-service/agent_service/auth/copilot_run_context.py"     "hmac"            "M3: HMAC verification"
}

Test-Step "Static M4: sidecar context verification dependency" {
    Assert-FileExists "agent-service/agent_service/auth/secret_resolver.py"   "M4 secret resolver present"
    Assert-FileExists "agent-service/agent_service/api/dependencies.py"       "M4 FastAPI dep present"
    Assert-FileExists "agent-service/tests/test_copilot_auth.py"              "M4 auth test present"
    Assert-FileContains "agent-service/agent_service/api/dependencies.py" "require_copilot_run_context" "M4 dep function exported"
}

Test-Step "Static M5: Python tool registry primitives + stubs" {
    Assert-FileExists "agent-service/agent_service/tools/__init__.py"   "tools package init"
    Assert-FileExists "agent-service/agent_service/tools/definition.py" "ToolDefinition module"
    Assert-FileExists "agent-service/agent_service/tools/registry.py"   "ToolRegistry module"
    Assert-FileExists "agent-service/agent_service/tools/stubs.py"      "stub tool definitions"
    Assert-FileExists "agent-service/tests/test_tool_registry.py"       "registry test"
    foreach ($name in @(
        "get_basic_patient_data",
        "get_current_medications",
        "get_active_allergies",
        "get_recent_events",
        "get_changes_since_last_visit",
        "get_source_detail"
    )) {
        Assert-FileContains "agent-service/agent_service/tools/stubs.py" $name "stub: $name"
    }
}

Test-Step "Static M6: policy-enforced tool executor" {
    Assert-FileExists "agent-service/agent_service/tools/executor.py"          "executor module"
    Assert-FileExists "agent-service/tests/test_tool_executor_policy.py"       "executor test"
    Assert-FileContains "agent-service/agent_service/tools/executor.py" "execute_tool"     "M6 execute_tool exported"
    Assert-FileContains "agent-service/agent_service/tools/executor.py" "ToolCallOutcome"  "M6 outcome model"
    Assert-FileContains "agent-service/agent_service/tools/executor.py" "ToolExecutionError" "M6 error type"
}

Test-Step "Static M7: intent catalog port to Python" {
    Assert-FileExists "agent-service/agent_service/intents/__init__.py" "intents package init"
    Assert-FileExists "agent-service/agent_service/intents/catalog.py"  "intent catalog"
    Assert-FileExists "agent-service/tests/test_intent_catalog.py"      "intent catalog test"
    foreach ($name in @(
        "basic_patient_data",
        "current_medications",
        "allergies_to_confirm",
        "recent_events",
        "changed_since_last_visit",
        "show_source"
    )) {
        Assert-FileContains "agent-service/agent_service/intents/catalog.py" $name "intent: $name"
    }
}

Test-Step "Static M8: evidence schemas + citation models" {
    Assert-FileExists "agent-service/agent_service/schemas/evidence.py"  "evidence schema module"
    Assert-FileExists "agent-service/tests/test_evidence_models.py"      "evidence model test"
    Assert-FileContains "agent-service/agent_service/schemas/evidence.py" "EvidenceSourceType"   "M8 source type enum"
    Assert-FileContains "agent-service/agent_service/schemas/evidence.py" "EvidenceEnvelope"     "M8 envelope model"
    Assert-FileContains "agent-service/agent_service/schemas/evidence.py" "MedicationRecord"     "M8 medication record"
    Assert-FileContains "agent-service/agent_service/schemas/evidence.py" "AllergyRecord"        "M8 allergy record"
    Assert-FileContains "agent-service/agent_service/schemas/evidence.py" "ScopeSummary"         "M8 scope summary"
}

Test-Step "Static M9: OpenEMR read repository" {
    Assert-FileExists "agent-service/agent_service/repository/__init__.py" "repository package init"
    Assert-FileExists "agent-service/agent_service/repository/openemr.py"  "OpenEMR repository"
    Assert-FileExists "agent-service/tests/test_openemr_repository.py"     "repository test"
    Assert-FileContains "agent-service/agent_service/repository/openemr.py" "OpenEmrReadRepository"     "M9 class present"
    Assert-FileContains "agent-service/agent_service/repository/openemr.py" "RepositoryConfigurationError" "M9 fail-closed error"
    Assert-FileContains "agent-service/agent_service/config.py"             "OPENEMR_DB_HOST"           "M9 config field added"
    Assert-FileContains "agent-service/pyproject.toml"                       "pymysql"                   "M9 pymysql dep added"
}

Test-Step "Static M10: read-only patient evidence tools" {
    Assert-FileExists "agent-service/agent_service/tools/patient_evidence_tools.py" "patient evidence tools module"
    Assert-FileExists "agent-service/tests/test_patient_evidence_tools.py"          "patient evidence test"
    Assert-FileContains "agent-service/agent_service/tools/patient_evidence_tools.py" "patient_evidence_tool_registry" "M10 registry factory"
}

Test-Step "Static M11: source drilldown tool" {
    Assert-FileExists "agent-service/agent_service/tools/source_drilldown.py"  "source drilldown module"
    Assert-FileExists "agent-service/tests/test_source_drilldown_tool.py"       "drilldown test"
    Assert-FileContains "agent-service/agent_service/tools/source_drilldown.py" "source_drilldown_tool_registry" "M11 registry factory"
}

Test-Step "Static M12: document/lab/intake tools registered" {
    Assert-FileExists "agent-service/agent_service/tools/document_tools.py"  "document tools module"
    Assert-FileExists "agent-service/agent_service/schemas/proposals.py"     "WriteProposal schema"
    Assert-FileExists "agent-service/tests/test_document_tools.py"           "document tools test"
    Assert-FileContains "agent-service/agent_service/tools/document_tools.py" "extract_uploaded_document"        "M12 extract tool"
    Assert-FileContains "agent-service/agent_service/tools/document_tools.py" "retrieve_guidelines"               "M12 retrieve tool"
    Assert-FileContains "agent-service/agent_service/tools/document_tools.py" "persist_lab_observation_proposal" "M12 propose tool"
    Assert-FileContains "agent-service/agent_service/tools/document_tools.py" "get_document_citation_region"      "M12 region tool"
}

Test-Step "Static M13: LLM tool-choice agent loop" {
    Assert-FileExists "agent-service/agent_service/loop/__init__.py" "loop package init"
    Assert-FileExists "agent-service/agent_service/loop/agent_loop.py" "agent loop module"
    Assert-FileExists "agent-service/agent_service/clients/tool_choice.py" "tool-choice client"
    Assert-FileExists "agent-service/tests/test_llm_tool_choice_loop.py" "loop test"
    Assert-FileContains "agent-service/agent_service/loop/agent_loop.py"     "AgentLoop"           "M13 AgentLoop class"
    Assert-FileContains "agent-service/agent_service/loop/agent_loop.py"     "max_iterations"      "M13 iteration cap"
    Assert-FileContains "agent-service/agent_service/api/copilot.py"         "AgentLoop"           "M13 wired into /run endpoint"
    Assert-FileNotContains "agent-service/agent_service/api/copilot.py"      "not_implemented"     "M13: 501 stub removed"
}

Test-Step "Static M14: answer schema + response builder" {
    Assert-FileExists "agent-service/agent_service/answer/__init__.py" "answer package init"
    Assert-FileExists "agent-service/agent_service/answer/builder.py"  "ResponseBuilder"
    Assert-FileExists "agent-service/tests/test_answer_shape.py"       "answer shape test"
    Assert-FileContains "agent-service/agent_service/answer/builder.py" "ResponseBuilder" "M14 builder class"
    Assert-FileContains "agent-service/agent_service/answer/builder.py" "build_refusal"   "M14 refusal builder"
    Assert-FileContains "agent-service/agent_service/schemas/copilot.py" "Claim"          "M14 Claim model"
}

Test-Step "Static M15: verifier and refusal rules" {
    Assert-FileExists "agent-service/agent_service/verifier/__init__.py"           "verifier package init"
    Assert-FileExists "agent-service/agent_service/verifier/answer_verifier.py"     "verifier module"
    Assert-FileExists "agent-service/tests/test_answer_verifier.py"                  "verifier test"
    Assert-FileContains "agent-service/agent_service/verifier/answer_verifier.py" "AnswerVerifier"        "M15 verifier class"
    Assert-FileContains "agent-service/agent_service/verifier/answer_verifier.py" "VerificationResult"    "M15 result model"
    Assert-FileContains "agent-service/agent_service/verifier/answer_verifier.py" "phi_in_output"          "M15 PHI guard"
}

Test-Step "Static M16: PHI-safe per-tool-call observability spans" {
    Assert-FileExists "agent-service/agent_service/observability/events.py"        "events module"
    Assert-FileExists "agent-service/agent_service/observability/recorder.py"      "event recorder"
    Assert-FileExists "agent-service/agent_service/observability/_phi_scanner.py"  "shared PHI scanner"
    Assert-FileExists "agent-service/tests/test_observability_redaction.py"        "redaction test"
    Assert-FileContains "agent-service/agent_service/observability/events.py"   "RunEvent"           "M16 RunEvent model"
    Assert-FileContains "agent-service/agent_service/loop/agent_loop.py"        "event_recorder"     "M16 wired into loop"
}

Test-Step "Static M17: PHP thin proxy to sidecar copilot" {
    Assert-FileExists "src/Services/Agent/Sidecar/CopilotSidecarClient.php"     "M17 sidecar client"
    Assert-FileExists "src/Services/Agent/Sidecar/CopilotSidecarException.php"  "M17 exception type"
    Assert-FileExists "tests/Tests/Isolated/Services/Agent/Sidecar/CopilotSidecarClientTest.php" "M17 client test"
    Assert-FileContains "src/Services/Agent/Sidecar/CopilotSidecarClient.php" "/api/copilot/run" "M17 endpoint URL"
    Assert-FileContains "src/RestControllers/Agent/AgentIntentRestController.php" "CopilotSidecarClient" "M17 controller wiring"
}

Test-Step "Static M18: shadow mode comparator + record" {
    Assert-FileExists "src/Services/Agent/Sidecar/ShadowComparator.php"        "M18 comparator class"
    Assert-FileExists "src/Services/Agent/Sidecar/ShadowComparisonRecord.php"  "M18 record value object"
    Assert-FileExists "tests/Tests/Isolated/Services/Agent/Sidecar/ShadowComparatorTest.php" "M18 comparator test"
    Assert-FileExists "agent-service/tests/test_shadow_contract.py" "M18 Python contract test"
}

Test-Step "Static M19: per-intent cutover flags" {
    Assert-FileExists "src/Services/Agent/Sidecar/IntentMode.php"              "M19 mode enum"
    Assert-FileExists "src/Services/Agent/Sidecar/CopilotSidecarRouting.php"   "M19 routing class"
    Assert-FileExists "tests/Tests/Isolated/Services/Agent/Sidecar/CopilotSidecarRoutingTest.php" "M19 routing test"
    Assert-FileContains "src/Services/Agent/Sidecar/IntentMode.php"        "case Php"        "M19 IntentMode::Php"
    Assert-FileContains "src/Services/Agent/Sidecar/IntentMode.php"        "case Shadow"     "M19 IntentMode::Shadow"
    Assert-FileContains "src/Services/Agent/Sidecar/IntentMode.php"        "case Sidecar"    "M19 IntentMode::Sidecar"
    Assert-FileContains "src/Services/Agent/Sidecar/CopilotSidecarRouting.php" "emergencyDisable" "M19 emergency disable"
}

Test-Step "Static M20: per-intent parity tests" {
    Assert-FileExists "agent-service/tests/test_intent_parity.py"  "M20 parity test"
    Assert-FileExists ".env.copilot-cutover.example"               "M20 cutover env example"
    foreach ($intent in @(
        "basic_patient_data","current_medications","allergies_to_confirm",
        "recent_events","changed_since_last_visit","show_source"
    )) {
        Assert-FileContains "agent-service/tests/test_intent_parity.py" $intent "M20 covers intent $intent"
    }
}

Test-Step "Static M21: two-phase write proposals" {
    Assert-FileExists "agent-service/agent_service/proposals/__init__.py" "M21 proposals init"
    Assert-FileExists "agent-service/agent_service/proposals/validator.py" "M21 validator"
    Assert-FileExists "agent-service/tests/test_write_action_proposals.py" "M21 Python test"
    Assert-FileExists "src/Services/Agent/Copilot/CopilotRunContextVerifier.php" "M21 PHP context verifier"
    Assert-FileExists "src/Services/Agent/Proposals/CommittedProposalRecord.php" "M21 PHP record"
    Assert-FileExists "src/Services/Agent/Proposals/CommittedProposalRepository.php" "M21 PHP repo"
    Assert-FileExists "src/RestControllers/Agent/AgentProposalCommitController.php" "M21 PHP commit controller"
    Assert-FileExists "tests/Tests/Isolated/RestControllers/Agent/AgentProposalCommitControllerTest.php" "M21 PHP controller test"
}

Test-Step "Static M22: eval rubrics for LLM-chosen tool behavior" {
    Assert-FileExists "agent-service/agent_service/eval/rubrics/__init__.py" "M22 rubrics init"
    Assert-FileExists "agent-service/agent_service/eval/rubrics/copilot_tools.py" "M22 rubric scorer"
    Assert-FileExists "agent-service/agent_service/eval/copilot_tools_suite.py" "M22 suite driver"
    Assert-FileExists "agent-service/agent_service/eval/recorder_capture.py" "M22 capture recorder"
    Assert-FileExists "agent-service/tests/test_eval_copilot_tools.py" "M22 rubric test"
    foreach ($rubric in @(
        "tool_allowed","tool_args_scoped","required_evidence_checked",
        "citation_present","factually_consistent","safe_refusal",
        "no_phi_in_logs","verification_passed"
    )) {
        Assert-FileContains "agent-service/agent_service/eval/rubrics/copilot_tools.py" $rubric "M22 rubric: $rubric"
    }
    $regBase = "agent-service/tests/fixtures/copilot_tools_regression"
    if (-not (Test-Path $regBase)) { throw "M22 regression fixtures dir missing: $regBase" }
    $regFiles = (Get-ChildItem "$regBase/*.json" -ErrorAction SilentlyContinue).Count
    if ($regFiles -lt 3) { throw "M22 expected 3+ regression fixtures, found $regFiles" }
    Write-Host "  ok: regression fixtures present ($regFiles files)"
}

Test-Step "Static M23: CI workflow for migration gate" {
    Assert-FileExists ".github/workflows/copilot-migration.yml" "M23 workflow"
    Assert-FileExists "agent-service/tests/test_migration_ci_regressions.py" "M23 regression-injection test"
    Assert-FileContains ".github/workflows/copilot-migration.yml" "copilot-tools" "M23 runs copilot-tools eval"
    Assert-FileContains ".github/workflows/copilot-migration.yml" "phpunit"       "M23 runs PHP isolated"
}

Test-Step "Static M24: migrated PHP internals removed" {
    foreach ($removed in @(
        "src/Services/Agent/AgentLlmOrchestrator.php",
        "src/Services/Agent/AgentEvidenceResponseBuilder.php",
        "src/Services/Agent/Verification/AgentAnswerVerifier.php",
        "src/Services/Agent/Llm/OpenAiResponsesAgentLlmProvider.php",
        "src/Services/Agent/Evidence/AgentEvidenceToolset.php",
        "src/Services/Agent/Evidence/SqlEvidenceRecordRepository.php"
    )) {
        if (Test-Path $removed) {
            throw "M24 should have removed file: $removed (still present)"
        }
    }
    Write-Host "  ok: 6 migrated PHP classes confirmed removed"
    # Verification grep from migration doc M24 spec
    Assert-NoMatchInTree -Roots @("src") -Pattern "AgentLlmOrchestrator|AgentAnswerVerifier|OpenAiResponsesAgentLlmProvider|AgentEvidenceResponseBuilder" -Description "M24: zero references in src/ to removed classes"
}

Test-Step "Static M25: final acceptance report present" {
    Assert-FileExists "docs/copilot-migration-acceptance.md" "M25 acceptance report"
}

Test-Step "Static M-overall: all 26 migration rows marked Done" {
    $doc = Get-Content "Clinical Co-Pilot Migration to Python Sidecar.md" -Raw
    $notStartedCount = ([regex]::Matches($doc, '\bStatus:\*\* Not started\b')).Count
    if ($notStartedCount -gt 0) { throw "$notStartedCount step(s) still 'Not started' in migration doc" }
    $checkboxCount = ([regex]::Matches($doc, '- \[x\] Done')).Count
    if ($checkboxCount -lt 26) { throw "expected >=26 '[x] Done' rows, found $checkboxCount" }
    Write-Host "  ok: $checkboxCount Done rows; 0 Not started"
}

if ($OnlyStatic) {
    Show-Summary
    exit ($(if (($results | Where-Object { $_.Status -eq 'FAIL' }).Count -gt 0) { 1 } else { 0 }))
}

# -----------------------------------------------------------------------------
# Section 2 - Python (pytest + eval suites + observability)
# -----------------------------------------------------------------------------

if ($SkipPythonTests) {
    Skip-Step "Python: pytest full suite"                 "-SkipPythonTests"
    Skip-Step "Python: copilot-tools eval suite"           "-SkipPythonTests"
    Skip-Step "Python: copilot run-context auth tests"     "-SkipPythonTests"
    Skip-Step "Python: intent parity tests"                "-SkipPythonTests"
    Skip-Step "Python: LLM tool-choice loop tests"         "-SkipPythonTests"
    Skip-Step "Python: verifier tests"                     "-SkipPythonTests"
    Skip-Step "Python: observability redaction tests"      "-SkipPythonTests"
    Skip-Step "Python: migration CI regression tests"      "-SkipPythonTests"
} elseif (-not (Have-Command 'py')) {
    Skip-Step "Python: pytest full suite"             "py interpreter not on PATH"
    Skip-Step "Python: copilot-tools eval suite"      "py interpreter not on PATH"
} else {

    Test-Step "Python: pytest full agent-service suite" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: copilot run-context auth tests (M3, M4)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_copilot_run_context.py tests/test_copilot_auth.py tests/test_copilot_run_contract.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: intent parity tests across all 6 intents (M20)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_intent_parity.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: LLM tool-choice loop + tool tests (M5, M6, M10-M13)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_tool_registry.py tests/test_tool_executor_policy.py tests/test_patient_evidence_tools.py tests/test_source_drilldown_tool.py tests/test_document_tools.py tests/test_llm_tool_choice_loop.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: verifier + answer shape + write-action tests (M14, M15, M21)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_answer_shape.py tests/test_answer_verifier.py tests/test_write_action_proposals.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: observability redaction tests (M16)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_observability_redaction.py tests/test_observability_phi_scanner.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: migration CI regression-injection tests (M23)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m pytest tests/test_migration_ci_regressions.py -q
            } finally { Pop-Location }
        }
    }

    Test-Step "Python: copilot-tools eval suite all rubrics (M22)" {
        Invoke-WithoutOpenAIKey {
            Push-Location "agent-service"
            try {
                & py -m agent_service.eval --suite copilot-tools
            } finally { Pop-Location }
        }
    }
}

# -----------------------------------------------------------------------------
# Section 3 - PHP isolated tests
# -----------------------------------------------------------------------------

if ($SkipPhpTests) {
    Skip-Step "PHP: copilot/sidecar isolated tests" "-SkipPhpTests"
} elseif (-not (Test-Path "vendor/bin/phpunit")) {
    Skip-Step "PHP: copilot/sidecar isolated tests" "vendor/bin/phpunit not present (run composer install)"
} elseif (-not (Have-Command 'php')) {
    Skip-Step "PHP: copilot/sidecar isolated tests" "php interpreter not on PATH"
} else {
    Test-Step "PHP: CopilotRunContext (M3)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "CopilotRunContext"
    }

    Test-Step "PHP: CopilotSidecarClient + Exception (M17)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "CopilotSidecarClient"
    }

    Test-Step "PHP: ShadowComparator (M18)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "ShadowComparator"
    }

    Test-Step "PHP: CopilotSidecarRouting + IntentMode (M19)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "CopilotSidecarRouting"
    }

    Test-Step "PHP: AgentProposalCommitController (M21)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "AgentProposalCommitController"
    }

    Test-Step "PHP: IntentCutoverPlan (M20)" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "IntentCutoverPlan"
    }

    Test-Step "PHP: full Copilot+Sidecar+Agent isolated suites" {
        & php vendor/bin/phpunit -c phpunit-isolated.xml --filter "(Copilot|Sidecar|Agent)"
    }
}

# -----------------------------------------------------------------------------
# Section 4 - Docker (compose + sidecar /api/copilot/run live smoke)
# -----------------------------------------------------------------------------

if ($SkipDocker) {
    Skip-Step "Docker: compose validates (M migration agent-service mount)"  "-SkipDocker"
    Skip-Step "Docker: agent-service /healthz"                               "-SkipDocker"
    Skip-Step "Docker: /api/copilot/run rejects unsigned (M4)"               "-SkipDocker"
    Skip-Step "Docker: /api/copilot/run rejects expired token (M4)"          "-SkipDocker"
} elseif (-not (Have-Command 'docker')) {
    Skip-Step "Docker: compose validates"                  "docker not on PATH"
    Skip-Step "Docker: agent-service /healthz"             "docker not on PATH"
    Skip-Step "Docker: /api/copilot/run unsigned reject"   "docker not on PATH"
    Skip-Step "Docker: /api/copilot/run expired reject"    "docker not on PATH"
} else {
    Test-Step "Docker: compose config validates" {
        Push-Location "docker/development-easy"
        try {
            & docker compose --project-name openemr config --quiet
            if ($LASTEXITCODE -ne 0) { throw "compose config returned $LASTEXITCODE" }
        } finally { Pop-Location }
    }

    $sidecarRunning = $false
    try {
        $ps = & docker compose --project-name openemr ps --services --filter "status=running" 2>$null
        if ($ps -match 'agent-service') { $sidecarRunning = $true }
    } catch {}

    if ($sidecarRunning) {
        Test-Step "Docker: agent-service /healthz" {
            $resp = Invoke-RestMethod -Uri "http://127.0.0.1:8010/healthz" -TimeoutSec 5
            if ($resp.status -ne 'ok') { throw "expected status=ok, got $($resp.status)" }
            Write-Host "  ok: /healthz -> status=$($resp.status)"
        }

        Test-Step "Docker: /api/copilot/run rejects unsigned request (M4)" {
            $body = @{
                run_context = "not.a.real.token"
                intent_id   = "basic_patient_data"
                request_id  = [guid]::NewGuid().ToString()
            } | ConvertTo-Json -Compress
            try {
                $resp = Invoke-WebRequest -Uri "http://127.0.0.1:8010/api/copilot/run" -Method POST -ContentType "application/json" -Body $body -TimeoutSec 10 -ErrorAction Stop
                throw "expected 401, got HTTP $($resp.StatusCode)"
            } catch {
                $ex = $_.Exception
                $statusCode = $null
                if ($ex.Response -and $ex.Response.StatusCode) {
                    $statusCode = [int]$ex.Response.StatusCode
                }
                if ($statusCode -ne 401) {
                    throw "expected HTTP 401 invalid_run_context, got $statusCode ($($ex.Message))"
                }
                Write-Host "  ok: rejected unsigned request with HTTP 401"
            }
        }

        Test-Step "Docker: /api/copilot/run rejects empty body (M2)" {
            try {
                $resp = Invoke-WebRequest -Uri "http://127.0.0.1:8010/api/copilot/run" -Method POST -ContentType "application/json" -Body "{}" -TimeoutSec 10 -ErrorAction Stop
                throw "expected non-2xx, got HTTP $($resp.StatusCode)"
            } catch {
                $ex = $_.Exception
                $statusCode = $null
                if ($ex.Response -and $ex.Response.StatusCode) {
                    $statusCode = [int]$ex.Response.StatusCode
                }
                # Either 422 (validation) or 401 (auth) is acceptable here -- both
                # mean the endpoint refused the bogus payload before doing work.
                if ($statusCode -ne 422 -and $statusCode -ne 401) {
                    throw "expected HTTP 422 or 401, got $statusCode ($($ex.Message))"
                }
                Write-Host "  ok: rejected empty body with HTTP $statusCode"
            }
        }
    } else {
        Skip-Step "Docker: agent-service /healthz"                               "agent-service container not running"
        Skip-Step "Docker: /api/copilot/run rejects unsigned request (M4)"       "agent-service container not running"
        Skip-Step "Docker: /api/copilot/run rejects empty body (M2)"             "agent-service container not running"
    }
}

# -----------------------------------------------------------------------------
Show-Summary
$failed = ($results | Where-Object { $_.Status -eq 'FAIL' }).Count
exit ($(if ($failed -gt 0) { 1 } else { 0 }))
