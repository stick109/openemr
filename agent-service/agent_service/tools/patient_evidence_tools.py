"""Read-only patient evidence tools for the sidecar agent loop (M10).

This module wires the M5 stub tool definitions (in
:mod:`agent_service.tools.stubs`) up to real executors that read from
the M9 :class:`OpenEmrReadRepository` and emit M8 evidence records and
:class:`agent_service.schemas.copilot.Citation` objects.

Five tools are wired here -- one per PHP intent data class on the chart
copilot button bar:

* ``get_basic_patient_data`` -- patient demographics snapshot.
* ``get_current_medications`` -- active medication list.
* ``get_active_allergies`` -- active allergy list.
* ``get_recent_events`` -- recent encounters in the lookback window.
* ``get_changes_since_last_visit`` -- recent encounters relative to the
  most recent baseline visit (the M9 repository currently shares the
  ``recent_events`` SQL for this; future iterations may diverge).

The M11 ``get_source_detail`` drilldown is intentionally NOT wired here
-- it has its own module so the read-only "list" surface and the
"single-row resolve" surface stay independently testable.

Design notes
------------
* **Repository injected via factory closure.** The M6
  :func:`agent_service.tools.executor.execute_tool` calls each tool's
  executor with ``(context, runtime_args)`` only -- it does not know
  about the repository. To preserve that contract we build each
  :class:`agent_service.tools.definition.ToolDefinition` whose
  ``executor`` is a closure over the repository instance.
  :func:`patient_evidence_tool_registry` is the public factory.

* **Defense in depth.** Every executor checks that
  ``context.allowed_source_types`` covers at least one of the tool's
  declared ``source_types`` before issuing the read. M6 already enforces
  ``tool_not_allowed`` against ``context.allowed_tools``; the source-type
  check here protects against accidentally minted tokens whose
  ``allowed_tools`` and ``allowed_source_types`` are out of sync.

* **Safe missingness phrasing.** Empty repository results yield an
  empty ``records`` tuple, an empty ``citations`` tuple, and a
  per-tool-specific warning string that mirrors the PHP
  :class:`OpenEMR\\Services\\Agent\\AgentIntentPlaceholderResponseBuilder`
  vocabulary so downstream UI / answer-builder text reads consistently
  across the PHP-only and sidecar-served paths.
"""

from __future__ import annotations

import logging
from collections.abc import Callable, Mapping, Sequence
from typing import Any, Final

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.repository.openemr import OpenEmrReadRepository
from agent_service.schemas.copilot import Citation
from agent_service.schemas.evidence import (
    AllergyRecord,
    EventRecord,
    EvidenceSourceType,
    MedicationRecord,
    PatientDemographics,
    ScopeSummary,
)
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry
from agent_service.tools.stubs import build_stub_tools


__all__ = [
    "PATIENT_EVIDENCE_TOOL_NAMES",
    "build_patient_evidence_tools",
    "patient_evidence_tool_registry",
]


_LOGGER: Final[logging.Logger] = logging.getLogger(
    "agent_service.tools.patient_evidence_tools",
)


# Canonical list of tool names this module wires. Used by tests to pin
# the surface and by callers (the M13 agent loop) to compose allow-lists.
PATIENT_EVIDENCE_TOOL_NAMES: Final[tuple[str, ...]] = (
    "get_active_allergies",
    "get_basic_patient_data",
    "get_changes_since_last_visit",
    "get_current_medications",
    "get_recent_events",
)


# Per-tool missingness warning strings. The phrasing mirrors the PHP
# :class:`AgentIntentPlaceholderResponseBuilder` vocabulary -- "X were
# not found in checked evidence" is the verbatim form the verifier
# (``AgentAnswerVerifier::looksLikeMissingness``) is willing to accept,
# and matching that wording lets the answer builder surface these
# warnings without re-phrasing.
_MISSINGNESS_WARNINGS: Final[Mapping[str, str]] = {
    "get_basic_patient_data": (
        "Basic patient demographics were not found in checked evidence."
    ),
    "get_current_medications": (
        "Current medication records were not found in checked evidence."
    ),
    "get_active_allergies": (
        "Current allergy records were not found in checked evidence."
    ),
    "get_recent_events": (
        "Recent encounter events were not found in checked evidence."
    ),
    "get_changes_since_last_visit": (
        "No chart changes were found in checked evidence since the last visit."
    ),
}


# Per-tool source-type tags, used to derive each :class:`Citation`'s
# ``source_type`` field from a record's ``citation_id`` prefix when the
# prefix matches one of the values in :class:`EvidenceSourceType`.
# The fallback on a non-matching prefix is the tool's primary tag.
_TOOL_PRIMARY_SOURCE_TYPE: Final[Mapping[str, str]] = {
    "get_basic_patient_data": "patient_record",
    "get_current_medications": "medications",
    "get_active_allergies": "allergies",
    "get_recent_events": "encounters",
    "get_changes_since_last_visit": "encounters",
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _stub_metadata(name: str) -> ToolDefinition:
    """Return the M5 stub definition for ``name``.

    Used to clone the stub's metadata (``description``, ``input_schema``,
    ``required_capability``, ``source_types``, ``read_only``,
    ``max_rows``) when building the M10 wired tool. Cloning rather than
    re-stating the metadata keeps the stub the single source of truth.
    """
    for stub in build_stub_tools():
        if stub.name == name:
            return stub
    raise ValueError(f"unknown patient-evidence stub tool name: {name!r}")


def _build_tool(
    name: str,
    executor: Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]],
) -> ToolDefinition:
    """Build a :class:`ToolDefinition` from the stub metadata + executor."""
    stub = _stub_metadata(name)
    return ToolDefinition(
        name=stub.name,
        description=stub.description,
        input_schema=stub.input_schema,
        required_capability=stub.required_capability,
        source_types=stub.source_types,
        read_only=stub.read_only,
        max_rows=stub.max_rows,
        executor=executor,
    )


def _has_required_source_types(
    context: CopilotRunContext, tool_source_types: Sequence[str]
) -> bool:
    """Return ``True`` when ``context.allowed_source_types`` covers the tool.

    A tool is allowed to run when the run context's allowed source types
    include at least one of the tool's declared ``source_types``. This is
    the defense-in-depth check described in the module docstring.
    """
    if not tool_source_types:
        return True
    allowed = set(context.allowed_source_types)
    return any(source_type in allowed for source_type in tool_source_types)


def _refusal_payload(
    tool_name: str,
    *,
    source_types: Sequence[str],
    context: CopilotRunContext,
) -> dict[str, Any]:
    """Build the empty result bag returned on a source-type refusal.

    Includes a typed warning so the answer builder can surface the
    refusal honestly rather than silently emitting a "no records" claim.
    """
    return {
        "records": [],
        "citations": [],
        "warnings": [
            f"{tool_name}: run context does not permit reading from any of "
            f"{tuple(source_types)!r}; refusing tool call."
        ],
        "scope": _scope_summary(
            context=context,
            tool_source_types=source_types,
            row_count=0,
            tool_max_rows=0,
            include_lookback=False,
        ),
    }


def _coerce_source_type_enum(prefix: str) -> EvidenceSourceType | None:
    """Map a citation-ID prefix to :class:`EvidenceSourceType` if known."""
    try:
        return EvidenceSourceType(prefix)
    except ValueError:
        return None


def _citation_from_record(
    *,
    record_citation_id: str,
    record_label: str,
    fallback_source_type: str,
) -> Citation:
    """Build a wire-contract :class:`Citation` from an evidence record.

    The citation's ``source_type`` is taken from the citation ID's
    leading segment when that prefix matches one of the
    :class:`EvidenceSourceType` enum values. Otherwise we fall back to
    the tool's primary source-type tag so the wire contract still has a
    plausible value.
    """
    if ":" in record_citation_id:
        prefix, _, _ = record_citation_id.partition(":")
        enum_value = _coerce_source_type_enum(prefix)
        if enum_value is not None:
            source_type = enum_value.value
        else:
            source_type = fallback_source_type
    else:
        source_type = fallback_source_type
    return Citation(
        source_type=source_type,
        source_id=record_citation_id,
        label=record_label,
    )


def _scope_summary(
    *,
    context: CopilotRunContext,
    tool_source_types: Sequence[str],
    row_count: int,
    tool_max_rows: int,
    include_lookback: bool,
) -> ScopeSummary:
    """Build a :class:`ScopeSummary` for the executor's return bag.

    ``truncated`` is set when the row count saturates the row cap; this
    is best-effort because the SQL ``LIMIT`` clause does not surface a
    "more rows existed" flag, but it is correct for the common case.
    """
    source_types_checked: list[EvidenceSourceType] = []
    for entry in tool_source_types:
        enum_value = _coerce_source_type_enum(entry)
        if enum_value is not None and enum_value not in source_types_checked:
            source_types_checked.append(enum_value)
    effective_cap = min(tool_max_rows, context.max_rows) if tool_max_rows > 0 else context.max_rows
    return ScopeSummary(
        patient_id_present=True,
        encounter_id_present=context.encounter_id is not None,
        lookback_days_used=context.lookback_days if include_lookback else None,
        max_rows_used=effective_cap,
        truncated=row_count >= effective_cap > 0,
        source_types_checked=tuple(source_types_checked),
    )


# ---------------------------------------------------------------------------
# Per-tool executors (factories that close over the repository)
# ---------------------------------------------------------------------------


def _make_basic_patient_data_executor(
    repository: OpenEmrReadRepository, *, tool_max_rows: int
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    tool_name = "get_basic_patient_data"
    primary = _TOOL_PRIMARY_SOURCE_TYPE[tool_name]
    source_types: tuple[str, ...] = ("patient_record",)

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        if not _has_required_source_types(context, source_types):
            _LOGGER.info(
                "patient evidence tool refused on source types",
                extra={
                    "trace_id": context.trace_id,
                    "tool_name": tool_name,
                    "reason": "allowed_source_types_mismatch",
                },
            )
            return _refusal_payload(
                tool_name, source_types=source_types, context=context
            )

        record: PatientDemographics | None = repository.get_demographics(
            context=context
        )
        if record is None:
            return {
                "records": [],
                "citations": [],
                "warnings": [_MISSINGNESS_WARNINGS[tool_name]],
                "scope": _scope_summary(
                    context=context,
                    tool_source_types=source_types,
                    row_count=0,
                    tool_max_rows=tool_max_rows,
                    include_lookback=False,
                ),
            }

        records: list[PatientDemographics] = [record]
        label = _basic_patient_data_label(record)
        citations: list[Citation] = [
            _citation_from_record(
                record_citation_id=record.citation_id,
                record_label=label,
                fallback_source_type=primary,
            )
        ]
        return {
            "records": records,
            "citations": citations,
            "warnings": [],
            "scope": _scope_summary(
                context=context,
                tool_source_types=source_types,
                row_count=len(records),
                tool_max_rows=tool_max_rows,
                include_lookback=False,
            ),
        }

    return _executor


def _basic_patient_data_label(record: PatientDemographics) -> str:
    """Return a short, PHI-light label for a demographics citation."""
    parts: list[str] = []
    if record.age is not None:
        parts.append(f"Age {record.age}")
    if record.sex != "unknown":
        parts.append(record.sex.title())
    return ", ".join(parts) if parts else "Patient demographics"


def _make_current_medications_executor(
    repository: OpenEmrReadRepository, *, tool_max_rows: int
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    tool_name = "get_current_medications"
    primary = _TOOL_PRIMARY_SOURCE_TYPE[tool_name]
    source_types: tuple[str, ...] = ("medications",)

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        if not _has_required_source_types(context, source_types):
            _LOGGER.info(
                "patient evidence tool refused on source types",
                extra={
                    "trace_id": context.trace_id,
                    "tool_name": tool_name,
                    "reason": "allowed_source_types_mismatch",
                },
            )
            return _refusal_payload(
                tool_name, source_types=source_types, context=context
            )

        records: list[MedicationRecord] = repository.get_current_medications(
            context=context
        )
        if not records:
            return {
                "records": [],
                "citations": [],
                "warnings": [_MISSINGNESS_WARNINGS[tool_name]],
                "scope": _scope_summary(
                    context=context,
                    tool_source_types=source_types,
                    row_count=0,
                    tool_max_rows=tool_max_rows,
                    include_lookback=True,
                ),
            }

        citations: list[Citation] = [
            _citation_from_record(
                record_citation_id=record.citation_id,
                record_label=record.name,
                fallback_source_type=primary,
            )
            for record in records
        ]
        return {
            "records": records,
            "citations": citations,
            "warnings": [],
            "scope": _scope_summary(
                context=context,
                tool_source_types=source_types,
                row_count=len(records),
                tool_max_rows=tool_max_rows,
                include_lookback=True,
            ),
        }

    return _executor


def _make_active_allergies_executor(
    repository: OpenEmrReadRepository, *, tool_max_rows: int
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    tool_name = "get_active_allergies"
    primary = _TOOL_PRIMARY_SOURCE_TYPE[tool_name]
    source_types: tuple[str, ...] = ("allergies",)

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        if not _has_required_source_types(context, source_types):
            _LOGGER.info(
                "patient evidence tool refused on source types",
                extra={
                    "trace_id": context.trace_id,
                    "tool_name": tool_name,
                    "reason": "allowed_source_types_mismatch",
                },
            )
            return _refusal_payload(
                tool_name, source_types=source_types, context=context
            )

        records: list[AllergyRecord] = repository.get_active_allergies(
            context=context
        )
        if not records:
            return {
                "records": [],
                "citations": [],
                "warnings": [_MISSINGNESS_WARNINGS[tool_name]],
                "scope": _scope_summary(
                    context=context,
                    tool_source_types=source_types,
                    row_count=0,
                    tool_max_rows=tool_max_rows,
                    include_lookback=True,
                ),
            }

        citations: list[Citation] = [
            _citation_from_record(
                record_citation_id=record.citation_id,
                record_label=record.allergen,
                fallback_source_type=primary,
            )
            for record in records
        ]
        return {
            "records": records,
            "citations": citations,
            "warnings": [],
            "scope": _scope_summary(
                context=context,
                tool_source_types=source_types,
                row_count=len(records),
                tool_max_rows=tool_max_rows,
                include_lookback=True,
            ),
        }

    return _executor


def _make_recent_events_executor(
    repository: OpenEmrReadRepository, *, tool_max_rows: int
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    tool_name = "get_recent_events"
    primary = _TOOL_PRIMARY_SOURCE_TYPE[tool_name]
    # The stub advertises a wider source-type set; we accept the call as
    # long as the run context allows at least one of these.
    source_types: tuple[str, ...] = ("encounters", "labs", "vitals", "procedures")

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        if not _has_required_source_types(context, source_types):
            _LOGGER.info(
                "patient evidence tool refused on source types",
                extra={
                    "trace_id": context.trace_id,
                    "tool_name": tool_name,
                    "reason": "allowed_source_types_mismatch",
                },
            )
            return _refusal_payload(
                tool_name, source_types=source_types, context=context
            )

        records: list[EventRecord] = repository.get_recent_events(context=context)
        if not records:
            return {
                "records": [],
                "citations": [],
                "warnings": [_MISSINGNESS_WARNINGS[tool_name]],
                "scope": _scope_summary(
                    context=context,
                    tool_source_types=source_types,
                    row_count=0,
                    tool_max_rows=tool_max_rows,
                    include_lookback=True,
                ),
            }

        citations: list[Citation] = [
            _citation_from_record(
                record_citation_id=record.citation_id,
                record_label=record.title,
                fallback_source_type=primary,
            )
            for record in records
        ]
        return {
            "records": records,
            "citations": citations,
            "warnings": [],
            "scope": _scope_summary(
                context=context,
                tool_source_types=source_types,
                row_count=len(records),
                tool_max_rows=tool_max_rows,
                include_lookback=True,
            ),
        }

    return _executor


def _make_changes_since_last_visit_executor(
    repository: OpenEmrReadRepository, *, tool_max_rows: int
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    tool_name = "get_changes_since_last_visit"
    primary = _TOOL_PRIMARY_SOURCE_TYPE[tool_name]
    source_types: tuple[str, ...] = (
        "medications",
        "allergies",
        "problems",
        "vitals",
        "labs",
    )

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        if not _has_required_source_types(context, source_types):
            _LOGGER.info(
                "patient evidence tool refused on source types",
                extra={
                    "trace_id": context.trace_id,
                    "tool_name": tool_name,
                    "reason": "allowed_source_types_mismatch",
                },
            )
            return _refusal_payload(
                tool_name, source_types=source_types, context=context
            )

        records: list[EventRecord] = repository.get_changes_since_last_visit(
            context=context
        )
        if not records:
            return {
                "records": [],
                "citations": [],
                "warnings": [_MISSINGNESS_WARNINGS[tool_name]],
                "scope": _scope_summary(
                    context=context,
                    tool_source_types=source_types,
                    row_count=0,
                    tool_max_rows=tool_max_rows,
                    include_lookback=True,
                ),
            }

        citations: list[Citation] = [
            _citation_from_record(
                record_citation_id=record.citation_id,
                record_label=record.title,
                fallback_source_type=primary,
            )
            for record in records
        ]
        return {
            "records": records,
            "citations": citations,
            "warnings": [],
            "scope": _scope_summary(
                context=context,
                tool_source_types=source_types,
                row_count=len(records),
                tool_max_rows=tool_max_rows,
                include_lookback=True,
            ),
        }

    return _executor


# ---------------------------------------------------------------------------
# Tool builders
# ---------------------------------------------------------------------------


def _make_basic_patient_data_tool(
    repository: OpenEmrReadRepository,
) -> ToolDefinition:
    stub = _stub_metadata("get_basic_patient_data")
    executor = _make_basic_patient_data_executor(
        repository, tool_max_rows=stub.max_rows
    )
    return _build_tool("get_basic_patient_data", executor)


def _make_current_medications_tool(
    repository: OpenEmrReadRepository,
) -> ToolDefinition:
    stub = _stub_metadata("get_current_medications")
    executor = _make_current_medications_executor(
        repository, tool_max_rows=stub.max_rows
    )
    return _build_tool("get_current_medications", executor)


def _make_active_allergies_tool(
    repository: OpenEmrReadRepository,
) -> ToolDefinition:
    stub = _stub_metadata("get_active_allergies")
    executor = _make_active_allergies_executor(
        repository, tool_max_rows=stub.max_rows
    )
    return _build_tool("get_active_allergies", executor)


def _make_recent_events_tool(
    repository: OpenEmrReadRepository,
) -> ToolDefinition:
    stub = _stub_metadata("get_recent_events")
    executor = _make_recent_events_executor(repository, tool_max_rows=stub.max_rows)
    return _build_tool("get_recent_events", executor)


def _make_changes_since_last_visit_tool(
    repository: OpenEmrReadRepository,
) -> ToolDefinition:
    stub = _stub_metadata("get_changes_since_last_visit")
    executor = _make_changes_since_last_visit_executor(
        repository, tool_max_rows=stub.max_rows
    )
    return _build_tool("get_changes_since_last_visit", executor)


def build_patient_evidence_tools(
    repository: OpenEmrReadRepository,
) -> tuple[ToolDefinition, ...]:
    """Return the immutable tuple of M10 patient-evidence tools.

    Tools are returned in alphabetical name order to match the
    convention established by
    :func:`agent_service.tools.stubs.build_stub_tools`. The agent loop
    (M13) is the place that composes this tuple with M12's document
    tools into a single registry handed to the executor.
    """
    return (
        _make_active_allergies_tool(repository),
        _make_basic_patient_data_tool(repository),
        _make_changes_since_last_visit_tool(repository),
        _make_current_medications_tool(repository),
        _make_recent_events_tool(repository),
    )


def patient_evidence_tool_registry(
    repository: OpenEmrReadRepository,
) -> ToolRegistry:
    """Return a fresh :class:`ToolRegistry` seeded with M10 evidence tools.

    Each call returns a new registry instance. The repository is bound
    into each tool's executor closure at registry-construction time so
    M6's :func:`execute_tool` can keep its single ``(context,
    runtime_args)`` invocation contract without learning about the
    repository.

    Tests pass a ``MagicMock`` repository here; production code passes an
    :class:`OpenEmrReadRepository` constructed via
    :meth:`OpenEmrReadRepository.from_settings`.
    """
    registry = ToolRegistry()
    for tool in build_patient_evidence_tools(repository):
        registry.register(tool)
    return registry
