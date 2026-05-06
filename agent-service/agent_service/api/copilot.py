"""HTTP route stub for ``POST /api/copilot/run``.

Step M2 introduces only the **wire contract** -- request and response
schemas -- and a stub endpoint that always returns HTTP 501.  The
real handler arrives in M4 (signed-context verification) and M13
(LLM tool-choice agent loop).  Wiring the route now lets the PHP
proxy work in M17 land before the agent loop is finished.

The router is mounted under the ``/api/copilot`` prefix in
:mod:`agent_service.main`, so the full path is ``/api/copilot/run``.
"""

from __future__ import annotations

from fastapi import APIRouter, HTTPException

from agent_service.schemas.copilot import CopilotRunRequest, CopilotRunResponse

router = APIRouter(tags=["copilot"])


@router.post(
    "/run",
    response_model=CopilotRunResponse,
    responses={
        501: {"description": "Stub: handler arrives in M4/M13."},
    },
)
async def run_copilot(request_body: CopilotRunRequest) -> CopilotRunResponse:
    """Stub handler for the chart copilot run endpoint.

    Validates the request body against the M2 contract and then raises
    ``HTTPException(501)``.  The signed-context verifier (M4) and the
    actual agent loop (M13) replace this body in later steps.
    """
    # Touch the validated body so static analyzers see the parameter as
    # used and so accidental future regressions that drop validation are
    # caught by the request-shape tests.
    _ = request_body.request_id

    raise HTTPException(
        status_code=501,
        detail={
            "error": "not_implemented",
            "message": "stub: handler arrives in M4/M13",
        },
    )


__all__ = ["router"]
