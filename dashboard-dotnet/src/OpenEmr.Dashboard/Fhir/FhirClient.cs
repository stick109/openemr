using System.Text.Json;
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

    public FhirClient(HttpClient httpClient)
    {
        this.httpClient = httpClient;
    }

    /// <summary>
    /// Searches the FHIR Patient resource for a record whose <c>PT</c>
    /// identifier matches the supplied OpenEMR local pid. OpenEMR's
    /// FhirPatientService maps <c>identifier=PT|{value}</c> to the
    /// <c>pubpid</c> column. Returns <c>null</c> when the bundle is empty.
    /// </summary>
    public async Task<FhirPatient?> GetPatientByIdentifierAsync(string ptid, CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrEmpty(ptid);

        var encoded = Uri.EscapeDataString(ptid);
        var url = $"Patient?identifier=PT|{encoded}&_format=json";

        using var response = await this.httpClient.GetAsync(url, cancellationToken);
        response.EnsureSuccessStatusCode();

        await using var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
        var bundle = await JsonSerializer.DeserializeAsync<FhirBundle<FhirPatient>>(
            stream, JsonOptions, cancellationToken);

        if (bundle?.Entry is null || bundle.Entry.Count == 0)
        {
            return null;
        }

        return bundle.Entry[0].Resource;
    }
}
