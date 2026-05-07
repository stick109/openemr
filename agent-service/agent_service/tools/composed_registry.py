"""Composed production tool registry for the chart copilot (M13 follow-up).

This module owns the *production* wiring of the agent loop's tool surface.
Step M13's :func:`agent_service.api.copilot.get_registry_builder` shipped
with a placeholder builder that returned :func:`default_registry` -- the
M5 inert stubs whose ``executor`` fields are all ``None``. Every LLM tool
call therefore tripped the executor's ``executor_missing`` reason and
the agent returned an empty refused response.

This module composes the three real registries minted in earlier steps:

* :func:`agent_service.tools.patient_evidence_tools.patient_evidence_tool_registry`
  (M10) -- five read-only chart tools backed by the M9 read repository.
* :func:`agent_service.tools.source_drilldown.source_drilldown_tool_registry`
  (M11) -- ``get_source_detail`` drilldown.
* :func:`agent_service.tools.document_tools.document_tool_registry`
  (M12) -- four document/lab/intake tools (extractor, RAG, lab proposal,
  citation region).

Document tools that depend on a configured pipeline / citation index
(``retrieve_guidelines``, ``get_document_citation_region``) are wired
with ``None`` for those resources; M12 already handles ``None`` by
returning a deterministic empty result bag with a typed warning, so the
agent loop's success path is exercisable in production until those
collaborators land in later milestones.

Public surface:

* :func:`compose_production_registry` -- per-context factory that the
  API's :func:`get_registry_builder` closes over a shared repository
  instance.
"""

from __future__ import annotations

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.repository.openemr import OpenEmrReadRepository
from agent_service.tools.document_tools import document_tool_registry
from agent_service.tools.patient_evidence_tools import (
    patient_evidence_tool_registry,
)
from agent_service.tools.registry import ToolRegistry
from agent_service.tools.source_drilldown import source_drilldown_tool_registry


__all__ = ["compose_production_registry"]


def compose_production_registry(
    context: CopilotRunContext,
    *,
    repository: OpenEmrReadRepository,
) -> ToolRegistry:
    """Return a per-context :class:`ToolRegistry` with real executors wired.

    The registry merges the M10 patient-evidence tools, the M11 source
    drilldown tool, and the M12 document tools into a single
    :class:`ToolRegistry`. The ``context`` argument is currently
    unused -- the tools' executors close over the shared repository at
    construction time and already enforce per-call authority via the
    M6 executor + the per-tool ``allowed_source_types`` defense-in-depth
    checks. The signature still accepts ``context`` so the function
    matches the :data:`agent_service.loop.RegistryBuilder` callable type
    and so future iterations can vary the registry by run scope without
    a churn-y signature change.

    The five M10 tools, the single M11 tool, and the four M12 tools all
    have distinct names, so there is no name collision to resolve when
    merging. ``ToolRegistry.register`` raises on duplicates -- if a
    future tool starts colliding, the agent loop will fail loudly at
    request time rather than silently masking a bug.

    Document tools (``retrieve_guidelines``,
    ``get_document_citation_region``) are wired without a pipeline
    factory or citation lookup. M12 already handles a ``None`` for those
    resources by returning an empty result bag with a typed warning, so
    the tool stays advertisable but degrades gracefully until the real
    collaborators are wired (M13/M21).
    """
    del context  # unused today; reserved for per-run registry tailoring.

    composed = ToolRegistry()
    for source in (
        patient_evidence_tool_registry(repository),
        source_drilldown_tool_registry(repository),
        # ``pipeline_factory`` and ``citation_lookup`` intentionally
        # left unset until those collaborators land. M12 gracefully
        # surfaces a typed warning when they are absent.
        document_tool_registry(),
    ):
        for name in source.list_names():
            composed.register(source.get(name))
    return composed
