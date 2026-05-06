# Sidecar Detailed Implementation Steps

Implementation tracker for the Week 2 Python sidecar described in
[Week-2-Assignment.pdf](Week-2-Assignment.pdf),
[W2_ARCHITECTURE.md](W2_ARCHITECTURE.md), and [week-2-plan.md](week-2-plan.md).

The sidecar is the new Python `agent-service` that OpenEMR calls after a lab
PDF or intake form upload. OpenEMR keeps CSRF, ACL, file validation, document
storage, and DB writes. The sidecar owns extraction, LangGraph orchestration,
hybrid RAG, citation metadata, evals, and observability.

## Status Legend

Use one of: `Not started`, `In progress`, `Blocked`, `Done`, `Skipped`.

## Parallelization Guide

| Group | Can start after | Steps | Notes |
| --- | --- | --- | --- |
| Foundation | none | S0-S4 | Do these first; most later work depends on the sidecar contract. |
| Sidecar internals | S2-S4 | S5-S11 | Schemas, RAG, graph, extractor, and observability can split across separate owners once package contracts exist. |
| OpenEMR integration | S2-S4 | S12-S18 | PHP proxy, UI enum changes, lab dispatch, citation DB, and overlay can proceed while sidecar internals mature if the endpoint stub is stable. |
| Eval and CI | S5-S11 | S19-S22 | Fixture generation can begin early; full scoring depends on schemas and graph outputs. |
| Deployment and docs | S3, S12 | S23-S26 | Compose/deploy documentation can be prepared in parallel, then verified after integration. |

## Step Summary

| ID | Step | Status | Depends on | Can run in parallel with |
| --- | --- | --- | --- | --- |
| S0 | Baseline branch and environment preflight | Not started | none | none |
| S1 | Freeze sidecar HTTP contract | Not started | S0 | S2 |
| S2 | Scaffold `agent-service` package | Not started | S0 | S1 |
| S3 | Add config, shared-secret auth, and health route | Not started | S1, S2 | S4 |
| S4 | Add typed request/response models | Not started | S1, S2 | S3 |
| S5 | Add strict clinical schemas and validators | Not started | S4 | S6, S7, S8 |
| S6 | Add fixture-safe OpenAI client boundary | Not started | S3, S4 | S5, S7, S8 |
| S7 | Add guideline corpus loader | Not started | S2 | S5, S6, S8 |
| S8 | Add BM25, dense vector, fusion, and rerank pipeline | Not started | S7 | S5, S6 |
| S9 | Implement extractor worker | Not started | S5, S6 | S10, S11 |
| S10 | Implement evidence retriever worker | Not started | S8 | S9, S11 |
| S11 | Implement LangGraph supervisor flow | Not started | S9, S10 | S12-S15 |
| S12 | Add OpenEMR sidecar config/env vars | Not started | S1 | S7-S11 |
| S13 | Add shared upload path/volume | Not started | S12 | S14, S15 |
| S14 | Extend upload UI for Lab Report | Done | S12 | S13, S15 |
| S15 | Replace PHP extraction path with sidecar proxy | Not started | S3, S4, S12, S13 | S14, S16 |
| S16 | Add lab PDF dispatch to OpenEMR lab tables | Not started | S5, S15 | S17, S18 |
| S17 | Add citation table and persistence | Not started | S5, S15 | S16, S18 |
| S18 | Add click-to-source PDF overlay UI | Not started | S17 | S19-S21 |
| S19 | Build 50-case fixture layout | Not started | S5 | S20 |
| S20 | Add eval runner and boolean rubrics | Not started | S11, S16, S17, S19 | S21 |
| S21 | Add pre-push hook and GitHub Actions gate | Not started | S20 | S23, S24 |
| S22 | Add regression-injection proof test | Not started | S20, S21 | S23-S26 |
| S23 | Add sidecar Docker/Compose wiring | Not started | S3, S12, S13 | S20-S22 |
| S24 | Add deployment guide and env documentation | Not started | S12, S23 | S20-S22 |
| S25 | Add cost/latency report generation | Not started | S11, S20 | S24, S26 |
| S26 | Final end-to-end demo verification | Not started | S18, S21, S23, S25 | none |

---

## S0 - Baseline Branch and Environment Preflight

**Status:** Not started  
**Depends on:** none  
**Can run in parallel with:** none

Implementation:

- Create or switch to a `codex/` work branch.
- Confirm Docker Desktop and Docker Compose v2 are available.
- Confirm Python 3.11+ is available for the sidecar.
- Confirm the existing OpenEMR stack starts with `run-docker.ps1`.
- Record any local environment issue in [environment-notes.md](environment-notes.md)
  immediately if it blocks sidecar work.

Verification:

```powershell
git status --short
python --version
docker compose version
.\run-docker.ps1
```

Pass criteria:

- OpenEMR is reachable at the local URL printed by `run-docker.ps1`.
- No unrelated user changes are modified or staged.

## S1 - Freeze Sidecar HTTP Contract

**Status:** Not started  
**Depends on:** S0  
**Can run in parallel with:** S2

Implementation:

- Define the canonical request/response contract in a small doc under
  `agent-service`, for example `agent-service\CONTRACT.md`.
- Keep the endpoint fixed as `POST /api/agent/run`.
- Request fields:
  `patient_id`, `file_path`, `doc_type`, `encounter_id`, `trace_id`.
- Response fields:
  `extracted`, `evidence`, `answer`, `citations`, `cost_usd`,
  `latency_ms_per_step`, `tool_sequence`, `extraction_confidence`.
- Define `doc_type` values as `lab_pdf`, `intake_form`, and `auto`.
- Define refusal/error response shape before PHP integration begins.

Verification:

```powershell
rg -n "POST /api/agent/run|doc_type|tool_sequence|citations" agent-service
```

Pass criteria:

- Contract is explicit enough that PHP and Python can be implemented by
  separate people without guessing field names.

## S2 - Scaffold `agent-service` Package

**Status:** Not started  
**Depends on:** S0  
**Can run in parallel with:** S1

Implementation:

- Create `agent-service\agent_service\`.
- Add `pyproject.toml` or `requirements.txt`.
- Include FastAPI, Uvicorn, Pydantic v2, pytest, httpx, LangGraph, OpenAI
  SDK, rank-bm25, numpy, and any vector/rerank dependencies selected.
- Add package folders:
  `schemas`, `workers`, `rag`, `eval`, and `tests`.
- Add a minimal importable `agent_service` package.

Verification:

```powershell
cd agent-service
python -m pip install -e .[dev]
python -m compileall agent_service
python -m pytest
```

Pass criteria:

- Package imports cleanly.
- Empty/minimal pytest suite passes.

## S3 - Add Config, Shared-Secret Auth, and Health Route

**Status:** Not started  
**Depends on:** S1, S2  
**Can run in parallel with:** S4

Implementation:

- Add `agent_service\config.py`.
- Read `OPENAI_API_KEY`, `COHERE_API_KEY`, `AGENT_SHARED_SECRET`,
  `HONEYCOMB_API_KEY`, and optional local toggles.
- Add FastAPI app in `agent_service\main.py`.
- Add `GET /healthz`.
- Add shared-secret auth for `POST /api/agent/run`; reject missing or wrong
  secret before reading the file path or running any tool.

Verification:

```powershell
cd agent-service
$env:AGENT_SHARED_SECRET = "dev-secret"
python -m pytest tests
uvicorn agent_service.main:app --host 127.0.0.1 --port 8010
```

In another PowerShell:

```powershell
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Pass criteria:

- Health route returns OK.
- Unauthorized `POST /api/agent/run` returns 401 or 403.

## S4 - Add Typed Request/Response Models

**Status:** Not started  
**Depends on:** S1, S2  
**Can run in parallel with:** S3

Implementation:

- Add Pydantic models for `AgentRunRequest`, `AgentRunResponse`,
  `AgentErrorResponse`, and `ToolSequence`.
- Validate positive `patient_id` and `encounter_id`.
- Validate `doc_type`.
- Validate `trace_id` as non-empty string or UUID.
- Return a stub response from `POST /api/agent/run` using these models.

Verification:

```powershell
cd agent-service
python -m pytest tests
```

Add tests for:

- Valid request returns 200.
- Invalid `doc_type` returns 422.
- Missing secret returns auth failure.
- Stub response contains `tool_sequence`.

Pass criteria:

- Endpoint contract is test-covered before any model calls exist.

## S5 - Add Strict Clinical Schemas and Validators

**Status:** Not started  
**Depends on:** S4  
**Can run in parallel with:** S6, S7, S8

Implementation:

- Add `agent_service\schemas\citation.py`.
- Add `agent_service\schemas\lab_pdf.py`.
- Add `agent_service\schemas\intake_form.py`.
- Required lab fields:
  test name, value, unit, reference range, collection date, abnormal flag,
  and source citation.
- Required intake fields:
  demographics, chief concern, current medications, allergies, family history,
  and source citation.
- Include `extraction_confidence` with range validation.
- Add PDF bounding-box validation helper for positive area and page bounds.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_schemas.py
```

Pass criteria:

- Valid lab and intake examples round-trip through Pydantic.
- Missing `source_citation` is rejected.
- Confidence outside `[0, 1]` is rejected.
- Invalid bbox is rejected.

## S6 - Add Fixture-Safe OpenAI Client Boundary

**Status:** Not started  
**Depends on:** S3, S4  
**Can run in parallel with:** S5, S7, S8

Implementation:

- Add `agent_service\openai_client.py` or `agent_service\clients\openai.py`.
- Define an interface/protocol with:
  `upload_pdf`, `extract_structured`, and `embed_texts`.
- Add a real OpenAI implementation.
- Add a fake/recorded implementation for tests and evals.
- Ensure CI/eval can run without live OpenAI calls.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_openai_client_boundary.py
```

Pass criteria:

- Tests prove fake client can replace the real client.
- Eval mode cannot accidentally call the live OpenAI API.

## S7 - Add Guideline Corpus Loader

**Status:** Not started  
**Depends on:** S2  
**Can run in parallel with:** S5, S6, S8

Implementation:

- Create `agent-service\agent_service\rag\corpus\`.
- Add 50-100 small public guideline chunks relevant to lab/intake scenarios:
  USPSTF, ADA, JNC or ACC/AHA hypertension, and CDC immunization.
- Store each chunk with stable `chunk_id`, `source_url`, `section`,
  `published`, and `text`.
- Add `corpus_loader.py`.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_corpus_loader.py
```

Pass criteria:

- Loader returns all chunks.
- Every chunk has stable citation metadata.
- No chunk contains synthetic patient PHI.

## S8 - Add BM25, Dense Vector, Fusion, and Rerank Pipeline

**Status:** Not started  
**Depends on:** S7  
**Can run in parallel with:** S5, S6

Implementation:

- Add BM25 index over corpus chunks.
- Add dense embedding index using selected local storage, preferably SQLite
  plus vector extension if viable in the target environment.
- Add Reciprocal Rank Fusion from sparse and dense results.
- Add Cohere rerank or equivalent reranker behind an interface.
- Add deterministic fake reranker for tests.
- Return top grounded snippets with `Citation` metadata.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_rag.py
```

Pass criteria:

- Canned hypertension/diabetes/lipid queries return expected chunk IDs.
- Reranker can be faked in CI.
- Returned snippets include citation metadata.

## S9 - Implement Extractor Worker

**Status:** Not started  
**Depends on:** S5, S6  
**Can run in parallel with:** S10, S11 prep

Implementation:

- Add `agent_service\workers\extractor.py`.
- Input: current graph state with `file_path`, `doc_type`, and `trace_id`.
- For `lab_pdf`, call OpenAI Files API + Structured Outputs through the
  client boundary and validate as `LabPdf`.
- For `intake_form`, validate as `IntakeForm`.
- Retry once on schema mismatch with validator feedback.
- Return an error event/refusal on repeated validation failure.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_extractor_worker.py
```

Pass criteria:

- Fake OpenAI response validates into strict schema.
- Malformed response retries once, then refuses.
- No partial extraction is returned as success.

## S10 - Implement Evidence Retriever Worker

**Status:** Not started  
**Depends on:** S8  
**Can run in parallel with:** S9, S11 prep

Implementation:

- Add `agent_service\workers\retriever.py`.
- Build a retrieval query from extracted state.
- For labs, include abnormal flag, test name, value/unit where useful.
- For intake forms, include chief concern, medications, allergies, and family
  history where useful.
- Return top guideline snippets with guideline citations.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_retriever_worker.py
```

Pass criteria:

- Lab fixture produces a clinically relevant query.
- Intake fixture produces a clinically relevant query.
- Result list contains top-k snippets and citation metadata.

## S11 - Implement LangGraph Supervisor Flow

**Status:** Not started  
**Depends on:** S9, S10  
**Can run in parallel with:** S12-S15 after endpoint stub is stable

Implementation:

- Add `agent_service\graph.py`.
- Add `agent_service\supervisor.py`.
- Use one supervisor and two workers: extractor and evidence retriever.
- Supervisor routes among `extract`, `retrieve`, `finalize`, and `refuse`.
- Keep supervisor deterministic.
- Check the supervisor prompt into source.
- Append every handoff to `tool_sequence`.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_graph.py
```

Pass criteria:

- Lab fixture sequence matches expected order.
- Intake fixture sequence matches expected order.
- Refusal fixture exits without retrieval or final answer hallucination.

## S12 - Add OpenEMR Sidecar Config/Env Vars

**Status:** Not started  
**Depends on:** S1  
**Can run in parallel with:** S7-S11

Implementation:

- Extend `.env.example` with sidecar-specific variables:
  `AGENT_SERVICE_URL`, `AGENT_SHARED_SECRET`, `COHERE_API_KEY`,
  `HONEYCOMB_API_KEY`.
- Decide whether OpenEMR reads these through `OEEnvBag` or another existing
  environment helper.
- Keep server-side secrets out of browser-rendered pages.

Verification:

```powershell
rg -n "AGENT_SERVICE_URL|AGENT_SHARED_SECRET|COHERE_API_KEY|HONEYCOMB_API_KEY" .env.example src interface
```

Pass criteria:

- Variables are documented.
- PHP can read the sidecar URL and secret without exposing them to client JS.

## S13 - Add Shared Upload Path/Volume

**Status:** Not started  
**Depends on:** S12  
**Can run in parallel with:** S14, S15 prep

Implementation:

- Define a shared upload directory visible to OpenEMR and `agent-service`,
  such as `/var/uploads` inside containers.
- Add compose volume wiring for local development.
- In PHP, copy the validated upload from the temporary PHP path into the shared
  directory before calling the sidecar.
- Use a generated safe filename keyed by `trace_id`.

Verification:

```powershell
docker compose --project-name openemr config
```

Manual local check:

- Upload or copy a test PDF through OpenEMR.
- Confirm the OpenEMR container and sidecar container can both see the same
  file path.

Pass criteria:

- Sidecar can read the exact path sent by PHP.

## S14 - Extend Upload UI for Lab Report

**Status:** Done  
**Depends on:** S12  
**Can run in parallel with:** S13, S15 prep

Implementation:

- Update [new.php](interface\forms\upload_intake_form\new.php) dropdown:
  add `Lab Report`.
- Update user-facing text from "Upload Intake Form" to a Week 2-appropriate
  label such as "Upload Document (Co-Pilot)" if desired.
- Update valid request values in [save.php](interface\forms\upload_intake_form\save.php).
- Update [table.sql](interface\forms\upload_intake_form\table.sql) and upgrade
  SQL enum to include lab report/lab PDF.
- Update registry row name if switching to the Week 2 label.

Verification:

```powershell
rg -n "Lab Report|lab_pdf|Upload Document" interface\forms\upload_intake_form sql\8_1_0-to-8_1_1_upgrade.sql
```

Pass criteria:

- UI accepts lab uploads.
- Server validation accepts the lab wire value.
- DB schema can persist that type.

## S15 - Replace PHP Extraction Path With Sidecar Proxy

**Status:** Not started  
**Depends on:** S3, S4, S12, S13  
**Can run in parallel with:** S14, S16 prep

Implementation:

- Add a PHP sidecar client service, for example
  `src\Services\Intake\AgentServiceClient.php`.
- In [save.php](interface\forms\upload_intake_form\save.php), replace direct
  construction of `OpenAIClient`/`IntakeFormIngestService` for Week 2 paths
  with a call to the sidecar.
- Keep CSRF, ACL, PDF validation, and active patient/encounter checks in PHP.
- Send `trace_id` and shared-secret header.
- Convert sidecar errors into clear user-facing failures.
- Ensure no DB writes happen if sidecar returns refusal/error.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Unit --filter AgentServiceClient
```

Manual integration with stub sidecar:

- Start sidecar returning a fixed valid response.
- Upload a fixture PDF.
- Confirm PHP calls sidecar and handles response.

Pass criteria:

- PHP no longer makes Week 2 extraction decisions locally.
- Sidecar error/refusal blocks persistence.

## S16 - Add Lab PDF Dispatch to OpenEMR Lab Tables

**Status:** Not started  
**Depends on:** S5, S15  
**Can run in parallel with:** S17, S18 prep

Implementation:

- Add a lab dispatcher service under `src\Services\Intake\Dispatcher\` or a
  Week 2-specific namespace.
- Map extracted `LabPdf.results[]` into:
  `procedure_order`, `procedure_report`, and `procedure_result`.
- Store document linkage where OpenEMR expects lab document references.
- Preserve LOINC code, value, unit, reference range, abnormal flag, and
  collection date.
- Avoid duplicate rows when retrying the same upload with the same trace/file
  if a previous attempt already completed.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Unit --filter LabPdf
```

Manual DB check after uploading one lab fixture:

```powershell
docker compose --project-name openemr exec -T mysql mysql -uroot -proot openemr -e "SELECT COUNT(*) FROM procedure_result;"
```

Pass criteria:

- One uploaded lab PDF produces expected procedure rows.
- Existing FHIR Observation path can see the result without custom FHIR hacks.

## S17 - Add Citation Table and Persistence

**Status:** Not started  
**Depends on:** S5, S15  
**Can run in parallel with:** S16, S18 prep

Implementation:

- Add `form_upload_intake_form_citation` migration and upgrade SQL.
- Persist one citation row per persisted clinical field.
- Store PDF bbox values for lab/intake source facts.
- Store guideline chunk citations for evidence snippets.
- Ensure `form_id` links to `form_upload_intake_form`.

Verification:

```powershell
rg -n "form_upload_intake_form_citation" db sql interface src
vendor\bin\phpunit tests\Tests\Unit --filter Citation
```

Manual DB check:

```powershell
docker compose --project-name openemr exec -T mysql mysql -uroot -proot openemr -e "DESCRIBE form_upload_intake_form_citation;"
```

Pass criteria:

- Citation table exists.
- Uploading a fixture creates citation rows with non-null citation metadata.

## S18 - Add Click-to-Source PDF Overlay UI

**Status:** Not started  
**Depends on:** S17  
**Can run in parallel with:** S19-S21

Implementation:

- Extend [view.php](interface\forms\upload_intake_form\view.php).
- Render original PDF with pdf.js.
- Fetch citation rows for the form.
- Render extracted fields beside the PDF.
- Hover/focus on a field draws bbox overlay.
- Click scrolls to the page and flashes the overlay.
- Guideline citations open a side panel with snippet and source URL.

Verification:

```powershell
vendor\bin\phpunit tests\Tests\Isolated --filter UploadIntakeForm
```

Manual UI check:

- Upload one lab fixture.
- Open the encounter timeline row.
- Hover an extracted field.
- Confirm the overlay appears over the source PDF region.

Pass criteria:

- The visual bounding-box overlay works for at least one lab field and one
  intake field.

## S19 - Build 50-Case Fixture Layout

**Status:** Not started  
**Depends on:** S5  
**Can run in parallel with:** S20

Implementation:

- Create `agent-service\agent_service\eval\fixtures\`.
- Generate/freeze 25 lab PDFs with [generate-lab-pdf.ps1](generate-lab-pdf.ps1).
- Generate/freeze 25 intake PDFs with
  [generate-intake-form.ps1](generate-intake-form.ps1).
- Add expected extracted JSON and expected rubric outcomes.
- Add corrupt/empty/missing-data cases for safe refusal.
- Add recorded fake OpenAI outputs for each fixture.

Verification:

```powershell
cd agent-service
python -m pytest tests\test_eval_fixtures.py
```

Pass criteria:

- Exactly 50 cases load.
- Every case has input file, expected values, rubric expectations, and recorded
  fake model output.

## S20 - Add Eval Runner and Boolean Rubrics

**Status:** Not started  
**Depends on:** S11, S16, S17, S19  
**Can run in parallel with:** S21 prep

Implementation:

- Add `python -m agent_service.eval`.
- Run graph using fake OpenAI client.
- Score rubrics:
  `schema_valid`, `citation_present`, `factually_consistent`,
  `safe_refusal`, `no_phi_in_logs`.
- Compare current score against committed baseline.
- Fail if any category regresses by more than 5 percentage points.
- Fail if any category drops below threshold.
- Enforce `no_phi_in_logs = 1.00`.

Verification:

```powershell
cd agent-service
python -m agent_service.eval --baseline agent_service\eval\baseline.json
```

Pass criteria:

- Eval exits 0 on baseline.
- Eval prints per-rubric pass rates.
- No live OpenAI/Cohere calls occur in CI mode.

## S21 - Add Pre-Push Hook and GitHub Actions Gate

**Status:** Not started  
**Depends on:** S20  
**Can run in parallel with:** S23, S24

Implementation:

- Add a repo script for installing/updating `.git\hooks\pre-push` without
  destructive behavior.
- Hook runs `python -m agent_service.eval`.
- Add `.github\workflows\agent-eval.yml`.
- Ensure workflow installs Python deps and runs the same command.
- Keep hook and workflow command identical where practical.

Verification:

```powershell
cd agent-service
python -m agent_service.eval --baseline agent_service\eval\baseline.json
```

Manual hook check:

```powershell
git hook run pre-push
```

If `git hook run` is unavailable in the local Git version, invoke the hook
script directly with PowerShell-compatible arguments documented in the hook.

Pass criteria:

- Local hook blocks failing evals.
- GitHub Actions uses the same eval runner.

## S22 - Add Regression-Injection Proof Test

**Status:** Not started  
**Depends on:** S20, S21  
**Can run in parallel with:** S23-S26

Implementation:

- Add a controlled test mode that simulates a meaningful regression, such as
  dropping citation bboxes or changing an expected lab value.
- Confirm the eval gate fails because `citation_present` or
  `factually_consistent` regresses.
- Document the exact regression proof command for the demo video.

Verification:

```powershell
cd agent-service
python -m agent_service.eval --baseline agent_service\eval\baseline.json --inject-regression drop-citations
```

Pass criteria:

- Command exits non-zero.
- Output names the failing rubric and affected fixtures.

## S23 - Add Sidecar Docker/Compose Wiring

**Status:** Not started  
**Depends on:** S3, S12, S13  
**Can run in parallel with:** S20-S22

Implementation:

- Add `agent-service\Dockerfile`.
- Add compose wiring to run `agent-service` beside OpenEMR locally.
- Mount the shared upload volume in both services.
- Expose sidecar port only as needed for local development.
- Keep production-facing traffic behind OpenEMR/PHP or documented deployment
  boundary.

Verification:

```powershell
docker compose --project-name openemr config
docker compose --project-name openemr up -d agent-service
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Pass criteria:

- Sidecar starts from Docker.
- Sidecar sees configured env vars.
- Health route works.

## S24 - Add Deployment Guide and Env Documentation

**Status:** Not started  
**Depends on:** S12, S23  
**Can run in parallel with:** S20-S22

Implementation:

- Add a Week 2 README section clearly separated from Week 1 behavior.
- Document local and deployed topology:
  OpenEMR plus Python sidecar.
- Document env vars:
  `OPENAI_API_KEY`, `COHERE_API_KEY`, `AGENT_SHARED_SECRET`,
  `HONEYCOMB_API_KEY`, `AGENT_SERVICE_URL`.
- Document how to run the sidecar locally.
- Document how graders reach the deployed OpenEMR app and sidecar-backed flow.

Verification:

```powershell
rg -n "Week 2|agent-service|AGENT_SERVICE_URL|AGENT_SHARED_SECRET|COHERE_API_KEY|HONEYCOMB_API_KEY" README.md .env.example agent-service
```

Pass criteria:

- A new developer can identify all services and env vars without reading the
  source code.

## S25 - Add Cost/Latency Report Generation

**Status:** Not started  
**Depends on:** S11, S20  
**Can run in parallel with:** S24, S26 prep

Implementation:

- Store sanitized per-run stats in local SQLite or JSONL:
  latency per step, tokens, model, cost estimate, retrieval hits, confidence.
- Add a report generator that emits Markdown.
- Include actual dev spend, projected cost at 100/1000/10000 docs per day,
  p50/p95 latency, and bottleneck analysis.

Verification:

```powershell
cd agent-service
python -m agent_service.eval --baseline agent_service\eval\baseline.json
python -m agent_service.observability.report --out ..\cost-latency-report.md
```

Pass criteria:

- Report file is generated.
- Report contains p50/p95, projected cost, and bottleneck summary.
- Report contains no raw PHI.

## S26 - Final End-to-End Demo Verification

**Status:** Not started  
**Depends on:** S18, S21, S23, S25  
**Can run in parallel with:** none

Implementation:

- Start OpenEMR and sidecar locally.
- Upload one generated lab PDF.
- Confirm sidecar extraction and retrieval run.
- Confirm lab results persist into OpenEMR.
- Confirm citation rows persist.
- Confirm PDF overlay works.
- Upload one intake form and confirm intake path still works.
- Run eval gate.
- Run regression-injection proof.
- Capture demo video flow.

Verification:

```powershell
.\run-docker.ps1
docker compose --project-name openemr up -d agent-service
cd agent-service
python -m agent_service.eval --baseline agent_service\eval\baseline.json
python -m agent_service.eval --baseline agent_service\eval\baseline.json --inject-regression drop-citations
```

Manual UI checks:

- Lab upload appears in encounter timeline.
- Lab observations are queryable from OpenEMR/FHIR path.
- Click-to-source overlay shows bbox for cited facts.
- Intake form upload still works.
- Observability record contains tool sequence, latency, cost, retrieval hits,
  extraction confidence, and no raw PHI.

Pass criteria:

- Core Week 2 flow works locally and in the deployed app.
- Eval passes normally and fails under injected regression.
- README/deployment docs match the actual commands used.

---

## Recommended Execution Order

1. S0-S4: make the sidecar contract real and testable.
2. S12-S15: wire PHP to the stub sidecar early to expose integration problems.
3. S5-S11: fill in schemas, extractor, retriever, and graph.
4. S16-S18: persist lab data/citations and add the visual source overlay.
5. S19-S22: make the eval gate deterministic and regression-blocking.
6. S23-S26: containerize, document, report costs, and run the final demo path.

The most important early risk reducer is S15 with a stub sidecar. Once OpenEMR
can call a local FastAPI service through the intended contract, the remaining
sidecar internals can improve without reopening the PHP boundary every time.

---

# Clinical Co-Pilot Migration to Python Sidecar

This section tracks the later migration where the existing PHP Clinical
Co-Pilot logic moves into Python and the sidecar becomes an LLM-driven tool
agent. The non-negotiable target is:

- The LLM chooses tools inside Python.
- PHP remains the OpenEMR UI and session boundary.
- PHP does not perform clinical answer generation, evidence shaping, verifier
  logic, prompt assembly, or model-provider calls after cutover.
- The sidecar never accepts patient IDs, encounter IDs, source IDs, SQL, file
  paths, or write targets from the model as authority. Runtime policy injects
  scoped values from the signed run context.

## Done Marker

Each migration step has an explicit checkbox:

```markdown
- [ ] Done
```

To mark a step complete, change the checkbox to:

```markdown
- [x] Done
```

Also update the matching row in the summary table from `Not started` to
`Done`. This gives both local, in-step tracking and a compact dashboard.

## Migration Step Summary

| ID | Step | Status | Depends on | Can run in parallel with |
| --- | --- | --- | --- | --- |
| M0 | Confirm migration target and PHP boundary | Not started | S3-S4 | M1 |
| M1 | Inventory current PHP behavior and build parity fixtures | Not started | none | M0, M2 |
| M2 | Define sidecar copilot run contract | Not started | M0 | M1, M3 |
| M3 | Define signed `CopilotRunContext` | Not started | M0 | M2 |
| M4 | Add sidecar context verification | Not started | M2, M3 | M5, M6 |
| M5 | Add Python tool registry primitives | Not started | M2 | M4, M6 |
| M6 | Add policy-enforced tool executor | Not started | M3, M5 | M4 |
| M7 | Port intent catalog and capability caps to Python | Not started | M5, M6 | M8, M9 |
| M8 | Port evidence schemas and citation models | Not started | M5 | M7, M9 |
| M9 | Add Python OpenEMR read repository | Not started | M3, M8 | M7, M10 |
| M10 | Implement read-only patient evidence tools | Not started | M7, M8, M9 | M11 |
| M11 | Implement source drilldown tool | Not started | M8, M9 | M10 |
| M12 | Implement document/lab/intake tools in same registry | Not started | S9-S11, M6 | M10-M11 |
| M13 | Implement LLM tool-choice agent loop | Not started | M6, M10, M11 | M14, M15 |
| M14 | Port answer schema and response shaping | Not started | M8 | M13, M15 |
| M15 | Port verifier/refusal rules to Python | Not started | M8, M14 | M13 |
| M16 | Add PHI-safe sidecar observability for tool calls | Not started | M6, M13 | M17 |
| M17 | Add PHP thin proxy to sidecar copilot endpoint | Not started | M2-M4 | M13-M16 |
| M18 | Add shadow mode comparing PHP and Python outputs | Not started | M13-M17 | M19 |
| M19 | Add per-intent cutover feature flags | Not started | M18 | M20 |
| M20 | Cut over read-only intents one by one | Not started | M18, M19 | M21 |
| M21 | Move write-like actions to two-phase sidecar proposals | Not started | M13, M15, S16-S17 | M20 |
| M22 | Expand evals for LLM-chosen tool behavior | Not started | M13-M16 | M23 |
| M23 | Gate migration in CI | Not started | M18, M22 | M24 |
| M24 | Remove migrated PHP agent internals | Not started | M20, M21, M23 | M25 |
| M25 | Final migration acceptance run | Not started | M23, M24 | none |

## Migration Parallelization Guide

| Group | Can start after | Steps | Notes |
| --- | --- | --- | --- |
| Contract and safety boundary | S3-S4 | M0-M6 | Do before moving clinical behavior. |
| PHP parity capture | none | M1 | Can run immediately and should finish before cutover. |
| Python agent/tool internals | M5-M6 | M7-M16 | Can split by intent/tool family once executor policy exists. |
| PHP proxy/cutover | M2-M4 | M17-M21 | Can be built against a stub sidecar, then switched to real agent loop. |
| Eval/CI/cleanup | M13-M18 | M22-M25 | Do not remove PHP internals until CI proves parity and regression coverage. |

---

## M0 - Confirm Migration Target and PHP Boundary

- [ ] Done

**Status:** Not started  
**Depends on:** S3-S4  
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
rg -n "LLM chooses tools|CopilotRunContext|runtime-allowed|authoritative" sidecar-detailed-steps.md W2_ARCHITECTURE.md
```

Pass criteria:

- The architecture text clearly states that LLM tool choice is allowed while
  patient scope and authority come from runtime context.

## M1 - Inventory Current PHP Behavior and Build Parity Fixtures

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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
rg -n "POST /api/copilot/run|answer_blocks|missing_or_uncertain|verification_status|tool_sequence" agent-service sidecar-detailed-steps.md
```

Pass criteria:

- The contract supports LLM-selected tools but still gives the UI a stable
  response shape compatible with current rendering.

## M3 - Define Signed `CopilotRunContext`

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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

- [ ] Done

**Status:** Not started  
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
**Depends on:** S9-S11, M6  
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
**Depends on:** M13, M15, S16-S17  
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
