"""Tests for the eval runner CLI and rubric scoring (S20).

Verifies that:

* the runner loads exactly 50 fixtures from disk
* each run produces a per-rubric pass-rate report
* the CLI exits 0 against the recorded baseline (and writes one on
  first run)
* injecting the ``drop-citations`` regression flips ``citation_present``
  (and the cascade) below threshold, surfacing a non-zero exit code
* no live OpenAI/Cohere client is ever instantiated -- the
  ``FakeLLMClient`` env-var guard rejects any leaked API key
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

import pytest

from agent_service.clients.openai_client import FakeLLMClient
from agent_service.eval import __main__ as eval_main
from agent_service.eval.runner import (
    DEFAULT_THRESHOLDS,
    EVAL_MODE_ENV,
    REGRESSION_TOLERANCE,
    RUBRIC_NAMES,
    EvalReport,
    compare_to_baseline,
    load_baseline,
    load_fixtures,
    run_eval,
    write_baseline,
)


# ---------------------------------------------------------------------------
# Test helpers
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _scrub_api_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    """Ensure no real API key is in the env for any test in this module.

    The runner explicitly forbids real API clients.  Even if the dev
    machine has these set, tests must run in a clean shell.
    """
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)


# ---------------------------------------------------------------------------
# Fixture loading
# ---------------------------------------------------------------------------


def test_loads_exactly_fifty_fixtures() -> None:
    """The runner must load all 50 fixtures from the manifest."""
    fixtures = load_fixtures()
    assert len(fixtures) == 50

    case_ids = {f.case_id for f in fixtures}
    assert len(case_ids) == 50, "Duplicate case_id values in fixtures"

    # Sanity: 25 lab + 25 intake.
    lab = sum(1 for f in fixtures if f.doc_type == "lab_pdf")
    intake = sum(1 for f in fixtures if f.doc_type == "intake_form")
    assert lab == 25
    assert intake == 25


def test_fixture_recorded_responses_have_upload_and_extract() -> None:
    """Every fixture's recorded response must have both maps populated."""
    for fixture in load_fixtures():
        recorded = fixture.recorded_openai_response
        assert "upload_responses" in recorded, fixture.case_id
        assert "extract_responses" in recorded, fixture.case_id


# ---------------------------------------------------------------------------
# Pass-rate reporting
# ---------------------------------------------------------------------------


def test_runner_produces_per_rubric_pass_rate_report() -> None:
    """run_eval returns a report covering all five rubrics."""
    report = run_eval()
    assert isinstance(report, EvalReport)
    assert report.total == 50
    assert len(report.cases) == 50

    for name in RUBRIC_NAMES:
        assert name in report.pass_rates
        rate = report.pass_rates[name]
        assert 0.0 <= rate <= 1.0


def test_runner_report_has_serialisable_dict() -> None:
    """EvalReport.as_dict round-trips through JSON without errors."""
    report = run_eval()
    payload = report.as_dict()
    serialised = json.dumps(payload)
    parsed = json.loads(serialised)
    assert parsed["total_cases"] == 50
    assert "pass_rates" in parsed
    assert "cases" in parsed
    assert len(parsed["cases"]) == 50


def test_runner_meets_default_thresholds_on_clean_run() -> None:
    """Without injected regressions the runner must meet every threshold."""
    report = run_eval()
    for name in RUBRIC_NAMES:
        rate = report.pass_rates[name]
        threshold = DEFAULT_THRESHOLDS[name]
        assert rate + 1e-9 >= threshold, (
            f"{name}: {rate:.2%} below threshold {threshold:.2%}"
        )


# ---------------------------------------------------------------------------
# CLI exit codes
# ---------------------------------------------------------------------------


def test_cli_exits_zero_when_baseline_missing(tmp_path: Path) -> None:
    """First run writes a baseline and exits 0."""
    baseline_path = tmp_path / "baseline.json"
    assert not baseline_path.exists()

    exit_code = eval_main.main(["--baseline", str(baseline_path)])

    assert exit_code == 0
    assert baseline_path.exists()

    payload = json.loads(baseline_path.read_text(encoding="utf-8"))
    assert payload["total_cases"] == 50
    assert "pass_rates" in payload
    for name in RUBRIC_NAMES:
        assert name in payload["pass_rates"]


def test_cli_exits_zero_when_baseline_matches(tmp_path: Path) -> None:
    """A second run against the freshly-written baseline must also exit 0."""
    baseline_path = tmp_path / "baseline.json"

    first = eval_main.main(["--baseline", str(baseline_path)])
    assert first == 0
    assert baseline_path.exists()

    second = eval_main.main(["--baseline", str(baseline_path)])
    assert second == 0


def test_cli_writes_per_case_output_when_requested(tmp_path: Path) -> None:
    """`--output` produces a JSON file with all 50 case entries."""
    baseline_path = tmp_path / "baseline.json"
    output_path = tmp_path / "results.json"

    exit_code = eval_main.main(
        [
            "--baseline",
            str(baseline_path),
            "--output",
            str(output_path),
        ]
    )

    assert exit_code == 0
    assert output_path.exists()
    payload = json.loads(output_path.read_text(encoding="utf-8"))
    assert payload["total_cases"] == 50
    assert len(payload["cases"]) == 50


def test_cli_against_repo_baseline_exits_zero() -> None:
    """The shipped baseline.json must continue to be satisfied."""
    repo_baseline = (
        Path(__file__).resolve().parent.parent
        / "agent_service"
        / "eval"
        / "baseline.json"
    )
    if not repo_baseline.is_file():
        pytest.skip("repo baseline.json not present yet")

    exit_code = eval_main.main(["--baseline", str(repo_baseline)])
    assert exit_code == 0


# ---------------------------------------------------------------------------
# Regression injection
# ---------------------------------------------------------------------------


def test_drop_citations_regression_breaks_citation_rubric(tmp_path: Path) -> None:
    """The drop-citations hook must drag citation_present below 100%.

    The cascade may also break other rubrics (because removing required
    source citations causes the extractor to refuse), but the spec only
    requires that citation_present definitively regresses.
    """
    baseline_path = tmp_path / "baseline.json"

    # Materialise a clean baseline first.
    initial_exit = eval_main.main(["--baseline", str(baseline_path)])
    assert initial_exit == 0
    baseline = load_baseline(baseline_path)
    assert baseline is not None
    assert baseline["citation_present"] >= 1.0 - 1e-9

    # Now run with the regression injected; expect a non-zero exit code.
    regression_exit = eval_main.main(
        [
            "--baseline",
            str(baseline_path),
            "--inject-regression",
            "drop-citations",
        ]
    )
    assert regression_exit == 1


def test_drop_citations_regression_pass_rates(tmp_path: Path) -> None:
    """Inspect the actual pass rates produced by the regression run."""
    report = run_eval(inject_regression="drop-citations")
    # Citation rubric must drop strictly below the absolute threshold.
    threshold = DEFAULT_THRESHOLDS["citation_present"]
    assert report.pass_rates["citation_present"] + 1e-9 < threshold

    # The PHI rubric must remain at 1.00 -- regressions never permit
    # PHI leaks regardless of what else is broken.
    assert report.pass_rates["no_phi_in_logs"] >= 1.0 - 1e-9


def test_unsupported_regression_raises_value_error() -> None:
    """Unknown regression names must surface as ValueError, not silent no-ops."""
    with pytest.raises(ValueError, match="Unknown regression type"):
        run_eval(inject_regression="not-a-real-regression")


# ---------------------------------------------------------------------------
# No live API calls
# ---------------------------------------------------------------------------


def test_runner_refuses_when_real_api_key_is_set(monkeypatch: pytest.MonkeyPatch) -> None:
    """The FakeLLMClient guard fires if OPENAI_API_KEY leaks into the env."""
    monkeypatch.setenv("OPENAI_API_KEY", "sk-do-not-use")
    with pytest.raises(RuntimeError, match="OPENAI_API_KEY"):
        # Try to construct a FakeLLMClient the same way run_eval does.
        FakeLLMClient(allow_env_key=False)


def test_eval_mode_env_var_set_during_run() -> None:
    """The runner exposes EVAL_MODE=1 to nested code paths."""
    captured: list[str | None] = []

    # Hook into the runner via a custom RAGPipeline-equivalent that records
    # the env var the moment it is asked to retrieve.  Easier: query the
    # env from inside a regression hook.
    from agent_service.eval import runner as runner_module

    original = runner_module._build_fake_client

    def spy(case: Any, *, inject_regression: str | None = None) -> Any:
        captured.append(os.environ.get(EVAL_MODE_ENV))
        return original(case, inject_regression=inject_regression)

    runner_module._build_fake_client = spy  # type: ignore[assignment]
    try:
        run_eval()
    finally:
        runner_module._build_fake_client = original  # type: ignore[assignment]

    assert captured, "spy did not record any env-var observations"
    assert all(value == "1" for value in captured), captured


def test_no_real_openai_client_constructed(monkeypatch: pytest.MonkeyPatch) -> None:
    """Patch openai.OpenAI to raise; if the runner ever constructs one the test fails."""
    import openai

    def _explode(*args: Any, **kwargs: Any) -> None:
        raise AssertionError(
            "Real OpenAI client was constructed during eval. "
            "FakeLLMClient should be the only LLM client used."
        )

    monkeypatch.setattr(openai, "OpenAI", _explode)
    # The eval run should complete without ever touching openai.OpenAI.
    report = run_eval()
    assert report.total == 50


# ---------------------------------------------------------------------------
# Baseline comparison logic
# ---------------------------------------------------------------------------


def test_compare_to_baseline_passes_when_rates_match(tmp_path: Path) -> None:
    """A run that exactly matches the baseline must compare clean."""
    report = run_eval()
    passed, failures = compare_to_baseline(report, dict(report.pass_rates))
    assert passed is True
    assert failures == []


def test_compare_to_baseline_flags_regression() -> None:
    """A drop greater than REGRESSION_TOLERANCE must be flagged."""
    report = run_eval()
    inflated_baseline = {
        name: min(rate + REGRESSION_TOLERANCE + 0.01, 1.0)
        for name, rate in report.pass_rates.items()
    }
    # Force the baseline above the observed rates.
    inflated_baseline = {
        name: 1.0 for name in report.pass_rates
    }
    # Manually break one rubric in the report to simulate a regression.
    fake_pass_rates = dict(report.pass_rates)
    fake_pass_rates["citation_present"] = 0.5
    broken = EvalReport(
        cases=report.cases,
        pass_rates=fake_pass_rates,
        total=report.total,
    )

    passed, failures = compare_to_baseline(broken, inflated_baseline)
    assert passed is False
    assert any("citation_present" in failure for failure in failures)


def test_compare_to_baseline_no_baseline_only_checks_thresholds() -> None:
    """When baseline is None, only absolute thresholds gate success."""
    report = run_eval()
    passed, failures = compare_to_baseline(report, None)
    # Clean runs meet every threshold.
    assert passed is True
    assert failures == []


def test_write_then_load_baseline_round_trip(tmp_path: Path) -> None:
    baseline_path = tmp_path / "baseline.json"
    report = run_eval()

    write_baseline(baseline_path, report)
    loaded = load_baseline(baseline_path)

    assert loaded is not None
    for name in RUBRIC_NAMES:
        assert abs(loaded[name] - report.pass_rates[name]) < 1e-4
