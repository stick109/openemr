using System.Net;
using System.Net.Http.Headers;
using System.Security.Claims;
using System.Text;
using System.Text.Encodings.Web;
using AngleSharp.Html.Parser;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;
using OpenEmr.Dashboard.Fhir;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Pages;

public sealed class PatientPageTests : IClassFixture<PatientPageTests.PatientPageFactory>
{
    private readonly PatientPageFactory factory;

    public PatientPageTests(PatientPageFactory factory)
    {
        this.factory = factory;
    }

    [Fact]
    public async Task PatientPage_RendersAllSevenCardsWithMockedFhir()
    {
        using var client = this.factory.CreateClient();

        var response = await client.GetAsync("/Patient/1");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);

        var html = await response.Content.ReadAsStringAsync();
        var parser = new HtmlParser();
        var document = await parser.ParseDocumentAsync(html);

        // The page renders one <header> for the patient and six .card divs for
        // the clinical sections — seven cards in total per plan §9.
        var headers = document.QuerySelectorAll("header[aria-label='Patient header']");
        Assert.Single(headers);

        var clinicalCards = document.QuerySelectorAll("div.card");
        Assert.Equal(6, clinicalCards.Length);

        // Header carries the mock patient name and MRN.
        var headerHtml = headers[0].TextContent;
        Assert.Contains("Doe, Jane", headerHtml);
        Assert.Contains("MRN-MOCK-1", headerHtml);

        // Each card surfaces a string only present in the matching mock payload.
        var bodyHtml = document.Body!.TextContent;
        Assert.Contains("Peanut", bodyHtml);                    // allergy substance
        Assert.Contains("Essential hypertension", bodyHtml);    // problem display
        Assert.Contains("Lisinopril 10mg", bodyHtml);           // active medication
        Assert.Contains("Amoxicillin 500mg", bodyHtml);         // prescription history
        Assert.Contains("Dr. Mock Provider", bodyHtml);         // care-team member
        Assert.Contains("Annual Wellness", bodyHtml);           // encounter type
    }

    public sealed class PatientPageFactory : WebApplicationFactory<Program>
    {
        protected override void ConfigureWebHost(IWebHostBuilder builder)
        {
            builder.UseSetting("OPENEMR_OIDC_AUTHORITY", "https://stub.example.test/oauth2/default");
            builder.UseSetting("DASHBOARD_OIDC_CLIENT_ID", "stub-client");
            builder.UseSetting("DASHBOARD_OIDC_CLIENT_SECRET", "stub-secret");
            builder.UseSetting("DASHBOARD_OIDC_REDIRECT_URI", "http://localhost/signin-oidc");
            builder.UseSetting("OPENEMR_FHIR_BASE_URL", "https://stub.example.test/apis/default/fhir/");

            builder.ConfigureServices(services =>
            {
                // OIDC discovery would otherwise be fetched on startup; mirror
                // OidcChallengeTests and inject a static configuration.
                services.Configure<OpenIdConnectOptions>(OpenIdConnectDefaults.AuthenticationScheme, o =>
                {
                    o.Configuration = new OpenIdConnectConfiguration
                    {
                        Issuer = "https://stub.example.test/oauth2/default",
                        AuthorizationEndpoint = "https://stub.example.test/oauth2/default/authorize",
                        TokenEndpoint = "https://stub.example.test/oauth2/default/token",
                        JwksUri = "https://stub.example.test/oauth2/default/jwk",
                        EndSessionEndpoint = "https://stub.example.test/oauth2/default/logout",
                    };
                });

                // Replace the registered FhirClient with one whose underlying
                // HttpClient is wired to a stub handler returning canned bundles.
                services.RemoveAll<FhirClient>();
                services.AddSingleton<FhirClient>(sp =>
                {
                    var httpClient = new HttpClient(new StubFhirHandler())
                    {
                        BaseAddress = new Uri("https://stub.example.test/apis/default/fhir/"),
                    };
                    httpClient.DefaultRequestHeaders.Accept.Add(
                        new MediaTypeWithQualityHeaderValue("application/fhir+json"));
                    return new FhirClient(httpClient, sp.GetRequiredService<ILogger<FhirClient>>());
                });

                // Bypass auth: register a "Test" scheme that always succeeds and
                // make it the default. The Razor page's [Authorize] still runs,
                // but every request is treated as the canned principal.
                services.AddAuthentication(o =>
                {
                    o.DefaultAuthenticateScheme = TestAuthHandler.SchemeName;
                    o.DefaultChallengeScheme = TestAuthHandler.SchemeName;
                    o.DefaultScheme = TestAuthHandler.SchemeName;
                })
                .AddScheme<AuthenticationSchemeOptions, TestAuthHandler>(
                    TestAuthHandler.SchemeName, _ => { });
            });
        }
    }

    private sealed class TestAuthHandler : AuthenticationHandler<AuthenticationSchemeOptions>
    {
        public const string SchemeName = "Test";

        public TestAuthHandler(
            IOptionsMonitor<AuthenticationSchemeOptions> options,
            ILoggerFactory logger,
            UrlEncoder encoder)
            : base(options, logger, encoder)
        {
        }

        protected override Task<AuthenticateResult> HandleAuthenticateAsync()
        {
            var identity = new ClaimsIdentity(new[]
            {
                new Claim(ClaimTypes.Name, "test-user"),
                new Claim(ClaimTypes.NameIdentifier, "test-user-id"),
            }, SchemeName);
            var principal = new ClaimsPrincipal(identity);
            var ticket = new AuthenticationTicket(principal, new AuthenticationProperties(), SchemeName);
            return Task.FromResult(AuthenticateResult.Success(ticket));
        }
    }

    /// <summary>
    /// Returns canned FHIR bundles based on the request path. The Patient
    /// search uses an identifier query; everything else searches by patient
    /// uuid. URL fragments are sufficient to disambiguate.
    /// </summary>
    private sealed class StubFhirHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            var url = request.RequestUri!.ToString();
            var body = url switch
            {
                _ when url.Contains("/Patient?") => PatientBundle,
                _ when url.Contains("/AllergyIntolerance?") => AllergyBundle,
                _ when url.Contains("/Condition?") => ConditionBundle,
                _ when url.Contains("/MedicationRequest?") && url.Contains("status=active") => ActiveMedsBundle,
                _ when url.Contains("/MedicationRequest?") => PrescriptionsBundle,
                _ when url.Contains("/CareTeam?") => CareTeamBundle,
                _ when url.Contains("/Encounter?") => EncounterBundle,
                _ => "{\"resourceType\":\"Bundle\",\"entry\":[]}",
            };
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/fhir+json"),
            };
            return Task.FromResult(response);
        }

        private const string PatientBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"Patient",
            "id":"mock-uuid-1","active":true,"gender":"female","birthDate":"1985-04-12",
            "name":[{"use":"official","family":"Doe","given":["Jane"]}],
            "identifier":[{"value":"MRN-MOCK-1","type":{"coding":[{"code":"PT"}]}}]}}]}
            """;

        private const string AllergyBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"AllergyIntolerance",
            "id":"a1","criticality":"high","code":{"text":"Peanut"},
            "clinicalStatus":{"coding":[{"code":"active"}]}}}]}
            """;

        private const string ConditionBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"Condition",
            "id":"c1","onsetDateTime":"2020-01-15",
            "code":{"text":"Essential hypertension","coding":[{"code":"I10","display":"Essential hypertension"}]},
            "clinicalStatus":{"coding":[{"code":"active"}]}}}]}
            """;

        private const string ActiveMedsBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"MedicationRequest",
            "id":"m1","status":"active","intent":"order","authoredOn":"2024-01-10",
            "medicationCodeableConcept":{"text":"Lisinopril 10mg"},
            "dosageInstruction":[{"text":"Once daily"}]}}]}
            """;

        private const string PrescriptionsBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"MedicationRequest",
            "id":"m2","status":"completed","intent":"order","authoredOn":"2023-06-22",
            "medicationCodeableConcept":{"text":"Amoxicillin 500mg"},
            "dosageInstruction":[{"text":"Three times daily for 7 days"}]}}]}
            """;

        private const string CareTeamBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"CareTeam",
            "id":"ct1","status":"active",
            "participant":[{"member":{"display":"Dr. Mock Provider"},
            "role":[{"text":"Primary Care Physician"}]}]}}]}
            """;

        private const string EncounterBundle = """
            {"resourceType":"Bundle","entry":[{"resource":{"resourceType":"Encounter",
            "id":"e1","status":"finished","class":{"code":"AMB","display":"ambulatory"},
            "type":[{"text":"Annual Wellness"}],
            "period":{"start":"2024-03-15T09:00:00Z","end":"2024-03-15T09:30:00Z"}}}]}
            """;
    }
}
