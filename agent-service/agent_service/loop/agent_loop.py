"""Inspectable LLM tool-choice agent loop for the chart copilot (M13).

This module is the central piece tying every other M-step together:

* M5/M6 -- the tool registry + policy-enforced executor.
* M7 -- the closed-set intent catalog.
* M10/M11/M12 -- real evidence/source/document tools.
* M14 -- the response builder.
* M15 -- the answer verifier.

The :class:`AgentLoop` accepts a verified
:class:`agent_service.auth.copilot_run_context.CopilotRunContext`, asks the
injected LLM client which tool to call (and in what order), funnels every
call through :func:`agent_service.tools.executor.execute_tool`, and
returns a verified :class:`agent_service.schemas.copilot.CopilotRunResponse`.

Determinism
-----------
All non-determinism is injected:

* The LLM client (real OpenAI client / fake replay client).
* The wall-clock used for the time budget.
* The structured logger used for PHI-safe trace spans.

Tests therefore never observe real-time clocks, real LLM completions, or
random ordering. The loop stops at one of the typed ``halt_reason``
values defined on :class:`AgentLoopResult`; every stop is mapped into a
deterministic :class:`CopilotRunResponse` so callers always see a well-
formed envelope.

Caps
----
The loop enforces three independent caps:

* ``max_iterations`` -- model turns. The LLM client is called at most
  ``max_iterations`` times.
* ``max_wall_time_s`` -- elapsed time since the loop entered ``run``.
  Checked at the top of every iteration.
* ``max_tool_calls`` -- total successful and failed tool calls across
  all turns combined. Defends against a model that loops on harmless
  tools.

Hitting any cap routes through :meth:`ResponseBuilder.build_refusal` with
a generic ``tool_error`` reason and a non-PHI explanation, so the wire
shape stays uniform whether the loop succeeded, was refused by the
verifier, or simply ran out of budget.

System prompt
-------------
The loop builds the system prompt from intent metadata only -- intent
ID, label, goal template, and the fixed safety preamble. **Never** from
the run context (which carries patient identifiers). This is a hard
invariant: the M3 token's PHI surface (patient_id, encounter_id, MRN-
adjacent IDs) must never reach the model's prompt or tool schemas.
"""

from __future__ import annotations

import logging
import time
import uuid
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Literal

from agent_service.answer.builder import RefusalReason, ResponseBuilder
from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.clients.tool_choice import (
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceClient,
    LLMToolChoiceTurn,
)
from agent_service.intents.catalog import (
    IntentCatalog,
    IntentDefinition,
    UnknownIntentError,
)
from agent_service.observability.events import EventType, RunEvent
from agent_service.observability.recorder import EventRecorder, NullEventRecorder
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunRequest,
    CopilotRunResponse,
    ToolCallRecord,
)
from agent_service.tools.source_drilldown import SOURCE_DETAIL_TOOL_NAME
from agent_service.tools.executor import (
    ToolCallOutcome,
    ToolExecutionError,
    execute_tool,
)
from agent_service.tools.registry import ToolRegistry
from agent_service.verifier.answer_verifier import (
    AnswerVerifier,
    VerificationResult,
)
from agent_service.verifier import to_refusal_response


__all__ = [
    "AgentLoop",
    "AgentLoopConfig",
    "AgentLoopResult",
    "HaltReason",
    "RegistryBuilder",
]


_LOGGER = logging.getLogger("agent_service.loop.agent_loop")


# ---------------------------------------------------------------------------
# Public types
# ---------------------------------------------------------------------------


HaltReason = Literal[
    "completed",
    "max_iterations",
    "wall_time",
    "max_tool_calls",
    "verifier_refused",
    "model_error",
    "tool_error",
]
"""Closed-set discriminator describing why the agent loop terminated."""


@dataclass(frozen=True, slots=True)
class AgentLoopConfig:
    """Caps that bound a single :meth:`AgentLoop.run` invocation.

    Defaults are conservative -- a 30-second wall clock and at most 8
    model turns -- so a misbehaving model cannot blow the request budget.
    Tests override these to exercise the cap branches directly.
    """

    max_iterations: int = 8
    max_wall_time_s: float = 30.0
    max_tool_calls: int = 12

    def __post_init__(self) -> None:
        if self.max_iterations <= 0:
            raise ValueError("max_iterations must be > 0")
        if self.max_wall_time_s <= 0:
            raise ValueError("max_wall_time_s must be > 0")
        if self.max_tool_calls <= 0:
            raise ValueError("max_tool_calls must be > 0")


@dataclass(frozen=True, slots=True)
class AgentLoopResult:
    """Inspectable result envelope from a single agent-loop run.

    The :class:`CopilotRunResponse` carried under ``response`` is the
    wire shape returned to the caller; the other fields are the same
    data sliced for observability and tests.
    """

    response: CopilotRunResponse
    tool_sequence: tuple[ToolCallRecord, ...]
    cost_usd: float
    latency_ms_per_step: dict[str, int]
    halt_reason: HaltReason


# Type alias: takes the verified run context, returns a fully-composed
# tool registry.  Injection point so tests can inject a registry seeded
# by patient_evidence_tool_registry / source_drilldown_tool_registry /
# document_tool_registry, while also allowing a single-tool registry for
# narrow unit tests.
RegistryBuilder = Callable[[CopilotRunContext], ToolRegistry]


# ---------------------------------------------------------------------------
# Internal helpers (kept module-private to constrain the public surface)
# ---------------------------------------------------------------------------


_REFUSAL_FOR_HALT: dict[HaltReason, RefusalReason] = {
    "max_iterations": "tool_error",
    "wall_time": "tool_error",
    "max_tool_calls": "tool_error",
    "model_error": "tool_error",
    "tool_error": "tool_error",
}


_HALT_EXPLANATIONS: dict[HaltReason, str] = {
    "max_iterations": (
        "The clinical co-pilot exceeded its work budget; please try again."
    ),
    "wall_time": (
        "The clinical co-pilot exceeded its work budget; please try again."
    ),
    "max_tool_calls": (
        "The clinical co-pilot exceeded its work budget; please try again."
    ),
    "model_error": (
        "The clinical co-pilot encountered an internal error; please try again."
    ),
    "tool_error": (
        "An evidence tool was unavailable; please try again."
    ),
}


_SYSTEM_PROMPT_PREAMBLE = (
    "You are a clinical chart co-pilot assistant. Your role is to retrieve "
    "and summarise bounded clinical evidence using ONLY the tools provided. "
    "Cite every claim with the citation IDs returned by the tools. "
    "Do not invent citations. Do not produce clinical recommendations or "
    "orders. If evidence is missing, say so using the phrase 'not found in "
    "checked evidence'. Patient identity, lookback windows, and row caps "
    "are injected by the system; do not provide them in tool arguments."
)


def _build_system_prompt(intent: IntentDefinition | None) -> str:
    """Compose a PHI-free system prompt from intent metadata only."""
    if intent is None:
        return _SYSTEM_PROMPT_PREAMBLE
    return (
        f"{_SYSTEM_PROMPT_PREAMBLE} "
        f"Active intent: '{intent.intent_id}' ({intent.label}). "
        f"Goal: {intent.goal_template}"
    )


def _build_user_prompt(
    *,
    request: CopilotRunRequest,
    intent: IntentDefinition | None,
) -> str:
    """Compose the initial user prompt for the loop.

    ``user_goal`` wins over the intent's ``goal_template`` when both are
    present, but the loop still falls back to the template so an
    intent-only request is well-formed.
    """
    if request.user_goal is not None and request.user_goal.strip():
        return request.user_goal.strip()
    if intent is not None:
        return intent.goal_template
    # CopilotRunRequest's model_validator already guarantees one of the
    # two is present, but guard for completeness.
    return ""  # pragma: no cover -- invariant of the request schema.


def _record_from_outcome(outcome: ToolCallOutcome) -> ToolCallRecord:
    """Convert a successful executor outcome to its wire-safe record.

    The ``ToolCallOutcome.arguments_keys`` tuple is sorted by the
    executor; we drop runtime-injected authority keys so the wire view
    only carries keys the model genuinely supplied. This keeps the
    ``ToolCallRecord`` PHI-safe and reproducible.
    """
    return ToolCallRecord(
        tool_name=outcome.tool_name,
        arguments_keys=sorted(_strip_authority_keys(outcome.arguments_keys)),
        result_count=outcome.result_count,
        latency_ms=outcome.latency_ms,
        error_class=outcome.error_class,
    )


def _record_from_error(
    *,
    tool_name: str,
    arguments_keys: Sequence[str],
    error_class: str,
    latency_ms: int,
) -> ToolCallRecord:
    """Build a wire-safe record for a rejected tool call."""
    return ToolCallRecord(
        tool_name=tool_name,
        arguments_keys=sorted(_strip_authority_keys(arguments_keys)),
        result_count=None,
        latency_ms=latency_ms,
        error_class=error_class,
    )


_AUTHORITY_KEYS = frozenset(
    {
        "patient_id",
        "encounter_id",
        "allowed_source_types",
        "lookback_days",
        "max_rows",
    },
)


def _strip_authority_keys(keys: Sequence[str]) -> tuple[str, ...]:
    """Drop authority-context keys injected by the executor."""
    return tuple(k for k in keys if k not in _AUTHORITY_KEYS)


def _trace_id_for(context: CopilotRunContext) -> str:
    """Pick the trace ID for the response.

    The run context carries the upstream trace ID minted by PHP; we
    reuse it so logs / observability spans correlate across services.
    Fallback to a fresh UUID4 only if the token is missing the field
    (defensive -- the M3 verifier already requires it).
    """
    return context.trace_id or str(uuid.uuid4())


def _serialise_tool_call(call: LLMToolCallChoice) -> dict[str, Any]:
    """Render a tool call into a dict suitable for the next-turn message.

    The model needs to round-trip its OWN tool-call arguments verbatim
    on the next turn -- OpenAI's chat-completions API requires the
    assistant message's ``tool_calls[i].function.arguments`` to be the
    same JSON the model emitted, so the conversation history stays
    consistent. ``arguments_keys`` was a safer view for the wire-side
    ``ToolCallRecord``, but messages back to the model carry the actual
    arguments (model-supplied input only -- runtime authority fields
    are injected by the executor and never reach the model).
    """
    return {
        "id": call.call_id,
        "tool_name": call.tool_name,
        "arguments": dict(call.arguments),
    }


def _extract_citations(payload: Mapping[str, Any]) -> list[Citation]:
    """Extract structured Citation objects from a tool's result payload.

    Tools return ``{"records": [...], "citations": [<Citation|dict>, ...],
    "warnings": [...]}``. This helper coerces the citations entry into
    a list of :class:`Citation` regardless of whether the executor
    handed us model instances or plain dicts. The verifier reads these
    as ``known_citation_ids`` so the model's claims can be checked
    against citations the tools actually returned.
    """
    raw = payload.get("citations") if isinstance(payload, Mapping) else None
    if not raw:
        return []
    citations: list[Citation] = []
    for entry in raw:
        if isinstance(entry, Citation):
            citations.append(entry)
            continue
        if isinstance(entry, Mapping):
            try:
                citations.append(Citation.model_validate(dict(entry)))
            except Exception:  # noqa: BLE001 - tool payload may be malformed
                continue
    return citations


def _dedupe_citations(citations: Sequence[Citation]) -> list[Citation]:
    """Deduplicate a list of citations by ``source_id``, preserving order."""
    seen: set[str] = set()
    unique: list[Citation] = []
    for citation in citations:
        if citation.source_id in seen:
            continue
        seen.add(citation.source_id)
        unique.append(citation)
    return unique


def _utc_now() -> datetime:
    """Return the current time as a timezone-aware UTC ``datetime``.

    Used to stamp ``occurred_at`` on :class:`RunEvent` documents.  Wall-
    clock time is intentionally separate from the monotonic ``clock``
    used for latency math so the event log records when things happened
    while the loop's cap arithmetic stays drift-free.
    """
    return datetime.now(tz=timezone.utc)


# ---------------------------------------------------------------------------
# Loop
# ---------------------------------------------------------------------------


class AgentLoop:
    """Inspectable LLM tool-choice loop.

    Construction takes every collaborator as a constructor parameter so
    tests can inject deterministic doubles. ``run`` is the only public
    entry point; the loop is stateless across invocations.
    """

    def __init__(
        self,
        *,
        intent_catalog: IntentCatalog,
        registry_builder: RegistryBuilder,
        response_builder: ResponseBuilder,
        verifier: AnswerVerifier,
        llm_client: LLMToolChoiceClient,
        config: AgentLoopConfig | None = None,
        clock: Callable[[], float] | None = None,
        logger: logging.Logger | None = None,
        event_recorder: EventRecorder | None = None,
        wall_clock: Callable[[], datetime] | None = None,
    ) -> None:
        self._intent_catalog = intent_catalog
        self._registry_builder = registry_builder
        self._response_builder = response_builder
        self._verifier = verifier
        self._llm_client = llm_client
        self._config = config if config is not None else AgentLoopConfig()
        self._clock = clock if clock is not None else time.monotonic
        self._logger = logger if logger is not None else _LOGGER
        # M16: per-event observability sink.  Defaults to a no-op so unit
        # tests that only care about the wire envelope are not forced to
        # construct a real recorder.  ``wall_clock`` stamps event UTC
        # timestamps and is injected separately from the monotonic
        # ``clock`` used for latency math.
        self._events: EventRecorder = (
            event_recorder if event_recorder is not None else NullEventRecorder()
        )
        self._wall_clock: Callable[[], datetime] = (
            wall_clock if wall_clock is not None else _utc_now
        )

    # -- public entry point -------------------------------------------------

    def run(
        self,
        *,
        request: CopilotRunRequest,
        context: CopilotRunContext,
    ) -> AgentLoopResult:
        """Execute the loop and return an :class:`AgentLoopResult`.

        Implementation outline:

        1. Resolve the active intent (if any) from ``request.intent_id``.
        2. Build the registry via ``registry_builder(context)`` and
           filter to ``context.allowed_tools`` intersected with the
           intent's ``allowed_tools``.
        3. Compose the initial messages from a PHI-free system prompt
           and the request's goal.
        4. Iterate up to ``max_iterations`` calling the LLM client.
           Forward every tool call request to ``execute_tool`` -- the
           executor enforces every authority/scoping rule on its own.
        5. On a final assistant message: verify and return.
        6. Map any cap miss / executor failure / model error into a
           deterministic refusal envelope.
        """
        trace_id = _trace_id_for(context)
        started_at = self._clock()
        latency_ms_per_step: dict[str, int] = {}
        tool_sequence: list[ToolCallRecord] = []
        # Citations accumulated from successful tool outcomes -- the
        # verifier (M15) reads these as ``known_citation_ids`` to detect
        # fabricated source IDs in the final assistant response. The
        # tools are the source of truth for "which citations exist";
        # the model only chooses which to cite.
        tool_citations: list[Citation] = []
        cost_usd = 0.0  # M13 does not estimate cost; left as a hook for M16.

        # M16: emit the run.received span so downstream observability can
        # correlate the rest of the events with this trace.
        self._emit_event(event_type="run.received", trace_id=trace_id)

        # 1. Resolve intent.
        intent = self._resolve_intent(request)
        if intent is None and request.intent_id is not None:
            # The request named an intent but the catalog did not know it.
            # Treat as out-of-scope -- emit a refusal with a precise reason.
            return self._refusal(
                halt_reason="model_error",
                tool_sequence=tool_sequence,
                cost_usd=cost_usd,
                latency_ms_per_step=latency_ms_per_step,
                trace_id=trace_id,
                started_at=started_at,
            )

        # 2. Build registry and compute the per-run allowed-tools set.
        registry = self._registry_builder(context)
        allowed_tools = self._effective_allowed_tools(
            context=context,
            intent=intent,
            registry=registry,
        )
        tool_schemas = registry.model_facing_schemas(allowed=allowed_tools)

        # 2a. Source-drilldown short-circuit.
        #
        # ``show_source`` is a constrained drilldown that lands on a
        # specific citation the UI already has in hand -- there is no
        # open-ended question for the model to answer. The chart UI
        # supplies the citation's ``source_id`` directly on the request,
        # so we resolve the bounded source detail deterministically and
        # skip the LLM round-trip entirely. This saves a model call,
        # makes the response shape predictable, and avoids "the model
        # had no context and gave up" empty-answer states.
        if (
            intent is not None
            and intent.is_source_drilldown
            and request.source_id
        ):
            return self._run_source_drilldown(
                request=request,
                context=context,
                intent=intent,
                registry=registry,
                trace_id=trace_id,
                started_at=started_at,
            )

        # 3. Prepare messages and per-step latency bookkeeping.
        messages: list[Mapping[str, Any]] = self._initial_messages(
            request=request,
            intent=intent,
        )
        if request.conversation_state:
            messages.append(
                {
                    "role": "system",
                    "name": "conversation_state",
                    "content": request.conversation_state,
                },
            )

        iterations = 0

        # 4. Main loop.
        while True:
            # Cap: max iterations.
            if iterations >= self._config.max_iterations:
                latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
                return self._refusal(
                    halt_reason="max_iterations",
                    tool_sequence=tool_sequence,
                    cost_usd=cost_usd,
                    latency_ms_per_step=latency_ms_per_step,
                    trace_id=trace_id,
                    started_at=started_at,
                )

            # Cap: wall-time.
            if (self._clock() - started_at) >= self._config.max_wall_time_s:
                latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
                return self._refusal(
                    halt_reason="wall_time",
                    tool_sequence=tool_sequence,
                    cost_usd=cost_usd,
                    latency_ms_per_step=latency_ms_per_step,
                    trace_id=trace_id,
                    started_at=started_at,
                )

            iterations += 1
            iter_started = self._clock()
            self._emit_event(
                event_type="model.turn.started",
                trace_id=trace_id,
            )
            try:
                turn = self._llm_client.tool_call_completion(
                    messages=messages,
                    tools=tool_schemas,
                )
            except Exception as exc:  # noqa: BLE001 -- intentional broad catch
                error_class = type(exc).__name__
                self._logger.error(
                    "agent loop model error",
                    extra={
                        "trace_id": trace_id,
                        "iteration": iterations,
                        "error_class": error_class,
                    },
                )
                turn_latency_ms = self._delta_ms(iter_started)
                latency_ms_per_step.setdefault(
                    f"llm_call_{iterations}_ms",
                    turn_latency_ms,
                )
                latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
                # Emit a model.turn.finished event with the failure class
                # so observers can attribute the refusal to the model.
                self._emit_event(
                    event_type="model.turn.finished",
                    trace_id=trace_id,
                    latency_ms=turn_latency_ms,
                    error_class=error_class,
                )
                return self._refusal(
                    halt_reason="model_error",
                    tool_sequence=tool_sequence,
                    cost_usd=cost_usd,
                    latency_ms_per_step=latency_ms_per_step,
                    trace_id=trace_id,
                    started_at=started_at,
                )

            turn_latency_ms = self._delta_ms(iter_started)
            latency_ms_per_step[f"llm_call_{iterations}_ms"] = turn_latency_ms
            self._emit_event(
                event_type="model.turn.finished",
                trace_id=trace_id,
                latency_ms=turn_latency_ms,
            )

            if turn.tool_calls:
                # Cap: max total tool calls.
                if (
                    len(tool_sequence) + len(turn.tool_calls)
                    > self._config.max_tool_calls
                ):
                    latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(
                        started_at,
                    )
                    return self._refusal(
                        halt_reason="max_tool_calls",
                        tool_sequence=tool_sequence,
                        cost_usd=cost_usd,
                        latency_ms_per_step=latency_ms_per_step,
                        trace_id=trace_id,
                        started_at=started_at,
                    )

                # Append an assistant turn describing the tool requests so
                # the model has a faithful record on the next iteration.
                messages.append(
                    {
                        "role": "assistant",
                        "tool_calls": [
                            _serialise_tool_call(c) for c in turn.tool_calls
                        ],
                    },
                )

                # Execute each tool call sequentially. Failures append a
                # ``tool_error`` message so the model can react on the
                # next turn (e.g. switch tools or refuse).
                for call in turn.tool_calls:
                    self._emit_event(
                        event_type="tool.started",
                        trace_id=trace_id,
                        tool_name=call.tool_name,
                    )
                    record, payload = self._execute_one(
                        call=call,
                        context=context,
                        registry=registry,
                        trace_id=trace_id,
                    )
                    tool_sequence.append(record)
                    if payload is not None:
                        for citation in _extract_citations(payload):
                            tool_citations.append(citation)
                    self._emit_event(
                        event_type="tool.finished",
                        trace_id=trace_id,
                        tool_name=record.tool_name,
                        result_count=record.result_count,
                        latency_ms=record.latency_ms,
                        error_class=record.error_class,
                    )
                    messages.append(
                        self._tool_result_message(
                            call=call,
                            record=record,
                            payload=payload,
                        ),
                    )
                continue  # next iteration

            # 5. Final assistant message: verify and emit.
            final_message = turn.final_message
            assert final_message is not None  # turn invariant
            response = self._build_final_response(
                final_message=final_message,
                tool_sequence=tool_sequence,
                cost_usd=cost_usd,
                latency_ms_per_step=latency_ms_per_step,
                trace_id=trace_id,
                started_at=started_at,
            )
            # Backfill ``response.citations`` from the tools' actual
            # outcomes when the LLM-produced response did not include
            # a structured citations array. The verifier reads this
            # set as ``known_citation_ids``; without it, every claim's
            # citation_id looks fabricated and M15 always refuses.
            if not response.citations and tool_citations:
                response = response.model_copy(
                    update={"citations": _dedupe_citations(tool_citations)},
                )
            verifier_started = self._clock()
            verification = self._verify(
                response=response,
                tool_sequence=tool_sequence,
            )
            if verification.status == "refused":
                self._emit_event(
                    event_type="verifier.finished",
                    trace_id=trace_id,
                    latency_ms=self._delta_ms(verifier_started),
                    verifier_outcome="refused",
                    refusal_reason=verification.refusal_reason,
                )
                refused = to_refusal_response(
                    builder=self._response_builder,
                    result=verification,
                    tool_sequence=tool_sequence,
                    cost_usd=cost_usd,
                    latency_ms_per_step=latency_ms_per_step,
                    trace_id=trace_id,
                )
                latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(
                    started_at,
                )
                self._emit_event(
                    event_type="response.returned",
                    trace_id=trace_id,
                    latency_ms=latency_ms_per_step["loop_total_ms"],
                    refusal_reason=verification.refusal_reason,
                    cost_usd_delta=cost_usd,
                )
                return AgentLoopResult(
                    response=refused,
                    tool_sequence=tuple(tool_sequence),
                    cost_usd=cost_usd,
                    latency_ms_per_step=dict(latency_ms_per_step),
                    halt_reason="verifier_refused",
                )

            self._emit_event(
                event_type="verifier.finished",
                trace_id=trace_id,
                latency_ms=self._delta_ms(verifier_started),
                verifier_outcome="passed",
            )
            latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
            self._emit_event(
                event_type="response.returned",
                trace_id=trace_id,
                latency_ms=latency_ms_per_step["loop_total_ms"],
                cost_usd_delta=cost_usd,
            )
            return AgentLoopResult(
                response=response,
                tool_sequence=tuple(tool_sequence),
                cost_usd=cost_usd,
                latency_ms_per_step=dict(latency_ms_per_step),
                halt_reason="completed",
            )

    # -- private helpers ----------------------------------------------------

    def _resolve_intent(
        self,
        request: CopilotRunRequest,
    ) -> IntentDefinition | None:
        if not request.intent_id:
            return None
        try:
            return self._intent_catalog.get(request.intent_id)
        except UnknownIntentError:
            return None

    def _effective_allowed_tools(
        self,
        *,
        context: CopilotRunContext,
        intent: IntentDefinition | None,
        registry: ToolRegistry,
    ) -> tuple[str, ...]:
        """Compute the per-run tool-name allow-list.

        Order of restriction (most permissive first):
        1. Tools registered in ``registry``.
        2. Tools the run context allows.
        3. Tools the resolved intent allows (when present).
        """
        registered = set(registry.list_names())
        allowed = registered & set(context.allowed_tools)
        if intent is not None:
            allowed &= set(intent.allowed_tools)
        return tuple(sorted(allowed))

    def _initial_messages(
        self,
        *,
        request: CopilotRunRequest,
        intent: IntentDefinition | None,
    ) -> list[Mapping[str, Any]]:
        return [
            {
                "role": "system",
                "content": _build_system_prompt(intent),
            },
            {
                "role": "user",
                "content": _build_user_prompt(request=request, intent=intent),
            },
        ]

    def _execute_one(
        self,
        *,
        call: LLMToolCallChoice,
        context: CopilotRunContext,
        registry: ToolRegistry,
        trace_id: str,
    ) -> tuple[ToolCallRecord, Mapping[str, Any] | None]:
        """Execute a single tool call.

        Returns ``(record, payload)``. ``payload`` is the executor's
        structured result (records, citations, warnings) on success, or
        ``None`` when the tool was rejected before reaching its
        executor. The payload is folded into the next-turn ``role:tool``
        message so the LLM can quote records and cite source IDs.
        """
        try:
            outcome = execute_tool(
                context,
                call.tool_name,
                call.arguments,
                registry=registry,
                logger=self._logger,
            )
        except ToolExecutionError as exc:
            error_class = exc.reason
            self._logger.warning(
                "agent loop tool rejected",
                extra={
                    "trace_id": trace_id,
                    "tool_name": call.tool_name,
                    "reason": error_class,
                },
            )
            return (
                _record_from_error(
                    tool_name=call.tool_name,
                    arguments_keys=tuple(sorted(call.arguments.keys())),
                    error_class=error_class,
                    latency_ms=0,
                ),
                None,
            )
        payload: Mapping[str, Any] | None = None
        if isinstance(outcome.payload, Mapping):
            payload = dict(outcome.payload)
        return _record_from_outcome(outcome), payload

    def _tool_result_message(
        self,
        *,
        call: LLMToolCallChoice,
        record: ToolCallRecord,
        payload: Mapping[str, Any] | None = None,
    ) -> Mapping[str, Any]:
        """Build the loop's internal tool-result message.

        The model needs the actual tool output so it can quote records
        and cite source IDs in its claims. ``payload`` carries the raw
        tool-side response (records, citations, warnings) that the
        OpenAI adapter folds into the ``content`` field. ``payload`` is
        ``None`` for error rows -- the error class alone is enough for
        the model to decide whether to retry or refuse.
        """
        if record.error_class is not None:
            return {
                "role": "tool",
                "tool_call_id": call.call_id,
                "tool_name": record.tool_name,
                "status": "error",
                "error_class": record.error_class,
            }
        message: dict[str, Any] = {
            "role": "tool",
            "tool_call_id": call.call_id,
            "tool_name": record.tool_name,
            "status": "ok",
            "result_count": record.result_count,
        }
        if payload is not None:
            message["payload"] = payload
        return message

    def _verify(
        self,
        *,
        response: CopilotRunResponse,
        tool_sequence: Sequence[ToolCallRecord],
    ) -> VerificationResult:
        # ``known_citation_ids`` is the set of citation source_ids the
        # tools actually returned -- the verifier rejects any cited ID
        # the model invented.
        known_ids: set[str] = {c.source_id for c in response.citations}
        all_succeeded = all(r.error_class is None for r in tool_sequence)
        return self._verifier.verify(
            response=response,
            known_citation_ids=known_ids,
            tool_call_succeeded=all_succeeded,
        )

    def _build_final_response(
        self,
        *,
        final_message: LLMFinalMessage,
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        started_at: float,
    ) -> CopilotRunResponse:
        # Only the fake's structured-response path is supported in M13.
        # When ``parsed_response`` is set, we copy the wire shape back
        # with the real loop-side metadata (tool_sequence, latency,
        # trace_id) so the caller observes accurate observability data
        # regardless of what the LLM client packed into the response.
        if final_message.parsed_response is None:
            # Construct a deterministic refusal -- the loop cannot ship
            # a wire response built out of un-parsed assistant text. The
            # M16 milestone replaces this with structured-output parsing.
            return self._response_builder.build_refusal(
                reason="tool_error",
                explanation=(
                    "The clinical co-pilot returned an unparseable response."
                ),
                tool_sequence=tool_sequence,
                cost_usd=cost_usd,
                latency_ms_per_step={
                    **latency_ms_per_step,
                    "loop_total_ms": self._elapsed_ms(started_at),
                },
                trace_id=trace_id,
            )

        parsed = final_message.parsed_response
        # Rebuild with loop-owned bookkeeping fields so they are always
        # accurate regardless of what the model side put in.
        return parsed.model_copy(
            update={
                "tool_sequence": list(tool_sequence),
                "cost_usd": cost_usd,
                "latency_ms_per_step": {
                    **latency_ms_per_step,
                    "loop_total_ms": self._elapsed_ms(started_at),
                },
                "trace_id": trace_id,
            },
        )

    # -- show_source drilldown short-circuit --------------------------------

    def _run_source_drilldown(
        self,
        *,
        request: CopilotRunRequest,
        context: CopilotRunContext,
        intent: IntentDefinition,
        registry: ToolRegistry,
        trace_id: str,
        started_at: float,
    ) -> AgentLoopResult:
        """Resolve a ``show_source`` request without invoking the LLM.

        The chart UI clicks a citation chip and posts the citation's
        opaque ``source_id`` to the controller; PHP forwards it on the
        :class:`CopilotRunRequest`. There is no open-ended question for
        the model -- the user just wants to see the bounded detail of
        one specific row. We invoke ``get_source_detail`` directly via
        the M6 executor (which still enforces every authority/scoping
        rule) and shape the response from its return bag.
        """
        tool_started = self._clock()
        latency_ms_per_step: dict[str, int] = {}
        cost_usd = 0.0
        tool_sequence: list[ToolCallRecord] = []

        source_id = request.source_id or ""
        call = LLMToolCallChoice(
            call_id="show_source_internal",
            tool_name=SOURCE_DETAIL_TOOL_NAME,
            arguments={"source_id": source_id},
        )

        self._emit_event(
            event_type="tool.started",
            trace_id=trace_id,
            tool_name=SOURCE_DETAIL_TOOL_NAME,
        )
        record, payload = self._execute_one(
            call=call,
            context=context,
            registry=registry,
            trace_id=trace_id,
        )
        tool_sequence.append(record)
        self._emit_event(
            event_type="tool.finished",
            trace_id=trace_id,
            tool_name=record.tool_name,
            result_count=record.result_count,
            latency_ms=record.latency_ms,
            error_class=record.error_class,
        )
        latency_ms_per_step["show_source_tool_ms"] = self._delta_ms(tool_started)

        records = []
        if isinstance(payload, Mapping):
            raw_records = payload.get("records")
            if isinstance(raw_records, list):
                records = list(raw_records)

        # Tool failure or no records -- emit a deterministic "not found"
        # answer so the UI panel renders something explanatory rather
        # than collapsing back to an empty envelope.
        if record.error_class is not None or not records:
            response = self._build_drilldown_missing_response(
                tool_sequence=tool_sequence,
                cost_usd=cost_usd,
                latency_ms_per_step=latency_ms_per_step,
                trace_id=trace_id,
                started_at=started_at,
            )
            latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
            self._emit_event(
                event_type="response.returned",
                trace_id=trace_id,
                latency_ms=latency_ms_per_step["loop_total_ms"],
                cost_usd_delta=cost_usd,
            )
            return AgentLoopResult(
                response=response,
                tool_sequence=tuple(tool_sequence),
                cost_usd=cost_usd,
                latency_ms_per_step=dict(latency_ms_per_step),
                halt_reason="completed",
            )

        detail = records[0]
        citations = list(_extract_citations(payload))

        response = self._build_drilldown_response(
            detail=detail,
            citations=citations,
            tool_sequence=tool_sequence,
            cost_usd=cost_usd,
            latency_ms_per_step=latency_ms_per_step,
            trace_id=trace_id,
            started_at=started_at,
        )
        latency_ms_per_step["loop_total_ms"] = self._elapsed_ms(started_at)
        self._emit_event(
            event_type="response.returned",
            trace_id=trace_id,
            latency_ms=latency_ms_per_step["loop_total_ms"],
            cost_usd_delta=cost_usd,
        )
        return AgentLoopResult(
            response=response,
            tool_sequence=tuple(tool_sequence),
            cost_usd=cost_usd,
            latency_ms_per_step=dict(latency_ms_per_step),
            halt_reason="completed",
        )

    def _build_drilldown_response(
        self,
        *,
        detail: Mapping[str, Any],
        citations: Sequence[Citation],
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        started_at: float,
    ) -> CopilotRunResponse:
        """Shape a :class:`CopilotRunResponse` from a source-detail row."""
        label = str(detail.get("label") or "Source record")
        body = str(detail.get("body") or "")
        source_id = str(detail.get("source_id") or "")
        occurred_at = detail.get("occurred_at")

        claim_parts: list[str] = []
        if body:
            claim_parts.append(body)
        if isinstance(occurred_at, str) and occurred_at:
            claim_parts.append(f"Recorded: {occurred_at[:10]}")
        claim_text = label
        if claim_parts:
            claim_text = f"{label}: {' | '.join(claim_parts)}"

        citation_ids = [source_id] if source_id else []
        claim = Claim(
            text=claim_text,
            citation_ids=citation_ids,
            certainty="source_record",
        )
        block = AnswerBlock(
            heading="Source",
            claims=[claim],
            body_markdown=None,
        )

        merged_latency = dict(latency_ms_per_step)
        merged_latency["loop_total_ms"] = self._elapsed_ms(started_at)

        return CopilotRunResponse(
            answer_blocks=[block],
            claims=[claim],
            citation_ids=citation_ids,
            certainty="high" if citations else "unknown",
            missing_or_uncertain=[],
            citations=list(citations),
            tool_sequence=list(tool_sequence),
            verification_status="passed",
            cost_usd=cost_usd,
            latency_ms_per_step=merged_latency,
            trace_id=trace_id,
        )

    def _build_drilldown_missing_response(
        self,
        *,
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        started_at: float,
    ) -> CopilotRunResponse:
        """Build a clear "source not available" answer when drilldown fails."""
        merged_latency = dict(latency_ms_per_step)
        merged_latency["loop_total_ms"] = self._elapsed_ms(started_at)
        claim = Claim(
            text=(
                "Source record could not be retrieved -- it may belong to "
                "a different patient or be outside the allowed evidence scope."
            ),
            citation_ids=[],
            certainty="not_found",
        )
        block = AnswerBlock(
            heading="Source",
            claims=[claim],
            body_markdown=None,
        )
        return CopilotRunResponse(
            answer_blocks=[block],
            claims=[claim],
            citation_ids=[],
            certainty="unknown",
            missing_or_uncertain=[],
            citations=[],
            tool_sequence=list(tool_sequence),
            verification_status="passed",
            cost_usd=cost_usd,
            latency_ms_per_step=merged_latency,
            trace_id=trace_id,
        )

    def _refusal(
        self,
        *,
        halt_reason: HaltReason,
        tool_sequence: Sequence[ToolCallRecord],
        cost_usd: float,
        latency_ms_per_step: Mapping[str, int],
        trace_id: str,
        started_at: float,
    ) -> AgentLoopResult:
        merged_latency = dict(latency_ms_per_step)
        merged_latency.setdefault(
            "loop_total_ms",
            self._elapsed_ms(started_at),
        )
        refusal_reason = _REFUSAL_FOR_HALT[halt_reason]
        response = self._response_builder.build_refusal(
            reason=refusal_reason,
            explanation=_HALT_EXPLANATIONS[halt_reason],
            tool_sequence=tool_sequence,
            cost_usd=cost_usd,
            latency_ms_per_step=merged_latency,
            trace_id=trace_id,
        )
        self._emit_event(
            event_type="response.returned",
            trace_id=trace_id,
            latency_ms=merged_latency["loop_total_ms"],
            refusal_reason=refusal_reason,
            cost_usd_delta=cost_usd,
        )
        return AgentLoopResult(
            response=response,
            tool_sequence=tuple(tool_sequence),
            cost_usd=cost_usd,
            latency_ms_per_step=merged_latency,
            halt_reason=halt_reason,
        )

    # -- timing helpers -----------------------------------------------------

    def _delta_ms(self, started: float) -> int:
        return max(0, int((self._clock() - started) * 1000))

    def _elapsed_ms(self, started_at: float) -> int:
        return max(0, int((self._clock() - started_at) * 1000))

    # -- event emission -----------------------------------------------------

    def _emit_event(
        self,
        *,
        event_type: EventType,
        trace_id: str,
        latency_ms: int | None = None,
        tool_name: str | None = None,
        result_count: int | None = None,
        token_usage_input: int | None = None,
        token_usage_output: int | None = None,
        cost_usd_delta: float | None = None,
        refusal_reason: str | None = None,
        verifier_outcome: Literal["passed", "refused"] | None = None,
        error_class: str | None = None,
    ) -> None:
        """Build a :class:`RunEvent` and hand it to the injected sink.

        Construction runs the PHI scanner; if a caller smuggles a
        PHI-bearing string into ``tool_name`` / ``error_class`` etc. we
        log the rejection at ERROR level and drop the event rather than
        crashing the run.  The caller is the agent loop itself so this
        is purely a defence-in-depth path; under normal operation the
        scanner never trips.
        """
        try:
            event = RunEvent(
                trace_id=trace_id,
                event_type=event_type,
                occurred_at=self._wall_clock(),
                latency_ms=latency_ms,
                tool_name=tool_name,
                result_count=result_count,
                token_usage_input=token_usage_input,
                token_usage_output=token_usage_output,
                cost_usd_delta=cost_usd_delta,
                refusal_reason=refusal_reason,
                verifier_outcome=verifier_outcome,
                error_class=error_class,
            )
        except Exception as exc:  # noqa: BLE001 -- defence in depth
            self._logger.error(
                "agent loop event rejected by PHI scanner",
                extra={
                    "trace_id": trace_id,
                    "event_type": event_type,
                    "error_class": type(exc).__name__,
                },
            )
            return

        try:
            self._events.record(event)
        except Exception as exc:  # noqa: BLE001 -- recorder must not break run
            self._logger.error(
                "agent loop event recorder failed",
                extra={
                    "trace_id": trace_id,
                    "event_type": event_type,
                    "error_class": type(exc).__name__,
                },
            )
