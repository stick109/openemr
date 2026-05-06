"""Tests for the RetrieverWorker (S10).

Validates that the retriever worker correctly:
- Builds clinically relevant queries from lab PDF extractions
- Builds clinically relevant queries from intake form extractions
- Returns evidence with citation metadata (chunk_id, source_url, snippet)
- Preserves trace_id through the worker
- Includes query_used in state for observability
"""

from __future__ import annotations

from typing import Any

import pytest

from agent_service.rag.bm25_index import BM25Index
from agent_service.rag.corpus_loader import GuidelineChunk, load_corpus
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.pipeline import RAGPipeline
from agent_service.rag.reranker import FakeReranker
from agent_service.workers.retriever import RetrieverWorker


# ---------------------------------------------------------------------------
# Shared fixtures
# ---------------------------------------------------------------------------

_TRACE_ID = "550e8400-e29b-41d4-a716-446655440000"


@pytest.fixture(scope="module")
def corpus() -> list[GuidelineChunk]:
    """Load the built-in corpus once for the entire test module."""
    return load_corpus()


@pytest.fixture(scope="module")
def pipeline(corpus: list[GuidelineChunk]) -> RAGPipeline:
    """Build a RAG pipeline with FakeReranker and fake embeddings."""
    bm25 = BM25Index(corpus)
    dense = DenseIndex.from_chunks_with_fake_embeddings(corpus, dim=64)
    return RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )


@pytest.fixture()
def worker(pipeline: RAGPipeline) -> RetrieverWorker:
    """Build a RetrieverWorker wired to the test pipeline."""
    return RetrieverWorker(rag_pipeline=pipeline, top_k=5)


# ---------------------------------------------------------------------------
# Helpers -- extraction fixture data
# ---------------------------------------------------------------------------


def _valid_citation(**overrides: object) -> dict[str, object]:
    """Return a minimal valid SourceCitation dict."""
    data: dict[str, object] = {
        "page": 1,
        "bbox": [72.0, 200.0, 540.0, 230.0],
        "field_name": "test_field",
    }
    data.update(overrides)
    return data


def _lab_extracted_abnormal_glucose() -> dict[str, Any]:
    """Lab extraction with abnormal (high) glucose result."""
    return {
        "results": [
            {
                "test_name": "Glucose",
                "value": "250",
                "unit": "mg/dL",
                "reference_range": "70-100",
                "collection_date": "2026-05-01",
                "abnormal_flag": "high",
                "source_citation": _valid_citation(field_name="glucose"),
            },
            {
                "test_name": "Hemoglobin",
                "value": "14.2",
                "unit": "g/dL",
                "reference_range": "13.5-17.5",
                "collection_date": "2026-05-01",
                "abnormal_flag": "normal",
                "source_citation": _valid_citation(field_name="hemoglobin"),
            },
        ],
        "extraction_confidence": 0.95,
        "patient_name": "John Doe",
    }


def _lab_extracted_high_cholesterol() -> dict[str, Any]:
    """Lab extraction with high cholesterol result."""
    return {
        "results": [
            {
                "test_name": "Total Cholesterol",
                "value": "280",
                "unit": "mg/dL",
                "reference_range": "<200",
                "collection_date": "2026-05-01",
                "abnormal_flag": "high",
                "source_citation": _valid_citation(field_name="cholesterol"),
            },
            {
                "test_name": "LDL Cholesterol",
                "value": "190",
                "unit": "mg/dL",
                "reference_range": "<100",
                "collection_date": "2026-05-01",
                "abnormal_flag": "high",
                "source_citation": _valid_citation(field_name="ldl"),
            },
        ],
        "extraction_confidence": 0.92,
        "patient_name": "Jane Smith",
    }


def _intake_extracted_chest_pain() -> dict[str, Any]:
    """Intake form extraction with chief concern of chest pain."""
    return {
        "demographics": {"name": "Bob Jones", "dob": "1960-07-20", "gender": "male"},
        "chief_concern": "chest pain radiating to left arm",
        "current_medications": [
            {"name": "Aspirin", "dosage": "81mg", "frequency": "daily"},
            {"name": "Metoprolol", "dosage": "50mg", "frequency": "twice daily"},
        ],
        "allergies": [{"allergen": "Sulfa", "reaction": "hives", "severity": "moderate"}],
        "family_history": [
            {"relation": "father", "condition": "myocardial infarction"},
        ],
        "source_citations": [_valid_citation(field_name="demographics")],
        "extraction_confidence": 0.90,
    }


def _intake_extracted_hypertension() -> dict[str, Any]:
    """Intake form extraction with hypertension and medications."""
    return {
        "demographics": {"name": "Alice Brown", "dob": "1975-11-03", "gender": "female"},
        "chief_concern": "hypertension follow-up",
        "current_medications": [
            {"name": "Lisinopril", "dosage": "20mg", "frequency": "daily"},
            {"name": "Amlodipine", "dosage": "5mg", "frequency": "daily"},
        ],
        "allergies": [],
        "family_history": [
            {"relation": "mother", "condition": "stroke"},
            {"relation": "father", "condition": "hypertension"},
        ],
        "source_citations": [_valid_citation(field_name="demographics")],
        "extraction_confidence": 0.88,
    }


def _make_state(
    *,
    extracted: dict[str, Any],
    doc_type: str = "lab_pdf",
    trace_id: str = _TRACE_ID,
) -> dict[str, Any]:
    """Build a minimal graph state dict for the retriever."""
    return {
        "extracted": extracted,
        "doc_type": doc_type,
        "trace_id": trace_id,
    }


# ===================================================================
# Lab PDF -- query construction
# ===================================================================


class TestLabPdfQueryConstruction:
    """Lab fixture with abnormal results produces a clinically relevant query."""

    def test_abnormal_glucose_query_contains_glucose(
        self, worker: RetrieverWorker
    ) -> None:
        """Abnormal glucose result produces a query mentioning glucose."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        assert "glucose" in query, f"Expected 'glucose' in query, got: {query}"

    def test_abnormal_glucose_query_contains_value(
        self, worker: RetrieverWorker
    ) -> None:
        """Abnormal glucose query includes the numeric value."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        query = result["query_used"]
        assert "250" in query, f"Expected '250' in query, got: {query}"

    def test_high_cholesterol_query_contains_cholesterol(
        self, worker: RetrieverWorker
    ) -> None:
        """High cholesterol result produces a query mentioning cholesterol."""
        state = _make_state(
            extracted=_lab_extracted_high_cholesterol(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        has_relevant_term = "cholesterol" in query or "lipid" in query
        assert has_relevant_term, (
            f"Expected 'cholesterol' or 'lipid' in query, got: {query}"
        )

    def test_high_cholesterol_query_includes_ldl(
        self, worker: RetrieverWorker
    ) -> None:
        """High LDL result is included in the query."""
        state = _make_state(
            extracted=_lab_extracted_high_cholesterol(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        assert "ldl" in query or "cholesterol" in query, (
            f"Expected LDL/cholesterol reference in query, got: {query}"
        )

    def test_normal_results_still_produce_query(
        self, worker: RetrieverWorker
    ) -> None:
        """All-normal lab results still produce a meaningful query."""
        extracted: dict[str, Any] = {
            "results": [
                {
                    "test_name": "Hemoglobin",
                    "value": "14.2",
                    "unit": "g/dL",
                    "reference_range": "13.5-17.5",
                    "collection_date": "2026-05-01",
                    "abnormal_flag": "normal",
                    "source_citation": _valid_citation(field_name="hemoglobin"),
                },
            ],
            "extraction_confidence": 0.95,
        }
        state = _make_state(extracted=extracted, doc_type="lab_pdf")
        result = worker.run(state)

        query = result["query_used"]
        assert len(query) > 0, "Query should not be empty even with all-normal results"
        assert "Hemoglobin" in query


# ===================================================================
# Intake form -- query construction
# ===================================================================


class TestIntakeFormQueryConstruction:
    """Intake fixture produces clinically relevant queries."""

    def test_chest_pain_query_contains_chief_concern(
        self, worker: RetrieverWorker
    ) -> None:
        """Chest pain chief concern appears in the query."""
        state = _make_state(
            extracted=_intake_extracted_chest_pain(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        assert "chest pain" in query, (
            f"Expected 'chest pain' in query, got: {query}"
        )

    def test_chest_pain_query_includes_medications(
        self, worker: RetrieverWorker
    ) -> None:
        """Medication names appear in the intake query."""
        state = _make_state(
            extracted=_intake_extracted_chest_pain(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        has_med = "aspirin" in query or "metoprolol" in query
        assert has_med, f"Expected medication names in query, got: {query}"

    def test_hypertension_query_contains_chief_concern(
        self, worker: RetrieverWorker
    ) -> None:
        """Hypertension chief concern appears in the query."""
        state = _make_state(
            extracted=_intake_extracted_hypertension(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        assert "hypertension" in query, (
            f"Expected 'hypertension' in query, got: {query}"
        )

    def test_hypertension_query_includes_medications(
        self, worker: RetrieverWorker
    ) -> None:
        """Medication names from the intake form are in the query."""
        state = _make_state(
            extracted=_intake_extracted_hypertension(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        has_med = "lisinopril" in query or "amlodipine" in query
        assert has_med, f"Expected medication names in query, got: {query}"

    def test_intake_query_includes_family_history(
        self, worker: RetrieverWorker
    ) -> None:
        """Family history conditions appear in the query."""
        state = _make_state(
            extracted=_intake_extracted_hypertension(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        query = result["query_used"].lower()
        has_family = "stroke" in query or "hypertension" in query
        assert has_family, (
            f"Expected family history condition in query, got: {query}"
        )


# ===================================================================
# Evidence results -- citation metadata
# ===================================================================


class TestEvidenceCitationMetadata:
    """Result list contains top-k snippets with citation metadata."""

    def test_evidence_contains_results(self, worker: RetrieverWorker) -> None:
        """Evidence list is non-empty for a realistic query."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        assert "evidence" in result
        assert len(result["evidence"]) > 0

    def test_evidence_has_chunk_id(self, worker: RetrieverWorker) -> None:
        """Every evidence item has a non-empty chunk_id."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        for item in result["evidence"]:
            assert "chunk_id" in item
            assert len(item["chunk_id"]) > 0

    def test_evidence_has_source_url(self, worker: RetrieverWorker) -> None:
        """Every evidence item has a non-empty source_url."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        for item in result["evidence"]:
            assert "source_url" in item
            assert item["source_url"].startswith("https://")

    def test_evidence_has_snippet(self, worker: RetrieverWorker) -> None:
        """Every evidence item has a non-empty snippet."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        for item in result["evidence"]:
            assert "snippet" in item
            assert len(item["snippet"]) > 0

    def test_evidence_has_score(self, worker: RetrieverWorker) -> None:
        """Every evidence item has a numeric score."""
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        for item in result["evidence"]:
            assert "score" in item
            assert isinstance(item["score"], float)

    def test_evidence_respects_top_k(self, pipeline: RAGPipeline) -> None:
        """Evidence list is limited to the configured top_k."""
        small_worker = RetrieverWorker(rag_pipeline=pipeline, top_k=3)
        state = _make_state(
            extracted=_intake_extracted_chest_pain(),
            doc_type="intake_form",
        )
        result = small_worker.run(state)

        assert len(result["evidence"]) <= 3

    def test_intake_form_evidence_contains_results(
        self, worker: RetrieverWorker
    ) -> None:
        """Intake form extraction also produces evidence results."""
        state = _make_state(
            extracted=_intake_extracted_hypertension(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        assert "evidence" in result
        assert len(result["evidence"]) > 0
        for item in result["evidence"]:
            assert "chunk_id" in item
            assert "source_url" in item
            assert "snippet" in item


# ===================================================================
# Trace ID preservation
# ===================================================================


class TestTraceIdPreservation:
    """trace_id is preserved through the retriever worker."""

    def test_trace_id_preserved(self, worker: RetrieverWorker) -> None:
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
            trace_id=_TRACE_ID,
        )
        result = worker.run(state)

        assert result["trace_id"] == _TRACE_ID

    def test_custom_trace_id_preserved(self, worker: RetrieverWorker) -> None:
        custom_trace = "12345678-1234-4234-8234-123456789abc"
        state = _make_state(
            extracted=_intake_extracted_chest_pain(),
            doc_type="intake_form",
            trace_id=custom_trace,
        )
        result = worker.run(state)

        assert result["trace_id"] == custom_trace

    def test_different_trace_ids_are_independent(
        self, worker: RetrieverWorker
    ) -> None:
        result_a = worker.run(
            _make_state(
                extracted=_lab_extracted_abnormal_glucose(),
                trace_id="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa",
            )
        )
        result_b = worker.run(
            _make_state(
                extracted=_lab_extracted_abnormal_glucose(),
                trace_id="bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb",
            )
        )

        assert result_a["trace_id"] == "aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
        assert result_b["trace_id"] == "bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb"


# ===================================================================
# Observability -- query_used in state
# ===================================================================


class TestQueryUsedObservability:
    """query_used is included in the returned state for observability."""

    def test_query_used_present_for_lab(self, worker: RetrieverWorker) -> None:
        state = _make_state(
            extracted=_lab_extracted_abnormal_glucose(),
            doc_type="lab_pdf",
        )
        result = worker.run(state)

        assert "query_used" in result
        assert isinstance(result["query_used"], str)
        assert len(result["query_used"]) > 0

    def test_query_used_present_for_intake(
        self, worker: RetrieverWorker
    ) -> None:
        state = _make_state(
            extracted=_intake_extracted_chest_pain(),
            doc_type="intake_form",
        )
        result = worker.run(state)

        assert "query_used" in result
        assert isinstance(result["query_used"], str)
        assert len(result["query_used"]) > 0
