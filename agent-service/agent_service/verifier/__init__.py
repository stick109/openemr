"""Answer verifier package for the chart copilot (M15).

Step M15 of ``Clinical Co-Pilot Migration to Python Sidecar.md`` ports
the verifier and refusal rules from
``src/Services/Agent/Verification/AgentAnswerVerifier.php`` into Python.

The verifier sits between the LLM-produced answer and response shaping
(M14). When verification fails, callers should funnel the result back
through the M14 ``ResponseBuilder`` to emit a deterministic refusal --
the helper :func:`to_refusal_response` does that wiring without
duplicating builder logic.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Final

from agent_service.answer.builder import (
    REFUSAL_PHRASING,
    RefusalReason,
    ResponseBuilder,
)
from agent_service.schemas.copilot import (
    CopilotRunResponse,
    ToolCallRecord,
)
from agent_service.verifier.answer_verifier import (
    AnswerVerifier,
    RuleId,
    Severity,
    VerificationFinding,
    VerificationResult,
    VerificationStatus,
    VerifierRefusalReason,
)


# Refusal categories the verifier emits that the M14 ResponseBuilder
# also recognises directly.  ``fabricated_citation`` and ``phi_in_output``
# do not have first-class entries in ``REFUSAL_PHRASING`` so we map them
# to the closest builder-supported reason.
_VERIFIER_TO_BUILDER_REASON: Final[dict[VerifierRefusalReason, RefusalReason]] = {
    "missing_data": "missing_data",
    "unsupported": "unsupported",
    "out_of_scope": "out_of_scope",
    "tool_error": "tool_error",
    "fabricated_citation": "verification_failed",
    "phi_in_output": "verification_failed",
    "verification_failed": "verification_failed",
}


def to_refusal_response(
    *,
    builder: ResponseBuilder,
    result: VerificationResult,
    tool_sequence: Sequence[ToolCallRecord],
    cost_usd: float,
    latency_ms_per_step: Mapping[str, int],
    trace_id: str,
) -> CopilotRunResponse:
    """Convert a refused :class:`VerificationResult` to a wire response.

    The helper wires the verifier's refusal reason through to the M14
    :class:`ResponseBuilder.build_refusal` API. The first failing
    finding's ``message`` is passed as the ``explanation`` body so
    operators can see *why* the answer was rejected without rebuilding
    the response by hand.

    ``result.status`` must be ``"refused"`` -- callers should not invoke
    this helper for a passing verification.  A ValueError is raised if
    they do, which surfaces a logic bug rather than silently emitting a
    refusal envelope for a clean answer.
    """
    if result.status != "refused":
        raise ValueError(
            "to_refusal_response called with result.status="
            f"{result.status!r}; expected 'refused'.",
        )

    reason: VerifierRefusalReason = result.refusal_reason or "verification_failed"
    builder_reason: RefusalReason = _VERIFIER_TO_BUILDER_REASON[reason]

    # Use the first fail-severity finding's message as the operator-facing
    # explanation.  PHI-safety: finding messages never contain claim text,
    # patient identifiers, or citation contents (see verifier rules).
    explanation = ""
    for finding in result.findings:
        if finding.severity == "fail":
            explanation = finding.message
            break

    return builder.build_refusal(
        reason=builder_reason,
        explanation=explanation,
        tool_sequence=tool_sequence,
        cost_usd=cost_usd,
        latency_ms_per_step=latency_ms_per_step,
        trace_id=trace_id,
    )


__all__ = [
    "AnswerVerifier",
    "RuleId",
    "Severity",
    "VerificationFinding",
    "VerificationResult",
    "VerificationStatus",
    "VerifierRefusalReason",
    "to_refusal_response",
]
