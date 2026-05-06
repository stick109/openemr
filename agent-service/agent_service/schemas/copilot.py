"""Pydantic v2 wire-contract models for ``POST /api/copilot/run``.

These schemas form the stable wire contract between the PHP UI proxy
(see ``src/Services/Agent/Sidecar``) and the Python sidecar's chart
copilot endpoint introduced in step M2 of
``Clinical Co-Pilot Migration to Python Sidecar.md``.

M14 brings the answer block / claim / citation shape into alignment with
the existing browser UI in
``interface/patient_file/summary/agent_panel.js`` and the PHP source of
truth in ``src/Services/Agent/AgentEvidenceResponseBuilder.php``. The UI
walks ``answer_blocks[].claims[]`` (each with ``text``, ``citation_ids``,
``certainty``) and renders ``missing_or_uncertain[]`` as objects with
``text`` + ``citation_ids``. M14 also surfaces top-level ``claims``,
``citation_ids``, and ``certainty`` for the verifier (M15).

Notes
-----
- ``run_context`` is the **raw signed wire token** as a string. Decoding
  and signature verification are handled by the auth layer added in M3
  / M4 (``agent_service.auth.copilot_run_context``). At the schema layer
  it is opaque.
- All models use ``ConfigDict(extra="forbid", strict=True)`` so unknown
  fields and silent type coercions are rejected at the boundary. Value
  objects (``AnswerBlock``, ``Claim``, ``Citation``, ``MissingOrUncertain``,
  ``ToolCallRecord``) are additionally ``frozen=True``.
- ``ToolCallRecord`` deliberately stores **argument keys only**, never
  values, so PHI cannot leak through the response envelope.
"""

from __future__ import annotations

from typing import Annotated, Any, Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


# ---------------------------------------------------------------------------
# Shared configuration
# ---------------------------------------------------------------------------


_STRICT = ConfigDict(extra="forbid", strict=True)
_STRICT_FROZEN = ConfigDict(extra="forbid", strict=True, frozen=True)


# Maximum length of a free-form ``user_goal`` request field.  Cap is enforced
# at the schema boundary so neither the agent loop nor downstream prompts
# ever have to defend against unbounded input.
USER_GOAL_MAX_CHARS: int = 4000


# Closed-set certainty markers as understood by the answer builder, the
# verifier, and the chart UI.
Certainty = Literal[
    "high",
    "medium",
    "low",
    "unknown",
    "active",
    "inactive",
    "conflicting",
    "not_found",
    "not_checked",
    "supported",
    "source_record",
]


# ---------------------------------------------------------------------------
# Sub-schemas
# ---------------------------------------------------------------------------


class Claim(BaseModel):
    """A single factual claim backed by zero or more citations.

    The verifier (M15) checks that every claim with content has at least
    one citation ID that resolves to a known source. Claims with empty
    ``citation_ids`` are reserved for safe-missingness messaging
    ("we did not find any X in the checked evidence").
    """

    model_config = _STRICT_FROZEN

    text: Annotated[
        str,
        Field(min_length=1, description="Short factual statement (already PHI-safe and HTML-escaped)."),
    ]
    citation_ids: list[str] = Field(
        default_factory=list,
        description="Citation IDs backing this claim; empty only for safe-missingness or refusal claims.",
    )
    certainty: Certainty = Field(
        description="Certainty marker consumed by the verifier and the UI.",
    )


class AnswerBlock(BaseModel):
    """A single rendered piece of the copilot response.

    Wire shape mirrors ``AgentEvidenceResponseBuilder::answerFromPacket``
    in PHP and the renderer in ``agent_panel.js`` (which iterates
    ``answer_blocks[].claims[]`` and pulls ``heading``).
    """

    model_config = _STRICT_FROZEN

    heading: Annotated[
        str,
        Field(min_length=1, description="Block heading shown above the claim list."),
    ]
    claims: list[Claim] = Field(
        description="Ordered list of factual claims rendered as a bullet list in the UI.",
    )
    body_markdown: str | None = Field(
        default=None,
        description="Optional inline notes rendered below the claims (HTML-escaped).",
    )


class MissingOrUncertain(BaseModel):
    """A 'what we did not find' note rendered alongside the answer.

    Wire shape mirrors the objects produced by
    ``AgentEvidenceResponseBuilder::addBasicPatientDataMissingness``: a
    short text plus optional citation IDs pointing at why the gap is
    known (e.g. a review marker that confirms the chart was inspected).
    """

    model_config = _STRICT_FROZEN

    text: Annotated[
        str,
        Field(min_length=1, description="Short human-readable missingness note (HTML-escaped)."),
    ]
    citation_ids: list[str] = Field(
        default_factory=list,
        description="Citation IDs explaining why the gap is known; empty when none apply.",
    )


class Citation(BaseModel):
    """Pointer back to a source backing some piece of the answer.

    This is the **API-level** citation used in the copilot wire contract.
    It is intentionally simpler than ``schemas.api.Citation`` (the lab
    pipeline's discriminated union) because copilot citations may point
    at OpenEMR rows, guideline chunks, uploaded documents, or other
    sources -- the renderer treats them uniformly.
    """

    model_config = _STRICT_FROZEN

    source_type: Annotated[
        str,
        Field(
            min_length=1,
            max_length=64,
            description="Citation source taxonomy, e.g. 'guideline', 'patient_record', 'document'.",
        ),
    ]
    source_id: Annotated[
        str,
        Field(min_length=1, description="Stable identifier for the source within ``source_type``."),
    ]
    label: Annotated[
        str,
        Field(min_length=1, description="Human-readable label shown next to the citation."),
    ]
    url: str | None = Field(
        default=None,
        description="Optional canonical URL or drill-down deeplink.",
    )
    snippet: str | None = Field(
        default=None,
        description="Optional verbatim excerpt from the source (already PHI-safe).",
    )


class ToolCallRecord(BaseModel):
    """PHI-safe trace entry for a single tool invocation in the agent loop.

    Crucially, ``arguments_keys`` records only the **keys** of the model's
    tool-call arguments -- never the values.  This guarantees the response
    envelope is safe to log, ship to observability backends, and surface
    in admin UIs without leaking patient data or free-text query content.
    """

    model_config = _STRICT_FROZEN

    tool_name: Annotated[str, Field(min_length=1, max_length=128)]
    arguments_keys: list[str] = Field(
        default_factory=list,
        description="Sorted/whitelisted keys of the model-supplied arguments. Values omitted.",
    )
    result_count: int | None = Field(
        default=None,
        ge=0,
        description="Row/result count returned by the tool, when meaningful.",
    )
    latency_ms: Annotated[int, Field(ge=0, description="Wall-clock latency of the tool call in milliseconds.")]
    error_class: str | None = Field(
        default=None,
        description="Fully-qualified exception class if the tool failed; null on success.",
    )


# ---------------------------------------------------------------------------
# Request
# ---------------------------------------------------------------------------


class CopilotRunRequest(BaseModel):
    """Wire contract for ``POST /api/copilot/run`` (request body)."""

    model_config = _STRICT

    run_context: Annotated[
        str,
        Field(min_length=1, description="Signed wire token (M3 mints, M4 verifies)."),
    ]
    intent_id: str | None = Field(
        default=None,
        description="Closed-set intent ID; required if ``user_goal`` is omitted.",
    )
    user_goal: str | None = Field(
        default=None,
        max_length=USER_GOAL_MAX_CHARS,
        description="Free-form clinician goal text; required if ``intent_id`` is omitted.",
    )
    request_id: Annotated[
        str,
        Field(min_length=1, description="Caller-supplied UUID for idempotency/correlation."),
    ]
    conversation_state: dict[str, Any] | None = Field(
        default=None,
        description="Opaque round-trip state echoed unchanged by the sidecar.",
    )

    @model_validator(mode="after")
    def _require_intent_or_goal(self) -> CopilotRunRequest:
        """Reject requests that supply neither ``intent_id`` nor ``user_goal``."""
        intent_present = self.intent_id is not None and self.intent_id.strip() != ""
        goal_present = self.user_goal is not None and self.user_goal.strip() != ""

        if not (intent_present or goal_present):
            raise ValueError(
                "CopilotRunRequest requires at least one of 'intent_id' or 'user_goal'.",
            )

        return self


# ---------------------------------------------------------------------------
# Response
# ---------------------------------------------------------------------------


VerificationStatus = Literal["passed", "refused", "error"]


class CopilotRunResponse(BaseModel):
    """Wire contract for ``POST /api/copilot/run`` (success body).

    All fields are required so downstream renderers can assume a stable
    shape.  ``missing_or_uncertain`` and ``citations`` may be empty
    lists; ``answer_blocks`` is empty only when the verifier refused
    and no claims survived.

    M14 surfaces three new top-level fields backed by the M14 builder:
    ``claims`` (the union of all per-block claims, ordered), ``citation_ids``
    (the deduplicated, sorted union of all citation IDs referenced by any
    claim), and ``certainty`` (the overall certainty bucket the M15
    verifier inspects).
    """

    model_config = _STRICT

    answer_blocks: list[AnswerBlock] = Field(
        description="Ordered list of rendered answer pieces.",
    )
    claims: list[Claim] = Field(
        description="Ordered union of every claim across answer_blocks; verifier-facing.",
    )
    citation_ids: list[str] = Field(
        description="Deduplicated, sorted union of all citation IDs referenced by claims.",
    )
    certainty: Literal["high", "medium", "low", "unknown"] = Field(
        description="Overall certainty bucket the verifier inspects.",
    )
    missing_or_uncertain: list[MissingOrUncertain] = Field(
        description="Notes for facts the agent could not confirm.",
    )
    citations: list[Citation] = Field(
        description="Sources backing the answer blocks.",
    )
    tool_sequence: list[ToolCallRecord] = Field(
        description="PHI-safe trace of tool calls made by the agent loop.",
    )
    verification_status: VerificationStatus = Field(
        description="Result of the answer verifier: 'passed', 'refused', or 'error'.",
    )
    cost_usd: Annotated[
        float,
        Field(ge=0.0, description="Estimated cost of this run in USD."),
    ]
    latency_ms_per_step: dict[str, int] = Field(
        description="Wall-clock latency per pipeline step (ms).",
    )
    trace_id: Annotated[
        str,
        Field(min_length=1, description="UUID v4 correlation ID echoed back to the caller."),
    ]


__all__ = [
    "AnswerBlock",
    "Certainty",
    "Citation",
    "Claim",
    "CopilotRunRequest",
    "CopilotRunResponse",
    "MissingOrUncertain",
    "ToolCallRecord",
    "USER_GOAL_MAX_CHARS",
    "VerificationStatus",
]
