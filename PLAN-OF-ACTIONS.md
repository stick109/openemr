# Plan Of Actions

Status values: `Done`, `Pending`.

## Phase 1: Closed UI And Endpoint

| ID   | Status    | Work Item                                                                 | Dependencies / Notes                                                                                 |
| ---- | --------- | ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| P1.1 | Done      | Define the intent catalog and button labels.                              | Based on the MVP intent IDs and user traces in `ARCHITECTURE.md` and `USERS.md`.                     |
| P1.2 | Done      | Add `POST /api/agent/intent` with a minimal authenticated route/controller. | Depends on P1.1 because the endpoint accepts intent IDs from the server-owned catalog only.          |
| P1.3 | Done      | Reject unknown intent IDs and any free-text payload.                      | Depends on P1.1 and P1.2 because validation requires both the catalog and endpoint request contract. |
| P1.4 | Done      | Return deterministic placeholder responses for each allowed intent.       | Depends on P1.3 because placeholders should only be returned after closed-intent validation passes.   |
| P1.5 | Done      | Add an authenticated patient-chart agent panel.                           | Depends on P1.1 through P1.4 because the panel needs labels, endpoint, validation, and response shape. |
| P1.6 | Done      | Move the patient-chart agent panel into a dedicated chart tab.            | Depends on P1.5 and keeps the closed-intent UI separate from dashboard cards.                         |
| P1.7 | Done      | Render a read-only prompt-preview field with a disabled send button next to the intent buttons. | Depends on P1.1 and P1.5. Clicking an intent button populates the field with the server-bound prompt text so the user sees what will be sent to the LLM; the field is not editable, the send button is never clickable, and the server ignores any value posted from the field. |

## Phase 2: Agent Access Broker

| ID   | Status  | Work Item                                                                         | Dependencies / Notes                                                                 |
| ---- | ------- | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| P2.1 | Done    | Implement `src\Services\Agent\AgentAccessBroker`.                                 | Depends on P1.2 and P1.3 so broker authorization can be called from the agent route. |
| P2.2 | Done    | Implement current patient resolver.                                               | Depends on P2.1 because patient resolution belongs behind the broker boundary.       |
| P2.3 | Done    | Enforce session, CSRF, ACL, and patient binding; resolve the user's full access set (data classes, tools, ACL categories) for the current patient in a single broker call and bake it into the token. | Depends on P2.1 and P2.2. Per-intent caps are catalog policy applied by retrieval tools, not broker output. |
| P2.4 | Done    | Add compact audit events for allow/deny.                                           | Depends on P2.3 so audit events record final broker decisions.                       |
| P2.5 | Done    | Add PHPUnit tests for allowed, denied, ambiguous, and tampered requests.           | Depends on P2.1 through P2.4.                                                       |

## Phase 3: Evidence Tools

| ID   | Status  | Work Item                                                                                               | Dependencies / Notes                                                |
| ---- | ------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| P3.1 | Done    | Implement bounded read tools under `src\Services\Agent\Evidence`.                                       | Depends on P2.3 so every tool can require broker-approved access.   |
| P3.2 | Done    | Start with basic patient data, current medications, allergies, recent events, changed since last visit, and source drilldown. | Depends on P3.1. Covers all six MVP intents in `ARCHITECTURE.md`. |
| P3.3 | Done    | Normalize source records into evidence packet format.                                                   | Depends on P3.1 and P3.2 because normalization wraps tool outputs.  |
| P3.4 | Done    | Add per-tool timing and source-count logs.                                                              | Depends on P3.1 and should align with Phase 6 observability fields. |
| P3.5 | Done    | Define per-intent caps (`max_records`, `max_documents`, `lookback_days`) in the intent catalog and have each retrieval tool clamp to them at call time. | Depends on P1.1 and P3.1. The token authorizes who can read what; the catalog governs how much, so caps live with the intent definition rather than the broker token. |

## Phase 4: Anonymizer

| ID   | Status  | Work Item                                                                                                      | Dependencies / Notes                                                                 |
| ---- | ------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| P4.1 | Done    | Implement `src\Services\Agent\Anonymizer`.                                                                     | Depends on P3.3 for the evidence packet shape it will transform.                     |
| P4.2 | Done    | Replace direct identifiers with stable per-interaction placeholders.                                            | Depends on P4.1.                                                                     |
| P4.3 | Done    | Keep the placeholder map server-side and scoped to the agent access token lifetime.                             | Depends on P2.1 and P4.1.                                                           |
| P4.4 | Done    | Route optional payload logs through the anonymizer. LLM-bound evidence is sent raw because the provider is covered by a signed BAA. | Depends on P4.1 through P4.3.                                                       |
| P4.5 | Done    | Add tests for names, addresses, SSNs, phone numbers, emails, insurance IDs, and free-text identifiers.          | Depends on P4.1 through P4.4.                                                       |

## Phase 5: LLM And Verification

| ID   | Status  | Work Item                                                                                           | Dependencies / Notes                                                     |
| ---- | ------- | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| P5.1 | Done    | Add server-side LLM provider client behind a configuration interface.                               | Depends on the provider BAA being in place (LLM input is raw evidence, not anonymized). |
| P5.2 | Done    | Keep provider keys server-side.                                                                     | Depends on P5.1 and deployment configuration.                            |
| P5.3 | Done    | Add structured output schema.                                                                       | Depends on P5.1 and P3.3 because outputs must cite evidence packet IDs.  |
| P5.4 | Done    | Add verifier for citations, patient ownership, unsupported claims, and out-of-scope clinical advice. | Depends on P2.3, P3.3, and P5.3.                                        |
| P5.5 | Done    | Render only verified output.                                                                        | Depends on P5.4.                                                         |

## Phase 6: Observability And Evals

| ID   | Status  | Work Item                                                                                              | Dependencies / Notes                                                       |
| ---- | ------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| P6.1 | Done    | Add request-level tracing metadata.                                                                    | Depends on P1.2 and should expand as later components are added.           |
| P6.2 | Done    | Disable or redact raw API response logging for agent routes.                                           | Depends on P1.2 and must be in place before PHI-bearing responses.         |
| P6.3 | Done    | Add anonymizer metrics and ensure optional payload logs use anonymized output only.                     | Depends on P4.4.                                                           |
| P6.4 | Done    | Add cost and token counters.                                                                           | Depends on P5.1.                                                           |
| P6.5 | Done    | Build eval fixtures for missing, stale, conflicting, duplicate, unauthorized, and prompt-injection cases. | Depends on P2, P3, P4, and P5 behavior becoming testable.                  |
| P6.6 | Done    | Add a deployment kill switch for external model calls.                                                  | Depends on P5.1 and should be available before enabling external providers. |
