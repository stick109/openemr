using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 Encounter resource. Fields per
/// week-2/week-2-extra-assignment-plan.md §5: <c>period.start/end</c>,
/// <c>class.code</c>, <c>type[0].text</c>,
/// <c>participant[].individual.display</c>, <c>reasonCode[0].text</c>.
/// </summary>
public sealed record FhirEncounter
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("status")]
    public string? Status { get; init; }

    /// <summary>FHIR R4 emits encounter class as a single Coding object (not a CodeableConcept).</summary>
    [JsonPropertyName("class")]
    public FhirCoding? Class { get; init; }

    [JsonPropertyName("type")]
    public IReadOnlyList<FhirCodeableConcept>? Type { get; init; }

    [JsonPropertyName("period")]
    public FhirPeriod? Period { get; init; }

    [JsonPropertyName("reasonCode")]
    public IReadOnlyList<FhirCodeableConcept>? ReasonCode { get; init; }

    [JsonPropertyName("participant")]
    public IReadOnlyList<FhirEncounterParticipant>? Participant { get; init; }

    /// <summary>First type text from <c>type[0].text</c>.</summary>
    public string? FirstTypeText() =>
        this.Type is { Count: > 0 } t ? t[0].Text : null;

    /// <summary>First reason text from <c>reasonCode[0].text</c>.</summary>
    public string? FirstReasonText() =>
        this.ReasonCode is { Count: > 0 } r ? r[0].Text : null;

    /// <summary>First participant individual display, if any.</summary>
    public string? FirstParticipantDisplay()
    {
        if (this.Participant is null)
        {
            return null;
        }

        foreach (var p in this.Participant)
        {
            var display = p.Individual?.Display;
            if (!string.IsNullOrWhiteSpace(display))
            {
                return display;
            }
        }

        return null;
    }
}

public sealed record FhirEncounterParticipant
{
    [JsonPropertyName("individual")]
    public FhirReference? Individual { get; init; }
}
