# Week 2 — Plan & Open TODO

Single source of truth for what's left for an AI coding agent to finish.
Strictly agent-doable items only. Items that genuinely require the user
(demo video recording, accounts, payments, IDs) live in
[`todo-for-user.md`](todo-for-user.md).

Status legend: `TODO` · `IN-PROGRESS` · `DONE` · `BLOCKED`

Source plans recovered from git (commit `c59e2877c`) and re-mined for
remaining work: `week-2-plan.md`, `intake-forms-plan.md`,
`sidecar-detailed-steps.md`,
`Clinical Co-Pilot Migration to Python Sidecar.md`. Each work item below
references the original §/step ID where applicable.

---

## 1. Demo readiness — the 6 moments the demo must show

Friday demo, ≤5 minutes, must hit these six in order (per `Week-2-Assignment.pdf`).

- [x] **Document upload** (lab PDF + intake form) — `DONE`. Lab Report enum + form proxies into sidecar; `agent-service` runs healthy in compose.

- [x] **Extraction** (strict-schema JSON) — `DONE`. Pydantic `LabPdf` / `IntakeForm` / `Citation` schemas + extractor worker landed; OpenAI tool-choice client wired.

- [x] **Evidence retrieval** (hybrid RAG + rerank) — `DONE`. BM25 + dense + RRF + Cohere rerank pipeline + deterministic CI faker shipped under `agent-service/agent_service/rag/`.

- [ ] **Citations** (machine-readable + click-to-source / PDF bbox overlay) — `IN-PROGRESS`. Citation table, `CitationPersistenceService`, `citation_overlay.js`, and `view.php` integration are landed. Wet-run verification still pending — see backlog §C.

- [ ] **Eval results** (50-case rubrics, pass rates visible in UI) — `IN-PROGRESS`. 50 fixtures, runner, baseline, GHA workflow, pre-push hook, regression-injection proof all landed. UI surfacing + last-run snapshot still pending — see backlog §E.

- [ ] **Observability** (tool sequence, latency, token cost, no PHI) — `IN-PROGRESS`. Per-tool spans, redactor, run records, and PHI scanner shipped. Surface selection + redactor smoke still pending — see backlog §F.

---

## 2. Submission deliverables (Week-2-Assignment.pdf p.5–6)

- [ ] **GitLab repo** — setup guide + deployed link + env-var docs in `README.md`. See backlog §G.

- [x] **`./W2_ARCHITECTURE.md`** at repo root — `DONE`. File at [`../W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md), 11 sections, embeds 9 drawio diagrams from `diagrams/`.

- [x] **Schemas (Pydantic) + validation tests** for `lab_pdf` and `intake_form` — `DONE`. `agent_service/schemas/` + `tests/test_schemas.py`.

- [ ] **Eval dataset** — 50 cases + judge config + results artifact. See backlog §E.

- [x] **CI evidence: PR-blocking Git Hook regression-block proof** — `DONE`. Pre-push hook + `.github/workflows/agent-eval.yml` + three regression-injection modes documented in [`EVAL.md`](EVAL.md).

- [ ] **Demo video** (3–5 min) — user-only. See [`todo-for-user.md`](todo-for-user.md).

- [ ] **Cost & latency report** — `IN-PROGRESS`. [`cost-latency-report.md`](cost-latency-report.md) is checked in; refresh before submission. See backlog §H.

- [ ] **Deployed app** (publicly accessible) — `IN-PROGRESS`. Railway assets present (`railway.toml`, `Dockerfile.railway`, `deploy-railway.ps1`, `.railwayignore`); SETUP.md still has `_TODO: insert deployed URL when published_`. See backlog §I.

---

## 3. Open risks / unresolved decisions

- **Demographics-merge UX (Q3 in `week-2-plan.md` §7)** — unresolved. Week 2 ships fill-only-empty; "review-and-confirm" remains a stretch goal.
- **Worker rename (Q8)** — code uses `document-extractor` for both labs and intakes; assignment text says "intake-extractor". Note the rename in docs so reviewers don't flag it as missing — see backlog §G.
- **Cost balloon during eval runs** — mitigation in place (`gpt-4o-mini`, fixture cache, fake client in CI). Watch dev-spend before the final eval pass — see backlog §H.
- **Supervisor-as-black-box pitfall** — mitigated by deterministic supervisor + `tool_sequence` assertions. Confirm trace-shape test still asserts expected ordering — see backlog §B.
- **PHI leak via observability SaaS (Q5)** — Honeycomb only gets sanitized traces; durable token/cost/eval records live in OpenEMR's MySQL. Re-run synthetic-PHI redactor test before recording — see backlog §F.
- **Cypress E2E flakiness against the live sidecar** — keep E2E mocked against a recorded fixture; do not point CI Cypress at the live agent service.
- **Eval gate too lenient** — graders will inject a regression. Three injection modes (`drop-citations`, `wrong-value`, `flip-abnormal-flags`) are proven to fire the gate. No action unless thresholds drift.

---

## 4. Implementation backlog

Work items recovered from the original plan files. Group letters group items
by demo moment / submission deliverable. Each item names the originating
§/step ID and points at concrete files where the source plan did.

### A. Sidecar internals — clean-up & residual contract work (sidecar-detailed-steps S0–S11)

- [ ] (S0) Confirm `run-docker.ps1` still launches OpenEMR + sidecar healthy on a clean clone; record any drift in `week-2/environment-notes.md`.
- [ ] (S1) Reconcile `agent-service/CONTRACT.md` with the actual response shape returned by `agent-service/agent_service/api/copilot.py` — confirm `extracted`, `evidence`, `answer`, `citations`, `cost_usd`, `latency_ms_per_step`, `tool_sequence`, `extraction_confidence` are all present and documented.
- [ ] (S4) Add/refresh tests under `agent-service/tests/` that assert: valid request → 200; invalid `doc_type` → 422; missing secret → 401; stub response contains `tool_sequence`.
- [ ] (S6) Confirm CI eval mode cannot accidentally call live OpenAI — assert `FakeLLMClient` is the boundary in `agent-service/agent_service/eval/runner.py`.
- [ ] (S9) Verify extractor worker retries once on schema mismatch and refuses on repeated failure — add test if missing in `tests/test_extractor_worker.py`.
- [ ] (S11) Add/confirm trace-shape test in `tests/test_graph.py` asserting `tool_sequence` ordering for one lab fixture, one intake fixture, one refusal fixture.

### B. RAG corpus & retrieval (sidecar S7–S8 + week-2-plan §4.7)

- [ ] (S7) Audit `agent-service/agent_service/rag/corpus/` — confirm 50 chunks minimum, each with stable `chunk_id`, `source_url`, `section`, `published`, `text`, no synthetic PHI.
- [ ] (S8) Add/confirm canned-query smoke test in `tests/test_rag.py`: hypertension/diabetes/lipid queries return expected chunk IDs through the BM25 + dense + RRF + rerank pipeline.

### C. Citations / click-to-source UI wiring (sidecar S17–S18 + week-2-plan §4.10)

- [ ] (S17) Add isolated PHP test under `tests/Tests/Isolated/` that asserts uploading a fixture writes the expected citation rows into `form_upload_intake_form_citation` (one row per persisted clinical field for `pdf_bbox`; one row per evidence snippet for `guideline`).
- [ ] (S18) Add isolated PHP test (or Cypress recorded fixture) covering `interface/forms/upload_intake_form/view.php` + `citation_overlay.js` — hover an extracted field, assert overlay renders at the expected `(bbox_x0, bbox_y0, bbox_x1, bbox_y1)`.
- [ ] (S18) Add a side-panel rendering for `guideline` citations that shows `snippet` + a link to `source_url` (assignment §5: "machine-readable + click-to-source").

### D. Lab PDF dispatch (FHIR Observation) — verification (sidecar S16 + week-2-plan §4.4)

- [ ] (S16) Add/confirm a PHPUnit test under `tests/Tests/Unit/` that exercises `src/Services/Agent/Sidecar/Dispatcher/LabPdfDispatcher.php` and asserts: one `procedure_order` row, one `procedure_report` row, N `procedure_result` rows for a fixture lab PDF.
- [ ] (S16) Confirm idempotency: re-dispatching the same `trace_id` + file does not create duplicate rows (test or assertion in dispatcher).
- [ ] (S16) Wet-run verification: upload a lab fixture and run the documented `DESCRIBE`/`SELECT COUNT(*)` checks against `procedure_result`. Capture output to `week-2/cost-latency-report.md` (or a new verification log).

### E. Eval dataset & gate (sidecar S19–S22 + week-2-plan §4.11)

- [ ] (S19) Confirm `agent-service/agent_service/eval/fixtures/` ships 25 lab + 25 intake fixtures with: input file, expected extracted JSON, rubric expectations, recorded fake model output. Add corrupt/empty/missing-data cases for `safe_refusal` if not already present.
- [ ] (S20) Run the eval against the latest baseline and commit a fresh `agent_service/eval/baseline.json` keyed to the submission commit SHA.
- [ ] (S20) Emit a per-rubric pass-rate JSON artifact (e.g., `week-2/eval-results-<sha>.json`) so graders can diff. Wire this into the eval runner's `--out` flag if missing.
- [ ] (week-2-plan §4.11) Tune extractor prompt or schema if any rubric is short of: `schema_valid ≥ 0.90`, `citation_present ≥ 0.90`, `factually_consistent ≥ 0.80`, `safe_refusal ≥ 0.80`, `no_phi_in_logs = 1.00`.
- [ ] (week-2-plan §4.11) Surface latest pass-rate vector somewhere reachable from the demo (eval dashboard page or static artifact link in `README.md`).
- [ ] (week-2-plan §4.11) Add an internal regression-injection assertion (delete-bbox variant) on top of the three existing modes documented in `EVAL.md`.

### F. Observability — surface selection & PHI scrubber (sidecar M16 + week-2-plan §4.12)

- [ ] (week-2-plan §4.12) Pick the surface the demo will show — Honeycomb board vs. local `agent_service/observability/report.py` markdown — and pre-load it (record the chosen surface in `DEMO.md`).
- [ ] (M16) Re-run synthetic-PHI redactor test (`tests/test_observability_redaction.py`) and confirm `no_phi_in_logs = 1.00` on the most recent eval run. Capture the output to `week-2/cost-latency-report.md`.
- [ ] (week-2-plan §4.12) Confirm the encounter event payload includes all required fields: `tool_sequence`, `latency_ms_per_step`, `tokens_in/out`, `cost_usd`, `retrieval_hits`, `extraction_confidence`, `eval_outcome`, `trace_id`. Add the field if any are missing in `agent_service/observability/run_record.py`.

### G. README, deployment guide, env-var docs (sidecar S24 + week-2-plan §4.14)

- [ ] (S24) Audit `README.md` — add a Week 2 section clearly separated from Week 1 (assignment requires this) with a "Deploy in 10 minutes" block naming every env var: `OPENAI_API_KEY`, `COHERE_API_KEY`, `AGENT_SHARED_SECRET` / `OPENEMR_AGENT_SIDECAR_SECRET`, `HONEYCOMB_API_KEY`, `OPENEMR_AGENT_SIDECAR_URL`.
- [ ] (S24) Document the worker rename in `README.md` or `W2_ARCHITECTURE.md`: assignment says "intake-extractor"; the code uses `document-extractor` (handles both `lab_pdf` and `intake_form`).
- [ ] (S24) Confirm GitLab remote is current with master and the submission link points to it (Q1 says GitLab is the official submission, GitHub is mirror). Use `git remote -v` to enumerate; push master to all remotes.
- [ ] (S24) Document the Railway setup in `week-2/SETUP.md` — replace `_TODO: insert deployed URL when published_` with the live URL once Railway exposes one.

### H. Cost & latency report (sidecar S25 + week-2-plan §4.17)

- [ ] (S25) Re-run `python -m agent_service.observability.report --out ../week-2/cost-latency-report.md` after the final eval pass so dev-spend, p50/p95, and projected 100/1000/10000-docs/day numbers are current.
- [ ] (S25) Confirm the report contains: actual dev spend, projected cost at 100/1000/10000 docs/day, p50/p95 latency per step (extractor, retriever, supervisor turn), 1–2 paragraph bottleneck analysis. Add any missing section to `agent_service/observability/report.py`.
- [ ] (S25) Re-run PHI scan on the generated report and confirm zero leakage.

### I. Deployment — Railway smoke (sidecar S26 + week-2-plan §4.14)

- [ ] (S26) Re-run `deploy-railway.ps1` (or trigger via Railway CLI from a linked checkout) and confirm the OpenEMR Railway URL is reachable.
- [ ] (S26) Confirm the sidecar service is reachable from inside the Railway project (check `OPENEMR_AGENT_SIDECAR_URL` resolves and `/healthz` returns OK).
- [ ] (S26) Smoke-test one full upload → extraction → citation overlay in the deployed instance. Capture a fresh screenshot to replace `week-2/openemr-prod-after-deploy.png` if stale.
- [ ] (S26) Paste the live URL into `README.md` and `week-2/SETUP.md`.

### J. Clinical Co-Pilot migration — verification only (Migration M22–M25, all DONE in code)

The M-series migration steps M0–M25 are all marked `Done` in the recovered
plan and the corresponding code paths exist (`src/Services/Agent/Sidecar/`,
`agent-service/agent_service/intents/`, `tools/`, `verifier/`,
`proposals/`; legacy `AgentLlmOrchestrator.php` / `AgentAnswerVerifier.php`
have been removed). Remaining backlog is verification-only:

- [ ] (M22) Run `python -m agent_service.eval --suite copilot-tools` and confirm rubrics still gate disallowed-tool / missing-citation / unsafe-advice / PHI-log regressions.
- [ ] (M23) Confirm `Copilot Migration` GitHub Actions workflow (`.github/workflows/copilot-migration.yml`) still passes all 5 jobs: `python-unit-tests`, `sidecar-contract-parity`, `php-isolated-agent-tests`, `copilot-tools-eval`, `migration-regression-injection`.
- [ ] (M25) Walk the full `M-changes-UI-tests.md` checklist against the deployed instance after the Railway URL is live (the checklist itself is agent-doable via Cypress / browser MCP once the URL exists).
- [ ] (M-changes) Run `M-changes-tests.ps1` end-to-end and capture the output.

### K. Cleanup & doc cross-links

- [ ] Replace stale references to `EVAL_REGRESSION_PROOF.md` with `EVAL.md` (the actual filename) wherever they appear.
- [ ] Replace references to `[`week-2/W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md)` with the correct repo-root path `[`../W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md)` in `week-2/` markdown files.
- [ ] Add an `intake-forms.md` cross-link from `W2_ARCHITECTURE.md` §9 ("Reuse from intake-forms feature") if not already present.
- [ ] Confirm the 9 diagrams referenced in `W2_ARCHITECTURE.md` (`01-component-overview` … `09-eval-rubric-data-flow`) all live under `diagrams/` and render in the SVG fallback (`gen_svg.py`).
