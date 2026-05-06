"""Evidence retriever worker for the LangGraph agent pipeline.

Accepts a graph state dict containing ``extracted`` clinical data,
``doc_type``, and ``trace_id``, builds a clinically relevant retrieval
query, and calls the RAG pipeline to fetch guideline evidence snippets
with citation metadata.
"""

from __future__ import annotations

import logging
from typing import Any

from agent_service.rag.pipeline import RAGPipeline, RetrievalResult
from agent_service.schemas.api import DocType

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Query builders
# ---------------------------------------------------------------------------


def _build_lab_query(extracted: dict[str, Any]) -> str:
    """Build a retrieval query from extracted lab PDF data.

    Focuses on abnormal results, including test names, values/units,
    and clinically relevant screening/guideline keywords.
    """
    parts: list[str] = []

    results: list[dict[str, Any]] = extracted.get("results", [])
    for result in results:
        flag = result.get("abnormal_flag", "normal")
        test_name = result.get("test_name", "")
        value = result.get("value", "")
        unit = result.get("unit", "")

        if flag != "normal" and test_name:
            flag_label = flag.replace("_", " ")
            parts.append(f"{flag_label} {test_name} {value} {unit}".strip())

    if not parts:
        # Fallback: include all test names even when normal.
        for result in results:
            test_name = result.get("test_name", "")
            if test_name:
                parts.append(test_name)

    parts.append("screening guidelines")

    return " ".join(parts)


def _build_intake_query(extracted: dict[str, Any]) -> str:
    """Build a retrieval query from extracted intake form data.

    Includes chief concern, medications, allergies, and family history
    to surface relevant clinical guidelines.
    """
    parts: list[str] = []

    chief_concern: str = extracted.get("chief_concern", "")
    if chief_concern:
        parts.append(chief_concern)

    medications: list[dict[str, Any]] = extracted.get("current_medications", [])
    for med in medications:
        name = med.get("name", "")
        if name:
            parts.append(name)

    allergies: list[dict[str, Any]] = extracted.get("allergies", [])
    for allergy in allergies:
        allergen = allergy.get("allergen", "")
        if allergen:
            parts.append(f"allergy {allergen}")

    family_history: list[dict[str, Any]] = extracted.get("family_history", [])
    for entry in family_history:
        relation = entry.get("relation", "")
        condition = entry.get("condition", "")
        if condition:
            parts.append(f"family history {condition}")

    parts.append("treatment guidelines")

    return " ".join(parts)


_QUERY_BUILDER_FOR_DOC_TYPE = {
    DocType.LAB_PDF: _build_lab_query,
    DocType.INTAKE_FORM: _build_intake_query,
}


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------


class RetrieverWorker:
    """LangGraph node that retrieves clinical guideline evidence.

    Uses the RAG pipeline to find relevant guideline snippets based on
    extracted clinical data from the previous extraction step.

    Parameters
    ----------
    rag_pipeline:
        A fully configured :class:`RAGPipeline` instance.
    top_k:
        Maximum number of evidence snippets to retrieve.
    """

    def __init__(self, rag_pipeline: RAGPipeline, top_k: int = 5) -> None:
        self._pipeline = rag_pipeline
        self._top_k = top_k

    def run(self, state: dict[str, Any]) -> dict[str, Any]:
        """Execute the evidence retrieval step.

        Parameters
        ----------
        state:
            LangGraph state dict.  Required keys:

            * ``extracted``  -- dict of structured extraction data
            * ``doc_type``   -- ``"lab_pdf"`` or ``"intake_form"``
            * ``trace_id``   -- UUID v4 correlation identifier

        Returns
        -------
        dict
            Updated state with ``evidence`` (list of dicts with citation
            metadata) and ``query_used`` (the constructed query string
            for observability).
        """
        extracted: dict[str, Any] = state["extracted"]
        doc_type = DocType(state["doc_type"])
        trace_id: str = state["trace_id"]

        logger.info(
            "Retriever starting",
            extra={"trace_id": trace_id, "doc_type": doc_type},
        )

        # Build the retrieval query from extracted clinical data.
        query_builder = _QUERY_BUILDER_FOR_DOC_TYPE.get(doc_type)
        if query_builder is None:
            logger.warning(
                "No query builder for doc_type, falling back to generic query",
                extra={"trace_id": trace_id, "doc_type": doc_type},
            )
            query = "clinical guidelines"
        else:
            query = query_builder(extracted)

        logger.info(
            "Retrieval query constructed",
            extra={"trace_id": trace_id, "query": query},
        )

        # Run the RAG pipeline.
        results: list[RetrievalResult] = self._pipeline.retrieve(
            query, top_k=self._top_k
        )

        # Convert results to serialisable dicts with citation metadata.
        evidence = [
            {
                "chunk_id": r.chunk_id,
                "source_url": r.source_url,
                "section": r.section,
                "snippet": r.snippet,
                "score": r.score,
            }
            for r in results
        ]

        logger.info(
            "Retrieval complete",
            extra={
                "trace_id": trace_id,
                "num_results": len(evidence),
            },
        )

        return {
            **state,
            "evidence": evidence,
            "query_used": query,
            "trace_id": trace_id,
        }
