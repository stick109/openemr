using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;
using OpenEmr.Dashboard.Fhir.Records;

namespace OpenEmr.Dashboard.Copilot;

/// <summary>
/// Sends Clinical Co-Pilot intents to OpenAI's Chat Completions API using the
/// patient's already-loaded FHIR data as inline context. We deliberately keep
/// this in the dashboard rather than proxying to OpenEMR's
/// <c>/apis/default/api/agent/intent</c> route - that route is bound to
/// OpenEMR's session+CSRF model, which a cross-origin OAuth client cannot
/// satisfy without re-implementing the session bridge. Calling OpenAI from
/// the dashboard is also faster (one fewer hop) and keeps the patient JSON
/// out of OpenEMR's request log.
/// </summary>
public sealed class CopilotService
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly HttpClient httpClient;
    private readonly ILogger<CopilotService> logger;
    private readonly string apiKey;
    private readonly string model;

    public CopilotService(HttpClient httpClient, ILogger<CopilotService> logger, IConfiguration cfg)
    {
        this.httpClient = httpClient;
        this.logger = logger;
        this.apiKey = cfg["OPENAI_API_KEY"] ?? string.Empty;
        this.model = cfg["OPENAI_MODEL"] ?? "gpt-4o-mini";
    }

    public bool IsConfigured => !string.IsNullOrWhiteSpace(this.apiKey);

    public async Task<string> RunIntentAsync(
        string intentId,
        FhirPatient patient,
        CardResult<FhirAllergy> allergies,
        CardResult<FhirCondition> problems,
        CardResult<FhirMedicationRequest> medications,
        CardResult<FhirMedicationRequest> prescriptions,
        CardResult<FhirCareTeam> careTeam,
        CardResult<FhirEncounter> encounters,
        CancellationToken cancellationToken)
    {
        if (!this.IsConfigured)
        {
            return "OPENAI_API_KEY is not configured on the dashboard service. Co-Pilot requests are disabled.";
        }

        var intent = CopilotIntents.Lookup(intentId)
            ?? throw new ArgumentException($"Unknown Co-Pilot intent: {intentId}", nameof(intentId));

        var contextJson = BuildPatientContext(patient, allergies, problems, medications, prescriptions, careTeam, encounters);

        var requestBody = new
        {
            model = this.model,
            messages = new object[]
            {
                new { role = "system", content = "You are a Clinical Co-Pilot embedded in an EHR. Respond in plain text suitable for a primary-care clinician glancing at a chart. Never invent data; if the chart is silent on something, say so. Do not include the words 'Patient' or any name in your output." },
                new { role = "user", content = $"Patient data (JSON, FHIR R4 selected fields):\n{contextJson}\n\nTask: {intent.Prompt}" },
            },
            temperature = 0.2,
            max_tokens = 400,
        };

        using var request = new HttpRequestMessage(HttpMethod.Post, "https://api.openai.com/v1/chat/completions")
        {
            Content = JsonContent.Create(requestBody, options: JsonOptions),
        };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", this.apiKey);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        try
        {
            using var response = await this.httpClient.SendAsync(request, cancellationToken);
            var raw = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                this.logger.LogWarning("OpenAI returned non-success {Status} for intent {Intent}", (int)response.StatusCode, intentId);
                return $"OpenAI returned HTTP {(int)response.StatusCode}. Co-Pilot is temporarily unavailable.";
            }

            using var doc = JsonDocument.Parse(raw);
            var content = doc.RootElement
                .GetProperty("choices")[0]
                .GetProperty("message")
                .GetProperty("content")
                .GetString();
            return string.IsNullOrWhiteSpace(content) ? "(no Co-Pilot output)" : content.Trim();
        }
        catch (HttpRequestException ex)
        {
            this.logger.LogError(ex, "OpenAI request failed for intent {Intent}", intentId);
            return "Could not reach OpenAI from the dashboard service.";
        }
        catch (JsonException ex)
        {
            this.logger.LogError(ex, "Could not parse OpenAI response for intent {Intent}", intentId);
            return "Could not parse the Co-Pilot response.";
        }
    }

    private static string BuildPatientContext(
        FhirPatient patient,
        CardResult<FhirAllergy> allergies,
        CardResult<FhirCondition> problems,
        CardResult<FhirMedicationRequest> medications,
        CardResult<FhirMedicationRequest> prescriptions,
        CardResult<FhirCareTeam> careTeam,
        CardResult<FhirEncounter> encounters)
    {
        var summary = new
        {
            demographics = new
            {
                birth_date = patient.BirthDate,
                gender = patient.Gender,
                active = patient.Active,
                deceased = patient.DeceasedBoolean,
            },
            allergies = SafeData(allergies),
            problems = SafeData(problems),
            active_medications = SafeData(medications),
            prescription_history = SafeData(prescriptions),
            care_team = SafeData(careTeam),
            encounters = SafeData(encounters),
        };
        return JsonSerializer.Serialize(summary, new JsonSerializerOptions
        {
            DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
            WriteIndented = false,
        });
    }

    private static IReadOnlyList<T> SafeData<T>(CardResult<T> result)
        where T : class
    {
        return result.Error is null ? result.Data : Array.Empty<T>();
    }
}
