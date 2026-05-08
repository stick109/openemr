using System.Text.Json;
using OpenEmr.Dashboard.Fhir.Records;
using Xunit;

namespace OpenEmr.Dashboard.Tests.Fhir;

public sealed class MedicationRequestMappingTests
{
    private const string Bundle = """
        {
          "resourceType": "Bundle", "type": "searchset", "total": 1,
          "entry": [{
            "resource": {
              "resourceType": "MedicationRequest", "id": "m1",
              "status": "active", "intent": "order",
              "authoredOn": "2024-03-12",
              "medicationCodeableConcept": { "text": "Lisinopril 10 mg" },
              "dosageInstruction": [{ "text": "1 tab daily" }]
            }
          }]
        }
        """;

    [Fact]
    public void ParsesMedicationRequest()
    {
        var bundle = JsonSerializer.Deserialize<FhirBundle<FhirMedicationRequest>>(Bundle);
        var m = bundle?.Entry?[0].Resource;

        Assert.NotNull(m);
        Assert.Equal("active", m!.Status);
        Assert.Equal("order", m.Intent);
        Assert.Equal("2024-03-12", m.AuthoredOn);
        Assert.Equal("Lisinopril 10 mg", m.MedicationCodeableConcept?.Text);
        Assert.Equal("1 tab daily", m.FirstDosageText());
    }
}
