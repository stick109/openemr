using System.Net.Http.Headers;
using Microsoft.AspNetCore.Authentication;

namespace OpenEmr.Dashboard.Fhir;

/// <summary>
/// Stamps every outgoing FHIR request with the user's OAuth2 access token.
/// The token lives in the auth cookie (OpenIdConnectOptions.SaveTokens = true)
/// and is fetched per-request via <see cref="AuthenticationHttpContextExtensions.GetTokenAsync"/>.
/// </summary>
public sealed class BearerTokenHandler : DelegatingHandler
{
    private readonly IHttpContextAccessor httpContextAccessor;

    public BearerTokenHandler(IHttpContextAccessor httpContextAccessor)
    {
        this.httpContextAccessor = httpContextAccessor;
    }

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var context = this.httpContextAccessor.HttpContext;
        if (context is not null)
        {
            var accessToken = await context.GetTokenAsync("access_token");
            if (!string.IsNullOrEmpty(accessToken))
            {
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", accessToken);
            }
        }

        return await base.SendAsync(request, cancellationToken);
    }
}
