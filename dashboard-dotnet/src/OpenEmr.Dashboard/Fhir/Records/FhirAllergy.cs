using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 AllergyIntolerance resource. Fields per
/// week-2/week-2-extra-assignment-plan.md §5: <c>code.text</c>,
/// <c>clinicalStatus</c>, <c>criticality</c>, and the first manifestation
/// text from each reaction.
/// </summary>
public sealed record FhirAllergy
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("code")]
    public FhirCodeableConcept? Code { get; init; }

    [JsonPropertyName("clinicalStatus")]
    public FhirCodeableConcept? ClinicalStatus { get; init; }

    [JsonPropertyName("verificationStatus")]
    public FhirCodeableConcept? VerificationStatus { get; init; }

    [JsonPropertyName("criticality")]
    public string? Criticality { get; init; }

    [JsonPropertyName("reaction")]
    public IReadOnlyList<FhirAllergyReaction>? Reaction { get; init; }

    /// <summary>
    /// Convenience: first manifestation text across all reactions. Returns
    /// <c>null</c> when no manifestation text is present.
    /// </summary>
    public string? FirstReactionText()
    {
        if (this.Reaction is null)
        {
            return null;
        }

        foreach (var r in this.Reaction)
        {
            if (r.Manifestation is null)
            {
                continue;
            }

            foreach (var m in r.Manifestation)
            {
                if (!string.IsNullOrWhiteSpace(m.Text))
                {
                    return m.Text;
                }
            }
        }

        return null;
    }

    /// <summary>
    /// First clinical-status code (e.g. <c>active</c>, <c>inactive</c>,
    /// <c>resolved</c>). Returns <c>null</c> when no coding is present.
    /// </summary>
    public string? ClinicalStatusCode() =>
        this.ClinicalStatus?.Coding is { Count: > 0 } coding ? coding[0].Code : null;

    /// <summary>
    /// Best-effort allergen display string. OpenEMR's FhirAllergyIntoleranceService
    /// emits the allergen name on <c>code.coding[0].display</c> (set from the
    /// diagnosis code description, falling back to <c>lists.title</c>); the FHIR
    /// <c>code.text</c> field is left empty. Reading only <c>Code.Text</c> caused
    /// every row to render as "(unspecified)". Returns null when no coding has a
    /// non-empty display and <c>code.text</c> is also empty.
    /// </summary>
    public string? DisplayText()
    {
        if (!string.IsNullOrWhiteSpace(this.Code?.Text))
        {
            return this.Code.Text;
        }
        if (this.Code?.Coding is { Count: > 0 } coding)
        {
            foreach (var c in coding)
            {
                if (!string.IsNullOrWhiteSpace(c.Display))
                {
                    return c.Display;
                }
            }
        }
        return null;
    }
}

public sealed record FhirAllergyReaction
{
    [JsonPropertyName("manifestation")]
    public IReadOnlyList<FhirCodeableConcept>? Manifestation { get; init; }
}
