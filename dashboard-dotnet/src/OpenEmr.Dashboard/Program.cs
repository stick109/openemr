var builder = WebApplication.CreateBuilder(args);

builder.Services.AddRazorPages();
builder.Services.AddHealthChecks();

var app = builder.Build();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error");
    app.UseHsts();
}

app.UseStaticFiles();
app.UseRouting();

app.MapRazorPages();
app.MapHealthChecks("/healthz");

app.Run();

namespace OpenEmr.Dashboard
{
    /// <summary>
    /// Marker partial used by <see cref="Microsoft.AspNetCore.Mvc.Testing.WebApplicationFactory{TEntryPoint}"/>
    /// in the test project to bootstrap the in-memory test server.
    /// </summary>
    public partial class Program;
}
