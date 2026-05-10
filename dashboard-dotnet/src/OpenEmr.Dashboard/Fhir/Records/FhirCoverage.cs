using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 Coverage resource. OpenEMR maps each
/// row in <c>insurance_data</c> to one Coverage entry; the patient's
/// "primary" insurance (the one the demographics card surfaces) is the
/// row with <c>type='primary'</c>. Only the fields the dashboard renders
/// are captured here.
/// </summary>
public sealed record FhirCoverage
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    /// <summary>
    /// Plan order: <c>1</c> = primary, <c>2</c> = secondary, <c>3</c> = tertiary.
    /// OpenEMR sets this from <c>insurance_data.type</c>. Used as the tie-breaker
    /// when multiple Coverage resources are returned for the same patient.
    /// </summary>
    [JsonPropertyName("order")]
    public int? Order { get; init; }

    /// <summary>
    /// Member ID / policy number. Sourced from
    /// <c>insurance_data.policy_number</c>.
    /// </summary>
    [JsonPropertyName("subscriberId")]
    public string? SubscriberId { get; init; }

    /// <summary>
    /// Plan-type CodeableConcept. OpenEMR puts <c>plan_name</c> in the
    /// <c>text</c> field and the legacy plan-type code (HMO/PPO/etc) in
    /// <c>coding[0]</c>.
    /// </summary>
    [JsonPropertyName("type")]
    public FhirCodeableConcept? Type { get; init; }

    /// <summary>
    /// Status string: <c>active</c>, <c>cancelled</c>, <c>draft</c>, or
    /// <c>entered-in-error</c>.
    /// </summary>
    [JsonPropertyName("status")]
    public string? Status { get; init; }

    /// <summary>
    /// Insurance carrier reference. The display string is the carrier name
    /// (sourced from the <c>insurance_companies.name</c> column joined to
    /// <c>insurance_data</c>).
    /// </summary>
    [JsonPropertyName("payor")]
    public IReadOnlyList<FhirReference>? Payor { get; init; }

    /// <summary>
    /// Class entries. OpenEMR uses <c>class[].type.coding[].code='group'</c>
    /// to mark the group-number entry, with the group number in
    /// <c>class[].value</c>.
    /// </summary>
    [JsonPropertyName("class")]
    public IReadOnlyList<FhirCoverageClass>? Class { get; init; }

    /// <summary>
    /// Best-effort carrier display name. Walks the payor[] list and
    /// returns the first non-empty <c>display</c> string.
    /// </summary>
    public string? CarrierDisplay()
    {
        if (this.Payor is null)
        {
            return null;
        }
        foreach (var p in this.Payor)
        {
            if (!string.IsNullOrWhiteSpace(p.Display))
            {
                return p.Display;
            }
        }
        return null;
    }

    /// <summary>
    /// Best-effort plan-type label. Prefers <c>type.text</c> (which carries
    /// the human-readable plan name on the OpenEMR side), then falls back
    /// to the first non-empty coding's <c>display</c> or <c>code</c>.
    /// </summary>
    public string? PlanTypeDisplay()
    {
        if (!string.IsNullOrWhiteSpace(this.Type?.Text))
        {
            return this.Type.Text;
        }
        if (this.Type?.Coding is { Count: > 0 } codings)
        {
            foreach (var c in codings)
            {
                if (!string.IsNullOrWhiteSpace(c.Display))
                {
                    return c.Display;
                }
                if (!string.IsNullOrWhiteSpace(c.Code))
                {
                    return c.Code;
                }
            }
        }
        return null;
    }

    /// <summary>
    /// Group number sourced from <c>class[]</c> with type-code 'group'.
    /// Returns null when no group class entry is present.
    /// </summary>
    public string? GroupNumber()
    {
        if (this.Class is null)
        {
            return null;
        }
        foreach (var c in this.Class)
        {
            if (c.Type?.Coding is null)
            {
                continue;
            }
            foreach (var coding in c.Type.Coding)
            {
                if (string.Equals(coding.Code, "group", StringComparison.Ordinal)
                    && !string.IsNullOrWhiteSpace(c.Value))
                {
                    return c.Value;
                }
            }
        }
        return null;
    }
}

/// <summary>
/// FHIR R4 Coverage.class entry. <c>type.coding[].code</c> identifies the
/// kind ("group", "plan", "subgroup", etc); <c>value</c> is the actual
/// identifier and <c>name</c> is its human-readable label.
/// </summary>
public sealed record FhirCoverageClass
{
    [JsonPropertyName("type")]
    public FhirCodeableConcept? Type { get; init; }

    [JsonPropertyName("value")]
    public string? Value { get; init; }

    [JsonPropertyName("name")]
    public string? Name { get; init; }
}
