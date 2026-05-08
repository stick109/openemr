using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

public sealed class AllergyMappingTests
{
    private const string Bundle = """
        {
          "resourceType": "Bundle", "type": "searchset", "total": 1,
          "entry": [{
            "resource": {
              "resourceType": "AllergyIntolerance", "id": "a1",
              "criticality": "high",
              "clinicalStatus": { "coding": [{ "code": "active" }] },
              "code": { "text": "Peanut" },
              "reaction": [{ "manifestation": [{ "text": "Anaphylaxis" }] }]
            }
          }]
        }
        """;

    [Fact]
    public void ParsesAllergyFields()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirAllergy>>(Bundle);
        var a = bundle?.Entry?[0].Resource;

        Assert.NotNull(a);
        Assert.Equal("Peanut", a!.Code?.Text);
        Assert.Equal("high", a.Criticality);
        Assert.Equal("active", a.ClinicalStatusCode());
        Assert.Equal("Anaphylaxis", a.FirstReactionText());
    }
}
