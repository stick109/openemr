"""Smoke tests for the eval runner's regression-injection hooks (S22).

These tests prove that each supported ``--inject-regression`` option:

* Flips at least one rubric below its absolute threshold and surfaces a
  non-zero CLI exit code.
* Names a recognisable failing rubric AND lists at least one affected
  fixture in the structured failure summary printed to stderr.

The tests intentionally exercise the runner via the CLI surface so that
the full ``__main__.py`` -> ``runner.py`` plumbing is covered, including
the ``format_failure_summary`` output the demo video relies on.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from agent_service.eval import __main__ as eval_main
from agent_service.eval.runner import (
    DEFAULT_THRESHOLDS,
    SUPPORTED_REGRESSIONS,
    _maybe_inject_regression,
    affected_fixtures,
    format_failure_summary,
    load_baseline,
    run_eval,
)


# ---------------------------------------------------------------------------
# Test helpers
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _scrub_api_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    """Strip any API key leaked from the host shell before every test."""
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)


def _bootstrap_clean_baseline(tmp_path: Path) -> Path:
    """Run the eval once to materialise a clean baseline file at *tmp_path*.

    Returns the path to the newly-written baseline JSON.
    """
    baseline_path = tmp_path / "baseline.json"
    initial_exit = eval_main.main(["--baseline", str(baseline_path)])
    assert initial_exit == 0, "Clean run must exit 0 to seed the baseline"
    assert baseline_path.is_file()

    seeded = load_baseline(baseline_path)
    assert seeded is not None
    # Sanity: the freshly-seeded baseline must show a clean run.
    for rate in seeded.values():
        assert rate >= 1.0 - 1e-9
    return baseline_path


# ---------------------------------------------------------------------------
# Supported-regression registry stays in sync
# ---------------------------------------------------------------------------


def test_supported_regressions_includes_all_expected_kinds() -> None:
    """The CLI choices must contain every regression we exercise here."""
    assert "drop-citations" in SUPPORTED_REGRESSIONS
    assert "wrong-value" in SUPPORTED_REGRESSIONS
    assert "flip-abnormal-flags" in SUPPORTED_REGRESSIONS


# ---------------------------------------------------------------------------
# Per-regression smoke tests (CLI surface)
# ---------------------------------------------------------------------------


def test_drop_citations_breaks_citation_rubric_and_lists_fixtures(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    """``--inject-regression drop-citations`` must:

    * exit non-zero,
    * report ``citation_present`` as the regressed rubric, and
    * list at least one ``lab_*`` / ``intake_*`` fixture as affected.
    """
    baseline_path = _bootstrap_clean_baseline(tmp_path)

    exit_code = eval_main.main(
        [
            "--baseline",
            str(baseline_path),
            "--inject-regression",
            "drop-citations",
        ]
    )
    assert exit_code == 1

    captured = capsys.readouterr()
    output = captured.out + captured.err

    assert "FAIL" in output
    assert "citation_present" in output
    assert "affected fixtures" in output
    # At least one canonical fixture id should be named.
    assert "lab_001" in output or "intake_001" in output


def test_wrong_value_breaks_factually_consistent_and_lists_fixtures(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    """``--inject-regression wrong-value`` must break ``factually_consistent``.

    The injected mutation rewrites the first lab value (and chief
    concern for intakes), so the rubric pass-rate must collapse below
    threshold and the failure summary must enumerate the affected
    fixtures.
    """
    baseline_path = _bootstrap_clean_baseline(tmp_path)

    exit_code = eval_main.main(
        [
            "--baseline",
            str(baseline_path),
            "--inject-regression",
            "wrong-value",
        ]
    )
    assert exit_code == 1

    captured = capsys.readouterr()
    output = captured.out + captured.err

    assert "FAIL" in output
    assert "factually_consistent" in output
    assert "affected fixtures" in output
    # Lab fixtures are guaranteed affected because we bump the first
    # row's value on every lab fixture.
    assert "lab_001" in output


def test_flip_abnormal_flags_breaks_factually_consistent(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    """``--inject-regression flip-abnormal-flags`` must regress ``factually_consistent``.

    Unlike ``wrong-value`` this only touches lab fixtures whose rows
    actually carry ``high`` / ``low`` flags, so we additionally verify
    that the CLI exit code is non-zero and the rubric is named.
    """
    baseline_path = _bootstrap_clean_baseline(tmp_path)

    exit_code = eval_main.main(
        [
            "--baseline",
            str(baseline_path),
            "--inject-regression",
            "flip-abnormal-flags",
        ]
    )
    assert exit_code == 1

    captured = capsys.readouterr()
    output = captured.out + captured.err

    assert "FAIL" in output
    assert "factually_consistent" in output
    assert "affected fixtures" in output


# ---------------------------------------------------------------------------
# Pass-rate level checks (run_eval surface)
# ---------------------------------------------------------------------------


def test_wrong_value_factually_consistent_below_threshold() -> None:
    """The pass rate produced under wrong-value must drop below the gate."""
    report = run_eval(inject_regression="wrong-value")
    threshold = DEFAULT_THRESHOLDS["factually_consistent"]
    observed = report.pass_rates["factually_consistent"]
    assert observed + 1e-9 < threshold, (
        f"wrong-value did not drop factually_consistent below threshold: "
        f"observed {observed:.2%}, threshold {threshold:.2%}"
    )


def test_flip_abnormal_flags_factually_consistent_below_threshold() -> None:
    """flip-abnormal-flags must also breach the absolute threshold."""
    report = run_eval(inject_regression="flip-abnormal-flags")
    threshold = DEFAULT_THRESHOLDS["factually_consistent"]
    observed = report.pass_rates["factually_consistent"]
    assert observed + 1e-9 < threshold, (
        f"flip-abnormal-flags did not drop factually_consistent below "
        f"threshold: observed {observed:.2%}, threshold {threshold:.2%}"
    )


def test_each_regression_names_affected_fixtures_in_report() -> None:
    """Every supported regression must produce a non-empty affected list.

    Drives :func:`affected_fixtures` for each regression rubric so the
    demo summary always has something to print.
    """
    expected_failing_rubric = {
        "drop-citations": "citation_present",
        "wrong-value": "factually_consistent",
        "flip-abnormal-flags": "factually_consistent",
    }
    for kind, rubric in expected_failing_rubric.items():
        report = run_eval(inject_regression=kind)
        affected = affected_fixtures(report, rubric)
        assert affected, (
            f"{kind} produced an empty affected-fixtures list for {rubric}"
        )


# ---------------------------------------------------------------------------
# Format helpers
# ---------------------------------------------------------------------------


def test_format_failure_summary_contains_rubric_and_threshold() -> None:
    """The structured summary must mention the rubric, delta, threshold, and IDs."""
    report = run_eval(inject_regression="wrong-value")
    summary = format_failure_summary(report, baseline=None)

    assert "FAIL" in summary
    assert "factually_consistent" in summary
    assert "threshold" in summary
    assert "affected fixtures" in summary


def test_format_failure_summary_clean_run_says_pass() -> None:
    """A clean run must produce the all-clear PASS summary."""
    report = run_eval()
    summary = format_failure_summary(report, baseline=None)
    assert summary.startswith("PASS")


# ---------------------------------------------------------------------------
# Determinism
# ---------------------------------------------------------------------------


def test_wrong_value_is_deterministic_across_runs() -> None:
    """Two consecutive wrong-value injections must produce identical mutations."""
    sample = {
        "results": [
            {
                "test_name": "WBC",
                "value": "6.5",
                "abnormal_flag": "normal",
                "source_citation": {"page": 1},
            }
        ],
        "chief_concern": "test",
    }
    first = _maybe_inject_regression(sample, "wrong-value")
    second = _maybe_inject_regression(sample, "wrong-value")
    assert first == second
    # And the original input must NOT have been mutated.
    assert sample["results"][0]["value"] == "6.5"
    assert sample["results"][0]["abnormal_flag"] == "normal"
    assert sample["chief_concern"] == "test"


def test_flip_abnormal_flags_is_symmetric() -> None:
    """Applying flip-abnormal-flags twice should round-trip the data."""
    sample = {
        "results": [
            {"abnormal_flag": "high"},
            {"abnormal_flag": "low"},
            {"abnormal_flag": "critical_high"},
            {"abnormal_flag": "critical_low"},
            {"abnormal_flag": "normal"},
        ],
    }
    once = _maybe_inject_regression(sample, "flip-abnormal-flags")
    twice = _maybe_inject_regression(once, "flip-abnormal-flags")
    assert twice == sample
