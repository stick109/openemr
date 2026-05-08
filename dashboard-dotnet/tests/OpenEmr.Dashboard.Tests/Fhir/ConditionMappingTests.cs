using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

public sealed class ConditionMappingTests
{
    private const string Bundle = """
        {
          "resourceType": "Bundle", "type": "searchset", "total": 1,
          "entry": [{
            "resource": {
              "resourceType": "Condition", "id": "c1",
              "clinicalStatus": { "coding": [{ "code": "active" }] },
              "verificationStatus": { "coding": [{ "code": "confirmed" }] },
              "code": {
                "coding": [{ "system": "http://snomed.info/sct", "code": "73211009", "display": "Diabetes mellitus" }],
                "text": "Diabetes mellitus"
              },
              "onsetDateTime": "2020-01-15",
              "recordedDate": "2020-02-01"
            }
          }]
        }
        """;

    [Fact]
    public void ParsesProblemListItem()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirCondition>>(Bundle);
        var c = bundle?.Entry?[0].Resource;

        Assert.NotNull(c);
        var coding = c!.FirstCoding();
        Assert.Equal("73211009", coding?.Code);
        Assert.Equal("Diabetes mellitus", coding?.Display);
        Assert.Equal("active", c.ClinicalStatusCode());
        Assert.Equal("confirmed", c.VerificationStatusCode());
        Assert.Equal("2020-01-15", c.OnsetDateTime);
        Assert.Equal("2020-02-01", c.RecordedDate);
    }
}
