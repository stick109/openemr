using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;
using OpenEmr.Dashboard.Fhir;
using OpenEmr.Dashboard.Fhir.Records;
using FhirPatientRecord = OpenEmr.Dashboard.Fhir.Records.FhirPatient;

namespace OpenEmr.Dashboard.Pages.Patient;

public sealed class IndexModel : PageModel
{
    private readonly FhirClient fhirClient;

    public IndexModel(FhirClient fhirClient)
    {
        this.fhirClient = fhirClient;
    }

    public string Pid { get; private set; } = string.Empty;

    public FhirPatientRecord? FhirPatient { get; private set; }

    public string? Mrn { get; private set; }

    public string DisplayName { get; private set; } = string.Empty;

    public CardResult<FhirAllergy> Allergies { get; private set; } = CardResult<FhirAllergy>.Empty;

    public CardResult<FhirCondition> Problems { get; private set; } = CardResult<FhirCondition>.Empty;

    public CardResult<FhirMedicationRequest> Medications { get; private set; } = CardResult<FhirMedicationRequest>.Empty;

    public CardResult<FhirMedicationRequest> Prescriptions { get; private set; } = CardResult<FhirMedicationRequest>.Empty;

    public CardResult<FhirCareTeam> CareTeam { get; private set; } = CardResult<FhirCareTeam>.Empty;

    public CardResult<FhirEncounter> Encounters { get; private set; } = CardResult<FhirEncounter>.Empty;

    public async Task<IActionResult> OnGetAsync(string pid, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(pid))
        {
            return this.NotFound();
        }

        this.Pid = pid;

        var patient = await this.fhirClient.GetPatientByIdentifierAsync(pid, cancellationToken);
        if (patient is null)
        {
            return this.NotFound();
        }

        this.FhirPatient = patient;
        this.Mrn = FhirPatientRecord.ExtractMrn(patient);
        this.DisplayName = BuildDisplayName(patient);

        // Fan-out: each card gets its own task wrapped in a try/catch, so a
        // single FHIR call's failure surfaces inside that card without
        // breaking the whole page. T11-T15 replace the no-op fetchers with
        // real FhirClient calls; this scaffold defines the latency contract
        // (slowest task governs OnGetAsync wall time).
        var allergiesTask = SafeFetchAsync(NoopFetchAsync<FhirAllergy>, cancellationToken);
        var problemsTask = SafeFetchAsync(NoopFetchAsync<FhirCondition>, cancellationToken);
        var medicationsTask = SafeFetchAsync(NoopFetchAsync<FhirMedicationRequest>, cancellationToken);
        var prescriptionsTask = SafeFetchAsync(NoopFetchAsync<FhirMedicationRequest>, cancellationToken);
        var careTeamTask = SafeFetchAsync(NoopFetchAsync<FhirCareTeam>, cancellationToken);
        var encountersTask = SafeFetchAsync(NoopFetchAsync<FhirEncounter>, cancellationToken);

        await Task.WhenAll(
            allergiesTask,
            problemsTask,
            medicationsTask,
            prescriptionsTask,
            careTeamTask,
            encountersTask);

        this.Allergies = allergiesTask.Result;
        this.Problems = problemsTask.Result;
        this.Medications = medicationsTask.Result;
        this.Prescriptions = prescriptionsTask.Result;
        this.CareTeam = careTeamTask.Result;
        this.Encounters = encountersTask.Result;

        return this.Page();
    }

    private static string BuildDisplayName(FhirPatientRecord patient)
    {
        var name = FhirPatientRecord.PreferredName(patient);
        if (name is null)
        {
            return "(unnamed patient)";
        }

        var given = name.Given is null ? string.Empty : string.Join(' ', name.Given);
        var family = name.Family ?? string.Empty;
        var combined = string.Join(' ', new[] { given, family }
            .Where(part => !string.IsNullOrWhiteSpace(part)));
        return string.IsNullOrWhiteSpace(combined) ? "(unnamed patient)" : combined;
    }

    /// <summary>
    /// Wraps a per-card fetcher so a thrown exception becomes a CardResult
    /// with a non-null Error. The page never blows up because of one bad call.
    /// </summary>
    private static async Task<CardResult<T>> SafeFetchAsync<T>(
        Func<CancellationToken, Task<CardResult<T>>> fetch,
        CancellationToken cancellationToken)
    {
        try
        {
            return await fetch(cancellationToken);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            // Caller cancelled (request abort); propagate to short-circuit
            // the rest of OnGetAsync rather than swallow.
            throw;
        }
        catch (Exception ex)
        {
            return CardResult<T>.Failure(ex.Message);
        }
    }

    /// <summary>
    /// Placeholder fetcher returning an empty success. T11-T15 swap this for
    /// real FhirClient calls (e.g. GetAllergiesByPatientAsync).
    /// </summary>
    private static Task<CardResult<T>> NoopFetchAsync<T>(CancellationToken _) =>
        Task.FromResult(CardResult<T>.Empty);
}
