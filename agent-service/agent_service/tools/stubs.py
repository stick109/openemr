"""Inert stub ``ToolDefinition`` instances for the chart copilot (M5).

These definitions advertise the *shape* of every read-only patient
evidence tool the agent loop will eventually call.  Real executors are
deliberately ``None`` here -- M6 ships the policy-enforced executor
shell, M10 / M11 / M12 wire the actual data lookups against the OpenEMR
schema.

One stub exists for each PHP intent data class in the current chart
copilot (``allergies_to_confirm``, ``basic_patient_data``,
``changed_since_last_visit``, ``current_medications``, ``recent_events``,
``show_source``).  Stub names track the action verb used by callers,
e.g. ``get_current_medications``, so the LLM-facing schema reads as a
lookup verb rather than as an intent identifier.

Source-type tags follow the citation taxonomy already exercised by the
parity fixtures under ``tests/fixtures/copilot_parity/`` and the
``Citation.source_type`` contract in ``schemas/copilot.py``.
"""

from __future__ import annotations

from typing import Any, Final

from agent_service.tools.definition import ToolDefinition

__all__ = [
    "STUB_TOOLS",
    "build_stub_tools",
]


# ---------------------------------------------------------------------------
# Schema helpers
# ---------------------------------------------------------------------------


def _empty_object_schema() -> dict[str, Any]:
    """Return a JSON Schema that accepts an empty argument object.

    Patient/encounter/lookback scoping is injected by the executor from
    the run context, so most evidence tools take **no** model-supplied
    arguments.  ``additionalProperties`` is set to ``False`` so the
    model cannot smuggle in unexpected keys.
    """
    return {
        "type": "object",
        "properties": {},
        "additionalProperties": False,
    }


def _source_detail_schema() -> dict[str, Any]:
    """Return the schema for ``get_source_detail``.

    The model supplies a ``citation_id`` (the opaque ID of a citation
    previously surfaced in this conversation).  Authorization that this
    citation belongs to the current patient is enforced by the executor
    via the run context -- the model never names a patient.
    """
    return {
        "type": "object",
        "properties": {
            "citation_id": {
                "type": "string",
                "minLength": 1,
                "maxLength": 256,
                "description": (
                    "Opaque identifier of a citation previously emitted "
                    "by the agent. The executor verifies it belongs to "
                    "the current run context."
                ),
            },
        },
        "required": ["citation_id"],
        "additionalProperties": False,
    }


# ---------------------------------------------------------------------------
# Stub builders
# ---------------------------------------------------------------------------


def build_stub_tools() -> tuple[ToolDefinition, ...]:
    """Construct the immutable tuple of stub tool definitions.

    A function (rather than a module-level constant) is exposed so call
    sites can re-build a fresh tuple in tests or when a future hot-reload
    path needs to.  The returned tuple is ordered alphabetically by name
    for determinism.
    """
    return (
        ToolDefinition(
            name="get_active_allergies",
            description=(
                "Return the patient's active allergy list with reaction, "
                "severity, and onset metadata."
            ),
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("allergies",),
            read_only=True,
            max_rows=50,
            executor=None,
        ),
        ToolDefinition(
            name="get_basic_patient_data",
            description=(
                "Return the patient's demographics and high-level chart "
                "summary (age, sex, problem-list highlights)."
            ),
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("patient_record",),
            read_only=True,
            max_rows=1,
            executor=None,
        ),
        ToolDefinition(
            name="get_changes_since_last_visit",
            description=(
                "Return chart changes recorded since the most recent "
                "encounter for this patient."
            ),
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=(
                "medications",
                "allergies",
                "problems",
                "vitals",
                "labs",
            ),
            read_only=True,
            max_rows=100,
            executor=None,
        ),
        ToolDefinition(
            name="get_current_medications",
            description=(
                "Return the patient's active medication list with dose, "
                "route, and prescriber metadata."
            ),
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=None,
        ),
        ToolDefinition(
            name="get_recent_events",
            description=(
                "Return clinically relevant events recorded in the patient's "
                "chart in the recent lookback window."
            ),
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=(
                "encounters",
                "labs",
                "vitals",
                "procedures",
            ),
            read_only=True,
            max_rows=200,
            executor=None,
        ),
        ToolDefinition(
            name="get_source_detail",
            description=(
                "Return the verbatim source row backing a previously "
                "surfaced citation, scoped to the current run context."
            ),
            input_schema=_source_detail_schema(),
            required_capability="read_basic_patient_data",
            source_types=(
                "medications",
                "allergies",
                "problems",
                "vitals",
                "labs",
                "encounters",
                "procedures",
                "patient_record",
                "document",
            ),
            read_only=True,
            max_rows=1,
            executor=None,
        ),
    )


# Public, frozen tuple of stubs used by ``default_registry()``.
STUB_TOOLS: Final[tuple[ToolDefinition, ...]] = build_stub_tools()
