using System.Net.Http.Headers;
using System.Text;

namespace OpenEmr.Dashboard.Auth;

// OpenEMR's OAuth2DiscoveryController emits `"require_request_uri_registration": ["false"]`
// (a single-element array of the string "false") whereas the OIDC discovery spec mandates
// a JSON boolean. Microsoft.IdentityModel's strict System.Text.Json parser throws
// IDX11020 on the array. This handler rewrites the field on the way back to the OIDC
// middleware so the response parses without forking upstream PHP.
public sealed class OpenEmrDiscoveryFixupHandler : DelegatingHandler
{
    private const string DiscoveryPathSuffix = "/.well-known/openid-configuration";

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var response = await base.SendAsync(request, cancellationToken);
        if (request.RequestUri is null
            || !request.RequestUri.AbsolutePath.EndsWith(DiscoveryPathSuffix, StringComparison.Ordinal)
            || response.Content is null
            || !response.IsSuccessStatusCode)
        {
            return response;
        }

        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        var fixedBody = body.Replace(
            "\"require_request_uri_registration\": [\"false\"]",
            "\"require_request_uri_registration\": false",
            StringComparison.Ordinal);
        if (ReferenceEquals(body, fixedBody))
        {
            return response;
        }

        var contentType = response.Content.Headers.ContentType
            ?? new MediaTypeHeaderValue("application/json");
        response.Content = new StringContent(fixedBody, Encoding.UTF8, contentType.MediaType ?? "application/json");
        return response;
    }
}
