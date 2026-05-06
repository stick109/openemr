"""Tests for typed request/response models (S4).

Validates that Pydantic models enforce every constraint from CONTRACT.md.
"""

from __future__ import annotations

import uuid

import pytest
from pydantic import ValidationError

from agent_service.schemas.api import (
    AgentErrorResponse,
    AgentRunRequest,
    AgentRunResponse,
    DocType,
    GuidelineCitation,
    PdfBboxCitation,
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

VALID_TRACE_ID = str(uuid.uuid4())


def _valid_request_data(**overrides: object) -> dict[str, object]:
    """Return a minimal valid AgentRunRequest dict, with optional overrides."""
    data: dict[str, object] = {
        "patient_id": 42,
        "file_path": "/var/shared/uploads/lab_20260506_001.pdf",
        "doc_type": "lab_pdf",
        "encounter_id": 1087,
        "trace_id": VALID_TRACE_ID,
    }
    data.update(overrides)
    return data


def _valid_response_data(**overrides: object) -> dict[str, object]:
    """Return a minimal valid AgentRunResponse dict, with optional overrides."""
    data: dict[str, object] = {
        "extracted": {"hemoglobin": 13.5},
        "evidence": [{"guideline": "AMA Lab Reference Ranges 2025"}],
        "answer": "CBC results are within normal limits.",
        "citations": [],
        "cost_usd": 0.0037,
        "latency_ms_per_step": {"pdf_parse": 120, "extraction": 830},
        "tool_sequence": ["pdf_parser", "lab_extractor"],
        "extraction_confidence": 0.96,
    }
    data.update(overrides)
    return data


# ===================================================================
# AgentRunRequest
# ===================================================================


class TestAgentRunRequest:
    """Tests for AgentRunRequest validation."""

    def test_valid_request_round_trips(self) -> None:
        req = AgentRunRequest(**_valid_request_data())

        assert req.patient_id == 42
        assert req.file_path == "/var/shared/uploads/lab_20260506_001.pdf"
        assert req.doc_type == DocType.LAB_PDF
        assert req.encounter_id == 1087
        assert req.trace_id == VALID_TRACE_ID

    def test_all_doc_types_accepted(self) -> None:
        for doc_type in ("lab_pdf", "intake_form", "auto"):
            req = AgentRunRequest(**_valid_request_data(doc_type=doc_type))
            assert req.doc_type.value == doc_type

    def test_invalid_doc_type_raises(self) -> None:
        with pytest.raises(ValidationError, match="doc_type"):
            AgentRunRequest(**_valid_request_data(doc_type="blood_test"))

    def test_negative_patient_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="patient_id"):
            AgentRunRequest(**_valid_request_data(patient_id=-1))

    def test_zero_patient_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="patient_id"):
            AgentRunRequest(**_valid_request_data(patient_id=0))

    def test_negative_encounter_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="encounter_id"):
            AgentRunRequest(**_valid_request_data(encounter_id=-5))

    def test_zero_encounter_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="encounter_id"):
            AgentRunRequest(**_valid_request_data(encounter_id=0))

    def test_empty_trace_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="trace_id"):
            AgentRunRequest(**_valid_request_data(trace_id=""))

    def test_non_uuid_trace_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="trace_id"):
            AgentRunRequest(**_valid_request_data(trace_id="not-a-uuid"))

    def test_trace_id_normalised_to_lowercase(self) -> None:
        upper = "A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D"
        req = AgentRunRequest(**_valid_request_data(trace_id=upper))
        assert req.trace_id == upper.lower()

    def test_empty_file_path_raises(self) -> None:
        with pytest.raises(ValidationError, match="file_path"):
            AgentRunRequest(**_valid_request_data(file_path=""))

    def test_json_round_trip(self) -> None:
        req = AgentRunRequest(**_valid_request_data())
        raw = req.model_dump_json()
        restored = AgentRunRequest.model_validate_json(raw)
        assert restored == req


# ===================================================================
# Citation
# ===================================================================


class TestCitation:
    """Tests for Citation discriminated union."""

    def test_pdf_bbox_citation_valid(self) -> None:
        cit = PdfBboxCitation(
            source_type="pdf_bbox",
            page=1,
            bbox=[72.0, 200.0, 540.0, 230.0],
        )
        assert cit.source_type == "pdf_bbox"
        assert cit.page == 1
        assert cit.bbox == [72.0, 200.0, 540.0, 230.0]

    def test_pdf_bbox_requires_four_floats(self) -> None:
        with pytest.raises(ValidationError, match="bbox"):
            PdfBboxCitation(source_type="pdf_bbox", page=1, bbox=[72.0, 200.0])

    def test_pdf_bbox_page_must_be_positive(self) -> None:
        with pytest.raises(ValidationError, match="page"):
            PdfBboxCitation(source_type="pdf_bbox", page=0, bbox=[72.0, 200.0, 540.0, 230.0])

    def test_guideline_citation_valid(self) -> None:
        cit = GuidelineCitation(
            source_type="guideline",
            chunk_id="ama-lab-ref-2025-cbc-003",
            source_url="https://guidelines.example.org/ama-lab-ref-2025",
            snippet="Normal hemoglobin for adult males: 13.5-17.5 g/dL",
        )
        assert cit.source_type == "guideline"
        assert cit.chunk_id == "ama-lab-ref-2025-cbc-003"

    def test_guideline_citation_empty_chunk_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="chunk_id"):
            GuidelineCitation(
                source_type="guideline",
                chunk_id="",
                source_url="https://example.com",
                snippet="text",
            )


# ===================================================================
# AgentRunResponse
# ===================================================================


class TestAgentRunResponse:
    """Tests for AgentRunResponse validation."""

    def test_valid_response_round_trips(self) -> None:
        resp = AgentRunResponse(**_valid_response_data())

        assert resp.answer == "CBC results are within normal limits."
        assert resp.extraction_confidence == 0.96
        assert resp.cost_usd == 0.0037

    def test_response_contains_tool_sequence(self) -> None:
        resp = AgentRunResponse(**_valid_response_data())
        assert resp.tool_sequence == ["pdf_parser", "lab_extractor"]

    def test_extraction_confidence_above_one_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            AgentRunResponse(**_valid_response_data(extraction_confidence=1.01))

    def test_extraction_confidence_below_zero_raises(self) -> None:
        with pytest.raises(ValidationError, match="extraction_confidence"):
            AgentRunResponse(**_valid_response_data(extraction_confidence=-0.1))

    def test_extraction_confidence_boundary_zero(self) -> None:
        resp = AgentRunResponse(**_valid_response_data(extraction_confidence=0.0))
        assert resp.extraction_confidence == 0.0

    def test_extraction_confidence_boundary_one(self) -> None:
        resp = AgentRunResponse(**_valid_response_data(extraction_confidence=1.0))
        assert resp.extraction_confidence == 1.0

    def test_negative_cost_raises(self) -> None:
        with pytest.raises(ValidationError, match="cost_usd"):
            AgentRunResponse(**_valid_response_data(cost_usd=-0.01))

    def test_response_with_citations(self) -> None:
        citations = [
            {"source_type": "pdf_bbox", "page": 1, "bbox": [72, 200, 540, 230]},
            {
                "source_type": "guideline",
                "chunk_id": "ama-lab-ref-2025-cbc-003",
                "source_url": "https://guidelines.example.org/ama-lab-ref-2025",
                "snippet": "Normal hemoglobin for adult males: 13.5-17.5 g/dL",
            },
        ]
        resp = AgentRunResponse(**_valid_response_data(citations=citations))
        assert len(resp.citations) == 2
        assert resp.citations[0].source_type == "pdf_bbox"
        assert resp.citations[1].source_type == "guideline"

    def test_json_round_trip(self) -> None:
        resp = AgentRunResponse(**_valid_response_data())
        raw = resp.model_dump_json()
        restored = AgentRunResponse.model_validate_json(raw)
        assert restored == resp


# ===================================================================
# AgentErrorResponse
# ===================================================================


class TestAgentErrorResponse:
    """Tests for AgentErrorResponse validation."""

    def test_valid_error_response(self) -> None:
        err = AgentErrorResponse(
            error="invalid_request",
            detail="patient_id must be a positive integer",
            trace_id=VALID_TRACE_ID,
        )
        assert err.error == "invalid_request"
        assert err.trace_id == VALID_TRACE_ID

    def test_empty_error_raises(self) -> None:
        with pytest.raises(ValidationError, match="error"):
            AgentErrorResponse(error="", detail="something", trace_id=VALID_TRACE_ID)

    def test_empty_detail_raises(self) -> None:
        with pytest.raises(ValidationError, match="detail"):
            AgentErrorResponse(error="invalid_request", detail="", trace_id=VALID_TRACE_ID)

    def test_empty_trace_id_raises(self) -> None:
        with pytest.raises(ValidationError, match="trace_id"):
            AgentErrorResponse(error="invalid_request", detail="something", trace_id="")
