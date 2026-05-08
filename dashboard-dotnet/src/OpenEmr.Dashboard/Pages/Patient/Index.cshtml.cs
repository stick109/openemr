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
}
