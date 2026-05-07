"""HTTP route for ``POST /api/copilot/run``.

Step M2 introduced the wire contract -- request and response schemas --
plus a stub endpoint that always returned HTTP 501. M4 wired the
:func:`verify_copilot_run_context` verifier (M3) into this route as a
FastAPI dependency. M13 replaces the stub body with the real agent loop:
the verified :class:`CopilotRunContext` is handed to an
:class:`AgentLoop`, which selects tools, executes them through the M6
policy-enforced executor, builds a :class:`CopilotRunResponse` with the
M14 builder, and verifies it with the M15 verifier before returning it.

Determinism in tests
--------------------
The loop and its dependencies are exposed as injectable FastAPI
dependencies. Tests override :func:`get_llm_tool_choice_client` and
:func:`get_registry_builder` with fakes so the loop is replayable
without touching real LLM endpoints or repositories.

The router is mounted under the ``/api/copilot`` prefix in
:mod:`agent_service.main`, so the full path is ``/api/copilot/run``.
"""

from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException

from agent_service.answer.builder import ResponseBuilder
from agent_service.api.dependencies import require_copilot_run_context
from agent_service.auth import CopilotRunContext
from agent_service.clients.tool_choice import LLMToolChoiceClient
from agent_service.clients.tool_choice_openai import (
    OpenAIToolChoiceClient,
    _MissingOpenAIKeyClient,
)
from agent_service.config import Settings, get_settings
from agent_service.intents import IntentCatalog, default_catalog
from agent_service.loop import AgentLoop, AgentLoopConfig, RegistryBuilder
from agent_service.observability.recorder import (
    EventRecorder,
    JsonlEventRecorder,
    NullEventRecorder,
)
from agent_service.schemas.copilot import CopilotRunRequest, CopilotRunResponse
from agent_service.tools.registry import ToolRegistry, default_registry
from agent_service.verifier import AnswerVerifier


logger = logging.getLogger(__name__)


router = APIRouter(tags=["copilot"])


# ---------------------------------------------------------------------------
# Dependency providers (overridable via app.dependency_overrides)
# ---------------------------------------------------------------------------


def get_intent_catalog() -> IntentCatalog:
    """Return a fresh :class:`IntentCatalog`.

    The catalog is cheap to construct and stateless after construction,
    so we build a new instance per request rather than caching at
    module scope. Tests override this when they need a custom catalog.
    """
    return default_catalog()


def get_response_builder() -> ResponseBuilder:
    """Return a default :class:`ResponseBuilder`."""
    return ResponseBuilder()


def get_answer_verifier() -> AnswerVerifier:
    """Return a default :class:`AnswerVerifier`."""
    return AnswerVerifier()


def get_agent_loop_config() -> AgentLoopConfig:
    """Return a default :class:`AgentLoopConfig`."""
    return AgentLoopConfig()


def get_llm_tool_choice_client() -> LLMToolChoiceClient:
    """Return the production LLM tool-choice client.

    Reads ``OPENAI_API_KEY`` from settings. When the key is present, a
    real :class:`OpenAIToolChoiceClient` is composed; missing keys yield
    a :class:`_MissingOpenAIKeyClient` whose
    ``tool_call_completion`` raises :class:`LLMNotConfiguredError` only
    when the agent loop actually invokes it. This deferral preserves
    FastAPI's body / auth validation ordering so 422 / 401 responses are
    returned for bad inputs even on a misconfigured deployment. Tests
    override this dependency with :class:`FakeLLMToolChoiceClient` to
    keep the loop deterministic.
    """
    try:
        settings: Settings = get_settings()
    except RuntimeError:
        # ``get_settings`` raises when ``AGENT_SHARED_SECRET`` is unset
        # in test environments. Fall through to the missing-key sentinel
        # so misconfigured deployments still produce a typed refusal
        # rather than a generic crash.
        return _MissingOpenAIKeyClient()
    if not settings.openai_api_key:
        return _MissingOpenAIKeyClient()
    return OpenAIToolChoiceClient(api_key=settings.openai_api_key)


def get_registry_builder() -> RegistryBuilder:
    """Return the registry builder used for new runs.

    The default builder ignores the run context and returns the
    inert :func:`default_registry` -- safe at import time but useless
    in production because every tool is a stub. Real production wiring
    overrides this to compose ``patient_evidence_tool_registry`` /
    ``source_drilldown_tool_registry`` / ``document_tool_registry``;
    tests override it with a tightly-scoped fake registry.
    """

    def _build(_context: CopilotRunContext) -> ToolRegistry:
        return default_registry()

    return _build


def get_event_recorder() -> EventRecorder:
    """Return the per-tool-call observability sink for the agent loop (M16).

    When ``OBSERVABILITY_EVENTS_PATH`` is configured, returns a
    :class:`JsonlEventRecorder` writing to that path.  Otherwise events
    are dropped through a :class:`NullEventRecorder`.

    Tests override this dependency directly when they want to capture
    events for inspection -- the per-request resolution model means a
    fresh recorder is composed for every request.
    """
    try:
        settings: Settings = get_settings()
    except RuntimeError:
        # ``get_settings`` raises when ``AGENT_SHARED_SECRET`` is unset
        # in a test environment. The agent loop already routes through a
        # NullEventRecorder by default so this is a safe fallback.
        return NullEventRecorder()
    if settings.observability_events_path is None:
        return NullEventRecorder()
    return JsonlEventRecorder(path=settings.observability_events_path)


def get_agent_loop(
    intent_catalog: Annotated[IntentCatalog, Depends(get_intent_catalog)],
    response_builder: Annotated[ResponseBuilder, Depends(get_response_builder)],
    verifier: Annotated[AnswerVerifier, Depends(get_answer_verifier)],
    llm_client: Annotated[
        LLMToolChoiceClient,
        Depends(get_llm_tool_choice_client),
    ],
    registry_builder: Annotated[
        RegistryBuilder,
        Depends(get_registry_builder),
    ],
    config: Annotated[AgentLoopConfig, Depends(get_agent_loop_config)],
    event_recorder: Annotated[EventRecorder, Depends(get_event_recorder)],
) -> AgentLoop:
    """Compose an :class:`AgentLoop` for a single request.

    Each request gets a fresh loop with freshly-resolved collaborators;
    the loop itself is stateless across runs so this is cheap.
    """
    return AgentLoop(
        intent_catalog=intent_catalog,
        registry_builder=registry_builder,
        response_builder=response_builder,
        verifier=verifier,
        llm_client=llm_client,
        config=config,
        event_recorder=event_recorder,
    )


# ---------------------------------------------------------------------------
# Route
# ---------------------------------------------------------------------------


@router.post(
    "/run",
    response_model=CopilotRunResponse,
    responses={
        401: {"description": "Run context missing, invalid, or expired."},
        500: {"description": "Internal error; the wire response is generic."},
    },
)
async def run_copilot(
    request_body: CopilotRunRequest,
    run_context: Annotated[CopilotRunContext, Depends(require_copilot_run_context)],
    loop: Annotated[AgentLoop, Depends(get_agent_loop)],
) -> CopilotRunResponse:
    """Execute the M13 agent loop for a verified run context.

    The dependency :func:`require_copilot_run_context` (M4) has already
    verified the signed token; ``run_context`` carries the parsed,
    frozen claims. The body of this handler then:

    1. Calls :meth:`AgentLoop.run` with the validated request and the
       authority context.
    2. Returns the resulting :class:`CopilotRunResponse` to the caller.

    Error handling
    --------------
    * 401 / 422 are produced by the M4 dependency layer or Pydantic.
    * Any unexpected exception is collapsed into a generic 500 with a
      static body so internal details never reach the client.
    """
    try:
        result = loop.run(request=request_body, context=run_context)
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001 -- intentional broad catch
        logger.exception(
            "agent loop crashed",
            extra={"trace_id": run_context.trace_id},
        )
        raise HTTPException(
            status_code=500,
            detail={"error": "internal_error"},
        ) from exc
    return result.response


__all__ = [
    "get_agent_loop",
    "get_agent_loop_config",
    "get_answer_verifier",
    "get_event_recorder",
    "get_intent_catalog",
    "get_llm_tool_choice_client",
    "get_registry_builder",
    "get_response_builder",
    "router",
]
