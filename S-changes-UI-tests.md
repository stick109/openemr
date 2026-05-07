# S-changes-UI-tests

Manual UI checklist for Week 2 sidecar work. These checks complement
`S-changes-tests.ps1` and cover steps that require a browser and visual
verification (S14, S18, and the user-facing parts of S26).

Run `S-changes-tests.ps1` first to validate everything cmdline-testable.
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

## Checklist

### 1. Login

- [ ] Browse to https://localhost:9300/
- [ ] Log in as `admin` / `pass`

### 2. Patient and encounter setup

- [ ] Open a (synthetic/demo) patient
- [ ] Open an encounter

### 3. Form picker (S14 + Option B)

- [ ] Click "Add Form" in the encounter
- [ ] Confirm **"Upload Document (Co-Pilot)"** appears in the picker

If it does not appear and Option B has not landed yet, register the form
manually: *Admin → Forms → Forms Administration*, find "Upload Document
(Co-Pilot)" in the unregistered list, click **Register**.

### 4. Lab PDF upload and extraction

- [ ] Choose **"Upload Document (Co-Pilot)"**
- [ ] Upload one of the synthetic lab PDFs you keep for demos
      (e.g., generated via `generate-lab-pdf.ps1`)
- [ ] Confirm processing completes without errors
- [ ] Confirm the form row appears on the encounter timeline

### 5. PDF overlay UI (S18)

- [ ] Open the form row — the view page renders
- [ ] PDF appears on the left (pdf.js)
- [ ] Extracted fields appear on the right
- [ ] **Hover** an extracted field — bbox overlay appears over the PDF
      region the value came from
- [ ] **Click** an extracted field — PDF scrolls to the page and flashes
      the overlay
- [ ] **Click** a guideline citation chip — side panel opens showing the
      snippet and source URL

### 6. Intake form regression

- [ ] (Optional) Upload one intake form fixture
- [ ] Confirm the legacy intake path still works
      (no errors, form appears in encounter)

### 7. Sidecar logs and observability

- [ ] In another terminal:

    ```powershell
    docker compose --project-name openemr logs -f agent-service
    ```

- [ ] `tool_sequence`, latency, and cost fields appear in logs
- [ ] No raw PHI in log lines (no patient names, no SSN-like strings)

## Pass criteria

All checkboxes above marked. If any item fails, capture the screen state
and the relevant log excerpt and triage before recording the demo video.
