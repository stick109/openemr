"""Tests for the LangGraph supervisor flow (S11).

Validates that the compiled graph correctly:
- Routes lab fixtures through extract -> retrieve -> finalize
- Routes intake fixtures through extract -> retrieve -> finalize
- Routes extraction failures through extract -> refuse (no retrieval/answer)
- Populates tool_sequence at every step
- Returns all required fields in the final response
"""

from __future__ import annotations

from typing import Any

import pytest

from agent_service.clients.openai_client import FakeLLMClient
from agent_service.graph import build_graph
from agent_service.rag.bm25_index import BM25Index
from agent_service.rag.corpus_loader import GuidelineChunk, load_corpus
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.pipeline import RAGPipeline
from agent_service.rag.reranker import FakeReranker


# ---------------------------------------------------------------------------
# Shared test constants
# ---------------------------------------------------------------------------

_TRACE_ID = "550e8400-e29b-41d4-a716-446655440000"


# ---------------------------------------------------------------------------
# Fixture helpers -- valid extraction data
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


def _valid_lab_pdf_dict(**overrides: object) -> dict[str, Any]:
    """Return a dict that validates as LabPdf."""
    data: dict[str, Any] = {
        "results": [_valid_lab_result()],
        "extraction_confidence": 0.95,
        "patient_name": "John Doe",
        "ordering_provider": "Dr. Smith",
        "lab_name": "Quest Diagnostics",
    }
    data.update(overrides)
    return data


def _valid_intake_form_dict(**overrides: object) -> dict[str, Any]:
    """Return a dict that validates as IntakeForm."""
    data: dict[str, Any] = {
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


# ---------------------------------------------------------------------------
# Pytest fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def corpus() -> list[GuidelineChunk]:
    """Load the built-in corpus once for the entire test module."""
    return load_corpus()


@pytest.fixture(scope="module")
def rag_pipeline(corpus: list[GuidelineChunk]) -> RAGPipeline:
    """Build a RAG pipeline with FakeReranker and fake embeddings."""
    bm25 = BM25Index(corpus)
    dense = DenseIndex.from_chunks_with_fake_embeddings(corpus, dim=64)
    return RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )


def _make_fake_client(
    file_path: str = "/tmp/test.pdf",
    extract_response: dict[str, Any] | None = None,
) -> FakeLLMClient:
    """Build a FakeLLMClient wired to return *extract_response* for *file_path*."""
    fake = FakeLLMClient(allow_env_key=True)
    file_id = fake.upload_pdf(file_path)
    fake.calls.clear()

    if extract_response is not None:
        fake.extract_responses[file_id] = extract_response
    return fake


def _make_initial_state(
    *,
    file_path: str = "/tmp/test.pdf",
    doc_type: str = "lab_pdf",
    trace_id: str = _TRACE_ID,
    patient_id: int = 1,
    encounter_id: int = 100,
) -> dict[str, Any]:
    """Build an initial graph state dict."""
    return {
        "file_path": file_path,
        "doc_type": doc_type,
        "trace_id": trace_id,
        "patient_id": patient_id,
        "encounter_id": encounter_id,
        "tool_sequence": [],
        "latency_ms_per_step": {},
    }


# ===================================================================
# Lab fixture -- sequence matches expected order
# ===================================================================


class TestLabFixtureSequence:
    """Lab PDF fixture follows extract -> retrieve -> finalize."""

    def test_lab_sequence_order(self, rag_pipeline: RAGPipeline) -> None:
        """Tool sequence for a valid lab PDF is [extract, retrieve, finalize]."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        assert result["tool_sequence"] == ["extract", "retrieve", "finalize"]

    def test_lab_extraction_produces_extracted(self, rag_pipeline: RAGPipeline) -> None:
        """Lab PDF extraction populates the 'extracted' key."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        assert "extracted" in result
        assert result["extracted"]["results"][0]["test_name"] == "Hemoglobin"

    def test_lab_retrieval_produces_evidence(self, rag_pipeline: RAGPipeline) -> None:
        """Lab PDF retrieval populates the 'evidence' key."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        assert "evidence" in result
        assert len(result["evidence"]) > 0

    def test_lab_finalize_produces_answer(self, rag_pipeline: RAGPipeline) -> None:
        """Lab PDF finalization populates the 'answer' key."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        assert "answer" in result
        assert len(result["answer"]) > 0

    def test_lab_status_completed(self, rag_pipeline: RAGPipeline) -> None:
        """Successful lab run sets status to 'completed'."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        assert result["status"] == "completed"


# ===================================================================
# Intake fixture -- sequence matches expected order
# ===================================================================


class TestIntakeFixtureSequence:
    """Intake form fixture follows extract -> retrieve -> finalize."""

    def test_intake_sequence_order(self, rag_pipeline: RAGPipeline) -> None:
        """Tool sequence for a valid intake form is [extract, retrieve, finalize]."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(doc_type="intake_form")
        )

        assert result["tool_sequence"] == ["extract", "retrieve", "finalize"]

    def test_intake_extraction_produces_extracted(self, rag_pipeline: RAGPipeline) -> None:
        """Intake form extraction populates the 'extracted' key."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(doc_type="intake_form")
        )

        assert "extracted" in result
        assert result["extracted"]["chief_concern"] == "Persistent headache for 2 weeks"

    def test_intake_retrieval_produces_evidence(self, rag_pipeline: RAGPipeline) -> None:
        """Intake form retrieval populates the 'evidence' key."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(doc_type="intake_form")
        )

        assert "evidence" in result
        assert len(result["evidence"]) > 0

    def test_intake_finalize_produces_answer(self, rag_pipeline: RAGPipeline) -> None:
        """Intake form finalization populates the 'answer' key."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(doc_type="intake_form")
        )

        assert "answer" in result
        assert "headache" in result["answer"].lower() or "concern" in result["answer"].lower()

    def test_intake_status_completed(self, rag_pipeline: RAGPipeline) -> None:
        """Successful intake run sets status to 'completed'."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(doc_type="intake_form")
        )

        assert result["status"] == "completed"


# ===================================================================
# Refusal fixture -- extraction fails, no retrieval or answer
# ===================================================================


class TestRefusalFixture:
    """Extraction failure routes to refuse without retrieval or answer."""

    def test_refusal_sequence_is_extract_refuse(self, rag_pipeline: RAGPipeline) -> None:
        """Tool sequence for a failed extraction is [extract, refuse]."""
        file_path = "/tmp/refuse_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        # Both attempts return malformed data (empty results violates min_length=1).
        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="lab_pdf")
        )

        assert result["tool_sequence"] == ["extract", "refuse"]

    def test_refusal_has_no_answer(self, rag_pipeline: RAGPipeline) -> None:
        """Refused runs do not produce an answer field (or it is empty)."""
        file_path = "/tmp/refuse_no_answer.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="lab_pdf")
        )

        # The answer should not be set (finalize was never called).
        assert result.get("answer", "") == ""

    def test_refusal_has_no_evidence(self, rag_pipeline: RAGPipeline) -> None:
        """Refused runs do not produce evidence (retrieval was skipped)."""
        file_path = "/tmp/refuse_no_evidence.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="lab_pdf")
        )

        # Evidence should not be populated (retriever was never called).
        assert result.get("evidence", []) == [] or "evidence" not in result

    def test_refusal_has_error(self, rag_pipeline: RAGPipeline) -> None:
        """Refused runs contain an error message."""
        file_path = "/tmp/refuse_error.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="lab_pdf")
        )

        assert "error" in result
        assert len(result["error"]) > 0

    def test_refusal_status_is_refused(self, rag_pipeline: RAGPipeline) -> None:
        """Refused runs set status to 'refused'."""
        file_path = "/tmp/refuse_status.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="lab_pdf")
        )

        assert result["status"] == "refused"

    def test_intake_refusal_sequence(self, rag_pipeline: RAGPipeline) -> None:
        """Intake form extraction failure also routes to [extract, refuse]."""
        file_path = "/tmp/refuse_intake.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        # Missing required chief_concern field.
        malformed: dict[str, Any] = {
            "demographics": {},
            "source_citations": [_valid_citation(field_name="demographics")],
            "extraction_confidence": 0.5,
        }

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path, doc_type="intake_form")
        )

        assert result["tool_sequence"] == ["extract", "refuse"]


# ===================================================================
# tool_sequence populated correctly
# ===================================================================


class TestToolSequencePopulation:
    """tool_sequence is populated at every graph step."""

    def test_successful_run_has_three_steps(self, rag_pipeline: RAGPipeline) -> None:
        """A successful run has exactly 3 entries in tool_sequence."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert len(result["tool_sequence"]) == 3

    def test_refused_run_has_two_steps(self, rag_pipeline: RAGPipeline) -> None:
        """A refused run has exactly 2 entries in tool_sequence."""
        file_path = "/tmp/seq_refuse.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        graph = build_graph(llm_client=fake, rag_pipeline=rag_pipeline)

        result = graph.invoke(
            _make_initial_state(file_path=file_path)
        )

        assert len(result["tool_sequence"]) == 2

    def test_tool_sequence_contains_only_known_steps(self, rag_pipeline: RAGPipeline) -> None:
        """All entries in tool_sequence are from the known step set."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        known_steps = {"extract", "retrieve", "finalize", "refuse"}
        for step in result["tool_sequence"]:
            assert step in known_steps, f"Unknown step '{step}' in tool_sequence"

    def test_extract_always_first(self, rag_pipeline: RAGPipeline) -> None:
        """'extract' is always the first step in tool_sequence."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert result["tool_sequence"][0] == "extract"


# ===================================================================
# Final response includes all required fields
# ===================================================================


class TestFinalResponseFields:
    """Completed response includes all fields needed for AgentRunResponse."""

    def test_extracted_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "extracted" in result
        assert isinstance(result["extracted"], dict)

    def test_evidence_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "evidence" in result
        assert isinstance(result["evidence"], list)

    def test_answer_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "answer" in result
        assert isinstance(result["answer"], str)
        assert len(result["answer"]) > 0

    def test_citations_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "citations" in result
        assert isinstance(result["citations"], list)

    def test_citations_have_guideline_fields(self, rag_pipeline: RAGPipeline) -> None:
        """Each guideline citation has source_type, chunk_id, source_url, snippet."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        guideline_citations = [
            c for c in result["citations"] if c.get("source_type") == "guideline"
        ]
        assert guideline_citations, "expected at least one guideline citation"
        for citation in guideline_citations:
            assert "chunk_id" in citation
            assert "source_url" in citation
            assert "snippet" in citation

    def test_citations_include_pdf_bbox_for_each_lab_result(
        self,
        rag_pipeline: RAGPipeline,
    ) -> None:
        """Each LabResult.source_citation becomes a pdf_bbox citation.

        The PHP host joins these against persisted procedure_result rows
        on case-insensitive ``field_name`` to wire UI bbox overlays --
        see interface/forms/upload_intake_form/view.php.  Without them
        no hover/click interaction is possible on the rendered PDF.
        """
        # Two distinct lab rows, each with its own source citation.
        extract_response = _valid_lab_pdf_dict(
            results=[
                _valid_lab_result(
                    test_name="Hemoglobin",
                    source_citation=_valid_citation(
                        field_name="hemoglobin",
                        page=1,
                        bbox=[72.0, 200.0, 540.0, 230.0],
                    ),
                ),
                _valid_lab_result(
                    test_name="WBC",
                    source_citation=_valid_citation(
                        field_name="wbc",
                        page=2,
                        bbox=[72.0, 300.0, 540.0, 330.0],
                    ),
                ),
            ],
        )
        client = _make_fake_client(extract_response=extract_response)
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="lab_pdf"))

        bbox_citations = [
            c for c in result["citations"] if c.get("source_type") == "pdf_bbox"
        ]
        assert len(bbox_citations) == 2, (
            f"expected 2 pdf_bbox citations (one per lab result), got "
            f"{len(bbox_citations)}: {bbox_citations!r}"
        )

        field_names = {c["field_name"] for c in bbox_citations}
        assert field_names == {"hemoglobin", "wbc"}, field_names

        for citation in bbox_citations:
            assert citation["source_type"] == "pdf_bbox"
            assert isinstance(citation["page"], int) and citation["page"] >= 1
            assert isinstance(citation["bbox"], list) and len(citation["bbox"]) == 4
            assert isinstance(citation["field_name"], str)
            assert citation["field_name"] != ""

    def test_citations_include_pdf_bbox_for_intake_form(
        self,
        rag_pipeline: RAGPipeline,
    ) -> None:
        """Intake forms emit pdf_bbox citations from ``source_citations``."""
        extract_response = _valid_intake_form_dict(
            source_citations=[
                _valid_citation(field_name="demographics", page=1),
                _valid_citation(field_name="chief_concern", page=1),
            ],
        )
        client = _make_fake_client(extract_response=extract_response)
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state(doc_type="intake_form"))

        bbox_citations = [
            c for c in result["citations"] if c.get("source_type") == "pdf_bbox"
        ]
        field_names = {c["field_name"] for c in bbox_citations}
        assert field_names == {"demographics", "chief_concern"}, field_names

    def test_cost_usd_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "cost_usd" in result
        assert isinstance(result["cost_usd"], float)
        assert result["cost_usd"] >= 0.0

    def test_latency_ms_per_step_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "latency_ms_per_step" in result
        latency = result["latency_ms_per_step"]
        assert isinstance(latency, dict)
        assert "extract" in latency
        assert "retrieve" in latency
        assert "finalize" in latency

    def test_tool_sequence_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "tool_sequence" in result
        assert isinstance(result["tool_sequence"], list)

    def test_extraction_confidence_present(self, rag_pipeline: RAGPipeline) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert "extraction_confidence" in result
        assert isinstance(result["extraction_confidence"], float)
        assert 0.0 <= result["extraction_confidence"] <= 1.0

    def test_trace_id_preserved(self, rag_pipeline: RAGPipeline) -> None:
        """trace_id flows through the entire graph."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)

        result = graph.invoke(_make_initial_state())

        assert result["trace_id"] == _TRACE_ID
