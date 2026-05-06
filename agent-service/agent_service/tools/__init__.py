"""Tool-registry primitives for the sidecar chart copilot (M5).

This subpackage owns the data model and registry plumbing for the
agent loop's evidence tools.  It deliberately ships **no** executor
logic, **no** LLM-loop wiring, and **no** real data lookups -- those
land in M6 (executor), M10 / M11 / M12 (real tool implementations),
and M13 (LLM loop).

Public surface
--------------
- :class:`ToolDefinition` / :class:`ToolDefinitionError`
- :class:`ToolRegistry` / :class:`ToolRegistryError` /
  :class:`ToolNotFoundError`
- :func:`default_registry`
- :data:`STUB_TOOLS`
"""

from __future__ import annotations

from agent_service.tools.definition import (
    FORBIDDEN_INPUT_KEYS,
    ToolDefinition,
    ToolDefinitionError,
)
from agent_service.tools.executor import (
    ToolCallOutcome,
    ToolExecutionError,
    execute_tool,
)
from agent_service.tools.registry import (
    ToolNotFoundError,
    ToolRegistry,
    ToolRegistryError,
    default_registry,
)
from agent_service.tools.stubs import STUB_TOOLS, build_stub_tools

# M12 document/lab/intake tools.  Imported at the bottom so the M5
# surface above stays untouched -- the agent loop (M13) is the place
# that composes the patient-evidence registry with the document tool
# registry returned by ``document_tool_registry``.
from agent_service.schemas.proposals import WriteProposal
from agent_service.tools.document_tools import (
    DOCUMENT_TOOL_NAMES,
    CitationLookup,
    build_document_tools,
    document_tool_registry,
)

# M10 read-only patient-evidence tools.  Imported after the M12 imports
# so the M5 surface above stays untouched and the agent loop (M13) can
# compose the M10 + M12 registries on demand.
from agent_service.tools.patient_evidence_tools import (
    PATIENT_EVIDENCE_TOOL_NAMES,
    build_patient_evidence_tools,
    patient_evidence_tool_registry,
)

# M11 source-drilldown tool.  Appended at the bottom so the M5/M6/M10/M12
# surface above stays untouched -- the agent loop (M13) is the place
# that composes the patient-evidence, document, and source-drilldown
# registries on demand.
from agent_service.tools.source_drilldown import (
    SOURCE_DETAIL_BODY_MAX_CHARS,
    SOURCE_DETAIL_TOOL_NAME,
    make_source_detail_tool,
    source_drilldown_tool_registry,
)

__all__ = [
    "DOCUMENT_TOOL_NAMES",
    "FORBIDDEN_INPUT_KEYS",
    "PATIENT_EVIDENCE_TOOL_NAMES",
    "SOURCE_DETAIL_BODY_MAX_CHARS",
    "SOURCE_DETAIL_TOOL_NAME",
    "STUB_TOOLS",
    "CitationLookup",
    "ToolCallOutcome",
    "ToolDefinition",
    "ToolDefinitionError",
    "ToolExecutionError",
    "ToolNotFoundError",
    "ToolRegistry",
    "ToolRegistryError",
    "WriteProposal",
    "build_document_tools",
    "build_patient_evidence_tools",
    "build_stub_tools",
    "default_registry",
    "document_tool_registry",
    "execute_tool",
    "make_source_detail_tool",
    "patient_evidence_tool_registry",
    "source_drilldown_tool_registry",
]
