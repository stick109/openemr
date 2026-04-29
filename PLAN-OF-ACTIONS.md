# Plan Of Actions

## Phase 1: Closed UI And Endpoint

1. Define the intent catalog and button labels. Depends on the MVP intent IDs and user traces already defined in `ARCHITECTURE.md` and `USERS.md`.
2. Add `POST /api/agent/intent` with a minimal authenticated route and controller skeleton. Depends on item 1 because the endpoint should accept intent IDs from the server-owned catalog only.
3. Reject unknown intent IDs and any free-text payload. Depends on items 1 and 2 because validation requires both the catalog and the endpoint request contract.
4. Return deterministic placeholder responses for each allowed intent. Depends on item 3 because placeholders should only be returned after the request passes closed-intent validation.
5. Add an authenticated patient-chart agent panel. Depends on items 1 through 4 because the panel needs final button labels, a stable endpoint, validation behavior, and placeholder response shape.

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
