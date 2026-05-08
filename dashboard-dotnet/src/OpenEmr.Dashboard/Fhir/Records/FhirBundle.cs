using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Minimal FHIR Bundle envelope. The dashboard only inspects
/// <see cref="Entry"/> and pulls each <see cref="FhirBundleEntry{T}.Resource"/>.
/// </summary>
public sealed record FhirBundle<T> where T : class
{
    [JsonPropertyName("resourceType")]
    public string? ResourceType { get; init; }

    [JsonPropertyName("type")]
    public string? Type { get; init; }

    [JsonPropertyName("total")]
    public int? Total { get; init; }

    [JsonPropertyName("entry")]
    public IReadOnlyList<FhirBundleEntry<T>>? Entry { get; init; }
}

public sealed record FhirBundleEntry<T> where T : class
{
    [JsonPropertyName("fullUrl")]
    public string? FullUrl { get; init; }

    [JsonPropertyName("resource")]
    public T? Resource { get; init; }
}
