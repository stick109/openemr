using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.RazorPages;
using OpenEmr.Dashboard.Copilot;
using OpenEmr.Dashboard.Fhir;
using OpenEmr.Dashboard.Fhir.Records;
using FhirPatientRecord = OpenEmr.Dashboard.Fhir.Records.FhirPatient;

namespace OpenEmr.Dashboard.Pages.Patient;

[IgnoreAntiforgeryToken]
public sealed class IndexModel : PageModel
{
    private readonly FhirClient fhirClient;
    private readonly CopilotService copilotService;

    public IndexModel(FhirClient fhirClient, CopilotService copilotService)
    {
        this.fhirClient = fhirClient;
        this.copilotService = copilotService;
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

    public bool CopilotEnabled => this.copilotService.IsConfigured;

    public IReadOnlyList<(CopilotIntent Id, string Label, string Prompt)> CopilotIntents => Copilot.CopilotIntents.Catalog;

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

        await this.PopulateCardsAsync(patient, cancellationToken);
        return this.Page();
    }

    /// <summary>
    /// Co-Pilot card form-post target. Fetches the same FHIR data the page
    /// already loaded, hands it (plus the chosen intent) to OpenAI, and
    /// returns plain text the card renders inline. Kept on the same Razor
    /// page so the form posts back to /Patient/{pid}?handler=Copilot.
    /// </summary>
    public async Task<IActionResult> OnPostCopilotAsync(
        string pid,
        [FromForm] string intentId,
        [FromForm] string? userGoal,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(pid))
        {
            return this.NotFound();
        }
        if (string.IsNullOrWhiteSpace(intentId))
        {
            return this.BadRequest("intentId is required.");
        }
        if (Copilot.CopilotIntents.Lookup(intentId) is null)
        {
            return this.BadRequest($"Unknown Co-Pilot intent: {intentId}");
        }
        // free_text needs the typed prompt; reject empty submissions before
        // we round-trip to OpenAI.
        var isFreeText = string.Equals(intentId, nameof(Copilot.CopilotIntent.FreeText), StringComparison.OrdinalIgnoreCase);
        if (isFreeText && string.IsNullOrWhiteSpace(userGoal))
        {
            return this.BadRequest("userGoal is required for the free_text intent.");
        }

        var patient = await this.fhirClient.GetPatientByIdentifierAsync(pid, cancellationToken);
        if (patient is null)
        {
            return this.NotFound();
        }

        await this.PopulateCardsAsync(patient, cancellationToken);

        var output = await this.copilotService.RunIntentAsync(
            intentId,
            userGoal,
            patient,
            this.Allergies,
            this.Problems,
            this.Medications,
            this.Prescriptions,
            this.CareTeam,
            this.Encounters,
            cancellationToken);

        return this.Content(output, "text/plain; charset=utf-8");
    }

    private async Task PopulateCardsAsync(FhirPatientRecord patient, CancellationToken cancellationToken)
    {
        this.FhirPatient = patient;
        this.Mrn = FhirPatientRecord.ExtractMrn(patient);
        this.DisplayName = BuildDisplayName(patient);

        var uuid = patient.Id;
        if (string.IsNullOrWhiteSpace(uuid))
        {
            return;
        }

        var allergiesTask = SafeFetchAsync(ct => this.fhirClient.GetAllergiesAsync(uuid, ct), cancellationToken);
        var problemsTask = SafeFetchAsync(ct => this.fhirClient.GetProblemsAsync(uuid, ct), cancellationToken);
        var medicationsTask = SafeFetchAsync(ct => this.fhirClient.GetActiveMedicationsAsync(uuid, ct), cancellationToken);
        var prescriptionsTask = SafeFetchAsync(ct => this.fhirClient.GetPrescriptionsAsync(uuid, ct), cancellationToken);
        var careTeamTask = SafeFetchAsync(ct => this.fhirClient.GetCareTeamAsync(uuid, ct), cancellationToken);
        var encountersTask = SafeFetchAsync(ct => this.fhirClient.GetEncountersAsync(uuid, ct), cancellationToken);

        await Task.WhenAll(allergiesTask, problemsTask, medicationsTask, prescriptionsTask, careTeamTask, encountersTask);

        this.Allergies = allergiesTask.Result;
        this.Problems = problemsTask.Result;
        this.Medications = medicationsTask.Result;
        this.Prescriptions = prescriptionsTask.Result;
        this.CareTeam = careTeamTask.Result;
        this.Encounters = encountersTask.Result;
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
            throw;
        }
        catch (Exception ex)
        {
            return CardResult<T>.Failure(ex.Message);
        }
    }
}
