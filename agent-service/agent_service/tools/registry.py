"""Tool registry for the sidecar chart copilot (M5).

The registry is the single point of truth for which tools the agent
loop is allowed to call.  It separates two concerns intentionally:

- ``ToolDefinition`` instances carry both the model-facing schema *and*
  internal-only metadata (``required_capability``, ``source_types``,
  ``max_rows``, ``executor``).
- :meth:`ToolRegistry.model_facing_schemas` exposes only the model-safe
  subset (``name`` / ``description`` / ``input_schema``) so internal
  policy fields can never leak into LLM prompts or wire responses.

The registry is mutable at construction time only -- once seeded by
``default_registry()`` it is treated as effectively read-only by the
executor (M6).  No global singleton is exported on purpose: each
process / run owns its registry and wires it explicitly to keep
dependency injection clean.
"""

from __future__ import annotations

from collections.abc import Iterable
from typing import Any

from agent_service.tools.definition import ToolDefinition
from agent_service.tools.stubs import build_stub_tools

# ``build_document_tools`` is imported lazily inside ``default_registry``
# to keep the module-level import graph free of cycles -- ``document_tools``
# itself depends on the M5 ``ToolRegistry`` for the ``document_tool_registry``
# helper.

__all__ = [
    "ToolNotFoundError",
    "ToolRegistry",
    "ToolRegistryError",
    "default_registry",
]


class ToolRegistryError(ValueError):
    """Base class for registry-level errors (duplicate registration, etc.)."""


class ToolNotFoundError(KeyError):
    """Raised by :meth:`ToolRegistry.get` when ``name`` is not registered.

    Subclasses :class:`KeyError` so generic dict-style ``except KeyError``
    handlers still work, but call sites that care can pin the exact type.
    """

    def __init__(self, name: str) -> None:
        self.name = name
        super().__init__(f"Tool not registered: {name!r}")


# ---------------------------------------------------------------------------
# Registry
# ---------------------------------------------------------------------------


class ToolRegistry:
    """An ordered collection of unique :class:`ToolDefinition` instances.

    The registry is intentionally tiny: register, lookup, list, and a
    helper for producing the model-facing schema list.  Anything more
    sophisticated (capability filtering, allow-list enforcement, etc.)
    lives in the executor (M6) so the registry stays a pure data store.
    """

    __slots__ = ("_tools",)

    def __init__(self) -> None:
        self._tools: dict[str, ToolDefinition] = {}

    # -- mutation ---------------------------------------------------------

    def register(self, tool: ToolDefinition) -> None:
        """Register ``tool`` under its ``name``.

        Raises
        ------
        ToolRegistryError
            If a tool with the same name is already registered.  Names
            are unique across a registry instance; callers must not
            silently overwrite an existing definition.
        TypeError
            If ``tool`` is not a :class:`ToolDefinition` instance.
        """
        if not isinstance(tool, ToolDefinition):
            raise TypeError(
                f"register() expected ToolDefinition, got {type(tool).__name__}",
            )
        if tool.name in self._tools:
            raise ToolRegistryError(
                f"Tool already registered: {tool.name!r}",
            )
        self._tools[tool.name] = tool

    # -- lookup ----------------------------------------------------------

    def get(self, name: str) -> ToolDefinition:
        """Return the definition registered under ``name``.

        Raises
        ------
        ToolNotFoundError
            If ``name`` is not registered.
        """
        try:
            return self._tools[name]
        except KeyError as exc:
            raise ToolNotFoundError(name) from exc

    def __contains__(self, name: object) -> bool:
        return isinstance(name, str) and name in self._tools

    def __len__(self) -> int:
        return len(self._tools)

    def list_names(self) -> list[str]:
        """Return all registered tool names, sorted alphabetically."""
        return sorted(self._tools)

    # -- model-facing surface --------------------------------------------

    def model_facing_schemas(
        self,
        allowed: Iterable[str] | None = None,
    ) -> list[dict[str, Any]]:
        """Return the model-facing schema list for advertised tools.

        Each entry has exactly three keys: ``name``, ``description``,
        and ``input_schema``.  Internal-only fields (``required_capability``,
        ``source_types``, ``read_only``, ``max_rows``, ``executor``) are
        deliberately omitted so they cannot leak into LLM prompts or
        wire responses.

        Parameters
        ----------
        allowed
            Optional iterable of tool names to include.  When provided,
            output is restricted to that set; unknown names are skipped
            silently (the executor is the place that errors on unknown
            allow-list entries).  Output is always sorted by ``name``.
        """
        if allowed is None:
            names = sorted(self._tools)
        else:
            allowed_set = {name for name in allowed if isinstance(name, str)}
            names = sorted(allowed_set & self._tools.keys())

        return [
            {
                "name": tool.name,
                "description": tool.description,
                "input_schema": tool.input_schema,
            }
            for tool in (self._tools[name] for name in names)
        ]


# ---------------------------------------------------------------------------
# Default factory
# ---------------------------------------------------------------------------


def default_registry() -> ToolRegistry:
    """Return a fresh :class:`ToolRegistry` seeded with the M5 stub tools.

    Each call returns a **new** registry instance so tests and runtime
    code never share mutable state by accident.  The seeded definitions
    come from :func:`agent_service.tools.stubs.build_stub_tools`, which
    in turn covers every PHP intent data class in the current chart
    copilot (basic patient data, current medications, active allergies,
    recent events, changes since last visit, source detail).

    Document tools (M12: ``extract_uploaded_document``,
    ``get_document_citation_region``, ``persist_lab_observation_proposal``,
    ``retrieve_guidelines``) are also seeded so the M21
    ``lab_pdf_extract_and_propose`` intent's ``allowed_tools`` cross-
    validate at boot.  Production wiring still uses
    :func:`agent_service.tools.composed_registry.compose_production_registry`
    for real executors -- this default surface only provides
    name-resolution for the catalog's pre-flight check.
    """
    # Lazy import to avoid a circular import at module load.
    from agent_service.tools.document_tools import build_document_tools  # noqa: PLC0415

    registry = ToolRegistry()
    for tool in build_stub_tools():
        registry.register(tool)
    for tool in build_document_tools():
        registry.register(tool)
    return registry
