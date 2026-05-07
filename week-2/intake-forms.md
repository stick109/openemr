# Intake Forms — Usage Guide

How to **generate** synthetic intake forms for demos and how to **ingest** real
intake-form PDFs into OpenEMR.

For the implementation plan and status of each work item, see
[intake-forms-plan.md](intake-forms-plan.md).

---

## What's an intake form?

A document a patient fills out at registration or before a visit. In real
clinics, intake forms come in by fax, scan, portal upload, or hand-fill on
paper that the front desk later scans. This project supports the three most
common types:

| Type                | What's on it                                                               | Where it lands in OpenEMR                                                                          |
|---------------------|----------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| **Demographics**    | Name, DOB, sex, address, phone, email, emergency contact, insurance.       | `patient_data` table (and `insurance_data`).                                                       |
| **Medical History** | Past conditions, surgeries, medications, allergies, family history, social.| `questionnaire_response` + `form_questionnaire_assessments`, attached to the current encounter.    |
| **Consent**         | HIPAA acknowledgment + consent for treatment, signed.                      | OpenEMR Documents module, indexed under the patient.                                               |

---

## Part 1 — Generating a synthetic intake form

`generate-intake-form.ps1` produces a realistic-looking PDF. PII contract:
only the patient's **initials, age, and sex** leave your machine — the OpenAI
call never sees full name, DOB, or address.

### Prerequisites

- Docker Desktop running.
- The `openemr` Docker stack started:
  ```powershell
  docker compose --project-name openemr up --detach --wait
  ```
- An `OPENAI_API_KEY` available via either:
  - Environment variable: `$env:OPENAI_API_KEY = "sk-..."`
  - `.env` file at the repo root: `OPENAI_API_KEY=sk-...`

### Usage

```powershell
.\generate-intake-form.ps1 -PatientId <pid> -FormType <type> [options]
```

| Parameter      | Required | Description                                                              |
|----------------|----------|--------------------------------------------------------------------------|
| `-PatientId`   | yes      | OpenEMR patient PID. Run `.\list-patients.ps1` to see the available IDs. |
| `-FormType`    | yes      | One of `Demographics`, `MedicalHistory`, `Consent`.                      |
| `-ProjectName` | no       | Docker Compose project name. Default: `openemr`.                         |
| `-Model`       | no       | OpenAI model. Default: `gpt-4o-mini`.                                    |
| `-OutFile`     | no       | Destination path. Default: `intake-forms/intake-<type>-<pid>-<stamp>.pdf`. |
| `-Seed`        | no       | Hint for value variation. Useful for reproducible-ish output.            |

### Examples

```powershell
# Demographics for patient 5 → intake-forms/intake-Demographics-5-<stamp>.pdf
.\generate-intake-form.ps1 -PatientId 5 -FormType Demographics

# Medical history with a specific output path
.\generate-intake-form.ps1 -PatientId 5 -FormType MedicalHistory `
    -OutFile C:\demo\wanda-history.pdf

# HIPAA consent
.\generate-intake-form.ps1 -PatientId 5 -FormType Consent
```

### Output

A single PDF in `intake-forms/`. The file is **not** automatically attached
to a patient or encounter — you upload it via the UI (Part 2).

---

## Part 2 — Ingesting an intake form (UI)

OpenEMR has a new menu item — **Administrative → Upload Intake Form** —
inside the encounter view. It accepts a PDF, extracts the data with OpenAI,
and writes it to the right place.

### Step-by-step

1. **Find the patient.** Top-left search bar → pick the patient. The patient
   summary tab opens.
2. **Open or create an encounter.**
   - Existing: click **Encounters** in the patient sidebar → click an
     encounter row.
   - New: click the **+** button next to "Select Encounter" in the patient
     header → fill the New Encounter form → **Save**.
3. **Open the Administrative menu** in the encounter navbar (the same row
   that contains *Clinical*, *Orders*, *Questionnaires*).
4. Click **Upload Intake Form**.

   ![Administrative dropdown showing Upload Intake Form]
   (the menu item appears alongside *Fee Sheet*, *Misc Billing Options HCFA*,
   and *New Encounter Form*.)

5. **Pick the PDF file** in the file picker.
6. **Pick the form type** in the dropdown:
   - `Auto-detect` — let the LLM classify the document.
   - `Demographics`, `Medical History`, or `Consent` — force a specific type.
7. Click **Upload**.

### What happens behind the scenes

| If you upload…   | OpenEMR will…                                                                                                                                                       |
|------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Demographics     | Show a preview of the extracted fields → ask you to confirm → update `patient_data` and `insurance_data` for **the current patient**. Confirmation diff is shown.   |
| Medical History  | Build a FHIR `QuestionnaireResponse` from the extracted data → save it as an encounter form attached to **the current encounter** (visible in the encounter timeline). |
| Consent          | Save the PDF into the **Documents** module under a "Consents" category for **the current patient** (no extraction needed beyond the patient name + signature date). |

In every case, a row is added to the encounter's form list saying "Intake
form uploaded: <type>" so you can audit what came in when.

### Errors and edge cases

| Symptom                                          | Likely cause / fix                                                                                                  |
|--------------------------------------------------|---------------------------------------------------------------------------------------------------------------------|
| "OPENAI_API_KEY is not configured"               | Set `OPENAI_API_KEY` in the openemr container's environment, or add it to the project `.env`.                       |
| "Could not auto-detect form type" (low confidence)| Re-upload and choose the type explicitly from the dropdown.                                                          |
| "Insurance carrier not recognized"               | The PDF used a carrier name that doesn't match any row in `insurance_companies`. The carrier text is saved as-is and you can fix it under *Patient → Demographics → Insurance*. |
| "PDF too large"                                  | 10 MB cap. Re-scan at lower DPI or split.                                                                            |
| "Encounter is locked"                            | E-signed encounters cannot accept new forms. Unlock the encounter first or upload to a new encounter.               |
| "ACL denied"                                     | The Upload Intake Form menu item requires the `admin|super` ACL by default. Adjust in *Administration → ACL*.        |

---

## Quick reference — full demo flow

```powershell
# 1. Make sure the stack is up
docker compose --project-name openemr up --detach --wait

# 2. Pick a patient
.\list-patients.ps1

# 3. Generate one of each form type for that patient
.\generate-intake-form.ps1 -PatientId 5 -FormType Demographics
.\generate-intake-form.ps1 -PatientId 5 -FormType MedicalHistory
.\generate-intake-form.ps1 -PatientId 5 -FormType Consent

# 4. In OpenEMR (http://localhost:8300/), upload each PDF via:
#    Patient → Encounter → Administrative → Upload Intake Form
```

---

## Why a UI instead of `ingest-intake-form.ps1`?

An earlier draft of this work proposed a PowerShell ingestion script. We
chose a UI menu item instead because:

- **Discoverability.** Clinic staff already work in the encounter view; they
  shouldn't have to drop to PowerShell to upload a fax.
- **Audit trail.** UI uploads can be logged in the encounter timeline with
  the user who performed them. A script run on the host can't be attributed.
- **Confirmation.** A UI can show the extracted fields and ask the user to
  confirm before overwriting `patient_data`. A script either has to ask via
  console (clumsy) or commit blindly.
- **ACL.** UI uploads respect the OpenEMR permission system. A script
  bypasses it.

The **generator** stays as a script because it produces *demo* data on a
developer's host — not something a clinic does.
