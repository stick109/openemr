"""Source-drilldown tool for the sidecar agent loop (M11).

This module wires the real executor for ``get_source_detail`` -- the tool
the LLM uses to look up the bounded detail of a single previously-cited
source.  The tool's contract is documented in step M11 of
``Clinical Co-Pilot Migration to Python Sidecar.md``:

* The model supplies only an opaque ``source_id`` of the form
  ``<source_type>:<table>:<record_id>``.  Patient identity, encounter
  scope, and capability gates are injected from the verified
  :class:`CopilotRunContext` -- the model never names a patient.
* The executor parses the ``source_id``, validates it against the run's
  ``allowed_source_types``, and delegates to
  :meth:`OpenEmrReadRepository.get_source_detail` which enforces the
  cross-patient guard at the SQL layer.
* The tool returns a bounded result bag
  (``{"records": [...], "citations": [...], "warnings": [...]}``).
  Bodies longer than :data:`SOURCE_DETAIL_BODY_MAX_CHARS` are truncated
  with a warning so the model context stays bounded.

The module exposes:

* :func:`source_drilldown_tool_registry` -- factory that returns a fresh
  :class:`ToolRegistry` containing only ``get_source_detail`` wired to a
  given :class:`OpenEmrReadRepository`.
* :func:`make_source_detail_tool` -- the underlying
  :class:`ToolDefinition` factory for callers that want to compose
  registries themselves (M13 will use this to merge with M10's
  patient-evidence registry and M12's document registry).
* :data:`SOURCE_DETAIL_TOOL_NAME` and
  :data:`SOURCE_DETAIL_BODY_MAX_CHARS` -- the canonical tool name and
  body cap, exported so tests pin the contract.
"""

from __future__ import annotations

import logging
from collections.abc import Mapping
from typing import Any, Final

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.repository import OpenEmrReadRepository, parse_source_id
from agent_service.schemas.copilot import Citation
from agent_service.schemas.evidence import EvidenceSourceDetail, EvidenceSourceType
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry

__all__ = [
    "SOURCE_DETAIL_BODY_MAX_CHARS",
    "SOURCE_DETAIL_TOOL_NAME",
    "make_source_detail_tool",
    "source_drilldown_tool_registry",
]


_LOGGER: Final[logging.Logger] = logging.getLogger("agent_service.tools.source_drilldown")


# Canonical tool name advertised to the LLM.  Kept as a module-level
# constant so tests can assert on the wire surface and so other M13
# composition code can refer to it without re-encoding the string.
SOURCE_DETAIL_TOOL_NAME: Final[str] = "get_source_detail"


# Maximum length of the ``EvidenceSourceDetail.body`` field returned to
# the model.  The repository (M9) does not enforce a cap, so M11 owns
# the responsibility of keeping the model context bounded.  4000 chars
# is roughly the size of a single short clinical note and matches the
# bound the PHP layer applies before shipping a source row to the chart UI.
SOURCE_DETAIL_BODY_MAX_CHARS: Final[int] = 4000


# ---------------------------------------------------------------------------
# Schema
# ---------------------------------------------------------------------------


def _source_detail_schema() -> dict[str, Any]:
    """Schema for ``get_source_detail``.

    The model supplies a single ``source_id`` of the form
    ``<source_type>:<table>:<record_id>``.  The pattern is enforced
    structurally here so most malformed inputs are rejected by the M6
    executor's schema-validation pass before the executor body runs.
    """
    return {
        "type": "object",
        "properties": {
            "source_id": {
                "type": "string",
                "pattern": r"^[a-z_]+:[a-z_]+:[0-9]+$",
                "minLength": 5,
                "maxLength": 256,
                "description": (
                    "Opaque citation/source ID of the form "
                    "'<source_type>:<table>:<record_id>'.  The executor "
                    "verifies the ID belongs to the current patient and "
                    "to a source type the run is allowed to read."
                ),
            },
        },
        "required": ["source_id"],
        "additionalProperties": False,
    }


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _source_type_label(source_type: EvidenceSourceType) -> str:
    """Return a short human-readable label for a citation source-type bucket.

    The PHP normalizer's source labels are not stored in the database --
    they are derived from the source-type bucket plus the row's display
    fields.  We mirror the same convention here so the citation we emit
    alongside the detail row matches the labels surfaced elsewhere.
    """
    return source_type.value.replace("_", " ").title()


def _detail_to_citation(detail: EvidenceSourceDetail) -> Citation:
    """Build the :class:`Citation` that backs an :class:`EvidenceSourceDetail`.

    The wire-contract :class:`Citation` model lives in
    ``agent_service.schemas.copilot`` (introduced in M2).  M11 emits one
    citation per resolved source so the answer builder (M14) can carry
    it verbatim without re-deriving anything.
    """
    return Citation(
        source_type=detail.source_type.value,
        source_id=detail.source_id,
        label=detail.label,
        snippet=detail.body if detail.body else None,
    )


def _truncate_body(body: str) -> tuple[str, bool]:
    """Cap ``body`` at :data:`SOURCE_DETAIL_BODY_MAX_CHARS`.

    Returns the (possibly truncated) body and a boolean indicating
    whether truncation happened.  The truncation is byte-cheap (we just
    slice the string) because the body is already a short clinical
    summary by the time it reaches this layer.
    """
    if len(body) <= SOURCE_DETAIL_BODY_MAX_CHARS:
        return body, False
    return body[:SOURCE_DETAIL_BODY_MAX_CHARS], True


# ---------------------------------------------------------------------------
# Executor
# ---------------------------------------------------------------------------


def _get_source_detail_executor(
    context: CopilotRunContext,
    runtime_args: Mapping[str, Any],
    *,
    repository: OpenEmrReadRepository,
) -> dict[str, Any]:
    """Resolve a single citation/source row for the model.

    Validation chain (each step short-circuits with an empty result bag
    plus a typed warning):

    1. ``source_id`` must be a non-empty string with the
       ``<source_type>:<table>:<record_id>`` shape.  M6's input-schema
       pass already rejects most malformed values; this check defends
       in depth.
    2. The parsed ``source_type`` must be in
       ``context.allowed_source_types``.  The executor (M6) does not
       enforce per-argument source-type filtering, so M11 owns this
       check.
    3. :meth:`OpenEmrReadRepository.get_source_detail` performs the
       cross-patient guard at the SQL layer; ``None`` from the
       repository means "not found, wrong patient, or unsupported
       table" -- the executor surfaces all three as the same shape so
       the model cannot distinguish.

    On success the returned bag has exactly one record (the
    :class:`EvidenceSourceDetail` serialised via Pydantic) and one
    citation.  Long bodies are truncated to
    :data:`SOURCE_DETAIL_BODY_MAX_CHARS` with a typed warning so the
    answer builder (M14) can surface the truncation to the UI if
    desired.
    """
    source_id_raw = runtime_args.get("source_id")
    if not isinstance(source_id_raw, str) or source_id_raw == "":
        return {
            "records": [],
            "citations": [],
            "warnings": [
                "get_source_detail: 'source_id' is missing or not a non-empty string."
            ],
        }
    parsed = parse_source_id(source_id_raw)
    if parsed is None:
        return {
            "records": [],
            "citations": [],
            "warnings": [
                "get_source_detail: 'source_id' is malformed; expected "
                "'<source_type>:<table>:<record_id>'."
            ],
        }

    if parsed.source_type not in context.allowed_source_types:
        _LOGGER.info(
            "source-drilldown rejected by allowed_source_types",
            extra={
                "trace_id": context.trace_id,
                "source_type": parsed.source_type,
            },
        )
        return {
            "records": [],
            "citations": [],
            "warnings": [
                "get_source_detail: source type not allowed in this run context."
            ],
        }

    detail = repository.get_source_detail(context=context, source_id=source_id_raw)
    if detail is None:
        _LOGGER.info(
            "source-drilldown not resolved",
            extra={
                "trace_id": context.trace_id,
                "source_type": parsed.source_type,
                "table": parsed.table,
            },
        )
        return {
            "records": [],
            "citations": [],
            "warnings": [
                "get_source_detail: source not found, not in scope, or belongs "
                "to a different patient."
            ],
        }

    body, truncated = _truncate_body(detail.body)
    warnings: list[str] = []
    if truncated:
        warnings.append(
            f"get_source_detail: body truncated to {SOURCE_DETAIL_BODY_MAX_CHARS} chars."
        )
        # Rebuild the detail with the truncated body so the wire payload
        # honours the cap.  ``EvidenceSourceDetail`` is frozen, so we
        # rebuild via ``model_copy`` rather than mutating in place.
        detail = detail.model_copy(update={"body": body})

    citation = _detail_to_citation(detail)
    return {
        "records": [detail.model_dump(mode="json")],
        "citations": [citation],
        "warnings": warnings,
    }


# ---------------------------------------------------------------------------
# Tool builder
# ---------------------------------------------------------------------------


def make_source_detail_tool(repository: OpenEmrReadRepository) -> ToolDefinition:
    """Return the :class:`ToolDefinition` for ``get_source_detail``.

    Parameters
    ----------
    repository
        The configured :class:`OpenEmrReadRepository` to delegate to.
        Wiring the dependency through the factory keeps the tool
        definition free of module-level globals and makes the
        cross-patient guard easy to fake in tests.
    """

    def _executor(
        context: CopilotRunContext, runtime_args: Mapping[str, Any]
    ) -> dict[str, Any]:
        return _get_source_detail_executor(
            context, runtime_args, repository=repository
        )

    return ToolDefinition(
        name=SOURCE_DETAIL_TOOL_NAME,
        description=(
            "Look up the bounded detail for a single citation/source "
            "belonging to the current patient.  The model supplies an "
            "opaque source_id; the executor verifies the source type is "
            "allowed and the row belongs to the current patient."
        ),
        input_schema=_source_detail_schema(),
        required_capability="read_source_detail",
        # Any registered source type is admissible at the definition
        # level; the executor enforces the per-call allow-list using
        # ``context.allowed_source_types``.
        source_types=tuple(t.value for t in EvidenceSourceType),
        read_only=True,
        max_rows=1,
        executor=_executor,
    )


# ---------------------------------------------------------------------------
# Registry factory
# ---------------------------------------------------------------------------


def source_drilldown_tool_registry(
    repository: OpenEmrReadRepository,
) -> ToolRegistry:
    """Return a fresh :class:`ToolRegistry` seeded with ``get_source_detail``.

    The registry contains only the source-drilldown tool.  M13 composes
    this with the patient-evidence registry (M10) and the document
    registry (M12) when assembling the agent loop's final tool list.
    """
    registry = ToolRegistry()
    registry.register(make_source_detail_tool(repository))
    return registry
