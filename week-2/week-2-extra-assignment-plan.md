# Patient Dashboard Migration Plan — OpenEMR → ASP.NET / .NET 9

## 1. Executive Summary

**Recommendation: ASP.NET Core 9 Razor Pages + htmx**, scaffolded with `dotnet new webapp --name OpenEmr.Dashboard`, deployed as a sibling Docker container `dashboard-dotnet` mirroring how `agent-service` is built and run today.

The dashboard is mostly read-only data display. We don't need a SPA, a persistent server connection, or a WebAssembly runtime. Razor Pages renders typed HTML server-side from C# (the modern equivalent of what PHP is doing today), and htmx (~14 KB JS library) lets each card fetch itself concurrently in the background by adding a couple of HTML attributes — no JavaScript code, no bundler, no npm.

What we get vs. the PHP original:
- Typed C# DTOs end-to-end against the FHIR API — compile-time field-name validation instead of runtime `array['name'][0]['family']` typos.
- Off-the-shelf OIDC middleware for auth (`Microsoft.AspNetCore.Authentication.OpenIdConnect`).
- Concurrent card loading via htmx without writing async JavaScript.
- Single C# stack on the dashboard page (no Smarty + jQuery + PHP mix).

Tradeoff: still a server-rendered model, so no full "rich SPA" feel. For a clinician dashboard that's mostly display, that's a feature — fewer moving parts, faster to ship, easier to debug.

---

## 2. Framework Choice & Rationale

### Comparison

| Option | Why considered | Why not chosen |
|---|---|---|
| **Razor Pages + htmx** ✅ | Lean, server-rendered, typed C#, tiny JS, demonstrably "off PHP" | Not a true SPA — but we don't need one |
| MVC + Razor (no htmx) | Even simpler | Loses concurrent card loading — every refresh is full page reload |
| Minimal API + JS SPA (React/Vue) | Familiar to most teams | Two stacks again; npm/bundler overhead for an internal dashboard |

**Scaffold command:**
```
dotnet new webapp --name OpenEmr.Dashboard --use-program-main
```
We'll layer in OIDC manually rather than using `--auth Individual` (which adds ASP.NET Identity — a *replacement* auth backend the assignment forbids).

### htmx in 30 seconds

htmx is a single JS file (~14 KB) you include via `<script src="https://unpkg.com/htmx.org@2"></script>`. It adds attributes to HTML tags:

```html
<div hx-get="/Patient/123/Cards/Allergies" hx-trigger="load" hx-swap="innerHTML">
  Loading allergies…
</div>
```

When the page loads, htmx fires a GET to that URL, takes the HTML returned by the server, and swaps it into the `<div>`. That's the entire mental model. Cards on the dashboard work this way — each is a Razor Page handler returning an HTML partial, fetched concurrently by htmx.

---

## 3. Integration Architecture

```
                              ┌─────────────────────────────────────┐
                              │  Docker network: development-easy   │
                              │                                     │
                              │  ┌──────────────────────────────┐   │
  Browser ──:8300──▶          │  │ openemr   (Apache+PHP)       │   │
                              │  │   /interface/...             │   │
                              │  │   /oauth2/default/...        │   │
                              │  │   /apis/default/fhir/...     │   │
                              │  └──────────────────────────────┘   │
                              │                ▲                    │
                              │                │ http://openemr     │
                              │                │   (port 80 inside) │
                              │                │                    │
                              │  ┌──────────────────────────────┐   │
  Browser ──:8400──▶          │  │ dashboard-dotnet (Kestrel)   │   │
                              │  │   /signin-oidc               │   │
                              │  │   /Patient/{pid}             │   │
                              │  │   /Patient/{pid}/Cards/...   │   │
                              │  └──────────────────────────────┘   │
                              │                                     │
                              │  ┌──────────────────────────────┐   │
                              │  │ agent-service (Python/FastAPI│   │
                              │  │   :8010 — existing peer)     │   │
                              │  └──────────────────────────────┘   │
                              └─────────────────────────────────────┘

  All URLs above are env-driven (see §4). The labels show dev-easy values;
  in prod they become the real public domain (e.g. https://openemr.example.com).
```

`dashboard-dotnet` is a **first-class peer of `openemr` and `agent-service`**, built and deployed the same way agent-service is today (own directory at repo root, own Dockerfile, sibling entry in `docker/development-easy/docker-compose.yml`, `${WT_*_PORT}` and `${*_LOCAL_IMAGE}` env-var conventions, healthcheck via curl).

**Port allocation:**

| Service | Internal | Host (default) | Env var |
|---|---|---|---|
| openemr (HTTP) | 80 | 8300 | `WT_HTTP_PORT` |
| openemr (HTTPS) | 443 | 9300 | `WT_HTTPS_PORT` |
| agent-service | 8010 | 8010 | `WT_AGENT_PORT` |
| **dashboard-dotnet** | **8080** | **8400** | **`WT_DASHBOARD_PORT`** |

**Reverse-proxy decision:** *don't* front the .NET service with Apache. Reasons: (a) agent-service is exposed directly on its own port and that pattern works; (b) Apache's `httpd.conf` lives inside the upstream image and editing it means rebuilding `Dockerfile.railway`, which is out of scope; (c) the OIDC redirect URI must be a stable host:port the auth server can reach — keeping it as `http://localhost:8400/signin-oidc` is the simplest registration. If we later want the dashboard at a path under OpenEMR (`/dashboard/`), we add an Apache `ProxyPass` in production, not in dev-easy.

**Internal hostname resolution:**

- `dashboard-dotnet` → `openemr` (FHIR server-to-server calls): use `http://openemr/apis/default/fhir/...` (Docker service-name DNS, port 80 inside).
- Browser → `openemr` (OAuth login redirects): use `http://localhost:8300/oauth2/default/...` from the user's perspective — but the OIDC issuer URL must match between browser view and container view. See §4 for the `host.docker.internal:8300` solution.

---

## 4. OAuth2 / OIDC Integration

### 4a. Production: a single public HTTPS URL

In a real deployment, OpenEMR is at some public domain (e.g. `https://emr.clinic.example`). The browser and the dashboard container both resolve and reach that same URL. The OIDC issuer URL is just OpenEMR's public URL; there is no hostname-mismatch problem and no special configuration needed beyond pointing the dashboard at the right domain.

**All OIDC URLs in the .NET app are read from environment variables.** No hardcoding. To deploy in another environment, you change env values, not code:

| Env var | Production example | Dev-easy value |
|---|---|---|
| `OPENEMR_OIDC_AUTHORITY` | `https://emr.clinic.example/oauth2/default` | `http://host.docker.internal:8300/oauth2/default` |
| `OPENEMR_PUBLIC_URL` | `https://emr.clinic.example` | `http://localhost:8300` |
| `OPENEMR_FHIR_BASE_URL` | `https://emr.clinic.example/apis/default/fhir/` (or an internal hostname if co-deployed) | `http://openemr/apis/default/fhir/` (Docker service-name DNS) |
| `DASHBOARD_OIDC_REDIRECT_URI` | `https://dashboard.clinic.example/signin-oidc` (or `https://emr.clinic.example/dashboard/signin-oidc` if reverse-proxied) | `http://localhost:8400/signin-oidc` |
| `DASHBOARD_OIDC_CLIENT_ID` / `_SECRET` | from prod's OAuth client registration | from dev's OAuth client registration |

The OAuth client must be registered at OpenEMR with the redirect URI for *that* environment. A dev client and a prod client are separate registrations.

### 4b. Dev-easy: hostname-mismatch workaround

The dev-only complication: in `docker/development-easy/docker-compose.yml`, the browser sees OpenEMR at `localhost:8300` (host port mapping), while the dashboard container sees it at `openemr` (Docker service-name DNS). The OIDC `iss` claim issued by OpenEMR (set by `OPENEMR_SETTING_site_addr_oath`) must match the URL the .NET middleware uses — which means it must be a single string both sides can resolve.

**Workaround: `host.docker.internal:8300`.** Docker Desktop on Windows (the user's machine) and macOS resolves this hostname on both the host (so the browser reaches it) and inside containers (so dashboard-dotnet reaches it). On Linux, add a one-line entry to `/etc/hosts` and use `extra_hosts: ["host.docker.internal:host-gateway"]` on the dashboard service (the §8c compose entry already includes this).

In the dev-easy compose only, change:
```yaml
OPENEMR_SETTING_site_addr_oath: 'https://localhost:${WT_HTTPS_PORT:-9300}'
```
to:
```yaml
OPENEMR_SETTING_site_addr_oath: 'http://host.docker.internal:${WT_HTTP_PORT:-8300}'
```

**This change is dev-easy-specific. Production OpenEMR keeps `site_addr_oath` set to its real public URL.**

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

Resolved URLs in dev-easy: prepend `http://host.docker.internal:8300/oauth2/default`. In prod: prepend whatever `OPENEMR_OIDC_AUTHORITY` is set to.

**Scopes to request** (clinician/PCP, user role):
```
openid fhirUser offline_access
api:fhir
user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs
user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs
```

### 4d. ASP.NET wiring in `Program.cs`

Fully env-driven — no environment-specific URLs in code:

```csharp
builder.Services.AddAuthentication(o => {
    o.DefaultScheme = CookieAuthenticationDefaults.AuthenticationScheme;
    o.DefaultChallengeScheme = OpenIdConnectDefaults.AuthenticationScheme;
})
.AddCookie()
.AddOpenIdConnect(o => {
    o.Authority = cfg["OPENEMR_OIDC_AUTHORITY"];      // env-driven, see §4a table
    o.ClientId = cfg["DASHBOARD_OIDC_CLIENT_ID"];
    o.ClientSecret = cfg["DASHBOARD_OIDC_CLIENT_SECRET"];
    o.ResponseType = "code";
    o.UsePkce = true;
    o.SaveTokens = true;                              // tokens go into auth cookie
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

`RequireHttpsMetadata` is the only env-aware line, and it's gated on `IHostEnvironment.IsDevelopment()` — production runs with HTTPS enforcement on. Everything else is identical between environments.

**Token storage:** access + refresh tokens go into the auth cookie (`SaveTokens = true`). Cookie is HttpOnly + SameSite=Lax + Secure (in non-dev environments). Refresh handled by tiny middleware: before each FHIR call, if `expires_at < now+30s`, exchange refresh token at the token endpoint, update the cookie, then proceed.

### 4e. Client registration

Register an OAuth client per environment (dev, staging, prod). The redirect URI you register **must exactly match** what the dashboard sends — `http://localhost:8400/signin-oidc` for dev-easy, `https://dashboard.clinic.example/signin-oidc` (or wherever the dashboard lives) for prod.

**Mechanism: see §11 risk #1** — OpenEMR's admin UI form for creating API clients is known to fail silently. Mitigation is documented there. **Do not pre-emptively work around it. Try the admin UI first; fall back to the workaround only if it actually fails.**

---

## 5. FHIR Data Layer

Base URL **inside the dashboard container**: `http://openemr/apis/default/fhir` (Docker service-name DNS, port 80 inside — the same backchannel the openemr container exposes internally). The browser never calls FHIR directly.

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

**Token attachment:** a `BearerTokenHandler : DelegatingHandler` reads `HttpContext.GetTokenAsync("access_token")` and sets `Authorization: Bearer ...`:
```csharp
builder.Services.AddHttpClient<FhirClient>(c => {
    c.BaseAddress = new Uri(cfg["OPENEMR_FHIR_BASE_URL"]);  // "http://openemr/apis/default/fhir/"
    c.DefaultRequestHeaders.Accept.Add(new("application/fhir+json"));
}).AddHttpMessageHandler<BearerTokenHandler>();
```

**Loading / error UI** (rendered server-side, swapped in by htmx):
- *Loading* (initial state of the card div before htmx fires): a Bootstrap spinner.
- *Error*: red alert with HTTP status + `OperationOutcome.issue[0].diagnostics`.
- *Empty*: muted "No active allergies recorded." Absence is clinically meaningful.

Cards load concurrently — htmx fires `hx-get` on each card div as soon as the page renders. One slow card never blocks the others.

---

## 6. UI Surface

**Chosen UI library: Bootstrap 5** (CDN-loaded). Reasons: (a) OpenEMR already uses Bootstrap 4.6, so the visual style stays familiar; (b) zero build step — drop a `<link>` into `_Layout.cshtml`; (c) decent built-in accessibility.

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

**Layout**: 12-column Bootstrap grid. Header spans 12 cols and is sticky. Below: 3 columns on desktop (≥1200 px), 1 column on mobile. Each card is `<div class="card">` with a `<div class="card-header">` (title + small "Refresh" link via `hx-get` + `hx-trigger="click"`).

---

## 7. Navigation Between OpenEMR and the New Dashboard

Both directions need to work. The new ASP.NET dashboard lives at `http://localhost:8400`; OpenEMR PHP pages live at `http://localhost:8300`. Different origins, **no shared session**. That's intentional — the assignment requires *navigating* between pages, not session continuity. The user's existing OpenEMR cookie (set when they logged into the legacy UI) is still valid for `localhost:8300`, so PHP pages always recognize them.

### 7a. Forward — PHP → ASP.NET (entering the new dashboard)

A clinician picks a patient in OpenEMR's existing patient finder, then clicks "Modern Dashboard" in the patient menu. They land on the new dashboard at `http://localhost:8400/Patient/{pid}` with that patient pre-selected.

**Mechanism:** add one entry to the site-local patient menu JSON (`sites/default/documents/custom_menus/patient_menus/Custom.json`, which shadows the upstream `interface/main/tabs/menu/menus/patient_menus/standard.json` without modifying upstream-tracked files). Dev-easy value:

```json
{ "label": "Modern Dashboard{{patient file}}", "menu_id": "modern_dashboard",
  "target": "main", "on_click": "top.restoreSession()",
  "url": "http://localhost:8400/Patient/{{pid}}", "pid": "true",
  "children": [], "requirement": 0 }
```

The `{{pid}}` token is OpenEMR's existing template-expansion mechanism (other entries in that file use `"pid": "true"`). At click time, OpenEMR substitutes the active patient's local pid into the URL.

**Per-environment URL:** the dashboard URL in this entry is per-deployment. In dev-easy it's `http://localhost:8400/...`; in prod it's whatever public URL the dashboard is reachable at (e.g. `https://dashboard.clinic.example/Patient/{{pid}}`, or `/dashboard/Patient/{{pid}}` if reverse-proxied under the OpenEMR origin). Site admins edit `Custom.json` for their environment; this is the standard OpenEMR pattern for site-specific menu customization.

**Resolving FHIR uuid from the local pid:** the menu hand-off arrives at `/Patient/{pid}` with OpenEMR's numeric `pid` (because `"pid": "true"` expands to local pid, not FHIR uuid). The .NET app then issues `GET /Patient?identifier=PT|{pid}` to translate to a FHIR uuid for subsequent calls. The `identifier` search param on `Patient` is supported (`src/Services/FHIR/FhirPatientService.php`).

**Possible iframe issue:** `target=main` opens the link inside OpenEMR's top-frame iframe. This may interact poorly with the OIDC redirect chain (see §11 #2 — handle only if it actually breaks).

### 7b. Back — ASP.NET → PHP (returning to legacy pages)

Each card row that has a corresponding OpenEMR detail page renders an "Open in OpenEMR" link. URLs are built from the env var `OPENEMR_PUBLIC_URL` (see §4a env table) — never hardcoded. In Razor:

```cshtml
@inject IConfiguration Cfg
@{ var oe = Cfg["OPENEMR_PUBLIC_URL"]; }   @* dev: http://localhost:8300, prod: https://emr.clinic.example *@
<a href="@oe/interface/patient_file/summary/demographics.php?set_pid=@Model.Pid">Open in OpenEMR</a>
```

Per-row examples:
- Patient header → `{OPENEMR_PUBLIC_URL}/interface/patient_file/summary/demographics.php?set_pid={pid}`
- Problem List row → `{OPENEMR_PUBLIC_URL}/interface/patient_file/summary/add_edit_issue.php?issue=...&id={issueId}`
- Persistent "← Back to OpenEMR" button in header → `{OPENEMR_PUBLIC_URL}/interface/main/tabs/main.php?set_pid={pid}`

`set_pid` is OpenEMR's canonical mechanism for switching the active patient via URL (verified in `interface/billing/sl_eob_search.php`, `interface/new/new_patient_save.php`, `interface/patient_tracker/patient_tracker.php`). The user's existing cookie on the OpenEMR public origin makes the PHP page authenticate them automatically.

---

## 8. Build & Dev Workflow

### 8a. Project layout — mirror agent-service

`dashboard-dotnet/` at repo root, parallel to `agent-service/`:

```
dashboard-dotnet/
├── Dockerfile                          # multi-stage; structure mirrors agent-service/Dockerfile
├── .dockerignore
├── .gitignore
├── OpenEmr.Dashboard.sln
├── src/OpenEmr.Dashboard/
│   ├── OpenEmr.Dashboard.csproj        # net9.0
│   ├── Program.cs
│   ├── appsettings.json
│   ├── appsettings.Development.json    # gitignored — holds OIDC client id/secret
│   ├── Pages/
│   │   ├── _ViewImports.cshtml
│   │   ├── _ViewStart.cshtml
│   │   ├── Shared/
│   │   │   ├── _Layout.cshtml          # Bootstrap 5 + htmx via CDN
│   │   │   └── _PatientHeader.cshtml
│   │   ├── Index.cshtml(.cs)           # auth landing
│   │   └── Patient/
│   │       ├── Index.cshtml(.cs)       # main dashboard page (route: /Patient/{pid})
│   │       └── Cards/
│   │           ├── Allergies.cshtml(.cs)        # htmx fragment endpoint
│   │           ├── Problems.cshtml(.cs)
│   │           ├── Medications.cshtml(.cs)
│   │           ├── Prescriptions.cshtml(.cs)
│   │           ├── CareTeam.cshtml(.cs)
│   │           └── Encounters.cshtml(.cs)
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

### 8b. Dockerfile — mirror agent-service style

Multi-stage, ~/dotnet/sdk:9.0 → ~/dotnet/aspnet:9.0, unprivileged user, healthcheck via curl. Same general shape as `agent-service/Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1.7

# OpenEMR Modern Patient Dashboard (.NET sibling)
#
# Builds the ASP.NET Core 9 Razor Pages app defined in src/OpenEmr.Dashboard
# and runs it under Kestrel on port 8080. Image is intentionally slim:
# only the ASP.NET runtime plus the published app.

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

# curl is used by the HEALTHCHECK below.
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

### 8c. Compose service — mirror agent-service entry

Add to `docker/development-easy/docker-compose.yml` (the entry below mirrors the agent-service block at lines 226–282 of that file):

```yaml
  # Modern Patient Dashboard sibling — ASP.NET Core 9 Razor Pages app in
  # ../../dashboard-dotnet. Browser reaches it at http://localhost:8400;
  # internal FHIR calls go to http://openemr (Docker service-name DNS).
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
      # Dev-easy values. In production these would point at the real public
      # domain (e.g. https://emr.clinic.example/oauth2/default). The .NET
      # app reads all of these from env — no environment-specific code.
      OPENEMR_OIDC_AUTHORITY: 'http://host.docker.internal:${WT_HTTP_PORT:-8300}/oauth2/default'
      OPENEMR_PUBLIC_URL: 'http://localhost:${WT_HTTP_PORT:-8300}'  # for back-nav links (§7b)
      OPENEMR_FHIR_BASE_URL: 'http://openemr/apis/default/fhir/'   # internal Docker DNS
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
      test:
      - CMD
      - curl
      - --fail
      - --silent
      - --show-error
      - http://127.0.0.1:8080/healthz
      start_period: 20s
      interval: 30s
      timeout: 5s
      retries: 3
```

Plus one **change** to the existing `openemr` service env block: flip `OPENEMR_SETTING_site_addr_oath` from `https://localhost:9300` to `http://host.docker.internal:8300` so the OIDC issuer URL matches what both browser and dashboard-dotnet will use (see §4).

### 8d. Dev iteration

Two modes, pick what fits the moment:

1. **Container rebuild loop** (mirrors how agent-service is iterated today): edit code → `docker compose build dashboard-dotnet && docker compose up -d dashboard-dotnet`. Slower (~10–20s rebuild) but represents prod exactly.
2. **Host iteration loop, faster** (optional): stop the container, run `dotnet watch run` from `dashboard-dotnet/src/OpenEmr.Dashboard/` on the host. Listens on `http://localhost:8400` directly. Requires reading the same env vars from `appsettings.Development.json` instead of compose. Use this when iterating on UI; switch back to the container before declaring done.

The deliverable is the **container**, exactly like agent-service. Mode 2 is just a developer convenience.

### 8e. Scaffold sequence (do not run yet)

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
# Then author Dockerfile + .dockerignore + .gitignore
```

---

## 9. Testing Plan (minimum viable)

| Test | What it proves | Library |
|---|---|---|
| `PatientMappingTests.MrnExtractedFromPtIdentifier` | The `code=="PT"` selector matches OpenEMR's actual emission | xUnit |
| `PatientMappingTests.NameUseOfficialPreferred` | Multiple `name[]` entries → official wins | xUnit |
| `ConditionMappingTests.ParsesProblemListItem` | Sample US-Core fixture parses without exception | xUnit |
| `OidcCallbackTests.UnauthenticatedHomeRedirectsToAuthorize` | `WebApplicationFactory` boot, GET `/`, assert 302 → `/oauth2/default/authorize?response_type=code&...` | xUnit + Mvc.Testing |
| `PatientPageTests.RendersAllSevenCardSlotsWithMockedFhir` | Inject fake `FhirClient`, GET `/Patient/1`, parse HTML with AngleSharp, assert 7 card divs with the expected `hx-get` URLs | xUnit + Mvc.Testing + AngleSharp |

Five tests, ~200 lines total, runnable as `dotnet test`. No Playwright — HTML-string assertions via AngleSharp are enough. CI hook: drop a `.github/workflows/dotnet-test.yml` (or extend existing CI) that runs `dotnet test` on PR — separate pipeline from PHPStan/PHPUnit.

---

## 10. PATIENT_DASHBOARD_MIGRATION.md Outline

Sections to fill in once implementation is done:
1. **Why migrate at all** — what's wrong/limited with PHP-rendered demographics.php (mixed jQuery/Smarty/Twig, full-page reloads, runtime-only validation of array keys).
2. **Why .NET specifically** — typed DTOs across the wire, off-the-shelf OIDC middleware, no `npm install`, single C# stack.
3. **Why Razor Pages + htmx** — section-by-section justification (this whole §2 of the plan, condensed).
4. **What we kept untouched** — OAuth2 server, FHIR controllers, Apache vhost, MariaDB schema. The only PHP-side changes are: one menu JSON entry, and one env var flip in dev-easy compose (`site_addr_oath`).
5. **What was gained** — compile-time field-name validation on FHIR DTOs; concurrent card loading via htmx without async JS; Hot Reload during dev; smaller per-page footprint.
6. **What was lost / deferred** — exact OpenEMR Bootstrap palette match, OpenEMR session SSO, in-browser editing, Smarty event hooks.
7. **OIDC integration recipe** — exact endpoint URLs, exact scope list, the `host.docker.internal` rationale, the client-registration recipe (whether the admin UI worked or we needed §11 #1's workaround).
8. **How to extend** — adding a new card, adding a new FHIR resource, adding a new page.
9. **What we'd do differently next time** — proxy through Apache for production, etc.

---

## 11. Risks & Open Questions

### Active risks (do not address until they bite us)

1. **Admin UI for creating an OAuth client may fail silently** (assignment-acknowledged setup caveat).
   - **Do not pre-emptively work around this.** Try the OpenEMR admin UI first (Admin → System → API Clients). Only if it actually fails — silently or with errors — fall back to the workaround below.
   - **Workaround if needed:** in the admin DevTools console, issue:
     ```js
     fetch('http://host.docker.internal:8300/oauth2/default/registration', {
       method: 'POST',
       headers: {'Content-Type': 'application/json'},
       body: JSON.stringify({
         client_name: 'Dashboard .NET',
         redirect_uris: ['http://localhost:8400/signin-oidc'],
         post_logout_redirect_uris: ['http://localhost:8400/signout-callback-oidc'],
         scope: 'openid fhirUser offline_access api:fhir ' +
                'user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs ' +
                'user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs'
       })
     }).then(r=>r.json()).then(console.log);
     ```
     Then either via admin UI or directly in `oauth_clients` DB row, mark `is_enabled=1` and `is_trusted=1`. Document whichever path actually worked in `PATIENT_DASHBOARD_MIGRATION.md`.

2. **The OpenEMR menu may open the new dashboard inside an iframe** (`target=main` in the menu JSON loads links into the existing OpenEMR top-frame iframe). If the OIDC redirect chain misbehaves inside an iframe (some browsers block third-party cookies in frames; the OAuth provider may set `X-Frame-Options` that prevents framing), the auth flow breaks.
   - **Do not pre-emptively work around this.** Build the menu link with `target=main` first. Test the OIDC round-trip *through the menu link*, not just by visiting `localhost:8400` directly. If it works, leave it.
   - **Mitigation if it actually fails:** change `target` to `_blank` (new tab) or use `top.location.href = ...` to break out of the frame.

### Risks we will handle as part of normal implementation

| Risk | Mitigation |
|---|---|
| **OpenEMR may refuse HTTP for OAuth endpoints** | Step 1 of §12 verifies the discovery doc loads over HTTP after the `site_addr_oath` flip. If OpenEMR forces HTTPS on OAuth endpoints, add `OPENEMR_SETTING_oauth_force_https: 0` (or equivalent) to compose; if no such setting exists, fall back to HTTPS with `host.docker.internal:9300` and the .NET `BackchannelHttpHandler` configured to accept the self-signed cert *in dev only*. |
| **CORS** if anyone tries to call FHIR from the browser | Server-side `HttpClient` only; never call `/apis/default/fhir/*` from JavaScript. (Designed in.) |
| **Refresh token rotation** — OpenEMR may rotate refresh tokens on each use | Replace stored refresh token in cookie on every refresh response; if 400 returned, force re-login. Implemented in `RefreshTokenMiddleware`. |
| **MRN field source** — is `pubpid` (`code="PT"`) really what the user calls "MRN"? | Confirmed in `src/Services/FHIR/FhirPatientService.php`. Display label as "MRN" with hover tooltip "OpenEMR public identifier (pubpid)". |
| **Active vs deceased status** — FHIR `Patient.active` may not collapse correctly with deceased | Display three independent badges: Active / Inactive / Deceased. Don't collapse. |
| **OpenEMR menu JSON is per-site** — `sites/default/documents/custom_menus/...` may shadow upstream | Edit the site-local custom JSON to avoid touching upstream-tracked files. |
| **`host.docker.internal` not auto-resolved on Linux Docker hosts** | The compose entry in §8c already includes `extra_hosts: ["host.docker.internal:host-gateway"]` for the dashboard service. Linux users also need a one-line entry in `/etc/hosts` for the host's browser. Document in migration doc. |
| **PHPStan / PHP-CS-Fixer / PHPUnit don't apply to .NET** | Separate `dotnet test` and `dotnet format` pipeline. Document in repo `CLAUDE.md`. |

**Open questions** (resolve during implementation):
- Does the clinician need access to all patients in the practice or only their own? (Affects whether to filter `Patient` search by `general-practitioner`.)

---

## 12. Step-by-Step Task List (sized 30–90 min, **CRITICAL PATH starred**)

### Day 1 — OAuth client + container scaffold
1. ★ Verify dev-easy stack is up (`docker compose up --detach --wait` from `docker/development-easy/`); admin login works at `http://localhost:8300`. *(30 min)*
2. ★ **Dev-easy-only compose tweak** (production OpenEMR keeps its real public URL): flip `OPENEMR_SETTING_site_addr_oath` in `docker/development-easy/docker-compose.yml` from `https://localhost:9300` to `http://host.docker.internal:8300` so the OIDC issuer URL is reachable identically from both browser and dashboard container. Restart openemr. Confirm `http://host.docker.internal:8300/oauth2/default/.well-known/openid-configuration` returns JSON. *(30 min)*
3. ★ Try the OpenEMR admin UI to register an OAuth client (Admin → System → API Clients) with redirect URI `http://localhost:8400/signin-oidc` and the scopes from §4. If it works, copy client id/secret. If it silently fails, fall back to the §11 #1 workaround. *(45 min)*
4. ★ `dotnet new` scaffold per §8e (Razor Pages webapp + xUnit + AngleSharp + OIDC middleware). Author `Dockerfile`, `.dockerignore`, `.gitignore`. Commit baseline. *(60 min)*
5. ★ Author the `dashboard-dotnet` compose service block per §8c. `docker compose up --build -d dashboard-dotnet`; verify `curl http://localhost:8400/healthz` returns 200. *(45 min)*

### Day 2 — Auth round-trip + Patient header
6. ★ Wire OIDC handler in `Program.cs` per §4. Read OIDC config from env (`OPENEMR_OIDC_AUTHORITY`, `DASHBOARD_OIDC_CLIENT_ID`, etc.). Add `[Authorize]` to default Razor Pages convention. Rebuild container. *(75 min)*
7. ★ Visit `http://localhost:8400`; OIDC challenge → OpenEMR login → callback → land on `/`. **Checkpoint: Auth round-trip works through the container.** *(60 min)*
8. ★ Implement `BearerTokenHandler`, `FhirClient`, `FhirPatient` record. `Pages/Patient/Index.cshtml` accepts `{pid}` route param, loads patient by `?identifier=PT|{pid}`. *(75 min)*
9. ★ Render the patient header partial (name, DOB+age, gender, MRN, Active/Inactive/Deceased badges). Bootstrap 5 styling. *(60 min)*
10. Wire htmx into `_Layout.cshtml` (single `<script>` from CDN). Add the dashboard grid with seven card slots, each with `hx-get` + `hx-trigger="load"`. *(45 min)*

### Day 3 — Cards
11. Add `Pages/Patient/Cards/Allergies.cshtml` partial endpoint + `FhirAllergy` record + unit test. *(60 min)*
12. Add `Problems.cshtml` partial + `FhirCondition` record + unit test. *(60 min)*
13. Add `Medications.cshtml` + `Prescriptions.cshtml` partials (sharing `FhirMedicationRequest`) + unit test. *(75 min)*
14. Add `CareTeam.cshtml` partial + `FhirCareTeam` record + unit test. *(45 min)*
15. Add `Encounters.cshtml` partial (the +1 section) + `FhirEncounter` record + unit test. *(60 min)*

### Day 4 — Forward + back navigation, refresh tokens, tests
16. ★ Add OpenEMR menu entry pointing at `http://localhost:8400/Patient/{{pid}}` (forward nav, §7a). Verify clinician picks a patient → lands on new dashboard with that patient pre-selected. *(45 min)*
17. Add "Open in OpenEMR" links on each card row and a "← Back to OpenEMR" button in the header (§7b). Verify each lands correctly with `set_pid`. *(45 min)*
18. Implement `RefreshTokenMiddleware`. Verify a 60-min idle refresh works. *(60 min)*
19. Write `OidcCallbackTests` and `PatientPageTests` per §9. *(90 min)*

### Day 5 — Docs, smoke, polish
20. ★ Write `PATIENT_DASHBOARD_MIGRATION.md` from §10 outline. *(75 min)*
21. ★ End-to-end smoke run: `docker compose down -v && docker compose up --detach --wait`, log in, navigate to a patient, click "Modern Dashboard", verify all 7 sections render with real data. *(45 min)*
22. Accessibility pass (keyboard nav, focus rings, ARIA labels on badges). *(60 min)*
23. Empty-state and error-state polish (Bootstrap alerts with FHIR `OperationOutcome.diagnostics`). *(60 min)*
24. Final review of `PATIENT_DASHBOARD_MIGRATION.md`; final `dotnet test` + `dotnet format` clean. *(45 min)*

**Critical-path summary:** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 16 → 20 → 21. If anything else slips, the dashboard still demos.

---

### Critical Files for Implementation
- `agent-service/Dockerfile` — read-only reference; the new `dashboard-dotnet/Dockerfile` mirrors its layering, unprivileged-user pattern, and HEALTHCHECK style
- `docker/development-easy/docker-compose.yml` — add the `dashboard-dotnet` sibling block (§8c) and flip `OPENEMR_SETTING_site_addr_oath` (§4)
- `src/RestControllers/Authorization/OAuth2DiscoveryController.php` — authoritative source for OIDC endpoint URLs
- `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php` — FHIR route map confirming the seven resource shapes
- `src/Services/FHIR/FhirPatientService.php` — MRN/`pubpid` → identifier `code=="PT"` mapping; the `identifier` search param
- `interface/main/tabs/menu/menus/patient_menus/standard.json` *or* `sites/default/documents/custom_menus/patient_menus/Custom.json` — where to add the "Modern Dashboard" menu entry (forward nav, §7a)
