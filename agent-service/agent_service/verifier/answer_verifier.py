"""Answer verifier for the chart copilot (M15).

This module ports ``AgentAnswerVerifier`` from
``src/Services/Agent/Verification/AgentAnswerVerifier.php`` to Python.

The verifier sits between the LLM-produced answer (M13) and the response
shaping (M14). It enforces:

* every factual ``Claim`` must have at least one entry in ``citation_ids``
  unless the certainty marker explicitly opts out (``not_found``,
  ``not_checked``, ``unknown`` -- the same "uncited certainties" set as
  the PHP verifier);
* every cited ID must resolve to a known source ID -- IDs the model
  invented are rejected as fabrications;
* claim text must not contain out-of-scope clinical advice (port of the
  PHP banned-phrase regex in ``containsOutOfScopeAdvice``);
* the response must not hide tool errors -- if any tool failed, the
  answer must explicitly say so;
* and the answer text must not contain raw PHI patterns (SSN, phone,
  email, street address) other than fields explicitly allowed by
  ``allowed_phi_in_output`` for the active intent.

PHI-detection regexes are intentionally conservative -- it is safer to
refuse a borderline answer than to leak data.
"""

from __future__ import annotations

import re
from collections.abc import Iterable, Sequence, Set as AbstractSet
from dataclasses import dataclass, field
from typing import Final, Literal

from agent_service.schemas.copilot import (
    AnswerBlock,
    Claim,
    CopilotRunResponse,
    MissingOrUncertain,
)


# ---------------------------------------------------------------------------
# Closed-set verifier outputs
# ---------------------------------------------------------------------------


VerificationStatus = Literal["passed", "refused", "error"]


VerifierRefusalReason = Literal[
    "missing_data",
    "unsupported",
    "out_of_scope",
    "tool_error",
    "fabricated_citation",
    "phi_in_output",
    "verification_failed",
]


# Severity literal kept narrow on purpose: the verifier emits exactly
# two severities. Anything callers cannot recover from is a fail; warnings
# are surfaced to observability without refusing the response.
Severity = Literal["fail", "warn"]


# Rule IDs are short, stable strings that can appear in observability
# without leaking PHI.  They map 1:1 to the rule names used in the PHP
# verifier where possible.
class RuleId:
    """Stable identifiers for verifier findings."""

    CLAIM_MISSING_CITATION: Final[str] = "claim_missing_citation"
    FABRICATED_CITATION_ID: Final[str] = "fabricated_citation_id"
    UNSUPPORTED_CLAIM: Final[str] = "unsupported_claim"
    OUT_OF_SCOPE_ADVICE: Final[str] = "out_of_scope_advice"
    TOOL_ERROR_HIDDEN: Final[str] = "tool_error_hidden"
    PHI_IN_OUTPUT: Final[str] = "phi_in_output"
    UNSAFE_MISSINGNESS: Final[str] = "unsafe_missingness_phrasing"
    COMPLETENESS_STATEMENT: Final[str] = "completeness_statement_in_missing"
    FABRICATED_MISSINGNESS_CITATION: Final[str] = "fabricated_missingness_citation_id"
    EMPTY_CLAIM_TEXT: Final[str] = "empty_claim_text"


@dataclass(frozen=True, slots=True)
class VerificationFinding:
    """A single verifier observation.

    ``message`` is plain English and PHI-safe -- never include claim text,
    citation contents, or patient identifiers verbatim. When pointing at a
    specific location use a ``path`` like ``"answer_blocks[0].claims[1]"``,
    matching the PHP verifier's idiom.
    """

    severity: Severity
    rule_id: str
    message: str
    path: str | None = None


@dataclass(frozen=True, slots=True)
class VerificationResult:
    """Outcome of running the verifier over a ``CopilotRunResponse``."""

    status: VerificationStatus
    findings: tuple[VerificationFinding, ...] = field(default_factory=tuple)
    refusal_reason: VerifierRefusalReason | None = None


# ---------------------------------------------------------------------------
# Rule constants ported from the PHP verifier
# ---------------------------------------------------------------------------


# Certainties that explicitly do not require a citation.  Mirrors the
# ``UNCITED_CERTAINTIES`` constant in ``AgentAnswerVerifier.php``.
_UNCITED_CERTAINTIES: frozenset[str] = frozenset(
    {
        "not_found",
        "not_checked",
        "unknown",
    },
)


# Certainties that the PHP verifier treats as "high-confidence" --
# claims marked active/supported must cite evidence.  Used to detect
# the ``unsupported_claim`` rule when a high-certainty claim has no
# citations.
_REQUIRES_CITATION_CERTAINTIES: frozenset[str] = frozenset(
    {
        "high",
        "active",
        "inactive",
        "supported",
        "source_record",
        "conflicting",
        "medium",
        "low",
    },
)


# Out-of-scope advice phrases.  Direct port of the regex in
# ``containsOutOfScopeAdvice``.  Kept as a compiled pattern so callers
# don't pay the recompile cost on every claim.
_OUT_OF_SCOPE_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b("
    r"should|"
    r"recommend|recommended|"
    r"consider|"
    r"stop|increase|decrease|"
    r"prescribe|"
    r"diagnose|"
    r"treat|"
    r"bill|billing code|"
    r"place an order|order a"
    r")\b",
    re.IGNORECASE,
)


# Missingness language detection (port of ``soundsLikeMissingness``).
_MISSINGNESS_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b(missing|not found|unavailable|not checked|unknown)\b",
    re.IGNORECASE,
)


# Safe missingness language (port of ``usesSafeMissingness``).  Multi-pattern
# in PHP, collapsed into one alternation here.
_SAFE_MISSINGNESS_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\bnot found in checked (evidence|records)\b"
    r"|\bnot checked\b"
    r"|\bunavailable\b"
    r"|\bunknown in checked (evidence|records)\b",
    re.IGNORECASE,
)


# Completeness-statement detector (port of ``isCompletenessStatement``).
_COMPLETENESS_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\bno\s+(additional|other|more)\b.*\b(found|identified|listed|seen|present)\b",
    re.IGNORECASE,
)


# ---------------------------------------------------------------------------
# PHI detection regexes (intentionally conservative)
# ---------------------------------------------------------------------------


# SSN: classic dashed pattern. We also flag any 9-digit run that follows
# an "ssn"/"social" keyword within ~16 characters.
_SSN_DASHED: Final[re.Pattern[str]] = re.compile(r"\b\d{3}-\d{2}-\d{4}\b")
_SSN_NEAR_KEYWORD: Final[re.Pattern[str]] = re.compile(
    r"\b(ssn|social\s+security)[^0-9]{0,16}\b\d{9}\b",
    re.IGNORECASE,
)


# Phone: 10-digit US-style with optional separators. Treat any such
# pattern as suspicious; allow callers to opt in via
# ``allowed_phi_in_output={"phone"}`` when an intent legitimately renders
# a contact number.
_PHONE_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b\d{3}[\.\-\s]?\d{3}[\.\-\s]?\d{4}\b",
)


# Email: any RFC-ish address.  Generous TLD bound but still tight enough
# to avoid matching things like ``foo@1`` or version strings.
_EMAIL_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b",
    re.IGNORECASE,
)


# Street address heuristic: a 1-5 digit street number followed by a
# capitalised street-name token and one of the common suffixes.
_ADDRESS_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b\d{1,5}\s+([A-Z][a-zA-Z]+\s+){1,4}"
    r"(St|Street|Ave|Avenue|Blvd|Boulevard|Rd|Road|Dr|Drive|Ln|Lane|Ct|Court|Way|Pl|Place|Pkwy|Parkway)"
    r"\b\.?",
)


# Mapping: PHI category name -> compiled detector(s). Names match the
# values callers may pass in ``allowed_phi_in_output``.
_PHI_DETECTORS: Final[dict[str, tuple[re.Pattern[str], ...]]] = {
    "ssn": (_SSN_DASHED, _SSN_NEAR_KEYWORD),
    "phone": (_PHONE_PATTERN,),
    "email": (_EMAIL_PATTERN,),
    "address": (_ADDRESS_PATTERN,),
}


def _is_guideline_grounded(
    citation_ids: Sequence[str],
    guideline_citation_ids: AbstractSet[str],
) -> bool:
    """Return True when *every* cited ID resolves to a guideline source.

    Claims with no citations never qualify -- the exemption is for
    grounded claims only. The "every" requirement is deliberate: a
    claim mixing guideline and chart citations must still pass the
    out-of-scope regex, otherwise a stray guideline citation could
    cover advice language about the patient's chart.
    """
    if not citation_ids:
        return False
    return all(cid in guideline_citation_ids for cid in citation_ids)


# ---------------------------------------------------------------------------
# Verifier
# ---------------------------------------------------------------------------


# Reason precedence used when multiple fail rules trigger -- the
# refusal_reason is the most specific match.  Order matters: PHI leakage
# trumps a fabricated citation, which trumps an unsupported claim, etc.
_REFUSAL_PRECEDENCE: tuple[VerifierRefusalReason, ...] = (
    "phi_in_output",
    "out_of_scope",
    "fabricated_citation",
    "tool_error",
    "unsupported",
    "missing_data",
    "verification_failed",
)


# Map a rule_id to a refusal_reason category.
_RULE_TO_REASON: Final[dict[str, VerifierRefusalReason]] = {
    RuleId.CLAIM_MISSING_CITATION: "unsupported",
    RuleId.FABRICATED_CITATION_ID: "fabricated_citation",
    RuleId.FABRICATED_MISSINGNESS_CITATION: "fabricated_citation",
    RuleId.UNSUPPORTED_CLAIM: "unsupported",
    RuleId.OUT_OF_SCOPE_ADVICE: "out_of_scope",
    RuleId.TOOL_ERROR_HIDDEN: "tool_error",
    RuleId.PHI_IN_OUTPUT: "phi_in_output",
    RuleId.UNSAFE_MISSINGNESS: "verification_failed",
    RuleId.COMPLETENESS_STATEMENT: "verification_failed",
    RuleId.EMPTY_CLAIM_TEXT: "verification_failed",
}


class AnswerVerifier:
    """Port of ``AgentAnswerVerifier`` to Python.

    The verifier is stateless apart from configuration (the PHI allow-list).
    Construct one per request or reuse across requests -- it carries no
    per-call state.
    """

    def __init__(
        self,
        *,
        allowed_phi_in_output: AbstractSet[str] | None = None,
    ) -> None:
        # Normalise to lowercase so callers can pass either form.
        self._allowed_phi: frozenset[str] = frozenset(
            (s.lower() for s in (allowed_phi_in_output or ())),
        )

    # --- Public surface -------------------------------------------------

    def verify(
        self,
        *,
        response: CopilotRunResponse,
        known_citation_ids: AbstractSet[str],
        tool_call_succeeded: bool,
        guideline_citation_ids: AbstractSet[str] | None = None,
    ) -> VerificationResult:
        """Run all verifier rules and return a :class:`VerificationResult`.

        ``known_citation_ids`` is the union of citation IDs the agent loop
        actually retrieved -- the verifier rejects any cited ID that does
        not appear in this set as a fabrication.

        ``tool_call_succeeded`` is False if any tool errored without
        recovery; the verifier then requires the response to acknowledge
        the failure.

        ``guideline_citation_ids`` is a subset of ``known_citation_ids``
        whose citations resolve to a published clinical-guideline source
        (i.e. ``Citation.source_type == "guideline"``). Claims whose
        ``citation_ids`` are non-empty and entirely contained in this
        set are exempt from the out-of-scope advice regex, because
        guideline text is recommendation-shaped by nature and the
        regex would otherwise refuse every cited answer drawn from
        ``retrieve_guidelines``. Default empty -- callers that don't
        opt in get the unchanged strict behaviour.
        """
        guideline_ids: AbstractSet[str] = guideline_citation_ids or frozenset()
        findings: list[VerificationFinding] = []

        # Per-block scan: claim citations + advice + PHI.
        for block_idx, block in enumerate(response.answer_blocks):
            self._verify_block(
                block, block_idx, known_citation_ids, guideline_ids, findings,
            )

        # Top-level claims (M14 surfaces these for verifier-side scanning).
        # We rely on the answer_blocks scan above for path-pinned messages
        # and use this loop to catch any orphan top-level claims that did
        # not appear in any block (defence in depth).
        block_claim_ids = {id(c) for b in response.answer_blocks for c in b.claims}
        for top_idx, claim in enumerate(response.claims):
            if id(claim) in block_claim_ids:
                continue
            self._verify_claim(
                claim,
                path=f"claims[{top_idx}]",
                known_citation_ids=known_citation_ids,
                guideline_citation_ids=guideline_ids,
                findings=findings,
            )

        # missing_or_uncertain has its own rule set (no out-of-scope check
        # for citations, but includes completeness-statement detection).
        for item_idx, item in enumerate(response.missing_or_uncertain):
            self._verify_missing(
                item,
                item_idx,
                known_citation_ids,
                findings,
            )

        # Tool-error masking.  The PHP verifier looks at the JSON body
        # text; we do an equivalent text-level check across all claim
        # texts, body markdown, and missing notes.
        if not tool_call_succeeded and response.verification_status == "passed":
            if not self._answer_acknowledges_failure(response):
                findings.append(
                    VerificationFinding(
                        severity="fail",
                        rule_id=RuleId.TOOL_ERROR_HIDDEN,
                        message=(
                            "Tool call failed but answer does not acknowledge "
                            "the failure with safe-missingness wording."
                        ),
                        path=None,
                    ),
                )

        return self._summarise(findings)

    # --- Block / claim verification ------------------------------------

    def _verify_block(
        self,
        block: AnswerBlock,
        block_idx: int,
        known_citation_ids: AbstractSet[str],
        guideline_citation_ids: AbstractSet[str],
        findings: list[VerificationFinding],
    ) -> None:
        for claim_idx, claim in enumerate(block.claims):
            path = f"answer_blocks[{block_idx}].claims[{claim_idx}]"
            self._verify_claim(
                claim,
                path,
                known_citation_ids=known_citation_ids,
                guideline_citation_ids=guideline_citation_ids,
                findings=findings,
            )

        if block.body_markdown:
            self._verify_phi(
                block.body_markdown,
                f"answer_blocks[{block_idx}].body_markdown",
                findings,
            )

    def _verify_claim(
        self,
        claim: Claim,
        path: str,
        *,
        known_citation_ids: AbstractSet[str],
        guideline_citation_ids: AbstractSet[str],
        findings: list[VerificationFinding],
    ) -> None:
        text = claim.text.strip()
        if text == "":
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.EMPTY_CLAIM_TEXT,
                    message=f"{path} text is empty.",
                    path=path,
                ),
            )
            return

        # Out-of-scope advice (regex match -- text content not echoed).
        # Exempt claims whose citations all resolve to guideline sources:
        # guideline text is recommendation-shaped by nature, so the regex
        # would otherwise refuse every cited answer from
        # ``retrieve_guidelines``. The "all" requirement is deliberate --
        # a claim mixing guideline and chart citations cannot use the
        # exemption to slip advice past the regex.
        if _OUT_OF_SCOPE_PATTERN.search(text) and not _is_guideline_grounded(
            claim.citation_ids, guideline_citation_ids,
        ):
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.OUT_OF_SCOPE_ADVICE,
                    message=f"{path} contains out-of-scope clinical advice.",
                    path=path,
                ),
            )

        # PHI detection on the claim text.
        self._verify_phi(text, f"{path}.text", findings)

        # Citation rules.
        certainty = claim.certainty
        if not claim.citation_ids:
            if certainty in _UNCITED_CERTAINTIES:
                # PHP also checks safe-missingness phrasing for these claims.
                if (
                    _MISSINGNESS_PATTERN.search(text)
                    and not _SAFE_MISSINGNESS_PATTERN.search(text)
                ):
                    findings.append(
                        VerificationFinding(
                            severity="fail",
                            rule_id=RuleId.UNSAFE_MISSINGNESS,
                            message=(
                                f"{path} must phrase missingness as not found "
                                f"in checked evidence."
                            ),
                            path=path,
                        ),
                    )
                return

            # High-certainty claim with no citation: missing citation +
            # unsupported. We emit two findings so observability gets the
            # specific rule_id, but only one fail rolls into the refusal
            # reason via precedence below.
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.CLAIM_MISSING_CITATION,
                    message=f"{path} must cite checked evidence.",
                    path=path,
                ),
            )
            if certainty in _REQUIRES_CITATION_CERTAINTIES:
                findings.append(
                    VerificationFinding(
                        severity="fail",
                        rule_id=RuleId.UNSUPPORTED_CLAIM,
                        message=(
                            f"{path} marked '{certainty}' but has no citations."
                        ),
                        path=path,
                    ),
                )
            return

        # Cited claim: every ID must appear in the known set.
        for citation_id in claim.citation_ids:
            if citation_id not in known_citation_ids:
                findings.append(
                    VerificationFinding(
                        severity="fail",
                        rule_id=RuleId.FABRICATED_CITATION_ID,
                        message=(
                            f"{path} cites unknown source_id (citation not "
                            f"present in checked evidence)."
                        ),
                        path=path,
                    ),
                )
                # Don't emit a finding per ID; one is sufficient and avoids
                # quadratic noise when many IDs are fabricated.
                break

        if certainty == "conflicting":
            findings.append(
                VerificationFinding(
                    severity="warn",
                    rule_id="conflicting_claim",
                    message=f"{path} is marked conflicting.",
                    path=path,
                ),
            )

    def _verify_missing(
        self,
        item: MissingOrUncertain,
        item_idx: int,
        known_citation_ids: AbstractSet[str],
        findings: list[VerificationFinding],
    ) -> None:
        path = f"missing_or_uncertain[{item_idx}]"
        text = item.text.strip()
        if text == "":
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.EMPTY_CLAIM_TEXT,
                    message=f"{path} text is empty.",
                    path=path,
                ),
            )
            return

        # Out-of-scope clinical advice in missingness notes is still a fail.
        if _OUT_OF_SCOPE_PATTERN.search(text):
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.OUT_OF_SCOPE_ADVICE,
                    message=f"{path} contains out-of-scope clinical advice.",
                    path=path,
                ),
            )

        self._verify_phi(text, f"{path}.text", findings)

        if (
            _MISSINGNESS_PATTERN.search(text)
            and not _SAFE_MISSINGNESS_PATTERN.search(text)
        ):
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.UNSAFE_MISSINGNESS,
                    message=(
                        f"{path} must phrase missingness as not found in "
                        f"checked evidence."
                    ),
                    path=path,
                ),
            )

        if _COMPLETENESS_PATTERN.search(text):
            findings.append(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.COMPLETENESS_STATEMENT,
                    message=(
                        f"{path} contains a completeness statement; leave "
                        f"missing_or_uncertain empty when no items apply."
                    ),
                    path=path,
                ),
            )

        for citation_id in item.citation_ids:
            if citation_id not in known_citation_ids:
                findings.append(
                    VerificationFinding(
                        severity="fail",
                        rule_id=RuleId.FABRICATED_MISSINGNESS_CITATION,
                        message=(
                            f"{path} cites unknown source_id (citation not "
                            f"present in checked evidence)."
                        ),
                        path=path,
                    ),
                )
                break

    # --- Tool-error masking --------------------------------------------

    def _answer_acknowledges_failure(self, response: CopilotRunResponse) -> bool:
        """Return True if the answer mentions tool unavailability.

        Mirrors the PHP verifier's keyword scan (``unavailable``,
        ``not checked``, ``tool``).
        """
        for text in self._all_texts(response):
            lower = text.lower()
            if (
                "unavailable" in lower
                or "not checked" in lower
                or "tool" in lower
            ):
                return True
        return False

    @staticmethod
    def _all_texts(response: CopilotRunResponse) -> Iterable[str]:
        for block in response.answer_blocks:
            yield block.heading
            if block.body_markdown:
                yield block.body_markdown
            for claim in block.claims:
                yield claim.text
        for item in response.missing_or_uncertain:
            yield item.text
        for claim in response.claims:
            yield claim.text

    # --- PHI scanning ---------------------------------------------------

    def _verify_phi(
        self,
        text: str,
        path: str,
        findings: list[VerificationFinding],
    ) -> None:
        for category, patterns in _PHI_DETECTORS.items():
            if category in self._allowed_phi:
                continue
            for pattern in patterns:
                if pattern.search(text):
                    findings.append(
                        VerificationFinding(
                            severity="fail",
                            rule_id=RuleId.PHI_IN_OUTPUT,
                            message=(
                                f"{path} contains potential PHI of type "
                                f"'{category}'; refusing to ship."
                            ),
                            path=path,
                        ),
                    )
                    # Only one PHI finding per path is needed; the location
                    # is the actionable signal, not the count.
                    return

    # --- Result summary -------------------------------------------------

    def _summarise(
        self,
        findings: Sequence[VerificationFinding],
    ) -> VerificationResult:
        fails = [f for f in findings if f.severity == "fail"]
        if not fails:
            return VerificationResult(
                status="passed",
                findings=tuple(findings),
                refusal_reason=None,
            )

        # Pick the most-specific reason.  Build the set of reasons
        # actually triggered and pick the first match in precedence order.
        triggered = {
            _RULE_TO_REASON.get(f.rule_id, "verification_failed")
            for f in fails
        }
        reason: VerifierRefusalReason = "verification_failed"
        for candidate in _REFUSAL_PRECEDENCE:
            if candidate in triggered:
                reason = candidate
                break

        return VerificationResult(
            status="refused",
            findings=tuple(findings),
            refusal_reason=reason,
        )


__all__ = [
    "AnswerVerifier",
    "RuleId",
    "Severity",
    "VerificationFinding",
    "VerificationResult",
    "VerificationStatus",
    "VerifierRefusalReason",
]
