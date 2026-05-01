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

## Phase 7: Evidence Scope Expansion

| ID   | Status  | Work Item | Dependencies / Notes |
| ---- | ------- | --------- | -------------------- |
| P7.1 | Done    | Expand the `basic_patient_data` evidence packet to include richer `patient_data` fields, patient-owned structured contact addresses, structured telecom values, and latest displayable employer data. | Implemented with bounded child sources and `max_records = 10`. Public patient id and last-updated timestamp are excluded. Direct generic `phone_numbers` lookup was avoided because safe patient ownership is not available through `foreign_id` alone. |
| P7.2 | Done    | Expand the `current_medications` evidence packet to include the must-have medication sources: additional `lists_medication` fields, patient-owned `prescriptions`, and the `lists_touch` medication-list review marker. | Implemented with patient-scoped linked-prescription enrichment, standalone current prescription sources, and drilldownable medication review markers. Optional/should sources remain out of scope (`drugs`, `list_options`, `users`, `pharmacies`, `issue_encounter`, `form_encounter`, `drug_sales`, `drug_inventory`). |
| P7.3 | Pending | Plan the `allergies_to_confirm` evidence expansion to include the must-have allergy sources: allergy-list review marker, additional `lists` allergy fields, coded allergen lookup, and severity lookup when severity is coded. | Planning-only item. Keep scope limited to allergy evidence in `lists`, `lists_touch`, and bounded `list_options` lookups. Do not implement code until this plan is reviewed. |

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

#### Implementation Status

- Implemented in [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php), [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php), [AgentEvidenceResponseBuilder.php](src\Services\Agent\AgentEvidenceResponseBuilder.php), [EvidencePacketNormalizer.php](src\Services\Agent\Evidence\EvidencePacketNormalizer.php), and [Anonymizer.php](src\Services\Agent\Anonymizer.php).
- The primary patient source now uses the curated `patient_data` projection and continues to emit `demographics:patient_data:{pid}`.
- Public patient id and last-updated timestamp are intentionally not selected or emitted by `basic_patient_data`.
- The `contact` table is used only as the patient-owned link table for address and telecom rows; no standalone contact claim is emitted because `contact` itself has no user-facing demographic value.
- Structured addresses are emitted only through the patient-owned `contact` -> `contact_address` -> `addresses` pattern and are capped at 3 child sources.
- Structured phone, SMS, fax, and email values are emitted only through the patient-owned `contact` -> `contact_telecom` pattern and are capped at 5 child sources.
- The latest displayable patient-owned `employer_data` row is emitted with employer name, employer address, occupation, industry, and employment period when those values are available; empty employer rows are skipped.
- Direct `phone_numbers.foreign_id` reads were not implemented because `phone_numbers` is generic and does not provide a safe patient ownership discriminator by itself.
- Source drilldown now supports the emitted `patient_data`, `addresses`, `contact_telecom`, and `employer_data` source ids with current-patient ownership checks.
- Missing address and phone responses now explicitly say the values were not found in checked evidence instead of implying a global absence.
- Focused isolated tests cover richer patient data output, privacy exclusions, ownership checks, deduplication, caps, source drilldown, catalog caps, and anonymizer coverage.

#### Non-Goals

- Do not include medications from `lists`, `lists_medication`, or `prescriptions`.
- Do not include allergies from `lists`.
- Do not include problems, health concerns, surgeries, devices, or dental issues from `lists`.
- Do not include encounters, forms, notes, SOAP notes, dictation, documents, labs, procedures, orders, immunizations, SDOH history, questionnaires, claims, billing, payments, insurance, care-team tables, portal activity, reminders, or audit logs.
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

4. `contact`
   - Purpose: patient-owned contact container rows used to link structured address and telecom records.
   - Ownership rule: `contact.foreign_table_name = 'patient_data'` and `contact.foreign_id = patient_data.pid`.
   - Do not emit a standalone contact source because the table itself carries only ownership/container data, not displayable patient information.

5. `employer_data`
   - Purpose: latest employer, employer address, occupation, industry, and employment-period context.
   - Ownership rule: `employer_data.pid = patient_data.pid`.
   - Emit at most one latest displayable row ordered by employer record date and id, and only when it has at least one displayable employer value.

#### `patient_data` Field Plan

Use a curated projection, grouped by meaning. Do not select `*`.

Identity and chart identifiers:

- `pid`: internal patient id, used for patient binding and source ownership.
- `uuid`: patient UUID, converted using existing UUID helpers.
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
- `pubpid`: public patient id / chart id / MRN-style identifier.
- `last_updated`: last update timestamp.
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
- Preferred cap model: keep one primary patient source and allow bounded child sources under the same intent. If the existing cap object cannot represent child caps, increase `max_records` enough to cover one patient row plus address, phone, and employer rows, for example `max_records = 10`.
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
- Address, phone, email, name, DOB, and employer name must be included in anonymizer coverage.
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
- Anonymizer test coverage for newly emitted address, phone, email, name, and DOB values.
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

### P7.2 Detailed Plan: Expand `current_medications`

#### Current Baseline

- The `Current medications` button maps to the `current_medications` intent in [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php).
- The intent maps to the `get_current_medications` evidence tool in [AgentEvidenceToolset.php](src\Services\Agent\Evidence\AgentEvidenceToolset.php).
- The evidence tool requires the `medications` data class and is authorized through the existing `patients/med` access policy.
- The SQL repository method is `fetchCurrentMedications()` in [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php).
- The current SQL anchors on `lists` rows where `lists.pid = ?`, `lists.type = 'medication'`, and the row is active/current by `activity`, `enddate`, or null `enddate`.
- The current SQL left joins `lists_medication` by `lists_medication.list_id = lists.id`.
- The current SQL selects these `lists` fields: `id`, `uuid`, `pid`, `date`, `begdate`, `enddate`, `title`, `activity`, `comments`, and `modifydate`.
- The current SQL selects these `lists_medication` fields: `id`, `drug_dosage_instructions`, `usage_category_title`, `request_intent_title`, `medication_adherence`, `medication_adherence_date_asserted`, and `prescription_id`.
- The current response emits medication sources with ids shaped like `medication:lists_medication:{id}` when a `lists_medication` row exists, otherwise `medication:lists:{id}`.
- The current `fields_used` list is `title`, `activity`, `begdate`, `enddate`, `drug_dosage_instructions`, and `usage_category_title`.
- The current intent cap is `max_records = 25`, `max_documents = 0`, and `lookback_days = 365`.

#### Goal

Expand the `current_medications` evidence packet so it can represent the current medication list more faithfully. The expanded packet should include medication-list records, supplemental medication metadata, patient-owned prescription records, and a medication-list review marker when the list has been reviewed/touched even if no active medications are found.

The answer should remain a current-medication summary. It should not become a dispense-history, billing, pharmacy-inventory, medication-reconciliation audit, or broad medication-administration workflow.

#### Non-Goals

- Do not add a new UI button as part of P7.2. The existing `Current medications` button should keep working.
- Do not add free-text routing. This remains a closed-intent retrieval path.
- Do not create or modify schema.
- Do not include optional/should enrichment sources in P7.2 unless a later plan explicitly promotes them to scope.
- Do not include `drugs` catalog enrichment in P7.2.
- Do not include `list_options` label/code resolution in P7.2.
- Do not include provider/filler display joins from `users` in P7.2.
- Do not include `pharmacies` joins in P7.2.
- Do not include `issue_encounter` or `form_encounter` joins in P7.2.
- Do not include `drug_sales` dispense/sale/fill history in P7.2.
- Do not include `drug_inventory`, lot numbers, inventory quantity, warehouse, vendor, or expiration details in P7.2.
- Do not include billing, claims, payments, prices, notes outside medication fields, documents, allergies, labs, problems, procedures, or immunizations.
- Do not expose raw eRx debug payloads such as `drug_info_erx` by default.

#### In-Scope Tables

1. `lists`
   - Purpose: medication-list anchor records already used by the current implementation.
   - Ownership rule: `lists.pid = current patient pid` and `lists.type = 'medication'`.
   - Continue using this as the primary medication-list source.

2. `lists_medication`
   - Purpose: medication-specific supplemental fields for a `lists` medication row.
   - Join rule: `lists_medication.list_id = lists.id`.
   - Ownership rule: ownership is inherited only through the joined `lists` row. Never read a `lists_medication` row without joining back to `lists.pid = current patient pid`.
   - P7.2 adds missing supplemental fields that clarify source, adherence, intent, and primary/reported status.

3. `prescriptions`
   - Purpose: patient-owned prescription records that may have richer details than the medication-list row.
   - Link rule A: `prescriptions.id = lists_medication.prescription_id` when a medication-list row references a prescription.
   - Link rule B: `prescriptions.patient_id = current patient pid` for active/current prescriptions that are not represented by a `lists_medication.prescription_id`.
   - Ownership rule: every prescription query must include `prescriptions.patient_id = current patient pid`, even when resolving by `prescription_id`.
   - P7.2 should use prescriptions as patient evidence sources, not just background enrichment, because they are patient-owned records.

4. `lists_touch`
   - Purpose: review marker for list-level attestation/touch events.
   - Ownership rule: `lists_touch.pid = current patient pid` and `lists_touch.type = 'medication'`.
   - Use only to indicate that the medication list was reviewed/touched, especially when no current medication records are returned.
   - Do not treat `lists_touch` as a medication record.

#### `lists` Field Plan

Continue selecting the existing fields:

- `id`: medication-list record id.
- `uuid`: medication-list UUID.
- `pid`: patient id.
- `date`: list record date.
- `begdate`: medication start/begin date.
- `enddate`: medication end date.
- `title`: medication name/free-text title.
- `activity`: active flag.
- `comments`: medication-list comments.
- `modifydate`: medication-list last modification timestamp.

Consider adding these `lists` fields if they are useful and remain current-medication scoped:

- `subtype`: local medication subtype, if populated.
- `diagnosis`: diagnosis/reason attached to the medication-list record.
- `external_id`: external medication/list id.
- `list_option_id`: local option/coded reference if used for medication names.
- `erx_source`: whether the record came from OpenEMR or an eRx/external source.
- `erx_uploaded`: whether the medication-list record was uploaded to the eRx system.

Do not add unrelated `lists` fields that are issue-type-specific but not medication-specific, such as allergy reaction/severity fields or injury fields.

#### `lists_medication` Field Plan

Continue selecting the existing fields:

- `id`: `lists_medication` row id.
- `list_id`: FK to `lists.id`; select explicitly if needed for source drilldown and debugging.
- `drug_dosage_instructions`: free-text medication dosage instructions.
- `usage_category_title`: display title for medication usage category.
- `request_intent_title`: display title for medication request intent.
- `medication_adherence`: adherence value.
- `medication_adherence_date_asserted`: date the adherence value was asserted.
- `prescription_id`: linked prescription id.

Add these must-have fields:

- `usage_category`: coded/option id for usage category.
- `request_intent`: coded/option id for request intent.
- `medication_adherence_information_source`: source of adherence information.
- `is_primary_record`: whether this is the primary medication record or a reported record.
- `reporting_source_record_id`: user/address-book source id for a reported medication record.

Field usage rules:

- `usage_category` and `usage_category_title` should be carried together so the answer can cite both the code and human label when available.
- `request_intent` and `request_intent_title` should be carried together so the answer can distinguish plan/order/proposal style semantics when available.
- `medication_adherence` should not be summarized as "taking as prescribed" unless the value clearly supports that interpretation.
- `medication_adherence_information_source` should be included as provenance, not as a clinical claim by itself.
- `is_primary_record = 0` should be surfaced as "reported medication" or similar cautious wording when appropriate.
- `reporting_source_record_id` should not be dereferenced in P7.2 unless a later plan defines safe ownership and display rules for the referenced source.

#### `prescriptions` Field Plan

Prescription identity and ownership:

- `id`: prescription id.
- `uuid`: prescription UUID.
- `patient_id`: must equal current patient pid.
- `encounter`: encounter number/id attached to the prescription, if present.
- `provider_id`: prescribing provider id, but do not join provider display details in P7.2.
- `filled_by_id`: filler/user id, but do not join filler display details in P7.2.
- `pharmacy_id`: pharmacy id, but do not join pharmacy display details in P7.2.

Medication name and coding:

- `drug`: prescription drug name.
- `drug_id`: local drug catalog id, but do not join `drugs` in P7.2.
- `rxnorm_drugcode`: RxNorm code, when present.
- `medication`: local medication field, if used by existing prescription workflows.

Dates and status:

- `date_added`: prescription creation date.
- `date_modified`: prescription modified date.
- `start_date`: start date.
- `end_date`: end date.
- `filled_date`: filled date.
- `datetime`: legacy/current timestamp field if populated.
- `active`: prescription active flag.
- `txDate`: transaction/date field if required by existing eRx semantics.

Directions, dose, quantity, and dispense/refill:

- `drug_dosage_instructions`: structured/free-text dosage instructions.
- `dosage`: dosage.
- `quantity`: quantity.
- `size`: size/strength.
- `unit`: unit option id.
- `route`: route option id/text.
- `interval`: interval/frequency option id.
- `form`: dosage form option id.
- `substitute`: substitution flag.
- `refills`: refill count.
- `per_refill`: per-refill amount.
- `prn`: PRN/as-needed flag.
- `note`: prescription note. Treat as potentially free text and keep the excerpt bounded.

Intent, category, indication, and diagnosis:

- `usage_category`: coded/option id for usage category.
- `usage_category_title`: human label for usage category.
- `request_intent`: coded/option id for request intent.
- `request_intent_title`: human label for request intent.
- `indication`: prescription indication.
- `diagnosis`: diagnosis/reason for prescription.

eRx/external provenance:

- `erx_source`: OpenEMR vs external/eRx source marker.
- `erx_uploaded`: upload status to eRx system.
- `external_id`: external prescription id.
- `prescriptionguid`: external/eRx GUID, if needed for source drilldown/provenance. Do not display by default unless necessary.

Fields to exclude from normal display even if selected for source-level metadata:

- `drug_info_erx`: raw eRx drug payload/debug data.
- Large opaque external payloads.
- Any field that cannot be explained in a current-medication answer without local workflow knowledge.

#### `prescriptions` Retrieval Rules

Use two bounded prescription paths:

1. Linked prescriptions:
   - Start from the current `lists` medication query.
   - Join `prescriptions` on `prescriptions.id = lists_medication.prescription_id`.
   - Require `prescriptions.patient_id = current patient pid`.
   - If the linked prescription exists and is patient-owned, either merge key prescription details into the medication-list source or emit a separate `prescriptions` source. The source strategy must be chosen before implementation and tested.

2. Standalone current prescriptions:
   - Query `prescriptions` where `patient_id = current patient pid`.
   - Include only current/active prescriptions by default: `active = 1` and no ended date, or `end_date IS NULL`, `end_date = '0000-00-00'`, or `end_date >= CURDATE()`.
   - Also consider `start_date`, `date_added`, and `date_modified` for ordering.
   - Exclude prescriptions already represented by a current `lists_medication.prescription_id`.
   - Keep this path bounded by the intent cap.

Deduplication rules:

- If a `lists` medication row links to a `prescriptions` row, avoid producing two identical claims for the same medication.
- Prefer a single merged medication claim when `lists.title` and `prescriptions.drug` clearly refer to the same medication.
- If the prescription has materially different name/directions/status from the list record, preserve both as separate cited facts and mark the uncertainty instead of silently merging.
- Deduplicate by `prescription_id`, `prescriptions.id`, medication title/drug name, RxNorm code, and active date window where possible.
- Never drop a patient-owned prescription solely because the free-text name is similar; similarity-based dedupe should be conservative.

#### `lists_touch` Field Plan

Select:

- `pid`: patient id.
- `type`: list type; must be `medication`.
- `date`: last touch/review date.

Use rules:

- Read only rows where `pid = current patient pid` and `type = 'medication'`.
- Emit at most one `lists_touch` source, preferably the latest by `date DESC`.
- Use `lists_touch` only as a medication-list review marker.
- If current medication records exist, the review marker can be included as secondary provenance or omitted from the main answer if it adds noise.
- If no current medication records exist but a recent `lists_touch` row exists, answer that no current medication records were found in checked medication evidence and that the medication list has a review/touch marker dated `date`.
- If no current medication records and no `lists_touch` row exist, answer that no matching current medication records were found in checked evidence and that no medication-list review marker was found.

#### Evidence Shape

Preferred implementation shape:

- Keep `data_class = medications` for all P7.2 sources.
- Keep `source_type = medication` for true medication or prescription records.
- Consider `source_type = medication_review` for `lists_touch`.
- Continue to emit `source_id = medication:lists_medication:{id}` when the `lists_medication` row is the primary source.
- Continue to emit `source_id = medication:lists:{id}` when a medication-list row has no `lists_medication` row.
- Add source ids for patient-owned prescriptions if emitted separately: `medication:prescriptions:{id}`.
- Add source ids for list review markers if emitted separately: `medication:lists_touch:{pid}` or `medication:lists_touch:{stableRowKey}`. Because `lists_touch` has no id column, the implementation must define a stable record id strategy before emitting it as a drilldown source.
- Every emitted source must include `source_id`, `source_type`, `data_class`, `table`, `record_id`, `patient_id`, `date`, `status`, `display`, `excerpt`, `fields_used`, and `reliability`.
- Prescription-backed sources should use status semantics derived from `active`, `end_date`, and possibly `start_date`.
- Medication-list-backed sources should keep status semantics derived from `activity` and `enddate`.
- `fields_used` must be updated to truthfully include all new selected fields used in display, excerpt, status, or provenance.

Cap handling:

- Keep `max_documents = 0`; these are structured records, not documents.
- Keep `lookback_days = 365` unless later evidence shows current prescriptions outside the lookback need to be included.
- Revisit `max_records = 25` only if the implementation emits separate prescription and list-touch sources in addition to medication-list rows.
- If linked prescription fields are merged into medication-list sources, `max_records = 25` can likely remain unchanged.
- If standalone prescription and review-marker sources are emitted separately, use the existing cap as the total packet cap and reserve capacity in this order: medication-list rows, linked prescription details, standalone current prescriptions, list review marker.

#### Source Drilldown

Future implementation should update `Show source` only for source ids that can be resolved safely:

- Continue supporting `medication:lists:{id}` with `lists.pid = current patient pid` and `lists.type = 'medication'`.
- Continue supporting `medication:lists_medication:{id}` by joining back through `lists.id = lists_medication.list_id`, `lists.pid = current patient pid`, and `lists.type = 'medication'`.
- Add `medication:prescriptions:{id}` only with `prescriptions.patient_id = current patient pid`.
- Add `medication:lists_touch:{...}` only if a stable and safely resolvable record id strategy exists. Otherwise include the review date in the packet but do not create a clickable source id for it.
- A source id must never be enough by itself to retrieve a row. All drilldown queries must include current patient ownership in the `WHERE` clause.
- If a linked prescription id points to another patient or cannot be resolved, drop the prescription enrichment and record the source as missing/uncertain rather than exposing it.

#### Access Control And Data Classes

- Keep P7.2 under the existing `medications` data class.
- Keep authorization under the existing broker path for `patients/med`.
- Do not require `patients/demo`; demographics are not needed for P7.2.
- Do not require `patients/appt`; encounter details are not in P7.2 scope.
- Do not add new data classes unless a later policy decision separates prescriptions from medication-list records.
- The access token should still grant only the final data classes and tools authorized by the broker. Retrieval code must not infer extra permission from the intent label.

#### Response Content Rules

The final answer for `Current medications` should prefer short, separate claims:

- Medication name.
- Active/current status and relevant start/end date.
- Dosage instructions, dose, route, frequency/interval, quantity, and PRN status when available.
- Usage category and request intent when available.
- RxNorm code when available.
- Adherence status, adherence assertion date, and adherence information source when available.
- Prescription source/provenance, including OpenEMR vs external/eRx marker when available.
- Refill and substitution details when available and not misleading.
- Indication/diagnosis only when directly attached to the medication or prescription record.
- Whether a medication is a reported/non-primary record when `is_primary_record` indicates that.

The answer should also be explicit about missing or uncertain data:

- If no current medication records are found, say no matching current medication records were found in checked medication evidence.
- If a `lists_touch` medication review marker exists, include the review/touch date.
- If a linked prescription cannot be found or is not patient-owned, state that linked prescription evidence was unavailable or not used.
- If a medication-list row and prescription row conflict, preserve the conflict rather than choosing silently.
- Do not infer patient adherence from active prescription status.
- Do not infer active use from a stale prescription if the active/end-date fields do not support it.

#### Privacy And Logging

- Raw evidence may still go to the configured LLM provider only through the existing BAA-covered LLM path.
- Durable API logging must continue to use anonymized evidence only.
- Medication names, prescription notes, dosage instructions, indications, diagnoses, external ids, and eRx identifiers must be considered PHI-bearing.
- Keep prescription notes and dosage instructions bounded in excerpts to avoid overexposing free text.
- Do not include raw `drug_info_erx` by default.
- Do not include billing, claim, payment, or inventory data in this packet.

#### Implementation Steps For Later

1. Decide whether linked `prescriptions` data should be merged into medication-list sources or emitted as separate prescription sources.
2. Decide whether `lists_touch` should be emitted as a source with a stable source id or used only as packet-level review metadata.
3. Update `SqlEvidenceRecordRepository::fetchCurrentMedications()` to select the additional `lists_medication` fields.
4. Add a patient-owned linked-prescription join, requiring `prescriptions.patient_id = current patient pid`.
5. Add a bounded standalone current-prescription query for active/current patient prescriptions not already represented by `lists_medication.prescription_id`.
6. Add a bounded `lists_touch` medication review query.
7. Add or update mapping helpers for medication-list, linked-prescription, standalone-prescription, and medication-review evidence.
8. Update source id parsing and source drilldown only for newly emitted resolvable source ids.
9. Update `fields_used` for all medication evidence sources.
10. Add conservative deduplication between `lists`, `lists_medication`, and `prescriptions`.
11. Keep all SQL parameterized and patient-scoped.
12. Keep all source counts bounded by catalog caps.
13. Update deterministic fallback answers if needed so the no-LLM path still produces readable current-medication claims.
14. Update eval fixtures for missing, stale, conflicting, duplicate, and unauthorized medication evidence.

#### Test Plan For Later

- Unit test that existing `lists` + `lists_medication` current medication behavior still works.
- Unit test that newly selected `lists_medication` fields appear in source display/excerpt/fields-used when populated.
- Unit test that `is_primary_record = 0` is represented as a reported/non-primary medication.
- Unit test that adherence information source is included without overstating adherence.
- Unit test that linked prescriptions are included only when `prescriptions.patient_id` matches the current patient.
- Unit test that linked prescription ids pointing to another patient are ignored.
- Unit test that standalone active prescriptions are included when not represented by a `lists_medication.prescription_id`.
- Unit test that inactive or ended prescriptions are excluded from current medications unless policy explicitly includes them as uncertain/stale.
- Unit test that duplicate medication-list and prescription records do not produce duplicate claims.
- Unit test that conflicting medication-list and prescription details are preserved as missing/uncertain or conflicting evidence.
- Unit test that `lists_touch` medication markers are included when no active current medications are found.
- Unit test that `lists_touch` for another patient or another type is not included.
- Unit test that `checked_evidence` remains `medications`.
- Unit test that all emitted source ids can be drilled down only for the current patient.
- Unit test that invalid/tampered prescription source ids return no source.
- Controller or isolated service test that the response remains authorized by `patients/med`.
- Anonymizer test coverage for prescription notes, dosage instructions, medication names, diagnoses, indications, RxNorm/external ids, and eRx provenance values.
- Verifier test that claims cite only emitted medication sources.
- Regression test that `basic_patient_data`, `allergies_to_confirm`, `recent_events`, and `changed_since_last_visit` behavior does not change.

#### Acceptance Criteria

- Clicking `Current medications` retrieves a bounded medication evidence packet from current `lists`/`lists_medication` medication rows, patient-owned prescriptions, and the medication-list review marker when available.
- The packet stays within `data_class = medications`.
- The packet includes the must-have `lists_medication` fields: `usage_category`, `request_intent`, `medication_adherence_information_source`, `is_primary_record`, and `reporting_source_record_id`.
- The packet includes patient-owned `prescriptions` details for linked and standalone current prescriptions without exposing prescriptions from other patients.
- The packet can distinguish "no current medication records found" from "medication list reviewed/touched but no current medications found" when `lists_touch` evidence exists.
- The packet never includes allergies, problems, encounters, notes outside medication/prescription fields, documents, labs, billing, claims, payments, pharmacy inventory, dispense/sale history, or unrelated patient-data classes.
- The response cites every claim to a source id in the packet.
- Source drilldown works for every emitted source id or the implementation deliberately emits only source ids that can be drilled down safely.
- The response handles missing prescriptions, stale prescriptions, conflicting list/prescription data, and missing review markers without implying unverified absence.
- The implementation remains closed-intent and does not add any free-text retrieval path.
- Tests cover ownership, caps, deduplication, stale/current filtering, missing data, privacy exclusions, source drilldown, and regression behavior.

### P7.3 Detailed Plan: Expand `allergies_to_confirm`

#### Current Baseline

- The `Allergies to confirm` button maps to the `allergies_to_confirm` intent in [AgentIntentCatalog.php](src\Services\Agent\AgentIntentCatalog.php).
- The intent maps to the `get_allergies_to_confirm` evidence tool in [AgentEvidenceToolset.php](src\Services\Agent\Evidence\AgentEvidenceToolset.php).
- The evidence tool requires the `allergies` data class and is authorized through the existing `patients/med` access policy.
- The SQL repository method is `fetchAllergiesToConfirm()` in [SqlEvidenceRecordRepository.php](src\Services\Agent\Evidence\SqlEvidenceRecordRepository.php).
- The current SQL anchors on `lists` rows where `lists.pid = ?`, `lists.type = 'allergy'`, and the row is active/current by `activity`, `enddate`, or null `enddate`.
- The current SQL selects these `lists` fields: `id`, `uuid`, `pid`, `date`, `begdate`, `enddate`, `title`, `activity`, `comments`, `reaction`, `verification`, `severity_al`, and `modifydate`.
- The current SQL already resolves `reaction_title` through `list_options` where `list_id = 'reaction'`.
- The current SQL already resolves `verification_title` through `list_options` where `list_id = 'allergyintolerance-verification'`.
- The current response emits allergy sources with ids shaped like `allergy:lists:{id}`.
- The current `fields_used` list is `title`, `activity`, `reaction`, `verification`, and `severity_al`.
- The current intent cap is `max_records = 25`, `max_documents = 0`, and `lookback_days = 365`.

#### Goal

Expand the `allergies_to_confirm` evidence packet so it can represent active allergy/intolerance records more completely while keeping the intent narrowly focused on confirming allergy evidence. The expanded packet should add list-review context, coded allergen details, external/eRx provenance, allergy subtype/diagnosis metadata, and human-readable severity labels when `severity_al` is coded.

The answer should remain an allergy confirmation summary. It should not become a medication interaction checker, clinical decision rule workflow, allergy assessment workflow, or free-form chart search.

#### Non-Goals

- Do not add a new UI button as part of P7.3. The existing `Allergies to confirm` button should keep working.
- Do not add free-text routing. This remains a closed-intent retrieval path.
- Do not create or modify schema.
- Do not include medication records, prescriptions, medication fills, or pharmacy inventory.
- Do not include encounters, forms, notes, SOAP notes, dictation, documents, labs, procedures, orders, immunizations, billing, claims, payments, reminders, clinical-rule logs, or audit logs.
- Do not add allergy clinical-decision-rule evidence from `clinical_rules`, `rule_*`, or CDR tables.
- Do not dereference external/eRx ids into remote systems.
- Do not interpret allergy severity, verification, or reaction codes beyond the labels stored in local structured tables.
- Do not infer absence of allergies globally unless both active allergy rows and the allergy-list review marker were checked.

#### In-Scope Tables

1. `lists`
   - Purpose: allergy/intolerance anchor records already used by the current implementation.
   - Ownership rule: `lists.pid = current patient pid` and `lists.type = 'allergy'`.
   - Continue using this as the primary allergy source.
   - P7.3 adds missing allergy metadata fields from the same patient-owned row.

2. `lists_touch`
   - Purpose: review marker for list-level allergy attestation/touch events.
   - Ownership rule: `lists_touch.pid = current patient pid` and `lists_touch.type = 'allergy'`.
   - Use only to indicate that the allergy list was reviewed/touched, especially when no active allergies are found.
   - Do not treat `lists_touch` as an allergy record.

3. `list_options` for coded allergen lookup
   - Purpose: resolve `lists.list_option_id` into a coded/local allergen label and optional code payload.
   - Candidate lookup: `list_options.option_id = lists.list_option_id`, normally under the allergy issue list, for example `list_options.list_id = 'allergy_issue_list'`.
   - Implementation must confirm the local list id convention before joining. If the list id cannot be determined safely, carry `lists.list_option_id` as a raw coded reference and do not do a broad `option_id`-only lookup.

4. `list_options` for severity lookup
   - Purpose: resolve `lists.severity_al` into a human-readable severity label and optional code payload when `severity_al` stores an option id.
   - Implementation must identify the correct severity list id before joining. If `severity_al` is already display text or the list id is ambiguous, do not do a broad lookup; keep the existing raw severity value.

#### `lists` Field Plan

Continue selecting the existing fields:

- `id`: allergy-list record id.
- `uuid`: allergy-list UUID.
- `pid`: patient id.
- `date`: list record date.
- `begdate`: allergy/intolerance begin date.
- `enddate`: allergy/intolerance end date.
- `title`: allergen/substance title.
- `activity`: active flag.
- `comments`: allergy comments.
- `reaction`: reaction code/value.
- `verification`: verification status code/value.
- `severity_al`: allergy severity value.
- `modifydate`: list record last modification timestamp.

Add these must-have `lists` fields:

- `list_option_id`: local coded allergen/list option reference.
- `external_allergyid`: external eRx allergy id.
- `external_id`: external allergy/list id.
- `erx_source`: whether the allergy came from OpenEMR or an eRx/external source.
- `erx_uploaded`: whether the allergy was uploaded to the eRx system.
- `subtype`: local allergy subtype/classification, if populated.
- `diagnosis`: diagnosis/reason metadata attached to the allergy row.

Field usage rules:

- `list_option_id` should be displayed only with a resolved label when the lookup is safe. Otherwise include it as a coded reference in source detail, not as a patient-facing claim.
- `external_allergyid`, `external_id`, `erx_source`, and `erx_uploaded` should be treated as provenance, not as clinical facts.
- `subtype` should be included only when non-empty and clearly allergy-related.
- `diagnosis` should be included only when directly attached to the allergy row and should not be expanded into problem-list evidence.
- `comments` should remain bounded in excerpts because it is free text.
- `reaction`, `verification`, and `severity_al` should keep existing raw values and add resolved labels where available.

#### `lists_touch` Field Plan

Select:

- `pid`: patient id.
- `type`: list type; must be `allergy`.
- `date`: last touch/review date.

Use rules:

- Read only rows where `pid = current patient pid` and `type = 'allergy'`.
- Emit at most one `lists_touch` source or review marker, preferably the latest by `date DESC`.
- Use `lists_touch` only as an allergy-list review marker.
- If active allergy records exist, the review marker can be included as secondary provenance or omitted from the main answer if it adds noise.
- If no active allergy records exist but a `lists_touch` row exists, answer that no current allergy records were found in checked allergy evidence and that the allergy list has a review/touch marker dated `date`.
- If no active allergy records and no `lists_touch` row exist, answer that no matching active allergy records were found in checked evidence and that no allergy-list review marker was found.

#### Coded Allergen Lookup Plan

Use `list_options` to resolve `lists.list_option_id` only when the list id is known and safe.

Fields to select:

- `list_id`: list-options list id used for the allergen lookup.
- `option_id`: coded allergen option id.
- `title`: coded allergen title.
- `codes`: optional coding payload.

Lookup rules:

- Join only from a patient-owned `lists` allergy row.
- Prefer a specific list id such as `allergy_issue_list` if the local schema/data convention confirms it.
- Do not use a global `option_id = lists.list_option_id` lookup without list-id restriction, because option ids are not globally unique across all lists.
- If no safe lookup is possible, do not fail the allergy row; keep `lists.title` as the primary allergen display and include `list_option_id` only as a source field.
- If both `lists.title` and resolved `list_options.title` exist and differ materially, preserve both as separate source details or mark uncertainty instead of silently overwriting one.

#### Severity Lookup Plan

Use `list_options` to resolve `lists.severity_al` only when `severity_al` is coded and the correct list id is known.

Fields to select:

- `option_id`: severity option id.
- `title`: severity title.
- `codes`: optional coding payload.

Lookup rules:

- Join only from a patient-owned `lists` allergy row.
- Confirm the severity list id before implementation. Do not guess with a broad `option_id` lookup.
- If `severity_al` is already text, use the existing raw value and skip lookup.
- If `severity_al` is empty, do not add a severity claim.
- If severity code and severity title conflict or cannot be resolved, include the raw value as source detail and mark the severity as uncertain if surfaced in the answer.

#### Evidence Shape

Preferred implementation shape:

- Keep `data_class = allergies` for all P7.3 sources.
- Keep `source_type = allergy` for true allergy/intolerance records.
- Consider `source_type = allergy_review` for `lists_touch`.
- Continue to emit `source_id = allergy:lists:{id}` for allergy rows.
- Add a review-marker source id only if it can be resolved safely despite `lists_touch` having no id column, for example `allergy:lists_touch:{stableRowKey}`. If no stable row key is chosen, include the review marker as packet-level metadata rather than a clickable source.
- Do not emit standalone `list_options` sources. Coded allergen and severity lookups should enrich the allergy source; they are not patient-owned records by themselves.
- Every emitted patient-owned source must include `source_id`, `source_type`, `data_class`, `table`, `record_id`, `patient_id`, `date`, `status`, `display`, `excerpt`, `fields_used`, and `reliability`.
- Allergy row status should continue to use verification status where available, otherwise active/current status from `activity` and `enddate`.
- `fields_used` must be updated to truthfully include all new selected fields used in display, excerpt, status, or provenance.

Cap handling:

- Keep `max_documents = 0`; these are structured records, not documents.
- Keep `lookback_days = 365` unless later evidence shows active allergy records outside the lookback need to be included.
- Keep `max_records = 25` unless the implementation emits `lists_touch` as an extra source in addition to allergy rows.
- If `lists_touch` is emitted as a source, reserve one slot for the review marker only after active allergy rows have been collected, or include it only when no active allergy rows are found.

#### Source Drilldown

Future implementation should update `Show source` only for source ids that can be resolved safely:

- Continue supporting `allergy:lists:{id}` with `lists.pid = current patient pid` and `lists.type = 'allergy'`.
- Add newly selected `lists` fields to the source drilldown output.
- Include resolved coded allergen and severity labels in source drilldown only as enrichment from the patient-owned allergy row.
- Add `allergy:lists_touch:{...}` only if a stable and safely resolvable record id strategy exists. Otherwise include the review date in the packet but do not create a clickable source id for it.
- A source id must never be enough by itself to retrieve a row. All drilldown queries must include current patient ownership in the `WHERE` clause.
- If a coded allergen or severity lookup is ambiguous, omit the enrichment rather than doing an unsafe lookup.

#### Access Control And Data Classes

- Keep P7.3 under the existing `allergies` data class.
- Keep authorization under the existing broker path for `patients/med`.
- Do not require `patients/demo`; demographics are not needed for P7.3.
- Do not require `patients/appt`; encounter details are not in P7.3 scope.
- Do not add new data classes unless a later policy decision separates eRx provenance or coded vocabularies from allergy records.
- The access token should still grant only the final data classes and tools authorized by the broker. Retrieval code must not infer extra permission from the intent label.

#### Response Content Rules

The final answer for `Allergies to confirm` should prefer short, separate claims:

- Allergen/substance name from `lists.title`.
- Coded allergen title and code when `lists.list_option_id` safely resolves.
- Reaction label/value.
- Severity label/value.
- Verification status.
- Active/current status and relevant begin/end date.
- Comments or notes only as bounded excerpts.
- eRx/external provenance when useful and not noisy.
- Subtype and diagnosis only when directly attached to the allergy row.
- Allergy-list review/touch date when no active allergy rows are found or when the review marker is needed for provenance.

The answer should also be explicit about missing or uncertain data:

- If no current allergy records are found, say no matching current allergy records were found in checked allergy evidence.
- If a `lists_touch` allergy review marker exists, include the review/touch date.
- If a coded allergen lookup cannot be safely resolved, do not imply the free-text title is coded.
- If severity cannot be safely resolved, show raw severity only as source detail or mark severity as uncertain.
- If reaction, severity, or verification fields are empty, do not invent them.
- Do not infer absence of allergies globally unless allergy rows and the allergy-list review marker were both checked.

#### Privacy And Logging

- Raw evidence may still go to the configured LLM provider only through the existing BAA-covered LLM path.
- Durable API logging must continue to use anonymized evidence only.
- Allergy substance names, reactions, comments, diagnoses, external ids, and eRx identifiers must be considered PHI-bearing.
- Keep allergy comments bounded in excerpts to avoid overexposing free text.
- Do not include clinical-rule logs, billing, claim, payment, medication, or unrelated chart data in this packet.

#### Implementation Steps For Later

1. Update `SqlEvidenceRecordRepository::fetchAllergiesToConfirm()` to select the additional must-have `lists` fields.
2. Add a bounded `lists_touch` allergy review query.
3. Confirm the safe `list_options` list id for `lists.list_option_id` allergy lookup, likely `allergy_issue_list`, before adding the join.
4. Confirm whether `severity_al` stores option ids and identify the correct severity list id before adding the severity lookup.
5. Add or update mapping helpers for allergy records, coded allergen enrichment, severity enrichment, and allergy-review evidence.
6. Decide whether `lists_touch` should be emitted as a source with a stable source id or used only as packet-level review metadata.
7. Update source id parsing and source drilldown only for newly emitted resolvable source ids.
8. Update `fields_used` for allergy evidence sources.
9. Keep all SQL parameterized and patient-scoped.
10. Keep all source counts bounded by catalog caps.
11. Update deterministic fallback answers if needed so the no-LLM path still produces readable allergy confirmation claims.
12. Update eval fixtures for missing, stale, conflicting, duplicate, coded, uncoded, and unauthorized allergy evidence.

#### Test Plan For Later

- Unit test that existing active `lists` allergy behavior still works.
- Unit test that newly selected `lists` fields appear in source display/excerpt/fields-used when populated.
- Unit test that `list_option_id` is resolved only through a safe list-id-restricted `list_options` lookup.
- Unit test that ambiguous `list_option_id` values do not trigger a broad unsafe lookup.
- Unit test that coded allergen title/code enrichment is included when safely resolved.
- Unit test that conflicting free-text title and coded allergen title are preserved or marked uncertain.
- Unit test that severity lookup is included only when `severity_al` is coded and the severity list id is known.
- Unit test that raw severity text still works when no severity lookup is available.
- Unit test that `external_allergyid`, `external_id`, `erx_source`, and `erx_uploaded` are treated as provenance.
- Unit test that `lists_touch` allergy markers are included when no active current allergies are found.
- Unit test that `lists_touch` for another patient or another type is not included.
- Unit test that `checked_evidence` remains `allergies`.
- Unit test that all emitted source ids can be drilled down only for the current patient.
- Unit test that invalid/tampered allergy source ids return no source.
- Controller or isolated service test that the response remains authorized by `patients/med`.
- Anonymizer test coverage for allergy names, reactions, comments, diagnoses, external ids, eRx provenance, and coded allergen labels.
- Verifier test that claims cite only emitted allergy sources.
- Regression test that `basic_patient_data`, `current_medications`, `recent_events`, and `changed_since_last_visit` behavior does not change.

#### Acceptance Criteria

- Clicking `Allergies to confirm` retrieves a bounded allergy evidence packet from active/current patient-owned `lists` allergy rows and the allergy-list review marker when available.
- The packet stays within `data_class = allergies`.
- The packet includes the must-have additional `lists` fields: `list_option_id`, `external_allergyid`, `external_id`, `erx_source`, `erx_uploaded`, `subtype`, and `diagnosis`.
- The packet resolves coded allergen labels/codes through `list_options` only when the list id is safe and specific.
- The packet resolves severity labels/codes through `list_options` only when `severity_al` is coded and the severity list id is safe and specific.
- The packet can distinguish "no current allergy records found" from "allergy list reviewed/touched but no current allergies found" when `lists_touch` evidence exists.
- The packet never includes medications, prescriptions, encounters, notes outside allergy fields, documents, labs, billing, claims, payments, clinical-rule logs, or unrelated patient-data classes.
- The response cites every claim to a source id in the packet.
- Source drilldown works for every emitted source id or the implementation deliberately emits only source ids that can be drilled down safely.
- The response handles missing coded lookup, missing severity lookup, empty reaction/severity/verification fields, and missing review markers without implying unverified absence.
- The implementation remains closed-intent and does not add any free-text retrieval path.
- Tests cover ownership, caps, coded lookup safety, severity lookup safety, missing data, privacy exclusions, source drilldown, and regression behavior.
