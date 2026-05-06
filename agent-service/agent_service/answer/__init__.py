"""Answer block schema and response shaping for the chart copilot (M14).

Step M14 of ``Clinical Co-Pilot Migration to Python Sidecar.md`` ports
the PHP answer-block / claim shape from
``src/Services/Agent/AgentEvidenceResponseBuilder.php`` and the
deterministic refusal phrasing from
``src/Services/Agent/AgentLlmOrchestrator.php`` into Python.
"""

from agent_service.answer.builder import (
    REFUSAL_HEADING,
    REFUSAL_PHRASING,
    SAFE_MISSINGNESS_HEADING,
    SAFE_MISSINGNESS_NO_RECORDS,
    SAFE_MISSINGNESS_PROLOGUE,
    RefusalReason,
    ResponseBuilder,
)

__all__ = [
    "REFUSAL_HEADING",
    "REFUSAL_PHRASING",
    "RefusalReason",
    "ResponseBuilder",
    "SAFE_MISSINGNESS_HEADING",
    "SAFE_MISSINGNESS_NO_RECORDS",
    "SAFE_MISSINGNESS_PROLOGUE",
]
