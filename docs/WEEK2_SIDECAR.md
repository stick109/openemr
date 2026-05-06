# Week 2 — Python Agent Sidecar Deployment Guide

> Operator-facing reference for the Week 2 multimodal evidence agent. Covers
> service topology, every required environment variable, local run commands,
> and how a grader / reviewer drives the sidecar-backed flow against a deployed
> instance.

This document is scoped to the Week 2 Python sidecar (`agent-service`). For the
Week 1 PHP-only Clinical Co-Pilot, see [`ARCHITECTURE.md`](../ARCHITECTURE.md)
and [`AUDIT.md`](../AUDIT.md). For the architecture defense behind the design,
see [`W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md).

---

## 1. Services at a glance

| Service         | Image / source                   | Purpose                                                     |
|-----------------|----------------------------------|-------------------------------------------------------------|
| `openemr`       | `Dockerfile.railway` (this repo) | OpenEMR PHP application; CSRF/ACL gate, FHIR, encounter UI  |
| `agent-service` | `agent-service/Dockerfile`       | Python FastAPI sidecar; LangGraph supervisor + RAG pipeline |
| `mysql`         | `mariadb:11.8.6`                 | OpenEMR database                                            |
| `phpmyadmin`    | `phpmyadmin:latest`              | DB admin UI for local development                           |
| (other)         | `couchdb`, `openldap`, `mailpit`, `selenium` | Existing OpenEMR development support services |

Only `openemr` and `agent-service` are required for the Week 2 deliverable; the
others are inherited from the standard OpenEMR development Compose stack.

---

## 2. Local topology

```
+--------------------------+         HTTP (X-Agent-Secret)        +--------------------------+
|         openemr          |  --------------------------------->  |      agent-service       |
|  PHP / Apache, port 80   |     POST /api/agent/run              |  Python FastAPI, 8010    |
|  Host: http://localhost: |                                      |  Host: http://127.0.0.1: |
|  8300 (HTTP) / 9300 (TLS)|                                      |  8010                    |
|                          |  <----  GET /healthz (no auth)  --   |                          |
+-----------+--------------+                                      +-----------+--------------+
            |                                                                 |
            | rw                                                              | ro
            v                                                                 v
            +-----------------------------------------------------------------+
            |       Docker named volume:  agent-uploads                       |
            |       Mount path inside both containers: /var/uploads/agent     |
            +-----------------------------------------------------------------+

            All services share the default Compose network of project "openemr",
            so the PHP container resolves the sidecar by service name:
                http://agent-service:8010
```

Key facts:

- **OpenEMR on the host:** `http://localhost:8300` (HTTP) and
  `https://localhost:9300` (TLS). Default credentials `admin` / `pass`.
- **Sidecar on the host:** `http://127.0.0.1:8010`. Health probe:
  `GET /healthz` (no shared secret required).
- **Sidecar inside the Compose network:** `http://agent-service:8010`. PHP uses
  this URL; it is set in
  [`docker/development-easy/docker-compose.yml`](../docker/development-easy/docker-compose.yml)
  as `OPENEMR_AGENT_SIDECAR_URL`.
- **Shared file volume:** `agent-uploads`. PHP writes uploaded PDFs to
  `/var/uploads/agent` (read-write). The sidecar mounts the same volume at
  `/var/uploads/agent` **read-only** — it must never mutate files OpenEMR
  uploads.
- **Network boundary:** the sidecar is published on `127.0.0.1:8010` for local
  inspection only. In production it must not be reachable from the public
  internet.

---

## 3. Deployed topology

Target platforms (matching [`week-2-plan.md` §4.14](../week-2-plan.md) and the
existing Railway tooling in this repo):

| Component       | Platform | Notes                                                                    |
|-----------------|----------|--------------------------------------------------------------------------|
| `openemr`       | Railway  | Existing deployment via [`Dockerfile.railway`](../Dockerfile.railway) and [`build-docker.ps1`](../build-docker.ps1). Public URL is the grader entry point. |
| `agent-service` | Render   | Single Python web service built from `agent-service/Dockerfile`.         |

```
                               public internet
                                      |
                                      v
                  +---------------------------------------+
                  |   Railway: openemr (public, TLS)      |
                  |   https://<openemr-host>              |
                  +-------------------+-------------------+
                                      |
                          HTTPS, X-Agent-Secret header
                                      |
                                      v
                  +---------------------------------------+
                  |   Render: agent-service (private)     |
                  |   https://<agent-service-host>        |
                  |   Reachable ONLY from OpenEMR         |
                  +---------------------------------------+
```

**Hardening rules:**

- The Render `agent-service` is configured with no public ingress beyond what
  OpenEMR needs. If Render exposes a public URL by default, restrict access
  with the shared secret (`AGENT_SHARED_SECRET`) and ideally an allow-list;
  every non-`/healthz` route already rejects requests without a valid
  `X-Agent-Secret` header (see [`agent-service/CONTRACT.md`](../agent-service/CONTRACT.md)).
- The shared secret flows from the host environment (Railway / Render
  dashboard) into both containers as `OPENEMR_AGENT_SIDECAR_SECRET` (PHP side)
  and `AGENT_SHARED_SECRET` (Python side). The two values must match
  byte-for-byte.
- Long-lived secrets (`OPENAI_API_KEY`, `COHERE_API_KEY`, `HONEYCOMB_API_KEY`)
  live only on the sidecar. The PHP host never sees them.

---

## 4. Environment variables

### 4.1 Sidecar (`agent-service`)

Read by [`agent-service/agent_service/config.py`](../agent-service/agent_service/config.py).

| Variable               | Required | Default | Description                                                                                       |
|------------------------|----------|---------|---------------------------------------------------------------------------------------------------|
| `AGENT_SHARED_SECRET`  | Yes      | (none)  | Shared secret checked on every non-health request. Must match the OpenEMR side bit-for-bit.       |
| `OPENAI_API_KEY`       | Yes (effective) | (empty) | OpenAI key used for vision extraction (Files API + Structured Outputs) and `text-embedding-3-small` embeddings. The container starts without it, but extraction calls fail until it is set. |
| `COHERE_API_KEY`       | Optional | (empty) | Cohere key used for `rerank-english-v3.0`. When unset, the sidecar transparently falls back to the local cross-encoder reranker (slower, slightly lower quality, but functional). |
| `HONEYCOMB_API_KEY`    | Optional | (empty) | Honeycomb ingest key for OpenTelemetry export. When unset, traces stay local.                     |
| `AGENT_LOG_LEVEL`      | Optional | `INFO`  | Python `logging` level. Common values: `DEBUG`, `INFO`, `WARNING`.                                |
| `AGENT_DEBUG`          | Optional | `false` | When truthy (`1` / `true` / `yes`), enables verbose stack traces and FastAPI debug mode.          |

### 4.2 OpenEMR (PHP host)

Read by
[`src/Services/Agent/Sidecar/AgentSidecarConfig.php`](../src/Services/Agent/Sidecar/AgentSidecarConfig.php)
and surfaced in [`.env.example`](../.env.example).

| Variable                                | Required | Default                       | Description                                                                                  |
|-----------------------------------------|----------|-------------------------------|----------------------------------------------------------------------------------------------|
| `OPENEMR_AGENT_SIDECAR_URL`             | Yes      | `http://agent-service:8010`   | Base URL OpenEMR uses to reach the sidecar. Inside Compose this is the service name; outside Compose use `http://127.0.0.1:8010` or the Render hostname. |
| `OPENEMR_AGENT_SIDECAR_SECRET`          | Yes      | (empty)                       | The same value as `AGENT_SHARED_SECRET` on the sidecar. Sent in the `X-Agent-Secret` header. |
| `OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS` | Optional | `60`                          | HTTP timeout for sidecar calls. Extraction + RAG can be slow; 60 s is the recommended floor. |

> **Naming note.** The implementation tracker in
> [`sidecar-detailed-steps.md`](../sidecar-detailed-steps.md) historically
> referred to `AGENT_SERVICE_URL`. The canonical name in this codebase is
> `OPENEMR_AGENT_SIDECAR_URL` — they refer to the same value.

### 4.3 Where to set them

| Environment        | Sidecar vars                                                                       | OpenEMR vars                                            |
|--------------------|------------------------------------------------------------------------------------|---------------------------------------------------------|
| Local Compose      | `docker/development-easy/docker-compose.yml` reads them from the host env or `.env` | Same Compose file; `.env.example` documents every var  |
| Render (sidecar)   | Service "Environment" tab                                                           | n/a                                                     |
| Railway (OpenEMR)  | n/a                                                                                 | Service "Variables" tab                                 |

For local development, copy `.env.example` to `.env` at the repo root, fill in
the secrets, and Compose will pick them up automatically.

---

## 5. Local run commands (PowerShell)

All commands assume the repo root as the current directory unless noted.

### 5.1 Start the full stack

```powershell
cd docker\development-easy
docker compose --project-name openemr up -d
```

The `--project-name openemr` flag pins the Compose project name so subsequent
commands target the same container set regardless of the working directory.
Wait roughly 3–6 minutes the first time; the easy-dev image rsyncs the
checkout before Apache comes up.

### 5.2 Start just the sidecar

```powershell
docker compose --project-name openemr up -d agent-service
```

Useful when the rest of OpenEMR is already running and you only need to rebuild
the Python service after a sidecar change.

### 5.3 Verify sidecar health from the host

```powershell
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Expected response:

```json
{ "status": "ok" }
```

### 5.4 Tail sidecar logs

```powershell
docker compose --project-name openemr logs -f agent-service
```

### 5.5 Quick auth check (shared secret round-trip)

```powershell
$secret = "dev-shared-secret"   # match OPENEMR_AGENT_SIDECAR_SECRET
Invoke-RestMethod `
  -Uri http://127.0.0.1:8010/api/agent/run `
  -Method Post `
  -Headers @{ 'X-Agent-Secret' = $secret; 'Content-Type' = 'application/json' } `
  -Body '{"patient_id":1,"file_path":"/var/uploads/agent/missing.pdf","doc_type":"lab_pdf","encounter_id":1,"trace_id":"00000000-0000-4000-8000-000000000000"}'
```

A missing file should return HTTP 404 with `{"error": "file_not_found", ...}`.
A wrong secret returns 403; a missing header returns 401. Either is enough to
prove the auth path is wired.

### 5.6 Stop the stack

```powershell
docker compose --project-name openemr down
```

Add `--volumes` to wipe the database and the `agent-uploads` shared volume.

---

## 6. Reaching the deployed app (graders / reviewers)

### 6.1 Public URL

| Surface                | URL                                              |
|------------------------|--------------------------------------------------|
| OpenEMR (Railway)      | _<insert deployed URL here when published>_      |
| Sidecar health (Render) | _Internal — verified from OpenEMR side only_     |

Update this section when the deployment is published. The sidecar's URL is
**not** intended for grader traffic; graders interact with the deployed
OpenEMR instance, which calls the sidecar internally.

### 6.2 Logging in

Use the standard OpenEMR development credentials:

| Field    | Value  |
|----------|--------|
| Username | `admin` |
| Password | `pass`  |

These mirror the local Compose defaults documented in
[`CONTRIBUTING.md`](../CONTRIBUTING.md). If a different demo account has been
provisioned for the deployment, it will be noted in the submission summary.

### 6.3 Exercising the sidecar end-to-end

1. Log in to OpenEMR.
2. Open or create a patient and start a new encounter.
3. Inside the encounter, add the **Upload Intake Form** form
   (`interface/forms/upload_intake_form/new.php`).
4. Pick a document type (`Intake Form` or `Lab Report`) and choose a PDF —
   sample fixtures live under `agent-service/eval/fixtures/`, or use any
   realistic intake PDF / lab PDF.
5. Submit the form. PHP validates CSRF + ACL + magic bytes, copies the file to
   `/var/uploads/agent/<encounter>/<file>.pdf`, and POSTs to the sidecar at
   `POST /api/agent/run`.
6. After the run completes, the encounter timeline shows the parsed result
   with per-field citations. PDF-derived fields support click-to-source
   bounding-box overlays in `view.php`.

### 6.4 Where to find sidecar logs

| Environment | How to view                                                                      |
|-------------|----------------------------------------------------------------------------------|
| Local       | `docker compose --project-name openemr logs -f agent-service`                    |
| Render      | Render dashboard → `agent-service` → **Logs** tab (live tail)                    |
| Honeycomb   | When `HONEYCOMB_API_KEY` is configured, traces land in the configured dataset; each request carries its `trace_id` so you can pivot from a chart entry to the trace |

For OpenEMR-side request lines, use Railway's **Logs** view on the
`openemr-web` service, or run `railway logs --latest` from a local checkout
linked with `railway link`.

---

## 7. Eval gate (pre-push hook + CI)

The 50-case offline eval at
[`agent-service/agent_service/eval/`](../agent-service/agent_service/eval/)
gates every push and every pull request. It compares per-rubric pass rates
against the checked-in baseline at
[`agent-service/agent_service/eval/baseline.json`](../agent-service/agent_service/eval/baseline.json)
and exits non-zero on any threshold breach or regression.

### 7.1 Install the local pre-push hook

The hook source lives at [`scripts/hooks/pre-push`](../scripts/hooks/pre-push)
and is copied into `.git/hooks/pre-push` by a one-shot installer. The
installer is non-destructive — if a `pre-push` hook already exists at the
target path, it is renamed to `pre-push.bak.<unix-timestamp>` before being
overwritten, and a warning is printed.

**Windows (PowerShell):**

```powershell
./scripts/install-eval-hook.ps1
```

**macOS / Linux (bash):**

```bash
./scripts/install-eval-hook.sh
```

After installation, every `git push` runs the eval first. A failing eval
aborts the push before any data is sent to the remote.

### 7.2 GitHub Actions

The same eval runs in CI on every pull request and on every push to
`master` and `codex/**` branches. The workflow is defined at
[`.github/workflows/agent-eval.yml`](../.github/workflows/agent-eval.yml)
and uses the identical command as the local hook
(`python -m agent_service.eval --baseline agent_service/eval/baseline.json`),
so a clean local run is a strong signal that CI will pass.

CI installs `agent-service` via `pip install -e ".[dev]"` from the
`agent-service/` directory, sets `OPENAI_API_KEY` to the empty string so
the FakeLLMClient guard does not reject construction, and fails the
workflow on any non-zero exit from the eval runner.

### 7.3 Bypass for emergencies

If you absolutely must push without running the eval (for example, an
infrastructure-only doc fix while the OpenAI API is unavailable), set
`SKIP_EVAL_HOOK=1` for that one invocation:

**bash / Git Bash:**

```bash
SKIP_EVAL_HOOK=1 git push
```

**PowerShell:**

```powershell
$env:SKIP_EVAL_HOOK = "1"; git push; Remove-Item Env:SKIP_EVAL_HOOK
```

This is for emergencies only. The CI gate is not bypassable, so any push
that would have failed the local hook will still be caught before merge.

### 7.4 Proving the gate actually catches regressions

The `--inject-regression` flag deliberately introduces controlled
failures to demonstrate the eval gate is not a no-op. See
[`EVAL_REGRESSION_PROOF.md`](EVAL_REGRESSION_PROOF.md) for copy-paste
demo commands covering the three supported regressions
(`drop-citations`, `wrong-value`, `flip-abnormal-flags`) and their
expected output.

---

## 8. Cross-references

- [`agent-service/CONTRACT.md`](../agent-service/CONTRACT.md) — frozen HTTP
  contract (request/response shape, error codes, auth).
- [`W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md) — architecture defense
  (component diagram, sequences, RAG design, observability).
- [`week-2-plan.md`](../week-2-plan.md) — plan of record for the Week 2
  deliverable; §4.14 owns the deployment story.
- [`sidecar-detailed-steps.md`](../sidecar-detailed-steps.md) —
  step-by-step implementation tracker (S0 through S26).
- [`.env.example`](../.env.example) — exhaustive list of host env vars,
  including the `OPENEMR_AGENT_SIDECAR_*` block this guide expands on.
