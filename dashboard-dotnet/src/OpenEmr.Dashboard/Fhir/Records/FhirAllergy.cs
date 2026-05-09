using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 AllergyIntolerance resource. Fields per
/// week-2/week-2-extra-assignment-plan.md §5: <c>code.text</c>,
/// <c>clinicalStatus</c>, <c>criticality</c>, and the first manifestation
/// text from each reaction. Also captures the resource-level <c>text</c>
/// narrative — OpenEMR stuffs the allergen name there when no diagnosis code
/// is mapped (see DisplayText()).
/// </summary>
public sealed record FhirAllergy
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("code")]
    public FhirCodeableConcept? Code { get; init; }

    [JsonPropertyName("text")]
    public FhirNarrative? Text { get; init; }

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
    /// Best-effort allergen display string. The OpenEMR FHIR layer can emit
    /// the allergen name in three different places depending on what the
    /// underlying lists row carries:
    ///
    ///  - <c>code.coding[i].display</c> — when the row has a diagnosis code,
    ///    set from the code description (falling back to <c>lists.title</c>).
    ///  - <c>text.div</c> — when no diagnosis code is mapped, OpenEMR drops
    ///    the title into the resource narrative ("Penicillin" lives here for
    ///    title-only rows). The <c>code</c> in this case is the
    ///    data-absent-unknown placeholder, whose display is the literal
    ///    string "Unknown" — not what the clinician wants to see.
    ///  - <c>code.text</c> — left empty by OpenEMR but kept here as the most
    ///    natural FHIR location, so callers that pre-populate it (e.g. tests
    ///    or third-party servers) still resolve correctly.
    ///
    /// We try <c>code.text</c> first, then any non-data-absent coding's
    /// <c>display</c>, then fall back to extracting the text from
    /// <c>text.div</c>. Returns <c>null</c> only when none of the three
    /// sources produced a usable name.
    /// </summary>
    public string? DisplayText()
    {
        if (!string.IsNullOrWhiteSpace(this.Code?.Text))
        {
            return this.Code.Text;
        }
        if (this.Code?.Coding is { Count: > 0 } codings)
        {
            foreach (var c in codings)
            {
                if (IsDataAbsentReason(c))
                {
                    continue;
                }
                if (!string.IsNullOrWhiteSpace(c.Display))
                {
                    return c.Display;
                }
            }
        }
        return ExtractNarrativeText(this.Text?.Div);
    }

    private static bool IsDataAbsentReason(FhirCoding coding) =>
        string.Equals(
            coding.System,
            "http://terminology.hl7.org/CodeSystem/data-absent-reason",
            StringComparison.Ordinal);

    private static string? ExtractNarrativeText(string? div)
    {
        if (string.IsNullOrWhiteSpace(div))
        {
            return null;
        }
        // FHIR Narrative.div is XHTML, always wrapped in a single <div>.
        // OpenEMR's UtilsService::createNarrative() emits
        // `<div xmlns='http://www.w3.org/1999/xhtml'>{title}</div>` so the
        // content we want is whatever sits inside the outermost div, with
        // any nested formatting tags stripped.
        var match = Regex.Match(
            div,
            "<div[^>]*>(?<content>.*?)</div>",
            RegexOptions.Singleline | RegexOptions.IgnoreCase);
        if (!match.Success)
        {
            return null;
        }
        var content = Regex.Replace(match.Groups["content"].Value, "<[^>]+>", string.Empty).Trim();
        return string.IsNullOrWhiteSpace(content) ? null : content;
    }
}

public sealed record FhirAllergyReaction
{
    [JsonPropertyName("manifestation")]
    public IReadOnlyList<FhirCodeableConcept>? Manifestation { get; init; }
}

public sealed record FhirNarrative
{
    [JsonPropertyName("status")]
    public string? Status { get; init; }

    [JsonPropertyName("div")]
    public string? Div { get; init; }
}
