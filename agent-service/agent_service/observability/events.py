"""Per-tool-call observability event schema (M16).

This module extends the S25 ``RunRecord`` observability subsystem with a
finer-grained event span.  Where ``RunRecord`` summarises one whole
graph invocation as a single document, :class:`RunEvent` captures the
individual phases of the M13 agent loop:

* ``run.received`` -- the loop accepted a verified run context.
* ``model.turn.started`` / ``model.turn.finished`` -- the loop called
  the LLM tool-choice client.
* ``tool.started`` / ``tool.finished`` -- the loop dispatched a tool
  through the M6 policy executor.
* ``verifier.finished`` -- the M15 answer verifier ran.
* ``response.returned`` -- the loop returned the final wire envelope.

Every event flows through :func:`observability._phi_scanner.scan_event_field_for_phi`
so SSN / ``Patient: <name>`` / email / phone / address patterns cannot
be persisted even if a misconfigured caller hands free-form text into a
field.  The scanner is shared with :class:`RunRecord` so a single fix
to a pattern updates every downstream sink.
"""

from __future__ import annotations

from datetime import datetime
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

from agent_service.observability._phi_scanner import scan_event_field_for_phi


# ---------------------------------------------------------------------------
# Closed-set discriminators
# ---------------------------------------------------------------------------


EventType = Literal[
    "run.received",
    "model.turn.started",
    "model.turn.finished",
    "tool.started",
    "tool.finished",
    "verifier.finished",
    "response.returned",
]
"""Closed-set discriminator naming the agent-loop phase the event belongs to."""


VerifierOutcome = Literal["passed", "refused"]
"""The two valid verifier outcomes surfaced in :class:`RunEvent`."""


# ---------------------------------------------------------------------------
# Event model
# ---------------------------------------------------------------------------


class RunEventPhiError(ValueError):
    """Raised when a :class:`RunEvent` field contains PHI markers.

    A subclass of ``ValueError`` so callers that already catch Pydantic
    validation failures still see this rejection -- the model validator
    chains the error in via the standard Pydantic mechanism.
    """


class RunEvent(BaseModel):
    """One sanitized observability event emitted by the agent loop.

    Field constraints
    -----------------
    * ``trace_id`` -- short stable string joining every event in a run.
    * ``event_type`` -- closed set; see :data:`EventType`.
    * ``occurred_at`` -- UTC ``datetime``.  The agent loop uses a
      monotonic clock for timing math but stamps wall-clock UTC on the
      event payload so it correlates with the run-record store.
    * Numeric fields (``latency_ms``, ``token_usage_*``, ``result_count``)
      are non-negative integers when set.
    * ``cost_usd_delta`` is a non-negative float when set.
    * ``refusal_reason`` is a free string but the PHI scanner runs over
      it; callers should pass closed-set enum values.
    * ``error_class`` is the Python exception class name only -- never
      a message string.

    PHI hygiene
    -----------
    Every string-typed field is run through
    :func:`scan_event_field_for_phi` at construction time.  A single hit
    is a hard failure: by the time PHI reaches the observability store,
    sanitisation upstream has already failed.
    """

    model_config = ConfigDict(
        extra="forbid",
        strict=True,
        frozen=True,
    )

    trace_id: str = Field(
        ...,
        min_length=1,
        max_length=128,
        description="Stable identifier joining every event in a single run.",
    )
    event_type: EventType = Field(
        ...,
        description="Closed-set name of the agent-loop phase.",
    )
    occurred_at: datetime = Field(
        ...,
        description="UTC wall-clock time at which the event was emitted.",
    )

    latency_ms: int | None = Field(
        default=None,
        ge=0,
        description=(
            "Phase latency in milliseconds (>= 0).  Set on ``*.finished`` "
            "events that bracket a ``*.started`` event."
        ),
    )
    tool_name: str | None = Field(
        default=None,
        max_length=128,
        description=(
            "Name of the tool the event refers to.  Set on tool.* events; "
            "``None`` on model / verifier / response events."
        ),
    )
    result_count: int | None = Field(
        default=None,
        ge=0,
        description=(
            "Number of records returned by a tool call (>= 0). "
            "``None`` on non-tool events or on a tool error."
        ),
    )
    token_usage_input: int | None = Field(
        default=None,
        ge=0,
        description="Estimated input tokens consumed by an LLM turn.",
    )
    token_usage_output: int | None = Field(
        default=None,
        ge=0,
        description="Estimated output tokens produced by an LLM turn.",
    )
    cost_usd_delta: float | None = Field(
        default=None,
        ge=0.0,
        description=(
            "Incremental dollar cost attributable to this event "
            "(model turn / tool call).  ``None`` when the cost layer is "
            "not wired in.  >= 0."
        ),
    )
    refusal_reason: str | None = Field(
        default=None,
        max_length=64,
        description=(
            "Closed-set enum value naming why a refusal was emitted.  "
            "Free-form text is rejected by the PHI scanner."
        ),
    )
    verifier_outcome: VerifierOutcome | None = Field(
        default=None,
        description="Outcome of the M15 verifier on a verifier.finished event.",
    )
    error_class: str | None = Field(
        default=None,
        max_length=128,
        description=(
            "Unqualified exception class name only -- never a rendered "
            "exception message."
        ),
    )

    # ------------------------------------------------------------------
    # Validators
    # ------------------------------------------------------------------

    @model_validator(mode="after")
    def _check_no_phi(self) -> RunEvent:
        """Reject events whose string fields contain PHI-like markers.

        Only string-typed fields are scanned; numeric / datetime fields
        cannot carry textual PHI.  The literal-typed ``event_type`` and
        ``verifier_outcome`` fields are scanned anyway for defence-in-
        depth (the cost is negligible and they are user-visible strings).
        """
        candidates: list[str] = [
            self.trace_id,
            self.event_type,
        ]
        if self.tool_name is not None:
            candidates.append(self.tool_name)
        if self.refusal_reason is not None:
            candidates.append(self.refusal_reason)
        if self.verifier_outcome is not None:
            candidates.append(self.verifier_outcome)
        if self.error_class is not None:
            candidates.append(self.error_class)

        hits = scan_event_field_for_phi(*candidates)
        if hits:
            raise RunEventPhiError(
                "RunEvent contains PHI-like markers: " + "; ".join(hits),
            )
        return self


__all__ = [
    "EventType",
    "RunEvent",
    "RunEventPhiError",
    "VerifierOutcome",
]
