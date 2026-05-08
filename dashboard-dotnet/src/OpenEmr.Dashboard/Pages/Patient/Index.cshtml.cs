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

        // The card fetchers want the FHIR uuid, not the local pid: identifier
        // search above translated pid → uuid, and downstream search params on
        // the FHIR resources expect `patient={uuid}`. Bail with empty results
        // if the patient has no id (defensive — OpenEMR always emits one).
        var uuid = patient.Id;
        if (string.IsNullOrWhiteSpace(uuid))
        {
            return this.Page();
        }

        // Fan-out: each card gets its own task wrapped in a try/catch, so a
        // single FHIR call's failure surfaces inside that card without
        // breaking the whole page. Total wall time is the slowest endpoint.
        var allergiesTask = SafeFetchAsync(ct => this.fhirClient.GetAllergiesAsync(uuid, ct), cancellationToken);
        var problemsTask = SafeFetchAsync(ct => this.fhirClient.GetProblemsAsync(uuid, ct), cancellationToken);
        var medicationsTask = SafeFetchAsync(ct => this.fhirClient.GetActiveMedicationsAsync(uuid, ct), cancellationToken);
        var prescriptionsTask = SafeFetchAsync(ct => this.fhirClient.GetPrescriptionsAsync(uuid, ct), cancellationToken);
        var careTeamTask = SafeFetchAsync(ct => this.fhirClient.GetCareTeamAsync(uuid, ct), cancellationToken);
        var encountersTask = SafeFetchAsync(ct => this.fhirClient.GetEncountersAsync(uuid, ct), cancellationToken);

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
}
