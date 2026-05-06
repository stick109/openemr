# Week 2 Demo Video Runbook

Use this as the operator script for a 3-5 minute recording of the Week 2
Clinical Co-Pilot flow. Keep the screen on the deployed app and evidence
artifacts; do not turn this into an architecture walkthrough.

Record only synthetic or demo data. Do not show real names, DOBs, MRNs,
addresses, phone numbers, document screenshots, or raw prompt/log payloads.

## Preconditions And Placeholders

Fill these in before recording:

| Item | Value |
|------|-------|
| Railway app URL | `TODO_RAILWAY_APP_URL` |
| GitLab repo URL | `TODO_GITLAB_REPO_URL` |
| GitHub Actions or eval evidence URL | `TODO_CI_OR_EVAL_URL` |
| Demo patient | `TODO_DEMO_PATIENT_NAME_OR_ID` |
| Demo encounter | `TODO_DEMO_ENCOUNTER_DATE_OR_ID` |
| Lab PDF fixture | `TODO_LAB_PDF_FIXTURE_PATH` |
| Intake form fixture | `TODO_INTAKE_FORM_FIXTURE_PATH` |
| Honeycomb trace URL | `TODO_HONEYCOMB_TRACE_URL` |
| Eval command output path | `TODO_EVAL_OUTPUT_PATH_OR_COMMAND` |
| Cost/latency report path | `TODO_COST_LATENCY_REPORT_PATH` |

Confirm before capture:

- Railway deployment opens the OpenEMR app and the `agent-service` health check
  is green.
- The demo patient and encounter are synthetic/demo records.
- The lab PDF fixture and intake form fixture are synthetic/demo files.
- The GitLab submission URL is ready; this is acceptable because the repo has
  both GitHub and GitLab remotes.
- Live/dev retrieval uses Cohere Rerank. CI/tests use the deterministic fake
  reranker.
- Honeycomb is used only for sanitized demo traces.
- Durable token, cost, latency, and eval records are stored in the existing
  OpenEMR MariaDB/MySQL database.
- Eval thresholds are visible in the eval output or supporting docs:
  `schema_valid >= 0.90`, `citation_present >= 0.90`,
  `factually_consistent >= 0.80`, `safe_refusal >= 0.80`,
  `no_phi_in_logs = 1.00`.

## Browser And Window Setup

- Tab 1: Railway OpenEMR app at `TODO_RAILWAY_APP_URL`.
- Tab 2: GitLab repo at `TODO_GITLAB_REPO_URL`.
- Tab 3: CI/eval evidence at `TODO_CI_OR_EVAL_URL`, or terminal with the eval
  command output.
- Tab 4: Honeycomb trace at `TODO_HONEYCOMB_TRACE_URL`.
- Optional file window: fixture folder showing the lab PDF and intake form
  filenames only.
- Hide bookmarks, chat windows, local secrets, terminal history, `.env` files,
  and any screens with raw patient identifiers.

## Timestamped Script

### 0:00-0:20 - Open The Deployed App

Screen: Railway OpenEMR login or already-authenticated dashboard.

Narration:
"This is the deployed Week 2 Clinical Co-Pilot running on Railway. I am using
synthetic demo data only. The submission repo is in GitLab, with the matching
GitHub remote still available for CI checks."

Operator actions:

- Open `TODO_RAILWAY_APP_URL`.
- Log in if needed.
- Navigate to the demo patient.

### 0:20-0:45 - Open The Demo Encounter

Screen: synthetic patient chart and encounter.

Narration:
"I am opening the demo encounter where the clinician will upload a recent lab
PDF and ask the co-pilot to extract structured results, cite the source, and
retrieve supporting guideline evidence."

Operator actions:

- Search/open `TODO_DEMO_PATIENT_NAME_OR_ID`.
- Open `TODO_DEMO_ENCOUNTER_DATE_OR_ID`.
- Keep the patient banner visible only if it is synthetic/demo data.

### 0:45-1:30 - Upload Lab PDF

Screen: Upload Document / Clinical Co-Pilot flow.

Narration:
"The upload stays inside the OpenEMR encounter. The sidecar receives the file,
extracts a strict schema, and writes durable results back through OpenEMR
records."

Operator actions:

- Open `Administrative -> Upload Document`, or the implemented Clinical
  Co-Pilot upload entry point.
- Select doc type `Lab PDF` or equivalent.
- Upload `TODO_LAB_PDF_FIXTURE_PATH`.
- Wait for processing to complete.

### 1:30-2:05 - Show Structured Extraction And Click-To-Source

Screen: extracted lab results in timeline/panel plus source citation behavior.

Narration:
"The extracted lab facts are structured fields, not free text. Each persisted
field carries source metadata, and the UI can jump back to the originating PDF
location."

Operator actions:

- Show the extracted fields: test name, value, unit, reference range,
  collection date, abnormal flag, and confidence if available.
- Hover or click one field citation.
- Show the PDF source overlay or source panel.
- Do not zoom into any raw non-demo identity content.

### 2:05-2:35 - Show Intake Path

Screen: intake upload path or implemented intake result.

Narration:
"The same document-extraction path supports intake forms. If the merge UI is
ready, I will show the fill-only-empty demographics preview; if not, I will
show the intake upload path and the fixture-backed eval evidence."

Operator actions:

- If ready: upload `TODO_INTAKE_FORM_FIXTURE_PATH` and show the merge preview.
- If not ready: show the intake form option, fixture path, and the passing eval
  case for intake extraction.

### 2:35-3:05 - Show Evidence Retrieval And Guideline Citation

Screen: co-pilot answer, evidence panel, or retrieval result.

Narration:
"For clinical guidance, the co-pilot separates patient facts from guideline
evidence. Live and dev retrieval use Cohere Rerank over the candidate guideline
chunks; CI uses a deterministic fake reranker so tests do not depend on an
external API."

Operator actions:

- Trigger or open the co-pilot summary/recommendation for the encounter.
- Show at least one guideline citation.
- Show enough source metadata to prove the citation is machine-readable.

### 3:05-3:40 - Show Eval Gate And CI Evidence

Screen: CI/eval run or terminal output.

Narration:
"The assignment hard gate is eval-driven CI. This run scores 50 demo cases with
boolean rubrics and blocks regressions through the pre-push hook and CI check."

Operator actions:

- Open `TODO_CI_OR_EVAL_URL` or show `TODO_EVAL_OUTPUT_PATH_OR_COMMAND`.
- Show the current rubric pass rates.
- Point to thresholds:
  `schema_valid >= 0.90`, `citation_present >= 0.90`,
  `factually_consistent >= 0.80`, `safe_refusal >= 0.80`,
  `no_phi_in_logs = 1.00`.
- Show either green CI or a saved regression-injection failure proving the gate
  blocks bad changes.

### 3:40-4:20 - Show Sanitized Honeycomb Trace

Screen: Honeycomb trace for the demo encounter.

Narration:
"This trace is sanitized before export. It shows the tool sequence, per-step
latency, retrieval hits, token usage, and cost metadata. Raw PHI is not sent to
Honeycomb; durable cost, latency, and eval records stay in the existing OpenEMR
MariaDB/MySQL database."

Operator actions:

- Open `TODO_HONEYCOMB_TRACE_URL`.
- Show `tool_sequence`, latency spans, retrieval hits, and cost fields.
- Show redaction or sanitized attributes if visible.
- Avoid opening raw request bodies or document text.

### 4:20-4:45 - Close With Assignment Artifacts

Screen: repo/docs or submission checklist.

Narration:
"The final submission includes the deployed Railway app, GitLab repo, eval
dataset and gate evidence, source-grounded demo video, Honeycomb trace, and the
cost and latency report generated from OpenEMR database records."

Operator actions:

- Show the artifact checklist or repo file list.
- End before 5:00.

## Shot List

| Shot | Screen | Must Show | Do Not Show |
|------|--------|-----------|-------------|
| 1 | Railway OpenEMR app | Public deployed URL and logged-in app | Secrets, env vars |
| 2 | Demo patient encounter | Synthetic/demo patient and encounter | Real patient data |
| 3 | Upload Document / Clinical Co-Pilot | Lab PDF fixture upload | Local secret paths beyond fixture name |
| 4 | Extracted lab output | Structured fields and citation metadata | Raw prompt payloads |
| 5 | Click-to-source | PDF overlay or source panel | Real identity data |
| 6 | Intake path | Intake upload or eval-backed intake evidence | Unsupported claims that intake is complete if it is not |
| 7 | Evidence retrieval | Guideline citation and source metadata | Uncited clinical recommendation |
| 8 | Eval gate | 50-case run, thresholds, blocking evidence | Test secrets or API keys |
| 9 | Honeycomb trace | Sanitized tool sequence, latency, retrieval hits, cost | Raw PHI, raw document text |
| 10 | Submission artifacts | URLs and required files | Private tokens |

## Fallback Recording Plan

### UI Overlay Not Ready

- Still upload the lab PDF through the deployed app.
- Show the extracted structured fields in OpenEMR.
- Show citation metadata from the saved response or eval output.
- Narrate: "The source citation is present in the payload; the visual overlay is
  not shown in this capture."
- Do not claim the overlay is complete unless it is visible.

### Live Cohere Unavailable

- Show the retrieval request failing over or show recorded retrieval output from
  the same fixture.
- Narrate: "Live/dev retrieval normally uses Cohere Rerank. CI uses the
  deterministic fake reranker, so the eval gate remains reproducible even if
  the live provider is unavailable."
- Show the eval run proving retrieval citations still meet thresholds.

### Honeycomb Dashboard Not Populated

- Show a single sanitized trace URL if available.
- If no dashboard exists, show emitted sanitized span fields from logs or eval
  output: `tool_sequence`, `latency_ms_per_step`, `retrieval_hits`,
  `tokens_in`, `tokens_out`, and `cost_usd`.
- Narrate that Honeycomb is for sanitized demo traces only and durable records
  remain in OpenEMR MariaDB/MySQL.

### CI Link Unavailable

- Show local pre-push hook or eval command output instead.
- Use the exact command/output captured in `TODO_EVAL_OUTPUT_PATH_OR_COMMAND`.
- Show both a passing run and, if available, the saved regression-injection
  failure that blocks a push.

## Final Capture Checklist

Before exporting the video, confirm the recording includes:

- Deployed Railway OpenEMR app URL.
- GitLab repo URL.
- Synthetic/demo patient encounter.
- Lab PDF upload through Upload Document / Clinical Co-Pilot flow.
- Structured lab extraction with source citation or click-to-source behavior.
- Intake form path or honest fallback evidence.
- Evidence retrieval with guideline citation.
- Eval results with the five thresholds.
- PR-blocking CI or local pre-push hook evidence.
- Honeycomb sanitized trace or fallback sanitized span output.
- Statement that no raw PHI is logged and only synthetic/demo data is used.
- Cost/latency report path.
- No secrets, env vars, raw PHI, real patient screenshots, or private tokens.
