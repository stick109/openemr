using System.Text.Json;
using Microsoft.Extensions.Logging;
using OpenEmr.Dashboard.Fhir.Records;

namespace OpenEmr.Dashboard.Fhir;

/// <summary>
/// Thin typed wrapper around the OpenEMR FHIR R4 API. Authentication is
/// applied by <see cref="BearerTokenHandler"/> registered in the HttpClient
/// pipeline. JSON deserialization uses System.Text.Json with the records in
/// <see cref="OpenEmr.Dashboard.Fhir.Records"/>; we deliberately avoid the
/// Hl7.Fhir.R4 NuGet (see plan §5).
/// </summary>
public sealed class FhirClient
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = false,
    };

    private readonly HttpClient httpClient;
    private readonly ILogger<FhirClient> logger;

    public FhirClient(HttpClient httpClient, ILogger<FhirClient> logger)
    {
        this.httpClient = httpClient;
        this.logger = logger;
    }

    /// <summary>
    /// Searches the FHIR Patient resource for a record whose identifier matches
    /// the supplied OpenEMR local pid. OpenEMR's FhirPatientService maps the
    /// FHIR identifier search across <c>ss</c>, <c>pubpid</c>, and <c>pid</c>
    /// columns, so a bare value (no system prefix) finds the patient by local
    /// pid even when pubpid differs. Returns <c>null</c> when the bundle is empty.
    /// </summary>
    public async Task<FhirPatient?> GetPatientByIdentifierAsync(string ptid, CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrEmpty(ptid);

        var encoded = Uri.EscapeDataString(ptid);
        var url = $"Patient?identifier={encoded}";

        using var response = await this.httpClient.GetAsync(url, cancellationToken);
        response.EnsureSuccessStatusCode();

        var rawBody = await response.Content.ReadAsStringAsync(cancellationToken);
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirPatient>>(rawBody, JsonOptions);

        if (bundle?.Entry is null || bundle.Entry.Count == 0)
        {
            return null;
        }

        return bundle.Entry[0].Resource;
    }
}
