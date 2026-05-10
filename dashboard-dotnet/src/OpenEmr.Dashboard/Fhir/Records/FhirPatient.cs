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
    /// Postal/billing addresses. OpenEMR's FHIR layer emits a single home
    /// address for most patients; the "use" attribute distinguishes home /
    /// billing / temp where multiple are present.
    /// </summary>
    [JsonPropertyName("address")]
    public IReadOnlyList<FhirAddress>? Address { get; init; }

    /// <summary>
    /// Phone numbers and email addresses. OpenEMR maps phone_home/biz/cell
    /// to <c>telecom[].system='phone'</c> with the corresponding <c>use</c>,
    /// and email/email_direct to <c>telecom[].system='email'</c>. Emergency
    /// contact phone (phone_contact) is NOT in this list — it lives in
    /// <see cref="Contact"/>.
    /// </summary>
    [JsonPropertyName("telecom")]
    public IReadOnlyList<FhirContactPoint>? Telecom { get; init; }

    /// <summary>
    /// Emergency / next-of-kin contacts (FHIR Patient.contact[]). Each entry
    /// holds the contact's name, relationship, and phone, sourced on the
    /// OpenEMR side from patient_data.phone_contact +
    /// patient_data.contact_relationship. Note: the dashboard-side
    /// DemographicsDispatcher writes "Name &lt;phone&gt;" into phone_contact
    /// when both halves are extracted, so the formatted string lives in
    /// telecom[0].value when OpenEMR splits it back out.
    /// </summary>
    [JsonPropertyName("contact")]
    public IReadOnlyList<FhirPatientContact>? Contact { get; init; }

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

/// <summary>
/// FHIR R4 Address. OpenEMR populates <c>line</c> as an array of street
/// strings, <c>city</c>, <c>state</c>, <c>postalCode</c>, and <c>country</c>.
/// </summary>
public sealed record FhirAddress
{
    [JsonPropertyName("use")]
    public string? Use { get; init; }

    [JsonPropertyName("type")]
    public string? Type { get; init; }

    [JsonPropertyName("text")]
    public string? Text { get; init; }

    [JsonPropertyName("line")]
    public IReadOnlyList<string>? Line { get; init; }

    [JsonPropertyName("city")]
    public string? City { get; init; }

    [JsonPropertyName("district")]
    public string? District { get; init; }

    [JsonPropertyName("state")]
    public string? State { get; init; }

    [JsonPropertyName("postalCode")]
    public string? PostalCode { get; init; }

    [JsonPropertyName("country")]
    public string? Country { get; init; }
}

/// <summary>
/// FHIR R4 ContactPoint (telecom). <c>system</c> is "phone" or "email";
/// <c>use</c> is "home" / "work" / "mobile".
/// </summary>
public sealed record FhirContactPoint
{
    [JsonPropertyName("system")]
    public string? System { get; init; }

    [JsonPropertyName("value")]
    public string? Value { get; init; }

    [JsonPropertyName("use")]
    public string? Use { get; init; }

    [JsonPropertyName("rank")]
    public int? Rank { get; init; }
}

/// <summary>
/// FHIR R4 Patient.contact (emergency contact / next-of-kin). Holds the
/// contact's name, relationship, and telecom. OpenEMR currently does not
/// emit <c>contact[]</c> (its FhirPatientService omits the field), so this
/// record will only deserialize when a future OpenEMR change adds it.
/// </summary>
public sealed record FhirPatientContact
{
    [JsonPropertyName("relationship")]
    public IReadOnlyList<FhirCodeableConcept>? Relationship { get; init; }

    [JsonPropertyName("name")]
    public FhirHumanName? Name { get; init; }

    [JsonPropertyName("telecom")]
    public IReadOnlyList<FhirContactPoint>? Telecom { get; init; }

    [JsonPropertyName("address")]
    public FhirAddress? Address { get; init; }

    [JsonPropertyName("gender")]
    public string? Gender { get; init; }
}
