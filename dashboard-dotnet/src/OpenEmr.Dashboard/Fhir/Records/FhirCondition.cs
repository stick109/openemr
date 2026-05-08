using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 Condition resource (problem list).
/// Fields per week-2/week-2-extra-assignment-plan.md §5:
/// <c>code.coding[0].code/display</c>, <c>clinicalStatus</c>,
/// <c>verificationStatus</c>, <c>onsetDateTime</c>, <c>recordedDate</c>.
/// </summary>
public sealed record FhirCondition
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("code")]
    public FhirCodeableConcept? Code { get; init; }

    [JsonPropertyName("clinicalStatus")]
    public FhirCodeableConcept? ClinicalStatus { get; init; }

    [JsonPropertyName("verificationStatus")]
    public FhirCodeableConcept? VerificationStatus { get; init; }

    [JsonPropertyName("onsetDateTime")]
    public string? OnsetDateTime { get; init; }

    [JsonPropertyName("recordedDate")]
    public string? RecordedDate { get; init; }

    /// <summary>First coding from <c>code.coding</c>, if any.</summary>
    public FhirCoding? FirstCoding() =>
        this.Code?.Coding is { Count: > 0 } coding ? coding[0] : null;

    /// <summary>First clinical-status code (e.g. <c>active</c>, <c>resolved</c>).</summary>
    public string? ClinicalStatusCode() =>
        this.ClinicalStatus?.Coding is { Count: > 0 } coding ? coding[0].Code : null;

    /// <summary>First verification-status code (e.g. <c>confirmed</c>, <c>provisional</c>).</summary>
    public string? VerificationStatusCode() =>
        this.VerificationStatus?.Coding is { Count: > 0 } coding ? coding[0].Code : null;
}
