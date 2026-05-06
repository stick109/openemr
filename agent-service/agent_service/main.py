"""FastAPI application for the OpenEMR agent sidecar."""

from __future__ import annotations

import logging
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse

from agent_service.api import copilot_router
from agent_service.config import get_settings
from agent_service.schemas.api import (
    AgentErrorResponse,
    AgentRunRequest,
    AgentRunResponse,
)

logger = logging.getLogger(__name__)

app = FastAPI(
    title="OpenEMR Agent Sidecar",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)

# Mount route modules.  The chart-copilot router covers POST /api/copilot/run
# (stubbed in M2; real handler arrives in M4/M13).
app.include_router(copilot_router, prefix="/api/copilot")


# ---------------------------------------------------------------------------
# Shared-secret authentication dependency
# ---------------------------------------------------------------------------

def _verify_secret(x_agent_secret: str | None = Header(default=None)) -> str:
    """Validate the ``X-Agent-Secret`` header.

    Raises 401 when the header is missing and 403 when the value does not
    match the configured secret.  Authentication runs **before** any other
    processing (FastAPI evaluates dependencies before the route body).
    """
    if x_agent_secret is None:
        raise HTTPException(
            status_code=401,
            detail={
                "error": "unauthorized",
                "detail": "Missing X-Agent-Secret header",
            },
        )
    settings = get_settings()
    if x_agent_secret != settings.agent_shared_secret:
        raise HTTPException(
            status_code=403,
            detail={
                "error": "forbidden",
                "detail": "Invalid X-Agent-Secret value",
            },
        )
    return x_agent_secret


# ---------------------------------------------------------------------------
# Custom exception handler so HTTPException detail dicts render as-is
# ---------------------------------------------------------------------------

@app.exception_handler(HTTPException)
async def _http_exception_handler(_request: Request, exc: HTTPException) -> JSONResponse:
    """Return the ``detail`` payload directly when it is already a dict."""
    body = exc.detail if isinstance(exc.detail, dict) else {"error": "error", "detail": exc.detail}
    return JSONResponse(status_code=exc.status_code, content=body)


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------

@app.get("/healthz")
async def healthz() -> dict[str, str]:
    """Liveness / readiness probe.  No authentication required."""
    return {"status": "ok"}


@app.post(
    "/api/agent/run",
    dependencies=[Depends(_verify_secret)],
    response_model=AgentRunResponse,
    responses={
        422: {"model": AgentErrorResponse},
        500: {"model": AgentErrorResponse},
    },
)
async def run_agent(request_body: AgentRunRequest) -> dict[str, Any]:
    """Run the LangGraph agent pipeline on an uploaded clinical document.

    Invokes the compiled graph (extract -> retrieve -> finalize) and
    converts the output to an ``AgentRunResponse``.  On extraction
    refusal the graph routes through the refuse node and this endpoint
    returns a 422 error response.
    """
    from agent_service.graph import build_graph  # noqa: PLC0415 -- deferred to avoid circular import at module level

    try:
        # Lazy-build the graph.  In production this would be cached,
        # but for correctness the caller can inject different clients
        # via the test harness.
        llm_client, rag_pipeline = _resolve_dependencies()

        graph = build_graph(
            llm_client=llm_client,
            rag_pipeline=rag_pipeline,
        )

        # Prepare initial state from the validated request.
        initial_state: dict[str, Any] = {
            "file_path": request_body.file_path,
            "doc_type": request_body.doc_type.value,
            "trace_id": request_body.trace_id,
            "patient_id": request_body.patient_id,
            "encounter_id": request_body.encounter_id,
            "tool_sequence": [],
            "latency_ms_per_step": {},
        }

        # Invoke the graph synchronously (workers are CPU-bound, not async).
        result = graph.invoke(initial_state)

        # Check for refusal / error path.
        if result.get("status") == "refused" or ("error" in result and "extracted" not in result):
            error_detail = result.get("error", "Extraction refused")
            raise HTTPException(
                status_code=422,
                detail={
                    "error": "extraction_refused",
                    "detail": error_detail,
                    "trace_id": request_body.trace_id,
                },
            )

        # Assemble success response.
        return {
            "extracted": result.get("extracted", {}),
            "evidence": result.get("evidence", []),
            "answer": result.get("answer", ""),
            "citations": result.get("citations", []),
            "cost_usd": result.get("cost_usd", 0.0),
            "latency_ms_per_step": result.get("latency_ms_per_step", {}),
            "tool_sequence": result.get("tool_sequence", []),
            "extraction_confidence": result.get("extraction_confidence", 0.0),
        }

    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("Unexpected error in agent pipeline")
        raise HTTPException(
            status_code=500,
            detail={
                "error": "internal_error",
                "detail": str(exc),
                "trace_id": request_body.trace_id,
            },
        ) from exc


def _resolve_dependencies() -> tuple[Any, Any]:
    """Resolve LLM client and RAG pipeline from application config.

    Returns
    -------
    tuple
        ``(llm_client, rag_pipeline)`` ready for graph construction.
    """
    from agent_service.clients.openai_client import OpenAIClient  # noqa: PLC0415
    from agent_service.rag.bm25_index import BM25Index  # noqa: PLC0415
    from agent_service.rag.corpus_loader import load_corpus  # noqa: PLC0415
    from agent_service.rag.dense_index import DenseIndex, fake_embed  # noqa: PLC0415
    from agent_service.rag.pipeline import RAGPipeline  # noqa: PLC0415
    from agent_service.rag.reranker import FakeReranker  # noqa: PLC0415

    llm_client = OpenAIClient()

    corpus = load_corpus()
    bm25 = BM25Index(corpus)
    dense = DenseIndex.from_chunks_with_fake_embeddings(corpus, dim=64)
    rag_pipeline = RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )

    return llm_client, rag_pipeline
