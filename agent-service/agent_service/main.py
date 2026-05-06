"""FastAPI application for the OpenEMR agent sidecar."""

from __future__ import annotations

from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse

from agent_service.config import get_settings

app = FastAPI(
    title="OpenEMR Agent Sidecar",
    version="0.1.0",
    docs_url="/docs",
    redoc_url=None,
)


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


@app.post("/api/agent/run", dependencies=[Depends(_verify_secret)])
async def run_agent(request: Request) -> dict[str, Any]:
    """Stub endpoint -- will be wired to the real agent pipeline later."""
    body = await request.json()
    return {
        "extracted": {},
        "evidence": [],
        "answer": "stub: agent run not yet implemented",
        "citations": [],
        "cost_usd": 0.0,
        "latency_ms_per_step": {},
        "tool_sequence": [],
        "extraction_confidence": 0.0,
        "trace_id": body.get("trace_id", ""),
    }
