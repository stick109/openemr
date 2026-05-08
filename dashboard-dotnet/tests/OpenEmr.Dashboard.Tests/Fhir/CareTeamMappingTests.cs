using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

public sealed class CareTeamMappingTests
{
    private const string Bundle = """
        {
          "resourceType": "Bundle", "type": "searchset", "total": 1,
          "entry": [{
            "resource": {
              "resourceType": "CareTeam", "id": "ct1",
              "status": "active",
              "participant": [{
                "role": [{ "text": "Primary care provider" }],
                "member": { "display": "Dr. Alice Example" }
              }]
            }
          }]
        }
        """;

    [Fact]
    public void ParsesCareTeamParticipant()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirCareTeam>>(Bundle);
        var ct = bundle?.Entry?[0].Resource;

        Assert.NotNull(ct);
        Assert.Equal("active", ct!.Status);
        Assert.NotNull(ct.Participant);
        var p = ct.Participant![0];
        Assert.Equal("Primary care provider", p.FirstRoleText());
        Assert.Equal("Dr. Alice Example", p.Member?.Display);
    }
}
