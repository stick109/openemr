using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// FHIR Reference type (the "member", "individual", etc. fields on resources
/// that point to another resource). We only render <see cref="Display"/> in
/// the dashboard; <see cref="Reference"/> is kept for completeness.
/// </summary>
public sealed record FhirReference
{
    [JsonPropertyName("reference")]
    public string? Reference { get; init; }

    [JsonPropertyName("display")]
    public string? Display { get; init; }
}

/// <summary>
/// FHIR Period type used by Encounter (`period.start`, `period.end`). Both
/// fields are FHIR <c>instant</c> strings; we surface them verbatim and let
/// the view format them.
/// </summary>
public sealed record FhirPeriod
{
    [JsonPropertyName("start")]
    public string? Start { get; init; }

    [JsonPropertyName("end")]
    public string? End { get; init; }
}
