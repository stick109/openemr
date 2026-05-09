namespace OpenEmr.Dashboard.Copilot;

/// <summary>
/// Closed set of Clinical Co-Pilot intents the dashboard exposes. Mirrors the
/// PHP-side <c>OpenEMR\Services\Agent\AgentIntentCatalog</c> so a clinician
/// switching between the legacy patient chart and the modern dashboard sees
/// the same set of one-click prompts. The dashboard runs the prompt against
/// OpenAI directly using the patient's already-loaded FHIR data, so a card
/// works without piggy-backing on OpenEMR's CSRF-bound agent endpoint.
/// </summary>
public enum CopilotIntent
{
    BasicPatientData,
    CurrentMedications,
    AllergiesToConfirm,
    RecentEvents,
    ChangedSinceLastVisit,
}

public static class CopilotIntents
{
    public static readonly IReadOnlyList<(CopilotIntent Id, string Label, string Prompt)> Catalog = new[]
    {
        (CopilotIntent.BasicPatientData,
            "Basic patient data",
            "Summarize who this patient is in 2-3 sentences using only the basic demographics."),
        (CopilotIntent.CurrentMedications,
            "Current medications",
            "List the patient's current medications grouped by indication. Flag overlapping or duplicative therapy. Two sentences max plus a short bulleted list."),
        (CopilotIntent.AllergiesToConfirm,
            "Allergies to confirm",
            "Identify allergies the clinician should reconfirm at this visit (those without a recorded reaction, or with vague substance text). One short bulleted list."),
        (CopilotIntent.RecentEvents,
            "Recent events",
            "Summarize the patient's encounters in the last 90 days. Note any recurring complaint or unresolved issue. Two sentences max."),
        (CopilotIntent.ChangedSinceLastVisit,
            "Changed since last visit",
            "Compare the patient's most recent encounter to the prior one. Call out new or removed problems, new medications, or new allergies. Two sentences max."),
    };

    public static (string Label, string Prompt)? Lookup(string id)
    {
        if (Enum.TryParse<CopilotIntent>(id, ignoreCase: true, out var parsed))
        {
            foreach (var entry in Catalog)
            {
                if (entry.Id == parsed)
                {
                    return (entry.Label, entry.Prompt);
                }
            }
        }
        return null;
    }
}
