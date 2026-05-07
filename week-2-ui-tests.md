# Week 2 — UI Tests

Comprehensive, self-contained manual UI test checklist for the Week 2 Clinical
Co-Pilot. Mirrors the six demo segments from Week-2-Assignment.pdf and adds the
sidecar-migration verifications needed before recording the demo.

For the demo run-of-show, see [week-2/DEMO.md](week-2/DEMO.md).

## Preconditions

- [ ] Docker stack up: `docker compose --project-name openemr ps` — both
      `openemr` and `agent-service` show `running (healthy)`
- [ ] agent-service `/healthz` returns 200 (curl from host)
- [ ] Sample lab PDF at `tests/fixtures/copilot/lab-*.pdf`
- [ ] Sample intake form at `intake-forms/intake-Demographics-<pid>-*.pdf`
      (regenerate via `./generate-intake-form.ps1` if absent)
- [ ] Logged in as `admin` / `pass` at https://localhost:9300/
- [ ] Demo patient pre-opened (synthetic data only) with at least:
      2 active medications, 1 active allergy, 1 active problem, 2 recent
      encounters — needed by the read-only intent checks below
- [ ] One encounter for the demo patient is open
- [ ] `agent-service` and `openemr` containers share `AGENT_SHARED_SECRET`
      (verify with `docker compose --project-name openemr config` —
      same value mounted in both, PHP minter uses key version `v1`)
- [ ] Browser dev tools open to **Console** + **Network** tabs (PHI watch)
- [ ] In a side terminal: `docker compose --project-name openemr logs -f agent-service`

## 1. Document upload  (lab PDF + intake form)

- [ ] In the open encounter, click **Add Form** in the encounter forms list
- [ ] Confirm **"Upload Document (Co-Pilot)"** appears in the picker
- [ ] Choose **"Upload Document (Co-Pilot)"** → select the lab PDF fixture
- [ ] Form row appears on the encounter timeline; processing spinner kicks off
- [ ] In the encounter forms navbar, click **Administrative → Upload Intake Form**
- [ ] Pick the intake-form PDF; choose form type `Auto-detect`; click **Upload**
- [ ] After ~1–2s the inner iframe (`enctabs-1001`) renders `Redirecting…`
      (success), not the friendly error page
- [ ] Both files now visible in encounter timeline with status `extracted`

## 2. Extraction  (strict-schema JSON, citations attached)

- [ ] Open the lab-PDF form row from the encounter timeline
- [ ] Right-hand pane populates with **structured fields**, not free text:
      test name, value, unit, reference range, collection date, abnormal flag
- [ ] Open browser **Network** tab → inspect the extraction response payload
- [ ] JSON conforms to strict schema: required fields present, no nulls in
      required keys, every observation carries a `citation_id`
- [ ] For the intake form: confirm `form_upload_intake_form` row appears in
      the encounter form list with `Intake form uploaded: <type>`
- [ ] No `Could not auto-detect form type` or `Invalid file data` console error

## 3. Evidence retrieval  (hybrid RAG + rerank)

- [ ] In the Co-Pilot panel, click an intent that triggers retrieval
      (e.g. `current_medications` or a summary/recommendation button)
- [ ] Evidence panel shows top-K guideline snippets with rerank scores
- [ ] At least one snippet shows **source doc + section + similarity score**
- [ ] Snippets are guideline excerpts (not patient-record text) — confirm the
      patient/guideline lanes are not crossed
- [ ] Rerank reordered results vs raw BM25 (visible score column or order)
- [ ] No raw embedding vectors or unsanitized prompts visible in network panel

## 4. Citations  (machine-readable + click-to-source + PDF bbox overlay)

- [ ] Click any citation chip on an extracted lab field
- [ ] **PDF source overlay opens** (pdf.js, left pane); right pane shows the
      extracted field
- [ ] **Hover** a different extracted field — bbox overlay appears over the
      PDF region the value came from
- [ ] **Click** that extracted field — PDF scrolls to the page and the bbox
      flashes
- [ ] Inspect the citation payload in Network tab — JSON includes
      `{source, page, bbox, span}`, machine-readable
- [ ] Toggle to a **guideline citation chip** — side panel opens with snippet
      + source URL/section, also machine-readable in the payload
- [ ] Cross-patient citation_id (manually craft URL with citation_id from a
      different patient) → drilldown shows unauthorized / empty state, NOT
      another patient's data

## 5. Eval results  (50-case rubrics, regression gate)

- [ ] In a terminal, run the eval suite (or open the saved run output)
- [ ] 50-case run completes; per-rubric pass rates printed
- [ ] Confirm thresholds met:
      `schema_valid ≥ 0.90`, `citation_present ≥ 0.90`,
      `factually_consistent ≥ 0.80`, `safe_refusal ≥ 0.80`,
      `no_phi_in_logs = 1.00`
- [ ] Show the saved regression-injection failure (or pre-push hook output)
      proving the gate blocks bad changes
- [ ] In GitHub Actions: `Copilot Migration` workflow ran on latest push;
      all 5 jobs pass (`python-unit-tests`, `sidecar-contract-parity`,
      `php-isolated-agent-tests`, `copilot-tools-eval`,
      `migration-regression-injection`)

## 6. Observability  (tool sequence, latency, cost, no PHI)

- [ ] In the side terminal tailing `agent-service` logs, click any intent
- [ ] Event sequence appears in order:
      `run.received` → `model.turn.started` → `tool.started` →
      `tool.finished` → `model.turn.finished` → `verifier.finished` →
      `response.returned`
- [ ] Each event has `trace_id` and `latency_ms` where applicable
- [ ] **PHI scan the log lines** — none of the following appear in plaintext:
      patient full name, DOB, MRN, phone, email, street address,
      raw document text, raw evidence body, prompt text
- [ ] Honeycomb trace tab shows `tool_sequence` span with per-step latency,
      retrieval hits, `tokens_in` / `tokens_out`, `cost_usd`
- [ ] Span attributes are sanitized — no document text or identifiers in
      span fields

## 7. Read-only intents  (sidecar-routed, all 6 buttons)

All six read-only intents route through the sidecar by default — no env-var
flipping needed. Walk through each one:

- `basic_patient_data`
- `current_medications`
- `allergies_to_confirm`
- `recent_events`
- `changed_since_last_visit`
- `show_source`

For each intent button:

- [ ] Click the intent button in the Co-Pilot panel
- [ ] Response renders within ~5 seconds
- [ ] Answer blocks populate (heading + claim text)
- [ ] At least one citation chip appears next to claims that need them
- [ ] Hovering a citation chip shows the source label (medication name,
      allergy substance, encounter date, etc.)
- [ ] Clicking a citation chip opens the source drilldown panel with
      bounded source detail (record body excerpt, occurred_at) — no PDF
      or raw-text spill
- [ ] Drilldown for a malformed `citation_id` shows a graceful error
      state (not a stack trace, not another patient's data)

## 8. Write proposal  (two-phase, idempotent, scoped)

Pre-req: a lab PDF already uploaded to the demo patient (segment 1) and the
sidecar's extractor produced an observation usable by `current_medications`.

- [ ] Trigger a write-proposal flow — sidecar tool
      `persist_lab_observation_proposal` returns a typed proposal payload
- [ ] PHP commit endpoint `POST /apis/api/agent/proposals/commit` accepts a
      valid signed `run_context` + proposal — confirm via Network tab or a
      curl with a valid token
- [ ] Replay the same commit (same `idempotency_key`) — server returns the
      previous result; `procedure_order` table shows no double-write
- [ ] Submit a proposal whose `citation_id` belongs to a different patient →
      server returns **422** (cross-patient rejection works)

## Pass criteria

- All checkboxes ticked
- No JS console errors (Console tab clean across all eight segments)
- No raw PHI in `agent-service` logs (verified by tailing during the run)
- No surprising cross-origin or third-party network calls in dev tools
- If any item fails: capture screen state + relevant log excerpt and triage
  before recording the demo video
