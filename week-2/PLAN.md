# Week 2 — Plan & Open TODO

Single source of truth for what's left. Done items are not tracked here by design.

Status legend: `TODO` · `IN-PROGRESS` · `DONE` · `BLOCKED`

Source plans (kept for context, not duplicated here):
[`week-2-plan.md`](week-2-plan.md), [`intake-forms-plan.md`](intake-forms-plan.md),
[`sidecar-detailed-steps.md`](sidecar-detailed-steps.md),
[`Clinical Co-Pilot Migration to Python Sidecar.md`](Clinical%20Co-Pilot%20Migration%20to%20Python%20Sidecar.md).

---

## 1. Demo readiness — the 6 moments the demo must show

Friday demo, ≤5 minutes, must hit these six in order (per Week-2-Assignment.pdf).

- [x] **Document upload** (lab PDF + intake form) — `DONE`. Lab Report enum + form proxies into sidecar; `agent-service` runs healthy in compose. Nothing left.

- [x] **Extraction** (strict-schema JSON) — `DONE`. Pydantic `LabPdf` / `IntakeForm` / `Citation` schemas + extractor worker landed; OpenAI tool-choice client wired. Nothing left.

- [x] **Evidence retrieval** (hybrid RAG + rerank) — `DONE`. BM25 + dense + RRF + Cohere rerank pipeline + deterministic CI faker shipped under `agent-service/agent_service/rag/`. Nothing left.

- [ ] **Citations** (machine-readable + click-to-source / PDF bbox overlay) — `IN-PROGRESS`. Citation table, `CitationPersistenceService`, `citation_overlay.js`, and `view.php` integration are landed.
  - [ ] Live wet-run: hover one extracted lab field, confirm bbox draws over the source page.
  - [ ] Live wet-run: click guideline citation, confirm side panel shows snippet + `source_url`.

- [ ] **Eval results** (50-case rubrics, pass rates visible in UI) — `IN-PROGRESS`. 50 fixtures, runner, baseline, GHA workflow, pre-push hook, regression-injection proof all landed.
  - [ ] Surface latest pass-rate vector somewhere reachable from the demo (eval dashboard page or static artifact link in README).
  - [ ] Confirm `no_phi_in_logs = 1.00` on the most recent run before recording the demo.

- [ ] **Observability** (tool sequence, latency, token cost, no PHI) — `IN-PROGRESS`. Per-tool spans, redactor, run records, and PHI scanner shipped.
  - [ ] Pick the surface the demo will show — Honeycomb board vs. local `observability/report.py` markdown — and pre-load it.
  - [ ] Smoke-test the redactor with one synthetic-PHI injection right before recording.

---

## 2. Submission deliverables (Week-2-Assignment.pdf p.5–6)

- [ ] **GitLab repo** — setup guide + deployed link + env-var docs in `README.md`.
  - [ ] Confirm GitLab remote is current with master and the submission link points to it (Q1 says GitLab is the official submission, GitHub is mirror).
  - [ ] README "Deploy in 10 minutes" block names every env var: `OPENAI_API_KEY`, `COHERE_API_KEY`, `AGENT_SHARED_SECRET`, `HONEYCOMB_API_KEY`, `AGENT_SERVICE_URL`.

- [x] **`./W2_ARCHITECTURE.md`** at repo root — `DONE`. File at [`week-2/W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md), 11 sections, embeds 9 drawio diagrams.

- [x] **Schemas (Pydantic) + validation tests** for `lab_pdf` and `intake_form` — `DONE`. `agent_service/schemas/` + `tests/test_schemas.py`.

- [ ] **Eval dataset** — 50 cases + judge config + results artifact.
  - [ ] Commit a fresh `baseline.json` + per-rubric pass-rate JSON tagged with the submission commit SHA so graders can diff.

- [x] **CI evidence: PR-blocking Git Hook regression-block proof** — `DONE`. Pre-push hook + `.github/workflows/agent-eval.yml` + three regression-injection modes documented in [`EVAL_REGRESSION_PROOF.md`](EVAL_REGRESSION_PROOF.md).

- [ ] **Demo video** (3–5 min) — `TODO`. No video artifact in repo yet.
  - [ ] Record following the script in `week-2-plan.md` §4.16 (encounter → upload lab PDF → extraction → click-to-source → upload intake → eval dashboard → break-and-show-CI-fail → observability board).
  - [ ] Upload to a durable host and link from README.

- [ ] **Cost & latency report** — `IN-PROGRESS`. [`cost-latency-report.md`](cost-latency-report.md) is checked in; refresh before submission.
  - [ ] Re-run `python -m agent_service.observability.report --out ../week-2/cost-latency-report.md` after the final eval pass so dev-spend, p50/p95, and projected 100/1000/10000-docs/day numbers are current.

- [ ] **Deployed app** (publicly accessible) — `IN-PROGRESS`. Railway assets present (`railway.toml`, `Dockerfile.railway`, `deploy-railway.ps1`, `.railwayignore`); a screenshot named `openemr-prod-after-deploy.png` exists.
  - [ ] Confirm the Railway OpenEMR URL is currently up and the sidecar is reachable from inside the Railway project (graders will hit it cold).
  - [ ] Smoke-test one full upload → extraction → citation overlay in the deployed instance.
  - [ ] Paste the live URL into README.

---

## 3. Open risks / unresolved decisions

- **Demographics-merge UX (Q3 in `week-2-plan.md` §7)** — still unresolved. Week 2 ships fill-only-empty; "review-and-confirm" remains a stretch goal. Decide before demo whether to call this out as deliberate or stretch.

- **Worker rename (Q8)** — code uses `document-extractor` for both labs and intakes; assignment text says "intake-extractor". Confirm the docs explicitly note the rename so reviewers don't flag it as missing.

- **Cost balloon during eval runs** — mitigation in place (`gpt-4o-mini`, fixture cache, fake client in CI). Watch dev-spend before the final eval pass.

- **Supervisor-as-black-box pitfall** — mitigated by deterministic supervisor + `tool_sequence` assertions. Confirm the trace-shape test still asserts the expected ordering after recent `M13`/`M14` follow-up commits.

- **PHI leak via observability SaaS (Q5)** — Honeycomb only gets sanitized traces; durable token/cost/eval records live in OpenEMR's MySQL. Re-run the synthetic-PHI redactor test (`test_observability_redaction.py`) right before recording the demo.

- **Cypress E2E flakiness against the live sidecar** — risk flagged in `week-2-plan.md` §8. Keep E2E mocked against a recorded fixture; do not point CI Cypress at the live agent service.

- **Eval gate too lenient** — graders will inject a regression. The three injection modes (`drop-citations`, `wrong-value`, `flip-abnormal-flags`) are proven to fire the gate. No action unless thresholds drift.
