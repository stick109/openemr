# Intake Forms — Implementation Plan

Plan for adding intake-form generation and ingestion to the OpenEMR demo.
Status of each work item is tracked inline; flip values as work progresses.

**Status legend:**
`Not started` · `In progress` · `Done` · `Blocked` · `Won't do`

---

## 1. Scope

Three intake-form types — chosen because they appear at virtually every
primary-care visit and exercise three distinct OpenEMR write paths.

| # | Form type                                               | Generator output | Ingestion target in OpenEMR                                                          |
|---|---------------------------------------------------------|------------------|--------------------------------------------------------------------------------------|
| 1 | **Demographics + Insurance** (new patient registration) | PDF              | `patient_data` (and `insurance_data`)                                                |
| 2 | **Medical History Questionnaire**                       | PDF              | `questionnaire_response` + `form_questionnaire_assessments` (linked to an encounter) |
| 3 | **HIPAA Privacy Acknowledgment + Consent for Treatment** | PDF              | `documents` module (binary stored, indexed under patient)                            |

PII contract for the generator: only patient INITIALS, AGE, and SEX leave the
host (same contract as `generate-lab-pdf.ps1`).

---

## 2. Decisions made

| Decision                                                       | Resolution                                                                                                 |
|----------------------------------------------------------------|------------------------------------------------------------------------------------------------------------|
| Offline mode for generator?                                    | No. OpenAI API assumed always available.                                                                   |
| `ingest-intake-form.ps1` script?                               | No — replaced by an in-app UI under the encounter's **Administrative** menu.                               |
| Output format per form type                                    | All three are PDF. PDF is the realistic case for ingestion (faxed/scanned/uploaded by front-desk staff).   |
| Where ingestion lives                                          | New encounter form `upload_intake_form` registered in the **Administrative** category of the encounter navbar. |
| Encounter linkage for MedicalHistory                           | Uses the current open encounter (the one the user is viewing when they click the menu). No auto-creation. |
| Patient-level vs encounter-level upload                        | Entry point is per-encounter (the Administrative menu lives in encounter view), but Demographics/Consent writes are patient-scoped. |
| Refactor `generate-lab-pdf.ps1` to share helpers?              | Out of scope for this work — separate cleanup PR later.                                                    |

---

## 3. Components and Status

### 3.1  `generate-intake-form.ps1` — generator script

**Status:** `Not started`

Mirrors the pattern of [generate-lab-pdf.ps1](generate-lab-pdf.ps1):
Docker preflight → `.env`/env-var OpenAI key → pull patient initials/age/sex
from MySQL → call OpenAI Structured Outputs → render HTML → render PDF via
the openemr container's bundled mPDF.

**Parameters:**

```powershell
param(
    [Parameter(Mandatory)][int]$PatientId,
    [Parameter(Mandatory)]
    [ValidateSet('Demographics','MedicalHistory','Consent')]
    [string]$FormType,
    [string]$ProjectName = "openemr",
    [string]$Model = "gpt-4o-mini",
    [string]$OutFile,
    [int]$Seed
)
```

**Default output:** `intake-forms/intake-<type>-<pid>-<timestamp>.pdf`

**Per-type generation:**

| FormType         | OpenAI schema fields                                                                                                            | HTML template                                         |
|------------------|----------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------|
| `Demographics`    | `firstName, lastName, dob, sex, address{street,city,state,zip}, phone, email, emergencyContact{name,relationship,phone}, insurance{carrier, memberId, group, planType}` | Two-column registration form, signature line, date.   |
| `MedicalHistory`  | `conditions[], surgeries[], medications[], allergies[], familyHistory[], social{smoking, alcohol, drugs}`                        | Long printed checklist with sections.                 |
| `Consent`         | Mostly fixed boilerplate text + `patientName, signatureDate`. A "signature" is a typed-in cursive font (no real handwriting).    | Single-page legal letterhead with signature block.    |

### 3.2  Encounter menu item — "Upload Intake Form"

**Status:** `Not started`

Add a new entry to the **Administrative** dropdown of the encounter navbar
(the navbar rendered by [templates/encounter/forms/navbar.html.twig](templates/encounter/forms/navbar.html.twig)).

**Mechanism:** register a standard encounter form. The encounter navbar reads
`registry` rows via `getFormsByCategory()` in
[interface/patient_file/encounter/forms.php:556](interface/patient_file/encounter/forms.php) and
groups them by the `category` column.

**Registry row to insert:**

```sql
INSERT INTO `registry`
  (`name`, `state`, `directory`, `sql_run`, `unpackaged`, `date`, `priority`,
   `category`, `nickname`, `patient_encounter`, `therapy_group_encounter`,
   `aco_spec`, `form_foreign_id`)
VALUES
  ('Upload Intake Form', 1, 'upload_intake_form', 1, 1, NOW(), 0,
   'Administrative', '', 1, 0, 'admin|super', NULL);
```

`aco_spec='admin|super'` for now — refine later if non-admin staff need it.

### 3.3  `interface/forms/upload_intake_form/` — the upload form

**Status:** `Not started`

Standard encounter-form layout:

| File           | Purpose                                                                                       |
|----------------|-----------------------------------------------------------------------------------------------|
| `info.txt`     | One-line description (OpenEMR's form-registry standard).                                      |
| `new.php`      | Upload UI: file picker (PDF), form-type dropdown, submit button. CSRF-protected.              |
| `save.php`     | Receives the upload, calls OpenAI, dispatches by type, writes to OpenEMR.                     |
| `report.php`   | Renders an entry in the encounter timeline showing what was uploaded.                         |
| `view.php`     | View-only mode after save.                                                                    |
| `table.sql`    | Schema for a small `form_upload_intake_form` row that logs each upload (encounter, type, document_id). |

**Type dropdown options:** `Auto-detect`, `Demographics`, `Medical History`,
`Consent`.

### 3.4  Server-side ingestion logic (PHP)

**Status:** `Not started`

Lives inside `save.php` (or a new `OpenEMR\Services\IntakeFormIngestService`
to keep `save.php` thin).

**Pipeline:**

1. Validate CSRF, ACL (`admin|super`), uploaded file (PDF, ≤ 10 MB).
2. Auto-classify (when `FormType=Auto`): one OpenAI call that returns the
   form type plus a confidence score. Reject if confidence < 0.6.
3. Type-specific extraction: second OpenAI call with a strict JSON schema
   matching the generator's schema for that type.
4. Dispatch:

   | FormType       | Action                                                                                                                                                                            |
   |----------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
   | Demographics   | `UPDATE patient_data` for the current patient. `INSERT/UPDATE insurance_data` for primary insurance. Confirmation dialog showing the diff before commit.                          |
   | MedicalHistory | Build FHIR `QuestionnaireResponse` JSON; INSERT into `questionnaire_response`; INSERT into `form_questionnaire_assessments`; INSERT into `forms` so it shows in the encounter.     |
   | Consent        | Save the original PDF into the `documents` module under a "Consents" category (using `C_Document` so categorization/event hooks fire correctly).                                   |

5. Insert a row into `form_upload_intake_form` so the upload appears in the
   encounter timeline with a link to what was created.

### 3.5  OpenAI client — PHP side

**Status:** `Not started`

PHP doesn't currently have an OpenAI helper class in this repo. Add a
minimal `OpenEMR\Services\OpenAIClient` that:
- Reads the API key from `OPENAI_API_KEY` env var (already used by the agent).
- Uploads the PDF via the Files API (`purpose=user_data`).
- Calls `chat.completions` with a `response_format` of `json_schema` (strict).
- Surfaces useful errors (no key, rate-limited, schema mismatch).

Reuse: the existing agent code in this repo may already have an OpenAI wrapper
worth extending. **Verify before writing a new one.**

### 3.6  Documentation

**Status:** `Done` for plan + user docs (this file and `intake-forms.md`).
Implementation docs (XML/PHP docblocks, inline comments) tracked in 3.3 / 3.4.

### 3.7  Tests

**Status:** `Not started`

Minimum:
- Isolated unit test for the form-type classifier prompt construction.
- Isolated unit test for each schema's required fields.
- Isolated test for the FHIR `QuestionnaireResponse` builder (no DB).
- One end-to-end Cypress test: open an encounter → click Administrative →
  Upload Intake Form → upload a fixture PDF → assert success message and
  encounter-timeline row.

Live OpenAI calls are mocked in tests via a fake client.

### 3.8  Migrations / installation

**Status:** `Not started`

- New Doctrine migration that runs the registry INSERT (see 3.2) and creates
  `form_upload_intake_form` (see 3.3).
- For dev environments: same SQL also added to the upgrade file
  (`sql/x_y_z-to-x_y_w_upgrade.sql`) following project convention.

---

## 4. Out of scope (explicitly)

- Offline / no-API mode for the generator.
- Form types other than the three in §1 (no PHQ-9, no ROS, no pain scale).
- Local OCR fallback — relying on `gpt-4o-mini`'s native PDF understanding.
- Patient-portal self-upload.
- Round-tripping guarantees: the generator's output and the ingester's input
  are not required to be byte-identical. The ingester must handle real-world
  PDFs (scans, faxes), not just generator output.
- Refactoring `generate-lab-pdf.ps1` to share helpers with the new generator.

---

## 5. Open questions

| #  | Question                                                                                          | Owner   | Resolved? |
|----|---------------------------------------------------------------------------------------------------|---------|-----------|
| Q1 | Does the OpenEMR clinical-copilot agent already have a PHP OpenAI client we can reuse?            | Claude  | No        |
| Q2 | Should Demographics extraction *replace* existing patient data or only fill empty fields?         | User    | No        |
| Q3 | Confidence threshold for auto-classification (currently proposed: 0.6) — is this right?           | User    | No        |
| Q4 | Document category name — `Consents`? Existing? Create on first use?                               | User    | No        |
| Q5 | Should the upload UI offer a preview of the extracted JSON for review/edit before commit?         | User    | No        |

---

## 6. Work-item dashboard

| ID  | Item                                              | Status      | Blocked by |
|-----|---------------------------------------------------|-------------|------------|
| 3.1 | `generate-intake-form.ps1`                         | Not started |            |
| 3.2 | Encounter menu item (registry INSERT)              | Not started |            |
| 3.3 | `interface/forms/upload_intake_form/` form         | Not started | 3.2        |
| 3.4 | Server-side ingestion logic                        | Not started | 3.3, 3.5   |
| 3.5 | OpenAI PHP client                                  | Not started | Q1         |
| 3.6 | Documentation                                      | Done        |            |
| 3.7 | Tests                                              | Not started | 3.1, 3.4   |
| 3.8 | Migrations / installation                          | Not started | 3.3        |

Update the **Status** cell as work progresses.
