using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

public sealed class EncounterMappingTests
{
    private const string Bundle = """
        {
          "resourceType": "Bundle", "type": "searchset", "total": 1,
          "entry": [{
            "resource": {
              "resourceType": "Encounter", "id": "e1",
              "status": "finished",
              "class": { "system": "http://terminology.hl7.org/CodeSystem/v3-ActCode", "code": "AMB", "display": "ambulatory" },
              "type": [{ "text": "Office visit" }],
              "period": { "start": "2024-01-02T09:00:00Z", "end": "2024-01-02T09:30:00Z" },
              "reasonCode": [{ "text": "Annual physical" }],
              "participant": [{ "individual": { "display": "Dr. Bob Smith" } }]
            }
          }]
        }
        """;

    [Fact]
    public void ParsesEncounterFields()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirEncounter>>(Bundle);
        var e = bundle?.Entry?[0].Resource;

        Assert.NotNull(e);
        Assert.Equal("AMB", e!.Class?.Code);
        Assert.Equal("Office visit", e.FirstTypeText());
        Assert.Equal("2024-01-02T09:00:00Z", e.Period?.Start);
        Assert.Equal("2024-01-02T09:30:00Z", e.Period?.End);
        Assert.Equal("Annual physical", e.FirstReasonText());
        Assert.Equal("Dr. Bob Smith", e.FirstParticipantDisplay());
    }
}
