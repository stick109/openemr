# Eval Regression Proof — Demo Commands

> Step S22 deliverable. Demonstrates that the offline 50-case eval gate
> in [`agent-service/agent_service/eval/`](../agent-service/agent_service/eval/)
> actually catches regressions instead of always passing. Three controlled
> regression hooks force specific rubric failures so a reviewer can
> reproduce the gate firing in seconds.

All commands run from `agent-service/`. They use the bundled
`FakeLLMClient`, so no API keys, network access, or Docker are required.

```powershell
cd agent-service
```

---

## 1. Baseline run (passes — exit 0)

```powershell
py -m agent_service.eval --baseline agent_service/eval/baseline.json
```

Expected output:

```
Eval pass rates over 50 cases:
  schema_valid           100.00%  (50/50)
  citation_present       100.00%  (50/50)
  factually_consistent   100.00%  (50/50)
  safe_refusal           100.00%  (50/50)
  no_phi_in_logs         100.00%  (50/50)

All rubrics meet thresholds and no regression detected.
```

Exit code: `0`. This is the green path the pre-push hook and CI rely on.

---

## 2. `drop-citations` regression (fails — exit 1)

```powershell
py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression drop-citations
```

The hook strips `source_citation` from every lab row and empties
`source_citations` on intake forms before the graph runs. The cascade
breaks four rubrics because the extractor refuses without citations.

Expected output (truncated):

```
FAIL: 4 rubric(s) regressed
- schema_valid: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
  affected fixtures: lab_001, lab_002, lab_003, lab_004, ...
- citation_present: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
  affected fixtures: lab_001, lab_002, lab_003, lab_004, ...
- factually_consistent: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
  affected fixtures: lab_001, lab_002, lab_003, lab_004, ...
- safe_refusal: 1.00 -> 0.12 (delta -88pp, threshold 1.00)
  affected fixtures: lab_001, lab_002, lab_003, lab_004, ...
```

Exit code: `1`. Only the 6 refusal fixtures still pass — every success
fixture is named in the affected list.

---

## 3. `wrong-value` regression (fails — exit 1)

```powershell
py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression wrong-value
```

The hook bumps the *first* lab result's numeric value by `+99.0` and
flips its abnormal flag, and rewrites every intake form's
`chief_concern` to a sentinel string. The schema still validates, so
only `factually_consistent` regresses.

Expected output:

```
FAIL: 1 rubric(s) regressed
- factually_consistent: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
  affected fixtures: lab_001, lab_002, lab_003, ..., intake_001, ...
```

Exit code: `1`. This is the cleanest demo — only the `factually_consistent`
gate fires, which is exactly what we promised when we typed the rubric.

---

## 4. `flip-abnormal-flags` regression (fails — exit 1)

```powershell
py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression flip-abnormal-flags
```

Swaps `high` <-> `low` and `critical_high` <-> `critical_low` on every
lab row. Affects the lab fixtures whose rows actually carry an abnormal
flag.

Expected output:

```
FAIL: 1 rubric(s) regressed
- factually_consistent: 1.00 -> 0.72 (delta -28pp, threshold 0.95)
  affected fixtures: lab_002, lab_003, lab_004, lab_005, ...
```

Exit code: `1`. Smaller blast radius than `wrong-value` because it
only impacts fixtures with non-`normal` abnormal flags.

---

## What this proves

* The gate is not a no-op. Each regression deterministically breaks at
  least one rubric and the runner exits non-zero.
* The failure summary names *both* the regressed rubric and the case
  IDs of the affected fixtures, so a reviewer can immediately look up
  what changed.
* All three regressions are deterministic — no random seeds, no
  per-run drift. Re-running produces identical output.
* The same command (sans `--inject-regression`) is what the pre-push
  hook and `agent-eval` GitHub Actions workflow run on every push and
  PR (see [`WEEK2_SIDECAR.md` §7](WEEK2_SIDECAR.md#7-eval-gate-pre-push-hook--ci)).

## Implementation references

* Regression hooks live in
  [`agent-service/agent_service/eval/runner.py`](../agent-service/agent_service/eval/runner.py)
  (function `_maybe_inject_regression`).
* Failure-summary formatting lives in the same file
  (`format_failure_summary`).
* Smoke tests covering every regression type live in
  [`agent-service/tests/test_regression_injection.py`](../agent-service/tests/test_regression_injection.py).
