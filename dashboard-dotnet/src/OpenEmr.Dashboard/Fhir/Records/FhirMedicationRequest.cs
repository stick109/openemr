using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 MedicationRequest resource. The dashboard
/// renders two cards from this same DTO: active medications
/// (<c>status=active</c>) and the prescription history
/// (<c>intent=order</c>). Fields per
/// week-2/week-2-extra-assignment-plan.md §5: <c>medicationCodeableConcept.text</c>,
/// <c>dosageInstruction[0].text</c>, <c>authoredOn</c>, <c>status</c>.
/// </summary>
public sealed record FhirMedicationRequest
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("status")]
    public string? Status { get; init; }

    [JsonPropertyName("intent")]
    public string? Intent { get; init; }

    [JsonPropertyName("authoredOn")]
    public string? AuthoredOn { get; init; }

    [JsonPropertyName("medicationCodeableConcept")]
    public FhirCodeableConcept? MedicationCodeableConcept { get; init; }

    [JsonPropertyName("dosageInstruction")]
    public IReadOnlyList<FhirDosageInstruction>? DosageInstruction { get; init; }

    /// <summary>First dosage-instruction text, if any.</summary>
    public string? FirstDosageText() =>
        this.DosageInstruction is { Count: > 0 } d ? d[0].Text : null;
}

public sealed record FhirDosageInstruction
{
    [JsonPropertyName("text")]
    public string? Text { get; init; }
}
