"""Tests for the M16 per-tool-call observability event subsystem.

Coverage from ``Clinical Co-Pilot Migration to Python Sidecar.md`` M16:

* :class:`RunEvent` rejects synthetic PHI in every string field.
* :class:`RunEvent` accepts clean events.
* :class:`JsonlEventRecorder` round-trips events losslessly.
* The agent loop emits events in the correct order on a happy-path run.
* The agent loop emits ``tool.finished`` with ``error_class`` set when a
  tool raises.
* The agent loop emits ``verifier.finished`` with
  ``verifier_outcome="refused"`` and a refusal_reason when the verifier
  refuses.
* Cost and latency are observable on the right events (non-None values).
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from pathlib import Path
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
from pydantic import ValidationError

from agent_service.observability.events import RunEvent, RunEventPhiError
from agent_service.observability.recorder import (
    JsonlEventRecorder,
    NullEventRecorder,
)
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunRequest,
    CopilotRunResponse,
)
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry
from agent_service.verifier import AnswerVerifier


# ---------------------------------------------------------------------------
# Recording test double
# ---------------------------------------------------------------------------


class _RecordingEventRecorder:
    """Captures every event so tests can assert ordering and field shape."""

    def __init__(self) -> None:
        self.events: list[RunEvent] = []

    def record(self, event: RunEvent) -> None:
        self.events.append(event)


# ---------------------------------------------------------------------------
# Fixtures shared with the loop tests
# ---------------------------------------------------------------------------


_TOKEN_EXPIRES_AT = 1_900_000_000  # well past 2026


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
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
                else ["get_current_medications"],
            ),
            "allowed_source_types": ["medications"],
            "max_rows": 25,
            "lookback_days": 365,
            "expires_at": _TOKEN_EXPIRES_AT,
            "request_id": "req-16-events",
            "trace_id": "trace-16-events",
            "key_version": "v1",
        },
    )


def _make_request() -> CopilotRunRequest:
    return CopilotRunRequest.model_validate(
        {
            "run_context": "signed.token.opaque",
            "intent_id": "current_medications",
            "user_goal": None,
            "request_id": "11111111-2222-4333-8444-555555555555",
            "conversation_state": None,
        },
    )


def _empty_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {}, "additionalProperties": False}


def _record_executor(
    *,
    citations: tuple[Citation, ...] = (),
    records: tuple[Mapping[str, Any], ...] | None = None,
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    def _exec(
        _context: CopilotRunContext,
        _runtime_args: Mapping[str, Any],
    ) -> dict[str, Any]:
        return {
            "records": list(records) if records is not None else [{"id": 1}],
            "citations": list(citations),
        }

    return _exec


def _raising_executor(
    *,
    exc: Exception,
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    def _exec(
        _context: CopilotRunContext,
        _runtime_args: Mapping[str, Any],
    ) -> dict[str, Any]:
        raise exc

    return _exec


def _build_test_registry(
    *,
    executor: Callable[
        [CopilotRunContext, Mapping[str, Any]], dict[str, Any]
    ] | None = None,
) -> ToolRegistry:
    registry = ToolRegistry()
    registry.register(
        ToolDefinition(
            name="get_current_medications",
            description="Return the patient's active medication list.",
            input_schema=_empty_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=25,
            executor=executor
            if executor is not None
            else _record_executor(
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
    )
    return registry


def _intent_catalog(registry: ToolRegistry) -> IntentCatalog:
    intents = (
        IntentDefinition(
            intent_id="current_medications",
            label="Current medications",
            goal_template="Show me current medications.",
            allowed_tools=("get_current_medications",),
            max_rows=25,
            lookback_days=365,
            allowed_source_types=("medications",),
        ),
    )
    return IntentCatalog(intents, tool_registry=registry)


def _final_response_passing() -> CopilotRunResponse:
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
        trace_id="trace-16-events",
    )


def _final_response_with_fabricated_citation() -> CopilotRunResponse:
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
        trace_id="trace-16-events",
    )


def _build_loop(
    *,
    llm_client: FakeLLMToolChoiceClient,
    registry: ToolRegistry,
    recorder: _RecordingEventRecorder | None = None,
) -> tuple[AgentLoop, _RecordingEventRecorder]:
    rec = recorder if recorder is not None else _RecordingEventRecorder()
    loop = AgentLoop(
        intent_catalog=_intent_catalog(registry),
        registry_builder=lambda _ctx: registry,
        response_builder=ResponseBuilder(),
        verifier=AnswerVerifier(),
        llm_client=llm_client,
        config=AgentLoopConfig(),
        event_recorder=rec,
    )
    return loop, rec


# ---------------------------------------------------------------------------
# RunEvent shape & PHI rejection
# ---------------------------------------------------------------------------


class TestRunEventShape:
    """Field-level constraints on :class:`RunEvent`."""

    def test_baseline_event_constructs(self) -> None:
        event = RunEvent(
            trace_id="trace-001",
            event_type="run.received",
            occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
        )
        assert event.trace_id == "trace-001"
        assert event.event_type == "run.received"
        assert event.latency_ms is None

    def test_clean_tool_event_with_metrics(self) -> None:
        event = RunEvent(
            trace_id="trace-001",
            event_type="tool.finished",
            occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
            tool_name="get_current_medications",
            result_count=3,
            latency_ms=42,
            cost_usd_delta=0.0001,
        )
        assert event.tool_name == "get_current_medications"
        assert event.result_count == 3
        assert event.latency_ms == 42

    def test_extra_fields_rejected(self) -> None:
        with pytest.raises(Exception):  # pydantic ValidationError
            RunEvent(
                trace_id="trace-001",
                event_type="run.received",
                occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
                unexpected_field="anything",  # type: ignore[call-arg]
            )

    def test_negative_latency_rejected(self) -> None:
        with pytest.raises(Exception):  # pydantic ValidationError
            RunEvent(
                trace_id="trace-001",
                event_type="tool.finished",
                occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
                tool_name="get_current_medications",
                latency_ms=-1,
            )

    def test_negative_cost_delta_rejected(self) -> None:
        with pytest.raises(Exception):  # pydantic ValidationError
            RunEvent(
                trace_id="trace-001",
                event_type="model.turn.finished",
                occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
                cost_usd_delta=-0.01,
            )


# ---------------------------------------------------------------------------
# PHI rejection across every string field
# ---------------------------------------------------------------------------


# Each tuple is (label, phi_string).  We will inject these into every string
# field of RunEvent and assert that construction raises.
_PHI_STRINGS: list[tuple[str, str]] = [
    ("ssn-dashed", "patient is 123-45-6789 today"),
    ("patient-name-colon", "Patient: John Smith was seen"),
    ("patient-name-equals", "Patient = Mary Jane Doe"),
    ("email", "contact alice.smith@example.com"),
    ("phone", "call 555-867-5309 to confirm"),
    ("address", "see 123 Main Street for follow-up"),
]


# Tuple of (field_name, base_kwargs) describing every string-typed field on
# ``RunEvent`` and the minimum-valid kwargs needed to construct an event
# whose only "dirty" field is the one under test.
_STRING_FIELDS: list[str] = [
    "trace_id",
    "tool_name",
    "refusal_reason",
    "error_class",
]


class TestRunEventPhiRejection:
    """Every string-typed field must reject every PHI pattern."""

    @pytest.mark.parametrize("phi_label,phi_value", _PHI_STRINGS)
    @pytest.mark.parametrize("field_name", _STRING_FIELDS)
    def test_phi_in_string_field_rejected(
        self,
        field_name: str,
        phi_label: str,
        phi_value: str,
    ) -> None:
        kwargs: dict[str, Any] = {
            "trace_id": "trace-001",
            "event_type": "tool.finished",
            "occurred_at": datetime(
                2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc,
            ),
        }
        # Patch the chosen field with a PHI-bearing string.
        kwargs[field_name] = phi_value

        # Pydantic V2 wraps ValueError raised in model validators into a
        # ValidationError -- the underlying RunEventPhiError chains in
        # via ``ValidationError.errors()`` but the public exception type
        # callers see is ValidationError, matching the S25 RunRecord
        # idiom.
        with pytest.raises(ValidationError) as excinfo:
            RunEvent(**kwargs)
        message = str(excinfo.value).lower()
        assert "phi" in message
        # The error message identifies the marker so an operator can
        # work out which scanner tripped.  ``phi_label`` is one of
        # ``ssn-dashed``, ``patient-name-colon`` etc.; the kind prefix
        # appears in the scanner's hit description.
        kind = phi_label.split("-")[0]
        assert kind in message
        # The PHI scanner emits ``RunEventPhiError`` under the hood; check
        # that it is the chained cause so the public type stays
        # consistent with the S-item idiom while the specialised
        # exception remains discoverable.
        assert any(
            isinstance(err.get("ctx", {}).get("error"), RunEventPhiError)
            for err in excinfo.value.errors()
        )

    def test_clean_string_fields_pass(self) -> None:
        # An event with non-PHI strings in every string-typed field
        # constructs without error.  ``refusal_reason`` is a closed-set
        # value (the verifier emits enum-like strings).
        event = RunEvent(
            trace_id="trace-clean-001",
            event_type="response.returned",
            occurred_at=datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
            tool_name="get_current_medications",
            refusal_reason="tool_error",
            error_class="ToolNotAllowed",
        )
        assert event.refusal_reason == "tool_error"


# ---------------------------------------------------------------------------
# JsonlEventRecorder round-trip
# ---------------------------------------------------------------------------


class TestJsonlEventRecorder:
    def test_round_trip(self, tmp_path: Path) -> None:
        path = tmp_path / "events.jsonl"
        recorder = JsonlEventRecorder(path=path)

        events = [
            RunEvent(
                trace_id="trace-rt-001",
                event_type="run.received",
                occurred_at=datetime(
                    2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc,
                ),
            ),
            RunEvent(
                trace_id="trace-rt-001",
                event_type="tool.finished",
                occurred_at=datetime(
                    2026, 5, 6, 12, 0, 1, tzinfo=timezone.utc,
                ),
                tool_name="get_current_medications",
                latency_ms=12,
                result_count=3,
            ),
            RunEvent(
                trace_id="trace-rt-001",
                event_type="response.returned",
                occurred_at=datetime(
                    2026, 5, 6, 12, 0, 2, tzinfo=timezone.utc,
                ),
                latency_ms=200,
                cost_usd_delta=0.0,
            ),
        ]
        for event in events:
            recorder.record(event)

        loaded = recorder.load_all()
        assert len(loaded) == len(events)
        assert [e.event_type for e in loaded] == [
            "run.received",
            "tool.finished",
            "response.returned",
        ]
        assert loaded[1].tool_name == "get_current_medications"
        assert loaded[1].latency_ms == 12
        assert loaded[2].cost_usd_delta == 0.0

    def test_load_all_with_blank_lines(self, tmp_path: Path) -> None:
        path = tmp_path / "events.jsonl"
        recorder = JsonlEventRecorder(path=path)
        recorder.record(
            RunEvent(
                trace_id="trace-001",
                event_type="run.received",
                occurred_at=datetime(
                    2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc,
                ),
            ),
        )
        # Inject a blank line; load_all should tolerate it.
        with path.open("a", encoding="utf-8") as fh:
            fh.write("\n")

        events = recorder.load_all()
        assert len(events) == 1

    def test_load_all_missing_file_returns_empty(self, tmp_path: Path) -> None:
        recorder = JsonlEventRecorder(path=tmp_path / "does-not-exist.jsonl")
        assert recorder.load_all() == []


# ---------------------------------------------------------------------------
# NullEventRecorder
# ---------------------------------------------------------------------------


class TestNullEventRecorder:
    def test_drops_events_without_error(self) -> None:
        recorder = NullEventRecorder()
        recorder.record(
            RunEvent(
                trace_id="trace-001",
                event_type="run.received",
                occurred_at=datetime(
                    2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc,
                ),
            ),
        )
        # No state to inspect; merely asserting no exception.


# ---------------------------------------------------------------------------
# Agent-loop integration
# ---------------------------------------------------------------------------


class TestAgentLoopEventEmission:
    """The agent loop must emit the expected event sequence."""

    def test_happy_path_event_sequence(self) -> None:
        registry = _build_test_registry()
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
                            parsed_response=_final_response_passing(),
                        ),
                    ),
                ),
            ),
        )
        loop, recorder = _build_loop(llm_client=client, registry=registry)
        result = loop.run(request=_make_request(), context=_make_context())

        assert result.halt_reason == "completed"
        types = [e.event_type for e in recorder.events]
        assert types == [
            "run.received",
            "model.turn.started",
            "model.turn.finished",
            "tool.started",
            "tool.finished",
            "model.turn.started",
            "model.turn.finished",
            "verifier.finished",
            "response.returned",
        ]
        # Every event carries the same trace_id.
        assert {e.trace_id for e in recorder.events} == {"trace-16-events"}
        # tool.finished carries the tool name and a result count.
        tool_finished = next(
            e for e in recorder.events if e.event_type == "tool.finished"
        )
        assert tool_finished.tool_name == "get_current_medications"
        assert tool_finished.result_count == 1
        assert tool_finished.error_class is None
        assert tool_finished.latency_ms is not None
        assert tool_finished.latency_ms >= 0
        # verifier.finished records a passed outcome on the happy path.
        verifier_finished = next(
            e for e in recorder.events if e.event_type == "verifier.finished"
        )
        assert verifier_finished.verifier_outcome == "passed"
        assert verifier_finished.refusal_reason is None
        # response.returned has a non-None latency.
        response_returned = next(
            e for e in recorder.events if e.event_type == "response.returned"
        )
        assert response_returned.latency_ms is not None
        assert response_returned.cost_usd_delta == 0.0

    def test_tool_raises_yields_error_class_event(self) -> None:
        # Build a registry whose executor raises ZeroDivisionError so the
        # M6 executor wraps it as ``executor_raised``.  The agent loop
        # should still emit a tool.finished event with error_class set.
        registry = _build_test_registry(
            executor=_raising_executor(exc=ZeroDivisionError("boom")),
        )
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
                            parsed_response=_final_response_passing(),
                        ),
                    ),
                ),
            ),
        )
        loop, recorder = _build_loop(llm_client=client, registry=registry)
        loop.run(request=_make_request(), context=_make_context())

        tool_finished = next(
            e for e in recorder.events if e.event_type == "tool.finished"
        )
        # The executor wraps the underlying exception with reason
        # ``executor_raised`` and surfaces that as the record's
        # error_class -- the loop emits whatever ToolCallRecord carries.
        assert tool_finished.error_class is not None

    def test_verifier_refusal_event(self) -> None:
        registry = _build_test_registry()
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
        loop, recorder = _build_loop(llm_client=client, registry=registry)
        result = loop.run(request=_make_request(), context=_make_context())

        assert result.halt_reason == "verifier_refused"
        verifier_finished = next(
            e for e in recorder.events if e.event_type == "verifier.finished"
        )
        assert verifier_finished.verifier_outcome == "refused"
        assert verifier_finished.refusal_reason == "fabricated_citation"

        # The terminal response.returned event carries the same
        # refusal_reason so consumers don't have to walk back through
        # the event log to find it.
        response_returned = next(
            e for e in recorder.events if e.event_type == "response.returned"
        )
        assert response_returned.refusal_reason == "fabricated_citation"

    def test_model_error_yields_response_returned(self) -> None:
        registry = _build_test_registry()
        client = FakeLLMToolChoiceClient(
            script=(ScriptedTurn(raise_exc=RuntimeError("boom")),),
        )
        loop, recorder = _build_loop(llm_client=client, registry=registry)
        result = loop.run(request=_make_request(), context=_make_context())

        assert result.halt_reason == "model_error"
        types = [e.event_type for e in recorder.events]
        # We started a turn, the turn raised, the loop emitted a
        # finished event with the exception class, then routed through
        # the cap-miss refusal path which terminates with
        # response.returned.
        assert "model.turn.started" in types
        assert "model.turn.finished" in types
        assert types[-1] == "response.returned"
        # The model.turn.finished event carries the exception class.
        finished = next(
            e for e in recorder.events if e.event_type == "model.turn.finished"
        )
        assert finished.error_class == "RuntimeError"

    def test_cost_and_latency_are_observable(self) -> None:
        # Cost is hooked through to ``response.returned``; latency lives
        # on every ``*.finished`` event.  Even though M13 reports 0.0 cost
        # the field must be set rather than left as None when the loop
        # has explicit cost bookkeeping.
        registry = _build_test_registry()
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
                            parsed_response=_final_response_passing(),
                        ),
                    ),
                ),
            ),
        )
        loop, recorder = _build_loop(llm_client=client, registry=registry)
        loop.run(request=_make_request(), context=_make_context())

        # Each *.finished event carries a non-None latency_ms.
        finished_events = [
            e
            for e in recorder.events
            if e.event_type
            in {
                "model.turn.finished",
                "tool.finished",
                "verifier.finished",
                "response.returned",
            }
        ]
        assert finished_events, "no *.finished events emitted"
        for event in finished_events:
            assert event.latency_ms is not None
            assert event.latency_ms >= 0

        # The terminal response.returned event carries cost_usd_delta.
        response_returned = next(
            e for e in recorder.events if e.event_type == "response.returned"
        )
        assert response_returned.cost_usd_delta is not None
