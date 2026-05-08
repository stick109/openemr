using System.Globalization;
using System.Net;
using System.Security.Claims;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;
using OpenEmr.Dashboard.Auth;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Auth;

public sealed class RefreshTokenMiddlewareTests
{
    [Fact]
    public async Task Refreshes_Token_And_Calls_SignIn_When_Within_Skew()
    {
        var nearExpiry = DateTimeOffset.UtcNow.AddSeconds(5)
            .ToString("o", CultureInfo.InvariantCulture);
        var props = new AuthenticationProperties();
        props.StoreTokens(new[]
        {
            new AuthenticationToken { Name = "access_token", Value = "old-access" },
            new AuthenticationToken { Name = "refresh_token", Value = "old-refresh" },
            new AuthenticationToken { Name = "expires_at", Value = nearExpiry },
        });
        var principal = new ClaimsPrincipal(
            new ClaimsIdentity(new[] { new Claim(ClaimTypes.Name, "alice") }, "TestAuth"));

        var fakeAuth = new FakeAuthenticationService(
            AuthenticateResult.Success(new AuthenticationTicket(principal, props, CookieAuthenticationDefaults.AuthenticationScheme)));
        var context = BuildHttpContext(principal, fakeAuth);

        var responseBody = """
            {
              "access_token": "new-access",
              "refresh_token": "new-refresh",
              "expires_in": 3600,
              "token_type": "Bearer"
            }
            """;
        var middleware = BuildMiddleware(responseBody, HttpStatusCode.OK);

        var nextCalled = false;
        await middleware.InvokeAsync(context, _ =>
        {
            nextCalled = true;
            return Task.CompletedTask;
        });

        Assert.True(nextCalled);
        Assert.NotNull(fakeAuth.LastSignInProperties);
        var refreshedTokens = fakeAuth.LastSignInProperties!.GetTokens()
            .ToDictionary(t => t.Name, t => t.Value, StringComparer.Ordinal);
        Assert.Equal("new-access", refreshedTokens["access_token"]);
        Assert.Equal("new-refresh", refreshedTokens["refresh_token"]);
        Assert.True(
            DateTimeOffset.Parse(refreshedTokens["expires_at"], CultureInfo.InvariantCulture)
                > DateTimeOffset.UtcNow.AddSeconds(60));
    }

    [Fact]
    public async Task Skips_Refresh_When_Token_Is_Not_Near_Expiry()
    {
        var farExpiry = DateTimeOffset.UtcNow.AddHours(1)
            .ToString("o", CultureInfo.InvariantCulture);
        var props = new AuthenticationProperties();
        props.StoreTokens(new[]
        {
            new AuthenticationToken { Name = "access_token", Value = "still-fresh" },
            new AuthenticationToken { Name = "refresh_token", Value = "rt" },
            new AuthenticationToken { Name = "expires_at", Value = farExpiry },
        });
        var principal = new ClaimsPrincipal(
            new ClaimsIdentity(new[] { new Claim(ClaimTypes.Name, "alice") }, "TestAuth"));

        var fakeAuth = new FakeAuthenticationService(
            AuthenticateResult.Success(new AuthenticationTicket(principal, props, CookieAuthenticationDefaults.AuthenticationScheme)));
        var context = BuildHttpContext(principal, fakeAuth);

        // Use an HTTP response that would fail JSON parse if the middleware ever
        // calls it — proves the network round-trip is not made.
        var middleware = BuildMiddleware("not-json", HttpStatusCode.OK);

        await middleware.InvokeAsync(context, _ => Task.CompletedTask);

        Assert.Null(fakeAuth.LastSignInProperties);
    }

    [Fact]
    public async Task Falls_Through_When_Refresh_Endpoint_Returns_4xx()
    {
        var nearExpiry = DateTimeOffset.UtcNow.AddSeconds(5)
            .ToString("o", CultureInfo.InvariantCulture);
        var props = new AuthenticationProperties();
        props.StoreTokens(new[]
        {
            new AuthenticationToken { Name = "access_token", Value = "old" },
            new AuthenticationToken { Name = "refresh_token", Value = "expired" },
            new AuthenticationToken { Name = "expires_at", Value = nearExpiry },
        });
        var principal = new ClaimsPrincipal(
            new ClaimsIdentity(new[] { new Claim(ClaimTypes.Name, "alice") }, "TestAuth"));

        var fakeAuth = new FakeAuthenticationService(
            AuthenticateResult.Success(new AuthenticationTicket(principal, props, CookieAuthenticationDefaults.AuthenticationScheme)));
        var context = BuildHttpContext(principal, fakeAuth);

        var middleware = BuildMiddleware("{\"error\":\"invalid_grant\"}", HttpStatusCode.BadRequest);

        var nextCalled = false;
        await middleware.InvokeAsync(context, _ =>
        {
            nextCalled = true;
            return Task.CompletedTask;
        });

        // The pipeline keeps moving, but we did not write a fresh cookie.
        Assert.True(nextCalled);
        Assert.Null(fakeAuth.LastSignInProperties);
    }

    private static RefreshTokenMiddleware BuildMiddleware(string responseBody, HttpStatusCode status)
    {
        var handler = new StubHttpMessageHandler(responseBody, status);
        var httpClient = new HttpClient(handler);
        var factory = new SingleClientFactory(httpClient);

        var oidcOptions = new OpenIdConnectOptions
        {
            ClientId = "stub-client",
            ClientSecret = "stub-secret",
            Configuration = new OpenIdConnectConfiguration
            {
                TokenEndpoint = "https://stub.example.test/oauth2/default/token",
            },
        };
        var monitor = new SingleOptionsMonitor<OpenIdConnectOptions>(
            OpenIdConnectDefaults.AuthenticationScheme,
            oidcOptions);

        return new RefreshTokenMiddleware(factory, monitor, NullLogger<RefreshTokenMiddleware>.Instance);
    }

    private static DefaultHttpContext BuildHttpContext(ClaimsPrincipal principal, IAuthenticationService authService)
    {
        var services = new ServiceCollection();
        services.AddSingleton(authService);
        services.AddSingleton<IAuthenticationSchemeProvider, FakeSchemeProvider>();
        services.AddLogging();
        var provider = services.BuildServiceProvider();

        var context = new DefaultHttpContext { User = principal, RequestServices = provider };
        return context;
    }

    private sealed class StubHttpMessageHandler : HttpMessageHandler
    {
        private readonly string body;
        private readonly HttpStatusCode status;

        public StubHttpMessageHandler(string body, HttpStatusCode status)
        {
            this.body = body;
            this.status = status;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var response = new HttpResponseMessage(this.status)
            {
                Content = new StringContent(this.body, Encoding.UTF8, "application/json"),
            };
            return Task.FromResult(response);
        }
    }

    private sealed class SingleClientFactory : IHttpClientFactory
    {
        private readonly HttpClient client;

        public SingleClientFactory(HttpClient client)
        {
            this.client = client;
        }

        public HttpClient CreateClient(string name) => this.client;
    }

    private sealed class SingleOptionsMonitor<T> : IOptionsMonitor<T>
    {
        private readonly string name;
        private readonly T value;

        public SingleOptionsMonitor(string name, T value)
        {
            this.name = name;
            this.value = value;
        }

        public T CurrentValue => this.value;

        public T Get(string? name) =>
            string.Equals(name, this.name, StringComparison.Ordinal) ? this.value : this.value;

        public IDisposable OnChange(Action<T, string> listener) => NoopDisposable.Instance;

        private sealed class NoopDisposable : IDisposable
        {
            public static readonly NoopDisposable Instance = new();

            public void Dispose()
            {
            }
        }
    }

    private sealed class FakeAuthenticationService : IAuthenticationService
    {
        private readonly AuthenticateResult authenticateResult;

        public FakeAuthenticationService(AuthenticateResult authenticateResult)
        {
            this.authenticateResult = authenticateResult;
        }

        public AuthenticationProperties? LastSignInProperties { get; private set; }

        public Task<AuthenticateResult> AuthenticateAsync(HttpContext context, string? scheme)
            => Task.FromResult(this.authenticateResult);

        public Task ChallengeAsync(HttpContext context, string? scheme, AuthenticationProperties? properties)
            => Task.CompletedTask;

        public Task ForbidAsync(HttpContext context, string? scheme, AuthenticationProperties? properties)
            => Task.CompletedTask;

        public Task SignInAsync(HttpContext context, string? scheme, ClaimsPrincipal principal, AuthenticationProperties? properties)
        {
            this.LastSignInProperties = properties;
            return Task.CompletedTask;
        }

        public Task SignOutAsync(HttpContext context, string? scheme, AuthenticationProperties? properties)
            => Task.CompletedTask;
    }

    private sealed class FakeSchemeProvider : IAuthenticationSchemeProvider
    {
        public Task<AuthenticationScheme?> GetDefaultAuthenticateSchemeAsync()
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<AuthenticationScheme?> GetDefaultChallengeSchemeAsync()
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<AuthenticationScheme?> GetDefaultForbidSchemeAsync()
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<AuthenticationScheme?> GetDefaultSignInSchemeAsync()
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<AuthenticationScheme?> GetDefaultSignOutSchemeAsync()
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<AuthenticationScheme?> GetSchemeAsync(string name)
            => Task.FromResult<AuthenticationScheme?>(null);

        public Task<IEnumerable<AuthenticationScheme>> GetAllSchemesAsync()
            => Task.FromResult<IEnumerable<AuthenticationScheme>>(Array.Empty<AuthenticationScheme>());

        public Task<IEnumerable<AuthenticationScheme>> GetRequestHandlerSchemesAsync()
            => Task.FromResult<IEnumerable<AuthenticationScheme>>(Array.Empty<AuthenticationScheme>());

        public void AddScheme(AuthenticationScheme scheme)
        {
        }

        public void RemoveScheme(string name)
        {
        }
    }
}
