"""Tests for two-phase lab-observation write proposals (M21).

Covers the contract documented in
``Clinical Co-Pilot Migration to Python Sidecar.md`` step M21:

* The persist tool returns a :class:`WriteProposal` whose
  ``citations`` cover every observation field via the
  ``citation_field_map``.
* The persist tool never performs any side-effect: re-running with the
  same trace + observation produces the same idempotency key.
* :func:`validate_lab_observation_proposal` accepts a well-formed
  proposal and rejects every M21 failure mode (missing per-field
  citation, unknown source type, malformed idempotency key, stale
  ``proposed_at``).
"""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timedelta, timezone

import pytest

from agent_service.auth import CopilotRunContext
from agent_service.proposals import (
    PROPOSAL_FRESHNESS_WINDOW_SECONDS,
    validate_lab_observation_proposal,
)
from agent_service.schemas.copilot import Citation
from agent_service.schemas.proposals import WriteProposal
from agent_service.tools import (
    DOCUMENT_TOOL_NAMES,
    document_tool_registry,
    execute_tool,
)


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


# Far-future expiry so frozen clocks always sit before the token deadline.
TOKEN_EXPIRES_AT: int = 1_900_000_000


def _frozen_clock(value: datetime) -> Callable[[], datetime]:
    """Return a deterministic clock returning ``value``."""

    def _clock() -> datetime:
        return value

    return _clock


def _make_context(
    *,
    trace_id: str = "trace-write-proposal-1",
    allowed_source_types: list[str] | None = None,
) -> CopilotRunContext:
    """Build a :class:`CopilotRunContext` with documents+labs scope."""
    return CopilotRunContext.model_validate(
        {
            "user_id": 9,
            "username": "dr.smith",
            "patient_id": 42,
            "encounter_id": 100,
            "allowed_tools": list(DOCUMENT_TOOL_NAMES),
            "allowed_source_types": list(
                allowed_source_types
                if allowed_source_types is not None
                else ["documents", "labs", "guidelines"],
            ),
            "max_rows": 10,
            "lookback_days": 365,
            "expires_at": TOKEN_EXPIRES_AT,
            "request_id": "req-write-proposal",
            "trace_id": trace_id,
            "key_version": "v1",
        },
    )


def _observation() -> dict[str, str]:
    return {
        "test_name": "Hemoglobin",
        "value": "13.5",
        "unit": "g/dL",
        "reference_range": "13.5-17.5",
        "collection_date": "2026-01-15",
        "abnormal_flag": "normal",
    }


def _citation_entries(*, source_type: str = "documents") -> list[dict[str, str]]:
    """One citation entry per observation field for the canonical fixture."""
    obs = _observation()
    return [
        {
            "field": field,
            "source_type": source_type,
            "source_id": f"doc:upload:{idx}",
            "label": f"Lab PDF page {idx + 1}",
            "snippet": f"{field}={value}",
        }
        for idx, (field, value) in enumerate(obs.items())
    ]


def _build_proposal(
    *,
    context: CopilotRunContext,
    proposed_at: datetime,
    observation_citations: list[dict[str, str]] | None = None,
) -> WriteProposal:
    """Build a proposal via the executor and override ``proposed_at``.

    The executor reads ``proposed_at`` from the tool module's wall-clock,
    which is not injected by ``execute_tool``'s ``clock`` parameter (that
    parameter governs expiry checks only).  We mint via the real tool to
    keep the test honest about idempotency-key shape, then rebuild the
    proposal with a deterministic ``proposed_at`` so freshness/future
    checks are reproducible.
    """
    citations = (
        observation_citations
        if observation_citations is not None
        else _citation_entries()
    )
    registry = document_tool_registry()
    outcome = execute_tool(
        context,
        "persist_lab_observation_proposal",
        {
            "observation": _observation(),
            "observation_citations": citations,
        },
        registry=registry,
        clock=_frozen_clock(proposed_at),
    )
    minted = outcome.payload["proposal"]
    assert isinstance(minted, WriteProposal)
    return WriteProposal(
        proposal_id=minted.proposal_id,
        proposal_kind=minted.proposal_kind,
        payload=minted.payload,
        citations=minted.citations,
        citation_field_map=minted.citation_field_map,
        idempotency_key=minted.idempotency_key,
        proposed_at=proposed_at,
    )


# ---------------------------------------------------------------------------
# Persist tool: citations + idempotency
# ---------------------------------------------------------------------------


class TestPersistLabObservationProposalEmitsCitations:
    """Tool surface contract for M21."""

    def test_emits_proposal_with_citations_and_field_map(self) -> None:
        ctx = _make_context()
        registry = document_tool_registry()
        outcome = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {
                "observation": _observation(),
                "observation_citations": _citation_entries(),
            },
            registry=registry,
            clock=_frozen_clock(datetime(2026, 5, 1, tzinfo=timezone.utc)),
        )

        proposal = outcome.payload["proposal"]
        assert isinstance(proposal, WriteProposal)
        assert proposal.proposal_kind == "lab_observation"
        assert len(proposal.citations) == len(_observation())
        assert len(proposal.citation_field_map) == len(proposal.citations)
        # Every field in the observation is named in the field map.
        assert set(proposal.citation_field_map) == set(_observation().keys())
        for citation in proposal.citations:
            assert isinstance(citation, Citation)
            assert citation.source_type == "documents"

    def test_idempotency_key_is_trace_id_prefixed_and_stable(self) -> None:
        ctx = _make_context(trace_id="trace-stable")
        first = _build_proposal(
            context=ctx,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        second = _build_proposal(
            context=ctx,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        assert first.idempotency_key == second.idempotency_key
        assert first.idempotency_key.startswith("trace-stable:")
        assert first.proposal_id != second.proposal_id

    def test_tool_does_not_mutate_state_when_re_invoked(self) -> None:
        # Two invocations across distinct contexts produce different
        # idempotency keys but identical payloads -- the tool body is
        # pure.
        ctx_a = _make_context(trace_id="trace-a")
        ctx_b = _make_context(trace_id="trace-b")
        a = _build_proposal(
            context=ctx_a,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        b = _build_proposal(
            context=ctx_b,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        assert a.payload == b.payload
        assert a.idempotency_key != b.idempotency_key


# ---------------------------------------------------------------------------
# Validator: happy path
# ---------------------------------------------------------------------------


class TestValidatorHappyPath:
    def test_well_formed_proposal_has_no_errors(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(context=ctx, proposed_at=proposed_at)

        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=30)),
        )
        assert list(errors) == []


# ---------------------------------------------------------------------------
# Validator: missing per-field citations
# ---------------------------------------------------------------------------


class TestValidatorRejectsUnderCitedProposal:
    def test_proposal_with_no_citations_is_rejected(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(
            context=ctx,
            proposed_at=proposed_at,
            observation_citations=[],
        )

        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=10)),
        )
        # Every field is uncited.
        for field in _observation().keys():
            assert any(field in e for e in errors), errors

    def test_proposal_missing_one_field_citation_is_rejected(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        # Drop the citation backing 'value' on purpose.
        partial = [c for c in _citation_entries() if c["field"] != "value"]
        proposal = _build_proposal(
            context=ctx,
            proposed_at=proposed_at,
            observation_citations=partial,
        )

        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=10)),
        )
        assert any("'value'" in e for e in errors), errors


# ---------------------------------------------------------------------------
# Validator: unauthorised citation source type
# ---------------------------------------------------------------------------


class TestValidatorRejectsCitationOutsideScope:
    def test_unauthorised_source_type_is_rejected(self) -> None:
        # Allow only "labs"; the executor will still mint Citation
        # objects with source_type="document".
        ctx = _make_context(allowed_source_types=["labs"])
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(
            context=ctx,
            proposed_at=proposed_at,
        )

        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=10)),
        )
        assert any("allowed_source_types" in e for e in errors), errors


# ---------------------------------------------------------------------------
# Validator: stale proposed_at and malformed idempotency key
# ---------------------------------------------------------------------------


class TestValidatorRejectsStaleAndMalformed:
    def test_stale_proposed_at_is_rejected(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(context=ctx, proposed_at=proposed_at)

        future_clock = _frozen_clock(
            proposed_at
            + timedelta(seconds=PROPOSAL_FRESHNESS_WINDOW_SECONDS + 1),
        )
        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=future_clock,
        )
        assert any("freshness window" in e for e in errors), errors

    def test_future_proposed_at_is_rejected(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(context=ctx, proposed_at=proposed_at)

        past_clock = _frozen_clock(proposed_at - timedelta(seconds=10))
        errors = validate_lab_observation_proposal(
            proposal,
            context=ctx,
            now=past_clock,
        )
        assert any("future" in e for e in errors), errors

    def test_malformed_idempotency_key_is_rejected(self) -> None:
        ctx = _make_context()
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(context=ctx, proposed_at=proposed_at)

        # Construct a sibling proposal with a malformed idempotency key.
        broken = WriteProposal(
            proposal_id=proposal.proposal_id,
            proposal_kind=proposal.proposal_kind,
            payload=proposal.payload,
            citations=proposal.citations,
            citation_field_map=proposal.citation_field_map,
            idempotency_key="not-trace-prefixed",
            proposed_at=proposal.proposed_at,
        )
        errors = validate_lab_observation_proposal(
            broken,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=10)),
        )
        assert any("idempotency_key" in e for e in errors), errors

    def test_empty_scope_idempotency_key_is_rejected(self) -> None:
        ctx = _make_context(trace_id="trace-empty-scope")
        proposed_at = datetime(2026, 5, 1, 12, 0, tzinfo=timezone.utc)
        proposal = _build_proposal(context=ctx, proposed_at=proposed_at)

        broken = WriteProposal(
            proposal_id=proposal.proposal_id,
            proposal_kind=proposal.proposal_kind,
            payload=proposal.payload,
            citations=proposal.citations,
            citation_field_map=proposal.citation_field_map,
            idempotency_key="trace-empty-scope:",
            proposed_at=proposal.proposed_at,
        )
        errors = validate_lab_observation_proposal(
            broken,
            context=ctx,
            now=_frozen_clock(proposed_at + timedelta(seconds=10)),
        )
        assert any("idempotency_key" in e for e in errors), errors


# ---------------------------------------------------------------------------
# Idempotency key shape on the tool itself
# ---------------------------------------------------------------------------


class TestIdempotencyKeyDeterminism:
    def test_same_observation_produces_same_key_within_trace(self) -> None:
        ctx = _make_context(trace_id="trace-x")
        a = _build_proposal(
            context=ctx,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        b = _build_proposal(
            context=ctx,
            proposed_at=datetime(2026, 5, 1, tzinfo=timezone.utc),
        )
        assert a.idempotency_key == b.idempotency_key

    def test_different_observation_changes_key(self) -> None:
        ctx = _make_context(trace_id="trace-y")
        registry = document_tool_registry()

        outcome = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {
                "observation": _observation(),
                "observation_citations": _citation_entries(),
            },
            registry=registry,
            clock=_frozen_clock(datetime(2026, 5, 1, tzinfo=timezone.utc)),
        )

        # Slightly different observation => different stable hash.
        modified = dict(_observation())
        modified["value"] = "14.0"
        modified_citations = [
            {**c, "source_id": c["source_id"] + "-v2"}
            for c in _citation_entries()
        ]
        outcome2 = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {
                "observation": modified,
                "observation_citations": modified_citations,
            },
            registry=registry,
            clock=_frozen_clock(datetime(2026, 5, 1, tzinfo=timezone.utc)),
        )

        assert (
            outcome.payload["proposal"].idempotency_key
            != outcome2.payload["proposal"].idempotency_key
        )


# ---------------------------------------------------------------------------
# Pure-function guarantee
# ---------------------------------------------------------------------------


def test_validator_does_not_mutate_proposal() -> None:
    ctx = _make_context()
    proposed_at = datetime(2026, 5, 1, tzinfo=timezone.utc)
    proposal = _build_proposal(context=ctx, proposed_at=proposed_at)
    snapshot = proposal.model_dump(mode="json")

    validate_lab_observation_proposal(
        proposal,
        context=ctx,
        now=_frozen_clock(proposed_at),
    )

    assert proposal.model_dump(mode="json") == snapshot
