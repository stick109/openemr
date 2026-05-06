"""Tests for the 50-case evaluation fixture layout.

Verifies the on-disk shape of ``agent_service/eval/fixtures/``:

* exactly 50 fixture cases load
* every case has the documented required fields
* all ``case_id`` values are unique
* every lab case validates against ``LabPdf`` (or is a refusal)
* every intake case validates against ``IntakeForm`` (or is a refusal)
* every refusal case carries ``safe_refusal: true`` in its rubric
* the manifest agrees with the on-disk fixture files
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from agent_service.schemas.intake_form import IntakeForm
from agent_service.schemas.lab_pdf import LabPdf

FIXTURES_DIR = (
    Path(__file__).resolve().parent.parent
    / "agent_service"
    / "eval"
    / "fixtures"
)
MANIFEST_PATH = FIXTURES_DIR / "manifest.json"

REQUIRED_FIXTURE_FIELDS: tuple[str, ...] = (
    "case_id",
    "doc_type",
    "description",
    "input_file_path",
    "expected_extracted",
    "expected_rubric",
    "recorded_openai_response",
)

REQUIRED_RUBRIC_FIELDS: tuple[str, ...] = (
    "schema_valid",
    "citation_present",
    "factually_consistent",
    "safe_refusal",
)


def _load_fixture(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as fh:
        return json.load(fh)


def _all_fixture_paths() -> list[Path]:
    return sorted(p for p in FIXTURES_DIR.glob("*.json") if p.name != "manifest.json")


def _all_fixtures() -> list[dict[str, Any]]:
    return [_load_fixture(p) for p in _all_fixture_paths()]


def test_fixtures_directory_exists() -> None:
    assert FIXTURES_DIR.is_dir(), f"Missing fixtures directory: {FIXTURES_DIR}"


def test_exactly_fifty_cases() -> None:
    paths = _all_fixture_paths()
    assert len(paths) == 50, f"Expected 50 fixture files, found {len(paths)}"


def test_manifest_loads_and_lists_fifty_cases() -> None:
    assert MANIFEST_PATH.is_file(), "manifest.json missing"
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    assert manifest["total_cases"] == 50
    assert len(manifest["cases"]) == 50


def test_manifest_matches_disk_fixtures() -> None:
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    on_disk_ids = {p.stem for p in _all_fixture_paths()}
    manifest_ids = {entry["case_id"] for entry in manifest["cases"]}
    assert on_disk_ids == manifest_ids


def test_every_case_has_required_fields() -> None:
    for fixture in _all_fixtures():
        missing = [f for f in REQUIRED_FIXTURE_FIELDS if f not in fixture]
        assert not missing, (
            f"Fixture {fixture.get('case_id', '<unknown>')} missing fields: {missing}"
        )

        rubric = fixture["expected_rubric"]
        missing_rubric = [f for f in REQUIRED_RUBRIC_FIELDS if f not in rubric]
        assert not missing_rubric, (
            f"Fixture {fixture['case_id']} rubric missing fields: {missing_rubric}"
        )


def test_all_case_ids_unique() -> None:
    ids = [fixture["case_id"] for fixture in _all_fixtures()]
    assert len(ids) == len(set(ids)), "Duplicate case_id values found"


def test_case_id_matches_filename() -> None:
    for path in _all_fixture_paths():
        fixture = _load_fixture(path)
        assert fixture["case_id"] == path.stem, (
            f"case_id {fixture['case_id']} disagrees with filename {path.name}"
        )


def test_doc_type_values_are_supported() -> None:
    allowed = {"lab_pdf", "intake_form"}
    for fixture in _all_fixtures():
        assert fixture["doc_type"] in allowed, (
            f"Fixture {fixture['case_id']} has unsupported doc_type {fixture['doc_type']!r}"
        )


def test_recorded_response_shape() -> None:
    """Recorded responses must be loadable into FakeLLMClient."""
    for fixture in _all_fixtures():
        recorded = fixture["recorded_openai_response"]
        assert "upload_responses" in recorded, fixture["case_id"]
        assert "extract_responses" in recorded, fixture["case_id"]
        assert isinstance(recorded["upload_responses"], dict)
        assert isinstance(recorded["extract_responses"], dict)


@pytest.mark.parametrize(
    "fixture_path", _all_fixture_paths(), ids=lambda p: p.stem
)
def test_lab_or_intake_validation(fixture_path: Path) -> None:
    """Non-refusal lab/intake cases validate against their schema."""
    fixture = _load_fixture(fixture_path)
    rubric = fixture["expected_rubric"]
    extracted = fixture["expected_extracted"]

    if rubric["safe_refusal"]:
        # Refusal cases are not required to validate; they describe an
        # extraction that the worker should reject.  Still confirm the
        # rubric flags are consistent.
        assert rubric["schema_valid"] is False
        return

    if fixture["doc_type"] == "lab_pdf":
        LabPdf.model_validate(extracted)
    elif fixture["doc_type"] == "intake_form":
        IntakeForm.model_validate(extracted)
    else:
        pytest.fail(f"Unsupported doc_type {fixture['doc_type']!r}")


def test_refusal_cases_count_within_expected_range() -> None:
    """Spec calls for 2-3 refusal cases per doc type."""
    lab_refusals = 0
    intake_refusals = 0
    for fixture in _all_fixtures():
        if fixture["expected_rubric"]["safe_refusal"]:
            if fixture["doc_type"] == "lab_pdf":
                lab_refusals += 1
            else:
                intake_refusals += 1

    assert 2 <= lab_refusals <= 3, f"Lab refusal count out of range: {lab_refusals}"
    assert 2 <= intake_refusals <= 3, (
        f"Intake refusal count out of range: {intake_refusals}"
    )


def test_refusal_rubric_is_consistent() -> None:
    """A refusal case must flag schema_valid=False and citation_present=False."""
    for fixture in _all_fixtures():
        rubric = fixture["expected_rubric"]
        if rubric["safe_refusal"]:
            assert rubric["schema_valid"] is False, fixture["case_id"]
            assert rubric["citation_present"] is False, fixture["case_id"]
            assert rubric["factually_consistent"] is False, fixture["case_id"]


def test_success_rubric_is_consistent() -> None:
    """A successful case must have all positive rubric flags."""
    for fixture in _all_fixtures():
        rubric = fixture["expected_rubric"]
        if not rubric["safe_refusal"]:
            assert rubric["schema_valid"] is True, fixture["case_id"]
            assert rubric["citation_present"] is True, fixture["case_id"]
            assert rubric["factually_consistent"] is True, fixture["case_id"]


def test_lab_and_intake_case_counts() -> None:
    lab = sum(1 for f in _all_fixtures() if f["doc_type"] == "lab_pdf")
    intake = sum(1 for f in _all_fixtures() if f["doc_type"] == "intake_form")
    assert lab == 25, f"Expected 25 lab cases, found {lab}"
    assert intake == 25, f"Expected 25 intake cases, found {intake}"
