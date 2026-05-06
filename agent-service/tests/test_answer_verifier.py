"""Tests for the M15 answer verifier.

These tests pin the rules and refusal taxonomy ported from
``src/Services/Agent/Verification/AgentAnswerVerifier.php``. Each rule
test asserts the right ``rule_id`` fires and that the resulting
``status``/``refusal_reason`` match the spec in the migration doc.

PHP fixture parity: the supportedAnswer/packet pair from
``tests/Tests/Isolated/Services/Agent/Verification/AgentAnswerVerifierTest.php``
is reproduced as Python data structures in the helpers below so the
ported verifier can be exercised against the same scenarios that
guarded the PHP implementation.
"""

from __future__ import annotations

from collections.abc import Sequence

import pytest

from agent_service.answer.builder import REFUSAL_PHRASING, ResponseBuilder
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunResponse,
    MissingOrUncertain,
    ToolCallRecord,
)
from agent_service.verifier import (
    AnswerVerifier,
    RuleId,
    VerificationFinding,
    VerificationResult,
    to_refusal_response,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


KNOWN_CITATION_ID = "medication:lists_medication:77"
KNOWN_CITATION_IDS: frozenset[str] = frozenset({KNOWN_CITATION_ID})


def _claim(
    *,
    text: str = "Metformin 500 mg twice daily is listed in the checked medication record.",
    citation_ids: Sequence[str] = (KNOWN_CITATION_ID,),
    certainty: str = "supported",
) -> Claim:
    return Claim(
        text=text,
        citation_ids=list(citation_ids),
        # Cast through the Literal at runtime via Pydantic.
        certainty=certainty,  # type: ignore[arg-type]
    )


def _tool_record() -> ToolCallRecord:
    return ToolCallRecord(
        tool_name="get_current_medications",
        arguments_keys=["lookback_days"],
        result_count=1,
        latency_ms=42,
        error_class=None,
    )


def _supported_response(
    *,
    claims: Sequence[Claim] | None = None,
    missing_or_uncertain: Sequence[MissingOrUncertain] = (),
    citations: Sequence[Citation] = (),
    verification_status: str = "passed",
) -> CopilotRunResponse:
    """Wire shape mirroring the PHP supportedAnswer fixture."""
    actual_claims = list(claims) if claims is not None else [_claim()]
    block = AnswerBlock(
        heading="Current medications",
        claims=actual_claims,
    )
    return CopilotRunResponse(
        answer_blocks=[block],
        claims=list(actual_claims),
        citation_ids=sorted({cid for c in actual_claims for cid in c.citation_ids}),
        certainty="high",
        missing_or_uncertain=list(missing_or_uncertain),
        citations=list(citations),
        tool_sequence=[_tool_record()],
        verification_status=verification_status,  # type: ignore[arg-type]
        cost_usd=0.0,
        latency_ms_per_step={"plan": 12, "tool_calls": 42},
        trace_id="aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee",
    )


def _rule_ids(result: VerificationResult) -> set[str]:
    return {f.rule_id for f in result.findings}


def _has_fail(result: VerificationResult, rule_id: str) -> bool:
    return any(
        f.severity == "fail" and f.rule_id == rule_id
        for f in result.findings
    )


# ---------------------------------------------------------------------------
# Pass path
# ---------------------------------------------------------------------------


class TestPassesCleanAnswers:
    def test_clean_answer_with_known_citation_passes(self) -> None:
        verifier = AnswerVerifier()
        result = verifier.verify(
            response=_supported_response(),
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "passed"
        assert result.refusal_reason is None
        # No fail findings are emitted; warns are tolerated.
        assert all(f.severity != "fail" for f in result.findings)

    def test_safe_missingness_passes(self) -> None:
        """A "we did not find that data" response with safe phrasing passes.

        Mirrors the PHP behaviour: claims with an uncited certainty and
        safe-missingness wording are accepted without citations.
        """
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="No matching medication records were found in checked evidence.",
                    citation_ids=(),
                    certainty="not_found",
                ),
            ],
            missing_or_uncertain=[
                MissingOrUncertain(
                    text="Medication verification date was not found in checked evidence.",
                    citation_ids=[],
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )

        assert result.status == "passed"
        assert not _rule_ids(result) & {
            RuleId.UNSUPPORTED_CLAIM,
            RuleId.UNSAFE_MISSINGNESS,
            RuleId.CLAIM_MISSING_CITATION,
        }


# ---------------------------------------------------------------------------
# Refusal rules
# ---------------------------------------------------------------------------


class TestClaimMissingCitation:
    def test_high_certainty_claim_with_no_citations_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Metformin 500 mg twice daily is listed.",
                    citation_ids=(),
                    certainty="active",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert _has_fail(result, RuleId.CLAIM_MISSING_CITATION)


class TestFabricatedCitationId:
    def test_unknown_citation_id_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Metformin 500 mg twice daily is listed.",
                    citation_ids=("medication:lists_medication:99999",),
                    certainty="active",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert result.refusal_reason == "fabricated_citation"
        assert _has_fail(result, RuleId.FABRICATED_CITATION_ID)

    def test_known_citation_id_passes(self) -> None:
        verifier = AnswerVerifier()
        result = verifier.verify(
            response=_supported_response(),
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "passed"

    def test_fabricated_missingness_citation_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            missing_or_uncertain=[
                MissingOrUncertain(
                    text="Allergy panel was not found in checked evidence.",
                    citation_ids=["medication:lists_medication:not-real"],
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert _has_fail(result, RuleId.FABRICATED_MISSINGNESS_CITATION)


class TestUnsupportedClaim:
    def test_high_certainty_with_no_citations_fails_unsupported(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Lisinopril 10 mg daily is listed as active.",
                    citation_ids=(),
                    certainty="active",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert _has_fail(result, RuleId.UNSUPPORTED_CLAIM)


class TestOutOfScopeAdvice:
    @pytest.mark.parametrize(
        "advice_text",
        [
            "I recommend taking aspirin daily.",
            "The clinician should increase Metformin today.",
            "Please prescribe lisinopril.",
            "Consider stopping Coumadin.",
            "This appears to diagnose hypertension definitively.",
            "Place an order for chest x-ray.",
        ],
    )
    def test_advice_phrases_are_refused(self, advice_text: str) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text=advice_text, certainty="supported"),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert result.refusal_reason == "out_of_scope"
        assert _has_fail(result, RuleId.OUT_OF_SCOPE_ADVICE)


class TestToolErrorHidden:
    def test_passed_status_with_failed_tool_and_no_acknowledgement_fails(self) -> None:
        verifier = AnswerVerifier()
        # Answer text mentions nothing about tool unavailability.
        response = _supported_response(
            claims=[
                _claim(
                    text="Metformin 500 mg twice daily is listed.",
                    citation_ids=(KNOWN_CITATION_ID,),
                    certainty="active",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=False,
        )

        assert result.status == "refused"
        assert result.refusal_reason == "tool_error"
        assert _has_fail(result, RuleId.TOOL_ERROR_HIDDEN)

    def test_acknowledged_failure_passes(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Medication tool was unavailable for this run.",
                    citation_ids=(),
                    certainty="not_checked",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=False,
        )

        assert result.status == "passed"


class TestPhiInOutput:
    def test_ssn_dashed_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text="Identifier noted: 123-45-6789 in record."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert result.refusal_reason == "phi_in_output"
        assert _has_fail(result, RuleId.PHI_IN_OUTPUT)

    def test_ssn_keyword_with_9_digits_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text="Patient SSN 123456789 reported."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert _has_fail(result, RuleId.PHI_IN_OUTPUT)

    def test_phone_in_output_fails_by_default(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text="Contact 415-555-2671 today."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert _has_fail(result, RuleId.PHI_IN_OUTPUT)

    def test_phone_passes_when_allowed(self) -> None:
        verifier = AnswerVerifier(allowed_phi_in_output={"phone"})
        response = _supported_response(
            claims=[
                _claim(text="Patient preferred number is 415-555-2671."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "passed"

    def test_email_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text="Email on file: jane.doe@example.com noted."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert _has_fail(result, RuleId.PHI_IN_OUTPUT)

    def test_address_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(text="Address: 123 Maple Street recorded."),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert _has_fail(result, RuleId.PHI_IN_OUTPUT)


class TestCompletenessStatementInMissing:
    def test_completeness_statement_in_missing_uncertain_fails(self) -> None:
        # Direct port of testRejectsCompletenessStatementInMissingOrUncertain
        # from the PHP fixture.
        verifier = AnswerVerifier()
        response = _supported_response(
            missing_or_uncertain=[
                MissingOrUncertain(
                    text="No additional current medications were found in checked evidence.",
                    citation_ids=[KNOWN_CITATION_ID],
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        assert _has_fail(result, RuleId.COMPLETENESS_STATEMENT)


class TestUnsafeMissingnessPhrasing:
    def test_missing_in_claim_without_safe_wording_fails(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="The medication is missing from the chart.",
                    citation_ids=(),
                    certainty="not_found",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )

        assert _has_fail(result, RuleId.UNSAFE_MISSINGNESS)


# ---------------------------------------------------------------------------
# Refusal precedence
# ---------------------------------------------------------------------------


class TestRefusalPrecedence:
    def test_phi_beats_other_failures(self) -> None:
        """When multiple rules fail, PHI takes the most-specific reason."""
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Identifier 123-45-6789 should take aspirin.",
                    citation_ids=("fabricated:1",),
                    certainty="active",
                ),
            ],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )

        assert result.status == "refused"
        # phi_in_output wins over out_of_scope/fabricated/unsupported.
        assert result.refusal_reason == "phi_in_output"


# ---------------------------------------------------------------------------
# to_refusal_response wiring
# ---------------------------------------------------------------------------


class TestToRefusalResponse:
    def test_refused_result_produces_refused_response(self) -> None:
        verifier = AnswerVerifier()
        response = _supported_response(
            claims=[
                _claim(
                    text="Lisinopril 10 mg daily is listed as active.",
                    citation_ids=(),
                    certainty="active",
                ),
            ],
        )
        result = verifier.verify(
            response=response,
            known_citation_ids=frozenset(),
            tool_call_succeeded=True,
        )
        assert result.status == "refused"

        builder = ResponseBuilder()
        refusal = to_refusal_response(
            builder=builder,
            result=result,
            tool_sequence=[_tool_record()],
            cost_usd=0.0,
            latency_ms_per_step={"plan": 1},
            trace_id="trace-abc",
        )

        assert isinstance(refusal, CopilotRunResponse)
        assert refusal.verification_status == "refused"
        # Builder uses canonical refusal phrasing for "unsupported".
        assert refusal.answer_blocks[0].claims[0].text == REFUSAL_PHRASING["unsupported"]
        # The first fail finding's message becomes the explanation body.
        assert refusal.answer_blocks[0].body_markdown is not None
        # Trace ID and tool sequence are passed through unchanged.
        assert refusal.trace_id == "trace-abc"
        assert refusal.tool_sequence == [_tool_record()]

    def test_passed_result_raises_value_error(self) -> None:
        result = VerificationResult(
            status="passed",
            findings=tuple(),
            refusal_reason=None,
        )
        with pytest.raises(ValueError):
            to_refusal_response(
                builder=ResponseBuilder(),
                result=result,
                tool_sequence=[],
                cost_usd=0.0,
                latency_ms_per_step={},
                trace_id="trace-xyz",
            )

    def test_phi_refusal_maps_to_verification_failed_phrasing(self) -> None:
        # PHI/fabricated_citation are not directly in the M14 builder
        # taxonomy; they should map to the canonical "verification_failed"
        # phrasing rather than crash.
        result = VerificationResult(
            status="refused",
            findings=(
                VerificationFinding(
                    severity="fail",
                    rule_id=RuleId.PHI_IN_OUTPUT,
                    message="Sample PHI message (no patient data).",
                    path="answer_blocks[0].claims[0].text",
                ),
            ),
            refusal_reason="phi_in_output",
        )

        refusal = to_refusal_response(
            builder=ResponseBuilder(),
            result=result,
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-phi",
        )

        assert refusal.verification_status == "refused"
        assert refusal.answer_blocks[0].claims[0].text == REFUSAL_PHRASING[
            "verification_failed"
        ]


# ---------------------------------------------------------------------------
# Empty/edge claim text
# ---------------------------------------------------------------------------


class TestEdgeCases:
    def test_warn_only_does_not_refuse(self) -> None:
        verifier = AnswerVerifier()
        # 'conflicting' certainty emits a warn finding but does not refuse.
        response = _supported_response(
            claims=[_claim(certainty="conflicting")],
        )

        result = verifier.verify(
            response=response,
            known_citation_ids=KNOWN_CITATION_IDS,
            tool_call_succeeded=True,
        )

        assert result.status == "passed"
        # Warn finding present.
        assert any(
            f.severity == "warn" and f.rule_id == "conflicting_claim"
            for f in result.findings
        )
