"""Tests for the M14 answer block schema and response builder.

These tests pin the wire shape produced by
``agent_service.answer.ResponseBuilder`` against:

* the ``CopilotRunResponse`` Pydantic contract (M2 / M14),
* the deterministic refusal phrasing in
  ``agent_service.answer.builder.REFUSAL_PHRASING``,
* the field names the chart UI in
  ``interface/patient_file/summary/agent_panel.js`` reads from
  ``data.answer`` (``answer_blocks``, ``claims``, ``missing_or_uncertain``,
  ``citations``).
"""

from __future__ import annotations

import json

import pytest

from agent_service.answer import (
    REFUSAL_PHRASING,
    SAFE_MISSINGNESS_PROLOGUE,
    RefusalReason,
    ResponseBuilder,
)
from agent_service.schemas.copilot import (
    Citation,
    Claim,
    CopilotRunResponse,
    MissingOrUncertain,
    ToolCallRecord,
)
from agent_service.schemas.evidence import (
    EvidenceEnvelope,
    EvidenceSourceType,
    ScopeSummary,
)


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


def _scope() -> ScopeSummary:
    return ScopeSummary(
        patient_id_present=True,
        encounter_id_present=False,
        lookback_days_used=365,
        max_rows_used=25,
        truncated=False,
        source_types_checked=(EvidenceSourceType.MEDICATION,),
    )


def _envelope_with_sources(
    *,
    tool_name: str = "get_current_medications",
    citations: tuple[Citation, ...] = (),
) -> EvidenceEnvelope:
    return EvidenceEnvelope(
        records=(),
        sources=citations,
        tool_name=tool_name,
        warnings=(),
        checked_scope=_scope(),
    )


def _citation(source_id: str, label: str = "Active medication list") -> Citation:
    return Citation(
        source_type="patient_record",
        source_id=source_id,
        label=label,
        url=None,
        snippet=None,
    )


def _tool_seq() -> list[ToolCallRecord]:
    return [
        ToolCallRecord(
            tool_name="get_current_medications",
            arguments_keys=["lookback_days"],
            result_count=2,
            latency_ms=42,
            error_class=None,
        ),
    ]


def _builder(*, escape: bool = True) -> ResponseBuilder:
    return ResponseBuilder(escape_for_html=escape)


# ---------------------------------------------------------------------------
# Success path
# ---------------------------------------------------------------------------


class TestBuildSuccess:
    def test_success_response_validates_against_contract(self) -> None:
        envelopes = (
            _envelope_with_sources(citations=(_citation("med:1"),)),
        )
        claims = [
            Claim(
                text="Lisinopril 10 mg PO daily",
                citation_ids=["med:1"],
                certainty="active",
            ),
        ]

        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=envelopes,
            claims=claims,
            certainty="high",
            tool_sequence=_tool_seq(),
            cost_usd=0.0123,
            latency_ms_per_step={"plan": 14, "tool_calls": 160},
            trace_id="aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee",
            heading="Current medications",
        )

        assert isinstance(response, CopilotRunResponse)
        assert response.verification_status == "passed"
        assert response.certainty == "high"
        assert response.answer_blocks[0].heading == "Current medications"
        assert len(response.answer_blocks[0].claims) == 1
        assert response.claims == response.answer_blocks[0].claims

    def test_citation_ids_are_deduplicated_and_sorted(self) -> None:
        claims = [
            Claim(
                text="Lisinopril 10 mg",
                citation_ids=["med:zebra", "med:alpha"],
                certainty="active",
            ),
            Claim(
                text="Atorvastatin 20 mg",
                citation_ids=["med:alpha", "med:mu"],
                certainty="active",
            ),
        ]

        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=claims,
            certainty="high",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.citation_ids == ["med:alpha", "med:mu", "med:zebra"]

    def test_citations_are_collected_and_deduplicated_by_source_id(self) -> None:
        env_a = _envelope_with_sources(
            citations=(
                _citation("med:1", label="From envelope A"),
                _citation("med:2", label="Also A"),
            ),
        )
        env_b = _envelope_with_sources(
            tool_name="get_allergies",
            citations=(
                _citation("med:1", label="Different label"),
                _citation("allergy:7"),
            ),
        )

        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(env_a, env_b),
            claims=[],
            certainty="unknown",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        ids = [c.source_id for c in response.citations]
        assert ids == ["med:1", "med:2", "allergy:7"]
        # First-occurrence wins on dedup.
        assert response.citations[0].label == "From envelope A"

    def test_empty_claims_still_produce_valid_response(self) -> None:
        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=[],
            certainty="unknown",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.answer_blocks[0].claims == []
        assert response.claims == []
        assert response.citation_ids == []
        assert response.citations == []

    def test_html_is_escaped_in_claim_text_heading_and_body(self) -> None:
        claims = [
            Claim(
                text="<script>alert('x')</script>",
                citation_ids=["med:1"],
                certainty="active",
            ),
        ]
        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=claims,
            certainty="high",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
            heading="<b>Heading</b>",
            body_markdown="A & B < C",
        )

        block = response.answer_blocks[0]
        assert block.heading == "&lt;b&gt;Heading&lt;/b&gt;"
        assert "&lt;script&gt;" in block.claims[0].text
        assert "<script" not in block.claims[0].text
        assert block.body_markdown == "A &amp; B &lt; C"

    def test_html_escaping_can_be_disabled(self) -> None:
        response = _builder(escape=False).build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=[
                Claim(
                    text="<b>raw</b>",
                    citation_ids=[],
                    certainty="active",
                ),
            ],
            certainty="high",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.answer_blocks[0].claims[0].text == "<b>raw</b>"


# ---------------------------------------------------------------------------
# Refusal path
# ---------------------------------------------------------------------------


class TestBuildRefusal:
    @pytest.mark.parametrize(
        "reason",
        [
            "missing_data",
            "unsupported",
            "out_of_scope",
            "tool_error",
            "verification_failed",
        ],
    )
    def test_each_reason_uses_canonical_phrasing(
        self, reason: RefusalReason
    ) -> None:
        response = _builder().build_refusal(
            reason=reason,
            explanation="Operator-supplied detail.",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.verification_status == "refused"
        assert response.certainty == "unknown"
        assert response.answer_blocks[0].claims[0].text == REFUSAL_PHRASING[reason]

    def test_refusal_is_deterministic(self) -> None:
        # Building the same refusal twice yields byte-identical JSON.
        kwargs = dict(
            reason="verification_failed",
            explanation="verifier rejected unsupported claims",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )
        first = _builder().build_refusal(**kwargs).model_dump_json()
        second = _builder().build_refusal(**kwargs).model_dump_json()
        assert first == second

    def test_refusal_explanation_is_html_escaped(self) -> None:
        response = _builder().build_refusal(
            reason="tool_error",
            explanation="<script>x</script>",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.answer_blocks[0].body_markdown is not None
        assert "&lt;script&gt;" in response.answer_blocks[0].body_markdown
        assert "<script" not in response.answer_blocks[0].body_markdown

    def test_refusal_has_no_citations_or_citation_ids(self) -> None:
        response = _builder().build_refusal(
            reason="out_of_scope",
            explanation="",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        assert response.citation_ids == []
        assert response.citations == []
        assert response.missing_or_uncertain == []


# ---------------------------------------------------------------------------
# Safe missingness path
# ---------------------------------------------------------------------------


class TestBuildSafeMissingness:
    def test_safe_missingness_returns_passed_status(self) -> None:
        response = _builder().build_safe_missingness(
            intent_id="current_medications",
            missing_kinds=["medication"],
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        # "We checked and found nothing" is a valid answer, not a refusal.
        assert response.verification_status == "passed"
        assert "medication" in response.answer_blocks[0].claims[0].text
        assert response.missing_or_uncertain[0].text == SAFE_MISSINGNESS_PROLOGUE

    def test_safe_missingness_emits_one_claim_per_kind(self) -> None:
        response = _builder().build_safe_missingness(
            intent_id="basic_patient_data",
            missing_kinds=["medication", "allergy"],
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        texts = [c.text for c in response.answer_blocks[0].claims]
        assert len(texts) == 2
        assert any("medication" in t for t in texts)
        assert any("allergy" in t for t in texts)

    def test_safe_missingness_with_no_kinds_uses_generic_text(self) -> None:
        response = _builder().build_safe_missingness(
            intent_id="current_medications",
            missing_kinds=[],
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        claim_text = response.answer_blocks[0].claims[0].text
        assert "No matching records" in claim_text


# ---------------------------------------------------------------------------
# Wire-shape checks (UI compatibility)
# ---------------------------------------------------------------------------


class TestWireShape:
    def test_top_level_keys_match_what_the_ui_expects(self) -> None:
        ui_required = {"answer_blocks", "missing_or_uncertain", "citations"}
        m14_added = {"claims", "citation_ids", "certainty"}

        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=[
                Claim(
                    text="Lisinopril 10 mg",
                    citation_ids=["med:1"],
                    certainty="active",
                ),
            ],
            certainty="high",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        dumped = response.model_dump()
        for key in ui_required | m14_added:
            assert key in dumped, f"missing key for UI/verifier: {key}"

    def test_block_and_claim_keys_match_ui_renderer(self) -> None:
        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(),
            claims=[
                Claim(
                    text="Lisinopril 10 mg",
                    citation_ids=["med:1"],
                    certainty="active",
                ),
            ],
            certainty="high",
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
            heading="Current medications",
        )

        block = response.model_dump()["answer_blocks"][0]
        assert "heading" in block
        assert "claims" in block
        claim = block["claims"][0]
        assert set(claim.keys()) >= {"text", "citation_ids", "certainty"}

    def test_missing_or_uncertain_items_have_text_and_citation_ids(self) -> None:
        response = _builder().build_safe_missingness(
            intent_id="basic_patient_data",
            missing_kinds=["medication"],
            tool_sequence=[],
            cost_usd=0.0,
            latency_ms_per_step={},
            trace_id="trace-id",
        )

        item = response.model_dump()["missing_or_uncertain"][0]
        assert set(item.keys()) >= {"text", "citation_ids"}

    def test_response_serialises_to_valid_json(self) -> None:
        response = _builder().build_success(
            intent_id="current_medications",
            evidence_envelopes=(_envelope_with_sources(citations=(_citation("med:1"),)),),
            claims=[
                Claim(
                    text="Lisinopril 10 mg",
                    citation_ids=["med:1"],
                    certainty="active",
                ),
            ],
            certainty="high",
            tool_sequence=_tool_seq(),
            cost_usd=0.01,
            latency_ms_per_step={"plan": 1},
            trace_id="trace-id",
        )

        encoded = response.model_dump_json()
        decoded = json.loads(encoded)
        re_validated = CopilotRunResponse.model_validate(decoded)
        assert re_validated.model_dump() == response.model_dump()

    def test_missing_or_uncertain_object_validation(self) -> None:
        item = MissingOrUncertain(
            text="Address was not found in checked evidence.",
            citation_ids=[],
        )
        assert item.text.startswith("Address")
        assert item.citation_ids == []
