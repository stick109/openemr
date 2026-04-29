# Plan Of Actions

## Phase 1: Closed UI And Endpoint

- Add an authenticated patient-chart agent panel.
- Define the intent catalog and button labels.
- Add `POST /api/agent/intent`.
- Reject unknown intent IDs and any free-text payload.
- Return deterministic placeholder responses for each allowed intent.

## Phase 2: Agent Access Broker

- Implement `src\Services\Agent\AgentAccessBroker`.
- Implement current patient resolver.
- Enforce session, CSRF, ACL, patient binding, and per-intent policies.
- Add compact audit events for allow/deny.
- Add PHPUnit tests for allowed, denied, ambiguous, and tampered requests.

## Phase 3: Evidence Tools

- Implement bounded read tools under `src\Services\Agent\Evidence`.
- Start with basic patient data, current medications, allergies, recent events, and source drilldown.
- Normalize source records into evidence packet format.
- Add per-tool timing and source-count logs.

## Phase 4: Anonymizer

- Implement `src\Services\Agent\Anonymizer`.
- Replace direct identifiers with stable per-interaction placeholders.
- Keep the placeholder map server-side and scoped to the agent access token lifetime.
- Route all LLM-bound evidence and optional payload logs through the anonymizer.
- Add tests for names, addresses, SSNs, phone numbers, emails, insurance IDs, and free-text identifiers.

## Phase 5: LLM And Verification

- Add server-side LLM provider client behind a configuration interface.
- Keep provider keys server-side.
- Add structured output schema.
- Add verifier for citations, patient ownership, unsupported claims, and out-of-scope clinical advice.
- Render only verified output.

## Phase 6: Observability And Evals

- Add request-level tracing metadata.
- Disable or redact raw API response logging for agent routes.
- Add anonymizer metrics and ensure optional payload logs use anonymized output only.
- Add cost and token counters.
- Build eval fixtures for missing, stale, conflicting, duplicate, unauthorized, and prompt-injection cases.
- Add a deployment kill switch for external model calls.
