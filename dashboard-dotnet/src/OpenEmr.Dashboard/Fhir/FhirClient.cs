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

    /// <summary>
    /// Active and historic allergies for a patient (FHIR R4
    /// <c>AllergyIntolerance?patient={uuid}</c>).
    /// </summary>
    public Task<CardResult<FhirAllergy>> GetAllergiesAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirAllergy>(
            $"AllergyIntolerance?patient={Uri.EscapeDataString(patientUuid)}",
            "allergies",
            cancellationToken);

    /// <summary>
    /// Problem-list conditions
    /// (<c>Condition?patient={uuid}&amp;category=problem-list-item</c>).
    /// </summary>
    public Task<CardResult<FhirCondition>> GetProblemsAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirCondition>(
            $"Condition?patient={Uri.EscapeDataString(patientUuid)}&category=problem-list-item",
            "problems",
            cancellationToken);

    /// <summary>
    /// Active medications
    /// (<c>MedicationRequest?patient={uuid}&amp;status=active</c>).
    /// </summary>
    public Task<CardResult<FhirMedicationRequest>> GetActiveMedicationsAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirMedicationRequest>(
            $"MedicationRequest?patient={Uri.EscapeDataString(patientUuid)}&status=active",
            "active-medications",
            cancellationToken);

    /// <summary>
    /// Prescription history, last 20 by authored-on
    /// (<c>MedicationRequest?patient={uuid}&amp;intent=order&amp;_count=20&amp;_sort=-authored</c>).
    /// </summary>
    public Task<CardResult<FhirMedicationRequest>> GetPrescriptionsAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirMedicationRequest>(
            $"MedicationRequest?patient={Uri.EscapeDataString(patientUuid)}&intent=order&_count=20&_sort=-authored",
            "prescriptions",
            cancellationToken);

    /// <summary>
    /// Care team members for a patient
    /// (<c>CareTeam?patient={uuid}</c>).
    /// </summary>
    public Task<CardResult<FhirCareTeam>> GetCareTeamAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirCareTeam>(
            $"CareTeam?patient={Uri.EscapeDataString(patientUuid)}",
            "care-team",
            cancellationToken);

    /// <summary>
    /// Encounters, last 20 by date
    /// (<c>Encounter?patient={uuid}&amp;_count=20&amp;_sort=-date</c>).
    /// </summary>
    public Task<CardResult<FhirEncounter>> GetEncountersAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirEncounter>(
            $"Encounter?patient={Uri.EscapeDataString(patientUuid)}&_count=20&_sort=-date",
            "encounters",
            cancellationToken);

    /// <summary>
    /// Insurance Coverage entries for a patient
    /// (<c>Coverage?patient={uuid}</c>). The dashboard renders the primary
    /// (lowest <c>order</c>) Coverage; the rest are kept available for
    /// future "secondary insurance" cards.
    /// </summary>
    public Task<CardResult<FhirCoverage>> GetCoverageAsync(string patientUuid, CancellationToken cancellationToken) =>
        this.SearchBundleAsync<FhirCoverage>(
            $"Coverage?patient={Uri.EscapeDataString(patientUuid)}",
            "coverage",
            cancellationToken);

    /// <summary>
    /// Issues a FHIR search and returns a <see cref="CardResult{T}"/> per the
    /// per-card-error-isolation contract from plan §5: HTTP failure or invalid
    /// JSON yields <see cref="CardResult{T}.Failure"/>; an empty bundle yields
    /// <see cref="CardResult{T}.Empty"/>; success returns the parsed entries.
    /// </summary>
    private async Task<CardResult<T>> SearchBundleAsync<T>(
        string url,
        string operationName,
        CancellationToken cancellationToken)
        where T : class
    {
        ArgumentException.ThrowIfNullOrEmpty(url);

        try
        {
            using var response = await this.httpClient.GetAsync(url, cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                this.logger.LogWarning(
                    "FHIR search failed with HTTP {Status} for {Operation}",
                    (int)response.StatusCode,
                    operationName);
                return CardResult<T>.Failure($"FHIR returned HTTP {(int)response.StatusCode}");
            }

            var rawBody = await response.Content.ReadAsStringAsync(cancellationToken);
            var bundle = JsonSerializer.Deserialize<FhirBundle<T>>(rawBody, JsonOptions);

            if (bundle?.Entry is null || bundle.Entry.Count == 0)
            {
                return CardResult<T>.Empty;
            }

            var resources = new List<T>(bundle.Entry.Count);
            foreach (var entry in bundle.Entry)
            {
                if (entry.Resource is not null)
                {
                    resources.Add(entry.Resource);
                }
            }

            return new CardResult<T>(resources, null);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (JsonException ex)
        {
            this.logger.LogError(
                ex,
                "Failed to parse FHIR response for {Operation}",
                operationName);
            return CardResult<T>.Failure("Could not parse FHIR response");
        }
        catch (HttpRequestException ex)
        {
            this.logger.LogError(
                ex,
                "HTTP failure during FHIR search for {Operation}",
                operationName);
            return CardResult<T>.Failure("FHIR request failed");
        }
    }
}
