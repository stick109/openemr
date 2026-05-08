using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Mvc.Testing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;
using Xunit;

namespace OpenEmr.Dashboard.Tests;

public sealed class OidcChallengeTests : IClassFixture<OidcChallengeTests.StubAuthorityFactory>
{
    private readonly StubAuthorityFactory factory;

    public OidcChallengeTests(StubAuthorityFactory factory)
    {
        this.factory = factory;
    }

    [Fact]
    public async Task UnauthenticatedHomeRedirectsToAuthorize()
    {
        using var client = factory.CreateClient(new WebApplicationFactoryClientOptions
        {
            AllowAutoRedirect = false,
        });

        var response = await client.GetAsync("/");

        Assert.Equal(System.Net.HttpStatusCode.Redirect, response.StatusCode);
        Assert.NotNull(response.Headers.Location);
        var location = response.Headers.Location!.ToString();
        Assert.StartsWith("https://stub.example.test/oauth2/default/authorize", location);
        Assert.Contains("response_type=code", location);
        Assert.Contains("client_id=stub-client", location);
    }

    public sealed class StubAuthorityFactory : WebApplicationFactory<Program>
    {
        protected override void ConfigureWebHost(IWebHostBuilder builder)
        {
            builder.UseSetting("OPENEMR_OIDC_AUTHORITY", "https://stub.example.test/oauth2/default");
            builder.UseSetting("DASHBOARD_OIDC_CLIENT_ID", "stub-client");
            builder.UseSetting("DASHBOARD_OIDC_CLIENT_SECRET", "stub-secret");
            builder.UseSetting("DASHBOARD_OIDC_REDIRECT_URI", "http://localhost/signin-oidc");

            // The OIDC middleware would otherwise fetch /.well-known/openid-configuration on
            // first challenge. Inject a static configuration so the test stays offline.
            builder.ConfigureServices(services =>
            {
                services.Configure<OpenIdConnectOptions>(OpenIdConnectDefaults.AuthenticationScheme, o =>
                {
                    o.Configuration = new OpenIdConnectConfiguration
                    {
                        Issuer = "https://stub.example.test/oauth2/default",
                        AuthorizationEndpoint = "https://stub.example.test/oauth2/default/authorize",
                        TokenEndpoint = "https://stub.example.test/oauth2/default/token",
                        UserInfoEndpoint = "https://stub.example.test/oauth2/default/userinfo",
                        JwksUri = "https://stub.example.test/oauth2/default/jwk",
                        EndSessionEndpoint = "https://stub.example.test/oauth2/default/logout",
                    };
                });
            });
        }
    }
}
