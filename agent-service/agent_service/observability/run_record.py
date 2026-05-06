"""Sanitized run-record schema for observability.

A :class:`RunRecord` summarises one pipeline invocation as a flat,
PHI-free document suitable for long-term storage and aggregate
reporting.  The schema deliberately omits any field that could carry
patient identifiers (file paths are stored only as document-type
context elsewhere; the record itself does not carry the source path).

A model validator scans every string field for SSN-like patterns and
``Patient: <name>`` markers and rejects records that contain them.  The
scan is intentionally conservative -- a single hit is a hard failure --
because once a PHI value reaches the observability store it has already
escaped the sanitised path.
"""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, ClassVar

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    NonNegativeFloat,
    NonNegativeInt,
    model_validator,
)

# The scanner used to live at module scope in this file.  M16 lifted it
# into ``observability/_phi_scanner.py`` so the new ``RunEvent`` model can
# reuse the same primitive without copy-pasting the regexes.  The public
# name is re-exported here for backwards compatibility -- callers (the
# eval runner, the report generator, downstream tests) still import
# ``scan_for_phi`` from ``run_record``.
from agent_service.observability._phi_scanner import scan_for_phi


_ALLOWED_STATUSES: frozenset[str] = frozenset({"success", "refused", "error"})
"""Closed set of run statuses persisted in the observability store."""


__all__ = [
    "RunRecord",
    "scan_for_phi",
]


# ---------------------------------------------------------------------------
# Record model
# ---------------------------------------------------------------------------


class RunRecord(BaseModel):
    """One sanitized observability record for a graph invocation.

    Field constraints
    -----------------
    * ``trace_id`` -- arbitrary stable identifier (UUID/string) so
      records can be joined back to graph traces.
    * ``doc_type`` -- ``"lab_pdf"`` / ``"intake_form"`` / etc.; the
      closed set is enforced at the API boundary, not here, so the
      store accepts new document types without requiring a code change.
    * Numeric counters (token counts, retrieval hit count) are
      non-negative integers.
    * Latencies and cost are non-negative floats.
    * ``extraction_confidence`` lives in [0.0, 1.0].
    * ``status`` is one of ``success`` / ``refused`` / ``error``.

    PHI hygiene
    -----------
    The :func:`_check_no_phi` model validator rejects any record whose
    string fields (``trace_id``, ``doc_type``, ``model``, ``status``)
    contain SSN-like patterns or ``Patient: <name>`` markers.
    """

    model_config = ConfigDict(
        # Reject typos and any field that wasn't declared explicitly --
        # the observability schema is small enough that an unknown
        # field is almost certainly a bug.
        extra="forbid",
        # Records are append-only; freezing the model removes a class
        # of accidental-mutation bugs in callers.
        frozen=True,
    )

    # Allowed status values, exposed as a class attribute so callers /
    # tests can introspect without re-importing the private constant.
    ALLOWED_STATUSES: ClassVar[frozenset[str]] = _ALLOWED_STATUSES

    trace_id: str = Field(
        ...,
        min_length=1,
        max_length=128,
        description="Stable identifier joining the record back to a graph trace.",
    )
    doc_type: str = Field(
        ...,
        min_length=1,
        max_length=64,
        description="Document type processed (e.g. lab_pdf, intake_form).",
    )
    timestamp: datetime = Field(
        default_factory=lambda: datetime.now(timezone.utc),
        description="UTC timestamp at which the record was emitted.",
    )

    latency_ms_per_step: dict[str, NonNegativeFloat] = Field(
        default_factory=dict,
        description="Map of step name to latency in milliseconds (>= 0).",
    )
    total_latency_ms: NonNegativeFloat = Field(
        default=0.0,
        description="Total wall-clock latency across all steps, in milliseconds.",
    )

    tokens_in: NonNegativeInt = Field(
        default=0,
        description="Estimated input tokens consumed during the run.",
    )
    tokens_out: NonNegativeInt = Field(
        default=0,
        description="Estimated output tokens produced during the run.",
    )

    model: str = Field(
        ...,
        min_length=1,
        max_length=128,
        description="LLM model identifier used for extraction.",
    )

    cost_usd: NonNegativeFloat = Field(
        default=0.0,
        description="Estimated dollar cost of the run.",
    )

    retrieval_hit_count: NonNegativeInt = Field(
        default=0,
        description="Number of retrieval evidence snippets returned.",
    )
    extraction_confidence: float = Field(
        default=0.0,
        ge=0.0,
        le=1.0,
        description="Worker-reported extraction confidence in [0, 1].",
    )

    status: str = Field(
        ...,
        description="Run status -- one of success, refused, error.",
    )

    # ------------------------------------------------------------------
    # Validators
    # ------------------------------------------------------------------

    @model_validator(mode="after")
    def _check_status_is_allowed(self) -> RunRecord:
        """Ensure ``status`` belongs to the closed allow-list."""
        if self.status not in _ALLOWED_STATUSES:
            allowed = ", ".join(sorted(_ALLOWED_STATUSES))
            raise ValueError(
                f"status must be one of [{allowed}]; got {self.status!r}",
            )
        return self

    @model_validator(mode="after")
    def _check_total_matches_steps(self) -> RunRecord:
        """If both totals are populated, the per-step sum must not exceed total.

        A small tolerance accommodates floating-point drift.  The check
        is one-sided: ``total_latency_ms`` may legitimately exceed the
        sum (e.g. wall-clock includes setup time outside any named
        step), but the per-step sum should never exceed the wall-clock
        total recorded by the caller.
        """
        if not self.latency_ms_per_step:
            return self
        per_step_sum = sum(self.latency_ms_per_step.values())
        # Allow ~1ms of rounding drift -- per-step latencies are stored
        # as floats but graph nodes round to int milliseconds.
        if per_step_sum > self.total_latency_ms + 1.0:
            raise ValueError(
                f"sum of latency_ms_per_step ({per_step_sum:.2f}) exceeds "
                f"total_latency_ms ({self.total_latency_ms:.2f})",
            )
        return self

    @model_validator(mode="after")
    def _check_no_phi(self) -> RunRecord:
        """Reject records whose string fields contain PHI-like markers."""
        # Only scan the string-typed fields the schema declares.  Numeric
        # fields cannot carry textual PHI; timestamp is a datetime; the
        # latency dict's keys are bounded step names ("extract", etc.).
        candidates: list[str] = [
            self.trace_id,
            self.doc_type,
            self.model,
            self.status,
        ]
        candidates.extend(self.latency_ms_per_step.keys())

        hits = scan_for_phi(*candidates)
        if hits:
            raise ValueError(
                "RunRecord contains PHI-like markers: " + "; ".join(hits),
            )
        return self

    # ------------------------------------------------------------------
    # Serialisation helpers
    # ------------------------------------------------------------------

    def to_jsonl_dict(self) -> dict[str, Any]:
        """Return a JSON-serialisable representation suitable for JSONL.

        ``datetime`` is rendered as an ISO-8601 string with timezone so
        the file remains human-readable and stable across reads.
        """
        payload = self.model_dump()
        payload["timestamp"] = self.timestamp.isoformat()
        return payload
