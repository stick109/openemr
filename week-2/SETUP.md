# Week 2 — Setup Guide

How to run the Clinical Co-Pilot Week 2 stack locally and against the deployed environment.
For architecture, see [`../W2_ARCHITECTURE.md`](../W2_ARCHITECTURE.md). For the demo flow, see [`DEMO.md`](DEMO.md).

## Deployed app

| Surface | URL | Notes |
|---------|-----|-------|
| OpenEMR (Railway) | _TODO: insert deployed URL when published_ | Grader entry point |
| Sidecar (Render)  | Internal only | Reachable only from OpenEMR via shared secret |

Default login:

| Field    | Value   |
|----------|---------|
| Username | `admin` |
| Password | `pass`  |

## Local setup

### Prerequisites

- Docker Desktop (Compose v2.39+)
- Python 3.11 (invoke as `py -3.11` on Windows; the `python` alias resolves to the Windows Store stub)
- PHP 8.2+ and Composer 2.x (only needed for host-side PHP tooling; the container ships its own)
- Node 20+ and npm
- Git

### Environment variables

Copy `.env.example` at the repo root to `.env` and fill in the secrets — Compose picks it up automatically.

#### Sidecar (`agent-service`)

Read by [`agent-service/agent_service/config.py`](../agent-service/agent_service/config.py).

| Variable               | Required | Default | Description |
|------------------------|----------|---------|-------------|
| `AGENT_SHARED_SECRET`  | Yes | (none) | Shared secret checked on every non-health request. Must match the OpenEMR side bit-for-bit. |
| `OPENAI_API_KEY`       | Yes (effective) | (empty) | OpenAI key for vision extraction (Files API + Structured Outputs) and `text-embedding-3-small`. Container starts without it; extraction calls fail until set. |
| `COHERE_API_KEY`       | Optional | (empty) | Cohere key for `rerank-english-v3.0`. When unset, falls back to local cross-encoder reranker. |
| `HONEYCOMB_API_KEY`    | Optional | (empty) | Honeycomb ingest key for OpenTelemetry export. When unset, traces stay local. |
| `AGENT_LOG_LEVEL`      | Optional | `INFO`  | Python `logging` level (`DEBUG`, `INFO`, `WARNING`). |
| `AGENT_DEBUG`          | Optional | `false` | Truthy values enable verbose stack traces and FastAPI debug mode. |

#### OpenEMR (PHP host)

Read by [`src/Services/Agent/Sidecar/AgentSidecarConfig.php`](../src/Services/Agent/Sidecar/AgentSidecarConfig.php).

| Variable                                | Required | Default                     | Description |
|-----------------------------------------|----------|-----------------------------|-------------|
| `OPENEMR_AGENT_SIDECAR_URL`             | Yes | `http://agent-service:8010` | Base URL OpenEMR uses to reach the sidecar. Inside Compose this is the service name; outside Compose use `http://127.0.0.1:8010` or the Render hostname. |
| `OPENEMR_AGENT_SIDECAR_SECRET`          | Yes | (empty) | Same value as `AGENT_SHARED_SECRET`. Sent in the `X-Agent-Secret` header. |
| `OPENEMR_AGENT_SIDECAR_TIMEOUT_SECONDS` | Optional | `60` | HTTP timeout. Extraction + RAG can be slow; 60 s is the recommended floor. |

### Start the stack

```powershell
cd docker\development-easy
docker compose --project-name openemr up -d
```

Wait roughly 3–6 minutes the first time — the easy-dev image rsyncs the checkout before Apache comes up. Pin `--project-name openemr` so subsequent commands target the same container set.

To rebuild only the Python sidecar after a change:

```powershell
docker compose --project-name openemr up -d agent-service
```

Verify sidecar health from the host:

```powershell
Invoke-RestMethod http://127.0.0.1:8010/healthz
```

Expected response: `{ "status": "ok" }`.

Shared-secret round-trip (proves auth wiring):

```powershell
$secret = "dev-shared-secret"   # match OPENEMR_AGENT_SIDECAR_SECRET
Invoke-RestMethod `
  -Uri http://127.0.0.1:8010/api/agent/run `
  -Method Post `
  -Headers @{ 'X-Agent-Secret' = $secret; 'Content-Type' = 'application/json' } `
  -Body '{"patient_id":1,"file_path":"/var/uploads/agent/missing.pdf","doc_type":"lab_pdf","encounter_id":1,"trace_id":"00000000-0000-4000-8000-000000000000"}'
```

A missing file returns HTTP 404 with `{"error":"file_not_found", ...}`. A wrong secret returns 403; a missing header returns 401.

Tail sidecar logs:

```powershell
docker compose --project-name openemr logs -f agent-service
```

Local OpenEMR endpoints:

- HTTP:  `http://localhost:8300/`
- HTTPS: `https://localhost:9300/`

### Stop the stack

```powershell
docker compose --project-name openemr down
```

Add `--volumes` to wipe the database and the `agent-uploads` shared volume.

## Pre-push eval hook

The 50-case offline eval at [`agent-service/agent_service/eval/`](../agent-service/agent_service/eval/) gates every push. It compares per-rubric pass rates against [`baseline.json`](../agent-service/agent_service/eval/baseline.json) and exits non-zero on any threshold breach.

### Install

The hook source lives at [`scripts/hooks/pre-push`](../scripts/hooks/pre-push). The installer is non-destructive — an existing `pre-push` hook is renamed to `pre-push.bak.<unix-timestamp>` before being overwritten.

Windows (PowerShell):

```powershell
./scripts/install-eval-hook.ps1
```

macOS / Linux:

```bash
./scripts/install-eval-hook.sh
```

The hook unsets `OPENAI_API_KEY` in its own subshell before running the eval, so the outer shell is unaffected.

### Bypass (emergencies only)

For pushes that must skip the eval (e.g., infra-only doc fix while OpenAI is unavailable):

PowerShell:

```powershell
$env:SKIP_EVAL_HOOK = "1"; git push; Remove-Item Env:SKIP_EVAL_HOOK
```

bash / Git Bash:

```bash
SKIP_EVAL_HOOK=1 git push
```

The CI gate is **not** bypassable — any push that would have failed locally is still caught before merge.

### Verify the regression gate works

The `--inject-regression` flag deliberately introduces controlled failures to prove the gate is not a no-op. See [`EVAL.md`](EVAL.md) for copy-paste demos covering `drop-citations`, `wrong-value`, and `flip-abnormal-flags`.

## Grader walkthrough

1. Open the deployed Railway URL above.
2. Log in with `admin` / `pass`.
3. Open or create a patient and start a new encounter.
4. Inside the encounter, add the **Upload Intake Form** form (`interface/forms/upload_intake_form/new.php`).
5. Pick a document type (`Intake Form` or `Lab Report`) and choose a PDF — sample fixtures live under `agent-service/eval/fixtures/`, or use any realistic intake/lab PDF.
6. Submit. PHP validates CSRF + ACL + magic bytes, copies the file to `/var/uploads/agent/<encounter>/<file>.pdf`, and POSTs to the sidecar at `POST /api/agent/run`.
7. After the run, the encounter timeline shows the parsed result with per-field citations. PDF-derived fields support click-to-source bounding-box overlays in `view.php`.

Where to find logs:

| Environment | How to view |
|-------------|-------------|
| Local sidecar | `docker compose --project-name openemr logs -f agent-service` |
| Render sidecar | Render dashboard → `agent-service` → **Logs** tab |
| Honeycomb | When `HONEYCOMB_API_KEY` is set, traces land in the configured dataset; pivot by `trace_id` |
| Railway OpenEMR | Railway dashboard → `openemr-web` → **Logs**, or `railway logs --latest` from a linked checkout |

## Windows / PowerShell notes

- **`python` alias:** `python` resolves to the Windows Store stub. Use `py -3.11` or the full path. Piping a here-string into `py -3.11 -` can prepend a UTF-8 BOM and break parsing — prefer `py -3.11 -c "<code>"` or a temp UTF-8-no-BOM file written via `[System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding $false))`.
- **No `&&` chaining:** this is Windows PowerShell 5.x, not pwsh. Run commands separately or check `$LASTEXITCODE` between them.
- **No `-SkipHttpErrorCheck`:** that switch is pwsh-only. Wrap `Invoke-WebRequest` in `try`/`catch` and read `$_.Exception.Response`.
- **Docker Desktop must be running** before `run-docker.ps1`; the wrapper now tries to start it but a cold start may need a manual launch.
- **First start is slow:** allow 5–6 minutes for forced recreates of the easy-dev containers — Apache only listens after the entrypoint finishes its rsync.
- **Use `http://localhost:8300/`** (not HTTPS) for local readiness checks unless TLS itself is under test.
- **Pipe-containing PHPUnit filters:** `vendor\bin\phpunit.bat --filter "A|B"` fails because `cmd.exe` interprets `|`. Use the `punit` helper (calls PHP directly) for any filter with regex alternation.
- **Isolated tests need their own config:** `vendor\bin\phpunit.bat -c phpunit-isolated.xml ...` — the default `phpunit.xml` boots the full OpenEMR stack and needs a database.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `http://localhost:8300/` returns `ERR_EMPTY_RESPONSE` while the container is `running` but `unhealthy` | Apache is still copying via rsync. Wait until `docker compose -p openemr exec -T openemr ps aux` shows `/usr/sbin/httpd -D FOREGROUND`, then wait one more health-check cycle. Use `.\run-docker.ps1 -Restart` for an explicit stop/start. |
| `docker compose up --detach --wait` fails with `Bind for 0.0.0.0:4444 failed: port is already allocated` | A previous easy-dev stack is running under project name `openemr`. Target it explicitly with `-p openemr`, or `docker compose -p openemr down` first. |
| Host-side `php bin\console ...` fails with `mysqli_query(): ... false given` | `sites\default\sqlconf.php` points at the Compose hostname `mysql`, which doesn't resolve from the Windows host. Run console commands inside the container: `docker compose -p openemr exec openemr ...`. |
| `Invoke-RestMethod http://127.0.0.1:8010/healthz` fails with connection refused | Sidecar isn't up. Check `docker compose --project-name openemr ps agent-service` and tail `logs -f agent-service`. |
| Sidecar returns 401/403 on every request | `OPENEMR_AGENT_SIDECAR_SECRET` (PHP) and `AGENT_SHARED_SECRET` (Python) don't match byte-for-byte. Restart both services after fixing `.env`. |
| Extraction calls fail with OpenAI errors | `OPENAI_API_KEY` is empty or invalid. Set it in the sidecar environment and restart `agent-service`. |
