# Week 2 — Demo Run-of-Show

Hard budget: 5:00. Six segments matching Week-2-Assignment.pdf §"Demo Video" exactly.
Synthetic/demo data only. No real names, DOBs, MRNs, or raw PHI on screen.

## Pre-demo checklist (do this before hitting record)

- [ ] Docker stack up: `docker compose ps` — all services healthy
- [ ] `agent-service` running, `/health` green; eval gate currently passing
- [ ] Lab PDF + intake fixtures ready at `tests/fixtures/copilot/` (paths memorized)
- [ ] Demo patient + encounter pre-opened in tab 1 (synthetic record)
- [ ] Tab 2: Honeycomb trace URL preloaded · Tab 3: terminal ready for eval run
- [ ] Browser zoomed for legibility, dev tools / bookmarks / `.env` hidden
- [ ] Mic check, recorder capturing 1080p, screen layout: app full-window, terminal corner
- [ ] Live retrieval = Cohere Rerank; CI = deterministic fake reranker (mention if asked)

## Run-of-show

### 0:00–0:45  ·  Document upload  (lab PDF + intake form)
- **Show:** Demo encounter already open. Patient banner visible, synthetic only.
- **Click:** Clinical Co-Pilot upload entry → doc type "Lab PDF" → select fixture.
- **Then:** Repeat with intake form fixture; same upload path.
- **Watch for:** Both files land in the encounter, processing spinner kicks off.
- **Say:** "Two documents — a lab PDF and an intake form — uploaded inside the encounter; the sidecar takes it from here."

### 0:45–1:30  ·  Extraction  (strict schema, citations attached)
- **Show:** Extracted lab panel populates with structured fields, not free text.
- **Point at:** Test name, value, unit, reference range, collection date, abnormal flag.
- **Then:** Open the response payload (or panel inspector) — show the JSON conforms to the strict schema and every field carries source metadata.
- **Watch for:** Schema-valid JSON, no nulls in required fields, citation IDs present.
- **Say:** "Extraction is strict-schema JSON — every field is typed, and every field carries a citation."

### 1:30–2:15  ·  Evidence retrieval  (hybrid RAG + rerank)
- **Trigger:** Co-pilot summary/recommendation on the encounter.
- **Show:** Evidence panel — top-K guideline snippets retrieved, reranked, with scores.
- **Point at:** At least one snippet showing source doc + section + similarity/rerank score.
- **Watch for:** Snippets are guideline excerpts (not patient text), rerank reordered them.
- **Say:** "Hybrid retrieval — BM25 plus embeddings, reranked with Cohere — patient facts and guideline evidence stay in separate lanes."

### 2:15–3:00  ·  Citations  (machine-readable + click-to-source)
- **Click:** A citation chip on one extracted lab field.
- **Show:** PDF source overlay opens with bbox highlight on the originating text.
- **Then:** Toggle to a guideline citation — show source URL/section is machine-readable in the payload.
- **Watch for:** Bbox lands on the right text, payload shows `{source, page, bbox, span}`.
- **Say:** "Every claim clicks through to its source — bbox overlay for PDFs, structured refs for guidelines."

### 3:00–4:00  ·  Eval results  (50-case rubrics, the Hard Gate)
- **Switch to:** Terminal. Run the eval (or open the saved run output).
- **Show:** 50-case run completes with per-rubric pass rates.
- **Point at thresholds:** `schema_valid ≥ 0.90`, `citation_present ≥ 0.90`, `factually_consistent ≥ 0.80`, `safe_refusal ≥ 0.80`, `no_phi_in_logs = 1.00`.
- **Then:** Show the saved regression-injection failure proving the gate blocks bad changes (or pre-push hook output).
- **Say:** "Fifty cases, five boolean rubrics, blocking gate — bad changes don't merge."

### 4:00–5:00  ·  Observability  (tool sequence, latency, cost — no PHI)
- **Open:** Honeycomb trace tab for the demo encounter.
- **Show:** `tool_sequence` span, per-step latency, retrieval hits, `tokens_in` / `tokens_out`, `cost_usd`.
- **Point at:** Sanitized attributes — no document text, no identifiers in span fields.
- **Mention:** Durable cost/latency/eval records live in OpenEMR's MariaDB, not Honeycomb.
- **Say:** "Full tool trace, latency, and token cost per call — sanitized at the export boundary, no PHI leaves the box. Done."

## Reset / recovery (if something breaks mid-demo)

- **Stack restart:** `docker compose -f docker/development-easy/docker-compose.yml restart`
- **Re-seed demo patient:** `docker compose exec openemr /root/devtools seed-demo-patient`
- **Clear browser session:** new incognito window, re-login `admin` / `pass`
- **Cohere unavailable:** narrate the fallback — CI uses fake reranker, eval still passes; show saved trace instead of live one.
- **UI overlay glitch:** show the citation payload JSON directly — same evidence, no theatrics.
