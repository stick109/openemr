using System.Globalization;
using System.Net.Http.Headers;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.Extensions.Options;

namespace OpenEmr.Dashboard.Auth;

/// <summary>
/// Refreshes the OIDC access token before downstream FHIR calls when the
/// cookie's <c>access_token</c> is within <see cref="RefreshSkew"/> of expiry.
/// Pulls the <c>refresh_token</c> out of the cookie's
/// <see cref="AuthenticationProperties"/>, exchanges it at the OpenEMR token
/// endpoint, and rewrites the auth cookie via <see cref="AuthenticationHttpContextExtensions.SignInAsync(HttpContext, string, System.Security.Claims.ClaimsPrincipal, AuthenticationProperties)"/>.
/// On any failure the request falls through unmodified — the user will see a
/// 401 from the FHIR call and re-login on the next round-trip.
/// </summary>
public sealed class RefreshTokenMiddleware : IMiddleware
{
    public const string HttpClientName = "RefreshTokenClient";
    private static readonly TimeSpan RefreshSkew = TimeSpan.FromSeconds(30);

    private readonly IHttpClientFactory httpClientFactory;
    private readonly IOptionsMonitor<OpenIdConnectOptions> oidcOptionsMonitor;
    private readonly ILogger<RefreshTokenMiddleware> logger;

    public RefreshTokenMiddleware(
        IHttpClientFactory httpClientFactory,
        IOptionsMonitor<OpenIdConnectOptions> oidcOptionsMonitor,
        ILogger<RefreshTokenMiddleware> logger)
    {
        this.httpClientFactory = httpClientFactory;
        this.oidcOptionsMonitor = oidcOptionsMonitor;
        this.logger = logger;
    }

    public async Task InvokeAsync(HttpContext context, RequestDelegate next)
    {
        if (context.User.Identity?.IsAuthenticated != true)
        {
            await next(context);
            return;
        }

        var authResult = await context.AuthenticateAsync(CookieAuthenticationDefaults.AuthenticationScheme);
        var props = authResult.Properties;
        if (!authResult.Succeeded || props is null)
        {
            await next(context);
            return;
        }

        var expiresAtRaw = props.GetTokenValue("expires_at");
        if (string.IsNullOrEmpty(expiresAtRaw)
            || !DateTimeOffset.TryParse(
                expiresAtRaw,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                out var expiresAt))
        {
            await next(context);
            return;
        }

        if (expiresAt > DateTimeOffset.UtcNow + RefreshSkew)
        {
            await next(context);
            return;
        }

        var refreshToken = props.GetTokenValue("refresh_token");
        if (string.IsNullOrEmpty(refreshToken))
        {
            this.logger.LogDebug("Skipping token refresh: no refresh_token stored in cookie.");
            await next(context);
            return;
        }

        var oidc = this.oidcOptionsMonitor.Get(OpenIdConnectDefaults.AuthenticationScheme);
        try
        {
            var refreshed = await this.RequestRefreshAsync(oidc, refreshToken, context.RequestAborted);
            if (refreshed is null)
            {
                await next(context);
                return;
            }

            UpdateTokenStore(props, refreshed);
            await context.SignInAsync(
                CookieAuthenticationDefaults.AuthenticationScheme,
                authResult.Principal!,
                props);
        }
        catch (HttpRequestException ex)
        {
            this.logger.LogWarning(ex, "OIDC refresh request failed.");
        }
        catch (JsonException ex)
        {
            this.logger.LogWarning(ex, "OIDC refresh response could not be parsed.");
        }

        await next(context);
    }

    private async Task<TokenResponse?> RequestRefreshAsync(
        OpenIdConnectOptions oidc,
        string refreshToken,
        CancellationToken cancellationToken)
    {
        var configuration = oidc.Configuration;
        if (configuration is null && oidc.ConfigurationManager is not null)
        {
            configuration = await oidc.ConfigurationManager.GetConfigurationAsync(cancellationToken);
        }

        var tokenEndpoint = configuration?.TokenEndpoint;
        if (string.IsNullOrEmpty(tokenEndpoint))
        {
            this.logger.LogWarning("Cannot refresh token: OIDC token endpoint is not configured.");
            return null;
        }

        if (string.IsNullOrEmpty(oidc.ClientId) || string.IsNullOrEmpty(oidc.ClientSecret))
        {
            this.logger.LogWarning("Cannot refresh token: OIDC client credentials are not configured.");
            return null;
        }

        using var client = this.httpClientFactory.CreateClient(HttpClientName);
        using var request = new HttpRequestMessage(HttpMethod.Post, tokenEndpoint)
        {
            Content = new FormUrlEncodedContent(new[]
            {
                new KeyValuePair<string, string>("grant_type", "refresh_token"),
                new KeyValuePair<string, string>("refresh_token", refreshToken),
                new KeyValuePair<string, string>("client_id", oidc.ClientId),
                new KeyValuePair<string, string>("client_secret", oidc.ClientSecret),
            }),
        };
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        using var response = await client.SendAsync(request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            this.logger.LogWarning(
                "OIDC refresh returned non-success status {Status}.",
                (int)response.StatusCode);
            return null;
        }

        await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        var parsed = await JsonSerializer.DeserializeAsync<TokenResponse>(stream, cancellationToken: cancellationToken);
        if (parsed is null || string.IsNullOrEmpty(parsed.AccessToken))
        {
            this.logger.LogWarning("OIDC refresh response was empty or missing access_token.");
            return null;
        }

        return parsed;
    }

    private static void UpdateTokenStore(AuthenticationProperties props, TokenResponse refreshed)
    {
        var tokens = props.GetTokens().ToList();
        var updated = new List<AuthenticationToken>(tokens.Count + 2);
        var seen = new HashSet<string>(StringComparer.Ordinal);

        void Upsert(string name, string? value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return;
            }

            seen.Add(name);
            updated.Add(new AuthenticationToken { Name = name, Value = value });
        }

        Upsert("access_token", refreshed.AccessToken);

        // OpenEMR's OAuth2 server may rotate the refresh token. If the response
        // contains a fresh one, persist it; otherwise keep the existing value.
        if (!string.IsNullOrEmpty(refreshed.RefreshToken))
        {
            Upsert("refresh_token", refreshed.RefreshToken);
        }

        if (refreshed.ExpiresIn > 0)
        {
            var newExpiry = DateTimeOffset.UtcNow.AddSeconds(refreshed.ExpiresIn)
                .ToString("o", CultureInfo.InvariantCulture);
            Upsert("expires_at", newExpiry);
        }

        if (!string.IsNullOrEmpty(refreshed.TokenType))
        {
            Upsert("token_type", refreshed.TokenType);
        }

        if (!string.IsNullOrEmpty(refreshed.IdToken))
        {
            Upsert("id_token", refreshed.IdToken);
        }

        foreach (var existing in tokens)
        {
            if (!seen.Contains(existing.Name))
            {
                updated.Add(existing);
            }
        }

        props.StoreTokens(updated);
    }

    /// <summary>
    /// Subset of the RFC 6749 token response we care about. Other fields are
    /// ignored — the OIDC middleware already validated the original id_token,
    /// and refresh responses in the wild include vendor-specific extras.
    /// </summary>
    public sealed record TokenResponse(
        [property: JsonPropertyName("access_token")] string? AccessToken,
        [property: JsonPropertyName("refresh_token")] string? RefreshToken,
        [property: JsonPropertyName("expires_in")] int ExpiresIn,
        [property: JsonPropertyName("token_type")] string? TokenType,
        [property: JsonPropertyName("id_token")] string? IdToken,
        [property: JsonPropertyName("scope")] string? Scope);
}
