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

## Phase 7: Basic Patient Data Scope Expansion

| ID   | Status  | Work Item                                                                                              | Dependencies / Notes                                                       |
| ---- | ------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| P7.1 | Pending | Plan the `basic_patient_data` evidence expansion to include the must-have demographic/contact sources: richer `patient_data` fields, structured `addresses`, and structured `phone_numbers`. | Planning-only item. Do not broaden this intent into clinical history, medications, allergies, labs, documents, billing, insurance, portal activity, employer data, care teams, or preferences. Implementation should happen only after this plan is reviewed. |

### P7.1 Detailed Plan: Expand `basic_patient_data`

#### Current Baseline

- The `Basic patient data` button maps to the `basic_patient_data` intent in [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php).
- The intent maps to the `get_patient_snapshot` evidence tool in [AgentEvidenceToolset.php](src\Services\Agent\Evidence\AgentEvidenceToolset.php).
- The evidence tool currently requires only the `demographics` data class.
- The SQL repository method is `fetchBasicPatientData()` in [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php).
- The current SQL only selects `pid`, `uuid`, `DOB`, `sex`, `status`, and `date` from `patient_data`.
- The current response emits one source with `source_id` shaped like `demographics:patient_data:{pid}`.
- The current `fields_used` list is `DOB`, `sex`, and `status`.
- The current intent cap is `max_records = 1`, `max_documents = 0`, and `lookback_days = 0`.

#### Goal

Expand the `basic_patient_data` evidence packet so the button answers the natural user expectation for "basic patient data": identity, demographic descriptors, structured address, and structured phone/contact details. The answer should remain a concise administrative/demographic snapshot, not a clinical-summary intent.

#### Non-Goals

- Do not include medications from `lists`, `lists_medication`, or `prescriptions`.
- Do not include allergies from `lists`.
- Do not include problems, health concerns, surgeries, devices, or dental issues from `lists`.
- Do not include encounters, forms, notes, SOAP notes, dictation, documents, labs, procedures, orders, immunizations, SDOH history, questionnaires, claims, billing, payments, insurance, care-team tables, employer tables, portal activity, reminders, or audit logs.
- Do not add a new UI button as part of this work. The existing `Basic patient data` button should keep working.
- Do not add free-text routing. This remains a closed-intent retrieval path.
- Do not create or modify schema.
- Do not expose SSN, driver's license, or other high-risk identifiers through this intent unless a later policy decision explicitly approves them.

#### In-Scope Tables

1. `patient_data`
   - Purpose: primary demographic and registration source.
   - Existing join key: `patient_data.pid`.
   - Continue using this row as the required anchor record for the packet.

2. `addresses`
   - Purpose: structured address rows when present outside the main `patient_data` address fields.
   - Candidate join key: `addresses.foreign_id = patient_data.pid`.
   - Implementation must confirm this ownership convention before reading rows, because `addresses` is a generic table and can also support non-patient entities in other workflows.
   - If no reliable patient-only discriminator exists, implementation should either use the existing patient address service/pattern already trusted elsewhere in the codebase or defer structured `addresses` until safe ownership can be proven.

3. `phone_numbers`
   - Purpose: structured phone rows when present outside the main `patient_data` phone fields.
   - Candidate join key: `phone_numbers.foreign_id = patient_data.pid`.
   - Implementation must confirm this ownership convention before reading rows, because `phone_numbers` is a generic table and can also support non-patient entities in other workflows.
   - If no reliable patient-only discriminator exists, implementation should either use the existing patient phone service/pattern already trusted elsewhere in the codebase or defer structured `phone_numbers` until safe ownership can be proven.

#### `patient_data` Field Plan

Use a curated projection, grouped by meaning. Do not select `*`.

Identity and chart identifiers:

- `pid`: internal patient id, used for patient binding and source ownership.
- `uuid`: patient UUID, converted using existing UUID helpers.
- `pubpid`: public patient id / chart id / MRN-style identifier.
- `title`: optional name prefix.
- `fname`: first name.
- `mname`: middle name.
- `lname`: last name.
- `suffix`: optional name suffix.
- `preferred_name`: preferred name, when present.
- `birth_fname`, `birth_mname`, `birth_lname`: birth-name fields, when present and useful.

Date of birth, sex, gender, and pronouns:

- `DOB`: date of birth; may also support an age display derived server-side.
- `sex`: sex at birth.
- `sex_identified`: patient-reported current sex.
- `gender_identity`: gender identity.
- `sexual_orientation`: sexual orientation, if policy keeps this within demographic scope.
- `pronoun`: pronoun.
- `status`: existing status field currently shown by the tool. Treat carefully because local deployments may use this as marital/status-style demographic data.
- `deceased_date`: deceased date if present.
- `deceased_reason`: deceased reason if present and policy allows including it in basic demographics.

Language, interpreter, race, ethnicity, and related demographics:

- `language`: preferred language.
- `interpreter`: legacy interpreter indicator.
- `interpreter_needed`: modern interpreter-needed field.
- `race`: race.
- `ethnicity`: ethnicity.
- `ethnoracial`: combined or legacy ethnoracial value.
- `religion`: religion, if policy keeps this within demographic scope.
- `nationality_country`: nationality country, if policy keeps this within demographic scope.
- `tribal_affiliations`: tribal affiliations, if policy keeps this within demographic scope.

Address fields already present on `patient_data`:

- `street`: first street line.
- `street_line_2`: second street line.
- `city`: city.
- `state`: state.
- `postal_code`: postal code.
- `county`: county.
- `country_code`: country code.

Contact fields already present on `patient_data`:

- `phone_home`: home phone.
- `phone_biz`: business phone.
- `phone_contact`: alternate/contact phone.
- `phone_cell`: mobile phone.
- `email`: email.
- `email_direct`: Direct address / secure clinical email, if policy allows showing it in basic data.
- `contact_relationship`: relationship for the contact phone/person represented in patient data.

Registration, portal, and provider context:

- `date`: original record date currently used by the tool.
- `regdate`: registration date.
- `last_updated`: last update timestamp.
- `providerID`: primary provider id, if this field is locally used that way.
- `ref_providerID`: referring provider id.
- `referrer`, `referrerID`: referral source context.
- `pharmacy_id`: default pharmacy id, if policy considers it basic chart context.
- `allow_patient_portal`: portal allowance flag, if policy considers it basic administrative context.
- `care_team_provider`, `care_team_facility`, `care_team_status`: patient-data embedded care-team fields only. Do not join `care_teams` or `care_team_member` in P7.1.
- `provider_since_date`: provider relationship start date, if populated.

Fields explicitly excluded from P7.1 despite being present in `patient_data`:

- `ss`: Social Security number.
- `drivers_license`: driver's license.
- `billing_note`: billing note.
- `financial`, `financial_review`, `monthly_income`, `pricelevel`, and other billing/financial values.
- `genericname*`, `genericval*`, `usertext*`, and `userlist*` unless a later mapping defines exactly what each deployment stores there.
- `hipaa_*` values unless a later privacy/admin intent needs them.
- `mothersname`, `guardiansname`, and guardian contact fields unless a later guardian/contact intent needs them.
- `dupscore` and duplicate-detection internals.
- `prevent_portal_apps` and other access-control internals.

#### `addresses` Field Plan

If safe patient ownership is confirmed, read a bounded set of structured address rows:

- `id`: address row id for citation/source drilldown.
- `foreign_id`: candidate patient id reference.
- `line1`: street line 1.
- `line2`: street line 2.
- `city`: city.
- `state`: state.
- `zip`: ZIP/postal code.
- `plus_four`: ZIP+4 extension.
- `country`: country.
- `district`: county/district.

Address retrieval rules:

- Read only rows owned by the current patient.
- Do not read addresses for any other `foreign_id`.
- Use a small cap, for example up to 3 structured address rows.
- Prefer deterministic ordering, such as `id ASC`, unless the existing service layer has a better recency or preference convention.
- If no structured address rows exist, fall back to the address fields already present on `patient_data`.
- If both `patient_data` address fields and `addresses` rows exist, output both only when they differ materially. Avoid duplicate claims.
- Do not infer current address from stale or ambiguous rows unless the source has an explicit current/preferred marker.

#### `phone_numbers` Field Plan

If safe patient ownership is confirmed, read a bounded set of structured phone rows:

- `id`: phone row id for citation/source drilldown.
- `foreign_id`: candidate patient id reference.
- `country_code`: country code.
- `area_code`: area code.
- `prefix`: phone prefix.
- `number`: final four/local number segment.
- `type`: phone type code.

Phone retrieval rules:

- Read only rows owned by the current patient.
- Do not read phone numbers for any other `foreign_id`.
- Use a small cap, for example up to 5 structured phone rows.
- Prefer deterministic ordering, such as `id ASC`, unless the existing service layer has a better preferred/contact order.
- Format phone numbers through an existing OpenEMR formatter if one is available.
- Preserve type labels when they can be resolved through existing list-option helpers.
- If no structured phone rows exist, fall back to `phone_home`, `phone_biz`, `phone_contact`, and `phone_cell` from `patient_data`.
- If both `patient_data` phone fields and `phone_numbers` rows exist, output both only when they differ materially. Avoid duplicate claims.

#### Evidence Shape

Preferred implementation shape:

- Keep `data_class = demographics` for all P7.1 sources.
- Keep `source_type = demographics` for the primary patient row.
- Add narrower source types for child rows only if they improve citations, for example `address` and `phone`.
- Keep `max_documents = 0`; these are structured records, not documents.
- Revisit `max_records` for `basic_patient_data`. The current cap of 1 cannot represent patient row plus address rows plus phone rows if each row is a separate source.
- Preferred cap model: keep one primary patient source and allow bounded child sources under the same intent. If the existing cap object cannot represent child caps, increase `max_records` enough to cover one patient row plus address and phone rows, for example `max_records = 10`.
- Every emitted source must include `source_id`, `source_type`, `data_class`, `table`, `record_id`, `patient_id`, `date`, `status`, `display`, `excerpt`, `fields_used`, and `reliability`.
- Primary source id should remain stable for the patient row: `demographics:patient_data:{pid}`.
- Address source ids, if emitted as separate citations, should be stable and table-specific: `demographics:addresses:{id}`.
- Phone source ids, if emitted as separate citations, should be stable and table-specific: `demographics:phone_numbers:{id}`.

#### Source Drilldown

Future implementation should update source drilldown so `Show source` can resolve any new source ids:

- Continue supporting `demographics:patient_data:{pid}`.
- Add support for `demographics:addresses:{id}` only after ownership validation confirms `foreign_id` belongs to the current patient.
- Add support for `demographics:phone_numbers:{id}` only after ownership validation confirms `foreign_id` belongs to the current patient.
- A source id must never be enough by itself to retrieve a row. All drilldown queries must include current patient ownership in the `WHERE` clause.
- If an address or phone source cannot be safely resolved, return no source rather than falling back to an unsafe lookup.

#### Access Control And Data Classes

- Keep P7.1 under the existing `demographics` data class.
- Keep authorization under the existing broker path for `patients/demo`.
- Do not require `patients/med`, because no medication/allergy data is in scope.
- Do not require `patients/appt`, because appointment data is not in scope.
- If local policy treats structured address or phone rows under a different ACL than demographics, update the broker policy before implementation.
- The access token should still grant only the final data classes and tools authorized by the broker. Retrieval code must not infer extra permission from the intent label.

#### Response Content Rules

The final answer for `Basic patient data` should prefer short, separate claims:

- Name / preferred name.
- Public patient id / chart id.
- Date of birth and age.
- Sex at birth / current sex / gender identity / pronoun, when present.
- Language and interpreter need, when present.
- Race and ethnicity, when present and policy allows.
- Registration or record status.
- Deceased status/date, when present.
- Primary address summary.
- Phone/contact summary.
- Email summary.
- Primary provider/referring provider context, when present.

The answer should also be explicit about missing data:

- If no structured address is found, say address was not found in checked structured address evidence.
- If no phone is found, say phone was not found in checked structured phone evidence.
- If the patient row exists but optional demographic fields are empty, do not invent values.
- The answer should not claim that a patient lacks an address or phone globally unless both `patient_data` and the safe structured tables were checked.

#### Privacy And Logging

- Raw evidence may still go to the configured LLM provider only through the existing BAA-covered LLM path.
- Durable API logging must continue to use anonymized evidence only.
- Address, phone, email, name, DOB, and public patient id must be included in anonymizer coverage.
- SSN and driver's license should remain excluded from P7.1 evidence to avoid unnecessary high-risk identifier exposure.
- Free-text fields should be kept out of P7.1 unless they have clear bounded semantics. Avoid broad text fields that can carry unrelated notes.

#### Implementation Steps For Later

1. Update `AgentIntentCatalog` only if the selected evidence shape requires a higher `max_records` for `basic_patient_data`.
2. Update `EvidenceRecordRepositoryInterface` only if the repository contract needs new method names. Prefer keeping `fetchBasicPatientData()` and expanding its returned source list.
3. Update `SqlEvidenceRecordRepository::fetchBasicPatientData()` to select the curated `patient_data` projection.
4. Add bounded address retrieval after confirming the safe ownership pattern for `addresses`.
5. Add bounded phone retrieval after confirming the safe ownership pattern for `phone_numbers`.
6. Add mapping helpers for patient demographic, address, and phone sources.
7. Add deduplication helpers so `patient_data` address/phone values and structured rows do not produce duplicate claims.
8. Add drilldown support for address and phone source ids only if separate child sources are emitted.
9. Update `fields_used` so every source truthfully reports which database fields were read and used.
10. Keep all SQL parameterized and patient-scoped.
11. Keep all source counts bounded by caps.
12. Update deterministic fallback answers if needed so the no-LLM path still produces readable claims.

#### Test Plan For Later

- Unit test that `basic_patient_data` still returns a patient source when only `patient_data` exists.
- Unit test that richer `patient_data` fields produce separate claims or source display parts.
- Unit test that empty optional fields do not produce empty or misleading claims.
- Unit test that SSN and driver's license are not selected or emitted.
- Unit test that address rows for another patient are not returned.
- Unit test that phone rows for another patient are not returned.
- Unit test that structured address rows are capped.
- Unit test that structured phone rows are capped.
- Unit test that duplicate `patient_data` address/phone values and structured rows are collapsed or clearly de-duplicated.
- Unit test that `checked_evidence` remains `demographics`.
- Unit test that address and phone source ids can be drilled down only for the current patient.
- Unit test that invalid/tampered address or phone source ids return no source.
- Controller or isolated service test that the response remains authorized by `patients/demo`.
- Anonymizer test coverage for newly emitted address, phone, email, name, DOB, and public patient id values.
- Verifier test that claims cite only emitted sources.
- Regression test that `current_medications`, `allergies_to_confirm`, `recent_events`, and `changed_since_last_visit` behavior does not change.

#### Acceptance Criteria

- Clicking `Basic patient data` retrieves a bounded demographic/contact evidence packet from `patient_data`, plus safe structured address and phone rows when available.
- The packet stays within `data_class = demographics`.
- The packet never includes medications, allergies, problems, encounters, notes, documents, labs, billing, claims, insurance, or unrelated patient-data classes.
- The response cites every claim to a source id in the packet.
- Source drilldown works for every emitted source id or the implementation deliberately emits only source ids that can be drilled down safely.
- The response handles missing address and missing phone data without implying unverified absence.
- The implementation does not expose SSN or driver's license.
- The implementation remains closed-intent and does not add any free-text retrieval path.
- Tests cover ownership, caps, missing data, privacy exclusions, source drilldown, and regression behavior.
