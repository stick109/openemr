"""FastAPI application for the OpenEMR agent sidecar."""

from __future__ import annotations

import logging
import os
import sys
import tempfile
import uuid as _uuid
from typing import Any

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse

from agent_service.api import copilot_router
from agent_service.config import get_settings
from agent_service.schemas.api import (
    AgentErrorResponse,
    AgentRunResponse,
    DocType,
)

# Whitelist of safe extensions for the temp-file suffix; mirrors PHP's
# SharedUploadManager.  An unknown / hostile filename falls back to ``.pdf``
# so the temp file always has a well-defined extension on disk.
_ALLOWED_UPLOAD_EXTENSIONS: frozenset[str] = frozenset({
    ".pdf", ".png", ".jpg", ".jpeg", ".tiff", ".tif",
    ".txt", ".csv", ".xml", ".json", ".hl7",
})

# 1 MiB chunk for streaming large uploads from the request body to disk
# without loading them entirely into memory.
_UPLOAD_CHUNK_SIZE = 1024 * 1024


def _configure_observability_logging() -> None:
    """Ensure ``agent_service.observability`` records reach stdout.

    Uvicorn configures its own loggers (``uvicorn``, ``uvicorn.access``,
    ``uvicorn.error``) at INFO level on a stdout stream handler, but it
    leaves application loggers at the Python default (``WARNING`` on the
    root logger, no handlers attached). When :class:`LoggingEventRecorder`
    emits an event it lands on ``agent_service.observability``; without
    a handler somewhere up the chain that record is silently dropped.

    We attach a stream handler directly to the observability logger and
    disable propagation so the records do not double-print through
    uvicorn's root handler. The handler is idempotent: re-importing the
    module (e.g. test reloads) does not stack duplicate handlers.
    """
    obs_logger = logging.getLogger("agent_service.observability")
    obs_logger.setLevel(logging.INFO)
    # Avoid stacking duplicate handlers on hot reloads / repeated imports.
    if not any(
        isinstance(h, logging.StreamHandler)
        and getattr(h, "_agent_observability_handler", False)
        for h in obs_logger.handlers
    ):
        handler = logging.StreamHandler(stream=sys.stdout)
        handler.setLevel(logging.INFO)
        handler.setFormatter(
            logging.Formatter(
                "%(asctime)s %(levelname)s %(name)s %(message)s",
            ),
        )
        # Tag the handler so we can detect it on subsequent imports
        # without depending on object identity.
        handler._agent_observability_handler = True  # type: ignore[attr-defined]
        obs_logger.addHandler(handler)
    # Don't propagate to the root logger -- uvicorn's root handler would
    # otherwise emit each record a second time.
    obs_logger.propagate = False


_configure_observability_logging()


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


def _safe_temp_suffix(filename: str | None) -> str:
    """Return a safe extension for the temp file backing an upload."""
    if not filename:
        return ".pdf"
    ext = os.path.splitext(filename)[1].lower()
    return ext if ext in _ALLOWED_UPLOAD_EXTENSIONS else ".pdf"


@app.post(
    "/api/agent/run",
    dependencies=[Depends(_verify_secret)],
    response_model=AgentRunResponse,
    responses={
        422: {"model": AgentErrorResponse},
        500: {"model": AgentErrorResponse},
    },
)
async def run_agent(
    file: UploadFile = File(..., description="Uploaded clinical document"),
    patient_id: int = Form(..., gt=0, description="OpenEMR internal patient ID (pid)"),
    doc_type: DocType = Form(..., description="Document classification hint"),
    encounter_id: int = Form(..., gt=0, description="OpenEMR encounter ID"),
    trace_id: str = Form(..., min_length=1, description="UUID v4 correlation ID"),
) -> dict[str, Any]:
    """Run the LangGraph agent pipeline on an uploaded clinical document.

    The file is sent as a multipart ``file`` part; we stream it to a
    NamedTemporaryFile on the agent-service host, run the graph against
    that path, then delete the temp file.  No volume sharing with PHP --
    the only handoff is over HTTP.

    Invokes the compiled graph (extract -> retrieve -> finalize) and
    converts the output to an ``AgentRunResponse``.  On extraction
    refusal the graph routes through the refuse node and this endpoint
    returns a 422 error response.
    """
    # Validate trace_id matches the UUID-v4 shape required by the contract.
    # FastAPI's Form() handles the gt=0 / min_length checks above; we re-use
    # the previous schema's stricter validator here.
    try:
        trace_id = str(_uuid.UUID(trace_id, version=4))
    except ValueError as exc:
        raise HTTPException(
            status_code=422,
            detail={
                "error": "invalid_request",
                "detail": f"trace_id must be a valid UUID v4, got: {trace_id!r}",
                "trace_id": trace_id,
            },
        ) from exc

    # Stream the upload to a temp file so the rest of the pipeline can
    # consume it via a path -- the extractor and OpenAI client both expect
    # a filesystem path today and don't need to change.
    suffix = _safe_temp_suffix(file.filename)
    fd, temp_path = tempfile.mkstemp(suffix=suffix, prefix="agent-upload-")
    try:
        with os.fdopen(fd, "wb") as out:
            while True:
                chunk = await file.read(_UPLOAD_CHUNK_SIZE)
                if not chunk:
                    break
                out.write(chunk)

        from agent_service.graph import build_graph  # noqa: PLC0415

        try:
            # Lazy-build the graph.  In production this would be cached,
            # but for correctness the caller can inject different clients
            # via the test harness.
            llm_client, rag_pipeline = _resolve_dependencies()

            graph = build_graph(
                llm_client=llm_client,
                rag_pipeline=rag_pipeline,
            )

            initial_state: dict[str, Any] = {
                "file_path": temp_path,
                "doc_type": doc_type.value,
                "trace_id": trace_id,
                "patient_id": patient_id,
                "encounter_id": encounter_id,
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
                        "trace_id": trace_id,
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
                    "trace_id": trace_id,
                },
            ) from exc
    finally:
        try:
            os.unlink(temp_path)
        except OSError:
            # Best-effort cleanup; don't fail the response on cleanup error.
            pass


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
