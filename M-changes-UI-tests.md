# M-changes-UI-tests

Manual UI checklist for the Clinical Co-Pilot migration to Python sidecar
(steps M0..M25). Companion to `S-changes-UI-tests.md` (which covers Week 2
sidecar UI: lab PDF + intake form + click-to-source).

Run `M-changes-tests.ps1` first to validate everything cmdline-testable.
Then walk through this checklist in the deployed app.

## Preconditions

- Docker stack up and healthy:

    ```powershell
    docker compose --project-name openemr ps
    ```

  Both `openemr` and `agent-service` should be `running (healthy)`.

- If the demo container is not running, bring it up fresh:

    ```powershell
    docker compose --project-name openemr down -v
    docker compose --project-name openemr up -d
    ```

  Wait until `docker compose ps` shows openemr + agent-service healthy.

- The agent-service container must read the same `AGENT_SHARED_SECRET` as the
  PHP container. `docker compose config` should show the same value mounted
  in both. The PHP minter (M3) uses key version `v1`.

- Default routing mode is `php` (legacy path). Per-intent overrides are set
  via env vars matching `OPENEMR_COPILOT_INTENT_MODE_<UPPER_INTENT_ID>`.

## Checklist

### 1. Login

- [ ] Browse to https://localhost:9300/
- [ ] Log in as `admin` / `pass`

### 2. Patient and encounter setup

- [ ] Open a (synthetic/demo) patient with at least: 2 active medications,
      1 active allergy, 1 active problem, 2 recent encounters
- [ ] Open an encounter

### 3. Default mode (PHP path) — sanity that legacy still renders

(With no `OPENEMR_COPILOT_INTENT_MODE_*` and no `OPENEMR_COPILOT_DEFAULT_MODE`
set: every intent goes through the PHP path that delegates to the sidecar via
M17 only when the appropriate flags are flipped. With nothing flipped, the
legacy PHP UI is gone after M24 — the controller only knows `php`/`shadow`/
`sidecar` modes. Confirm the controller still works in `php` mode.)

- [ ] Open the **Clinical Co-Pilot** panel (sidebar / encounter UI)
- [ ] Click **"Basic patient data"** intent button
- [ ] Confirm a response renders (it may be a placeholder/legacy-fallback
      shape since M24 removed the live PHP answer-builder, but the UI must
      not crash)

### 4. Sidecar mode — read-only intents (M19, M20)

For each of the six read-only intents, set `OPENEMR_COPILOT_INTENT_MODE_<ID>=sidecar`
in the OpenEMR container's env (or `.env` file used by Compose), restart
the openemr container, and exercise the button:

| Intent | Env var to flip |
|---|---|
| basic_patient_data | `OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA=sidecar` |
| current_medications | `OPENEMR_COPILOT_INTENT_MODE_CURRENT_MEDICATIONS=sidecar` |
| allergies_to_confirm | `OPENEMR_COPILOT_INTENT_MODE_ALLERGIES_TO_CONFIRM=sidecar` |
| recent_events | `OPENEMR_COPILOT_INTENT_MODE_RECENT_EVENTS=sidecar` |
| changed_since_last_visit | `OPENEMR_COPILOT_INTENT_MODE_CHANGED_SINCE_LAST_VISIT=sidecar` |
| show_source | `OPENEMR_COPILOT_INTENT_MODE_SHOW_SOURCE=sidecar` |

For each one:

- [ ] Click the intent button in the Co-Pilot panel
- [ ] Response renders within ~5 seconds
- [ ] Answer blocks are populated (heading + claim text)
- [ ] At least one citation chip is shown next to claims that need them
- [ ] Hovering a citation chip shows the source label (medication name,
      allergy substance, encounter date, etc.)
- [ ] Clicking a citation chip opens the source drilldown panel and
      shows bounded source detail (record body excerpt, occurred_at)

### 5. Source drilldown specific cases (M11)

- [ ] With **show_source** in sidecar mode, open the panel and select a
      previously-cited source — bounded detail renders, no PDF/raw text spill
- [ ] Manually craft a URL with a citation_id from a different patient's
      record (e.g., copy a citation_id from another patient's panel) — the
      drilldown shows an "unauthorized" or empty-state message, NOT another
      patient's data
- [ ] Drilldown for a malformed citation_id shows a graceful error state

### 6. Shadow mode (M18)

Set `OPENEMR_COPILOT_INTENT_MODE_BASIC_PATIENT_DATA=shadow` (legacy answer
shown to user, sidecar called in parallel for comparison). Restart openemr.

- [ ] Click "Basic patient data"
- [ ] User-visible response is the LEGACY-style answer (shadow returns the
      legacy build path's answer to the UI)
- [ ] In `docker compose --project-name openemr logs openemr` look for an
      INFO entry tagged `Sidecar shadow comparison` with PSR-3 context fields:
      `trace_id`, `intent_id`, `verification_status_match`,
      `cited_source_ids_match`, `php_cited_count`, `sidecar_cited_count`,
      `headings_match`
- [ ] Confirm the shadow log entry has NO claim text, evidence body, or
      patient identifiers beyond `trace_id` + `intent_id`

### 7. Emergency disable (M19)

Set `OPENEMR_COPILOT_EMERGENCY_DISABLE=1` while individual intents have
`...=sidecar`. Restart openemr.

- [ ] Click an intent button
- [ ] The route uses the legacy/PHP code path regardless of per-intent mode
- [ ] Unset `OPENEMR_COPILOT_EMERGENCY_DISABLE` (or set to 0) — sidecar mode
      resumes per the per-intent settings

### 8. Two-phase write proposal (M21)

Pre-req: an uploaded lab PDF that the sidecar's extractor produced an
observation for, in shadow or sidecar mode for `current_medications`.

- [ ] Trigger a write proposal flow (sidecar tool
      `persist_lab_observation_proposal` returns a typed proposal)
- [ ] PHP commit endpoint `POST /apis/api/agent/proposals/commit` accepts
      a valid signed run_context + proposal — confirm via browser devtools
      Network tab or a curl with valid token
- [ ] Replay the same commit (same `idempotency_key`) — server returns the
      previous result, no double-write in the procedure_order table
- [ ] Cross-patient citation_id in proposal → 422

### 9. Per-tool-call observability (M16)

In another terminal:

```powershell
docker compose --project-name openemr logs -f agent-service
```

- [ ] Click any sidecar-mode intent button
- [ ] Logs show the event sequence:
      `run.received` → `model.turn.started` → `tool.started` →
      `tool.finished` → `model.turn.finished` → `verifier.finished` →
      `response.returned`
- [ ] Each event has `trace_id`, `latency_ms` where applicable
- [ ] No event contains: full names, DOB, address, MRN, phone, email,
      document text, raw evidence body, prompt text

### 10. CI workflow visibility (M23)

- [ ] In the GitHub repo, open Actions → confirm `Copilot Migration` workflow
      ran on the latest push
- [ ] Confirm all 5 jobs pass:
      `python-unit-tests`, `sidecar-contract-parity`, `php-isolated-agent-tests`,
      `copilot-tools-eval`, `migration-regression-injection`

## Pass criteria

All checkboxes above marked. If any item fails:

1. Capture the screen state and the relevant log excerpt
2. Note which env-var combination was active
3. Triage before recording the demo video

After all items pass, the migration is verified end-to-end through the UI.
