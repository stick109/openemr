"""Tests for the evidence record / citation models introduced in step M8.

Covers:

- Each evidence record model accepts a representative valid payload.
- ``extra="forbid"`` rejects unknown keys on every model.
- Required fields raise ``ValidationError`` when missing (notably
  ``citation_id``, which the chart UI relies on for round-trip
  drilldown).
- ``EvidenceEnvelope`` accepts a tuple of mixed record types and an
  empty ``sources`` tuple.
- The discriminated union picks the right subtype when deserialising a
  heterogeneous list of records keyed only by ``record_type``.
- ``ScopeSummary(truncated=True)`` round-trips through ``model_dump``.
- Citation IDs follow the ``<source_type>:<table>:<record_id>`` shape
  exercised by the parity fixtures under
  ``agent-service/tests/fixtures/copilot_parity/`` and the chart UI in
  ``interface/patient_file/summary/agent_panel.js``.
"""

from __future__ import annotations

import json
from datetime import date, datetime, timezone
from pathlib import Path

import pytest
from pydantic import TypeAdapter, ValidationError

from agent_service.schemas.copilot import Citation
from agent_service.schemas.evidence import (
    AllergyRecord,
    EventRecord,
    EvidenceEnvelope,
    EvidenceRecord,
    EvidenceSourceDetail,
    EvidenceSourceType,
    MedicationRecord,
    PatientDemographics,
    ProblemRecord,
    ResultRecord,
    ScopeSummary,
)


# Citation IDs in the PHP layer follow this exact shape (source_type :
# table : record_id), which the chart UI passes back verbatim via
# ``data-source-id``. Asserting against representative values here
# guarantees we do not silently drop or rewrite the round-trip format.
DEMOGRAPHICS_CITATION_ID = "demographics:patient_data:42"
MEDICATION_CITATION_ID = "medication:lists_medication:501"
ALLERGY_CITATION_ID = "allergy:lists:902"
PROBLEM_CITATION_ID = "problem:lists:778"
RESULT_CITATION_ID = "result:procedure_result:1100"
EVENT_CITATION_ID = "encounter:form_encounter:2031"


# ---------------------------------------------------------------------------
# Per-record validation
# ---------------------------------------------------------------------------


def test_patient_demographics_accepts_valid_payload() -> None:
    record = PatientDemographics(
        citation_id=DEMOGRAPHICS_CITATION_ID,
        age=62,
        sex="female",
        preferred_language="English",
        pronouns="she/her",
        primary_provider_npi="1234567893",
    )
    assert record.record_type == "patient_demographics"
    assert record.citation_id == DEMOGRAPHICS_CITATION_ID
    # Frozen value-object semantics: setting an attribute fails.
    with pytest.raises(ValidationError):
        record.age = 63  # type: ignore[misc]


def test_medication_record_accepts_valid_payload() -> None:
    record = MedicationRecord(
        citation_id=MEDICATION_CITATION_ID,
        name="lisinopril 10 mg oral tablet",
        rxnorm_code="314076",
        dose="10 mg",
        route="oral",
        schedule="once daily",
        start_date=date(2024, 1, 5),
        stop_date=None,
        status="active",
    )
    assert record.record_type == "medication"
    assert record.status == "active"
    assert record.start_date == date(2024, 1, 5)


def test_allergy_record_accepts_valid_payload() -> None:
    record = AllergyRecord(
        citation_id=ALLERGY_CITATION_ID,
        allergen="penicillin",
        coded_allergen="rxnorm:7984",
        reaction="hives",
        severity="moderate",
        verification_status="confirmed",
        onset_date=date(2010, 6, 15),
        status="active",
    )
    assert record.record_type == "allergy"
    assert record.severity == "moderate"
    assert record.verification_status == "confirmed"


def test_problem_record_accepts_valid_payload() -> None:
    record = ProblemRecord(
        citation_id=PROBLEM_CITATION_ID,
        title="essential hypertension",
        icd10_code="I10",
        snomed_code="59621000",
        onset_date=date(2018, 3, 1),
        resolved_date=None,
        status="active",
    )
    assert record.record_type == "problem"
    assert record.icd10_code == "I10"


def test_result_record_accepts_valid_payload() -> None:
    record = ResultRecord(
        citation_id=RESULT_CITATION_ID,
        name="hemoglobin A1c",
        loinc_code="4548-4",
        value="6.7",
        unit="%",
        reference_range="<5.7",
        abnormal_flag="high",
        observed_at=datetime(2025, 9, 12, 14, 30, tzinfo=timezone.utc),
        status="final",
    )
    assert record.record_type == "result"
    assert record.abnormal_flag == "high"
    assert record.observed_at is not None


def test_event_record_accepts_valid_payload() -> None:
    record = EventRecord(
        citation_id=EVENT_CITATION_ID,
        title="office visit",
        event_type="encounter",
        occurred_at=datetime(2025, 10, 4, 9, 0, tzinfo=timezone.utc),
        encounter_id=2031,
        summary="follow-up for hypertension management",
        status="completed",
    )
    assert record.record_type == "event"
    assert record.event_type == "encounter"
    assert record.encounter_id == 2031


# ---------------------------------------------------------------------------
# extra="forbid"
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("model", "payload"),
    [
        (
            PatientDemographics,
            {"citation_id": DEMOGRAPHICS_CITATION_ID, "extra_key": "boom"},
        ),
        (
            MedicationRecord,
            {
                "citation_id": MEDICATION_CITATION_ID,
                "name": "ibuprofen",
                "status": "active",
                "extra_key": "boom",
            },
        ),
        (
            AllergyRecord,
            {
                "citation_id": ALLERGY_CITATION_ID,
                "allergen": "penicillin",
                "status": "active",
                "extra_key": "boom",
            },
        ),
        (
            ProblemRecord,
            {
                "citation_id": PROBLEM_CITATION_ID,
                "title": "hypertension",
                "status": "active",
                "extra_key": "boom",
            },
        ),
        (
            ResultRecord,
            {
                "citation_id": RESULT_CITATION_ID,
                "name": "A1c",
                "extra_key": "boom",
            },
        ),
        (
            EventRecord,
            {
                "citation_id": EVENT_CITATION_ID,
                "title": "office visit",
                "event_type": "encounter",
                "extra_key": "boom",
            },
        ),
        (
            EvidenceSourceDetail,
            {
                "source_id": MEDICATION_CITATION_ID,
                "source_type": "medication",
                "label": "lisinopril",
                "body": "verbatim row",
                "extra_key": "boom",
            },
        ),
        (
            ScopeSummary,
            {
                "patient_id_present": True,
                "encounter_id_present": False,
                "max_rows_used": 25,
                "truncated": False,
                "extra_key": "boom",
            },
        ),
    ],
)
def test_extra_keys_rejected(model: type, payload: dict[str, object]) -> None:
    with pytest.raises(ValidationError):
        model.model_validate(payload)


# ---------------------------------------------------------------------------
# Required-field enforcement
# ---------------------------------------------------------------------------


def test_medication_record_requires_citation_id() -> None:
    with pytest.raises(ValidationError) as exc_info:
        MedicationRecord.model_validate(
            {"name": "lisinopril 10 mg", "status": "active"},
        )
    # Locate the missing-field error so a future field rename does not
    # silently let this assertion pass.
    errors = exc_info.value.errors()
    assert any(err["loc"] == ("citation_id",) and err["type"] == "missing" for err in errors)


def test_allergy_record_requires_citation_id_and_status() -> None:
    with pytest.raises(ValidationError) as exc_info:
        AllergyRecord.model_validate({"allergen": "penicillin"})
    locs = {err["loc"] for err in exc_info.value.errors() if err["type"] == "missing"}
    assert ("citation_id",) in locs
    assert ("status",) in locs


def test_problem_record_rejects_invalid_status() -> None:
    with pytest.raises(ValidationError):
        ProblemRecord.model_validate(
            {
                "citation_id": PROBLEM_CITATION_ID,
                "title": "hypertension",
                "status": "deleted",  # not in the closed set
            },
        )


# ---------------------------------------------------------------------------
# Discriminated union round-trip
# ---------------------------------------------------------------------------


def test_evidence_record_discriminator_picks_correct_subtype() -> None:
    """A heterogeneous list deserialises to the correct concrete subtype."""

    payload: list[dict[str, object]] = [
        {
            "record_type": "patient_demographics",
            "citation_id": DEMOGRAPHICS_CITATION_ID,
            "age": 62,
            "sex": "female",
        },
        {
            "record_type": "medication",
            "citation_id": MEDICATION_CITATION_ID,
            "name": "lisinopril 10 mg",
            "status": "active",
        },
        {
            "record_type": "allergy",
            "citation_id": ALLERGY_CITATION_ID,
            "allergen": "penicillin",
            "status": "active",
        },
        {
            "record_type": "problem",
            "citation_id": PROBLEM_CITATION_ID,
            "title": "hypertension",
            "status": "active",
        },
        {
            "record_type": "result",
            "citation_id": RESULT_CITATION_ID,
            "name": "hemoglobin A1c",
            "value": "6.7",
        },
        {
            "record_type": "event",
            "citation_id": EVENT_CITATION_ID,
            "title": "office visit",
            "event_type": "encounter",
        },
    ]

    adapter: TypeAdapter[list[EvidenceRecord]] = TypeAdapter(list[EvidenceRecord])
    records = adapter.validate_python(payload)

    expected_types = (
        PatientDemographics,
        MedicationRecord,
        AllergyRecord,
        ProblemRecord,
        ResultRecord,
        EventRecord,
    )
    assert tuple(type(r) for r in records) == expected_types


def test_evidence_record_discriminator_rejects_unknown_record_type() -> None:
    adapter: TypeAdapter[EvidenceRecord] = TypeAdapter(EvidenceRecord)
    with pytest.raises(ValidationError):
        adapter.validate_python(
            {
                "record_type": "spaceship",
                "citation_id": "spaceship:lists:1",
            },
        )


# ---------------------------------------------------------------------------
# Envelope and scope summary
# ---------------------------------------------------------------------------


def _citation(source_type: str, source_id: str, label: str) -> Citation:
    return Citation(source_type=source_type, source_id=source_id, label=label)


def test_evidence_envelope_accepts_mixed_records_and_empty_sources() -> None:
    records: tuple[EvidenceRecord, ...] = (
        MedicationRecord(
            citation_id=MEDICATION_CITATION_ID,
            name="lisinopril 10 mg",
            status="active",
        ),
        AllergyRecord(
            citation_id=ALLERGY_CITATION_ID,
            allergen="penicillin",
            status="active",
        ),
    )
    envelope = EvidenceEnvelope(
        records=records,
        sources=(),
        tool_name="get_current_medications",
        warnings=("evidence may be incomplete",),
        checked_scope=ScopeSummary(
            patient_id_present=True,
            encounter_id_present=False,
            lookback_days_used=365,
            max_rows_used=25,
            truncated=False,
            source_types_checked=(EvidenceSourceType.MEDICATION,),
        ),
    )
    assert envelope.records == records
    assert envelope.sources == ()
    assert envelope.warnings == ("evidence may be incomplete",)
    assert envelope.checked_scope.source_types_checked == (EvidenceSourceType.MEDICATION,)


def test_evidence_envelope_serialises_with_citations() -> None:
    envelope = EvidenceEnvelope(
        records=(
            MedicationRecord(
                citation_id=MEDICATION_CITATION_ID,
                name="lisinopril 10 mg",
                status="active",
            ),
        ),
        sources=(
            _citation("medication", MEDICATION_CITATION_ID, "Lisinopril 10 mg oral tablet"),
        ),
        tool_name="get_current_medications",
        checked_scope=ScopeSummary(
            patient_id_present=True,
            encounter_id_present=False,
            lookback_days_used=365,
            max_rows_used=25,
            truncated=False,
            source_types_checked=(EvidenceSourceType.MEDICATION,),
        ),
    )
    dumped = envelope.model_dump()
    assert dumped["sources"][0]["source_id"] == MEDICATION_CITATION_ID
    assert dumped["records"][0]["record_type"] == "medication"
    assert dumped["checked_scope"]["truncated"] is False


def test_scope_summary_with_truncated_true() -> None:
    summary = ScopeSummary(
        patient_id_present=True,
        encounter_id_present=True,
        lookback_days_used=30,
        max_rows_used=100,
        truncated=True,
        source_types_checked=(
            EvidenceSourceType.ENCOUNTER,
            EvidenceSourceType.MEDICATION,
        ),
    )
    assert summary.truncated is True
    # Frozen: cannot mutate after construction.
    with pytest.raises(ValidationError):
        summary.truncated = False  # type: ignore[misc]


# ---------------------------------------------------------------------------
# Citation round-trip with the chart-copilot parity fixtures
# ---------------------------------------------------------------------------


_PARITY_FIXTURE_DIR = (
    Path(__file__).parent / "fixtures" / "copilot_parity"
)


def _load_show_source_happy() -> dict[str, object]:
    path = _PARITY_FIXTURE_DIR / "show_source" / "01_show_source_happy.json"
    return json.loads(path.read_text(encoding="utf-8"))


def test_citation_id_round_trips_show_source_fixture() -> None:
    """Citation IDs from the M1 parity fixtures load without rewriting.

    The PHP layer emits ``<source_type>:<table>:<record_id>`` and the
    chart UI in ``agent_panel.js`` passes the value back verbatim. We
    confirm that the same shape parses through the typed evidence
    models and is preserved verbatim on ``model_dump`` -- this is the
    M8 pass criterion "citation IDs round-trip without changing
    UI-visible source links".
    """

    fixture = _load_show_source_happy()
    notes = str(fixture.get("notes", ""))
    evidence_ref = str(fixture.get("input", {}).get("evidence_packet_ref", ""))

    # The fixture asserts on this exact citation ID format. Any drift
    # here breaks the chart-copilot drilldown.
    expected_citation_id = "medication:lists_medication:501"
    assert expected_citation_id in evidence_ref
    assert "Source <type>" in notes  # Format sentinel from PHP claim text.

    record = MedicationRecord(
        citation_id=expected_citation_id,
        name="lisinopril 10 mg",
        status="active",
    )
    citation = _citation(
        "medication",
        expected_citation_id,
        "Lisinopril 10 mg oral tablet",
    )

    detail = EvidenceSourceDetail(
        source_id=expected_citation_id,
        source_type=EvidenceSourceType.MEDICATION,
        label="Lisinopril 10 mg oral tablet",
        body="medication: lisinopril 10 mg; dosage: once daily; status: active",
        occurred_at=datetime(2025, 6, 1, 12, 0, tzinfo=timezone.utc),
        parent_record_id=None,
    )

    # The citation ID must be byte-identical at every layer that touches
    # it -- record, citation, and source-detail -- so the chart UI's
    # ``data-source-id`` attribute round-trips unchanged.
    assert record.citation_id == expected_citation_id
    assert citation.source_id == expected_citation_id
    assert detail.source_id == expected_citation_id

    dumped_detail = detail.model_dump()
    assert dumped_detail["source_id"] == expected_citation_id
    assert dumped_detail["source_type"] == "medication"


def test_evidence_source_detail_requires_label_and_body() -> None:
    with pytest.raises(ValidationError):
        EvidenceSourceDetail.model_validate(
            {
                "source_id": MEDICATION_CITATION_ID,
                "source_type": "medication",
                # missing label and body
            },
        )
