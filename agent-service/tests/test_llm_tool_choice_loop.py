"""Tests for the M13 LLM tool-choice agent loop.

Coverage goals from ``Clinical Co-Pilot Migration to Python Sidecar.md``
M13:

* A canned fake model can choose multiple tools.
* Disallowed tool choices are refused by the executor.
* Loop stops at ``max_iterations``.
* Loop stops at ``max_tool_calls``.
* Loop stops at ``wall_time``.
* Verifier refusal yields a refusal response.
* Model error is handled gracefully (no exception leaks).
* ``tool_sequence`` shape is deterministic and PHI-safe (no values).
* Defense-in-depth against model-supplied authority fields.

The loop is exercised with a deterministic
:class:`agent_service.clients.tool_choice.FakeLLMToolChoiceClient`
playing back scripted turns. A monotonic clock is also injected so the
``wall_time`` cap can be exercised without sleeping.
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from typing import Any

import pytest

from agent_service.answer.builder import ResponseBuilder
from agent_service.auth import CopilotRunContext
from agent_service.clients.tool_choice import (
    FakeLLMToolChoiceClient,
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceTurn,
    ScriptedTurn,
)
from agent_service.intents.catalog import IntentCatalog, IntentDefinition
from agent_service.loop import AgentLoop, AgentLoopConfig
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunRequest,
    CopilotRunResponse,
    MissingOrUncertain,
)
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry
from agent_service.verifier import AnswerVerifier


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------


_TOKEN_EXPIRES_AT = 1_900_000_000  # well past 2026


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
    expires_at: int = _TOKEN_EXPIRES_AT,
) -> CopilotRunContext:
    return CopilotRunContext.model_validate(
        {
            "user_id": 17,
            "username": "dr.smith",
            "patient_id": 42,
            "encounter_id": 100,
            "allowed_tools": list(
                allowed_tools
                if allowed_tools is not None
                else ["get_current_medications", "get_active_allergies"],
            ),
            "allowed_source_types": ["medications", "allergies"],
            "max_rows": 25,
            "lookback_days": 365,
            "expires_at": expires_at,
            "request_id": "req-13-loop",
            "trace_id": "trace-13-loop",
            "key_version": "v1",
        },
    )


def _make_request(
    *,
    intent_id: str | None = "current_medications",
    user_goal: str | None = None,
) -> CopilotRunRequest:
    payload: dict[str, Any] = {
        "run_context": "signed.token.opaque",
        "intent_id": intent_id,
        "user_goal": user_goal,
        "request_id": "11111111-2222-4333-8444-555555555555",
        "conversation_state": None,
    }
    return CopilotRunRequest.model_validate(payload)


def _empty_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {}, "additionalProperties": False}


def _record_executor(
    *,
    citations: tuple[Citation, ...] = (),
    records: tuple[Mapping[str, Any], ...] | None = None,
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    """Return a tool executor that emits a fixed record bag."""

    def _exec(
        _context: CopilotRunContext,
        _runtime_args: Mapping[str, Any],
    ) -> dict[str, Any]:
        return {
            "records": list(records) if records is not None else [{"id": 1}],
            "citations": list(citations),
        }

    return _exec


def _build_test_registry(
    *,
    tools: tuple[ToolDefinition, ...] | None = None,
) -> ToolRegistry:
    registry = ToolRegistry()
    if tools is None:
        tools = (
            ToolDefinition(
                name="get_current_medications",
                description="Return the patient's active medication list.",
                input_schema=_empty_schema(),
                required_capability="read_basic_patient_data",
                source_types=("medications",),
                read_only=True,
                max_rows=25,
                executor=_record_executor(
                    citations=(
                        Citation(
                            source_type="patient_record",
                            source_id="med:1",
                            label="Active medication list",
                        ),
                    ),
                    records=({"id": 1},),
                ),
            ),
            ToolDefinition(
                name="get_active_allergies",
                description="Return the patient's active allergy list.",
                input_schema=_empty_schema(),
                required_capability="read_basic_patient_data",
                source_types=("allergies",),
                read_only=True,
                max_rows=25,
                executor=_record_executor(
                    citations=(
                        Citation(
                            source_type="patient_record",
                            source_id="allergy:1",
                            label="Active allergy list",
                        ),
                    ),
                    records=({"id": 2},),
                ),
            ),
        )
    for tool in tools:
        registry.register(tool)
    return registry


def _intent_catalog() -> IntentCatalog:
    """Build a catalog whose ``allowed_tools`` are present in the test registry."""
    intents = (
        IntentDefinition(
            intent_id="current_medications",
            label="Current medications",
            goal_template="Show me current medications.",
            allowed_tools=("get_current_medications", "get_active_allergies"),
            max_rows=25,
            lookback_days=365,
            allowed_source_types=("medications", "allergies"),
        ),
    )
    return IntentCatalog(intents, tool_registry=_build_test_registry())


def _final_response_with_med_citation() -> CopilotRunResponse:
    """Return a verifier-passing pre-built CopilotRunResponse."""
    return CopilotRunResponse(
        answer_blocks=[
            AnswerBlock(
                heading="Current medications",
                claims=[
                    Claim(
                        text="Lisinopril 10 mg PO daily",
                        citation_ids=["med:1"],
                        certainty="active",
                    ),
                ],
            ),
        ],
        claims=[
            Claim(
                text="Lisinopril 10 mg PO daily",
                citation_ids=["med:1"],
                certainty="active",
            ),
        ],
        citation_ids=["med:1"],
        certainty="high",
        missing_or_uncertain=[],
        citations=[
            Citation(
                source_type="patient_record",
                source_id="med:1",
                label="Active medication list",
            ),
        ],
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id="trace-13-loop",
    )


def _final_response_with_fabricated_citation() -> CopilotRunResponse:
    """Return a response that cites an ID the tools never returned."""
    return CopilotRunResponse(
        answer_blocks=[
            AnswerBlock(
                heading="Current medications",
                claims=[
                    Claim(
                        text="Atorvastatin 20 mg PO nightly",
                        citation_ids=["med:fake-9999"],
                        certainty="active",
                    ),
                ],
            ),
        ],
        claims=[
            Claim(
                text="Atorvastatin 20 mg PO nightly",
                citation_ids=["med:fake-9999"],
                certainty="active",
            ),
        ],
        citation_ids=["med:fake-9999"],
        certainty="high",
        missing_or_uncertain=[],
        citations=[
            Citation(
                source_type="patient_record",
                source_id="med:1",
                label="Active medication list",
            ),
        ],
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id="trace-13-loop",
    )


def _build_loop(
    *,
    llm_client: FakeLLMToolChoiceClient,
    registry: ToolRegistry | None = None,
    config: AgentLoopConfig | None = None,
    clock: Callable[[], float] | None = None,
    intent_catalog: IntentCatalog | None = None,
) -> AgentLoop:
    reg = registry if registry is not None else _build_test_registry()
    return AgentLoop(
        intent_catalog=intent_catalog if intent_catalog is not None else _intent_catalog(),
        registry_builder=lambda _ctx: reg,
        response_builder=ResponseBuilder(),
        verifier=AnswerVerifier(),
        llm_client=llm_client,
        config=config if config is not None else AgentLoopConfig(),
        clock=clock,
    )


# ---------------------------------------------------------------------------
# Scripted clock
# ---------------------------------------------------------------------------


class _ScriptedClock:
    """Monotonic-ish clock that advances by ``step`` on every call.

    Useful for exercising the wall-time cap without ``time.sleep``.
    """

    def __init__(self, *, step_seconds: float, start: float = 0.0) -> None:
        self._step = step_seconds
        self._t = start

    def __call__(self) -> float:
        current = self._t
        self._t += self._step
        return current


# ---------------------------------------------------------------------------
# Happy-path: scripted multi-tool flow
# ---------------------------------------------------------------------------


class TestHappyPath:
    def test_multi_tool_flow_yields_completed_response(self) -> None:
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                            LLMToolCallChoice(
                                call_id="c1",
                                tool_name="get_active_allergies",
                                arguments={},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(
                            parsed_response=_final_response_with_med_citation(),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(llm_client=client)

        result = loop.run(
            request=_make_request(),
            context=_make_context(),
        )

        assert result.halt_reason == "completed"
        assert result.response.verification_status == "passed"
        # Two tool calls executed in the order the model chose them.
        assert [r.tool_name for r in result.tool_sequence] == [
            "get_current_medications",
            "get_active_allergies",
        ]
        # Trace ID round-trips through the loop bookkeeping.
        assert result.response.trace_id == "trace-13-loop"
        # The fake was called exactly twice (one per turn).
        assert len(client.calls) == 2
        # First call sent two tool schemas, no prior tool result messages.
        assert len(client.calls[0]["tools"]) == 2

    def test_tool_sequence_records_have_required_shape(self) -> None:
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(
                            parsed_response=_final_response_with_med_citation(),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(llm_client=client)
        result = loop.run(
            request=_make_request(),
            context=_make_context(),
        )

        assert len(result.tool_sequence) == 1
        record = result.tool_sequence[0]
        # PHI-safe: arguments_keys is a list of strings (no values).
        assert isinstance(record.arguments_keys, list)
        assert all(isinstance(k, str) for k in record.arguments_keys)
        # Authority-context keys must not appear in the wire view.
        for forbidden in ("patient_id", "encounter_id", "lookback_days", "max_rows", "allowed_source_types"):
            assert forbidden not in record.arguments_keys
        assert record.error_class is None
        assert record.result_count == 1
        assert isinstance(record.latency_ms, int)
        assert record.latency_ms >= 0


# ---------------------------------------------------------------------------
# Defense-in-depth: executor-level rejections surface as ToolCallRecords
# ---------------------------------------------------------------------------


class TestExecutorRejections:
    def test_disallowed_tool_recorded_then_recovered(self) -> None:
        """A model attempting an out-of-scope tool gets refused by the executor."""
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            # The intent excludes get_active_allergies *only* if
                            # we narrow the catalog -- but for simplicity here
                            # we use a context whose allowed_tools omits one.
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_active_allergies",
                                arguments={},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(
                            parsed_response=_final_response_with_med_citation(),
                        ),
                    ),
                ),
            ),
        )
        # Context only authorises medications, so the allergies call fails.
        ctx = _make_context(allowed_tools=["get_current_medications"])
        loop = _build_loop(llm_client=client)

        result = loop.run(request=_make_request(), context=ctx)

        # The disallowed call was recorded and the loop continued.
        assert any(
            r.error_class == "tool_not_allowed"
            for r in result.tool_sequence
        )
        # Loop ultimately produced a final response (not a hard failure).
        assert result.halt_reason in {"completed", "verifier_refused"}

    def test_model_supplied_authority_field_recorded(self) -> None:
        """The defense-in-depth invariant: ``patient_id`` cannot be passed."""
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                # M6 must reject the entire call: patient_id
                                # is in EXECUTOR_FORBIDDEN_MODEL_KEYS.
                                arguments={"patient_id": 42},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(
                            parsed_response=_final_response_with_med_citation(),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(llm_client=client)
        result = loop.run(request=_make_request(), context=_make_context())

        assert any(
            r.error_class == "model_supplied_authority_field"
            for r in result.tool_sequence
        )


# ---------------------------------------------------------------------------
# Caps
# ---------------------------------------------------------------------------


class TestCaps:
    def test_max_iterations_yields_refusal(self) -> None:
        # Every turn requests one tool call -- never a final message.
        # The loop should bail at max_iterations and emit a refusal.
        infinite_tool_turns = tuple(
            ScriptedTurn(
                turn=LLMToolChoiceTurn(
                    tool_calls=(
                        LLMToolCallChoice(
                            call_id=f"c{i}",
                            tool_name="get_current_medications",
                            arguments={},
                        ),
                    ),
                ),
            )
            for i in range(5)
        )
        client = FakeLLMToolChoiceClient(script=infinite_tool_turns)
        loop = _build_loop(
            llm_client=client,
            config=AgentLoopConfig(
                max_iterations=3,
                max_wall_time_s=60.0,
                max_tool_calls=99,
            ),
        )
        result = loop.run(request=_make_request(), context=_make_context())
        assert result.halt_reason == "max_iterations"
        assert result.response.verification_status == "refused"
        # The refusal carries no claims tied to fabricated citations.
        assert result.response.citation_ids == []

    def test_max_tool_calls_yields_refusal(self) -> None:
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                            LLMToolCallChoice(
                                call_id="c1",
                                tool_name="get_active_allergies",
                                arguments={},
                            ),
                            LLMToolCallChoice(
                                call_id="c2",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(
            llm_client=client,
            config=AgentLoopConfig(
                max_iterations=8,
                max_wall_time_s=60.0,
                max_tool_calls=2,
            ),
        )
        result = loop.run(request=_make_request(), context=_make_context())
        assert result.halt_reason == "max_tool_calls"
        assert result.response.verification_status == "refused"

    def test_wall_time_yields_refusal(self) -> None:
        # Clock advances 100s per call; the cap is 1s. The first wall-time
        # check at the top of iteration 2 sees an elapsed time well over 1s.
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c1",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(
            llm_client=client,
            clock=_ScriptedClock(step_seconds=100.0),
            config=AgentLoopConfig(
                max_iterations=8,
                max_wall_time_s=1.0,
                max_tool_calls=99,
            ),
        )
        result = loop.run(request=_make_request(), context=_make_context())
        assert result.halt_reason == "wall_time"


# ---------------------------------------------------------------------------
# Verifier refusal path
# ---------------------------------------------------------------------------


class TestVerifierRefusal:
    def test_fabricated_citation_yields_refusal(self) -> None:
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        tool_calls=(
                            LLMToolCallChoice(
                                call_id="c0",
                                tool_name="get_current_medications",
                                arguments={},
                            ),
                        ),
                    ),
                ),
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(
                            parsed_response=_final_response_with_fabricated_citation(),
                        ),
                    ),
                ),
            ),
        )
        loop = _build_loop(llm_client=client)
        result = loop.run(request=_make_request(), context=_make_context())
        assert result.halt_reason == "verifier_refused"
        assert result.response.verification_status == "refused"
        # The refused response does not surface the fabricated citation
        # IDs in citation_ids; build_refusal emits an empty list.
        assert result.response.citation_ids == []


# ---------------------------------------------------------------------------
# Model error
# ---------------------------------------------------------------------------


class TestModelError:
    def test_model_raises_yields_refusal(self) -> None:
        client = FakeLLMToolChoiceClient(
            script=(
                ScriptedTurn(raise_exc=RuntimeError("boom")),
            ),
        )
        loop = _build_loop(llm_client=client)
        result = loop.run(request=_make_request(), context=_make_context())
        assert result.halt_reason == "model_error"
        assert result.response.verification_status == "refused"
        # No exception propagated; we got a deterministic refusal envelope.
        assert isinstance(result.response, CopilotRunResponse)


# ---------------------------------------------------------------------------
# Determinism / shape invariants
# ---------------------------------------------------------------------------


class TestDeterminism:
    def test_same_script_yields_same_tool_sequence(self) -> None:
        def _run() -> tuple[str, ...]:
            client = FakeLLMToolChoiceClient(
                script=(
                    ScriptedTurn(
                        turn=LLMToolChoiceTurn(
                            tool_calls=(
                                LLMToolCallChoice(
                                    call_id="c0",
                                    tool_name="get_current_medications",
                                    arguments={},
                                ),
                                LLMToolCallChoice(
                                    call_id="c1",
                                    tool_name="get_active_allergies",
                                    arguments={},
                                ),
                            ),
                        ),
                    ),
                    ScriptedTurn(
                        turn=LLMToolChoiceTurn(
                            final_message=LLMFinalMessage(
                                parsed_response=_final_response_with_med_citation(),
                            ),
                        ),
                    ),
                ),
            )
            loop = _build_loop(llm_client=client)
            result = loop.run(request=_make_request(), context=_make_context())
            return tuple(r.tool_name for r in result.tool_sequence)

        first = _run()
        second = _run()
        assert first == second
        assert first == ("get_current_medications", "get_active_allergies")


# ---------------------------------------------------------------------------
# Pytest sanity guard: every test uses the deterministic fake client.
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _no_openai_env(monkeypatch: pytest.MonkeyPatch) -> None:
    """Make sure no OPENAI_API_KEY leaks into FakeLLMClient guards.

    The M13 loop tests use :class:`FakeLLMToolChoiceClient` exclusively,
    which has no env-var guard, but other modules import the OpenAI
    fake at collection time. Ensuring the env is clean keeps the test
    suite reproducible across local + CI environments.
    """
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
