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
| P2.1 | Pending | Implement `src\Services\Agent\AgentAccessBroker`.                                 | Depends on P1.2 and P1.3 so broker authorization can be called from the agent route. |
| P2.2 | Pending | Implement current patient resolver.                                               | Depends on P2.1 because patient resolution belongs behind the broker boundary.       |
| P2.3 | Pending | Enforce session, CSRF, ACL, patient binding, and per-intent policies.              | Depends on P2.1 and P2.2.                                                           |
| P2.4 | Pending | Add compact audit events for allow/deny.                                           | Depends on P2.3 so audit events record final broker decisions.                       |
| P2.5 | Pending | Add PHPUnit tests for allowed, denied, ambiguous, and tampered requests.           | Depends on P2.1 through P2.4.                                                       |

## Phase 3: Evidence Tools

| ID   | Status  | Work Item                                                                                               | Dependencies / Notes                                                |
| ---- | ------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| P3.1 | Pending | Implement bounded read tools under `src\Services\Agent\Evidence`.                                       | Depends on P2.3 so every tool can require broker-approved access.   |
| P3.2 | Pending | Start with basic patient data, current medications, allergies, recent events, and source drilldown.     | Depends on P3.1.                                                    |
| P3.3 | Pending | Normalize source records into evidence packet format.                                                   | Depends on P3.1 and P3.2 because normalization wraps tool outputs.  |
| P3.4 | Pending | Add per-tool timing and source-count logs.                                                              | Depends on P3.1 and should align with Phase 6 observability fields. |

## Phase 4: Anonymizer

| ID   | Status  | Work Item                                                                                                      | Dependencies / Notes                                                                 |
| ---- | ------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| P4.1 | Pending | Implement `src\Services\Agent\Anonymizer`.                                                                     | Depends on P3.3 for the evidence packet shape it will transform.                     |
| P4.2 | Pending | Replace direct identifiers with stable per-interaction placeholders.                                            | Depends on P4.1.                                                                     |
| P4.3 | Pending | Keep the placeholder map server-side and scoped to the agent access token lifetime.                             | Depends on P2.1 and P4.1.                                                           |
| P4.4 | Pending | Route all LLM-bound evidence and optional payload logs through the anonymizer.                                  | Depends on P4.1 through P4.3 and must be complete before external LLM calls.         |
| P4.5 | Pending | Add tests for names, addresses, SSNs, phone numbers, emails, insurance IDs, and free-text identifiers.          | Depends on P4.1 through P4.4.                                                       |

## Phase 5: LLM And Verification

| ID   | Status  | Work Item                                                                                           | Dependencies / Notes                                                     |
| ---- | ------- | --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| P5.1 | Pending | Add server-side LLM provider client behind a configuration interface.                               | Depends on P4.4 so model-bound evidence is anonymized first.             |
| P5.2 | Pending | Keep provider keys server-side.                                                                     | Depends on P5.1 and deployment configuration.                            |
| P5.3 | Pending | Add structured output schema.                                                                       | Depends on P5.1 and P3.3 because outputs must cite evidence packet IDs.  |
| P5.4 | Pending | Add verifier for citations, patient ownership, unsupported claims, and out-of-scope clinical advice. | Depends on P2.3, P3.3, and P5.3.                                        |
| P5.5 | Pending | Render only verified output.                                                                        | Depends on P5.4.                                                         |

## Phase 6: Observability And Evals

| ID   | Status  | Work Item                                                                                              | Dependencies / Notes                                                       |
| ---- | ------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| P6.1 | Pending | Add request-level tracing metadata.                                                                    | Depends on P1.2 and should expand as later components are added.           |
| P6.2 | Pending | Disable or redact raw API response logging for agent routes.                                           | Depends on P1.2 and must be in place before PHI-bearing responses.         |
| P6.3 | Pending | Add anonymizer metrics and ensure optional payload logs use anonymized output only.                     | Depends on P4.4.                                                           |
| P6.4 | Pending | Add cost and token counters.                                                                           | Depends on P5.1.                                                           |
| P6.5 | Pending | Build eval fixtures for missing, stale, conflicting, duplicate, unauthorized, and prompt-injection cases. | Depends on P2, P3, P4, and P5 behavior becoming testable.                  |
| P6.6 | Pending | Add a deployment kill switch for external model calls.                                                  | Depends on P5.1 and should be available before enabling external providers. |
