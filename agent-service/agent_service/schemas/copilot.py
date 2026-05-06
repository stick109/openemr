"""Pydantic v2 wire-contract models for ``POST /api/copilot/run``.

These schemas form the stable wire contract between the PHP UI proxy
(see ``src/Services/Agent/Sidecar``) and the Python sidecar's chart
copilot endpoint introduced in step M2 of
``Clinical Co-Pilot Migration to Python Sidecar.md``.

Notes
-----
- ``run_context`` is the **raw signed wire token** as a string. Decoding
  and signature verification are handled by the auth layer added in M3
  / M4 (``agent_service.auth.copilot_run_context``). At the schema layer
  it is opaque.
- All models use ``ConfigDict(extra="forbid", strict=True)`` so unknown
  fields and silent type coercions are rejected at the boundary.
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


# Maximum length of a free-form ``user_goal`` request field.  Cap is enforced
# at the schema boundary so neither the agent loop nor downstream prompts
# ever have to defend against unbounded input.
USER_GOAL_MAX_CHARS: int = 4000


# ---------------------------------------------------------------------------
# Sub-schemas
# ---------------------------------------------------------------------------


class AnswerBlock(BaseModel):
    """A single rendered piece of the copilot response.

    The chart UI walks ``answer_blocks`` in order and renders each one
    according to its ``type``.  Renderers for unknown types should fall
    back to plain text rendering of ``content``.
    """

    model_config = _STRICT

    type: Annotated[
        str,
        Field(
            min_length=1,
            max_length=64,
            description="Renderer hint, e.g. 'paragraph', 'list', 'table', 'callout'.",
        ),
    ]
    content: Annotated[
        str,
        Field(description="Rendered text or structured payload (renderer-specific)."),
    ]
    citation_indices: list[int] = Field(
        default_factory=list,
        description="Indices into the response-level ``citations`` list backing this block.",
    )


class Citation(BaseModel):
    """Pointer back to a source backing some piece of the answer.

    This is the **API-level** citation used in the copilot wire contract.
    It is intentionally simpler than ``schemas.api.Citation`` (the lab
    pipeline's discriminated union) because copilot citations may point
    at OpenEMR rows, guideline chunks, uploaded documents, or other
    sources -- the renderer treats them uniformly.
    """

    model_config = _STRICT

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

    model_config = _STRICT

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
    """Wire contract for ``POST /api/copilot/run`` (request body).

    Fields
    ------
    run_context
        Signed, short-lived wire token minted by PHP and verified by the
        sidecar (see M3 / M4).  Carried as an opaque string at this
        layer.
    intent_id
        Optional closed-set intent identifier (e.g. ``current_medications``).
        Mutually compatible with ``user_goal`` -- at least one must be
        present.
    user_goal
        Optional free-form clinician question.  Hard-capped at
        ``USER_GOAL_MAX_CHARS`` to bound prompt size.
    request_id
        Caller-supplied UUID used for idempotency and trace correlation.
    conversation_state
        Optional opaque round-trip state passed through unchanged.
    """

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
    lists; ``answer_blocks`` is empty only when the verifier refused.
    """

    model_config = _STRICT

    answer_blocks: list[AnswerBlock] = Field(
        description="Ordered list of rendered answer pieces.",
    )
    missing_or_uncertain: list[str] = Field(
        description="Human-readable notes for facts the agent could not confirm.",
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
    "Citation",
    "CopilotRunRequest",
    "CopilotRunResponse",
    "ToolCallRecord",
    "USER_GOAL_MAX_CHARS",
    "VerificationStatus",
]
