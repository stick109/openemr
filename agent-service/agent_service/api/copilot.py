"""HTTP route for ``POST /api/copilot/run``.

Step M2 introduced the wire contract -- request and response schemas --
plus a stub endpoint that always returned HTTP 501. M4 wires the
:func:`verify_copilot_run_context` verifier (M3) into this route as a
FastAPI dependency. Unsigned, expired, tampered, or unknown-key
requests are now rejected at the dependency layer with a fail-closed
401 response **before** the body executes. Valid signed requests reach
this stub, which still returns HTTP 501 because the agent loop arrives
in M13.

The router is mounted under the ``/api/copilot`` prefix in
:mod:`agent_service.main`, so the full path is ``/api/copilot/run``.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException

from agent_service.api.dependencies import require_copilot_run_context
from agent_service.auth import CopilotRunContext
from agent_service.schemas.copilot import CopilotRunRequest, CopilotRunResponse

router = APIRouter(tags=["copilot"])


@router.post(
    "/run",
    response_model=CopilotRunResponse,
    responses={
        401: {"description": "Run context missing, invalid, or expired."},
        501: {"description": "Stub: agent loop arrives in M13."},
    },
)
async def run_copilot(
    request_body: CopilotRunRequest,
    run_context: Annotated[CopilotRunContext, Depends(require_copilot_run_context)],
) -> CopilotRunResponse:
    """Stub handler for the chart copilot run endpoint.

    Validates the request body against the M2 contract, runs the M4
    signed-context verifier via the
    :func:`require_copilot_run_context` dependency, and then raises
    ``HTTPException(501)``. The verified context is bound to a local
    variable so the structure is in place for M13 to start consuming
    it; nothing else uses the value yet.
    """
    # Touch the validated body so static analyzers see the parameter as
    # used and so accidental future regressions that drop validation are
    # caught by the request-shape tests.
    _ = request_body.request_id

    # M13 will thread the verified authority context into the agent loop
    # (tool registry, PHI scope, expiry checks). Until then we keep the
    # binding so the dependency wiring is exercised end-to-end.
    _run_context: CopilotRunContext = run_context  # noqa: F841 -- consumed in M13

    raise HTTPException(
        status_code=501,
        detail={
            "error": "not_implemented",
            "message": "stub: agent loop arrives in M13",
        },
    )


__all__ = ["router"]
