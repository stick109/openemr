"""Tool-definition primitives for the sidecar agent loop (M5).

This module introduces ``ToolDefinition``: an immutable, value-object
description of a single read-only patient evidence tool the agent can
call.  The shape mirrors the contract documented in
``Clinical Co-Pilot Migration to Python Sidecar.md`` step M5:

- A model-facing surface (``name``, ``description``, ``input_schema``)
  used to advertise tools to the LLM.
- An internal-only surface (``required_capability``, ``source_types``,
  ``read_only``, ``max_rows``, ``executor``) used by the policy-enforced
  executor (M6) and never shipped to the model.

Design notes
------------
- Frozen dataclass is used (not Pydantic) because:
    * ``executor`` is an arbitrary callable, not a serializable type.
    * The class is an internal value object, not a wire contract.
    * ``frozen=True``/``slots=True`` cheaply gives the immutability and
      ``final readonly`` semantics required by ``CLAUDE.md``.
- All construction-time validation runs in ``__post_init__`` so that
  invalid tool definitions cannot be registered.  Validation failures
  raise ``ToolDefinitionError`` -- a typed, package-local exception so
  tests and call sites can pin behaviour without catching ``ValueError``.
- The forbidden-input-keys list is the single enforcement point for
  step M5's pass criterion: model-supplied arguments must never carry
  ``patient_id``, ``encounter_id``, SQL strings, or filesystem paths.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Any, Final

__all__ = [
    "FORBIDDEN_INPUT_KEYS",
    "ToolDefinition",
    "ToolDefinitionError",
]


class ToolDefinitionError(ValueError):
    """Raised when a ``ToolDefinition`` is constructed with invalid metadata.

    Subclasses :class:`ValueError` so generic ``except ValueError`` blocks
    still catch it, but call sites that care about tool-definition
    failures specifically can pin the exact type.
    """


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------


# Snake_case identifier pattern enforced for ``ToolDefinition.name``.
# Must start with a lowercase letter, contain only lowercase letters /
# digits / underscores, and be at most 64 characters long.  This keeps
# tool names safe to use as JSON keys, log fields, and Python identifiers.
_NAME_PATTERN: Final[re.Pattern[str]] = re.compile(r"^[a-z][a-z0-9_]{0,63}$")


# Top-level ``input_schema.properties`` keys that must NEVER be
# model-supplied.  These are either patient-scoping identifiers (which
# the executor injects from the run context) or unsafe surfaces (raw
# SQL, filesystem paths, free-form queries).
FORBIDDEN_INPUT_KEYS: Final[frozenset[str]] = frozenset(
    {
        "patient_id",
        "encounter_id",
        "document_id",
        "mrn",
        "path",
        "file_path",
        "sql",
        "query",
        "query_string",
    },
)


# ---------------------------------------------------------------------------
# Tool definition
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class ToolDefinition:
    """Immutable description of a single agent-callable tool.

    Attributes
    ----------
    name
        Snake_case identifier matching ``^[a-z][a-z0-9_]{0,63}$``.
    description
        Short, model-facing prose describing what the tool returns.
    input_schema
        JSON-Schema-style ``dict`` describing the **model-supplied**
        arguments.  Patient/encounter scoping must NOT appear here --
        those come from the verified run context.
    required_capability
        Capability gate the run context must carry for this tool to be
        callable (e.g. ``"read_basic_patient_data"``).
    source_types
        Source-taxonomy tags this tool emits citations for (e.g.
        ``("medications",)``).  Used by the executor and citation
        layer to enforce per-run ``allowed_source_types``.
    read_only
        ``True`` for evidence tools.  M5 only ships read-only stubs.
    max_rows
        Hard upper bound on the number of rows the tool will return.
        Must be strictly positive.
    executor
        Callable that performs the actual lookup, or ``None`` for stub
        registrations.  Real executors are wired by M6 / M10 / M11 / M12.
    """

    name: str
    description: str
    input_schema: dict[str, Any]
    required_capability: str
    source_types: tuple[str, ...]
    read_only: bool
    max_rows: int
    executor: Callable[..., Any] | None = field(default=None)

    def __post_init__(self) -> None:
        self._validate_name(self.name)
        self._validate_description(self.description)
        self._validate_input_schema(self.input_schema)
        self._validate_required_capability(self.required_capability)
        self._validate_source_types(self.source_types)
        self._validate_max_rows(self.max_rows)
        if self.executor is not None and not callable(self.executor):
            raise ToolDefinitionError(
                "executor must be callable or None, got non-callable value",
            )

    # -- validators ---------------------------------------------------------

    @staticmethod
    def _validate_name(name: str) -> None:
        if not isinstance(name, str):
            raise ToolDefinitionError("name must be a string")
        if not _NAME_PATTERN.match(name):
            raise ToolDefinitionError(
                f"name must match {_NAME_PATTERN.pattern!r}, got {name!r}",
            )

    @staticmethod
    def _validate_description(description: str) -> None:
        if not isinstance(description, str):
            raise ToolDefinitionError("description must be a string")
        stripped = description.strip()
        if stripped == "":
            raise ToolDefinitionError("description must be a non-empty string")
        if len(description) > 1024:
            raise ToolDefinitionError(
                f"description must be <= 1024 chars, got {len(description)}",
            )

    @staticmethod
    def _validate_input_schema(schema: dict[str, Any]) -> None:
        if not isinstance(schema, dict):
            raise ToolDefinitionError("input_schema must be a dict")

        schema_type = schema.get("type")
        if schema_type != "object":
            raise ToolDefinitionError(
                f"input_schema.type must be 'object', got {schema_type!r}",
            )

        properties = schema.get("properties", {})
        if not isinstance(properties, dict):
            raise ToolDefinitionError(
                "input_schema.properties must be a dict if present",
            )

        forbidden_present = sorted(
            key for key in properties if key in FORBIDDEN_INPUT_KEYS
        )
        if forbidden_present:
            raise ToolDefinitionError(
                "input_schema may not declare forbidden model-supplied "
                f"properties: {forbidden_present!r}. Patient/encounter "
                "scoping is injected from the run context, and SQL / "
                "filesystem inputs are never model-supplied.",
            )

        # ``required`` must be a list of strings, all of which appear in
        # ``properties`` (so we don't ship malformed JSON Schema to the model).
        required = schema.get("required", [])
        if not isinstance(required, list):
            raise ToolDefinitionError("input_schema.required must be a list if present")
        for entry in required:
            if not isinstance(entry, str):
                raise ToolDefinitionError(
                    f"input_schema.required entries must be strings, got {type(entry).__name__}",
                )
            if entry not in properties:
                raise ToolDefinitionError(
                    f"input_schema.required references unknown property {entry!r}",
                )

    @staticmethod
    def _validate_required_capability(capability: str) -> None:
        if not isinstance(capability, str):
            raise ToolDefinitionError("required_capability must be a string")
        if capability.strip() == "":
            raise ToolDefinitionError("required_capability must be non-empty")

    @staticmethod
    def _validate_source_types(source_types: tuple[str, ...]) -> None:
        if not isinstance(source_types, tuple):
            raise ToolDefinitionError("source_types must be a tuple")
        for entry in source_types:
            if not isinstance(entry, str) or entry.strip() == "":
                raise ToolDefinitionError(
                    "source_types entries must be non-empty strings",
                )

    @staticmethod
    def _validate_max_rows(max_rows: int) -> None:
        if not isinstance(max_rows, int) or isinstance(max_rows, bool):
            raise ToolDefinitionError("max_rows must be an int")
        if max_rows <= 0:
            raise ToolDefinitionError(
                f"max_rows must be strictly positive, got {max_rows}",
            )
