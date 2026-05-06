"""Tests for the M22 copilot-tools eval suite + rubric scorer.

Coverage:

* :func:`agent_service.eval.rubrics.copilot_tools.score_copilot_tools_rubrics`
  -- happy path (every rubric True) and per-rubric independent failures.
* :class:`agent_service.eval.copilot_tools_suite` -- end-to-end suite
  invocation through the ``--suite copilot-tools`` CLI surface.

The rubric scorer is exercised with synthetic
:class:`AgentLoopResult` / :class:`RunEvent` inputs constructed in-test
so each rubric can be flipped without standing up the real agent loop.
"""

from __future__ import annotations

import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import pytest

from agent_service.eval.copilot_tools_suite import (
    DEFAULT_COPILOT_TOOLS_FIXTURES_DIR,
    DEFAULT_REGRESSION_FIXTURES_DIR,
    load_fixtures,
    run_copilot_tools_suite,
)
from agent_service.eval.rubrics.copilot_tools import (
    COPILOT_TOOLS_RUBRIC_NAMES,
    CopilotToolsRubrics,
    score_copilot_tools_rubrics,
)
from agent_service.intents.catalog import IntentDefinition, default_catalog
from agent_service.loop.agent_loop import AgentLoopResult, HaltReason
from agent_service.observability.events import RunEvent
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunResponse,
    MissingOrUncertain,
    ToolCallRecord,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _intent_current_meds() -> IntentDefinition:
    return default_catalog().get("current_medications")


def _make_response(
    *,
    claims: list[Claim],
    citations: list[Citation],
    verification_status: str = "passed",
    missing: list[MissingOrUncertain] | None = None,
) -> CopilotRunResponse:
    return CopilotRunResponse(
        answer_blocks=[
            AnswerBlock(heading="Current medications", claims=claims),
        ],
        claims=claims,
        citation_ids=sorted({cid for c in claims for cid in c.citation_ids}),
        certainty="high" if verification_status == "passed" else "unknown",
        missing_or_uncertain=missing or [],
        citations=citations,
        tool_sequence=[],
        verification_status=verification_status,
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id="trace-test",
    )


def _make_tool_call_record(
    *,
    tool_name: str,
    error_class: str | None = None,
) -> ToolCallRecord:
    return ToolCallRecord(
        tool_name=tool_name,
        arguments_keys=[],
        result_count=None if error_class is not None else 1,
        latency_ms=1,
        error_class=error_class,
    )


def _make_event(
    *,
    event_type: str,
    refusal_reason: str | None = None,
    tool_name: str | None = None,
) -> RunEvent:
    return RunEvent(
        trace_id="trace-test",
        event_type=event_type,  # type: ignore[arg-type]
        occurred_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
        latency_ms=1,
        tool_name=tool_name,
        refusal_reason=refusal_reason,
    )


def _happy_response() -> CopilotRunResponse:
    citation = Citation(
        source_type="patient_record",
        source_id="med:1",
        label="Active medication list",
    )
    claim = Claim(
        text="Lisinopril 10 mg PO daily",
        citation_ids=["med:1"],
        certainty="active",
    )
    return _make_response(claims=[claim], citations=[citation])


def _happy_loop_result(
    *,
    tool_sequence: list[ToolCallRecord] | None = None,
    response: CopilotRunResponse | None = None,
    halt_reason: HaltReason = "completed",
) -> AgentLoopResult:
    if tool_sequence is None:
        tool_sequence = [_make_tool_call_record(tool_name="get_current_medications")]
    return AgentLoopResult(
        response=response if response is not None else _happy_response(),
        tool_sequence=tuple(tool_sequence),
        cost_usd=0.0,
        latency_ms_per_step={},
        halt_reason=halt_reason,
    )


# ---------------------------------------------------------------------------
# Rubric set wiring
# ---------------------------------------------------------------------------


def test_rubric_names_match_migration_doc() -> None:
    """Every rubric named in the migration doc is present, in stable order."""
    expected = (
        "tool_allowed",
        "tool_args_scoped",
        "required_evidence_checked",
        "citation_present",
        "factually_consistent",
        "safe_refusal",
        "no_phi_in_logs",
        "verification_passed",
    )
    assert COPILOT_TOOLS_RUBRIC_NAMES == expected
    rubrics = CopilotToolsRubrics(
        tool_allowed=True,
        tool_args_scoped=True,
        required_evidence_checked=True,
        citation_present=True,
        factually_consistent=True,
        safe_refusal=True,
        no_phi_in_logs=True,
        verification_passed=True,
    )
    assert tuple(rubrics.as_dict().keys()) == expected


# ---------------------------------------------------------------------------
# Happy path
# ---------------------------------------------------------------------------


def test_score_happy_path_passes_every_rubric() -> None:
    intent = _intent_current_meds()
    result = _happy_loop_result()
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(),
    )
    assert rubrics.all_passed()
    assert rubrics.factually_consistent is True
    assert rubrics.verification_passed is True


# ---------------------------------------------------------------------------
# Per-rubric isolated failures
# ---------------------------------------------------------------------------


def test_disallowed_tool_flips_tool_allowed_only() -> None:
    intent = _intent_current_meds()
    sequence = [
        _make_tool_call_record(tool_name="get_current_medications"),
        _make_tool_call_record(tool_name="get_active_allergies"),
    ]
    result = _happy_loop_result(tool_sequence=sequence)
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(),
    )
    assert rubrics.tool_allowed is False
    # Other rubrics are unaffected by the model picking an extra tool.
    assert rubrics.tool_args_scoped is True
    assert rubrics.required_evidence_checked is True
    assert rubrics.citation_present is True
    assert rubrics.factually_consistent is True
    assert rubrics.safe_refusal is True
    assert rubrics.no_phi_in_logs is True
    assert rubrics.verification_passed is True


def test_authority_field_rejection_flips_tool_args_scoped() -> None:
    intent = _intent_current_meds()
    sequence = [
        _make_tool_call_record(
            tool_name="get_current_medications",
            error_class="model_supplied_authority_field",
        ),
    ]
    result = _happy_loop_result(tool_sequence=sequence)
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(),
    )
    assert rubrics.tool_args_scoped is False
    # The rejected call also counts as "tool was not successfully used"
    # so required_evidence_checked falls.  Document the side effect
    # rather than hide it: it is the right semantic answer.
    assert rubrics.required_evidence_checked is False
    # tool_allowed is still about which tool name appeared, not whether
    # it succeeded -- the model picked an in-scope tool.
    assert rubrics.tool_allowed is True


def test_skipped_required_tool_flips_required_evidence_checked() -> None:
    intent = _intent_current_meds()
    # Empty tool sequence -- the model produced a final answer without
    # ever calling get_current_medications.
    result = _happy_loop_result(tool_sequence=[])
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(),
    )
    assert rubrics.required_evidence_checked is False
    assert rubrics.tool_allowed is True
    assert rubrics.tool_args_scoped is True


def test_empty_citation_ids_flips_citation_present() -> None:
    intent = _intent_current_meds()
    claim = Claim(
        text="Lisinopril 10 mg PO daily",
        citation_ids=[],
        certainty="active",
    )
    response = _make_response(
        claims=[claim],
        citations=[],
        verification_status="passed",
    )
    result = _happy_loop_result(response=response)
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(),
    )
    assert rubrics.citation_present is False


def test_verifier_refusal_flips_factually_consistent_and_keeps_safe_refusal() -> None:
    intent = _intent_current_meds()
    # Refusal envelope shape: a single not_checked claim with no
    # citations.  verification_status="refused" tells the verifier
    # rubric to fail.
    refusal_claim = Claim(
        text="A verified answer is not available.",
        citation_ids=[],
        certainty="not_checked",
    )
    response = _make_response(
        claims=[refusal_claim],
        citations=[],
        verification_status="refused",
    )
    result = _happy_loop_result(
        response=response,
        halt_reason="verifier_refused",
    )
    events = (
        _make_event(
            event_type="verifier.finished",
            refusal_reason="unsupported",
        ),
        _make_event(
            event_type="response.returned",
            refusal_reason="unsupported",
        ),
    )
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=events,
    )
    assert rubrics.factually_consistent is False
    assert rubrics.verification_passed is False
    # The refusal carries a reason from the safe enum.
    assert rubrics.safe_refusal is True


def test_phi_in_event_flips_no_phi_in_logs() -> None:
    intent = _intent_current_meds()

    # ``RunEvent`` rejects PHI at construction time, so we use
    # ``model_construct`` to bypass validation for this synthetic test
    # only -- documented inline so future readers know why.
    poisoned = RunEvent.model_construct(
        trace_id="trace-test",
        event_type="tool.finished",
        occurred_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
        latency_ms=1,
        tool_name="get_current_medications",
        # Synthetic SSN-like marker in the refusal_reason field; the
        # M16 PHI scanner flags this immediately.
        refusal_reason="contact: 123-45-6789 reviewer",
    )

    result = _happy_loop_result()
    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=(poisoned,),
    )
    assert rubrics.no_phi_in_logs is False


# ---------------------------------------------------------------------------
# Suite-level smoke tests
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _scrub_api_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    """Strip any API keys leaked from the host shell."""
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)


def test_default_fixture_directories_are_present() -> None:
    """Sanity check: the suite ships at least one positive + one regression fixture."""
    primary = load_fixtures(DEFAULT_COPILOT_TOOLS_FIXTURES_DIR)
    regressions = load_fixtures(DEFAULT_REGRESSION_FIXTURES_DIR)
    assert len(primary) >= 1
    assert len(regressions) >= 3, (
        "Expected disallowed-tool, missing-citation, and skipped-required-tool fixtures."
    )
    # Every regression fixture is flagged ``regression: true`` on disk
    # -- the suite runner forces the bucket regardless, but the on-disk
    # flag keeps fixture intent obvious to a future reader.
    for fixture in regressions:
        assert fixture.is_regression is True


def test_run_suite_matches_every_fixture_expectation() -> None:
    """Every shipped fixture lines up with its expected rubric snapshot."""
    report = run_copilot_tools_suite()
    assert report.cases, "Expected at least one positive case"
    assert report.regressions, "Expected at least three regression cases"
    failures = [c.fixture_id for c in report.cases if not c.matches_expected]
    assert not failures, f"Positive cases failed expectations: {failures}"
    reg_failures = [
        c.fixture_id for c in report.regressions if not c.matches_expected
    ]
    assert not reg_failures, f"Regression cases failed expectations: {reg_failures}"
    assert report.all_passed()


def test_regression_disallowed_tool_fails_tool_allowed() -> None:
    """The disallowed-tool fixture must fail ``tool_allowed`` and pass everything else."""
    report = run_copilot_tools_suite()
    [case] = [
        c
        for c in report.regressions
        if c.fixture_id == "regression_disallowed_tool"
    ]
    rubrics = case.rubrics.as_dict()
    assert rubrics["tool_allowed"] is False


def test_regression_missing_citation_fails_citation_rubric() -> None:
    report = run_copilot_tools_suite()
    [case] = [
        c
        for c in report.regressions
        if c.fixture_id == "regression_missing_citation"
    ]
    rubrics = case.rubrics.as_dict()
    assert rubrics["citation_present"] is False


def test_regression_skipped_required_tool_fails_required_rubric() -> None:
    report = run_copilot_tools_suite()
    [case] = [
        c
        for c in report.regressions
        if c.fixture_id == "regression_skipped_required_tool"
    ]
    rubrics = case.rubrics.as_dict()
    assert rubrics["required_evidence_checked"] is False


def test_cli_invocation_returns_zero_when_fixtures_match(tmp_path: Path) -> None:
    """``python -m agent_service.eval --suite copilot-tools`` must exit 0 today."""
    output_path = tmp_path / "report.json"
    result = subprocess.run(
        [
            sys.executable,
            "-m",
            "agent_service.eval",
            "--suite",
            "copilot-tools",
            "--output",
            str(output_path),
        ],
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0, (
        f"copilot-tools eval CLI failed:\nSTDOUT:\n{result.stdout}\n"
        f"STDERR:\n{result.stderr}"
    )
    assert output_path.is_file()
    summary = output_path.read_text(encoding="utf-8")
    assert "current_medications_happy" in summary


def test_cli_invocation_reports_failure_when_fixture_breaks(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """If a regression fixture's expectation flips, the CLI exits non-zero.

    We synthesise the failure via a temporary fixture directory whose
    expectation no longer matches reality.  This exercises the
    failure-summary path without modifying any shipped fixture.
    """
    # Copy the disallowed-tool fixture into a temp dir but flip the
    # expected ``tool_allowed`` rubric to ``true`` so the actual run's
    # ``False`` no longer matches.  Keep the file in a regression-only
    # directory so the suite classifies it correctly.
    src = (
        DEFAULT_REGRESSION_FIXTURES_DIR / "01_disallowed_tool.json"
    )
    import json

    payload: dict[str, Any] = json.loads(src.read_text(encoding="utf-8"))
    payload["expected"]["rubrics"]["tool_allowed"] = True
    payload["expected"]["expected_failure"] = False  # inverted expectation
    payload["regression"] = True

    fixture_dir = tmp_path / "regressions"
    fixture_dir.mkdir()
    (fixture_dir / "broken.json").write_text(
        json.dumps(payload),
        encoding="utf-8",
    )

    # Drive the suite with ONLY this broken fixture and an empty primary
    # bucket.  Use the API rather than the CLI so we can pin the
    # fixture directory explicitly; the CLI test above proves the
    # surface, this test proves the failure path.
    empty_dir = tmp_path / "primary"
    empty_dir.mkdir()
    report = run_copilot_tools_suite(
        fixtures_dirs=[empty_dir],
        regression_dirs=[fixture_dir],
    )
    assert not report.all_passed()
