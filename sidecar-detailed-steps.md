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
| S14 | Extend upload UI for Lab Report | Not started | S12 | S13, S15 |
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

**Status:** Not started  
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
