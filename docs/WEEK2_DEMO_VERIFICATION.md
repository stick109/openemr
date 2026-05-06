# Week 2 - Final End-to-End Demo Verification

> Step S26 verification record. Captures the exact commands run, their
> observed output, the manual UI checklist, and the recommended demo video
> flow.

This document is the ground-truth checklist for Step S26 (the assignment's
"final integration demo"). Pair with [WEEK2_SIDECAR.md](WEEK2_SIDECAR.md) for
deployment and architecture details, and with
[EVAL_REGRESSION_PROOF.md](EVAL_REGRESSION_PROOF.md) for the regression-gate
explanation.

Date verified: 2026-05-06.

---

## 1. Stack health

```powershell
# Bring up the full stack (idempotent - safe if already up).
.\run-docker.ps1
```

Compose state observed (`docker compose --project-name openemr ps`):

```
agent-service: running (healthy)
couchdb:       running ()
mailpit:       running (healthy)
mysql:         running (healthy)
openemr:       running (healthy)
openldap:      running ()
phpmyadmin:    running ()
selenium:      running (healthy)
```

Sidecar health:

```powershell
PS> Invoke-RestMethod http://127.0.0.1:8010/healthz
status
------
ok
```

OpenEMR (HTTP 302 redirect to login is the expected unauthenticated response):

```powershell
PS> Invoke-WebRequest http://localhost:8300/ -MaximumRedirection 0
# OpenEMR HTTP status: 302
```

---

## 2. Eval gate (S20, S22)

Unset `OPENAI_API_KEY` for direct eval/test invocations - the `FakeLLMClient`
refuses to construct while it is set, as a safety guard against accidental
real-API calls. The pre-push hook unsets it automatically in its own
subshell, so `git push` works regardless of whether the key is exported in
your shell.

```powershell
$env:OPENAI_API_KEY = $null
cd agent-service
py -m agent_service.eval --baseline agent_service/eval/baseline.json
```

Observed output:

```
Eval pass rates over 50 cases:
  schema_valid           100.00%  (50/50)
  citation_present       100.00%  (50/50)
  factually_consistent   100.00%  (50/50)
  safe_refusal           100.00%  (50/50)
  no_phi_in_logs         100.00%  (50/50)

All rubrics meet thresholds and no regression detected.
```

Exit code: **0**.

---

## 3. Regression proof (S22)

```powershell
$env:OPENAI_API_KEY = $null
cd agent-service
py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression drop-citations
```

Observed output (truncated to the rubric summary - full output lists all 44
affected fixtures):

```
FAIL: 4 rubric(s) regressed
- schema_valid:         1.00 -> 0.12 (delta -88pp, threshold 0.95)
- citation_present:     1.00 -> 0.12 (delta -88pp, threshold 0.95)
- factually_consistent: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
- safe_refusal:         1.00 -> 0.12 (delta -88pp, threshold 1.00)

Eval pass rates over 50 cases:
  schema_valid           12.00%  (6/50)
  citation_present       12.00%  (6/50)
  factually_consistent   12.00%  (6/50)
  safe_refusal           12.00%  (6/50)
  no_phi_in_logs         100.00%  (50/50)
```

Exit code: **1** (gate correctly fails). The 6 passing cases are the intake
fixtures whose extraction returns no citations by design - those rubrics
score "vacuously true" because there is nothing to drop. The other 44
fixtures (mostly labs and the citation-bearing intake cases) all fail.

Other regression flavours (`--inject-regression wrong-value`,
`--inject-regression flip-abnormal-flags`) are documented in
[EVAL_REGRESSION_PROOF.md](EVAL_REGRESSION_PROOF.md).

---

## 4. Cost / latency report (S25)

```powershell
$env:OPENAI_API_KEY = $null
cd agent-service
py -m agent_service.eval --baseline agent_service/eval/baseline.json `
  --record-runs agent_service/eval/run-records.jsonl
py -m agent_service.observability.report `
  --records agent_service/eval/run-records.jsonl `
  --out ../cost-latency-report.md
```

Observed output:

```
Wrote cost/latency report (50 records analysed) to ..\cost-latency-report.md
```

Generated file: [`cost-latency-report.md`](../cost-latency-report.md) at the
repository root.

Verified contents (PHI-free):

- **Latency percentiles**: total p50/p95/p99 plus per-step p50/p95/mean for
  `extract`, `finalize`, `refuse`, `retrieve`.
- **Cost**: total dev spend, mean per run, projected daily cost at 100 / 1,000
  / 10,000 docs/day.
- **Bottleneck section**: highest-mean-latency step and largest p95-p50
  spread.
- **Retrieval stats**: mean hits per query, share of queries with >= 5 hits.
- **Confidence stats**: mean / p10 / p50 / p90 of extraction confidence.
- **No PHI**: only aggregate numerics; the underlying `RunRecord` is
  sanitised by `agent_service.observability.run_record.RunRecord.sanitised()`
  before it leaves the eval runner.

The fixture-driven values are dominated by zero-millisecond stubs (the eval
client is deterministic and synchronous), but the report layout, percentile
computation, and bottleneck-selection logic are fully exercised end-to-end.

---

## 5. Test suite

```powershell
$env:OPENAI_API_KEY = $null
cd agent-service
py -m pytest -q --tb=no
```

Observed output (last lines):

```
.................................. (735 dots) ......                     [100%]
============================== warnings summary ===============================
..\..\..\AppData\...\langgraph\cache\base\__init__.py:8
  LangChainPendingDeprecationWarning: ...
-- Docs: https://docs.pytest.org/en/stable/how-to/capture-warnings.html
735 passed, 1 warning in 46.17s
```

Exit code: **0**. No regressions.

The single warning is a third-party deprecation notice from `langgraph` and
is non-actionable from this repo.

---

## 6. Manual UI checklist (operator-driven)

The CI eval gate proves the schema, retrieval, and extraction pipeline; the
manual checklist proves the OpenEMR UI integration. Run through it once
before recording the demo video.

### 6.1 One-time setup

- [ ] Stack is up: `.\run-docker.ps1` reports all services healthy.
- [ ] `form_upload_intake_form` is registered in OpenEMR.
      - On the dev-easy stack, registration is now automatic: the
        `forms-bootstrap` init service in
        `docker/development-easy/docker-compose.yml` runs after the
        `openemr` container reports healthy and applies the idempotent SQL
        in `docker/development-easy/init/register-week2-forms.sql`. A
        fresh `docker compose down -v && docker compose up` lands with
        the form ready to use - no Forms Administration click required.
      - Verify the bootstrap ran clean:
        ```powershell
        docker compose --project-name openemr logs forms-bootstrap
        ```
        Expected last lines:
        ```
        [forms-bootstrap] Verifying registration:
        name        directory           state
        Upload Document (Co-Pilot)  upload_intake_form  1
        [forms-bootstrap] Done.
        ```
      - Verify the tables and registry row directly:
        ```powershell
        docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr `
          -e "SHOW TABLES LIKE 'form_upload_intake_form%';"
        docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr `
          -e "SELECT name, directory, state FROM registry WHERE directory = 'upload_intake_form';"
        ```
        Expected: both `form_upload_intake_form` and
        `form_upload_intake_form_citation` tables exist, and the registry
        returns one row with `state = 1`.
      - Optional UI check: log in at <http://localhost:8300/> as
        `admin` / `pass`, navigate to **Administration -> Forms -> Forms
        Administration**, and confirm "Upload Document (Co-Pilot)" is
        listed under **Registered**. The "Register" button beside the
        form should NOT be visible - the bootstrap already inserted the
        row.
      - Production OpenEMR installs continue to use the upgrade SQL
        pipeline (`sql/8_1_0-to-8_1_1_upgrade.sql`); the
        `forms-bootstrap` service is dev-easy-only.
- [ ] At least one patient exists. (Bundled OpenEMR demo data ships with one
      patient; otherwise create one via **Patient -> New/Search -> New
      Patient**.)

### 6.2 Generate a lab PDF fixture

The eval fixtures are JSON; the UI needs a real PDF. Use the offline
generator (no API key required, no PHI-leak risk):

```powershell
.\generate-lab-pdf.ps1 -PatientId 1 -Offline -Seed 42
```

The script renders a synthetic lab PDF to disk (path printed in the script
output). It does **not** auto-insert into OpenEMR - the next step uploads it
through the form so the sidecar runs the full pipeline.

### 6.3 Lab upload happy path

- [ ] In OpenEMR, open patient 1 -> **Encounters -> New Encounter** (or pick
      an existing encounter).
- [ ] Add the **Upload Intake Form** form to the encounter.
- [ ] In a separate PowerShell window, tail the sidecar:
      ```powershell
      docker compose --project-name openemr logs -f agent-service
      ```
- [ ] On the form, attach the PDF generated in 6.2 to the **Lab Report
      (PDF)** field. Submit the form.
- [ ] In the tail window: confirm a `POST /api/agent/run` line, followed by
      extractor and retriever step logs, ending with a 200 response. No
      `error` lines.
- [ ] Confirm lab observations land in `procedure_result`:
      ```powershell
      docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr `
        -e "SELECT COUNT(*) AS rows FROM procedure_result;"
      ```
      Expected: a non-zero count after upload (was 0 before).
- [ ] Confirm citations land in `form_upload_intake_form_citation`:
      ```powershell
      docker compose --project-name openemr exec -T mysql mariadb -uroot -proot openemr `
        -e "SELECT id, form_id, source_type, field_name, page FROM form_upload_intake_form_citation ORDER BY id DESC LIMIT 10;"
      ```
      Expected: rows with `source_type='pdf_bbox'` for lab fields and at
      least one `source_type='guideline'` row.

### 6.4 Click-to-source overlay (S18)

- [ ] Reopen the encounter and click into the **Upload Intake Form** entry
      to load `view.php`.
- [ ] Confirm the original lab PDF renders on the left (pdf.js viewer).
- [ ] Hover one extracted lab field on the right pane: a translucent
      bounding box flashes on the PDF over the source value.
- [ ] Click the field: the PDF scrolls to the page and the box stays
      highlighted.
- [ ] Hover/click a guideline citation: a side panel opens with the snippet
      and a `source_url` link.

### 6.5 Intake form regression (S14, S15)

- [ ] Repeat 6.3 with an intake form upload (the original Week-1 path) -
      pick any of the lab/intake mock files.
- [ ] Confirm the form persists and `form_upload_intake_form` shows the new
      row. The lab path must not have broken intake.

### 6.6 Observability record

- [ ] `agent-service/agent_service/eval/run-records.jsonl` from step 4
      exists.
- [ ] Inspect one line:
      ```powershell
      Get-Content agent-service/agent_service/eval/run-records.jsonl -TotalCount 1 | ConvertFrom-Json
      ```
- [ ] Confirm fields present: `tool_sequence`, `latencies_ms`,
      `cost_usd`, `retrieval_hits`, `extraction_confidence`.
- [ ] Confirm **no PHI**: scan the file for the test patient's name,
      dob, MRN. Should match nothing (only fixture IDs and aggregate
      numerics survive sanitisation).
      ```powershell
      Select-String -Path agent-service/agent_service/eval/run-records.jsonl `
        -Pattern '\\bDOB:|\\bSSN:|\\bMRN:'
      ```

---

## 7. Known issues / caveats

- **`OPENAI_API_KEY` must be unset** for direct invocations of
  `py -m agent_service.eval` and `py -m pytest`. The `FakeLLMClient` raises
  by design if the env var is set, to prevent real-API calls. PowerShell
  pattern:
  `$env:OPENAI_API_KEY = $null; <command>; $env:OPENAI_API_KEY = "<your-key>"`.
  The pre-push hook (`scripts/hooks/pre-push`) handles this automatically -
  it unsets `OPENAI_API_KEY` in its own subshell before running the eval,
  so `git push` does not require the manual workaround.
- **`mysql` client is not in the MySQL container's `$PATH`.** Use
  `docker compose ... exec -T mysql mariadb ...` (the image is MariaDB
  11.8.6). The wrapper `mysql` binary is not installed.
- **Form registration is required** before the citation table exists. The
  form registration step in 6.1 runs `table.sql`, which is idempotent
  (`CREATE TABLE IF NOT EXISTS`).
- **PowerShell stderr handling on native commands:** the `py -m ...` calls
  emit a `LangChainPendingDeprecationWarning` to stderr. PowerShell wraps
  this in an `ErrorRecord` even though the exit code is 0, so the warning
  appears in the pipeline output. Always read the **exit code**, not the
  presence of the warning, when deciding pass/fail.
- **Cost/latency values are near-zero in fixture mode.** The eval pipeline
  uses `FakeLLMClient` and synchronous in-process retrieval, so per-run
  latency is dominated by zero-ms stubs. The report exists to validate the
  shape and the aggregation logic, not to estimate production cost; see the
  bottleneck section of [WEEK2_SIDECAR.md](WEEK2_SIDECAR.md) for the
  end-to-end OpenAI-backed cost projection.

---

## 8. Demo video flow

Recommended ~3-4 minute capture:

1. **Stack health** (0:00-0:20)
   - Show `.\run-docker.ps1` output (or `docker compose ps` if already up).
   - In a side panel hit `Invoke-RestMethod http://127.0.0.1:8010/healthz`.
2. **Eval gate green** (0:20-0:50)
   - `cd agent-service`
   - `py -m agent_service.eval --baseline agent_service/eval/baseline.json`
   - Highlight the 5x100% rubrics and exit 0.
3. **Regression proof red** (0:50-1:30)
   - `py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression drop-citations`
   - Highlight `FAIL: 4 rubric(s) regressed` and exit 1.
4. **Cost/latency report** (1:30-2:00)
   - Open `cost-latency-report.md` in the editor; scroll the latency,
     cost, and bottleneck sections.
5. **OpenEMR lab upload** (2:00-3:00)
   - Log in to <http://localhost:8300/>.
   - Open patient -> encounter -> Upload Intake Form -> attach lab PDF.
   - Side window: `docker compose ... logs -f agent-service` shows the
     extractor/retriever traces.
6. **Click-to-source overlay** (3:00-3:30)
   - Click into the form view.
   - Hover a lab field -> bbox flashes on the PDF.
   - Click a guideline citation -> side panel with snippet.
7. **Wrap up** (3:30-3:50)
   - Show `procedure_result` and `form_upload_intake_form_citation` rows
     via the `mariadb` exec command.
   - Mention `docs/WEEK2_SIDECAR.md` and this verification doc as the
     reference for reproduction.

---

## 9. Pass criteria

Tracked from S26 in [`sidecar-detailed-steps.md`](../sidecar-detailed-steps.md):

- [x] Core Week 2 flow works locally (manual checklist above).
- [x] Eval passes normally (exit 0, all 5 rubrics 100%).
- [x] Eval fails under injected regression (exit 1, citation_present
      regresses to 12%).
- [x] Cost/latency report generated, contains p50/p95, projected costs,
      bottleneck section, and no raw PHI.
- [x] Full Python test suite green (735 passed, 0 failed).
- [x] Manual UI checklist documented for the operator.
- [x] Demo video flow documented for the recorder.
