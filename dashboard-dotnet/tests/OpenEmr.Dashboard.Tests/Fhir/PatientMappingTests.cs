using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

/// <summary>
/// Pure-mapping tests that exercise <see cref="FhirPatient"/> static helpers
/// against representative bundle JSON. No HttpClient or container; the live
/// integration smoke is T7's responsibility.
/// </summary>
public sealed class PatientMappingTests
{
    private const string BundleWithPtIdentifier = """
        {
          "resourceType": "Bundle",
          "type": "searchset",
          "total": 1,
          "entry": [
            {
              "fullUrl": "http://example/Patient/abc-123",
              "resource": {
                "resourceType": "Patient",
                "id": "abc-123",
                "active": true,
                "name": [
                  {
                    "use": "official",
                    "family": "Smith",
                    "given": ["John", "Q"]
                  }
                ],
                "birthDate": "1980-04-12",
                "gender": "male",
                "identifier": [
                  {
                    "system": "http://hl7.org/fhir/sid/us-ssn",
                    "value": "123-45-6789",
                    "type": {
                      "coding": [
                        { "system": "http://terminology.hl7.org/CodeSystem/v2-0203", "code": "SS" }
                      ]
                    }
                  },
                  {
                    "system": "http://example/openemr/pubpid",
                    "value": "MRN-42",
                    "type": {
                      "coding": [
                        { "system": "http://terminology.hl7.org/CodeSystem/v2-0203", "code": "PT" }
                      ]
                    }
                  }
                ],
                "deceasedBoolean": false
              }
            }
          ]
        }
        """;

    private const string BundleMultipleNameUses = """
        {
          "resourceType": "Bundle",
          "type": "searchset",
          "total": 1,
          "entry": [
            {
              "resource": {
                "resourceType": "Patient",
                "id": "name-uses-1",
                "name": [
                  { "use": "usual",    "family": "Doe",  "given": ["Johnny"] },
                  { "use": "official", "family": "Doe",  "given": ["John", "Robert"] },
                  { "use": "nickname", "family": "Doe",  "given": ["JR"] }
                ]
              }
            }
          ]
        }
        """;

    [Fact]
    public void MrnExtractedFromPtIdentifier()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirPatient>>(BundleWithPtIdentifier);
        Assert.NotNull(bundle);
        Assert.NotNull(bundle.Entry);
        var patient = bundle.Entry![0].Resource;
        Assert.NotNull(patient);

        var mrn = FhirPatient.ExtractMrn(patient!);

        Assert.Equal("MRN-42", mrn);
    }

    [Fact]
    public void NameUseOfficialPreferred()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirPatient>>(BundleMultipleNameUses);
        Assert.NotNull(bundle);
        Assert.NotNull(bundle.Entry);
        var patient = bundle.Entry![0].Resource;
        Assert.NotNull(patient);

        var name = FhirPatient.PreferredName(patient!);

        Assert.NotNull(name);
        Assert.Equal("official", name!.Use);
        Assert.Equal("Doe", name.Family);
        Assert.NotNull(name.Given);
        Assert.Equal(new[] { "John", "Robert" }, name.Given!.ToArray());
    }

    [Fact]
    public void ExtractMrnReturnsNullWhenNoPtIdentifier()
    {
        var patient = new FhirPatient
        {
            Identifier = new[]
            {
                new FhirIdentifier
                {
                    Value = "999",
                    Type = new FhirCodeableConcept
                    {
                        Coding = new[] { new FhirCoding { Code = "MR" } },
                    },
                },
            },
        };

        Assert.Null(FhirPatient.ExtractMrn(patient));
    }

    [Fact]
    public void PreferredNameFallsBackToFirstWhenNoOfficial()
    {
        var patient = new FhirPatient
        {
            Name = new[]
            {
                new FhirHumanName { Use = "usual", Family = "Doe", Given = new[] { "Jane" } },
                new FhirHumanName { Use = "nickname", Family = "Doe", Given = new[] { "Janie" } },
            },
        };

        var name = FhirPatient.PreferredName(patient);

        Assert.NotNull(name);
        Assert.Equal("usual", name!.Use);
    }
}
