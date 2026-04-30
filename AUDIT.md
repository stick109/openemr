# OpenEMR Audit

## One-Page Summary

### Access Control

- OpenEMR has useful foundations: authenticated sessions, OAuth2/SMART/FHIR APIs, route checks, CSRF handling, and phpGACL-backed ACLs.

- The key gap is patient-specific authorization. Many checks answer "can this user access this data type?" rather than "can this user access this exact patient right now?"

- The FHIR patient-bound flow includes `checkUserHasAccessToPatient()` in `src\RestControllers\Authorization\BearerTokenAuthorizationStrategy.php`, but it currently returns `true`. Agent tools must add a real server-side patient binding check.

- MVP agent access should be read-only, tied to an authenticated OpenEMR workflow, and limited to a validated current patient context. The LLM must never choose patient IDs or run generic chart searches without server validation.

### Performance

- The physician workflow assumes about 90 seconds between rooms, so the agent needs useful answers in seconds, not a full-chart crawl.

- OpenEMR can retrieve patients, encounters, medications, documents, appointments, allergies, procedures, and FHIR resources, but paths often include legacy bootstrapping, global state, SQL audit logging, and potentially broad service queries.

- The first agent retrieval layer should produce a bounded evidence packet: today's appointment, recent encounters, active problems, active medications, allergies, recent labs/procedures, and selected parsed documents.

- Each retrieval should require patient binding, explicit limits, and timing instrumentation. Long documents, OCR, embeddings, and broad summarization should be precomputed or asynchronous.

### Observability

- OpenEMR already has audit primitives: `log`, `extended_log`, `audit_master`, `audit_details`, `api_log`, SQL audit hooks, and optional ATNA support.

- The risk is PHI overlogging. `api_log_option` defaults to full logging, and `ApiResponseLoggerListener` can store JSON API responses in both `request_body` and `response`.

- Agent observability should log request ID, user ID, patient ID, tool sequence, source record IDs, latency, model, token counts, cost, verification result, refusal outcome, and error class.

- Raw prompts, chart excerpts, source snippets, and model completions should be redacted, hashed, disabled, or retained only under an explicit policy.

### Other

- The agent should integrate through existing OpenEMR UI/API/module boundaries, using existing session, CSRF, ACL, audit, and event mechanisms.

- Data quality is uneven across `patient_data`, `lists`, `lists_medication`, `prescriptions`, encounters, forms, documents, procedures, and FHIR projections.

- The main build recommendation is a verified evidence layer before conversational behavior: cite source records, refuse unsupported claims, enforce patient access, bound retrieval for speed, and emit PHI-safe observability.

## Scope And Method

Primary inputs:

- `CURRENT-ARCHITECTURE.md`
- `Week-1-Assignment.pdf`

Supporting code sampled for audit evidence:

- `interface\globals.php`
- `library\auth.inc.php`
- `library\globals.inc.php`
- `src\BC\FallbackRouter.php`
- `src\Common\Acl\AclMain.php`
- `src\Common\Database\QueryUtils.php`
- `src\Common\Http\HttpRestRouteHandler.php`
- `src\Common\Session\SessionWrapperFactory.php`
- `src\Common\Twig\TwigContainer.php`
- `src\RestControllers\Authorization\BearerTokenAuthorizationStrategy.php`
- `src\RestControllers\Authorization\LocalApiAuthorizationController.php`
- `src\RestControllers\Config\RestConfig.php`
- `src\RestControllers\Subscriber\ApiResponseLoggerListener.php`
- `src\RestControllers\Subscriber\AuthorizationListener.php`
- `src\RestControllers\Subscriber\SiteSetupListener.php`
- `src\Services\BaseService.php`
- `src\Services\PatientService.php`
- `src\Services\EncounterService.php`
- `src\Services\Storage`
- `library\classes\Document.class.php`
- `apis\routes\_rest_routes_standard.inc.php`
- `apis\routes\_rest_routes_fhir_r4_us_core_3_1_0.inc.php`
- `sql\database.sql`
- `db\README.md`
- `src\Entities\README.md`

This is a codebase and architecture audit for planning the Clinical Co-Pilot. It is not a production penetration test, HIPAA legal opinion, or certification assessment.

Severity scale:

- High: must be addressed before exposing an AI agent to real PHI or broad patient data.
- Medium: important for MVP trust, performance, or maintainability.
- Low: useful hardening or follow-up work.

## Security Audit

### S1. Patient-specific authorization is the primary blocker

Severity: High

OpenEMR has authentication, API authorization strategies, route security checks, and phpGACL-backed ACLs. Browser requests pass through `interface\globals.php` and `library\auth.inc.php`. REST and FHIR requests pass through `SiteSetupListener`, `AuthorizationListener`, `HttpRestRouteHandler`, and route callbacks that call `RestConfig::request_authorization_check()`.

The gap is patient-specific authorization for an AI retrieval tool. ACL categories such as `patients\demo`, `patients\med`, `patients\docs`, and `encounters\auth_a` answer "can this user access this class of data?" They do not always answer "can this user access this specific patient's data right now?" The FHIR patient-bound flow recognizes this issue, but `BearerTokenAuthorizationStrategy::checkUserHasAccessToPatient()` currently returns `true`.

Impact for the agent: a natural-language prompt like "summarize John Smith before my next visit" must not become a chart-wide search followed by best-match retrieval. The agent needs a hard patient-binding step before tool execution.

Required control:

- Resolve the active patient from the current chart, schedule, or a selected patient UUID.
- Verify the requesting user's access to that patient before any retrieval.
- Deny or ask for disambiguation when patient identity is ambiguous.
- Do not let the LLM choose patient identifiers without server-side validation.

### S2. Local API requests skip downstream scope checks after CSRF validation

Severity: Medium

`LocalApiAuthorizationController` marks valid local API requests with `skipAuthorization=true`. That is reasonable for authenticated in-app calls protected by session and API CSRF tokens, but an agent embedded in the UI will likely use this path. Once local API authorization succeeds, route-level ACL checks still run in many route callbacks, but the scope-check layer is skipped.

Impact for the agent: in-app JavaScript or an agent endpoint must not treat a valid API CSRF token as a patient-data authorization grant. It also needs route-specific ACL and patient-specific checks.

Required control:

- Put the agent behind a server-side endpoint that validates session, CSRF, ACL, and patient binding.
- Avoid exposing generic "run tool" endpoints to browser JavaScript.
- Keep tool names and arguments server-defined, not model-defined.

### S3. Legacy direct-entry routing increases the exposed surface area

Severity: Medium

The application still has many direct PHP entry points under `interface`, `library`, `controllers`, `portal`, and root-level scripts. `src\BC\FallbackRouter.php` is a modern compatibility bridge and blocks many sensitive paths such as `config`, `db`, `sql`, `src`, `tests`, `vendor`, templates, dotfiles, `sqlconf.php`, and `sites\*\documents`. This is a useful hardening layer, but the architecture still depends on correct web-server configuration and consistent per-entry authentication behavior.

Impact for the agent: do not add another standalone public PHP script that bypasses the emerging front-controller/API conventions.

Required control:

- Add agent endpoints through existing REST/API, module, or authenticated UI patterns.
- Keep any new route inside existing bootstrap, auth, ACL, CSRF, and audit flows.
- Do not place PHI-bearing generated files under public or static asset paths.

### S4. Rendering AI output has XSS risk unless escaped deliberately

Severity: High

`src\Common\Twig\TwigContainer.php` creates Twig with `autoescape => false`. Existing templates often use explicit helpers such as `text`, `attr`, `js_escape`, `xlt`, and related functions. This pattern can be safe only when every output path escapes deliberately.

Impact for the agent: model output, patient-entered chart content, document text, and retrieved notes are all untrusted display content. If rendered as HTML, an attacker could inject script through patient data, document text, or prompt content.

Required control:

- Render agent answers as escaped text or a strict markdown subset.
- Escape all source snippets, citations, and error messages.
- Never trust model output as HTML.
- Add tests for prompt/content injection and rendered XSS cases.

### S5. API and debug logs can expose PHI

Severity: High

`library\globals.inc.php` defaults `api_log_option` to full logging. `ApiResponseLoggerListener` logs JSON API responses and stores the same content in both `request_body` and `response`. `HttpRestRouteHandler` also includes query parameters in debug logging.

Impact for the agent: raw prompts, retrieved records, generated clinical summaries, and intermediate tool results could be captured in durable logs. This conflicts with minimum necessary handling unless explicitly justified, protected, and retained under policy.

Required control:

- Do not log raw prompts, raw chart excerpts, or generated clinical summaries by default.
- Log structured metadata: request ID, user ID, patient ID, source record IDs, tool names, latency, verification status, token counts, and error class.
- Redact or hash source snippets if a debugging mode is needed.
- Review `api_log_option` for any agent routes before deployment.

### S6. Secrets and site configuration are site-scoped and must stay server-side

Severity: Medium

OpenEMR stores site-specific configuration under `sites\<site>`, including database configuration, document paths, certificates, and local storage. `FallbackRouter` blocks direct access to `sqlconf.php` and `sites\*\documents`, but agent code must preserve that trust boundary.

Impact for the agent: LLM provider keys, vector database credentials, encryption keys, and observability tokens must not be stored in browser-visible config or logged.

Required control:

- Store secrets in environment variables or server-only site configuration.
- Never expose LLM keys to browser code.
- Keep any retrieval or embedding service server-side.

## Performance Audit

### P1. The agent has a seconds-level latency budget

Severity: High

The assignment's core workflow is a physician with 90 seconds between rooms. OpenEMR bootstraps a large runtime through `interface\globals.php`: session handling, site setup, database connection, globals loading, event dispatcher, modules, auth, and settings. API requests also initialize site state and OAuth/session bridges.

Impact for the agent: every request should avoid cold-start-like work where possible. A conversational interface that makes many serial server round trips will feel too slow.

Required control:

- Use a small number of bounded server-side tool calls per answer.
- Prefer one composed "patient briefing evidence" endpoint over many fine-grained chat-driven queries.
- Add per-step timing logs from the first implementation.

### P2. Some service patterns are not safe for broad, unbounded retrieval

Severity: High

`BaseService` discovers table fields with `QueryUtils::listTableFields()` and checks auto-increment columns on construction. `QueryUtils::escapeTableName()` whitelists table names by running `SHOW TABLES`. This is safe-oriented but can add overhead. `QueryUtils::selectHelper()` applies a limit only when callers pass one. Some clinical service methods and routes can search or return broad result sets.

Impact for the agent: if an LLM tool can request arbitrary searches, it can accidentally trigger expensive queries or return too much PHI.

Required control:

- Every agent retrieval tool must require patient UUID and have server-side limits.
- Default to recent, active, or clinically relevant subsets.
- Refuse chart-wide export-style requests in the chat path.
- Use pagination only for explicit follow-up drilldown, not initial briefing.

### P3. The data model supports common lookups, but not all agent queries cheaply

Severity: Medium

Important indexes exist: `patient_data` has unique `pid` and `uuid`, name and DOB indexes; `openemr_postcalendar_events` has event-date and composite schedule indexes; `lists` has indexes on `pid`, `type`, and `uuid`; `documents` has indexes for patient and owner references. However, the agent's common question is cross-domain: "what changed since the last visit?" That spans encounters, vitals, medications, problems, labs, documents, messages, and possibly imported C-CDA content.

Impact for the agent: naive cross-table retrieval will be slow and incomplete.

Required control:

- Build a deterministic evidence query for the initial briefing.
- Consider composite indexes or cached summaries for high-value queries after measuring.
- Track "last changed" timestamps per source where available.

### P4. Audit logging and API response logging affect latency and storage

Severity: Medium

OpenEMR's audit settings default many categories on, including SELECT query logging and HTTP request logging. Full API logging is also default. This improves traceability but creates write amplification for an agent that may perform several reads per answer.

Impact for the agent: heavy audit/log writes can slow requests and create large PHI-bearing log tables.

Required control:

- Measure latency with audit logging enabled, not only in a developer-light mode.
- Keep agent observability metadata compact.
- Avoid logging repeated response payloads.

### P5. Documents and unstructured clinical notes require asynchronous handling

Severity: Medium

`Document.class.php` supports local filesystem storage, CouchDB, optional encryption, remote storage hooks, hashes, thumbnails, categories, and soft deletion. Document records include parsing/import status, but binary content and large text are not appropriate for on-demand full ingestion during a chat turn.

Impact for the agent: reading and summarizing large documents synchronously can exceed latency targets and increase PHI exposure.

Required control:

- Use metadata first.
- Only retrieve document text that has already been parsed, approved, and selected for the current patient.
- Put OCR, embedding, and long-document summarization in an offline job with audit metadata.

## Architecture Audit

### A1. OpenEMR is a hybrid monolith, not a single-framework app

Severity: Medium

The codebase mixes legacy procedural PHP, direct file routing, `controller.php`, Smarty, Twig, REST/FHIR controllers, Symfony HttpKernel events, phpGACL, ADODB helpers, Doctrine DBAL/ORM scaffolding, and modules. `CURRENT-ARCHITECTURE.md` correctly describes this as a system in transition.

Impact for the agent: a clean integration must respect existing boundaries rather than inventing a separate parallel application.

Recommended integration shape:

- UI: embed in the authenticated chart or main tabs workflow.
- Backend: namespaced services under `src` plus route/controller or module entry points.
- Retrieval: use existing services/FHIR routes where practical.
- Extension: use events or module bootstraps when the codebase already expects them.

### A2. Existing APIs are useful, but not sufficient as the only agent tool layer

Severity: Medium

Standard API and FHIR routes expose patients, encounters, medications, allergies, appointments, documents, immunizations, procedures, and other resources. These are good retrieval foundations. However, a clinical co-pilot needs a curated evidence packet, not raw API results.

Impact for the agent: letting the model call arbitrary OpenEMR routes will increase latency, PHI exposure, and hallucination risk.

Required control:

- Add server-owned tools such as `get_patient_snapshot`, `get_current_medications`, `get_recent_events`, and `get_changed_since_last_visit`.
- Return source IDs, table/resource names, timestamps, and display text separately.
- Keep model-visible data minimized and already filtered.

### A3. Global state and sessions constrain background work

Severity: Medium

Legacy code depends heavily on `$GLOBALS`, `OEGlobalsBag`, active sessions, site IDs, and request-derived state. This is manageable in normal web requests but risky for background workers that compute embeddings, summaries, or eval fixtures.

Impact for the agent: asynchronous jobs must explicitly set site context, patient context, and audit identity.

Required control:

- Use a server-side job interface that accepts site, patient, source IDs, and actor identity.
- Do not rely on whichever session happens to be active.
- Record provenance for generated artifacts.

### A4. Storage is split across database, site filesystem, CouchDB, and remote hooks

Severity: Medium

Clinical evidence can live in MySQL/MariaDB tables, `sites\<site>\documents`, CouchDB, or remote storage via `PatientDocumentStoreOffsite`. The newer Flysystem-backed storage manager is present but not universal.

Impact for the agent: source attribution must include storage type and stable record identifiers. A document answer should cite the document record, not just an extracted text chunk.

Required control:

- Store citations as table/resource + UUID/id + timestamp + field/section.
- Keep extracted document text linked to `documents.uuid` or equivalent source metadata.

### A5. Schema evolution is transitional

Severity: Medium

`db\README.md` says Doctrine Migrations are not fully integrated. Routine schema changes still depend on `sql\database.sql`, upgrade scripts, and `sql\patch.sql`.

Impact for the agent: adding agent tables for conversations, evidence, eval results, or observability needs to follow current OpenEMR schema-change practice, not only Doctrine migrations.

Required control:

- Before adding tables, confirm the active project migration path.
- Keep agent storage minimal for MVP.
- Avoid storing raw PHI in new tables unless required.

## Data Quality Audit

### D1. Patient demographics are sparse and permissive

Severity: Medium

`patient_data` contains many fields with empty-string defaults, nullable dates, legacy custom fields, social/history fields, and flexible text columns. It has useful identifiers and indexes, but field presence does not mean field reliability.

Impact for the agent: demographics and social context must be treated as data points with possible missingness, not guaranteed truth.

Required control:

- Represent missing fields explicitly.
- Avoid inferring clinical facts from absent values.
- Cite the exact demographic fields used.

### D2. Medications, problems, allergies, and other clinical items have multiple representations

Severity: High

The `lists` table stores several issue types and uses flexible fields such as `type`, `title`, `begdate`, `enddate`, `activity`, `diagnosis`, `comments`, and `verification`. `lists_medication` adds medication-specific fields and can link to prescriptions, but medication state can still come from lists, prescriptions, imported C-CDA, or external sources.

Impact for the agent: a single "active meds" answer can be wrong if it ignores one representation or treats stale imported data as current.

Required control:

- Build source-specific medication and problem normalizers.
- Prefer active/verified/current records.
- Show uncertainty when records conflict.
- Cite each medication claim to its source row.

### D3. Staleness and "changed since last visit" are not uniform

Severity: High

Tables use different date fields: encounter date, modification timestamps, appointment dates, document dates, list begin/end dates, created/updated fields, and import timestamps. Some records have null dates or free-text dates.

Impact for the agent: "what changed?" cannot be implemented as one timestamp comparison across the whole chart.

Required control:

- Define a per-source staleness rule.
- For the initial briefing, compare against the most recent completed encounter or today's appointment context.
- Label records as "recent", "active", "historical", or "undated" rather than blending them.

### D4. Documents are high-value but inconsistent evidence

Severity: Medium

Documents have metadata, hashes, categories, soft deletion, expiration checks, storage methods, and optional parsing status. The content can be binary, external, encrypted, unparsed, stale, or patient-uploaded.

Impact for the agent: document text should not be treated as equally reliable to structured medication or lab data without provenance and status.

Required control:

- Exclude deleted or expired documents.
- Prefer parsed, categorized, recent documents.
- Include document date, category, owner, and hash/UUID in citations.

### D5. Demo data may not reflect production data quality

Severity: Medium

The assignment requires demo data only. Demo datasets can be cleaner, smaller, and less contradictory than real EHR records.

Impact for the agent: passing on demo charts does not prove clinical robustness.

Required control:

- Add eval cases for missing DOB, missing active meds, duplicated medications, stale problems, conflicting allergies, empty labs, deleted documents, and unauthorized patient access.
- Keep evals source-grounded and deterministic.

## Compliance And Regulatory Audit

### C1. HIPAA minimum necessary must shape retrieval

Severity: High

The assignment says to act as if there is a signed BAA with LLM providers and that no data is used for training. That still does not justify sending entire charts to the model. HIPAA minimum necessary principles require limiting PHI to what is needed for the task.

Required control:

- Send only the current patient's bounded evidence packet.
- Remove unnecessary identifiers from model context when possible.
- Prefer record IDs and short clinical facts over whole notes.

### C2. Audit logging exists but agent-specific audit events are needed

Severity: High

OpenEMR has `log`, `extended_log`, `audit_master`, `audit_details`, `api_log`, SQL audit hooks, and optional ATNA support. Defaults enable many audit categories, while ATNA and audit-log encryption are disabled by default.

Impact for the agent: standard API logs do not capture the full decision chain of an AI answer, and full raw logs may overcapture PHI.

Required control:

- Record agent event type, actor, patient, route, tool sequence, source record IDs, verification result, and refusal/error outcome.
- Avoid storing raw prompts and raw model completions unless a retention policy explicitly permits it.
- Consider enabling audit-log encryption in deployed environments.

### C3. BAA and LLM retention settings are deployment blockers

Severity: High

Any LLM, embedding, tracing, eval, or observability provider that receives PHI is a business associate. The assignment assumes a BAA, but the implementation still needs configuration evidence.

Required control:

- Document each third-party processor.
- Confirm no-training and retention settings.
- Keep provider keys server-side.
- Provide a switch to disable external LLM calls for local/demo or incident response.

### C4. Breach notification and incident response are not solved by code alone

Severity: Medium

HIPAA breach notification obligations require operational procedures, timelines, investigation, and documentation. OpenEMR logging can support investigation, but the agent needs its own incident evidence.

Required control:

- Keep request IDs that connect UI, backend, tool calls, LLM calls, and audit records.
- Log denied authorization attempts and model/tool failures.
- Define who reviews agent incidents and how logs are retained.

### C5. Generated summaries may become part of the medical record if persisted

Severity: Medium

If agent answers are stored in OpenEMR as notes, documents, messages, or conversation history, they may create medical-record and retention obligations. If they are not stored, clinicians still need source attribution and reproducibility.

Required control:

- Decide whether agent outputs are transient clinical decision support or persisted record artifacts.
- If persisted, store source citations, model/version, timestamp, user, and verification status.
- If transient, make that clear in the UI and keep audit metadata sufficient for investigation.

## Recommended MVP Gate Before Building The Agent

Before implementing the Clinical Co-Pilot, complete these gate items:

1. Implement or identify a server-side patient-access check for provider users.
2. Define the exact evidence packet for the first physician workflow.
3. Create PHI-safe observability that does not log raw prompts or full chart payloads.
4. Add a verification contract: every returned claim maps to one or more source records.
5. Add eval fixtures for missing, stale, conflicting, duplicate, and unauthorized data.
6. Keep the initial agent read-only.
7. Disable or redact full API response logging for any agent endpoint.
8. Render model output only through escaped text or a strict safe-markdown renderer.

## Resolved Questions

- **Workflow choice (`USERS.md`):** picked a single doctor user preparing to see a scheduled outpatient.
- **Citation form (`ARCHITECTURE.md`):** record-level citation chips, with a `show_source` intent for drilldown — no unbounded note dumps in the briefing.
- **Output persistence (MVP):** read-only MVP; outputs are not persisted. `ARCHITECTURE.md` State Management defers persistence to a later design with provenance, retention, and review.
- **Deployment environment:** Railway will host the OpenEMR fork and the agent server.
- **LLM and tracing PHI exposure:** the LLM provider is covered by a signed BAA with no-training and bounded retention, so raw evidence is sent to the model without redaction. The anonymizer (`ARCHITECTURE.md` Anonymizer Component) is reserved for the durable logging path (`api_log` and equivalent sinks): it strips direct identifiers from any payload before it is persisted, so durable log records never contain raw PHI. Provider, BAA, retention, and access controls remain the primary HIPAA controls for model-bound traffic.
