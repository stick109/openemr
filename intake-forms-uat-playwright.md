# Intake Forms — Playwright UAT runbook

End-to-end UI verification for the `interface/forms/upload_intake_form/`
feature, driven through Claude's Playwright MCP browser tools. Repeat this
sequence to confirm the upload flow works on a target environment after a
deploy or code change.

Goal: log in → open a patient with an encounter → click
`Administrative → Upload Intake Form` → upload a fixture PDF → confirm a
"Redirecting…" page (success) or capture the failure reason from logs.

---

## Targets

| Target | URL | Notes |
|---|---|---|
| Local dev | `http://localhost:8300/` | Project name `openemr`, mariadb at `openemr-mysql-1`. Compose at `docker/development-easy/`. |
| Prod | `https://openemr-web-production.up.railway.app/` | Railway project `openemr`, env `production`, service `openemr-web`. |

Login on both: `admin` / `pass`.

OpenAI key:
- **Local:** the running container picks `OPENAI_API_KEY` from its env. If
  the override file is missing, recreate
  `docker/development-easy/docker-compose.override.yml` (gitignored) with
  the key from `.env`, then `docker compose -p openemr up -d openemr`.
- **Prod:** Railway variable `OPENAI_API_KEY` on `openemr-web`. **Beware
  pasting** — a leading BOM (`﻿`) in the variable will fail with
  `Invalid character found in option "auth_bearer"`. Retype rather than
  paste, or use `railway variables --service openemr-web --set …` from a
  non-BOM shell.

Fixture PDFs in `intake-forms/` (gitignored). If absent, regenerate with
`./generate-intake-form.ps1 -PatientId <pid> -FormType <Demographics|MedicalHistory|Consent>`.

---

## Sequence

### 1. Login

```text
browser_navigate <target_url>
browser_fill_form admin/pass
browser_click "Login"
```

Successful redirect: `/interface/main/tabs/main.php?token_main=…`.

### 2. Open a patient

The standalone URL `/.../demographics.php?set_pid=N` triggers a `beforeunload`
dialog and times out — **don't navigate directly**. Use the Finder:

```text
browser_click "Finder" in nav
browser_click <patient row link>   # e.g. "Moore, Wanda"
```

The patient header appears with a `Select Encounter (N)` button.

### 3. Open a real encounter (NOT a new one)

> Do NOT navigate to `/interface/forms/newpatient/new.php` — that opens the
> "create new encounter" form, which is a different thing.

```text
browser_click "Select Encounter (N)" dropdown
# The menu lives at the page level (outside any iframe). Use:
#   document.querySelectorAll('a.dropdown-item')
# and click the most recent date.
browser_evaluate -> click the latest a.dropdown-item
```

The patient header now shows `Open Encounter: 2026-04-30 (900000000003)`.

### 4. Reach the upload form (3-level iframe nesting)

The encounter forms list lives **two iframes deep**. The form itself opens
in a **third** iframe (`enctabs-1001`).

```text
# 1) Click Administrative in the encounter forms navbar
#    Path: top page → iframe[name="enc"] → inner iframe → button "Administrative"
browser_click button "Administrative"  # ref under iframe[name="enc"]

# 2) Click the Upload Intake Form anchor (still inside the inner iframe)
#    The anchor's onclick fires window.openNewForm() which opens enctabs-1001.
#    A direct browser_click can fail because the anchor is a JS-wrapped link
#    in a multiply-nested iframe; if so, do this:
browser_evaluate -> {
  const enc = document.querySelector('iframe[name="enc"]').contentDocument;
  const inner = enc.querySelector('iframe').contentDocument;
  inner.querySelector('a[onclick*="upload_intake_form"]').click();
}
```

After ~1–2s the form is rendered at:

```text
iframe[name="enc"] → iframe[name="enctabs-1001"]
  → /interface/patient_file/encounter/load_form.php?formname=upload_intake_form&pid=N&encounter=M
```

### 5. Submit the upload

```text
browser_select_option ref=<form_type select> ["Consent"]   # or Demographics, MedicalHistory, Auto
browser_click ref=<PDF file button>                        # opens native file chooser
browser_file_upload ["C:\\…\\intake-forms\\intake-Consent-3-…pdf"]
browser_click ref=<Upload submit button>
browser_wait_for time=20    # OpenAI roundtrip (Files API + chat completion)
```

### 6. Read the result (look in the right iframe!)

The save.php response renders **inside the `enctabs-1001` iframe**, not at
the top level. Reading `document.body.innerText` returns the encounter
list, which is misleading.

```text
browser_evaluate -> {
  const enc = document.querySelector('iframe[name="enc"]').contentDocument;
  const form = enc.querySelector('iframe[name="enctabs-1001"]').contentDocument;
  return form.body.innerText.slice(0, 600);
}
```

Successful upload: page title `Redirecting…` and body text near
`Redirecting…`. Failure: body contains `The intake form could not be
processed. Please retry or contact support.`

---

## Verifying the DB after a successful upload

### Local

```bash
docker exec openemr-mysql-1 mariadb -uroot -proot openemr -e "
SELECT id, pid, encounter, form_type, document_id, inserted_row_id,
       SUBSTRING(diff_preview,1,80) AS diff_head
FROM form_upload_intake_form WHERE pid=<pid> ORDER BY id DESC LIMIT 5;

SELECT d.id, d.name, d.mimetype, c.name AS category
FROM documents d
JOIN categories_to_documents ctd ON ctd.document_id=d.id
JOIN categories c ON c.id=ctd.category_id
WHERE d.id IN (SELECT document_id FROM form_upload_intake_form WHERE pid=<pid>);
"
```

For `Demographics`: also check `patient_data` and `insurance_data` (primary).
For `MedicalHistory`: also check `questionnaire_response`,
`form_questionnaire_assessments`, and `forms` (`formdir='upload_intake_form'`).

### Prod

`railway run --service openemr-web` only injects env, it does **not** exec
into the container. Connect to the prod MariaDB via the Railway public
proxy with PHP PDO from the host:

```bash
railway variables --service <db-service> | grep -E "MYSQL_(HOST|PORT|USER|PASSWORD|DATABASE|PUBLIC_URL)"
# or pull MYSQL_PUBLIC_URL and parse with PHP's parse_url()
```

Then:

```php
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->query("…")->fetchAll();
```

---

## Reading logs when the upload fails

The friendly error page is what every typed-exception path renders. The
real cause is in the apache log.

**Local:**

```bash
MSYS_NO_PATHCONV=1 docker exec openemr-openemr-1 \
  sh -c 'tail -300 /var/log/apache2/error.log | grep -E "intake|IntakeForm|OpenAI|CRITICAL"'
```

**Prod:**

```bash
railway logs --service openemr-web 2>&1 | tail -200 \
  | grep -E "intake|IntakeForm|OpenAI|CRITICAL"
```

---

## Known failure modes

| Error fragment | Root cause | Fix |
|---|---|---|
| `LogicException … Mime component is not installed` | `symfony/mime` not in production vendor; `UploadedFile::getMimeType()` blew up. | Use `mime_content_type()` (already done in `save.php`). |
| `OpenAIRequestFailedException … Missing scopes: api.files.write` | Restricted OpenAI key lacks Files write. | OpenAI dashboard → key permissions → enable Files: Write. |
| `OpenAIRequestFailedException … Missing scopes: model.request` | Restricted key lacks model invocation. | OpenAI dashboard → Model capabilities (parent dropdown) → Request. |
| `Invalid file data: 'file_id' … unsupported MIME type 'None'` | Upload's multipart filename did not end with `.pdf` (PHP temp upload paths have no extension). | `IntakeFormIngestService::displayFilename()` always emits `.pdf` (commit `8120746ee`). |
| `Invalid character found in option "auth_bearer": "﻿…"` | UTF-8 BOM prefix on the env var (Railway / pasted in Notepad-likes). | Retype the env var or set via CLI from a clean shell. |
| `IngestionFailedException: Patient id must be positive` | `$pid` clobbered by `interface/globals.php`. | Use locally-prefixed variables in any smoke script. |
| Direct nav to demographics.php with `set_pid=N` times out | Top tabs frame guards against navigating away with `beforeunload`. | Use the Finder in the UI, never bare URLs. |

---

## Smoke harness (CLI alternative)

For headless / programmatic testing without a browser, use the smoke harness
pattern (recreate as `intake-forms/smoke-ingest.php`, gitignored — body is
documented in commit `8120746ee` and the in-conversation history). Bootstrap
`/var/www/localhost/htdocs/openemr/interface/globals.php` with `$ignoreAuth = true`,
inject a `MockArraySessionStorage`, and call `IntakeFormIngestService::ingest()`.

This bypasses the UI and is useful for diagnosing whether a failure is in
the code path vs the iframe / form-submit plumbing. **Caveat:** smoke uses
`/tmp/foo.pdf` paths (with `.pdf`); web UI uses Symfony's extensionless
`/tmp/phpXXXXXX` paths. If you find a bug only with the web UI, replicate
the upload mechanism rather than relying on the smoke harness.
