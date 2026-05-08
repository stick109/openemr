using Microsoft.AspNetCore.Mvc.Testing;
using Xunit;

namespace OpenEmr.Dashboard.Tests;

public sealed class HealthzTests : IClassFixture<WebApplicationFactory<Program>>
{
    private readonly WebApplicationFactory<Program> factory;

    public HealthzTests(WebApplicationFactory<Program> factory)
    {
        this.factory = factory;
    }

    [Fact]
    public async Task Healthz_ReturnsHealthy()
    {
        using var client = factory.CreateClient();

        var response = await client.GetAsync("/healthz");

        Assert.True(response.IsSuccessStatusCode);
        var body = await response.Content.ReadAsStringAsync();
        Assert.Equal("Healthy", body);
    }
}
