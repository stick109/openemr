"""LangGraph state graph for the OpenEMR agent sidecar pipeline.

Defines the graph state, nodes (extract, retrieve, finalize, refuse),
conditional routing, and compiles the graph for invocation from the
FastAPI endpoint.
"""

from __future__ import annotations

import logging
import time
from typing import Any, TypedDict

from langgraph.graph import END, StateGraph

from agent_service.clients.openai_client import LLMClient
from agent_service.rag.pipeline import RAGPipeline
from agent_service.supervisor import SUPERVISOR_PROMPT  # noqa: F401 -- re-exported for visibility
from agent_service.workers.extractor import ExtractorWorker
from agent_service.workers.retriever import RetrieverWorker

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Graph state
# ---------------------------------------------------------------------------


class GraphState(TypedDict, total=False):
    """Typed state flowing through every node in the LangGraph pipeline.

    Keys are populated progressively as nodes execute.
    """

    # -- input (set by caller) --
    file_path: str
    doc_type: str
    trace_id: str
    patient_id: int
    encounter_id: int

    # -- populated by extract --
    extracted: dict[str, Any]
    extraction_confidence: float

    # -- populated by retrieve --
    evidence: list[dict[str, Any]]

    # -- populated by finalize --
    answer: str
    citations: list[dict[str, Any]]

    # -- observability / accounting --
    tool_sequence: list[str]
    cost_usd: float
    latency_ms_per_step: dict[str, int]

    # -- error path --
    error: str
    status: str


# ---------------------------------------------------------------------------
# Node functions
# ---------------------------------------------------------------------------


def _make_extract_node(
    extractor: ExtractorWorker,
) -> Any:
    """Return a node function that runs extraction and records timing."""

    def extract(state: dict[str, Any]) -> dict[str, Any]:
        start = time.perf_counter()
        result = extractor.run(state)
        elapsed_ms = int((time.perf_counter() - start) * 1000)

        tool_sequence = list(state.get("tool_sequence", []))
        tool_sequence.append("extract")

        latency = dict(state.get("latency_ms_per_step", {}))
        latency["extract"] = elapsed_ms

        return {
            **result,
            "tool_sequence": tool_sequence,
            "latency_ms_per_step": latency,
        }

    return extract


def _make_retrieve_node(
    retriever: RetrieverWorker,
) -> Any:
    """Return a node function that runs retrieval and records timing."""

    def retrieve(state: dict[str, Any]) -> dict[str, Any]:
        start = time.perf_counter()
        result = retriever.run(state)
        elapsed_ms = int((time.perf_counter() - start) * 1000)

        tool_sequence = list(state.get("tool_sequence", []))
        tool_sequence.append("retrieve")

        latency = dict(state.get("latency_ms_per_step", {}))
        latency["retrieve"] = elapsed_ms

        return {
            **result,
            "tool_sequence": tool_sequence,
            "latency_ms_per_step": latency,
        }

    return retrieve


def _finalize_node(state: dict[str, Any]) -> dict[str, Any]:
    """Assemble the final answer from extracted data and evidence."""
    start = time.perf_counter()

    extracted = state.get("extracted", {})
    evidence = state.get("evidence", [])
    doc_type = state.get("doc_type", "unknown")

    # Build a natural-language clinical summary.
    if doc_type == "lab_pdf":
        results = extracted.get("results", [])
        abnormal = [r for r in results if r.get("abnormal_flag", "normal") != "normal"]
        if abnormal:
            test_names = ", ".join(r.get("test_name", "unknown") for r in abnormal)
            answer = (
                f"Lab results extracted with {len(results)} test(s). "
                f"Abnormal findings: {test_names}. "
                f"Retrieved {len(evidence)} guideline snippet(s) for clinical context."
            )
        else:
            answer = (
                f"Lab results extracted with {len(results)} test(s), all within normal limits. "
                f"Retrieved {len(evidence)} guideline snippet(s) for reference."
            )
    elif doc_type == "intake_form":
        chief_concern = extracted.get("chief_concern", "not specified")
        med_count = len(extracted.get("current_medications", []))
        answer = (
            f"Intake form extracted. Chief concern: {chief_concern}. "
            f"{med_count} medication(s) documented. "
            f"Retrieved {len(evidence)} guideline snippet(s) for clinical context."
        )
    else:
        answer = (
            f"Document processed. "
            f"Retrieved {len(evidence)} guideline snippet(s)."
        )

    # Build citations from evidence items (guideline citations).
    citations: list[dict[str, Any]] = []
    for ev in evidence:
        citations.append({
            "source_type": "guideline",
            "chunk_id": ev.get("chunk_id", ""),
            "source_url": ev.get("source_url", ""),
            "snippet": ev.get("snippet", ""),
        })

    elapsed_ms = int((time.perf_counter() - start) * 1000)

    tool_sequence = list(state.get("tool_sequence", []))
    tool_sequence.append("finalize")

    latency = dict(state.get("latency_ms_per_step", {}))
    latency["finalize"] = elapsed_ms

    return {
        **state,
        "answer": answer,
        "citations": citations,
        "tool_sequence": tool_sequence,
        "latency_ms_per_step": latency,
        "cost_usd": 0.0,
        "status": "completed",
    }


def _refuse_node(state: dict[str, Any]) -> dict[str, Any]:
    """Return an error state when extraction fails."""
    start = time.perf_counter()

    tool_sequence = list(state.get("tool_sequence", []))
    tool_sequence.append("refuse")

    elapsed_ms = int((time.perf_counter() - start) * 1000)

    latency = dict(state.get("latency_ms_per_step", {}))
    latency["refuse"] = elapsed_ms

    error_msg = state.get("error", "Extraction failed")

    return {
        **state,
        "error": error_msg,
        "tool_sequence": tool_sequence,
        "latency_ms_per_step": latency,
        "status": "refused",
    }


# ---------------------------------------------------------------------------
# Routing
# ---------------------------------------------------------------------------


def _after_extract(state: dict[str, Any]) -> str:
    """Route after extraction: succeed -> retrieve, fail -> refuse."""
    if "error" in state and state["error"]:
        return "refuse"
    if "extracted" in state and state["extracted"]:
        return "retrieve"
    return "refuse"


# ---------------------------------------------------------------------------
# Graph builder
# ---------------------------------------------------------------------------


def build_graph(
    llm_client: LLMClient,
    rag_pipeline: RAGPipeline,
    retriever_top_k: int = 5,
) -> Any:
    """Build and compile the LangGraph StateGraph.

    Parameters
    ----------
    llm_client:
        LLM client (real or fake) for extraction.
    rag_pipeline:
        Configured RAG pipeline for evidence retrieval.
    retriever_top_k:
        Maximum number of evidence snippets to retrieve.

    Returns
    -------
    CompiledGraph
        A compiled LangGraph graph ready for invocation.
    """
    extractor = ExtractorWorker(llm_client)
    retriever = RetrieverWorker(rag_pipeline=rag_pipeline, top_k=retriever_top_k)

    workflow = StateGraph(dict)

    # Add nodes
    workflow.add_node("extract", _make_extract_node(extractor))
    workflow.add_node("retrieve", _make_retrieve_node(retriever))
    workflow.add_node("finalize", _finalize_node)
    workflow.add_node("refuse", _refuse_node)

    # Set entry point
    workflow.set_entry_point("extract")

    # Conditional edge from extract
    workflow.add_conditional_edges(
        "extract",
        _after_extract,
        {
            "retrieve": "retrieve",
            "refuse": "refuse",
        },
    )

    # Linear edges
    workflow.add_edge("retrieve", "finalize")
    workflow.add_edge("finalize", END)
    workflow.add_edge("refuse", END)

    return workflow.compile()
