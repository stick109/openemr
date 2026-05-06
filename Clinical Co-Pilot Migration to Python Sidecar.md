# Clinical Co-Pilot Migration to Python Sidecar

This document tracks the later migration where the existing PHP Clinical
Co-Pilot logic moves into the Python sidecar. It is separate from
[sidecar-detailed-steps.md](sidecar-detailed-steps.md), which tracks the
Week 2 document-ingestion sidecar itself.

The target architecture is:

- The LLM chooses tools inside Python.
- PHP remains the OpenEMR UI, session, CSRF, current-patient, and route
  boundary.
- PHP does not perform clinical answer generation, evidence shaping, verifier
  logic, prompt assembly, or model-provider calls after cutover.
- The sidecar never accepts patient IDs, encounter IDs, source IDs, SQL, file
  paths, or write targets from the model as authority. Runtime policy injects
  scoped values from a signed run context.

## Done Marker

Each step has an in-step checkbox:

```markdown
- [ ] Done
```

To mark a step complete, change it to:

```markdown
- [x] Done
```

Also update the matching `Status` value in the summary table. This gives both
a local marker inside the step and a compact dashboard at the top.

## Status Legend

Use one of: `Not started`, `In progress`, `Blocked`, `Done`, `Skipped`.

## Step Summary

| ID | Step | Status | Depends on | Can run in parallel with |
| --- | --- | --- | --- | --- |
| M0 | Confirm migration target and PHP boundary | Done | Sidecar S3-S4 | M1 |
| M1 | Inventory current PHP behavior and build parity fixtures | Done | none | M0, M2 |
| M2 | Define sidecar copilot run contract | Done | M0 | M1, M3 |
| M3 | Define signed `CopilotRunContext` | Done | M0 | M2 |
| M4 | Add sidecar context verification | Done | M2, M3 | M5, M6 |
| M5 | Add Python tool registry primitives | Done | M2 | M4, M6 |
| M6 | Add policy-enforced tool executor | Done | M3, M5 | M4 |
| M7 | Port intent catalog and capability caps to Python | Done | M5, M6 | M8, M9 |
| M8 | Port evidence schemas and citation models | Done | M5 | M7, M9 |
| M9 | Add Python OpenEMR read repository | Not started | M3, M8 | M7, M10 |
| M10 | Implement read-only patient evidence tools | Not started | M7, M8, M9 | M11 |
| M11 | Implement source drilldown tool | Not started | M8, M9 | M10 |
| M12 | Implement document/lab/intake tools in same registry | Not started | Sidecar S9-S11, M6 | M10-M11 |
| M13 | Implement LLM tool-choice agent loop | Not started | M6, M10, M11 | M14, M15 |
| M14 | Port answer schema and response shaping | Not started | M8 | M13, M15 |
| M15 | Port verifier/refusal rules to Python | Not started | M8, M14 | M13 |
| M16 | Add PHI-safe sidecar observability for tool calls | Not started | M6, M13 | M17 |
| M17 | Add PHP thin proxy to sidecar copilot endpoint | Not started | M2-M4 | M13-M16 |
| M18 | Add shadow mode comparing PHP and Python outputs | Not started | M13-M17 | M19 |
| M19 | Add per-intent cutover feature flags | Not started | M18 | M20 |
| M20 | Cut over read-only intents one by one | Not started | M18, M19 | M21 |
| M21 | Move write-like actions to two-phase sidecar proposals | Not started | M13, M15, Sidecar S16-S17 | M20 |
| M22 | Expand evals for LLM-chosen tool behavior | Not started | M13-M16 | M23 |
| M23 | Gate migration in CI | Not started | M18, M22 | M24 |
| M24 | Remove migrated PHP agent internals | Not started | M20, M21, M23 | M25 prep |
| M25 | Final migration acceptance run | Not started | M23, M24 | none |

## Parallelization Guide

| Group | Can start after | Steps | Notes |
| --- | --- | --- | --- |
| Contract and safety boundary | Sidecar S3-S4 | M0-M6 | Do before moving clinical behavior. |
| PHP parity capture | none | M1 | Can run immediately and should finish before cutover. |
| Python agent/tool internals | M5-M6 | M7-M16 | Can split by intent/tool family once executor policy exists. |
| PHP proxy/cutover | M2-M4 | M17-M21 | Can be built against a stub sidecar, then switched to real agent loop. |
| Eval/CI/cleanup | M13-M18 | M22-M25 | Do not remove PHP internals until CI proves parity and regression coverage. |

---

## M0 - Confirm Migration Target and PHP Boundary

- [x] Done

**Status:** Done  
**Depends on:** Sidecar S3-S4  
**Can run in parallel with:** M1

Implementation:

- Write down the post-migration ownership contract:
  Python owns agent/tool selection, retrieval orchestration, prompt/schema,
  answer generation, verifier/refusal, evals, and model providers.
- PHP owns UI rendering, authenticated OpenEMR route entry, CSRF/session
  checks, current patient/encounter resolution, and signed run-context minting.
- Define that the LLM can choose tools, but only from the runtime-allowed tool
  registry.
- Define that the model never supplies authoritative `patient_id`,
  `encounter_id`, `document_id`, SQL, file path, or write destination.

Verification:

```powershell
rg -n "LLM chooses tools|CopilotRunContext|runtime-allowed|authoritative" "Clinical Co-Pilot Migration to Python Sidecar.md" W2_ARCHITECTURE.md
```

Pass criteria:

- The architecture text clearly states that LLM tool choice is allowed while
  patient scope and authority come from runtime context.

## M1 - Inventory Current PHP Behavior and Build Parity Fixtures

- [x] Done

**Status:** Done  
**Depends on:** none  
**Can run in parallel with:** M0, M2

Implementation:

- Inventory the current PHP copilot files:
  [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php),
  [AgentIntentRestController.php](src\RestControllers\Agent\AgentIntentRestController.php),
  [AgentEvidenceToolset.php](src\Services\Agent\Evidence\AgentEvidenceToolset.php),
  [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php),
  [AgentEvidenceResponseBuilder.php](src\Services\Agent\AgentEvidenceResponseBuilder.php),
  [AgentLlmOrchestrator.php](src\Services\Agent\AgentLlmOrchestrator.php), and
  [AgentAnswerVerifier.php](src\Services\Agent\Verification\AgentAnswerVerifier.php).
- Capture expected behavior for each current intent:
  `basic_patient_data`, `current_medications`, `allergies_to_confirm`,
  `recent_events`, `changed_since_last_visit`, and `show_source`.
- Convert representative PHP eval fixtures into sidecar parity fixtures.
- Include missing-data, conflicting-data, unauthorized-source, and prompt
  injection cases.

Verification:

```powershell
rg -n "basic_patient_data|current_medications|allergies_to_confirm|recent_events|changed_since_last_visit|show_source" src\Services\Agent tests\Tests\Fixtures\Agent
```

Pass criteria:

- There is a fixture list for every current PHP intent.
- Each fixture states expected tool calls, expected citations, and expected
  final answer/refusal behavior.

## M2 - Define Sidecar Copilot Run Contract

- [x] Done

**Status:** Done  
**Depends on:** M0  
**Can run in parallel with:** M1, M3

Implementation:

- Add or update the sidecar contract for existing chart-copilot requests.
- Use a separate endpoint from document ingestion if that keeps contracts
  clearer, for example `POST /api/copilot/run`.
- Request fields:
  `run_context`, `intent_id` or `user_goal`, `request_id`, and optional
  `conversation_state`.
- Response fields:
  `answer_blocks`, `missing_or_uncertain`, `citations`, `tool_sequence`,
  `verification_status`, `cost_usd`, `latency_ms_per_step`, and `trace_id`.
- Decide whether existing closed-intent buttons remain as UI shortcuts while
  the sidecar agent still chooses tools internally.

Verification:

```powershell
rg -n "POST /api/copilot/run|answer_blocks|missing_or_uncertain|verification_status|tool_sequence" agent-service "Clinical Co-Pilot Migration to Python Sidecar.md"
```

Pass criteria:

- The contract supports LLM-selected tools but still gives the UI a stable
  response shape compatible with current rendering.

## M3 - Define Signed `CopilotRunContext`

- [x] Done

**Status:** Done  
**Depends on:** M0  
**Can run in parallel with:** M2

Implementation:

- Define a signed, short-lived context minted by PHP and verified by Python.
- Include:
  `user_id`, `username`, `patient_id`, `encounter_id`, `allowed_tools`,
  `allowed_source_types`, `max_rows`, `lookback_days`, `expires_at`,
  `request_id`, and `trace_id`.
- Use HMAC or an equivalent server-side signature with `AGENT_SHARED_SECRET`.
- Include a key/version field so the context can be rotated later.
- Do not include raw patient names, DOB, addresses, phone numbers, or free-text
  chart content in the token.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Unit --filter CopilotRunContext
cd agent-service
python -m pytest tests\test_copilot_run_context.py
```

Pass criteria:

- PHP can mint a context.
- Python accepts valid context.
- Python rejects expired, tampered, missing-signature, and wrong-secret
  contexts.

## M4 - Add Sidecar Context Verification

- [x] Done

**Status:** Done  
**Depends on:** M2, M3  
**Can run in parallel with:** M5, M6

Implementation:

- Add sidecar middleware or endpoint-level dependency that verifies
  `CopilotRunContext`.
- Store verified context in a request-local object used by all tools.
- Ensure model-visible prompts and tool args never contain the signed token.
- Return fail-closed errors for missing/invalid context.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_copilot_auth.py
```

Manual smoke test:

```powershell
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Pass criteria:

- `/healthz` remains public.
- `/api/copilot/run` rejects unsigned requests.
- Valid signed requests reach the stub handler.

## M5 - Add Python Tool Registry Primitives

- [x] Done

**Status:** Done  
**Depends on:** M2  
**Can run in parallel with:** M4, M6

Implementation:

- Add `ToolDefinition` with:
  `name`, `description`, `input_schema`, `required_capability`,
  `source_types`, `read_only`, `max_rows`, and `executor`.
- Add a registry that exposes model-facing tool schemas.
- Keep internal-only fields out of the model-facing schema.
- Add initial empty/stub tools for all current PHP intent data classes.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_tool_registry.py
```

Pass criteria:

- Registry lists known tools.
- Tool schemas are valid JSON Schema.
- No tool schema exposes `patient_id`, `encounter_id`, SQL, file paths, or raw
  secrets as model-provided inputs.

## M6 - Add Policy-Enforced Tool Executor

- [x] Done

**Status:** Done  
**Depends on:** M3, M5  
**Can run in parallel with:** M4

Implementation:

- Add a single `execute_tool(context, tool_name, model_args)` path.
- Validate:
  tool exists, tool is allowed by context, context is unexpired, args match
  schema, row/window caps are enforced, and patient/encounter are injected
  from context.
- Log safe tool-call metadata:
  `trace_id`, `tool_name`, sanitized arg keys, latency, result count, error
  class.
- Return structured tool results with citations.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_tool_executor_policy.py
```

Pass criteria:

- A model attempt to pass `patient_id` is rejected or ignored.
- A disallowed tool is rejected.
- Row caps and lookback caps are applied.
- Tool results include citation/source IDs where applicable.

## M7 - Port Intent Catalog and Capability Caps to Python

- [x] Done

**Status:** Done  
**Depends on:** M5, M6  
**Can run in parallel with:** M8, M9

Implementation:

- Port current intent IDs, labels, prompt text, and caps from
  [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php).
- Decide which tools each intent may use.
- Treat buttons as initial goals, not hard-coded PHP tool plans.
- Keep `show_source` as a constrained source-detail workflow.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_intent_catalog.py
```

Pass criteria:

- Every current PHP intent exists in Python.
- Each intent maps to an allowed tool set and caps.
- Unknown intent IDs are rejected.

## M8 - Port Evidence Schemas and Citation Models

- [x] Done

**Status:** Done  
**Depends on:** M5  
**Can run in parallel with:** M7, M9

Implementation:

- Define Python models for current evidence source types:
  patient demographics, medications, allergies, problems/results/events,
  encounter events, and source drilldown.
- Align citation IDs with current UI expectations where possible.
- Add normalized result envelope:
  `records`, `sources`, `tool_name`, `warnings`, and `checked_scope`.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_evidence_models.py
```

Pass criteria:

- Current PHP fixture evidence packets validate in Python.
- Citation IDs round-trip without changing UI-visible source links.

## M9 - Add Python OpenEMR Read Repository

- [ ] Done

**Status:** Not started  
**Depends on:** M3, M8  
**Can run in parallel with:** M7, M10

Implementation:

- Add a Python repository layer for read-only OpenEMR access.
- Prefer a read-only DB credential for the sidecar.
- Implement explicit SQL per data class rather than arbitrary query execution.
- Every method requires verified context and injects patient ID from that
  context.
- Keep SQL result limits and date windows at the query boundary.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_openemr_repository.py
```

Integration smoke test with local DB:

```powershell
docker compose --project-name openemr ps
```

Pass criteria:

- Repository tests prove patient ID cannot come from model args.
- Queries are bounded and read-only.
- Missing DB config fails closed.

## M10 - Implement Read-Only Patient Evidence Tools

- [ ] Done

**Status:** Not started  
**Depends on:** M7, M8, M9  
**Can run in parallel with:** M11

Implementation:

- Implement tools:
  `get_basic_patient_data`, `get_current_medications`,
  `get_active_allergies`, `get_recent_events`, and
  `get_changes_since_last_visit`.
- Each tool calls the Python repository through the policy executor.
- Each tool returns structured evidence plus citations.
- Preserve current safe missingness phrasing inputs where applicable.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_patient_evidence_tools.py
```

Pass criteria:

- Each tool returns the expected fixture evidence.
- Tool output includes source/citation IDs.
- Tools refuse if context lacks the required capability.

## M11 - Implement Source Drilldown Tool

- [ ] Done

**Status:** Not started  
**Depends on:** M8, M9  
**Can run in parallel with:** M10

Implementation:

- Implement `get_source_detail`.
- Accept only a citation/source ID from the model.
- Validate that the source belongs to the current patient and allowed source
  types.
- Return only bounded source detail needed for UI display.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_source_drilldown_tool.py
```

Pass criteria:

- Valid same-patient source IDs return bounded detail.
- Wrong-patient, unknown, or disallowed source IDs are rejected.

## M12 - Implement Document/Lab/Intake Tools in Same Registry

- [ ] Done

**Status:** Not started  
**Depends on:** Sidecar S9-S11, M6  
**Can run in parallel with:** M10-M11

Implementation:

- Register document-oriented tools already built for Week 2:
  `extract_uploaded_document`, `retrieve_guidelines`,
  `persist_lab_observation_proposal`, and `get_document_citation_region`.
- Keep write-like tools as proposals unless explicitly approved by the PHP
  boundary or a validated commit endpoint.
- Ensure all document tools use scoped file/document IDs, not model-supplied
  filesystem paths.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_document_tools.py
```

Pass criteria:

- Document tools are visible to the LLM only when allowed by context.
- A model-supplied arbitrary file path is rejected.
- Extraction results preserve source citations and bboxes.

## M13 - Implement LLM Tool-Choice Agent Loop

- [ ] Done

**Status:** Not started  
**Depends on:** M6, M10, M11  
**Can run in parallel with:** M14, M15

Implementation:

- Implement an inspectable agent loop in Python using LangGraph, OpenAI
  tool-calling, or the selected equivalent.
- Expose only runtime-allowed tool schemas to the model.
- Let the LLM choose tool calls and order.
- Route every tool call through `execute_tool`.
- Cap maximum tool calls, max wall time, and max model iterations.
- Record `tool_sequence` with model-requested tool names and executor
  outcomes.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_llm_tool_choice_loop.py
```

Pass criteria:

- A canned fake model can choose multiple tools.
- Disallowed tool choices are refused by executor.
- Loop stops at max iterations.
- `tool_sequence` is deterministic in fake-model tests.

## M14 - Port Answer Schema and Response Shaping

- [ ] Done

**Status:** Not started  
**Depends on:** M8  
**Can run in parallel with:** M13, M15

Implementation:

- Port answer block schema from PHP into Python.
- Keep UI-compatible fields:
  `answer_blocks`, `claims`, `citation_ids`, `certainty`,
  `missing_or_uncertain`, and `citations`.
- Keep deterministic formatting for fallback/refusal cases.
- Escape/normalize UI-bound text before returning to PHP.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_answer_shape.py
```

Pass criteria:

- Python output validates against the same shape expected by
  [agent_panel.js](interface\patient_file\summary\agent_panel.js).
- Existing fixture answers can be rendered by the current UI without JS
  changes beyond endpoint wiring.

## M15 - Port Verifier/Refusal Rules to Python

- [ ] Done

**Status:** Not started  
**Depends on:** M8, M14  
**Can run in parallel with:** M13

Implementation:

- Port verifier behavior from
  [AgentAnswerVerifier.php](src\Services\Agent\Verification\AgentAnswerVerifier.php).
- Verify every factual claim has known citations.
- Reject fabricated citation IDs.
- Reject unsupported claims and out-of-scope advice.
- Reject hidden tool failures.
- Add PHI-output guard for phone, email, SSN, and address leakage where those
  are not needed.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_answer_verifier.py
```

Pass criteria:

- Existing PHP verifier fixtures pass in Python.
- Fabricated citations fail.
- Unsafe action language fails.
- Missing-data answers use safe missingness wording.

## M16 - Add PHI-Safe Sidecar Observability for Tool Calls

- [ ] Done

**Status:** Not started  
**Depends on:** M6, M13  
**Can run in parallel with:** M17

Implementation:

- Emit spans/events for:
  run received, model turn started/finished, tool started/finished, verifier
  finished, response returned.
- Include request/trace IDs, tool names, result counts, latency, token usage,
  cost, refusal reason, and verifier outcome.
- Do not log raw evidence, raw prompt text, full names, DOB, address, MRN,
  phone, email, document text, screenshots, or PDF images.
- Add redactor tests that inject synthetic PHI into every event field.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_observability_redaction.py
```

Pass criteria:

- Synthetic PHI is redacted or the event is dropped.
- Tool sequence and cost/latency are still observable.

## M17 - Add PHP Thin Proxy to Sidecar Copilot Endpoint

- [ ] Done

**Status:** Not started  
**Depends on:** M2-M4  
**Can run in parallel with:** M13-M16

Implementation:

- Keep [AgentIntentRestController.php](src\RestControllers\Agent\AgentIntentRestController.php)
  as the UI-facing authenticated route.
- Replace PHP answer-building path with:
  validate request, mint `CopilotRunContext`, call sidecar, return sidecar
  response.
- Keep CSRF/session/current-patient checks at the route boundary.
- Ensure sidecar failures return a safe user-visible failure, not partial PHP
  fallback that hides the sidecar outage.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Unit --filter AgentIntentRestController
```

Manual stub-sidecar smoke test:

```powershell
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Pass criteria:

- PHP route can call a stub sidecar response.
- Browser response shape is unchanged.
- Invalid session/context never reaches sidecar.

## M18 - Add Shadow Mode Comparing PHP and Python Outputs

- [ ] Done

**Status:** Not started  
**Depends on:** M13-M17  
**Can run in parallel with:** M19

Implementation:

- Add a feature flag for shadow mode.
- For selected intents, PHP returns the current PHP answer but also calls the
  sidecar and logs a sanitized comparison.
- Compare:
  verification status, cited source IDs, missingness behavior, source counts,
  and answer block headings.
- Never log raw PHI during comparison.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Unit --filter AgentShadowMode
cd agent-service
python -m pytest tests\test_shadow_contract.py
```

Pass criteria:

- Shadow mode produces comparison records without changing user-visible output.
- Comparison logs contain no raw PHI.

## M19 - Add Per-Intent Cutover Feature Flags

- [ ] Done

**Status:** Not started  
**Depends on:** M18  
**Can run in parallel with:** M20

Implementation:

- Add configuration for per-intent sidecar cutover.
- Start all intents in PHP mode.
- Allow enabling sidecar mode intent-by-intent.
- Add emergency disable switch that returns all intents to PHP mode until PHP
  internals are removed.

Verification:

```powershell
rg -n "sidecar.*intent|copilot.*feature|shadow" .env.example src tests
vendor\bin\phpunit tests\Tests\Unit --filter AgentSidecarCutover
```

Pass criteria:

- Each intent can be independently routed to PHP or sidecar.
- Emergency disable works.

## M20 - Cut Over Read-Only Intents One by One

- [ ] Done

**Status:** Not started  
**Depends on:** M18, M19  
**Can run in parallel with:** M21

Implementation:

- Cut over in this order:
  `basic_patient_data`, `current_medications`, `allergies_to_confirm`,
  `recent_events`, `changed_since_last_visit`, `show_source`.
- For each intent:
  run parity fixtures, run browser smoke test, inspect tool sequence, inspect
  no-PHI logs, then mark that intent as sidecar-owned.
- Keep rollback flag active until all intents pass CI and manual smoke tests.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_intent_parity.py
vendor\bin\phpunit tests\Tests\Unit --filter AgentSidecarCutover
```

Manual UI smoke test:

- Open the Clinical Co-Pilot tab.
- Click each intent button.
- Confirm answer renders with citations and source drilldown.

Pass criteria:

- Every read-only intent is served by Python sidecar.
- PHP no longer builds clinical answers for cut-over intents.

## M21 - Move Write-Like Actions to Two-Phase Sidecar Proposals

- [ ] Done

**Status:** Not started  
**Depends on:** M13, M15, Sidecar S16-S17  
**Can run in parallel with:** M20

Implementation:

- For lab/intake persistence, keep the LLM-chosen sidecar tool from directly
  committing writes unless the operation is explicitly validated.
- Prefer two-phase actions:
  sidecar proposes a typed write action with citations; PHP/OpenEMR validates
  patient/encounter/document linkage and applies it.
- Add idempotency keys using `trace_id` plus document ID.
- Keep commit responses auditable and source-linked.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_write_action_proposals.py
vendor\bin\phpunit tests\Tests\Unit --filter LabPdf
```

Pass criteria:

- Sidecar can propose writes.
- Invalid or uncited write proposals are rejected.
- Duplicate proposal replay is idempotent.

## M22 - Expand Evals for LLM-Chosen Tool Behavior

- [ ] Done

**Status:** Not started  
**Depends on:** M13-M16  
**Can run in parallel with:** M23

Implementation:

- Extend eval fixtures to score model tool behavior.
- Add rubrics:
  `tool_allowed`, `tool_args_scoped`, `required_evidence_checked`,
  `citation_present`, `factually_consistent`, `safe_refusal`,
  `no_phi_in_logs`, and `verification_passed`.
- In fake-model tests, assert exact tool sequences.
- In live-model optional evals, assert required tool set rather than exact
  order where appropriate.

Verification:

```powershell
cd agent-service
python -m agent_service.eval --suite copilot-tools
```

Pass criteria:

- Eval fails when the model tries a disallowed tool.
- Eval fails when a tool result omits citations.
- Eval fails when a required evidence source is skipped.

## M23 - Gate Migration in CI

- [ ] Done

**Status:** Not started  
**Depends on:** M18, M22  
**Can run in parallel with:** M24

Implementation:

- Add CI jobs for:
  Python unit tests, PHP proxy tests, sidecar contract tests, parity tests, and
  tool-behavior evals.
- CI must block:
  context auth regressions, cross-patient source access, disallowed tool
  execution, uncited claims, unsafe advice, and PHI log leaks.
- Keep the Week 2 50-case eval gate running.

Verification:

```powershell
cd agent-service
python -m pytest
python -m agent_service.eval --suite copilot-tools
vendor\bin\phpunit tests\Tests\Unit --filter Agent
```

Pass criteria:

- Local commands pass.
- CI fails under an injected disallowed-tool or missing-citation regression.

## M24 - Remove Migrated PHP Agent Internals

- [ ] Done

**Status:** Not started  
**Depends on:** M20, M21, M23  
**Can run in parallel with:** M25 prep

Implementation:

- Remove or deprecate PHP classes that are no longer the source of truth:
  PHP LLM provider, PHP orchestrator, PHP verifier, PHP response builder, and
  PHP evidence tool planning.
- Keep PHP classes needed for UI, route entry, context minting, sidecar client,
  and OpenEMR write validation.
- Update docs so future work happens in Python for agent logic.
- Remove stale env vars only after deployed configs are updated.

Verification:

```powershell
rg -n "AgentLlmOrchestrator|AgentAnswerVerifier|OpenAiResponsesAgentLlmProvider|AgentEvidenceResponseBuilder" src tests
vendor\bin\phpunit tests\Tests\Unit --filter Agent
cd agent-service
python -m pytest
```

Pass criteria:

- No active PHP code path performs clinical answer generation or verification.
- UI and context proxy tests still pass.
- Python sidecar tests/evals pass.

## M25 - Final Migration Acceptance Run

- [ ] Done

**Status:** Not started  
**Depends on:** M23, M24  
**Can run in parallel with:** none

Implementation:

- Start OpenEMR and sidecar.
- Exercise every Clinical Co-Pilot UI intent.
- Exercise lab PDF upload and intake form upload.
- Confirm the LLM chooses tools in the trace.
- Confirm all tools execute through policy executor.
- Confirm source drilldown works.
- Confirm write proposals or commits are source-linked and idempotent.
- Confirm no raw PHI appears in sidecar, PHP, CI, or observability logs.

Verification:

```powershell
.\run-docker.ps1
docker compose --project-name openemr up -d agent-service
cd agent-service
python -m pytest
python -m agent_service.eval --suite week2
python -m agent_service.eval --suite copilot-tools
```

Manual UI checks:

- Open Clinical Co-Pilot.
- Run all current intents.
- Open cited source details.
- Upload one lab PDF.
- Upload one intake form.
- Review sidecar traces for LLM-selected tools and verifier result.

Pass criteria:

- PHP is UI/context proxy only for Clinical Co-Pilot.
- Python owns all agent logic.
- LLM tool choice is visible in traces.
- CI/evals block tool-policy, citation, factuality, refusal, and PHI-log
  regressions.

---

## Recommended Execution Order

1. M0-M6: lock the sidecar authority model and tool executor before moving
   behavior.
2. M1-M2: build parity fixtures and endpoint contracts while the executor is
   being built.
3. M7-M16: port agent behavior into Python behind policy-enforced tools.
4. M17-M20: wire PHP as a thin proxy and cut over read-only intents one at a
   time.
5. M21: treat write-like behavior as typed, cited proposals before allowing
   commits.
6. M22-M23: add eval/CI coverage for LLM-chosen tool behavior.
7. M24-M25: remove migrated PHP internals only after sidecar parity and CI
   gates are stable.

The key implementation rule is: let the LLM choose tools, but make every tool
call pass through runtime policy that injects patient scope and rejects
unauthorized arguments before any OpenEMR data is read or written.
