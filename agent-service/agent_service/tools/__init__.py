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

__all__ = [
    "FORBIDDEN_INPUT_KEYS",
    "STUB_TOOLS",
    "ToolCallOutcome",
    "ToolDefinition",
    "ToolDefinitionError",
    "ToolExecutionError",
    "ToolNotFoundError",
    "ToolRegistry",
    "ToolRegistryError",
    "build_stub_tools",
    "default_registry",
    "execute_tool",
]
