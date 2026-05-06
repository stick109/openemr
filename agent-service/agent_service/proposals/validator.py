"""Validator for lab-observation write proposals (M21).

Every failure mode listed in the M21 spec is enumerated here as a
distinct error reason so callers (the PHP committer, eval harness, and
shadow-mode comparator) can pin behaviour without inspecting message
strings.  The validator is **pure**: no I/O, no mutation, no globals.

Failure modes
-------------

* ``proposal_kind`` other than ``"lab_observation"``.
* ``payload`` is empty or contains non-string keys.
* Any payload field is not citation-backed (no entry in
  ``citation_field_map`` names that field).
* A citation references a ``source_type`` the run context is not
  authorised to read.
* ``idempotency_key`` is malformed -- specifically, it must be the
  run's ``trace_id`` followed by ``":"`` and a non-empty scope.
* ``proposed_at`` is in the future or older than
  :data:`PROPOSAL_FRESHNESS_WINDOW_SECONDS` (60 minutes).  The freshness
  cap prevents stale-replay attacks where an attacker resubmits an old
  signed proposal long after the agent emitted it.

Design notes
------------

* The validator never inspects the ``payload`` for clinical correctness
  (LOINC ranges, abnormal flags, etc.).  Those checks belong to the PHP
  committer where the OpenEMR-side schema lives.
* Returning a list of strings (rather than raising) lets callers
  aggregate every failure in one round trip; the PHP side maps the list
  to a typed 422 response.
"""

from __future__ import annotations

from collections.abc import Callable, Sequence
from datetime import datetime, timezone
from typing import Final

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.schemas.proposals import WriteProposal

__all__ = [
    "PROPOSAL_FRESHNESS_WINDOW_SECONDS",
    "validate_lab_observation_proposal",
]


# Maximum age of a proposal before it is considered stale.  The wall
# clock is intentionally injected so deterministic tests can pin the
# boundary without monkey-patching ``datetime.now``.
PROPOSAL_FRESHNESS_WINDOW_SECONDS: Final[int] = 60 * 60


def _utc_now() -> datetime:
    """Return the current time as a timezone-aware UTC datetime.

    Defined at module scope so tests can monkeypatch a deterministic
    clock without instantiating any fixtures.
    """
    return datetime.now(tz=timezone.utc)


def _idempotency_key_well_formed(key: str, trace_id: str) -> bool:
    """Return ``True`` when ``key`` follows the M21 format.

    The expected format is ``"<trace_id>:<scope>"`` where ``<scope>`` is
    a non-empty token (the executor uses a 16-hex-char hash today, but
    the validator does not care about its exact shape, only that it
    exists and is non-trivial).
    """
    prefix = f"{trace_id}:"
    if not key.startswith(prefix):
        return False
    scope = key[len(prefix):]
    if scope == "":
        return False
    # Scopes must be printable ASCII without control chars / whitespace.
    return all(0x21 <= ord(c) <= 0x7E for c in scope)


def validate_lab_observation_proposal(
    proposal: WriteProposal,
    *,
    context: CopilotRunContext,
    now: Callable[[], datetime] = _utc_now,
) -> Sequence[str]:
    """Validate a lab-observation write proposal.

    Parameters
    ----------
    proposal
        The :class:`WriteProposal` minted by the persist tool.
    context
        The verified :class:`CopilotRunContext` the proposal is scoped
        to.  Citation source-type checks are performed against
        ``context.allowed_source_types``.
    now
        Injected clock returning a timezone-aware UTC datetime.

    Returns
    -------
    Sequence[str]
        Empty when the proposal is valid; otherwise a list of typed
        error reasons.  Callers must treat a non-empty list as a hard
        rejection -- partial proposals never reach the committer.
    """
    errors: list[str] = []

    if proposal.proposal_kind != "lab_observation":
        errors.append(
            f"proposal_kind must be 'lab_observation' (got "
            f"{proposal.proposal_kind!r})"
        )

    if not isinstance(proposal.payload, dict) or proposal.payload == {}:
        errors.append("payload must be a non-empty object")

    payload_fields: list[str] = []
    if isinstance(proposal.payload, dict):
        for key in proposal.payload.keys():
            if not isinstance(key, str) or key == "":
                errors.append("payload keys must be non-empty strings")
                payload_fields = []
                break
            payload_fields.append(key)

    citation_field_map = list(proposal.citation_field_map)
    if len(citation_field_map) != len(proposal.citations):
        errors.append(
            "citation_field_map length must match citations length"
        )

    # Per-field citation coverage: every payload field must appear in
    # the field map.  Fields named in the map but not present in the
    # payload are also a contract violation -- they would cite a value
    # that was never proposed.
    cited_fields = {field for field in citation_field_map}
    for field in payload_fields:
        if field not in cited_fields:
            errors.append(f"payload field '{field}' has no citation")
    for field in citation_field_map:
        if field not in payload_fields:
            errors.append(
                f"citation_field_map references unknown payload field "
                f"'{field}'"
            )

    allowed_source_types = set(context.allowed_source_types)
    for index, citation in enumerate(proposal.citations):
        if citation.source_type not in allowed_source_types:
            errors.append(
                f"citations[{index}] source_type "
                f"'{citation.source_type}' is outside the run context's "
                f"allowed_source_types"
            )

    if not _idempotency_key_well_formed(
        proposal.idempotency_key, context.trace_id
    ):
        errors.append(
            "idempotency_key must be of the form '<trace_id>:<scope>'"
        )

    proposed_at = proposal.proposed_at
    if proposed_at.tzinfo is None:
        errors.append("proposed_at must be timezone-aware")
    else:
        current = now()
        if proposed_at > current:
            errors.append("proposed_at is in the future")
        else:
            age_seconds = (current - proposed_at).total_seconds()
            if age_seconds > PROPOSAL_FRESHNESS_WINDOW_SECONDS:
                errors.append(
                    f"proposed_at is older than the "
                    f"{PROPOSAL_FRESHNESS_WINDOW_SECONDS}-second "
                    f"freshness window"
                )

    return errors
