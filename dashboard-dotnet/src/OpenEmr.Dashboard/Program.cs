using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Authorization;
using OpenEmr.Dashboard.Auth;

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
    o.GetClaimsFromUserInfoEndpoint = true;
    o.CallbackPath = "/signin-oidc";
    o.SignedOutCallbackPath = "/signout-callback-oidc";
    o.RequireHttpsMetadata = !env.IsDevelopment();
    o.BackchannelHttpHandler = new OpenEmrDiscoveryFixupHandler { InnerHandler = new HttpClientHandler() };
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

builder.Services.AddRazorPages()
    .AddRazorPagesOptions(o =>
    {
        o.Conventions.AuthorizeFolder("/");
        o.Conventions.AllowAnonymousToPage("/Error");
    });

builder.Services.AddHealthChecks();

var app = builder.Build();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error");
    app.UseHsts();
}

app.UseStaticFiles();
app.UseRouting();

app.UseAuthentication();
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
