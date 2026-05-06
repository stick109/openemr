"""Response shaping for the chart copilot (M14).

This module ports the shape produced by
``AgentEvidenceResponseBuilder::answerFromPacket`` and the deterministic
refusal phrasing from ``AgentLlmOrchestrator::systemRefusal`` to Python.

UI-side mapping (from ``interface/patient_file/summary/agent_panel.js``)
======================================================================

The browser UI reads the ``data.answer`` envelope and walks:

* ``answer_blocks[]`` -- each block has ``heading`` and ``claims[]``
  (``shouldShowBlockHeading`` checks ``block.heading``;
  ``Array.isArray(block.claims)`` iterates the claim list).
* ``answer_blocks[].claims[]`` -- each claim has ``text``,
  ``citation_ids``, and ``certainty`` (``shouldShowCertainty`` filters
  the bucket; ``appendClaimText`` renders ``claim.text``;
  ``appendCitationLinks`` walks ``claim.citation_ids``).
* ``missing_or_uncertain[]`` -- objects with ``text`` and
  ``citation_ids`` (``data.answer.missing_or_uncertain.forEach`` reads
  ``item.text`` / ``item.citation_ids``).
* ``checked_evidence`` -- decorated string list (handled at the response
  envelope by the M13 agent loop, not by this builder).

The fields the builder produces match those names verbatim, plus the
top-level verifier-facing fields ``claims`` / ``citation_ids`` /
``certainty`` introduced in M14.
"""

from __future__ import annotations

import html
from collections.abc import Mapping, Sequence
from typing import Final, Literal

from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunResponse,
    MissingOrUncertain,
    ToolCallRecord,
)
from agent_service.schemas.evidence import EvidenceEnvelope


# ---------------------------------------------------------------------------
# Closed-set refusal taxonomy
# ---------------------------------------------------------------------------


RefusalReason = Literal[
    "missing_data",
    "unsupported",
    "out_of_scope",
    "tool_error",
    "verification_failed",
]


REFUSAL_PHRASING: Final[dict[RefusalReason, str]] = {
    "missing_data": (
        "A verified answer is not available because the checked evidence "
        "did not contain the records needed for this request."
    ),
    "unsupported": (
        "A verified answer is not available because at least one claim "
        "could not be supported by the cited evidence."
    ),
    "out_of_scope": (
        "This request is outside the supported scope of the chart copilot. "
        "Please consult a clinician for guidance."
    ),
    "tool_error": (
        "A verified answer is not available because an evidence tool "
        "failed to return data for this request."
    ),
    "verification_failed": (
        "A verified answer is not available from the checked evidence "
        "for this request."
    ),
}


REFUSAL_HEADING: Final[str] = "Clinical Co-Pilot"


SAFE_MISSINGNESS_HEADING: Final[str] = "Clinical Co-Pilot"
SAFE_MISSINGNESS_PROLOGUE: Final[str] = (
    "This does not prove absence from the full chart; it only reflects "
    "the bounded evidence retrieved for this request."
)
SAFE_MISSINGNESS_NO_RECORDS: Final[str] = (
    "No matching {kind} records were found in checked evidence."
)


# ---------------------------------------------------------------------------
# Builder
# ---------------------------------------------------------------------------


class ResponseBuilder:
    """Single place that constructs ``CopilotRunResponse`` envelopes.

    The builder is the only component that performs HTML escaping and
    citation deduplication. M13 (agent loop), M14 (this module), and M15
    (verifier) all funnel their output through it so the wire shape is
    consistent and the UI never has to defend against unsafe text.
    """

    def __init__(self, *, escape_for_html: bool = True) -> None:
        self._escape_for_html = escape_for_html

    # --- Public surface -------------------------------------------------

    def build_success(
        self,
        *,
        intent_id: str,
        evidence_envelopes: Sequence[EvidenceEnvelope],
        claims: Sequence[Claim],
        certainty: Literal["high", "medium", "low", "unknown"],
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        heading: str | None = None,
        body_markdown: str | None = None,
        missing_or_uncertain: Sequence[MissingOrUncertain] = (),
    ) -> CopilotRunResponse:
        """Build a verified-success response."""
        escaped_heading = self._escape(heading or intent_id)
        escaped_body = self._escape(body_markdown) if body_markdown is not None else None
        escaped_claims = [self._escape_claim(c) for c in claims]
        escaped_missing = [self._escape_missing(m) for m in missing_or_uncertain]

        block = AnswerBlock(
            heading=escaped_heading,
            claims=escaped_claims,
            body_markdown=escaped_body,
        )

        return CopilotRunResponse(
            answer_blocks=[block],
            claims=list(escaped_claims),
            citation_ids=self._collect_citation_ids(escaped_claims),
            certainty=certainty,
            missing_or_uncertain=escaped_missing,
            citations=self._collect_citations(evidence_envelopes),
            tool_sequence=list(tool_sequence),
            verification_status="passed",
            cost_usd=cost_usd,
            latency_ms_per_step=dict(latency_ms_per_step),
            trace_id=trace_id,
        )

    def build_refusal(
        self,
        *,
        reason: RefusalReason,
        explanation: str,
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
    ) -> CopilotRunResponse:
        """Build a deterministic refusal response.

        The block heading is fixed (``REFUSAL_HEADING``) and the claim
        text is the canonical phrasing for ``reason``. ``explanation``
        is rendered as ``body_markdown`` so a caller-supplied,
        already-vetted detail line can travel alongside the canonical
        refusal without altering the canonical text.
        """
        canonical_text = REFUSAL_PHRASING[reason]
        claim = Claim(
            text=self._escape(canonical_text),
            citation_ids=[],
            certainty="not_checked",
        )
        block = AnswerBlock(
            heading=self._escape(REFUSAL_HEADING),
            claims=[claim],
            body_markdown=self._escape(explanation) if explanation else None,
        )

        return CopilotRunResponse(
            answer_blocks=[block],
            claims=[claim],
            citation_ids=[],
            certainty="unknown",
            missing_or_uncertain=[],
            citations=[],
            tool_sequence=list(tool_sequence),
            verification_status="refused",
            cost_usd=cost_usd,
            latency_ms_per_step=dict(latency_ms_per_step),
            trace_id=trace_id,
        )

    def build_safe_missingness(
        self,
        *,
        intent_id: str,
        missing_kinds: Sequence[str],
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        heading: str | None = None,
    ) -> CopilotRunResponse:
        """Build a "we did not find that data" response.

        This is a *successful* answer (``verification_status="passed"``)
        because the agent ran its bounded read and accurately reports
        the absence.
        """
        kind_claims: list[Claim]
        if missing_kinds:
            kind_claims = [
                Claim(
                    text=self._escape(
                        SAFE_MISSINGNESS_NO_RECORDS.format(kind=kind),
                    ),
                    citation_ids=[],
                    certainty="not_found",
                )
                for kind in missing_kinds
            ]
        else:
            kind_claims = [
                Claim(
                    text=self._escape(
                        "No matching records were found in checked evidence "
                        "for this intent.",
                    ),
                    citation_ids=[],
                    certainty="not_found",
                ),
            ]

        prologue_note = MissingOrUncertain(
            text=self._escape(SAFE_MISSINGNESS_PROLOGUE),
            citation_ids=[],
        )

        block = AnswerBlock(
            heading=self._escape(heading or SAFE_MISSINGNESS_HEADING),
            claims=kind_claims,
        )

        return CopilotRunResponse(
            answer_blocks=[block],
            claims=list(kind_claims),
            citation_ids=[],
            certainty="unknown",
            missing_or_uncertain=[prologue_note],
            citations=[],
            tool_sequence=list(tool_sequence),
            verification_status="passed",
            cost_usd=cost_usd,
            latency_ms_per_step=dict(latency_ms_per_step),
            trace_id=trace_id,
        )

    # --- Internal helpers -----------------------------------------------

    def _escape(self, text: str) -> str:
        """Escape a single UI-bound string when escaping is enabled."""
        if not self._escape_for_html:
            return text
        return html.escape(text, quote=True)

    def _escape_claim(self, claim: Claim) -> Claim:
        """Return a new ``Claim`` with ``text`` HTML-escaped."""
        return Claim(
            text=self._escape(claim.text),
            citation_ids=list(claim.citation_ids),
            certainty=claim.certainty,
        )

    def _escape_missing(self, item: MissingOrUncertain) -> MissingOrUncertain:
        """Return a new ``MissingOrUncertain`` with ``text`` HTML-escaped."""
        return MissingOrUncertain(
            text=self._escape(item.text),
            citation_ids=list(item.citation_ids),
        )

    @staticmethod
    def _collect_citation_ids(claims: Sequence[Claim]) -> list[str]:
        """Return the deduplicated, sorted union of per-claim citation IDs."""
        seen: set[str] = set()
        for claim in claims:
            seen.update(claim.citation_ids)
        return sorted(seen)

    @staticmethod
    def _collect_citations(
        envelopes: Sequence[EvidenceEnvelope],
    ) -> list[Citation]:
        """Flatten + dedup envelopes' ``sources`` by ``source_id``.

        Order is preserved by first occurrence so the chart UI presents
        citations in the same sequence they were retrieved.
        """
        seen: dict[str, Citation] = {}
        for envelope in envelopes:
            for citation in envelope.sources:
                if citation.source_id in seen:
                    continue
                seen[citation.source_id] = citation
        return list(seen.values())


__all__ = [
    "REFUSAL_HEADING",
    "REFUSAL_PHRASING",
    "RefusalReason",
    "ResponseBuilder",
    "SAFE_MISSINGNESS_HEADING",
    "SAFE_MISSINGNESS_NO_RECORDS",
    "SAFE_MISSINGNESS_PROLOGUE",
]
