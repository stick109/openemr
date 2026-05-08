# Patient Dashboard Migration Plan — OpenEMR → ASP.NET / .NET 9

## 1. Executive Summary

**Recommendation: ASP.NET Core 9 Razor Pages**, scaffolded with `dotnet new webapp --name OpenEmr.Dashboard`, deployed as a sibling Railway service `dashboard-dotnet` mirroring how `agent-service` is built and deployed today (own directory at repo root, own `Dockerfile`, own `railway.toml`).

The dashboard is read-only data, loaded once when the clinician navigates to a patient. Razor Pages renders typed HTML server-side from C# (the modern equivalent of what PHP is doing today). Bootstrap (already in the default template) handles styling. No JavaScript framework, no bundler, no npm. The dashboard PageModel fans out 7 concurrent FHIR calls via `Task.WhenAll` and renders the full page in one shot.

What we get vs. the PHP original:
- Typed C# DTOs end-to-end against the FHIR API — compile-time field-name validation instead of runtime `array['name'][0]['family']` typos.
- Off-the-shelf OIDC middleware for auth (`Microsoft.AspNetCore.Authentication.OpenIdConnect`).
- Concurrent FHIR fetches via `Task.WhenAll` instead of serial PHP calls.
- Single C# stack on the dashboard page (no Smarty + jQuery + PHP mix).

---

## 2. Framework Choice & Rationale

### Comparison

| Option | Why considered | Why not chosen |
|---|---|---|
| **Razor Pages** ✅ | Lean, server-rendered, typed C#, no JS framework, demonstrably off PHP | None — fits the use case |
| MVC + Razor | Controllers-based flexibility | Razor Pages' page-centric model fits a dashboard better; less ceremony |
| Minimal API + React/Vue frontend | Familiar to many teams | Two stacks; npm/bundler overhead for an internal dashboard |

**Scaffold command:**
```
dotnet new webapp --name OpenEmr.Dashboard --use-program-main
```
We'll layer in OIDC manually rather than using `--auth Individual` (which adds ASP.NET Identity — a *replacement* auth backend the assignment forbids).

---

## 3. Integration Architecture

### 3a. Production (Railway) — primary deployment

Three sibling Railway services in one project, plus the existing MySQL service. Each service has its own auto-assigned public URL (`<service>.up.railway.app` or a custom domain) and an internal `*.RAILWAY_PRIVATE_DOMAIN` reachable from peer services.

```
                                ┌───────────────────────────────────┐
                                │  Railway project                  │
                                │                                   │
                                │  ┌────────────────────────────┐   │
  Browser ──HTTPS public URL──▶ │  │ openemr-web (Apache+PHP)   │   │
                                │  │   /interface/...           │   │
                                │  │   /oauth2/default/...      │   │
                                │  │   /apis/default/fhir/...   │   │
                                │  └────────────────────────────┘   │
                                │             ▲                     │
                                │             │ http://${{openemr-web.RAILWAY_PRIVATE_DOMAIN}}
                                │             │   (private DNS, in-project)
                                │  ┌────────────────────────────┐   │
  Browser ──HTTPS public URL──▶ │  │ dashboard-dotnet (Kestrel) │   │
                                │  │   /signin-oidc             │   │
                                │  │   /Patient/{pid}           │   │
                                │  │   /healthz                 │   │
                                │  └────────────────────────────┘   │
                                │                                   │
                                │  ┌────────────────────────────┐   │
                                │  │ agent-service (existing)   │   │
                                │  │   :8010                    │   │
                                │  └────────────────────────────┘   │
                                │                                   │
                                │  ┌────────────────────────────┐   │
                                │  │ MySQL (existing)           │   │
                                │  └────────────────────────────┘   │
                                └───────────────────────────────────┘
```

The dashboard reaches OpenEMR's FHIR API via Railway's private DNS (`http://${{openemr-web.RAILWAY_PRIVATE_DOMAIN}}/apis/default/fhir/`) — same pattern OpenEMR uses today to reach `agent-service`. OAuth login redirects use OpenEMR's **public** URL because the browser must follow them, and the issuer URL must match between browser and container views.

We don't reverse-proxy the dashboard through OpenEMR's Apache; agent-service runs as a peer with its own URL, and the dashboard does too.

### 3b. Dev-easy (local testing only)

Same services on a Docker Compose network, exposed to the developer's machine on host ports.

```
                              ┌─────────────────────────────────────┐
                              │  Docker network: development-easy   │
                              │                                     │
                              │  ┌──────────────────────────────┐   │
  Browser ──:8300──▶          │  │ openemr   (Apache+PHP)       │   │
                              │  └──────────────────────────────┘   │
                              │                ▲                    │
                              │                │ http://openemr     │
                              │                │   (service DNS)    │
                              │  ┌──────────────────────────────┐   │
  Browser ──:8400──▶          │  │ dashboard-dotnet (Kestrel)   │   │
                              │  └──────────────────────────────┘   │
                              │  ┌──────────────────────────────┐   │
                              │  │ agent-service (existing)     │   │
                              │  └──────────────────────────────┘   │
                              └─────────────────────────────────────┘
```

Dev-easy host ports (visible to the developer's browser only):

| Service | Internal | Host port | Env var |
|---|---|---|---|
| openemr (HTTP) | 80 | 8300 | `WT_HTTP_PORT` |
| agent-service | 8010 | 8010 | `WT_AGENT_PORT` |
| **dashboard-dotnet** | **8080** | **8400** | **`WT_DASHBOARD_PORT`** |

---

## 4. OAuth2 / OIDC Integration

### 4a. The model

All OIDC URLs in the .NET app are **read from environment variables**. Nothing about the URL scheme, host, or port is hardcoded. To deploy in another environment you change env values, not code.

| Env var | Production (Railway) | Dev-easy local testing |
|---|---|---|
| `OPENEMR_OIDC_AUTHORITY` | `https://openemr-web.up.railway.app/oauth2/default` (or custom domain) | `http://host.docker.internal:8300/oauth2/default` |
| `OPENEMR_PUBLIC_URL` | `https://openemr-web.up.railway.app` (used for back-nav links, §7b) | `http://localhost:8300` |
| `OPENEMR_FHIR_BASE_URL` | `http://${{openemr-web.RAILWAY_PRIVATE_DOMAIN}}/apis/default/fhir/` (private DNS, server-to-server) | `http://openemr/apis/default/fhir/` (Docker service DNS) |
| `DASHBOARD_OIDC_REDIRECT_URI` | `https://dashboard-dotnet.up.railway.app/signin-oidc` (or custom domain) | `http://localhost:8400/signin-oidc` |
| `DASHBOARD_OIDC_CLIENT_ID` / `_SECRET` | from prod's OAuth client registration | from dev's OAuth client registration |

The OAuth client must be registered at OpenEMR with the redirect URI for *that* environment. A dev client and a prod client are separate registrations; OpenEMR's admin UI for creating clients is known to fail silently (see §11 #1 — handle only if it bites).

In **production**, browser and dashboard container both resolve `openemr-web.up.railway.app` to the same public URL. The OIDC `iss` claim in the issued token matches what the .NET middleware uses for validation. No special trickery.

### 4b. Dev-easy: hostname-mismatch workaround

In `docker/development-easy/docker-compose.yml`, the browser sees OpenEMR at `localhost:8300` (host port mapping), while the dashboard container sees it at `openemr` (Docker service-name DNS). The OIDC `iss` claim must match the URL the .NET middleware uses, so we need a single string both sides can resolve.

**Workaround for local testing only: `host.docker.internal:8300`.** Docker Desktop on Windows (the user's machine) and macOS resolves this name on both the host (so the browser reaches it) and inside containers (so dashboard-dotnet reaches it). Linux Docker hosts need a one-line entry in `/etc/hosts` and the `extra_hosts: ["host.docker.internal:host-gateway"]` line on the dashboard service (§8c includes this).

In the **dev-easy compose only**, change:
```yaml
OPENEMR_SETTING_site_addr_oath: 'https://localhost:${WT_HTTPS_PORT:-9300}'
```
to:
```yaml
OPENEMR_SETTING_site_addr_oath: 'http://host.docker.internal:${WT_HTTP_PORT:-8300}'
```

Production OpenEMR keeps `site_addr_oath` set to its real public URL — this entire workaround disappears.

### 4c. Endpoints (parameterized)

All endpoints have the form `{OPENEMR_OIDC_AUTHORITY}/{operation}`:

| Purpose | Path |
|---|---|
| Discovery | `/.well-known/openid-configuration` |
| Authorize | `/authorize` |
| Token | `/token` |
| Userinfo | `/userinfo` |
| JWKS | `/jwk` |
| Registration | `/registration` |
| Logout | `/logout` |

**Scopes to request** (clinician/PCP, user role):
```
openid fhirUser offline_access
api:fhir
user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs
user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs
```

### 4d. ASP.NET wiring in `Program.cs`

```csharp
builder.Services.AddAuthentication(o => {
    o.DefaultScheme = CookieAuthenticationDefaults.AuthenticationScheme;
    o.DefaultChallengeScheme = OpenIdConnectDefaults.AuthenticationScheme;
})
.AddCookie()
.AddOpenIdConnect(o => {
    o.Authority = cfg["OPENEMR_OIDC_AUTHORITY"];
    o.ClientId = cfg["DASHBOARD_OIDC_CLIENT_ID"];
    o.ClientSecret = cfg["DASHBOARD_OIDC_CLIENT_SECRET"];
    o.ResponseType = "code";
    o.UsePkce = true;
    o.SaveTokens = true;
    o.GetClaimsFromUserInfoEndpoint = true;
    o.RequireHttpsMetadata = !env.IsDevelopment();    // HTTP allowed only in dev
    o.Scope.Clear();
    foreach (var s in new[]{"openid","fhirUser","offline_access","api:fhir",
        "user/Patient.rs","user/AllergyIntolerance.rs","user/Condition.rs",
        "user/MedicationRequest.rs","user/CareTeam.rs","user/Encounter.rs"})
        o.Scope.Add(s);
    o.CallbackPath = "/signin-oidc";
});
```

`RequireHttpsMetadata` is the only env-aware line; production runs with HTTPS enforcement on automatically.

**Token storage:** access + refresh tokens go into the auth cookie (`SaveTokens = true`). Cookie is HttpOnly + SameSite=Lax + Secure (in non-dev environments). A small middleware refreshes the access token before each FHIR call if it's within 30 seconds of expiry.

---

## 5. FHIR Data Layer

**Library choice:** **hand-rolled C# `record` types per resource**, not the `Hl7.Fhir.R4` NuGet (~14 MB, drags in Newtonsoft.Json). We deserialize with `System.Text.Json` and `[JsonPropertyName]`. ~300 lines for the 7 resources.

| Resource | URL | Fields we keep | DTO |
|---|---|---|---|
| Patient | `GET /Patient/{uuid}` | `id`, `name[].family/given`, `birthDate`, `gender`, `active`, `identifier[where type.coding.code=='PT'].value` (→ MRN), `deceasedBoolean` | `FhirPatient` |
| AllergyIntolerance | `GET /AllergyIntolerance?patient={uuid}` | `code.text`, `clinicalStatus`, `criticality`, `reaction[].manifestation[].text` | `FhirAllergy` |
| Condition (Problem List) | `GET /Condition?patient={uuid}&category=problem-list-item` | `code.coding[0].code/display`, `clinicalStatus`, `verificationStatus`, `onsetDateTime`, `recordedDate` | `FhirCondition` |
| MedicationRequest (active) | `GET /MedicationRequest?patient={uuid}&status=active` | `medicationCodeableConcept.text`, `dosageInstruction[0].text`, `authoredOn`, `status` | `FhirMedicationRequest` |
| MedicationRequest (history) | `GET /MedicationRequest?patient={uuid}&intent=order` | same fields | reuse |
| CareTeam | `GET /CareTeam?patient={uuid}` | `participant[].member.display`, `participant[].role[].text`, `status` | `FhirCareTeam` |
| Encounter (+1) | `GET /Encounter?patient={uuid}&_count=20&_sort=-date` | `period.start/end`, `class.code`, `type[0].text`, `participant[].individual.display`, `reasonCode[0].text` | `FhirEncounter` |

OpenEMR's FHIR layer only exposes `MedicationRequest` (no `MedicationStatement` for the dashboard). We render two cards from the same resource: **Medications** = `status=active`, **Prescriptions** = full history (`intent=order`, last 20 by `authoredOn`).

**MRN extraction:** `pubpid` is emitted as identifier with `type.coding[0].code == "PT"`:
```csharp
string? Mrn(FhirPatient p) =>
  p.Identifier?.FirstOrDefault(i =>
    i.Type?.Coding?.Any(c => c.Code == "PT") == true)?.Value;
```

**HTTP client setup** (FHIR base URL is env-driven per §4a):
```csharp
builder.Services.AddHttpClient<FhirClient>(c => {
    c.BaseAddress = new Uri(cfg["OPENEMR_FHIR_BASE_URL"]);
    c.DefaultRequestHeaders.Accept.Add(new("application/fhir+json"));
}).AddHttpMessageHandler<BearerTokenHandler>();
```

`BearerTokenHandler : DelegatingHandler` reads `HttpContext.GetTokenAsync("access_token")` and sets `Authorization: Bearer ...` on each outgoing request.

**Error / empty states** (rendered server-side):
- *Error*: red Bootstrap alert with HTTP status + `OperationOutcome.issue[0].diagnostics` if FHIR returned one. Each card has its own error state — one failing card doesn't break the page.
- *Empty*: muted "No active allergies recorded." Absence is clinically meaningful.

The dashboard PageModel fans out all 7 FHIR calls concurrently via `Task.WhenAll`, each wrapped in a per-card error handler that returns either parsed data or an error string. Page renders when the slowest call finishes (or errors out). Total latency is governed by the slowest endpoint, not the sum.

---

## 6. UI Surface

**Chosen UI library: Bootstrap 5** (already shipped by the `dotnet new webapp` template). Reasons: (a) OpenEMR already uses Bootstrap 4.6, so the visual style stays familiar; (b) zero build step; (c) decent built-in accessibility.

| Card | Fields displayed |
|---|---|
| **Patient header (sticky bar)** | `Family, Given (Middle)`, DOB + age, gender pill, MRN, Active / Inactive / Deceased status badge |
| **Allergies** | Substance text, criticality icon, clinical status, top reaction manifestation |
| **Problem List** | Code display, ICD/SNOMED code, onset date, clinical status, verification status |
| **Medications (active)** | Drug name, dose-instruction text, authored-on date |
| **Prescriptions (history, last 20)** | Drug name, dose, status, authored-on date |
| **Care Team** | Member name, role, participant status |
| **Encounters (+1 section)** | Date range, class code, type, attending provider name, reason |

**+1 section justification: Encounters.** Highest clinical signal-per-byte for a PCP — answers "what happened last visit, who saw the patient" without needing to drill in. Bundle is small (~10–20 entries). Vitals or labs would require Observation parsing with LOINC codes and `valueQuantity` unit handling — too much surface for the week.

**Layout**: 12-column Bootstrap grid. Header spans 12 cols and is sticky. Below: 3 columns on desktop (≥1200 px), 1 column on mobile. Each card is `<div class="card">` with a `<div class="card-header">` showing the title. To refresh, the user reloads the page (F5).

---

## 7. Navigation Between OpenEMR and the New Dashboard

Both directions need to work. The dashboard and OpenEMR live at different origins (different Railway services in prod, different ports in dev). **No shared session** — that's intentional. The user's existing OpenEMR cookie is still valid for the OpenEMR origin, so PHP pages always recognize them when they navigate back.

### 7a. Forward — PHP → ASP.NET (entering the new dashboard)

A clinician picks a patient in OpenEMR's existing patient finder, then clicks "Modern Dashboard" in the patient menu. They land on `/Patient/{pid}` on the dashboard service with the patient pre-selected.

**Mechanism:** add one entry to the site-local patient menu JSON (`sites/default/documents/custom_menus/patient_menus/Custom.json` — shadows the upstream `interface/main/tabs/menu/menus/patient_menus/standard.json` without modifying upstream-tracked files):

```json
{ "label": "Modern Dashboard{{patient file}}", "menu_id": "modern_dashboard",
  "target": "main", "on_click": "top.restoreSession()",
  "url": "<DASHBOARD_PUBLIC_URL>/Patient/{{pid}}", "pid": "true",
  "children": [], "requirement": 0 }
```

Replace `<DASHBOARD_PUBLIC_URL>` with whatever the dashboard's public URL is in that environment (`https://dashboard-dotnet.up.railway.app` in prod, `http://localhost:8400` in dev-easy). The `{{pid}}` token is OpenEMR's existing template-expansion mechanism; it expands at click time to the active patient's local pid. Site admins maintain `Custom.json` per environment; this is the standard OpenEMR pattern for site-specific menu customization.

**Resolving FHIR uuid from the local pid:** the menu hand-off arrives at `/Patient/{pid}` with OpenEMR's numeric `pid` (because `"pid": "true"` expands to local pid, not FHIR uuid). The dashboard issues `GET /Patient?identifier=PT|{pid}` to translate to a FHIR uuid for subsequent calls. The `identifier` search param is supported (`src/Services/FHIR/FhirPatientService.php`).

**Possible iframe issue:** `target=main` opens the link inside OpenEMR's top-frame iframe. This may interact poorly with the OIDC redirect chain (see §11 #2 — handle only if it actually breaks).

### 7b. Back — ASP.NET → PHP (returning to legacy pages)

URLs are built from the env var `OPENEMR_PUBLIC_URL` (see §4a) — never hardcoded. In Razor:

```cshtml
@inject IConfiguration Cfg
@{ var oe = Cfg["OPENEMR_PUBLIC_URL"]; }
<a href="@oe/interface/patient_file/summary/demographics.php?set_pid=@Model.Pid">Open in OpenEMR</a>
```

Per-row examples:
- Patient header → `{OPENEMR_PUBLIC_URL}/interface/patient_file/summary/demographics.php?set_pid={pid}`
- Problem List row → `{OPENEMR_PUBLIC_URL}/interface/patient_file/summary/add_edit_issue.php?issue=...&id={issueId}`
- Persistent "← Back to OpenEMR" button in header → `{OPENEMR_PUBLIC_URL}/interface/main/tabs/main.php?set_pid={pid}`

`set_pid` is OpenEMR's canonical mechanism for switching the active patient via URL (used throughout `interface/`).

---

## 8. Build & Deploy Workflow

### 8a. Project layout — mirror agent-service

`dashboard-dotnet/` at repo root, parallel to `agent-service/`:

```
dashboard-dotnet/
├── Dockerfile                          # multi-stage; structure mirrors agent-service/Dockerfile
├── railway.toml                        # mirrors agent-service/railway.toml
├── .dockerignore
├── .gitignore
├── OpenEmr.Dashboard.sln
├── src/OpenEmr.Dashboard/
│   ├── OpenEmr.Dashboard.csproj        # net9.0
│   ├── Program.cs
│   ├── appsettings.json
│   ├── appsettings.Development.json    # gitignored — holds OIDC client id/secret for local testing
│   ├── Pages/
│   │   ├── _ViewImports.cshtml
│   │   ├── _ViewStart.cshtml
│   │   ├── Shared/
│   │   │   ├── _Layout.cshtml          # Bootstrap 5 (default template)
│   │   │   ├── _PatientHeader.cshtml
│   │   │   ├── _AllergiesCard.cshtml   # one partial view per card
│   │   │   ├── _ProblemsCard.cshtml
│   │   │   ├── _MedicationsCard.cshtml
│   │   │   ├── _PrescriptionsCard.cshtml
│   │   │   ├── _CareTeamCard.cshtml
│   │   │   └── _EncountersCard.cshtml
│   │   ├── Index.cshtml(.cs)           # auth landing
│   │   └── Patient/
│   │       └── Index.cshtml(.cs)       # main dashboard (route: /Patient/{pid})
│   │                                   # OnGetAsync fans out 7 FHIR calls via Task.WhenAll
│   ├── Fhir/
│   │   ├── FhirClient.cs
│   │   ├── BearerTokenHandler.cs
│   │   └── Records/  (FhirPatient.cs, FhirAllergy.cs, ...)
│   └── Auth/RefreshTokenMiddleware.cs
└── tests/OpenEmr.Dashboard.Tests/
    ├── OpenEmr.Dashboard.Tests.csproj  # xUnit + Mvc.Testing + AngleSharp
    ├── Fhir/PatientMappingTests.cs
    ├── Fhir/ConditionMappingTests.cs
    ├── Auth/OidcCallbackTests.cs       # WebApplicationFactory
    └── Pages/PatientPageTests.cs       # WebApplicationFactory + HTML assertion
```

### 8b. Dockerfile — multi-stage, mirrors agent-service style

Slim runtime image, unprivileged user, healthcheck via curl. Same general layering as `agent-service/Dockerfile`, adapted for .NET:

```dockerfile
# syntax=docker/dockerfile:1.7

FROM mcr.microsoft.com/dotnet/sdk:9.0 AS build
WORKDIR /src
COPY OpenEmr.Dashboard.sln ./
COPY src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj src/OpenEmr.Dashboard/
RUN dotnet restore src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj
COPY src/OpenEmr.Dashboard/ src/OpenEmr.Dashboard/
RUN dotnet publish src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj \
    --configuration Release --no-restore -o /app/publish

FROM mcr.microsoft.com/dotnet/aspnet:9.0 AS runtime
ENV ASPNETCORE_URLS=http://+:8080 \
    ASPNETCORE_ENVIRONMENT=Production \
    DOTNET_RUNNING_IN_CONTAINER=true \
    DOTNET_NOLOGO=1

RUN apt-get update \
    && apt-get install --no-install-recommends --yes curl \
    && rm -rf /var/lib/apt/lists/*

# Unprivileged user — uid/gid 10002 (10001 is taken by agent-service).
RUN groupadd --system --gid 10002 dashboard \
    && useradd --system --uid 10002 --gid dashboard --home /app --shell /usr/sbin/nologin dashboard

WORKDIR /app
COPY --from=build /app/publish ./
RUN chown -R dashboard:dashboard /app
USER dashboard

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/healthz || exit 1

ENTRYPOINT ["dotnet", "OpenEmr.Dashboard.dll"]
```

The container listens on port 8080 internally. In Railway, set service variable `PORT=8080` so Railway's edge proxy forwards correctly (matches the agent-service pattern in `deploy-railway.ps1` line 591). The dev-easy compose maps `8080 → 8400` on the host.

`/healthz` is a one-line addition in `Program.cs`:
```csharp
builder.Services.AddHealthChecks();
// ...
app.MapHealthChecks("/healthz");
```

### 8c. railway.toml — mirrors agent-service/railway.toml

`dashboard-dotnet/railway.toml`:
```toml
[build]
builder = "DOCKERFILE"
dockerfilePath = "Dockerfile"

[deploy]
healthcheckPath = "/healthz"
healthcheckTimeout = 300
restartPolicyType = "ON_FAILURE"
restartPolicyMaxRetries = 10
```

Identical structure to `agent-service/railway.toml`; only `healthcheckPath` differs (both happen to be `/healthz` in this case — coincidence).

### 8d. Production deployment (Railway)

The repo's existing `deploy-railway.ps1` already does a multi-pass deploy (openemr-web first, then agent-service via the `-DeployAgentService` flag). Adding a third pass for `dashboard-dotnet` is a small extension to that script — same pattern:

1. Create the Railway service `dashboard-dotnet` once via the Railway dashboard or `railway service create dashboard-dotnet`.
2. Set service variables on `dashboard-dotnet` (matching the prod column of §4a's table):
   - `OPENEMR_OIDC_AUTHORITY` — public URL of openemr-web (e.g. `https://openemr-web.up.railway.app/oauth2/default`)
   - `OPENEMR_PUBLIC_URL` — public URL of openemr-web
   - `OPENEMR_FHIR_BASE_URL` — `http://${{openemr-web.RAILWAY_PRIVATE_DOMAIN}}/apis/default/fhir/`
   - `DASHBOARD_OIDC_REDIRECT_URI` — public URL of dashboard-dotnet + `/signin-oidc`
   - `DASHBOARD_OIDC_CLIENT_ID` / `_SECRET` — from prod's OAuth client registration (see §11 #1 if the admin form fails)
   - `PORT=8080`, `ASPNETCORE_ENVIRONMENT=Production`
3. Set `OPENEMR_SETTING_site_addr_oath` on the **openemr-web** service to its own public URL (so the OIDC `iss` claim matches what the dashboard expects). Production OpenEMR doesn't need any host.docker.internal trickery — the public URL is reachable from both browser and dashboard container.
4. Deploy: `railway up dashboard-dotnet --service dashboard-dotnet` (or extend `deploy-railway.ps1` with a third pass mirroring the agent-service pass at line 612, `Invoke-RailwayAgentServiceDeploy`).

The `.railwayignore` at repo root excludes `dashboard-dotnet/` source from the openemr-web build context (which doesn't need it). Each service uploads its own subtree.

### 8e. Compose service — dev-easy local testing

Add to `docker/development-easy/docker-compose.yml` (mirrors the existing agent-service block):

```yaml
  dashboard-dotnet:
    restart: always
    container_name: dashboard-dotnet
    image: ${DASHBOARD_DOTNET_LOCAL_IMAGE:-dashboard-dotnet-local:latest}
    build:
      context: ../../dashboard-dotnet
      dockerfile: Dockerfile
    ports:
    - "${WT_DASHBOARD_PORT:-8400}:8080"
    environment:
      OPENEMR_OIDC_AUTHORITY: 'http://host.docker.internal:${WT_HTTP_PORT:-8300}/oauth2/default'
      OPENEMR_PUBLIC_URL: 'http://localhost:${WT_HTTP_PORT:-8300}'
      OPENEMR_FHIR_BASE_URL: 'http://openemr/apis/default/fhir/'
      DASHBOARD_OIDC_CLIENT_ID: '${DASHBOARD_OIDC_CLIENT_ID:-}'
      DASHBOARD_OIDC_CLIENT_SECRET: '${DASHBOARD_OIDC_CLIENT_SECRET:-}'
      DASHBOARD_OIDC_REDIRECT_URI: 'http://localhost:${WT_DASHBOARD_PORT:-8400}/signin-oidc'
      ASPNETCORE_URLS: 'http://+:8080'
      ASPNETCORE_ENVIRONMENT: '${DASHBOARD_ASPNETCORE_ENVIRONMENT:-Development}'
    extra_hosts:
    # Linux Docker hosts don't auto-resolve host.docker.internal. Windows
    # and macOS Docker Desktop do; this line is harmless on those.
    - "host.docker.internal:host-gateway"
    depends_on:
      openemr:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:8080/healthz"]
      start_period: 20s
      interval: 30s
      timeout: 5s
      retries: 3
```

Plus the **dev-easy-only** flip to the existing `openemr` service env: `OPENEMR_SETTING_site_addr_oath` from `https://localhost:9300` to `http://host.docker.internal:8300` (see §4b).

### 8f. Local dev iteration

Two modes:

1. **Container rebuild loop** (matches prod): edit code → `docker compose build dashboard-dotnet && docker compose up -d dashboard-dotnet`. ~10–20s rebuild cost per iteration but exercises the same image we ship.
2. **Host iteration loop, faster** (optional): stop the container, run `dotnet watch run` from `dashboard-dotnet/src/OpenEmr.Dashboard/` on the host. Reads env vars from `appsettings.Development.json` instead of compose. Switch back to mode 1 before declaring a task done.

### 8g. Scaffold sequence (do not run yet)

```
mkdir dashboard-dotnet
cd dashboard-dotnet
dotnet new sln -n OpenEmr.Dashboard
dotnet new webapp -n OpenEmr.Dashboard --use-program-main -o src/OpenEmr.Dashboard
dotnet new xunit -n OpenEmr.Dashboard.Tests -o tests/OpenEmr.Dashboard.Tests
dotnet sln add src/OpenEmr.Dashboard tests/OpenEmr.Dashboard.Tests
dotnet add src/OpenEmr.Dashboard package Microsoft.AspNetCore.Authentication.OpenIdConnect
dotnet add tests/OpenEmr.Dashboard.Tests package Microsoft.AspNetCore.Mvc.Testing
dotnet add tests/OpenEmr.Dashboard.Tests package AngleSharp
# Then author Dockerfile, railway.toml, .dockerignore, .gitignore
```

---

## 9. Testing Plan (minimum viable)

| Test | What it proves | Library |
|---|---|---|
| `PatientMappingTests.MrnExtractedFromPtIdentifier` | The `code=="PT"` selector matches OpenEMR's actual emission | xUnit |
| `PatientMappingTests.NameUseOfficialPreferred` | Multiple `name[]` entries → official wins | xUnit |
| `ConditionMappingTests.ParsesProblemListItem` | Sample US-Core fixture parses without exception | xUnit |
| `OidcCallbackTests.UnauthenticatedHomeRedirectsToAuthorize` | `WebApplicationFactory` boot, GET `/`, assert 302 → `/oauth2/default/authorize?response_type=code&...` | xUnit + Mvc.Testing |
| `PatientPageTests.RendersAllSevenCardsWithMockedFhir` | Inject fake `FhirClient`, GET `/Patient/1`, parse HTML with AngleSharp, assert 7 card divs (header + 6 clinical cards) contain expected text from mocked data | xUnit + Mvc.Testing + AngleSharp |

Five tests, ~200 lines total, runnable as `dotnet test`. CI hook: drop a `.github/workflows/dotnet-test.yml` (or extend existing CI) that runs `dotnet test` on PR — separate pipeline from PHPStan/PHPUnit.

---

## 10. PATIENT_DASHBOARD_MIGRATION.md Outline

Sections to fill in once implementation is done:
1. **Why migrate** — what's wrong/limited with PHP-rendered demographics.php (mixed jQuery/Smarty/Twig, full-page reloads, runtime-only validation of array keys).
2. **Why .NET / Razor Pages** — typed DTOs, off-the-shelf OIDC middleware, no `npm install`, single C# stack on the dashboard page; condensed §2.
3. **What we kept untouched** — OAuth2 server, FHIR controllers, Apache vhost, MariaDB schema. The only PHP-side changes: one menu JSON entry (`Custom.json`) and one env var flip in dev-easy compose (`site_addr_oath`).
4. **What was gained** — compile-time field-name validation on FHIR DTOs; concurrent FHIR fetches via `Task.WhenAll`; Hot Reload during dev; smaller per-page footprint.
5. **What was lost / deferred** — exact OpenEMR Bootstrap palette match, OpenEMR session SSO, in-browser editing, Smarty event hooks.
6. **OIDC integration recipe** — endpoint URL pattern, scope list, the env-var table from §4a, the client-registration recipe (whichever path actually worked).
7. **How to extend** — adding a new card, a new FHIR resource, a new page.

---

## 11. Risks & Open Questions

### Active risks (do not address until they bite us)

1. **Admin UI for creating an OAuth client may fail silently** (assignment-acknowledged setup caveat).
   - **Do not pre-emptively work around this.** Try the OpenEMR admin UI first (Admin → System → API Clients). Only if it actually fails — silently or with errors — fall back to the workaround below. Same recipe applies in dev and prod; URLs change.
   - **Workaround if needed:** in the admin DevTools console:
     ```js
     fetch('<OPENEMR_OIDC_AUTHORITY>/registration', {
       method: 'POST',
       headers: {'Content-Type': 'application/json'},
       body: JSON.stringify({
         client_name: 'Dashboard .NET',
         redirect_uris: ['<DASHBOARD_REDIRECT_URI>'],
         post_logout_redirect_uris: ['<DASHBOARD_PUBLIC_URL>/signout-callback-oidc'],
         scope: 'openid fhirUser offline_access api:fhir ' +
                'user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs ' +
                'user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs'
       })
     }).then(r=>r.json()).then(console.log);
     ```
     Replace the `<...>` placeholders with the URLs from §4a for that environment. Then via the admin UI or directly in the `oauth_clients` DB row, mark `is_enabled=1` and `is_trusted=1`. Document whichever path actually worked in `PATIENT_DASHBOARD_MIGRATION.md`.

2. **The OpenEMR menu may open the new dashboard inside an iframe** (`target=main` in the menu JSON). If the OIDC redirect chain misbehaves inside an iframe (third-party cookie blocking, `X-Frame-Options`), the auth flow breaks.
   - **Do not pre-emptively work around this.** Build the menu link with `target=main` first. Test the OIDC round-trip *through the menu link*, not just by visiting the dashboard URL directly. If it works, leave it.
   - **Mitigation if it actually fails:** change `target` to `_blank` (new tab) or use `top.location.href = ...` to break out of the frame.

### Risks we will handle as part of normal implementation

| Risk | Mitigation |
|---|---|
| **Refresh token rotation** — OpenEMR may rotate refresh tokens on each use | Replace stored refresh token in cookie on every refresh response; if 400 returned, force re-login. Implemented in `RefreshTokenMiddleware`. |
| **OpenEMR menu JSON is per-site** — `Custom.json` shadows upstream | Edit the site-local custom JSON to avoid touching upstream-tracked files; ship the customization per-environment. |
| **Railway service `dashboard-dotnet` must exist before first `railway up`** | Create once via Railway dashboard or `railway service create dashboard-dotnet`. Same as the agent-service workflow. |

**Open questions** (resolve during implementation):
- Does the clinician need access to all patients in the practice or only their own? (Affects whether to filter `Patient` search by `general-practitioner`.)

---

## 12. Task List

Moved to a separate file: [week-2-task-list.md](week-2-task-list.md). It contains:
- A Mermaid dependency graph
- Tasks grouped into parallelizable phases
- Checkbox status per task — flipped as work progresses
- Critical-path identification
- Parallelization opportunities (Phase 0 vs Phase 1 overlap, the 5-card parallel fan-out, Railway deploy running alongside dev work, etc.)
