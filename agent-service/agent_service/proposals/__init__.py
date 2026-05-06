"""Two-phase write-proposal validation surface (M21).

The agent-service sidecar never commits clinical writes itself.  Tools
that look like writes -- e.g.
:func:`agent_service.tools.document_tools.persist_lab_observation_proposal_executor`
-- emit a typed :class:`agent_service.schemas.proposals.WriteProposal`
which the PHP host then validates and applies via
``AgentProposalCommitController`` (see ``src/RestControllers/Agent``).

This package is the **Python-side** validation surface: it implements
the same checks the PHP boundary will eventually re-run, so we can
reject malformed or under-cited proposals **before** they leave the
sidecar in observability tooling, evals, and shadow-mode comparisons
(M18 / M22).

Public surface
--------------

* :func:`validate_lab_observation_proposal` -- structural + semantic
  validation of a ``lab_observation`` proposal.  Returns a list of
  human-readable error reasons (an empty list means "valid").

The validator is intentionally pure: it never mutates inputs and never
performs I/O.  Callers (the agent loop, evals, the proxy controller's
shadow path) compose their own audit / log behaviour around it.
"""

from __future__ import annotations

from agent_service.proposals.validator import (
    PROPOSAL_FRESHNESS_WINDOW_SECONDS,
    validate_lab_observation_proposal,
)

__all__ = [
    "PROPOSAL_FRESHNESS_WINDOW_SECONDS",
    "validate_lab_observation_proposal",
]
