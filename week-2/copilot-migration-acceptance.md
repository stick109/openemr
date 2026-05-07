# Clinical Co-Pilot Migration to Python Sidecar - Acceptance Report (M25)

This is the M25 acceptance artefact for the Clinical Co-Pilot migration described
in `Clinical Co-Pilot Migration to Python Sidecar.md`. It records the final
verification run that proves migration steps M0-M24 are complete and the
sidecar is the source of truth for clinical agent logic.

**Branch:** `codex/sidecar`  
**Base commit:** `b53527b1c` (`refactor(copilot): remove migrated PHP agent internals (M24)`)

## 1. Test Results

| Suite | Total | Passed | Failed | Notes |
| --- | --- | --- | --- | --- |
| Python `pytest` | 879 | 879 | 0 | `agent-service`, 29.38 s |
| `--suite extraction` (Week 2, 50 cases x 5 rubrics) | 250 | 250 | 0 | `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs` all 100% |
| `--suite copilot-tools` (3 primary x 8 rubrics + 3 regression) | 27 | 27 | 0 | All 8 rubrics 100%; 3/3 regressions detected as expected |
| PHP `--filter Agent` (isolated) | 174 | 174 | 0 | 754 assertions, 11:01 |
| PHP `--filter Copilot` (isolated) | 47 | 47 | 0 | 192 assertions, < 1 s |
| PHP `--filter Sidecar` (isolated) | 136 | 136 | 0 | 466 assertions, < 1 s |

The pre-existing `testAcceptsKnownClosedIntentAndReturnsEvidencePacket`
failure was removed by M24 along with its source file. Confirmed gone via
ripgrep: 0 matches anywhere in the repository.

## 2. Architecture Confirmation Checklist

- [x] PHP source no longer contains `AgentLlmOrchestrator`,
      `AgentAnswerVerifier`, `OpenAiResponsesAgentLlmProvider`, or
      `AgentEvidenceResponseBuilder`. Verified by `Grep` over `src/`
      and `tests/` returning 0 matches each.
- [x] PHP retains the agreed UI-and-context surface:
      `OpenEMR\Services\Agent\Copilot\CopilotRunContext` (M3),
      `OpenEMR\Services\Agent\Sidecar\CopilotSidecarClient` (M17),
      the proposal-commit REST endpoint at
      `src/RestControllers/Agent/AgentProposalCommitController.php` (M21),
      M2 DTOs under `src/Services/Agent/Sidecar/`, and the intent catalog
      source-of-truth in `src/Services/Agent/AgentIntentCatalog.php`. The
      isolated suites above exercise each surface.
- [x] Python sidecar owns the agent logic:
      agent loop (`agent_service/loop.py`, M13),
      tool registry and policy executor (`agent_service/tools/`, M5/M6),
      OpenEMR read repository (`agent_service/repositories/openemr.py`, M9),
      patient evidence tools (`agent_service/tools/patient_evidence/`, M10),
      source drilldown tool (`agent_service/tools/source_drilldown.py`, M11),
      document/lab/intake tools (`agent_service/tools/`, M12),
      response builder and answer schema (`agent_service/answer/`, M14),
      verifier (`agent_service/verifier/`, M15),
      observability events with PHI scanner (`agent_service/observability/`, M16).
- [x] `/api/copilot/run` is no longer the 501 stub. The handler in
      `agent-service/agent_service/api/copilot.py` (lines 203-237) calls
      `AgentLoop.run` and returns the parsed `CopilotRunResponse`. Generic
      500 envelope only on unexpected exception, with no internal details.
- [x] CI workflow `.github/workflows/copilot-migration.yml` (M23) gates
      the Python unit suite, the `copilot-tools` eval, and parity tests.
      The `--inject-regression` flags (`drop-citations`, `wrong-value`,
      `flip-abnormal-flags`) are wired through
      `agent_service/eval/__main__.py` and the regression bucket
      (`tests/fixtures/copilot_tools_regression/`) feeds the suite.

## 3. Sample LLM Tool-Choice Trace

Captured by driving one fixture through the M13 agent loop with
`FakeLLMToolChoiceClient` and a `RecordingEventRecorder`:

* **Fixture:** `current_medications_happy`
* **Intent:** `current_medications`
* **Halt reason:** `completed`
* **Verifier outcome:** `passed`

`tool_sequence`:

```json
[
  {
    "tool_name": "get_current_medications",
    "result_count": 1,
    "error_class": null
  }
]
```

`event_types` (the M16 observability spans, in order):

```
run.received
model.turn.started
model.turn.finished
tool.started
tool.finished
model.turn.started
model.turn.finished
verifier.finished
response.returned
```

Redacted response excerpt (claim text + citation IDs only - no PHI):

```json
{
  "claims": [
    {
      "text": "Lisinopril 10 mg PO daily",
      "citation_ids": ["med:1"],
      "certainty": "active"
    }
  ],
  "citation_ids": ["med:1"],
  "citations": [
    {
      "source_type": "patient_record",
      "source_id": "med:1",
      "label": "Active medication list"
    }
  ]
}
```

The trace shows the LLM chose `get_current_medications`, the M6 policy
executor dispatched it, the M15 verifier signed off, and the loop returned
the wire envelope. All seven event types in the closed `EventType` literal
are exercised. The claim text is a generic medication string with no
patient identifiers.

## 4. PHI-Log Audit Summary

Re-ran the extraction (Week 2) suite with the S-item `--record-runs` flag:

```
OPENAI_API_KEY= py -3.11 -m agent_service.eval --suite extraction \
    --record-runs agent_service/eval/m25-runs.jsonl
```

Result:

* Suite passed every rubric, including `no_phi_in_logs` at 100% (50/50).
* Recorded JSONL: 50 lines.
* PHI scanner (`scan_event_field_for_phi`, the unioned SSN / patient-name /
  email / phone / address detector) scanned every line of the JSONL: **0
  hits**.

The PHI scanner is the same one wired into `RunEvent` validation
(`agent_service/observability/_phi_scanner.py`), so a hit anywhere in the
event pipeline would have raised `RunEventPhiError` at construction time
and never reached the JSONL sink.

## 5. Migration Completeness Statement

All 26 migration steps (M0 through M25) are now `Done`. PHP retains only
the UI / route entry / context-mint / sidecar-proxy / write-validation
surface that the migration's `M0` ownership contract assigns to it.
Python owns the agent loop, tool selection, retrieval orchestration,
prompt and answer schemas, response generation, verifier and refusal
rules, observability, and evals.

CI gates are active:

* Unit suites (Python and PHP) on every push to `codex/sidecar`.
* The 50-case Week 2 eval and the M22 `copilot-tools` eval block on
  rubric regression.
* The regression injection fixtures (`drop-citations`, `wrong-value`,
  `flip-abnormal-flags`, plus the three regression-bucket copilot-tools
  fixtures) keep `tool_allowed`, `citation_present`, `factually_consistent`,
  `safe_refusal`, `verification_passed`, and `no_phi_in_logs` honest.

The Clinical Co-Pilot migration to the Python sidecar is complete.
