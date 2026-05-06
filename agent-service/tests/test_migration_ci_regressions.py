"""M23 belt-and-suspenders meta-test for the Clinical Co-Pilot migration.

This test asserts that the M22 ``copilot-tools`` eval suite catches the
regression patterns the CI gate is supposed to block:

* a model invoking a tool outside the intent's ``allowed_tools`` set
* a final response with claims but missing citations
* a model skipping the intent's required evidence tools

If any of these regression fixtures stop failing their target rubric,
this test fails -- which is the M23 promise that "CI fails under an
injected disallowed-tool or missing-citation regression".

The test runs the suite in-process (via
:func:`agent_service.eval.copilot_tools_suite.run_copilot_tools_suite`)
*and* shells out to ``python -m agent_service.eval --suite copilot-tools``
to confirm the CLI surface that the GitHub Actions job calls also exits
non-zero when a regression-only run is forced.

The test deliberately does not depend on a live LLM, network, or
database.  ``OPENAI_API_KEY`` is forced empty so the FakeLLMClient guard
in the agent loop activates regardless of the host shell environment.
"""

from __future__ import annotations

import subprocess
import sys
from datetime import date
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock

import pytest

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.eval.copilot_tools_suite import (
    DEFAULT_COPILOT_TOOLS_FIXTURES_DIR,
    DEFAULT_REGRESSION_FIXTURES_DIR,
    load_fixtures,
    run_copilot_tools_suite,
    run_fixture,
)
from agent_service.repository import OpenEmrReadRepository
from agent_service.schemas.evidence import EvidenceSourceType


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _scrub_api_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    """Strip API keys leaked from the host shell before every test.

    The agent loop's :class:`FakeLLMClient` guard refuses to construct
    when a real key is set; the regression meta-test deliberately
    exercises the deterministic loop path, so we mirror what the CI
    job does and unset both keys.
    """
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)


# ---------------------------------------------------------------------------
# Inventory: confirm both buckets contain the cases CI is supposed to gate
# ---------------------------------------------------------------------------


def test_three_happy_fixtures_present() -> None:
    """The primary bucket carries at least three happy fixtures.

    M23 verification: the CI gate is meaningful only when the happy
    bucket is non-empty.  Lock the count at the M22-shipped value so a
    quiet deletion is caught.
    """
    fixtures = load_fixtures(DEFAULT_COPILOT_TOOLS_FIXTURES_DIR)
    happy = [f for f in fixtures if not f.is_regression]
    assert len(happy) >= 3, (
        f"Expected at least three primary copilot-tools fixtures; "
        f"found {len(happy)} in {DEFAULT_COPILOT_TOOLS_FIXTURES_DIR}"
    )


def test_three_regression_fixtures_present() -> None:
    """The regression bucket carries the disallowed-tool, missing-citation,
    and skipped-required-tool injections that M23 promises CI catches.
    """
    fixtures = load_fixtures(DEFAULT_REGRESSION_FIXTURES_DIR)
    fixture_ids = {f.fixture_id for f in fixtures}
    expected = {
        "regression_disallowed_tool",
        "regression_missing_citation",
        "regression_skipped_required_tool",
    }
    missing = expected - fixture_ids
    assert not missing, (
        f"Missing M23-required regression fixtures: {sorted(missing)}; "
        f"found {sorted(fixture_ids)}"
    )


# ---------------------------------------------------------------------------
# Per-fixture rubric assertions: happy passes, regression fails its target
# ---------------------------------------------------------------------------


def test_happy_fixtures_pass_every_rubric() -> None:
    """Every primary fixture passes all eight rubrics.

    A regression that quietly breaks one of the happy fixtures (for
    example, an evidence-tool change that drops a citation) would let
    the suite-level exit code stay green if we only checked the
    regression bucket.  Pin every rubric for every primary fixture.
    """
    report = run_copilot_tools_suite(regression_dirs=[])
    assert report.cases, "expected at least one primary case"
    failures: list[tuple[str, dict[str, bool]]] = []
    for case in report.cases:
        if not case.rubrics.all_passed():
            failures.append((case.fixture_id, case.rubrics.as_dict()))
    assert not failures, (
        "Primary copilot-tools fixtures must pass every rubric; "
        f"failures: {failures}"
    )


def test_regression_disallowed_tool_breaks_tool_allowed() -> None:
    """The disallowed-tool fixture must fail the ``tool_allowed`` rubric."""
    case = _run_regression_case("regression_disallowed_tool")
    rubrics = case.rubrics.as_dict()
    assert rubrics["tool_allowed"] is False, (
        f"regression_disallowed_tool should fail tool_allowed; got {rubrics}"
    )
    assert case.matches_expected, (
        "regression fixture's outcome did not match its expected block; "
        "the fixture's regression hook is no longer effective"
    )


def test_regression_missing_citation_breaks_citation_present() -> None:
    """The missing-citation fixture must fail ``citation_present``."""
    case = _run_regression_case("regression_missing_citation")
    rubrics = case.rubrics.as_dict()
    assert rubrics["citation_present"] is False, (
        f"regression_missing_citation should fail citation_present; got {rubrics}"
    )
    assert case.matches_expected


def test_regression_skipped_required_tool_breaks_required_evidence() -> None:
    """The skipped-required-tool fixture must fail
    ``required_evidence_checked``.
    """
    case = _run_regression_case("regression_skipped_required_tool")
    rubrics = case.rubrics.as_dict()
    assert rubrics["required_evidence_checked"] is False, (
        f"regression_skipped_required_tool should fail required_evidence_checked; "
        f"got {rubrics}"
    )
    assert case.matches_expected


# ---------------------------------------------------------------------------
# Suite-level promise: full run still exits clean (regressions are expected)
# ---------------------------------------------------------------------------


def test_full_suite_exits_zero_when_regressions_meet_their_expected_failures() -> None:
    """``run_copilot_tools_suite`` returns ``all_passed()`` only when
    every fixture matches its declared expectations -- including the
    regression bucket failing in the documented way.

    This mirrors the GitHub Actions job ``copilot-tools-eval``: as long
    as the regressions still fail their target rubric, the suite as a
    whole stays green.  When a regression fixture stops failing, the
    suite flips to non-zero -- which is the failure mode M23 wants CI
    to detect.
    """
    report = run_copilot_tools_suite()
    assert report.all_passed(), (
        "copilot-tools suite reported unexpected outcomes; "
        f"cases={[(c.fixture_id, c.matches_expected) for c in report.cases]}, "
        f"regressions={[(r.fixture_id, r.matches_expected) for r in report.regressions]}"
    )


def test_cli_surface_exits_zero_for_default_buckets(tmp_path: Path) -> None:
    """``python -m agent_service.eval --suite copilot-tools`` exits 0
    when the regression fixtures still fail in the documented way.

    This is the exact command the ``copilot-tools-eval`` GitHub Actions
    job runs.  We invoke it as a subprocess so the CLI argument parsing,
    fixture discovery, and exit-code wiring are exercised end-to-end.
    """
    repo_root = Path(__file__).resolve().parents[1]
    env_overrides = {
        "OPENAI_API_KEY": "",
        "COHERE_API_KEY": "",
    }
    proc = subprocess.run(
        [sys.executable, "-m", "agent_service.eval", "--suite", "copilot-tools"],
        cwd=repo_root,
        env={**_inherit_path_env(), **env_overrides},
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, (
        f"copilot-tools CLI exited {proc.returncode}\n"
        f"stdout:\n{proc.stdout}\nstderr:\n{proc.stderr}"
    )


# ---------------------------------------------------------------------------
# Cross-patient guard: M23 promises CI catches a wrong-patient source_id
# ---------------------------------------------------------------------------


class _StubCursor:
    """Minimal DictCursor stub returning a single foreign-patient row.

    Records SQL/params for assertion-free inspection in tests; no
    actual DB driver is touched.
    """

    def __init__(self, rows: list[dict[str, Any]]) -> None:
        self._rows = rows
        self.executed: list[tuple[str, tuple[Any, ...]]] = []

    def execute(self, sql: str, params: Any = None) -> None:
        self.executed.append((sql, tuple(params or ())))

    def fetchone(self) -> dict[str, Any] | None:
        return self._rows[0] if self._rows else None

    def fetchall(self) -> list[dict[str, Any]]:
        return list(self._rows)

    def close(self) -> None:
        return None


def _stub_connection_factory(cursor: _StubCursor):  # type: ignore[no-untyped-def]
    connection = MagicMock()
    connection.cursor = MagicMock(return_value=cursor)
    connection.close = MagicMock()

    def _factory():
        return connection

    return _factory


def _migration_run_context(*, patient_id: int = 42) -> CopilotRunContext:
    """Build a verified context scoped to *patient_id* with all source types."""
    return CopilotRunContext(
        user_id=17,
        username="dr.smith",
        patient_id=patient_id,
        encounter_id=100,
        allowed_tools=[
            "get_basic_patient_data",
            "get_current_medications",
            "get_active_allergies",
            "get_recent_events",
        ],
        allowed_source_types=[t.value for t in EvidenceSourceType],
        max_rows=50,
        lookback_days=365,
        expires_at=2_000_000_000,
        request_id="req-migration-ci",
        trace_id="trace-migration-ci",
        key_version="v1",
    )


def test_repository_rejects_cross_patient_source_id() -> None:
    """A source_id whose underlying row belongs to a different patient
    must never surface through ``get_source_detail``.

    M23 promises CI blocks cross-patient source access.  The M9
    repository is the single chokepoint for that guarantee, so we pin
    its behaviour here as a CI-level regression test.  This complements
    the broader cross-patient suite in ``test_openemr_repository.py``.
    """
    foreign_row = {
        "patient_id": 9999,
        "title": "Cross-patient leaked record",
        "type": "medical_problem",
        "begdate": date(2024, 1, 1),
        "enddate": None,
        "comments": "should not surface",
        "modifydate": None,
    }
    cursor = _StubCursor(rows=[foreign_row])
    repo = OpenEmrReadRepository(connection_factory=_stub_connection_factory(cursor))
    context = _migration_run_context(patient_id=42)

    detail = repo.get_source_detail(
        context=context,
        source_id="problem:lists:778",
    )

    assert detail is None, (
        "OpenEmrReadRepository.get_source_detail must return None when the "
        "looked-up row's patient_id does not match the run-context patient_id; "
        "a non-None return would be a cross-patient leak."
    )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _run_regression_case(fixture_id: str):  # type: ignore[no-untyped-def]
    """Locate a regression fixture by id and run it through the suite."""
    fixtures = load_fixtures(DEFAULT_REGRESSION_FIXTURES_DIR)
    matched = [f for f in fixtures if f.fixture_id == fixture_id]
    assert matched, (
        f"regression fixture {fixture_id!r} not found under "
        f"{DEFAULT_REGRESSION_FIXTURES_DIR}"
    )
    return run_fixture(matched[0])


def _inherit_path_env() -> dict[str, str]:
    """Return a minimal env dict the subprocess can run under.

    We inherit ``PATH`` and the venv-relevant variables so the spawned
    Python finds ``agent_service`` on its sys.path, but scrub any
    OPENAI/COHERE keys that may have leaked from the host shell.
    """
    import os

    keep = {"PATH", "PYTHONPATH", "VIRTUAL_ENV", "SYSTEMROOT", "HOME", "TEMP", "TMP"}
    return {k: v for k, v in os.environ.items() if k in keep}
