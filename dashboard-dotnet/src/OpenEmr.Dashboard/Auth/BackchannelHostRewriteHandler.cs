namespace OpenEmr.Dashboard.Auth;

// The OIDC discovery doc OpenEMR returns advertises issuer/authorization/
// token/jwks URLs based on its `site_addr_oath` global. Dev-easy points the
// browser at http://localhost:8300, so site_addr_oath must also be
// http://localhost:8300 for the EHR session cookie scope to match the
// /authorize host (otherwise OPENEMR_OAUTH_TRUST_CORE_SESSION cannot see
// the cookie on the cross-host redirect, and the user sees a duplicate
// login form).
//
// "localhost" from inside the dashboard container is the dashboard itself,
// not OpenEMR, so backchannel HTTP calls (discovery, JWK, /token exchange)
// would 404 against the wrong server. This DelegatingHandler swaps the
// request URI's authority from the public/browser-facing host:port to a
// container-network host:port that resolves to OpenEMR. The id_token
// `iss` claim still validates against the public Authority because the
// OIDC client never sees the rewrite - the OpenEMR response body is
// returned unchanged.
public sealed class BackchannelHostRewriteHandler : DelegatingHandler
{
    private readonly string _externalAuthority;
    private readonly string _internalHost;
    private readonly int _internalPort;

    public BackchannelHostRewriteHandler(string externalAuthority, string internalAuthority)
    {
        _externalAuthority = externalAuthority;
        var parts = internalAuthority.Split(':', 2);
        _internalHost = parts[0];
        _internalPort = parts.Length > 1 ? int.Parse(parts[1]) : -1;
    }

    protected override Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request, CancellationToken cancellationToken)
    {
        if (request.RequestUri is { } uri
            && uri.Authority.Equals(_externalAuthority, StringComparison.OrdinalIgnoreCase))
        {
            var builder = new UriBuilder(uri)
            {
                Host = _internalHost,
                Port = _internalPort,
            };
            request.RequestUri = builder.Uri;
        }
        return base.SendAsync(request, cancellationToken);
    }
}
