"""Tests for clinical schemas and validators (S5).

Validates that SourceCitation, LabPdf, LabResult, IntakeForm, and related
models enforce every constraint defined in Step S5.
"""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from agent_service.schemas.citation import SourceCitation, validate_bbox
from agent_service.schemas.intake_form import (
    Allergy,
    Demographics,
    FamilyHistoryEntry,
    IntakeForm,
    Medication,
)
from agent_service.schemas.lab_pdf import AbnormalFlag, LabPdf, LabResult


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _valid_citation(**overrides: object) -> dict[str, object]:
    """Return a minimal valid SourceCitation dict."""
    data: dict[str, object] = {
        "page": 1,
        "bbox": [72.0, 200.0, 540.0, 230.0],
        "field_name": "hemoglobin",
    }
    data.update(overrides)
    return data


def _valid_lab_result(**overrides: object) -> dict[str, object]:
    """Return a minimal valid LabResult dict."""
    data: dict[str, object] = {
        "test_name": "Hemoglobin",
        "value": "14.2",
        "unit": "g/dL",
        "reference_range": "13.5-17.5",
        "collection_date": "2026-05-01",
        "abnormal_flag": "normal",
        "source_citation": _valid_citation(),
    }
    data.update(overrides)
    return data


def _valid_lab_pdf(**overrides: object) -> dict[str, object]:
    """Return a minimal valid LabPdf dict."""
    data: dict[str, object] = {
        "results": [_valid_lab_result()],
        "extraction_confidence": 0.95,
    }
    data.update(overrides)
    return data


def _valid_intake_form(**overrides: object) -> dict[str, object]:
    """Return a minimal valid IntakeForm dict."""
    data: dict[str, object] = {
        "demographics": {"name": "Jane Doe", "dob": "1985-03-15", "gender": "female"},
        "chief_concern": "Persistent headache for 2 weeks",
        "current_medications": [{"name": "Ibuprofen", "dosage": "400mg", "frequency": "as needed"}],
        "allergies": [{"allergen": "Penicillin", "reaction": "rash", "severity": "moderate"}],
        "family_history": [{"relation": "mother", "condition": "hypertension"}],
        "source_citations": [_valid_citation(field_name="demographics")],
        "extraction_confidence": 0.88,
    }
    data.update(overrides)
    return data


# ===================================================================
# SourceCitation
# ===================================================================


class TestSourceCitation:
    """Tests for SourceCitation model and bbox validation."""

    def test_valid_citation_round_trips(self) -> None:
        cit = SourceCitation(**_valid_citation())
        assert cit.page == 1
        assert cit.bbox == [72.0, 200.0, 540.0, 230.0]
        assert cit.field_name == "hemoglobin"

    def test_json_round_trip(self) -> None:
        cit = SourceCitation(**_valid_citation())
        raw = cit.model_dump_json()
        restored = SourceCitation.model_validate_json(raw)
        assert restored == cit

    def test_page_zero_raises(self) -> None:
        with pytest.raises(ValidationError, match="page"):
            SourceCitation(**_valid_citation(page=0))

    def test_negative_page_raises(self) -> None:
        with pytest.raises(ValidationError, match="page"):
            SourceCitation(**_valid_citation(page=-1))

    def test_negative_bbox_coordinate_raises(self) -> None:
        with pytest.raises(ValidationError, match="non-negative"):
            SourceCitation(**_valid_citation(bbox=[-1.0, 200.0, 540.0, 230.0]))

    def test_negative_y0_raises(self) -> None:
        with pytest.raises(ValidationError, match="non-negative"):
            SourceCitation(**_valid_citation(bbox=[0.0, -10.0, 540.0, 230.0]))

    def test_zero_width_raises(self) -> None:
        with pytest.raises(ValidationError, match="x1.*greater than x0"):
            SourceCitation(**_valid_citation(bbox=[100.0, 200.0, 100.0, 230.0]))

    def test_negative_width_raises(self) -> None:
        with pytest.raises(ValidationError, match="x1.*greater than x0"):
            SourceCitation(**_valid_citation(bbox=[200.0, 100.0, 100.0, 230.0]))

    def test_zero_height_raises(self) -> None:
        with pytest.raises(ValidationError, match="y1.*greater than y0"):
            SourceCitation(**_valid_citation(bbox=[72.0, 230.0, 540.0, 230.0]))

    def test_negative_height_raises(self) -> None:
        with pytest.raises(ValidationError, match="y1.*greater than y0"):
            SourceCitation(**_valid_citation(bbox=[72.0, 300.0, 540.0, 200.0]))

    def test_bbox_wrong_length_raises(self) -> None:
        with pytest.raises(ValidationError, match="bbox"):
            SourceCitation(**_valid_citation(bbox=[72.0, 200.0]))

    def test_empty_field_name_raises(self) -> None:
        with pytest.raises(ValidationError, match="field_name"):
            SourceCitation(**_valid_citation(field_name=""))

    def test_bbox_at_origin_valid(self) -> None:
        """Bbox starting at (0, 0) with positive area is valid."""
        cit = SourceCitation(**_valid_citation(bbox=[0.0, 0.0, 10.0, 10.0]))
        assert cit.bbox == [0.0, 0.0, 10.0, 10.0]


# ===================================================================
# validate_bbox helper
# ===================================================================


class TestValidateBbox:
    """Tests for the standalone validate_bbox helper."""

    def test_valid_bbox(self) -> None:
        validate_bbox([72.0, 200.0, 540.0, 230.0])  # should not raise

    def test_wrong_length(self) -> None:
        with pytest.raises(ValueError, match="exactly 4"):
            validate_bbox([72.0, 200.0])

    def test_negative_coordinate(self) -> None:
        with pytest.raises(ValueError, match="non-negative"):
            validate_bbox([-1.0, 200.0, 540.0, 230.0])

    def test_zero_width(self) -> None:
        with pytest.raises(ValueError, match="x1.*greater than x0"):
            validate_bbox([100.0, 200.0, 100.0, 230.0])

    def test_zero_height(self) -> None:
        with pytest.raises(ValueError, match="y1.*greater than y0"):
            validate_bbox([72.0, 230.0, 540.0, 230.0])


# ===================================================================
# LabResult
# ===================================================================


class TestLabResult:
    """Tests for LabResult model."""

    def test_valid_lab_result_round_trips(self) -> None:
        result = LabResult(**_valid_lab_result())
        assert result.test_name == "Hemoglobin"
        assert result.value == "14.2"
        assert result.unit == "g/dL"
        assert result.abnormal_flag == AbnormalFlag.NORMAL

    def test_json_round_trip(self) -> None:
        result = LabResult(**_valid_lab_result())
        raw = result.model_dump_json()
        restored = LabResult.model_validate_json(raw)
        assert restored == result

    def test_missing_source_citation_raises(self) -> None:
        data = _valid_lab_result()
        del data["source_citation"]
        with pytest.raises(ValidationError, match="source_citation"):
            LabResult(**data)

    def test_all_abnormal_flags_accepted(self) -> None:
        for flag in AbnormalFlag:
            result = LabResult(**_valid_lab_result(abnormal_flag=flag.value))
            assert result.abnormal_flag == flag

    def test_invalid_abnormal_flag_raises(self) -> None:
        with pytest.raises(ValidationError, match="abnormal_flag"):
            LabResult(**_valid_lab_result(abnormal_flag="very_high"))

    def test_empty_test_name_raises(self) -> None:
        with pytest.raises(ValidationError, match="test_name"):
            LabResult(**_valid_lab_result(test_name=""))

    def test_empty_value_raises(self) -> None:
        with pytest.raises(ValidationError, match="value"):
            LabResult(**_valid_lab_result(value=""))

    def test_empty_unit_raises(self) -> None:
        with pytest.raises(ValidationError, match="unit"):
            LabResult(**_valid_lab_result(unit=""))


# ===================================================================
# LabPdf
# ===================================================================


class TestLabPdf:
    """Tests for LabPdf model."""

    def test_valid_lab_pdf_round_trips(self) -> None:
        pdf = LabPdf(**_valid_lab_pdf())
        assert len(pdf.results) == 1
        assert pdf.extraction_confidence == 0.95
        assert pdf.patient_name is None
        assert pdf.ordering_provider is None
        assert pdf.lab_name is None

    def test_json_round_trip(self) -> None:
        pdf = LabPdf(**_valid_lab_pdf())
        raw = pdf.model_dump_json()
        restored = LabPdf.model_validate_json(raw)
        assert restored == pdf

    def test_with_optional_fields(self) -> None:
        pdf = LabPdf(
            **_valid_lab_pdf(
                patient_name="John Doe",
                ordering_provider="Dr. Smith",
                lab_name="Quest Diagnostics",
            )
        )
        assert pdf.patient_name == "John Doe"
        assert pdf.ordering_provider == "Dr. Smith"
        assert pdf.lab_name == "Quest Diagnostics"

    def test_empty_results_raises(self) -> None:
        with pytest.raises(ValidationError, match="results"):
            LabPdf(**_valid_lab_pdf(results=[]))

    def test_extraction_confidence_above_one_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            LabPdf(**_valid_lab_pdf(extraction_confidence=1.01))

    def test_extraction_confidence_below_zero_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            LabPdf(**_valid_lab_pdf(extraction_confidence=-0.1))

    def test_extraction_confidence_boundary_zero(self) -> None:
        pdf = LabPdf(**_valid_lab_pdf(extraction_confidence=0.0))
        assert pdf.extraction_confidence == 0.0

    def test_extraction_confidence_boundary_one(self) -> None:
        pdf = LabPdf(**_valid_lab_pdf(extraction_confidence=1.0))
        assert pdf.extraction_confidence == 1.0

    def test_multiple_results(self) -> None:
        results = [
            _valid_lab_result(test_name="Hemoglobin"),
            _valid_lab_result(test_name="WBC", value="7.5", unit="K/uL", reference_range="4.5-11.0"),
            _valid_lab_result(
                test_name="Glucose",
                value="250",
                unit="mg/dL",
                reference_range="70-100",
                abnormal_flag="critical_high",
            ),
        ]
        pdf = LabPdf(**_valid_lab_pdf(results=results))
        assert len(pdf.results) == 3
        assert pdf.results[2].abnormal_flag == AbnormalFlag.CRITICAL_HIGH

    def test_result_with_invalid_bbox_raises(self) -> None:
        bad_citation = _valid_citation(bbox=[100.0, 200.0, 50.0, 230.0])
        with pytest.raises(ValidationError, match="x1.*greater than x0"):
            LabPdf(**_valid_lab_pdf(results=[_valid_lab_result(source_citation=bad_citation)]))


# ===================================================================
# IntakeForm
# ===================================================================


class TestIntakeForm:
    """Tests for IntakeForm model."""

    def test_valid_intake_form_round_trips(self) -> None:
        form = IntakeForm(**_valid_intake_form())
        assert form.chief_concern == "Persistent headache for 2 weeks"
        assert form.demographics.name == "Jane Doe"
        assert form.extraction_confidence == 0.88
        assert len(form.source_citations) == 1
        assert len(form.current_medications) == 1
        assert len(form.allergies) == 1
        assert len(form.family_history) == 1

    def test_json_round_trip(self) -> None:
        form = IntakeForm(**_valid_intake_form())
        raw = form.model_dump_json()
        restored = IntakeForm.model_validate_json(raw)
        assert restored == form

    def test_missing_source_citations_raises(self) -> None:
        data = _valid_intake_form()
        del data["source_citations"]
        with pytest.raises(ValidationError, match="source_citations"):
            IntakeForm(**data)

    def test_empty_source_citations_raises(self) -> None:
        with pytest.raises(ValidationError, match="source_citations"):
            IntakeForm(**_valid_intake_form(source_citations=[]))

    def test_missing_chief_concern_raises(self) -> None:
        data = _valid_intake_form()
        del data["chief_concern"]
        with pytest.raises(ValidationError, match="chief_concern"):
            IntakeForm(**data)

    def test_empty_chief_concern_raises(self) -> None:
        with pytest.raises(ValidationError, match="chief_concern"):
            IntakeForm(**_valid_intake_form(chief_concern=""))

    def test_extraction_confidence_above_one_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            IntakeForm(**_valid_intake_form(extraction_confidence=1.5))

    def test_extraction_confidence_below_zero_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            IntakeForm(**_valid_intake_form(extraction_confidence=-0.01))

    def test_extraction_confidence_boundary_zero(self) -> None:
        form = IntakeForm(**_valid_intake_form(extraction_confidence=0.0))
        assert form.extraction_confidence == 0.0

    def test_extraction_confidence_boundary_one(self) -> None:
        form = IntakeForm(**_valid_intake_form(extraction_confidence=1.0))
        assert form.extraction_confidence == 1.0

    def test_empty_medications_and_allergies_valid(self) -> None:
        form = IntakeForm(
            **_valid_intake_form(
                current_medications=[],
                allergies=[],
                family_history=[],
            )
        )
        assert form.current_medications == []
        assert form.allergies == []
        assert form.family_history == []

    def test_minimal_demographics_valid(self) -> None:
        """Demographics with all-None fields is valid."""
        form = IntakeForm(**_valid_intake_form(demographics={}))
        assert form.demographics.name is None
        assert form.demographics.dob is None

    def test_medication_requires_name(self) -> None:
        with pytest.raises(ValidationError, match="name"):
            Medication(name="", dosage="10mg")

    def test_allergy_requires_allergen(self) -> None:
        with pytest.raises(ValidationError, match="allergen"):
            Allergy(allergen="")

    def test_family_history_requires_relation_and_condition(self) -> None:
        with pytest.raises(ValidationError, match="relation"):
            FamilyHistoryEntry(relation="", condition="diabetes")
        with pytest.raises(ValidationError, match="condition"):
            FamilyHistoryEntry(relation="father", condition="")

    def test_citation_with_invalid_bbox_in_intake_raises(self) -> None:
        bad_citation = _valid_citation(bbox=[0.0, 0.0, 0.0, 10.0])
        with pytest.raises(ValidationError, match="x1.*greater than x0"):
            IntakeForm(**_valid_intake_form(source_citations=[bad_citation]))


# ===================================================================
# AbnormalFlag enum
# ===================================================================


class TestAbnormalFlag:
    """Tests for AbnormalFlag enum members."""

    def test_all_members_present(self) -> None:
        expected = {"normal", "high", "low", "critical_high", "critical_low", "abnormal"}
        actual = {member.value for member in AbnormalFlag}
        assert actual == expected

    def test_string_enum_comparison(self) -> None:
        assert AbnormalFlag.NORMAL == "normal"
        assert AbnormalFlag.CRITICAL_HIGH == "critical_high"
