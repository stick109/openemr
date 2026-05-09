using System.Net.Http.Headers;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.HttpOverrides;
using OpenEmr.Dashboard.Auth;
using OpenEmr.Dashboard.Copilot;
using OpenEmr.Dashboard.Fhir;

var builder = WebApplication.CreateBuilder(args);
var cfg = builder.Configuration;
var env = builder.Environment;

builder.Services.AddAuthentication(o =>
{
    o.DefaultScheme = CookieAuthenticationDefaults.AuthenticationScheme;
    o.DefaultChallengeScheme = OpenIdConnectDefaults.AuthenticationScheme;
})
.AddCookie()
.AddOpenIdConnect(o =>
{
    o.Authority = cfg["OPENEMR_OIDC_AUTHORITY"];
    o.ClientId = cfg["DASHBOARD_OIDC_CLIENT_ID"];
    o.ClientSecret = cfg["DASHBOARD_OIDC_CLIENT_SECRET"];
    o.ResponseType = "code";
    o.UsePkce = true;
    o.SaveTokens = true;
    // OpenEMR advertises /userinfo in discovery but the endpoint returns 404
    // for token-bearing requests in current builds. The id_token already
    // includes the claims this dashboard needs, so we skip the second hop.
    o.GetClaimsFromUserInfoEndpoint = false;
    o.CallbackPath = "/signin-oidc";
    o.SignedOutCallbackPath = "/signout-callback-oidc";
    o.RequireHttpsMetadata = !env.IsDevelopment();
    var inner = new HttpClientHandler();
    if (env.IsDevelopment())
    {
        inner.ServerCertificateCustomValidationCallback = HttpClientHandler.DangerousAcceptAnyServerCertificateValidator;
    }
    DelegatingHandler chain = new OpenEmrDiscoveryFixupHandler { InnerHandler = inner };
    var externalAuthority = cfg["OPENEMR_BACKCHANNEL_EXTERNAL_AUTHORITY"];
    var internalAuthority = cfg["OPENEMR_BACKCHANNEL_INTERNAL_AUTHORITY"];
    if (!string.IsNullOrWhiteSpace(externalAuthority) && !string.IsNullOrWhiteSpace(internalAuthority))
    {
        chain = new BackchannelHostRewriteHandler(externalAuthority, internalAuthority) { InnerHandler = chain };
    }
    o.BackchannelHttpHandler = chain;
    o.Scope.Clear();
    foreach (var s in new[]
    {
        "openid", "fhirUser", "offline_access", "api:fhir",
        "user/Patient.rs", "user/AllergyIntolerance.rs", "user/Condition.rs",
        "user/MedicationRequest.rs", "user/CareTeam.rs", "user/Encounter.rs",
    })
    {
        o.Scope.Add(s);
    }
});

builder.Services.AddAuthorization();

builder.Services.AddHttpContextAccessor();
builder.Services.AddTransient<BearerTokenHandler>();
builder.Services.AddTransient<RefreshTokenMiddleware>();
builder.Services.AddHttpClient(RefreshTokenMiddleware.HttpClientName)
    .ConfigurePrimaryHttpMessageHandler(() =>
    {
        var handler = new HttpClientHandler();
        if (env.IsDevelopment())
        {
            // Mirror the OIDC backchannel: dev-easy may front OpenEMR with a
            // self-signed cert. Production uses Railway private DNS with valid
            // certs so this branch is dev-only.
            handler.ServerCertificateCustomValidationCallback =
                HttpClientHandler.DangerousAcceptAnyServerCertificateValidator;
        }
        return handler;
    });

builder.Services.AddHttpClient<FhirClient>(c =>
{
    var fhirBase = cfg["OPENEMR_FHIR_BASE_URL"]
        ?? throw new InvalidOperationException("OPENEMR_FHIR_BASE_URL is not configured.");
    if (!fhirBase.EndsWith('/'))
    {
        fhirBase += "/";
    }
    c.BaseAddress = new Uri(fhirBase);
    c.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/fhir+json"));
})
.ConfigurePrimaryHttpMessageHandler(() =>
{
    var handler = new HttpClientHandler();
    if (env.IsDevelopment())
    {
        // Mirror the OIDC backchannel: dev-easy uses HTTP locally, but if a
        // self-signed HTTPS endpoint is ever wired in front of OpenEMR, the
        // dashboard should not refuse it. Production runs on Railway private
        // DNS with valid certs so this branch is dev-only.
        handler.ServerCertificateCustomValidationCallback =
            HttpClientHandler.DangerousAcceptAnyServerCertificateValidator;
    }
    return handler;
})
.AddHttpMessageHandler<BearerTokenHandler>();

// Clinical Co-Pilot card calls OpenAI directly from the dashboard backend
// rather than proxying through OpenEMR's session+CSRF-bound agent route.
// The HttpClient is registered without auth handlers; CopilotService attaches
// the OpenAI bearer token per request.
builder.Services.AddHttpClient<CopilotService>(c =>
{
    c.Timeout = TimeSpan.FromSeconds(30);
});

builder.Services.AddRazorPages()
    .AddRazorPagesOptions(o =>
    {
        o.Conventions.AuthorizeFolder("/");
        o.Conventions.AllowAnonymousToPage("/Error");
    });

builder.Services.AddHealthChecks();

// Railway (and most PaaS platforms) terminate TLS at the edge proxy and
// forward plain HTTP to the container. Without this, the cookie-and-redirect
// stack generates http:// callback URIs that don't match the https:// URI
// registered in OpenEMR's OAuth client table.
builder.Services.Configure<ForwardedHeadersOptions>(o =>
{
    o.ForwardedHeaders = ForwardedHeaders.XForwardedFor | ForwardedHeaders.XForwardedProto;
    // Railway's edge IPs are not enumerable; trust all proxies behind the
    // container network (loopback + RFC-1918 range). For a stricter config,
    // enumerate Railway's egress CIDRs here.
    o.KnownNetworks.Clear();
    o.KnownProxies.Clear();
});

var app = builder.Build();

// Must be registered before any middleware that reads HttpContext.Request.Scheme
// (authentication, HSTS, cookie options) so they see https:// not http://.
app.UseForwardedHeaders();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error");
    app.UseHsts();
}

app.UseStaticFiles();
app.UseRouting();

app.UseAuthentication();
app.UseMiddleware<RefreshTokenMiddleware>();
app.UseAuthorization();

app.MapRazorPages();
app.MapHealthChecks("/healthz").AllowAnonymous();

app.Run();

namespace OpenEmr.Dashboard
{
    /// <summary>
    /// Marker partial used by <see cref="Microsoft.AspNetCore.Mvc.Testing.WebApplicationFactory{TEntryPoint}"/>
    /// in the test project to bootstrap the in-memory test server.
    /// </summary>
    public partial class Program;
}
