# Clinical Co-Pilot PHP-to-Python Parity Fixtures

These fixtures capture the expected behavior of the existing PHP Clinical
Co-Pilot for every cataloged intent. They are the M1 deliverable in
`Clinical Co-Pilot Migration to Python Sidecar.md` and serve as the
authoritative parity contract that the Python sidecar agent loop must match
once M7-M16 land.

The fixtures are intentionally human-authored JSON descriptions, not full
evidence packets. They describe the run context, evidence shape, expected
tool sequence, expected citation behavior, and expected verification status,
plus a short note pointing back to the PHP source-of-truth file. They are
not yet wired into a Python test runner; that is M22's job.

## Intent to fixture mapping

| Intent ID                     | Fixture folder                   | Scenarios |
| ---                           | ---                              | --- |
| `basic_patient_data`          | `basic_patient_data/`            | 5 |
| `current_medications`         | `current_medications/`           | 5 |
| `allergies_to_confirm`        | `allergies_to_confirm/`          | 5 |
| `recent_events`               | `recent_events/`                 | 5 |
| `changed_since_last_visit`    | `changed_since_last_visit/`      | 5 |
| `show_source`                 | `show_source/`                   | 7 |

Total: 32 fixture files.

## Scenario taxonomy

Every intent folder includes the same five base scenarios. The `show_source`
intent adds two extra cases because its policy boundary is the citation ID
rather than the patient.

| Scenario                  | What it tests |
| ---                       | --- |
| `happy path`              | Cooperating evidence, expected tool runs, expected verifier pass. |
| `missing data`            | Empty sources from the repository; safe missingness wording. |
| `conflicting data`        | Multiple records that disagree (e.g., active vs inactive). |
| `unauthorized source`     | Citation references a source outside granted data classes or another patient. |
| `prompt injection`        | User goal contains "ignore previous instructions" / out-of-scope advice. |
| `invalid citation ID`     | (`show_source` only) Malformed or unknown source ID. |
| `cross-patient citation ID` | (`show_source` only) Well-formed source ID for a different patient. |

## Source-of-truth PHP files

Each scenario's expected behavior was derived from these PHP files. When
adapting a fixture's expected output, consult the matching file first:

| Intent                       | Primary PHP source(s) |
| ---                          | --- |
| All intents (request entry)  | `src/RestControllers/Agent/AgentIntentRestController.php` (free-text rejection, `source_id` regex, payload shape). |
| All intents (catalog)        | `src/Services/Agent/AgentIntentCatalog.php` (caps `max_records`, `max_documents`, `lookback_days`, button labels). |
| All intents (evidence shaping) | `src/Services/Agent/AgentEvidenceResponseBuilder.php` (deterministic claim text, missingness, capitalization). |
| All intents (orchestration)  | `src/Services/Agent/AgentLlmOrchestrator.php` (LLM vs deterministic answer, system_refusal fallback). |
| All intents (verifier)       | `src/Services/Agent/Verification/AgentAnswerVerifier.php` (out-of-scope advice, citation existence, cross-patient rejection, active-status rule, completeness statements). |
| `basic_patient_data`         | `SqlEvidenceRecordRepository::fetchBasicPatientData` and `mapPatientRecord`/`mapAddressRecord`/`mapTelecomRecord`/`mapEmployerRecord`. |
| `current_medications`        | `SqlEvidenceRecordRepository::fetchCurrentMedications`; medication source IDs `medication:<table>:<id>`. |
| `allergies_to_confirm`       | `SqlEvidenceRecordRepository::fetchAllergiesToConfirm`; allergy source IDs `allergy:lists:<id>`. |
| `recent_events`              | `SqlEvidenceRecordRepository::fetchRecentEvents`; encounter and document source IDs. |
| `changed_since_last_visit`   | `SqlEvidenceRecordRepository::fetchChangedSinceLastVisit` (also reads `accessToken->getGrantedDataClasses()`). |
| `show_source`                | `SqlEvidenceRecordRepository::fetchSourceRecord`; `AgentEvidenceToolset::readRecords` `SHOW_SOURCE` branch. |

## Cross-references with existing PHP fixtures

`tests/Tests/Fixtures/Agent/agent-eval-fixtures.json` already encodes a few
representative cases. The parity fixtures here keep the same source IDs and
shapes where they overlap so a future Python loader can compare directly:

| PHP fixture id                  | Parity fixture |
| ---                             | --- |
| `missing_current_medications`   | `current_medications/02_current_medications_missing.json` |
| `stale_medication_record`       | partial overlap with `current_medications/01_current_medications_happy.json` |
| `conflicting_allergy_records`   | `allergies_to_confirm/03_allergies_to_confirm_conflicting.json` |
| `duplicate_recent_event_records`| `recent_events/03_recent_events_conflicting.json` |
| `unauthorized_billing_source`   | `current_medications/04_current_medications_unauthorized_source.json` and `show_source/04_show_source_unauthorized_source.json` |
| `prompt_injection_note_text`    | `recent_events/05_recent_events_prompt_injection.json` |

## Fixture schema

Every JSON file has the same top-level shape:

```jsonc
{
  "intent_id": "<one of the six>",
  "scenario": "<scenario name from the table above>",
  "input": {
    "user_goal": "<what reaches the LLM-facing prompt>",
    "run_context_summary": {
      "patient_id_present": true,
      "encounter_id_present": false,
      "lookback_days": 30,
      "max_rows": 25,
      "allowed_tools": ["get_basic_patient_data", "get_source_detail"]
    },
    "evidence_packet_ref": "<plain English description of the repository result>"
  },
  "expected": {
    "tool_sequence": ["<expected tool names in order>"],
    "citation_ids_required": true,
    "answer_blocks_min": 1,
    "verification_status": "passed | refused",
    "missing_or_uncertain": ["<expected safe-missingness lines>"],
    "refusal_reason": "<short reason or null>"
  },
  "notes": "<one-line PHP behavior reference>"
}
```

The fixture is descriptive, not executable. It does not include raw SQL
result rows. When the sidecar gains a Python parity test runner (M22), each
fixture should be paired with a small evidence packet JSON or factory call
that can synthesize the described `evidence_packet_ref` deterministically.

## What is intentionally not here

- No raw PHI. Names, DOBs, addresses, and phone numbers are excluded;
  fixtures only reference the *shape* of evidence (e.g., "patient_data row
  plus contact_telecom row"), not values.
- No model-supplied `patient_id`, `encounter_id`, `document_id`, SQL, or
  filesystem paths. The sidecar tool executor injects those from the signed
  run context, never from model arguments.
- No expected raw answer text. Expected verifier outcomes and required
  missingness phrasing are captured, but the deterministic wording is
  considered an implementation detail of `AgentEvidenceResponseBuilder` until
  the Python port lands.
