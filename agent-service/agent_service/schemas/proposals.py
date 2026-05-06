"""Write-proposal schemas for the sidecar agent loop (M12).

A *write proposal* is a typed, immutable record describing a database
mutation the agent loop wants to perform.  Proposals are NOT executed
in-process: they are returned to the PHP host (or a future validated
commit endpoint introduced in M21) which is the only side allowed to
touch persistent state.

The agent loop and tool executor never invoke a real ``INSERT``.  Tools
that look like writes (e.g. :func:`persist_lab_observation_proposal`)
emit a :class:`WriteProposal` instead.  This keeps the sidecar's
read-only contract intact while still letting the LLM model the *intent*
to write.

Design notes
------------
- ``frozen=True`` and ``extra="forbid"`` on every model so a proposal,
  once minted, cannot be mutated and cannot accidentally absorb extra
  attacker-controlled keys.
- ``proposal_id`` is a uuid4 string -- callers must compute it before
  construction (we keep schema construction pure / deterministic in
  tests).
- ``idempotency_key`` is derived from the run's ``trace_id`` plus a
  caller-chosen scope (e.g. an upload identifier or an observation hash)
  so re-running the same tool with identical inputs in the same trace
  produces the same key.  The PHP side uses this to dedupe writes.
- ``proposed_at`` is the timestamp when the proposal was minted, in
  UTC.  Stored at second resolution; the PHP host picks the
  authoritative commit time.
"""

from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any, Literal

from pydantic import BaseModel, ConfigDict, Field

from agent_service.schemas.copilot import Citation

__all__ = [
    "ProposalKind",
    "WriteProposal",
]


# Closed set of proposal kinds the sidecar can emit.  Adding a new kind
# here is intentional: every kind needs an upstream PHP committer.
ProposalKind = Literal["lab_observation"]


class WriteProposal(BaseModel):
    """A typed, immutable description of a deferred clinical write.

    The chart copilot never writes to the OpenEMR database directly.
    Instead, write-like tools return a :class:`WriteProposal` describing
    what the model wants to commit.  The PHP boundary (or, in M21, a
    validated commit endpoint) is the only side authorised to materialise
    the proposal as a real row.

    Attributes
    ----------
    proposal_id
        Caller-supplied uuid4 identifying this specific proposal.  The
        PHP side echoes it back on commit so the UI can correlate.
    proposal_kind
        Discriminator for the typed payload shape.  Currently only
        ``"lab_observation"`` is defined.
    payload
        The typed observation / record the proposal would write.  Stored
        as an opaque ``dict`` here so callers can ship pre-validated
        Pydantic dumps without re-validating against another schema.
    citations
        Tuple of :class:`Citation` objects backing the proposed write.
        Required: every clinical write must cite its evidence.
    idempotency_key
        Stable, deterministic key derived from the run's ``trace_id`` and
        a caller-chosen scope.  Identical inputs in the same trace must
        produce identical keys -- the PHP side uses this to dedupe.
    proposed_at
        UTC timestamp when the proposal was minted.  The PHP side picks
        the authoritative commit time on accept; this field is purely
        observational.
    """

    model_config = ConfigDict(extra="forbid", strict=True, frozen=True)

    proposal_id: Annotated[
        str,
        Field(
            min_length=1,
            max_length=128,
            description="uuid4 identifying this proposal across systems.",
        ),
    ]
    proposal_kind: ProposalKind = Field(
        description="Discriminator for the payload shape.",
    )
    payload: dict[str, Any] = Field(
        description="Typed observation/record dump backing this proposal.",
    )
    citations: tuple[Citation, ...] = Field(
        description="Sources backing the proposed write; required.",
    )
    idempotency_key: Annotated[
        str,
        Field(
            min_length=1,
            max_length=256,
            description=(
                "Deterministic dedupe key derived from trace_id and scope. "
                "Identical inputs in the same trace produce identical keys."
            ),
        ),
    ]
    proposed_at: datetime = Field(
        description="UTC timestamp when the proposal was minted.",
    )
