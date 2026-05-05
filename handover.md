# Intake Forms — Close-Out Handover

Self-contained close-out document for the OpenEMR intake-forms feature
(see [`intake-forms-plan.md`](intake-forms-plan.md)). A fresh session can
finish the feature without seeing the prior conversation by working through
the steps below.

---

## 1. Status snapshot

All planned code has been merged on `master` and pushed to both the `origin`
(GitHub) and `gitlab` remotes. The 43 isolated tests in the `intake-forms`
PHPUnit group pass cleanly (`OK (43 tests, 160 assertions)`). The feature
has not yet been end-to-end-tested in a running OpenEMR instance, the
Doctrine migration ([`db/Migrations/Version20260504000001.php`](db/Migrations/Version20260504000001.php))
has not been applied to a live database, and the registry row that surfaces
the "Upload Intake Form" entry under the encounter Administrative dropdown
has therefore not yet been inserted on any environment.

---

## 2. What's already built

Mapping of every `intake-forms-plan.md` §3.x to the actual artifacts and
the commit-message scope (`(intake-forms)`).

- **§3.1 Generator script** — Done (deferred decisions). `generate-intake-form.ps1` (950 lines). Last touched in `feat(intake-forms): pull carriers from insurance_companies; fix clinic identity` and `feat(intake-forms): add generate-intake-form.ps1 generator script`. Decisions: (a) carriers pulled from `insurance_companies` table, (b) clinic identity fixed to "Maple Grove Primary Care, 1820 N Walnut Ave, Springfield IL 62702".
- **§3.2 Encounter menu item (registry INSERT)** — Done. Migration [`db/Migrations/Version20260504000001.php`](db/Migrations/Version20260504000001.php) and the upgrade SQL [`sql/8_1_0-to-8_1_1_upgrade.sql`](sql/8_1_0-to-8_1_1_upgrade.sql) both insert the registry row (`category=Administrative`, `aco_spec=admin|super`, `directory=upload_intake_form`). Commit: `feat(intake-forms): migration for upload_intake_form table and registry row` and `dcc29b544`.
- **§3.3 `interface/forms/upload_intake_form/`** — Done (deferred decisions). [`info.txt`](interface/forms/upload_intake_form/info.txt), [`new.php`](interface/forms/upload_intake_form/new.php), [`save.php`](interface/forms/upload_intake_form/save.php), [`report.php`](interface/forms/upload_intake_form/report.php), [`view.php`](interface/forms/upload_intake_form/view.php), [`table.sql`](interface/forms/upload_intake_form/table.sql). Commits: `feat(intake-forms): add upload_intake_form encounter form`, `fix(intake-forms): wire save.php to IngestResult DTO and Intake namespace`, `chore(intake-forms): baseline upload_intake_form_report global function`. Decisions: (a) `upload_intake_form_report` shipped via PHPStan baseline (matches every other form's pattern), (b) wire-level form_type vocabulary unified to `Auto` / `Demographics` / `MedicalHistory` / `Consent`.
- **§3.4 Server-side ingestion logic** — Done (deferred decisions). [`src/Services/Intake/IntakeFormIngestService.php`](src/Services/Intake/IntakeFormIngestService.php) plus dispatchers in [`src/Services/Intake/Dispatcher/`](src/Services/Intake/Dispatcher/). Commits: `feat(intake-forms): add OpenAI client and ingest service`, `refactor(intake-forms): extract testable classifier/schema/FHIR helpers`, `fix(intake-forms): align form_upload_intake_form with canonical schema`, `chore(intake-forms): raise classifier confidence threshold default to 0.7`. Decisions: (a) classifier confidence threshold raised from 0.6 to 0.7, (b) Demographics merge runs in fill-only-empty mode (`DemographicsDispatcher::$fillOnlyEmpty=true` by default), (c) the original PDF is stored under the Documents module for **all three** form types (not just Consent).
- **§3.5 OpenAI PHP client** — Done. [`src/Services/Intake/OpenAi/OpenAIClient.php`](src/Services/Intake/OpenAi/OpenAIClient.php) plus [`OpenAIStructuredRequest`](src/Services/Intake/OpenAi/OpenAIStructuredRequest.php) and the typed exceptions in [`src/Services/Intake/OpenAi/Exception/`](src/Services/Intake/OpenAi/Exception/) (`OpenAIException`, `OpenAIMissingKeyException`, `OpenAIRateLimitException`, `OpenAIRequestFailedException`, `OpenAISchemaMismatchException`). Same commits as §3.4.
- **§3.6 Documentation** — Done. The user-facing plan docs are checked in (`intake-forms-plan.md`, `intake-forms.md`); inline PHP docblocks live with the code.
- **§3.7 Tests** — Done (deferred decisions). Three isolated test files: [`tests/Tests/Isolated/Common/Services/IntakeFormClassifierPromptTest.php`](tests/Tests/Isolated/Common/Services/IntakeFormClassifierPromptTest.php), [`tests/Tests/Isolated/Common/Services/IntakeFormSchemaTest.php`](tests/Tests/Isolated/Common/Services/IntakeFormSchemaTest.php), [`tests/Tests/Isolated/Common/Services/QuestionnaireResponseBuilderTest.php`](tests/Tests/Isolated/Common/Services/QuestionnaireResponseBuilderTest.php). One E2E test: [`tests/Tests/E2e/UploadIntakeFormTest.php`](tests/Tests/E2e/UploadIntakeFormTest.php). Commit: `test(intake-forms): isolated unit tests + Panther E2E for upload flow`. Decision: the plan asked for Cypress; this repo's E2E layer is PHPUnit + Symfony Panther + Selenium, so Panther was used.
- **§3.8 Migrations / installation** — Done. [`db/Migrations/Version20260504000001.php`](db/Migrations/Version20260504000001.php) creates `form_upload_intake_form` and inserts the registry row. The mirror SQL is in [`sql/8_1_0-to-8_1_1_upgrade.sql`](sql/8_1_0-to-8_1_1_upgrade.sql) (gated on `#IfNotTable` / `#IfNotRow2D`). Commit: `feat(intake-forms): migration for upload_intake_form table and registry row`.

Won't ship: nothing from the §3 plan was dropped.

---

## 3. Close-out items (in order of execution)

### 3.a Apply the Doctrine migration

**Goal.** Create `form_upload_intake_form` and insert the registry row that
surfaces "Upload Intake Form" in the encounter Administrative dropdown.

**Steps.** From the repo root in a host shell:

```bash
cd docker/development-easy
docker compose up --detach --wait
```

Then apply the migration. OpenEMR ships a `cli` script at the repo root that
wraps Doctrine Migrations (see [`db/README.md`](db/README.md) and
[`db/migration-config.php`](db/migration-config.php)). The exec wrapper used
by the dev stack:

```bash
docker compose exec openemr php /var/www/localhost/htdocs/openemr/cli migrate
```

Follow the prompts to apply pending migrations.

If the `cli` migration runner is not yet wired up on the running stack
(per the warning in [`db/README.md`](db/README.md): "The Doctrine Migrations
system is NOT fully integrated into OpenEMR yet"), apply the upgrade SQL
through the standard OpenEMR upgrade path instead — open
`http(s)://localhost:8300/sql_upgrade.php` in a browser and run the
`8.1.0 to 8.1.1` upgrade. The same `CREATE TABLE` / registry `INSERT` lives
in [`sql/8_1_0-to-8_1_1_upgrade.sql`](sql/8_1_0-to-8_1_1_upgrade.sql) and is
gated on `#IfNotTable form_upload_intake_form` / `#IfNotRow2D registry
directory upload_intake_form`.

**Verification queries** (run against the dev DB; the easy stack ships
phpMyAdmin at `http://localhost:8310/`):

```sql
-- Table created with the canonical OpenEMR form-table layout.
SHOW CREATE TABLE form_upload_intake_form;

-- Registry row present and active.
SELECT name, state, directory, category, aco_spec
FROM registry
WHERE directory = 'upload_intake_form';
-- expected: ('Upload Intake Form', 1, 'upload_intake_form', 'Administrative', 'admin|super')
```

**Expected outcome.** The table exists with the canonical OpenEMR form columns
(`id`, `date`, `pid`, `encounter`, `user`, `groupname`, `authorized`,
`activity`) plus the four intake-specific columns (`form_type`,
`document_id`, `inserted_row_id`, `diff_preview`). The registry row appears
with `state=1`. After a hard refresh of the encounter view, the
Administrative dropdown shows "Upload Intake Form".

**What to do if it fails.** If the migration aborts mid-run, roll back with
`docker compose exec openemr php /var/www/localhost/htdocs/openemr/cli migrate prev`
and inspect [`db/Migrations/Version20260504000001.php`](db/Migrations/Version20260504000001.php)
for ENUM/charset issues (the `form_type` column uses an explicit
`columnDefinition` to keep the ENUM literal). If the SQL upgrade path is
used and the registry row is duplicated by hand, delete with
`DELETE FROM registry WHERE directory='upload_intake_form'` and re-run.

**Time estimate.** 15 minutes.

### 3.b End-to-end smoke test

**Goal.** Verify the full upload flow against a real OpenEMR instance for
each of the three form types.

**Steps.** Start fresh from the dev stack (`docker/development-easy`).
Generate one synthetic PDF of each type using the script in §3.1. Replace
`<PID>` with a real patient id on the dev stack:

```bash
pwsh ./generate-intake-form.ps1 -PatientId <PID> -FormType Demographics
pwsh ./generate-intake-form.ps1 -PatientId <PID> -FormType MedicalHistory
pwsh ./generate-intake-form.ps1 -PatientId <PID> -FormType Consent
```

PDFs land in `intake-forms/intake-<type>-<pid>-<timestamp>.pdf`.

In the browser at `http://localhost:8300/`:

1. Log in as `admin` / `pass`.
2. Open a patient (any patient with `pid=<PID>`).
3. Open or create an encounter.
4. In the encounter forms navbar, click `Administrative -> Upload Intake Form`.
5. For each PDF in turn: choose the matching form type from the dropdown
   (or leave on `Auto-detect` to exercise the classifier), pick the file,
   click Upload.
6. After each upload, verify the encounter timeline shows a new
   `Medical History (Intake Upload)` row (for MedicalHistory) or a new
   `Upload Intake Form` row (for the other two).

**Verification SQL** (run after each upload):

```sql
-- Common to all three: row in form_upload_intake_form, document stored.
SELECT id, pid, encounter, form_type, document_id, inserted_row_id
FROM form_upload_intake_form
WHERE encounter = <ENCOUNTER_ID>
ORDER BY id DESC;

-- Demographics specifically: patient_data updated for empty columns,
-- insurance_data row inserted/updated.
SELECT pid, fname, lname, DOB, sex, street, city, state, postal_code, phone_home, email
FROM patient_data WHERE pid = <PID>;

SELECT id, pid, type, provider, policy_number, group_number, plan_name
FROM insurance_data WHERE pid = <PID> AND type = 'primary';

-- MedicalHistory: questionnaire_response + form_questionnaire_assessments
-- + forms timeline row.
SELECT id, response_id, questionnaire_id, patient_id, encounter
FROM questionnaire_response
WHERE patient_id = <PID> AND encounter = <ENCOUNTER_ID>;

SELECT id, response_id, pid, form_name, questionnaire_id
FROM form_questionnaire_assessments
WHERE pid = <PID>;

SELECT id, encounter, form_name, form_id, formdir
FROM forms
WHERE encounter = <ENCOUNTER_ID> AND formdir = 'upload_intake_form';

-- Consent: document stored under the "Consents" category (auto-created on
-- first use). Demographics goes under "Patient Information"; MedicalHistory
-- goes under "Medical Record".
SELECT d.id, d.name, d.mimetype, c.name AS category
FROM documents d
JOIN categories_to_documents ctd ON ctd.document_id = d.id
JOIN categories c ON c.id = ctd.category_id
WHERE d.id IN (
  SELECT document_id FROM form_upload_intake_form WHERE pid = <PID>
);

-- Confirm the Consents category was created.
SELECT id, name, parent FROM categories WHERE name = 'Consents';
```

**Expected outcome.**
- Demographics: row present in `form_upload_intake_form` (form_type=`Demographics`); empty columns in `patient_data` filled (existing values preserved per fill-only-empty); `insurance_data` row upserted; PDF stored under "Patient Information".
- MedicalHistory: row in `questionnaire_response` (with FHIR JSON in `questionnaire_response`); row in `form_questionnaire_assessments`; row in `forms` (`formdir=upload_intake_form`); row in `form_upload_intake_form`; PDF stored under "Medical Record"; encounter timeline shows the new entry.
- Consent: row in `form_upload_intake_form`; PDF stored under "Consents" (category auto-created if missing); no patient-data writes.

**What to do if it fails.**
- "Upload Intake Form" not in dropdown → verify §3.a registry row.
- Save fails with "Not authorized" → user is not in the `admin|super` ACL group; switch to `admin`.
- OpenAI errors (rate limit, missing key) → check `OPENAI_API_KEY` env var inside the container (`docker compose exec openemr printenv OPENAI_API_KEY`); the client surfaces typed exceptions ([`OpenAIMissingKeyException`](src/Services/Intake/OpenAi/Exception/OpenAIMissingKeyException.php), [`OpenAIRateLimitException`](src/Services/Intake/OpenAi/Exception/OpenAIRateLimitException.php)) so the PHP error log will name the cause.
- Demographics merge wrote nothing → expected when every target column already had a value (fill-only-empty); inspect `diff_preview` JSON in `form_upload_intake_form` for the per-field reasons.

**Time estimate.** 60 minutes (15 per form type, plus debug headroom).

### 3.c Verify PHPStan baseline still applies

**Goal.** Confirm no new PHPStan errors slipped in and the one new baseline
entry near line 1548 of `.phpstan/baseline/openemr.noGlobalNsFunctions.php`
is still load-bearing.

**Steps.**

```bash
composer phpstan
```

Then verify the new baseline entry survives a regenerate:

```bash
composer phpstan-baseline
git diff .phpstan/baseline/
```

**Expected outcome.** PHPStan passes. The diff shows no spurious changes —
the `upload_intake_form_report` entry stays in
[`.phpstan/baseline/openemr.noGlobalNsFunctions.php`](.phpstan/baseline/openemr.noGlobalNsFunctions.php)
near line 1548. No other intake-forms files appear in any baseline.

**What to do if it fails.** PHPStan errors on intake-forms files mean the
type contract drifted somewhere — fix at the source (per `CLAUDE.md`'s
"Fix at the source, not the sink"), do not paper over with new baseline
entries.

**Time estimate.** 10 minutes.

---

## 4. Decisions made on the user's behalf

| Question | Decision | Where it lives in the code | How to revert |
|----------|----------|----------------------------|---------------|
| §3.4 Q3 — auto-classifier confidence threshold | Raised default from 0.6 to 0.7 | [`IntakeFormIngestService::CLASSIFIER_THRESHOLD`](src/Services/Intake/IntakeFormIngestService.php) (line 54) | Pass `classifierThreshold: 0.6` to the constructor, or change the constant. |
| §3.3 — `upload_intake_form_report` global function | Added a single PHPStan baseline entry instead of refactoring `FormReportRenderer` | [`.phpstan/baseline/openemr.noGlobalNsFunctions.php`](.phpstan/baseline/openemr.noGlobalNsFunctions.php) line ~1548 | Refactor [`report.php`](interface/forms/upload_intake_form/report.php) to a class method once `FormReportRenderer` is class-based; remove the baseline line. |
| §3.1 — synthetic insurance carriers | Pulled from the live `insurance_companies` table (up to 25 active rows, falls back to LLM-open if empty) | [`generate-intake-form.ps1`](generate-intake-form.ps1) carrier-fetch query | Hard-code or remove the carrier enum injection in the script. |
| §3.1 — consent letterhead clinic identity | Fixed default: "Maple Grove Primary Care, 1820 N Walnut Ave, Springfield IL 62702"; CLI overrides via `-ClinicName` / `-ClinicAddress` | [`generate-intake-form.ps1`](generate-intake-form.ps1) param block (lines 29–30) | Pass `-ClinicName` / `-ClinicAddress`, or change the param defaults. |
| §3.4 Q2 — Demographics merge mode | Fill-only-empty (existing non-empty columns preserved) | [`DemographicsDispatcher::$fillOnlyEmpty=true`](src/Services/Intake/Dispatcher/DemographicsDispatcher.php) constructor default | Pass `fillOnlyEmpty: false` from `save.php` when constructing the dispatcher. |
| §3.4 — PDF storage scope | The original PDF is saved into the Documents module for **all three** form types (not only Consent). Demographics goes under "Patient Information", MedicalHistory under "Medical Record", Consent under "Consents" (auto-created on first use). | [`IntakeFormIngestService::resolveCategoryId()`](src/Services/Intake/IntakeFormIngestService.php) | Restrict the `storeOriginalPdf()` call in `IntakeFormIngestService::ingest()` to the Consent path. |
| §3.7 — E2E framework | Symfony Panther replaced Cypress (no Cypress runner exists in this repo) | [`tests/Tests/E2e/UploadIntakeFormTest.php`](tests/Tests/E2e/UploadIntakeFormTest.php) extends `PantherTestCase` | Add a Cypress harness — see `intake-forms-plan.md` §3.7 for the original ask. |
| `IngestResult.insertedRowId` semantics | Holds the `form_upload_intake_form.id`, **not** the dispatcher's row id (e.g. not `questionnaire_response.id`). The dispatcher row id lives separately on `DispatchOutcome::insertedRowId`. | [`IntakeFormIngestService::ingest()`](src/Services/Intake/IntakeFormIngestService.php) lines 140–164 and the [`IngestResult`](src/Services/Intake/IngestResult.php) docblock (lines 27–35) | Returning the dispatcher id would break `FormService::addForm()`, which needs the form-table row id. |

---

## 5. Open questions still genuinely unresolved

- **§3.4 Q4 — Document category name.** "Consents" is created on first use,
  but no UX review has confirmed this name or its placement under
  `parent=1` ([`IntakeFormIngestService::createConsentsCategory()`](src/Services/Intake/IntakeFormIngestService.php),
  see `CONSENT_CATEGORY_PARENT_ID = 1`). Demographics goes under "Patient
  Information" and MedicalHistory under "Medical Record"; if those
  categories don't exist in a fresh install the code falls back to
  `parent=1` silently rather than creating them.
- **§3.4 Q5 — Preview/edit before commit.** The current flow commits
  immediately. The `diff_preview` JSON column on `form_upload_intake_form`
  captures what changed, but there is no UI to gate the write — this was
  never started.
- **Multi-worktree autoload quirk.** The `vendor/` directory only exists in
  the main checkout (`C:\Users\s-109\OneDrive\Dev\Gauntlet\openemr`); the
  worktree at `.claude/worktrees/agent-ad59b4ba875ff1b40/` does not have
  its own vendor tree. New PSR-4 classes added in a worktree will not
  resolve via the main repo's autoloader until the main repo's
  `composer dump-autoload` is run. Tests run cleanly from the main
  worktree once that's done; running tests directly from the agent
  worktree fails with "phpunit not recognized" because `vendor/bin/`
  is missing. This is a development-environment quirk, not a code defect.
- **Live ACL.** The plan pinned `aco_spec=admin|super` (§3.2). No reception
  / front-desk role has been wired up. Real deployments will likely need a
  refined ACL.

---

## 6. How to run the test suite

The vendor tree only lives in the main checkout (see §5). Run from the main
repo path, not from the worktree:

```bash
# From the main repo root: C:/Users/s-109/OneDrive/Dev/Gauntlet/openemr
# Required after pulling new PSR-4 classes — refreshes the autoload map.
composer dump-autoload

# Isolated suite (no Docker, no DB).
composer phpunit-isolated -- --group intake-forms
# Expected: OK (43 tests, 160 assertions)

# Isolated suite, single class.
composer phpunit-isolated -- --filter IntakeFormSchemaTest

# Full Docker-backed suites — run from docker/development-easy.
cd docker/development-easy
docker compose exec openemr /root/devtools clean-sweep-tests
docker compose exec openemr /root/devtools unit-test
docker compose exec openemr /root/devtools e2e-test    # runs UploadIntakeFormTest

# PHPStan and code style on the host.
composer code-quality
```

The E2E test [`tests/Tests/E2e/UploadIntakeFormTest.php`](tests/Tests/E2e/UploadIntakeFormTest.php)
self-skips when `interface/forms/upload_intake_form/` is absent; once §3.a
lands the directory is present and the test runs against the live UI.

---

## 7. Quick reference — file map

| File | Purpose | Owning §3.x |
|------|---------|--------------|
| [`generate-intake-form.ps1`](generate-intake-form.ps1) | PowerShell synthetic-PDF generator (Demographics / MedicalHistory / Consent). | §3.1 |
| [`db/Migrations/Version20260504000001.php`](db/Migrations/Version20260504000001.php) | Doctrine migration: creates `form_upload_intake_form`, inserts the registry row. | §3.2 + §3.8 |
| [`sql/8_1_0-to-8_1_1_upgrade.sql`](sql/8_1_0-to-8_1_1_upgrade.sql) | Mirror upgrade SQL (gated on `#IfNotTable` / `#IfNotRow2D`). | §3.2 + §3.8 |
| [`interface/forms/upload_intake_form/info.txt`](interface/forms/upload_intake_form/info.txt) | One-line form description ("Upload Intake Form"). | §3.3 |
| [`interface/forms/upload_intake_form/new.php`](interface/forms/upload_intake_form/new.php) | Upload UI (file picker + form-type dropdown + CSRF token). | §3.3 |
| [`interface/forms/upload_intake_form/save.php`](interface/forms/upload_intake_form/save.php) | Multipart-upload handler; constructs `IntakeFormIngestService` with all six DI dependencies and wires the result into `FormService::addForm()`. | §3.3 + §3.4 |
| [`interface/forms/upload_intake_form/report.php`](interface/forms/upload_intake_form/report.php) | Encounter-timeline renderer (defines the `upload_intake_form_report` global function). | §3.3 |
| [`interface/forms/upload_intake_form/view.php`](interface/forms/upload_intake_form/view.php) | Read-only form view by `form_upload_intake_form.id`. | §3.3 |
| [`interface/forms/upload_intake_form/table.sql`](interface/forms/upload_intake_form/table.sql) | Canonical schema reference for `form_upload_intake_form`. | §3.3 + §3.8 |
| [`src/Services/Intake/IntakeFormType.php`](src/Services/Intake/IntakeFormType.php) | Backed enum (`Demographics` / `MedicalHistory` / `Consent`). `fromRequest()` parses the wire vocabulary. | §3.4 |
| [`src/Services/Intake/IntakeFormIngestService.php`](src/Services/Intake/IntakeFormIngestService.php) | Pipeline orchestrator: validate → upload → classify → extract → validate-extract → store PDF → dispatch → record. | §3.4 |
| [`src/Services/Intake/IngestResult.php`](src/Services/Intake/IngestResult.php) | Immutable DTO. `insertedRowId` is the `form_upload_intake_form.id`. | §3.4 |
| [`src/Services/Intake/Classifier/IntakeFormClassifierPrompt.php`](src/Services/Intake/Classifier/IntakeFormClassifierPrompt.php) | Pure prompt-construction helper (model, messages, strict JSON schema). | §3.4 |
| [`src/Services/Intake/Schema/IntakeJsonSchemas.php`](src/Services/Intake/Schema/IntakeJsonSchemas.php) | OpenAI Structured-Outputs schemas (Demographics / MedicalHistory / Consent). | §3.4 |
| [`src/Services/Intake/Schema/IntakeFormSchemaValidator.php`](src/Services/Intake/Schema/IntakeFormSchemaValidator.php) | Hand-rolled required-field validator on the OpenAI response. | §3.4 |
| [`src/Services/Intake/Dispatcher/DemographicsDispatcher.php`](src/Services/Intake/Dispatcher/DemographicsDispatcher.php) | `patient_data` UPDATE + `insurance_data` UPSERT in fill-only-empty mode. | §3.4 |
| [`src/Services/Intake/Dispatcher/MedicalHistoryDispatcher.php`](src/Services/Intake/Dispatcher/MedicalHistoryDispatcher.php) | Builds FHIR `QuestionnaireResponse`, INSERTs `questionnaire_response` + `form_questionnaire_assessments` + `forms`. | §3.4 |
| [`src/Services/Intake/Dispatcher/ConsentDispatcher.php`](src/Services/Intake/Dispatcher/ConsentDispatcher.php) | Surfaces the diff preview for an already-stored consent PDF. | §3.4 |
| [`src/Services/Intake/Dispatcher/DiffEntry.php`](src/Services/Intake/Dispatcher/DiffEntry.php) | Per-field readonly DTO (`field`, `oldValue`, `newValue`, `applied`, `reason`). | §3.4 |
| [`src/Services/Intake/Dispatcher/DispatchOutcome.php`](src/Services/Intake/Dispatcher/DispatchOutcome.php) | Per-dispatcher return type (`insertedRowId` + diff). | §3.4 |
| [`src/Services/Intake/Fhir/QuestionnaireResponseBuilder.php`](src/Services/Intake/Fhir/QuestionnaireResponseBuilder.php) | Pure FHIR R4 `QuestionnaireResponse` builder. | §3.4 |
| [`src/Services/Intake/Exception/`](src/Services/Intake/Exception/) | `IntakeFormException` base + `AmbiguousFormException`, `IngestionFailedException`, `InvalidFormTypeException`, `InvalidUploadException`. | §3.4 |
| [`src/Services/Intake/OpenAi/OpenAIClient.php`](src/Services/Intake/OpenAi/OpenAIClient.php) | Minimal Files-API + chat-completions client with strict JSON-schema response_format. | §3.5 |
| [`src/Services/Intake/OpenAi/OpenAIStructuredRequest.php`](src/Services/Intake/OpenAi/OpenAIStructuredRequest.php) | Readonly request DTO. | §3.5 |
| [`src/Services/Intake/OpenAi/Exception/`](src/Services/Intake/OpenAi/Exception/) | `OpenAIException` base + `OpenAIMissingKeyException`, `OpenAIRateLimitException`, `OpenAIRequestFailedException`, `OpenAISchemaMismatchException`. | §3.5 |
| [`tests/Tests/Isolated/Common/Services/IntakeFormClassifierPromptTest.php`](tests/Tests/Isolated/Common/Services/IntakeFormClassifierPromptTest.php) | Unit tests for the classifier prompt construction. | §3.7 |
| [`tests/Tests/Isolated/Common/Services/IntakeFormSchemaTest.php`](tests/Tests/Isolated/Common/Services/IntakeFormSchemaTest.php) | Unit tests for the per-form-type required-fields validator. | §3.7 |
| [`tests/Tests/Isolated/Common/Services/QuestionnaireResponseBuilderTest.php`](tests/Tests/Isolated/Common/Services/QuestionnaireResponseBuilderTest.php) | Unit tests for the FHIR builder (no DB). | §3.7 |
| [`tests/Tests/E2e/UploadIntakeFormTest.php`](tests/Tests/E2e/UploadIntakeFormTest.php) | Symfony Panther E2E (login → encounter → Administrative → upload). | §3.7 |
| [`.phpstan/baseline/openemr.noGlobalNsFunctions.php`](.phpstan/baseline/openemr.noGlobalNsFunctions.php) | Hosts the lone new baseline entry near line 1548 (`upload_intake_form_report`). | §3.3 |

---

## 8. Glossary

- **`form_type` wire vocabulary** — the four exact strings that travel from the dropdown into the service: `Auto`, `Demographics`, `MedicalHistory`, `Consent`. The display labels (e.g. "Auto-detect", "Medical History") only live in [`new.php`](interface/forms/upload_intake_form/new.php). Verified by [`IntakeFormType::fromRequest()`](src/Services/Intake/IntakeFormType.php) and the `UPLOAD_INTAKE_FORM_VALID_TYPES` const in [`save.php`](interface/forms/upload_intake_form/save.php).
- **`IngestResult` DTO** — readonly DTO returned by `IntakeFormIngestService::ingest()`. Properties: `formType` (resolved enum value as string), `documentId` (FK into `documents`), `insertedRowId` (the `form_upload_intake_form.id` — what `FormService::addForm()` needs), `diffPreview` (per-field array describing what changed). See [`IngestResult.php`](src/Services/Intake/IngestResult.php).
- **`C_Document`** — legacy procedural `Document` controller class (`new \Document()` / `$document->createDocument()`) used by [`IntakeFormIngestService::storeOriginalPdf()`](src/Services/Intake/IntakeFormIngestService.php) to write the original PDF into the Documents module. The "C_" prefix is OpenEMR-folklore for legacy controller classes; the actual class name is just `Document`.
- **`QuestionnaireResponse`** — FHIR R4 resource shape used to persist the MedicalHistory answers. The `Questionnaire` definition (the empty form) is built inline in [`MedicalHistoryDispatcher::buildQuestionnaireDefinition()`](src/Services/Intake/Dispatcher/MedicalHistoryDispatcher.php); the response (filled-in answers) comes from [`QuestionnaireResponseBuilder::build()`](src/Services/Intake/Fhir/QuestionnaireResponseBuilder.php). Both are stored as JSON in the `questionnaire_response` and `form_questionnaire_assessments` tables.
- **ACL `admin|super`** — OpenEMR access-control pipe-separated `section|aco_spec` string (`admin` section, `super` aco). Enforced by `AclMain::aclCheckCore('admin', 'super')` in [`new.php`](interface/forms/upload_intake_form/new.php), [`save.php`](interface/forms/upload_intake_form/save.php), and [`view.php`](interface/forms/upload_intake_form/view.php), and stored on the registry row's `aco_spec` column.
