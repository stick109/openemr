using System.Text.Json.Serialization;

namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Hand-rolled subset of the FHIR R4 Patient resource. Only the fields the
/// dashboard renders are present; see week-2/week-2-extra-assignment-plan.md §5.
/// We avoid Hl7.Fhir.R4 to keep the assembly light and use System.Text.Json.
/// </summary>
public sealed record FhirPatient
{
    [JsonPropertyName("id")]
    public string? Id { get; init; }

    [JsonPropertyName("active")]
    public bool? Active { get; init; }

    [JsonPropertyName("name")]
    public IReadOnlyList<FhirHumanName>? Name { get; init; }

    [JsonPropertyName("birthDate")]
    public string? BirthDate { get; init; }

    [JsonPropertyName("gender")]
    public string? Gender { get; init; }

    [JsonPropertyName("identifier")]
    public IReadOnlyList<FhirIdentifier>? Identifier { get; init; }

    [JsonPropertyName("deceasedBoolean")]
    public bool? DeceasedBoolean { get; init; }

    /// <summary>
    /// OpenEMR's `pubpid` is emitted as a FHIR identifier whose
    /// <c>type.coding[].code == "PT"</c>. Returns the first matching value, or
    /// <c>null</c> if no PT identifier is present.
    /// </summary>
    public static string? ExtractMrn(FhirPatient patient)
    {
        if (patient.Identifier is null)
        {
            return null;
        }

        foreach (var ident in patient.Identifier)
        {
            var coding = ident.Type?.Coding;
            if (coding is null)
            {
                continue;
            }

            foreach (var c in coding)
            {
                if (string.Equals(c.Code, "PT", StringComparison.Ordinal))
                {
                    return ident.Value;
                }
            }
        }

        return null;
    }

    /// <summary>
    /// Picks the official-use name when present, else the first name in the
    /// list. FHIR allows multiple names with different <c>use</c> values
    /// (official, usual, nickname, etc.); the official name is the canonical
    /// legal name we want to display.
    /// </summary>
    public static FhirHumanName? PreferredName(FhirPatient patient)
    {
        if (patient.Name is null || patient.Name.Count == 0)
        {
            return null;
        }

        foreach (var n in patient.Name)
        {
            if (string.Equals(n.Use, "official", StringComparison.Ordinal))
            {
                return n;
            }
        }

        return patient.Name[0];
    }
}

public sealed record FhirHumanName
{
    [JsonPropertyName("use")]
    public string? Use { get; init; }

    [JsonPropertyName("family")]
    public string? Family { get; init; }

    [JsonPropertyName("given")]
    public IReadOnlyList<string>? Given { get; init; }
}

public sealed record FhirIdentifier
{
    [JsonPropertyName("system")]
    public string? System { get; init; }

    [JsonPropertyName("value")]
    public string? Value { get; init; }

    [JsonPropertyName("type")]
    public FhirCodeableConcept? Type { get; init; }
}

public sealed record FhirCodeableConcept
{
    [JsonPropertyName("coding")]
    public IReadOnlyList<FhirCoding>? Coding { get; init; }

    [JsonPropertyName("text")]
    public string? Text { get; init; }
}

public sealed record FhirCoding
{
    [JsonPropertyName("system")]
    public string? System { get; init; }

    [JsonPropertyName("code")]
    public string? Code { get; init; }

    [JsonPropertyName("display")]
    public string? Display { get; init; }
}
