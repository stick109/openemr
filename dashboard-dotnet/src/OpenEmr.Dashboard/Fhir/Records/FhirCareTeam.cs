using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 CareTeam resource. Fields per
/// week-2/week-2-extra-assignment-plan.md §5:
/// <c>participant[].member.display</c>, <c>participant[].role[].text</c>,
/// <c>status</c>.
/// </summary>
public sealed record FhirCareTeam
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("status")]
    public string? Status { get; init; }

    [JsonPropertyName("participant")]
    public IReadOnlyList<FhirCareTeamParticipant>? Participant { get; init; }
}

public sealed record FhirCareTeamParticipant
{
    [JsonPropertyName("role")]
    public IReadOnlyList<FhirCodeableConcept>? Role { get; init; }

    [JsonPropertyName("member")]
    public FhirReference? Member { get; init; }

    /// <summary>First role text from this participant.</summary>
    public string? FirstRoleText() =>
        this.Role is { Count: > 0 } r ? r[0].Text : null;
}
