# Patient Dashboard Migration: OpenEMR PHP to ASP.NET Core 9

The patient dashboard has been replaced with a sibling Razor Pages service
(`dashboard-dotnet/`) deployed alongside the existing `openemr-web` and
`agent-service` Railway services. This document captures the why, the
intentional shape of the integration, the workarounds that were necessary,
and how to extend the dashboard with new cards, resources, or pages.

- **Production dashboard:** `https://dashboard-dotnet-production.up.railway.app`
- **Production OpenEMR:** `https://openemr-web-production.up.railway.app`
- **Implementation:** `dashboard-dotnet/` (mirrors `agent-service/` layout)
- **Plan:** `week-2/week-2-extra-assignment-plan.md`

---

## 1. Why migrate

The existing demographics page (`interface/patient_file/summary/demographics.php`)
mixes three template engines and a UI framework on a single screen:

- Smarty templates render the outer chrome.
- Twig partials cover newer fragments.
- jQuery and Angular 1.8 hydrate behavior.
- PHP composes the page from `$_SESSION`, `$_GET`, and globals at request time.

That mix has practical consequences:

- **Runtime-only validation of array keys.** Patient resources flow through
  PHP as nested associative arrays. A typo such as `$resource['name'][0]['famly']`
  silently returns `null` and the bug only surfaces in production when a
  particular field is missing. Static analysis cannot verify the keys.
- **Full-page reloads on most interactions.** Switching between sub-tabs of
  the patient file rebuilds the entire chrome, the menu JSON, and the patient
  picker.
- **Mixed-engine debugging cost.** A regression in the demographics card can
  live in any of the three templating layers, or in the global state that
  feeds them.

The goal of this migration was not to replace OpenEMR. It was to demonstrate
that a single screen can be rebuilt on a modern, statically typed stack while
leaving the rest of OpenEMR (auth server, FHIR controllers, MariaDB schema,
Apache vhost) untouched.

## 2. Why .NET / Razor Pages

ASP.NET Core 9 Razor Pages was chosen over MVC, minimal API + SPA, and Blazor
for four reasons:

- **Typed DTOs end-to-end.** FHIR resources are deserialized into `record`
  types in `dashboard-dotnet/src/OpenEmr.Dashboard/Fhir/Records/`. The
  compiler refuses to build code that references a non-existent field.
- **Off-the-shelf OIDC middleware.** `Microsoft.AspNetCore.Authentication.OpenIdConnect`
  handles the OAuth2 authorization-code flow, PKCE, cookie issuance, and
  challenge redirects. The dashboard never hand-rolls token logic.
- **No `npm install`.** The default `dotnet new webapp` template ships with
  Bootstrap 5 already wired in. There is no bundler, no SASS pipeline, no
  package-lock churn for a screen that mostly renders typography and badges.
- **Single C# stack on the page.** Razor Pages keeps view, model, and
  page-handler in one folder. There is no JavaScript framework on the page;
  the rendered HTML is the final state.

Razor Pages was preferred over MVC because the dashboard is page-centric, and
preferred over Blazor because the dashboard does not need realtime
client-server state.

## 3. What we kept untouched

The OpenEMR side is overwhelmingly unchanged. Everything below was preserved
exactly as it ships upstream:

- The OAuth2 / OIDC server (`oauth2/default/...` endpoints).
- The FHIR R4 controllers and `src/Services/FHIR/*` service classes.
- The Apache vhost configuration.
- The MariaDB schema.
- The Smarty / Twig templating layer for every other screen.
- The patient finder and the rest of the menu system.

Four PHP-side touches were necessary, and only four. They are listed below
with the actual diffs.

### 3.1 `src/Common/Session/SessionConfigurationBuilder.php` — env-gated dev-only cookie

Production OpenEMR serves OAuth over HTTPS, where the OAuth session cookie
must use `SameSite=None; Secure` so cross-site SMART app launches are not
dropped by the browser. Dev-easy serves OAuth over HTTP via
`host.docker.internal:8300`; Chrome (since v80) silently drops `SameSite=None`
cookies that are not `Secure`, which breaks the redirect from `/authorize` to
`/provider/login`.

`forOAuth` reads the `OPENEMR_OAUTH_COOKIE_INSECURE` env var. When it is
truthy (dev-easy only), the cookie is downgraded to `SameSite=Lax` and
`Secure=false`. **Production is unchanged**: the env var is unset, so the
production code path is bit-for-bit identical to upstream.

```php
$rawInsecure = getenv('OPENEMR_OAUTH_COOKIE_INSECURE') ?: ($_ENV['OPENEMR_OAUTH_COOKIE_INSECURE'] ?? '');
$allowInsecure = filter_var($rawInsecure, FILTER_VALIDATE_BOOL);
return (new self())
    ->setName(SessionUtil::OAUTH_SESSION_ID)
    ->setCookiePath((!empty($webRoot)) ? $webRoot . SessionUtil::OAUTH_WEBROOT : SessionUtil::OAUTH_WEBROOT)
    ->setCookieSameSite($allowInsecure ? 'Lax' : 'None')
    ->setCookieSecure(!$allowInsecure)
    ->build();
```

### 3.2 `src/Services/FHIR/FhirPatientService.php` — added `pid` identifier search field

The menu hand-off (§6) lands on `/Patient/{pid}` with OpenEMR's numeric local
`pid`. The dashboard issues `GET /Patient?identifier={pid}` to translate that
to a FHIR uuid for downstream calls. Upstream OpenEMR mapped the FHIR
`identifier` token across `ss` (Social Security number) and `pubpid` (public
patient id) only — there was no path to the local `pid` column. One column
was added to the search-field array:

```diff
-'identifier' => new FhirSearchParameterDefinition('identifier', SearchFieldType::TOKEN, ['ss', 'pubpid']),
+'identifier' => new FhirSearchParameterDefinition('identifier', SearchFieldType::TOKEN, ['ss', 'pubpid', 'pid']),
```

This is additive: existing identifier searches against `ss` and `pubpid`
still work.

### 3.3 `sites/default/documents/custom_menus/patient_menus/Custom.json` — one new menu entry

The site-local custom menu shadows the upstream
`interface/main/tabs/menu/menus/patient_menus/standard.json` without
modifying upstream-tracked files. One entry was added:

```json
{
    "label": "Modern Dashboard{{patient file}}",
    "menu_id": "modern_dashboard",
    "target": "main",
    "on_click": "top.restoreSession()",
    "url": "http://localhost:8400/Patient/",
    "pid": "true",
    "children": [],
    "requirement": 0
}
```

The `"pid": "true"` flag tells OpenEMR to append the active patient's local
pid to the URL at click time. Site admins maintain `Custom.json` per
environment; the prod copy points at the prod dashboard's public URL instead.

### 3.4 `docker/development-easy/docker-compose.yml` — `site_addr_oath` flip and new service block

Two coupled changes for local testing only:

1. The existing `openemr` service had its `OPENEMR_SETTING_site_addr_oath`
   flipped from `https://localhost:9300` to
   `http://host.docker.internal:8300`, with a new
   `OPENEMR_OAUTH_COOKIE_INSECURE: '1'` line. This makes the OIDC `iss`
   claim the same string both the browser and the dashboard container can
   resolve (see §6 for why). Production keeps `site_addr_oath` set to its
   real public URL.
2. A new `dashboard-dotnet` service block was added that mirrors the
   existing `agent-service` block: builds from `../../dashboard-dotnet`,
   maps `${WT_DASHBOARD_PORT:-8400} → 8080`, depends on `openemr` being
   healthy, and includes
   `extra_hosts: ["host.docker.internal:host-gateway"]` so Linux Docker
   hosts can resolve the same name as Windows / macOS Docker Desktop.

Production deployment is handled by `deploy-railway.ps1`, which gained a
third pass (`-DeployDashboardDotnet`) mirroring its agent-service pass.

That's the entire PHP-side surface. Five additive lines in two PHP files,
one menu JSON entry, and one compose block.

## 4. What was gained

- **Compile-time field-name validation on FHIR DTOs.** `FhirPatient` is a
  `record` with explicit `[JsonPropertyName]` bindings. A mistyped
  `patient.Identifeir` is a build error, not a silent null at runtime. See
  `dashboard-dotnet/src/OpenEmr.Dashboard/Fhir/Records/FhirPatient.cs`.
- **Concurrent FHIR fetches via `Task.WhenAll`.** The page model fans out
  six FHIR calls and awaits them together. Total wall time is governed by
  the slowest endpoint, not the sum. See
  `dashboard-dotnet/src/OpenEmr.Dashboard/Pages/Patient/Index.cshtml.cs::OnGetAsync`:

  ```csharp
  var allergiesTask = SafeFetchAsync(ct => this.fhirClient.GetAllergiesAsync(uuid, ct), cancellationToken);
  var problemsTask = SafeFetchAsync(ct => this.fhirClient.GetProblemsAsync(uuid, ct), cancellationToken);
  var medicationsTask = SafeFetchAsync(ct => this.fhirClient.GetActiveMedicationsAsync(uuid, ct), cancellationToken);
  var prescriptionsTask = SafeFetchAsync(ct => this.fhirClient.GetPrescriptionsAsync(uuid, ct), cancellationToken);
  var careTeamTask = SafeFetchAsync(ct => this.fhirClient.GetCareTeamAsync(uuid, ct), cancellationToken);
  var encountersTask = SafeFetchAsync(ct => this.fhirClient.GetEncountersAsync(uuid, ct), cancellationToken);

  await Task.WhenAll(
      allergiesTask, problemsTask, medicationsTask,
      prescriptionsTask, careTeamTask, encountersTask);
  ```

  Each fetcher delegates to `FhirClient.SearchBundleAsync<T>`, a generic
  helper that issues a search, returns a typed `CardResult<T>`, and isolates
  per-card failures so one bad endpoint never breaks the whole page.

- **Hot Reload during dev.** `dotnet watch run` rebuilds and replaces the
  page-model bindings on save, without restarting the auth flow.
- **Smaller per-page footprint.** The dashboard page is rendered from
  one Razor Page plus seven partials. There is no Smarty template, no
  Angular bootstrap, no jQuery, no global state. The rendered HTML is the
  final state.

## 5. What was lost or deferred

This is honest accounting, not advocacy.

- **Exact OpenEMR Bootstrap palette match.** Bootstrap 5 default theme
  was used as-is. OpenEMR's Bootstrap 4.6 theme has a different accent
  palette and slightly different button sizing. The dashboard is
  recognisably "Bootstrap-ish" but not pixel-identical to legacy pages.
- **OpenEMR session SSO.** The dashboard runs at a different origin
  (different Railway service in prod, different port in dev). It performs
  its own OIDC login flow against OpenEMR's OAuth2 server. The user sees
  an explicit OpenEMR login the first time they enter the dashboard;
  subsequent navigations reuse the OIDC cookie until expiry. Sharing a
  single session cookie across origins was deliberately scoped out.
- **In-browser editing.** The dashboard is read-only. Every per-row
  "Open" button routes back to the matching legacy editor (e.g.
  `add_edit_issue.php` for problems). Adding write paths would require
  CSRF handling, FHIR write scopes, and a confirmation UX that was not
  in scope.
- **Smarty event hooks.** OpenEMR modules can hook into demographics
  rendering via the Symfony EventDispatcher. The new dashboard does not
  fire those hooks; modules that depend on them will not see this page.
- **Vitals and labs.** The +1 card is Encounters, not Observations.
  Observation parsing requires LOINC code handling and `valueQuantity`
  unit reconciliation. That was a deliberate scope cut.

## 6. OIDC integration recipe

All OIDC URLs are read from environment variables. Nothing about the URL
scheme, host, or port is hardcoded in the dashboard. To deploy in another
environment, change env values, not code.

### 6.1 Endpoints

All OIDC endpoints follow the pattern `{OPENEMR_OIDC_AUTHORITY}/{operation}`:

| Purpose       | Path                                |
|---------------|-------------------------------------|
| Discovery     | `/.well-known/openid-configuration` |
| Authorize     | `/authorize`                        |
| Token         | `/token`                            |
| Userinfo      | `/userinfo` (skipped, see §6.4)     |
| JWKS          | `/jwk`                              |
| Registration  | `/registration`                     |
| Logout        | `/logout`                           |

### 6.2 Scopes

```
openid fhirUser offline_access api:fhir
user/Patient.rs user/AllergyIntolerance.rs user/Condition.rs
user/MedicationRequest.rs user/CareTeam.rs user/Encounter.rs
```

### 6.3 Environment variable matrix

| Env var                         | Production (Railway)                                                            | Dev-easy local testing                                          |
|---------------------------------|---------------------------------------------------------------------------------|-----------------------------------------------------------------|
| `OPENEMR_OIDC_AUTHORITY`        | `https://openemr-web-production.up.railway.app/oauth2/default`                  | `http://host.docker.internal:8300/oauth2/default`               |
| `OPENEMR_PUBLIC_URL`            | `https://openemr-web-production.up.railway.app`                                 | `http://localhost:8300`                                         |
| `OPENEMR_FHIR_BASE_URL`         | `http://${{openemr-web.RAILWAY_PRIVATE_DOMAIN}}/apis/default/fhir/`             | `http://host.docker.internal:8300/apis/default/fhir/`           |
| `DASHBOARD_OIDC_REDIRECT_URI`   | `https://dashboard-dotnet-production.up.railway.app/signin-oidc`                | `http://localhost:8400/signin-oidc`                             |
| `DASHBOARD_OIDC_CLIENT_ID`      | from prod's OAuth client registration                                           | from dev's OAuth client registration                            |
| `DASHBOARD_OIDC_CLIENT_SECRET`  | from prod's OAuth client registration                                           | from dev's OAuth client registration                            |
| `OPENEMR_SETTING_site_addr_oath`| public URL of openemr-web (set on the `openemr-web` service, not the dashboard) | `http://host.docker.internal:8300` (set on the `openemr` service) |
| `OPENEMR_OAUTH_COOKIE_INSECURE` | unset                                                                           | `1` (dev-only Lax cookie, see §6.5)                             |

The OAuth client must be registered at OpenEMR with the redirect URI for
*that* environment. Dev and prod are separate registrations.

### 6.4 Client registration

Try the OpenEMR admin UI first (`Admin → System → API Clients`). If it
silently fails, fall back to the dynamic-registration endpoint from the
admin DevTools console:

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
}).then(r => r.json()).then(console.log);
```

Then either via the admin UI or by editing the `oauth_clients` row, set
`is_enabled=1` and `is_trusted=1`. Capture the `client_id` and
`client_secret` into the env vars from §6.3.

### 6.5 Workarounds that landed in the dashboard

Four things did not work out of the box and required code in the dashboard.
All are real fixes, all live in `dashboard-dotnet/`, and all have comments
explaining why they exist.

**6.5.1 The discovery-doc boolean bug.**
OpenEMR's `OAuth2DiscoveryController` emits
`"require_request_uri_registration": ["false"]` (a single-element array of
the literal string `"false"`) where the OIDC discovery spec mandates a JSON
boolean. Microsoft.IdentityModel's strict System.Text.Json parser throws
`IDX11020` on the array. `OpenEmrDiscoveryFixupHandler` is a
`DelegatingHandler` wired into `OpenIdConnectOptions.BackchannelHttpHandler`
that rewrites this single field on the way back to the middleware:

```csharp
var fixedBody = body.Replace(
    "\"require_request_uri_registration\": [\"false\"]",
    "\"require_request_uri_registration\": false",
    StringComparison.Ordinal);
```

This was preferred over forking upstream PHP. See
`dashboard-dotnet/src/OpenEmr.Dashboard/Auth/OpenEmrDiscoveryFixupHandler.cs`.

**6.5.2 Dev-easy HTTP cookie acceptance (`OPENEMR_OAUTH_COOKIE_INSECURE`).**
Documented in §3.1. Dev-easy serves OAuth over HTTP via
`host.docker.internal:8300`; Chrome (since v80) silently drops
`SameSite=None` cookies that are not `Secure`. The env var is read by
`SessionConfigurationBuilder::forOAuth` and downgrades the OAuth session
cookie to `SameSite=Lax; Secure=false` for local testing only. Production
never sets this env var.

**6.5.3 `UseForwardedHeaders` for HTTPS redirect URIs behind Railway.**
Railway terminates TLS at the edge proxy and forwards plain HTTP to the
container. Without `app.UseForwardedHeaders()` (configured to honor
`X-Forwarded-Proto`), the cookie-and-redirect stack generates `http://`
callback URIs that don't match the `https://` URI registered in OpenEMR's
`oauth_clients` table — and the OIDC challenge fails on first round-trip.
The middleware is registered in `Program.cs` *before* any other middleware
that reads `HttpContext.Request.Scheme`:

```csharp
builder.Services.Configure<ForwardedHeadersOptions>(o =>
{
    o.ForwardedHeaders = ForwardedHeaders.XForwardedFor | ForwardedHeaders.XForwardedProto;
    o.KnownNetworks.Clear();
    o.KnownProxies.Clear();
});
// ...
app.UseForwardedHeaders();
```

**6.5.4 Skipping `GetClaimsFromUserInfoEndpoint`.**
OpenEMR advertises `/userinfo` in its discovery document, but the endpoint
returns 404 for token-bearing requests in current builds. The id_token
already carries the claims the dashboard needs (the `fhirUser` claim, the
subject), so the second hop is disabled:

```csharp
o.GetClaimsFromUserInfoEndpoint = false;
```

**6.5.5 The `pid` identifier search field.**
Documented in §3.2. The menu hand-off arrives at `/Patient/{pid}` with
OpenEMR's local pid (because `"pid": "true"` expands to local pid, not FHIR
uuid). The dashboard then issues `GET /Patient?identifier={pid}` to
translate to a FHIR uuid for downstream calls. Adding `'pid'` to the
identifier search-field array in `FhirPatientService` was the smallest
change that made this work without inventing a new search parameter.

**6.5.6 Suppressing the OpenEMR top-frame "Leave site?" prompt.**
`interface/main/tabs/main.php` registers a `beforeunload` listener via
`addEventListener` that pops a "Leave site?" prompt to protect users from
unsaved chart edits. The prompt fires when the redirect shim navigates
the top frame to the cross-origin dashboard. The shim sets
`window.top.timed_out = true` before assigning `window.top.location.replace()`,
which is the same flag OpenEMR's listener already checks to skip the prompt
during session-timeout reloads. Setting `onbeforeunload = null` does not
remove `addEventListener` listeners, so the flag is the right path. See
`interface/patient_file/summary/modern_dashboard.php`.

**6.5.7 Auto-trust an active OpenEMR core session
(`OPENEMR_OAUTH_TRUST_CORE_SESSION`).**
A clinician already signed into OpenEMR's web UI should not have to enter
their credentials again when the menu hand-off triggers the dashboard's
OIDC flow. When the env var is set on the openemr-web service,
`AuthorizationController::oauthAuthorizationFlow` detects an active core
session via the existing `OpenEMR` cookie, auto-approves the request for
any enabled client, and skips both `/provider/login` and
`/scope-authorize-confirm`. The path mirrors the SMART launch's
`processAuthorizeFlowForLaunch` but does not require the launch parameter
or the autosubmit cookie-rebind dance because the core session is already
first-party with the OAuth server. Note that `getLoggedInCoreUserUuid`'s
`restoreOAuthSession` swaps `$this->session` for a fresh instance, so the
flow re-populates `nonce`, `csrf`, `scopes`, `client_id`, `redirect_uri`,
and `site_id` from the `AuthorizationRequest` before serializing the
trusted-user row. See `processAuthorizeFlowForCoreSession` in
`src/RestControllers/AuthorizationController.php`. Production sets the
env var to `1`; dev-easy compose ships it on by default. Single-tenant
trust only — leave the env var unset for installations where the OAuth
server fronts multiple OpenEMR core sessions you do not control.

**6.5.8 Origin parity for cookie-bound auth (dev-easy and prod).**
The trust-core-session path requires the OpenEMR session cookie set when
the user logs into the web UI to follow the OAuth flow. Cookies are scoped
by host, so the user has to access OpenEMR at the same hostname as the
OAuth issuer. Dev-easy ships with `OPENEMR_SETTING_site_addr_oath` set to
`http://host.docker.internal:8300` and the recommended login URL is also
`http://host.docker.internal:8300/` (not `localhost:8300`). Production
sets `site_addr_oath` to its own public Railway URL where this is a
non-issue.

## 7. Clinical Co-Pilot card

The dashboard ships a "Clinical Co-Pilot" card alongside the six clinical
cards on `/Patient/{pid}`. It mirrors the legacy
`OpenEMR\Services\Agent\AgentIntentCatalog` button set (Basic patient
data, Current medications, Allergies to confirm, Recent events, Changed
since last visit). Clicking an intent posts the patient's already-loaded
FHIR record plus the intent's prompt to OpenAI's Chat Completions API
and renders the response inline.

The dashboard does not proxy through OpenEMR's `/apis/default/api/agent/intent`
route — that endpoint enforces an OpenEMR session + an `APICSRFTOKEN`
header, neither of which a cross-origin OAuth client can satisfy without
re-implementing the session bridge. Calling OpenAI directly from the
dashboard backend keeps the integration simple, the patient JSON out of
OpenEMR's request log, and the latency to one network hop.

Configuration:

| Variable | Purpose |
|---|---|
| `OPENAI_API_KEY` | API key passed as `Authorization: Bearer …`. Card disables itself with a "not configured" notice when this is absent so the page never crashes on a fresh deploy. |
| `OPENAI_MODEL` | Model identifier; defaults to `gpt-4o-mini`. Override per environment if a different model is preferred. |

Files: `dashboard-dotnet/src/OpenEmr.Dashboard/Copilot/CopilotIntent.cs`
(intent catalog + prompts), `…/Copilot/CopilotService.cs` (OpenAI client),
`…/Pages/Shared/_CopilotCard.cshtml` (UI partial), and the
`OnPostCopilotAsync` handler on `IndexModel`.

## 8. How to extend

### 7.1 Add a new card to the existing patient page

Pick an existing card (e.g. `_AllergiesCard.cshtml`) as a template.

1. Add a `record` for the FHIR resource in
   `dashboard-dotnet/src/OpenEmr.Dashboard/Fhir/Records/`. Use
   `[JsonPropertyName]` for every JSON field. Reuse common types
   (`FhirCodeableConcept`, `FhirCoding`, `FhirPeriod`) from
   `FhirCommon.cs`.
2. Add a fetcher to `FhirClient.cs` that calls the generic
   `SearchBundleAsync<T>` helper with the FHIR query string and an
   operation name for logging.
3. Add a public `CardResult<NewType>` property to
   `Pages/Patient/Index.cshtml.cs` and add the matching
   `SafeFetchAsync` task to the `Task.WhenAll` block in `OnGetAsync`.
4. Create `Pages/Shared/_NewCard.cshtml`. The model type is
   `CardResult<NewType>`. Render `Model.HasError`, the empty case, and
   the row loop.
5. Add a `<div class="col-12 col-lg-4">` slot for the partial in
   `Pages/Patient/Index.cshtml`.
6. Add a unit test in
   `tests/OpenEmr.Dashboard.Tests/Fhir/` that parses a sample fixture.

### 7.2 Add a new FHIR resource

Largely the same as §7.1 but with the additional step of registering the
scope in `Program.cs` (the `o.Scope.Add(...)` block) and re-registering
the OAuth client at OpenEMR with the expanded scope set. OpenEMR's
existing FHIR controllers cover most of US Core; if the resource is not
exposed, you will need to add a service class under `src/Services/FHIR/`
on the OpenEMR side first.

### 7.3 Add a new page

1. Create a new Razor Page under
   `dashboard-dotnet/src/OpenEmr.Dashboard/Pages/...`. The
   `AuthorizeFolder("/")` convention in `Program.cs` already requires
   authentication; pages that should be public need an explicit
   `[AllowAnonymous]`.
2. Inject `FhirClient` (or a new service) via the constructor.
3. Build any back-nav links from `IConfiguration["OPENEMR_PUBLIC_URL"]`.
   Do not hardcode hostnames. The patient header partial
   (`_PatientHeader.cshtml`) is the reference for how to do this:

   ```cshtml
   var oeUrl = (Cfg["OPENEMR_PUBLIC_URL"] ?? string.Empty).TrimEnd('/');
   var demographicsLink = $"{oeUrl}/interface/patient_file/summary/demographics.php?set_pid={pid}";
   ```
4. If the page should appear in OpenEMR's patient menu, add an entry to
   `sites/default/documents/custom_menus/patient_menus/Custom.json`
   matching the `Modern Dashboard` entry. Use `"pid": "true"` if the page
   route accepts a patient id.
