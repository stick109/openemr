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

## Checklist

### 1. Login

- [ ] Browse to http://localhost:8300/
- [ ] Log in as `admin` / `pass`

### 2. Patient and encounter setup

- [ ] Open a (synthetic/demo) patient with at least: 2 active medications,
      1 active allergy, 1 active problem, 2 recent encounters
- [ ] Open an encounter

### 3. Read-only intents (M19, M20)

All six read-only intents now route through the sidecar by default — no
env-var flipping needed. Walk through each one:

- basic_patient_data
- current_medications
- allergies_to_confirm
- recent_events
- changed_since_last_visit
- show_source

For each one:

- [ ] Click the intent button in the Co-Pilot panel
- [ ] Response renders within ~5 seconds
- [ ] Answer blocks are populated (heading + claim text)
- [ ] At least one citation chip is shown next to claims that need them
- [ ] Hovering a citation chip shows the source label (medication name,
      allergy substance, encounter date, etc.)
- [ ] Clicking a citation chip opens the source drilldown panel and
      shows bounded source detail (record body excerpt, occurred_at)

### 4. Source drilldown specific cases (M11)

- [ ] Open the panel and select a previously-cited source — bounded detail
      renders, no PDF/raw text spill
- [ ] Manually craft a URL with a citation_id from a different patient's
      record (e.g., copy a citation_id from another patient's panel) — the
      drilldown shows an "unauthorized" or empty-state message, NOT another
      patient's data
- [ ] Drilldown for a malformed citation_id shows a graceful error state

### 5. Two-phase write proposal (M21)

Pre-req: an uploaded lab PDF that the sidecar's extractor produced an
observation for, for `current_medications`.

- [ ] Trigger a write proposal flow (sidecar tool
      `persist_lab_observation_proposal` returns a typed proposal)
- [ ] PHP commit endpoint `POST /apis/api/agent/proposals/commit` accepts
      a valid signed run_context + proposal — confirm via browser devtools
      Network tab or a curl with valid token
- [ ] Replay the same commit (same `idempotency_key`) — server returns the
      previous result, no double-write in the procedure_order table
- [ ] Cross-patient citation_id in proposal → 422

### 6. Per-tool-call observability (M16)

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

### 7. CI workflow visibility (M23)

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
