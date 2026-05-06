"""End-to-end runner for the M22 ``copilot-tools`` eval suite.

Where the original 50-case eval (:mod:`agent_service.eval.runner`) drives
the LangGraph extraction pipeline, the M22 suite drives the M13 LLM
tool-choice loop with a deterministic
:class:`agent_service.clients.tool_choice.FakeLLMToolChoiceClient` and a
:class:`agent_service.eval.recorder_capture.RecordingEventRecorder`,
scoring each run against
:class:`agent_service.eval.rubrics.copilot_tools.CopilotToolsRubrics`.

Fixture format
--------------

Each fixture is a JSON document under
``agent_service/eval/fixtures/copilot_tools/`` describing one scripted
loop run::

    {
      "fixture_id": "current_medications_happy",
      "intent_id": "current_medications",
      "user_goal": "Show me current medications.",
      "expected": {
        "halt_reason": "completed",
        "rubrics": {
          "tool_allowed": true, ...
        }
      },
      "scripted_turns": [
        {
          "tool_calls": [
            {
              "call_id": "c0",
              "tool_name": "get_current_medications",
              "arguments": {}
            }
          ]
        },
        {
          "final_response": {
            "claims": [
              {
                "text": "Lisinopril 10 mg PO daily",
                "citation_ids": ["med:1"],
                "certainty": "active"
              }
            ],
            "citation_ids": ["med:1"],
            "certainty": "high",
            "missing_or_uncertain": [],
            "verification_status": "passed",
            "answer_block_heading": "Current medications",
            "citations": [
              {
                "source_type": "patient_record",
                "source_id": "med:1",
                "label": "Active medication list"
              }
            ]
          }
        }
      ],
      "tool_payloads": {
        "get_current_medications": {
          "records": [{"id": 1}],
          "citations": [
            {
              "source_type": "patient_record",
              "source_id": "med:1",
              "label": "Active medication list"
            }
          ]
        }
      }
    }

The fixture tells the runner:

* Which cataloged intent to use (``intent_id``).
* What ``user_goal`` to seed the loop with.
* The scripted LLM tool-choice turns -- a list of
  :class:`agent_service.clients.tool_choice.ScriptedTurn` recipes.
  Each entry either lists ``tool_calls`` or carries a ``final_response``
  payload that the runner shapes into a
  :class:`agent_service.schemas.copilot.CopilotRunResponse`.
* What records / citations the scripted tools return.
* The expected halt reason and per-rubric pass/fail booleans.

A fixture marked ``regression: true`` is part of the regression-bucket;
the suite reports it under ``regressions`` separately so a
deliberately-failing fixture does not flip the suite-level exit code
into "broken".
"""

from __future__ import annotations

import json
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from agent_service.answer.builder import ResponseBuilder
from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.clients.tool_choice import (
    FakeLLMToolChoiceClient,
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceTurn,
    ScriptedTurn,
)
from agent_service.eval.recorder_capture import RecordingEventRecorder
from agent_service.eval.rubrics.copilot_tools import (
    CopilotToolsRubrics,
    score_copilot_tools_rubrics,
)
from agent_service.intents.catalog import (
    IntentCatalog,
    IntentDefinition,
    UnknownIntentError,
    default_catalog,
)
from agent_service.loop import AgentLoop, AgentLoopConfig, AgentLoopResult
from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    Claim,
    CopilotRunRequest,
    CopilotRunResponse,
    MissingOrUncertain,
    ToolCallRecord,
)
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry
from agent_service.verifier import AnswerVerifier


__all__ = [
    "CopilotToolsCaseResult",
    "CopilotToolsFixture",
    "CopilotToolsSuiteReport",
    "DEFAULT_COPILOT_TOOLS_FIXTURES_DIR",
    "DEFAULT_REGRESSION_FIXTURES_DIR",
    "load_fixtures",
    "run_copilot_tools_suite",
    "run_fixture",
]


# ---------------------------------------------------------------------------
# Locations
# ---------------------------------------------------------------------------


DEFAULT_COPILOT_TOOLS_FIXTURES_DIR: Path = (
    Path(__file__).resolve().parent / "fixtures" / "copilot_tools"
)
"""Default directory shipped with the package."""


# Tests author regression fixtures under this path.  Kept separate so a
# CI-side eval can invoke the suite over either bucket explicitly.
DEFAULT_REGRESSION_FIXTURES_DIR: Path = (
    Path(__file__).resolve().parent.parent.parent
    / "tests"
    / "fixtures"
    / "copilot_tools_regression"
)
"""Regression fixtures (intentionally fail-on-pass)."""


# Token expiry lifted from the M13 unit tests: a fixed value well past
# 2026 so the executor's expiry check never trips.
_TOKEN_EXPIRES_AT: int = 1_900_000_000


# ---------------------------------------------------------------------------
# Loaded fixture / report types
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class CopilotToolsFixture:
    """An eval fixture loaded off disk."""

    fixture_id: str
    intent_id: str
    user_goal: str
    scripted_turns: tuple[Mapping[str, Any], ...]
    tool_payloads: Mapping[str, Mapping[str, Any]]
    expected: Mapping[str, Any]
    is_regression: bool
    source_path: Path


@dataclass(frozen=True, slots=True)
class CopilotToolsCaseResult:
    """One scored fixture run."""

    fixture_id: str
    rubrics: CopilotToolsRubrics
    halt_reason: str
    response_summary: dict[str, Any]
    is_regression: bool
    expected_failure: bool
    matches_expected: bool


@dataclass(frozen=True, slots=True)
class CopilotToolsSuiteReport:
    """Aggregate result for a single suite invocation."""

    cases: tuple[CopilotToolsCaseResult, ...]
    regressions: tuple[CopilotToolsCaseResult, ...] = field(default_factory=tuple)

    def as_dict(self) -> dict[str, Any]:
        """Return a JSON-serialisable summary."""
        return {
            "total_cases": len(self.cases),
            "total_regressions": len(self.regressions),
            "cases": [_case_as_dict(c) for c in self.cases],
            "regressions": [_case_as_dict(c) for c in self.regressions],
        }

    def all_passed(self) -> bool:
        """True if every case (and every regression) hit its expectations."""
        return all(c.matches_expected for c in self.cases) and all(
            c.matches_expected for c in self.regressions
        )


def _case_as_dict(case: CopilotToolsCaseResult) -> dict[str, Any]:
    return {
        "fixture_id": case.fixture_id,
        "rubrics": case.rubrics.as_dict(),
        "halt_reason": case.halt_reason,
        "response_summary": case.response_summary,
        "is_regression": case.is_regression,
        "expected_failure": case.expected_failure,
        "matches_expected": case.matches_expected,
    }


# ---------------------------------------------------------------------------
# Fixture loading
# ---------------------------------------------------------------------------


def load_fixtures(directory: Path) -> list[CopilotToolsFixture]:
    """Load every ``*.json`` fixture in *directory* sorted by file name."""
    if not directory.is_dir():
        return []
    out: list[CopilotToolsFixture] = []
    for path in sorted(directory.glob("*.json")):
        with path.open("r", encoding="utf-8") as fh:
            data = json.load(fh)
        out.append(_fixture_from_dict(data, source_path=path))
    return out


def _fixture_from_dict(
    data: Mapping[str, Any],
    *,
    source_path: Path,
) -> CopilotToolsFixture:
    return CopilotToolsFixture(
        fixture_id=str(data["fixture_id"]),
        intent_id=str(data["intent_id"]),
        user_goal=str(data.get("user_goal", "")),
        scripted_turns=tuple(data.get("scripted_turns", ())),
        tool_payloads={
            str(k): dict(v) for k, v in data.get("tool_payloads", {}).items()
        },
        expected=dict(data.get("expected", {})),
        is_regression=bool(data.get("regression", False)),
        source_path=source_path,
    )


# ---------------------------------------------------------------------------
# Loop wiring helpers
# ---------------------------------------------------------------------------


def _empty_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {}, "additionalProperties": False}


def _make_executor(
    payload: Mapping[str, Any],
) -> Any:
    """Wrap a static payload into a tool executor callable."""

    def _exec(_context: CopilotRunContext, _runtime_args: Mapping[str, Any]) -> Any:
        # Deep-copy so concurrent calls do not share mutable list refs.
        return json.loads(json.dumps(payload))

    return _exec


def _build_registry_for_fixture(
    *,
    intent: IntentDefinition,
    fixture: CopilotToolsFixture,
) -> ToolRegistry:
    """Build a per-fixture :class:`ToolRegistry` from intent + payloads.

    Every tool the intent advertises is materialised so the M13 loop can
    schedule it.  The fixture's ``tool_payloads`` map keys onto tool
    names; tools not in the map get a no-op executor that returns an
    empty record bag (those tools are still "callable" by a misbehaving
    model, which is exactly what ``tool_allowed`` should detect).
    """
    registry = ToolRegistry()
    payloads = dict(fixture.tool_payloads)
    # Seed every intent-allowed tool plus any tool the fixture lists --
    # the latter covers regression cases that script the model to call
    # an out-of-intent tool.
    extra_names = set(payloads) - set(intent.allowed_tools)
    seeded: set[str] = set()
    for tool_name in (*intent.allowed_tools, *sorted(extra_names)):
        if tool_name in seeded:
            continue
        seeded.add(tool_name)
        payload = payloads.get(tool_name, {"records": [], "citations": []})
        tool = ToolDefinition(
            name=tool_name,
            description=f"Eval stub tool for {tool_name}.",
            input_schema=_empty_schema(),
            required_capability="read_basic_patient_data",
            source_types=intent.allowed_source_types or ("patient_record",),
            read_only=True,
            max_rows=intent.max_rows,
            executor=_make_executor(payload),
        )
        registry.register(tool)
    return registry


def _make_run_context(
    *,
    intent: IntentDefinition,
    extra_allowed_tools: Sequence[str] = (),
) -> CopilotRunContext:
    """Build a verified :class:`CopilotRunContext` for a fixture run.

    Tests can widen ``allowed_tools`` past the intent's set so the
    executor is not the layer that rejects an out-of-intent call -- the
    rubric is meant to catch the model picking such a tool, even when
    the run context happens to whitelist it (defence in depth).
    """
    allowed_tools = list(set(list(intent.allowed_tools) + list(extra_allowed_tools)))
    # ``CopilotRunContext`` requires ``lookback_days > 0`` and
    # ``max_rows > 0``.  A handful of intents (basic_patient_data,
    # show_source) carry ``lookback_days=0`` to mean "point-in-time
    # snapshot"; for eval-side context construction we substitute the
    # smallest valid window so the wire object validates.  The value is
    # never observed by tools because the eval registry returns canned
    # payloads.
    safe_lookback = intent.lookback_days if intent.lookback_days > 0 else 1
    safe_max_rows = intent.max_rows if intent.max_rows > 0 else 1
    return CopilotRunContext.model_validate(
        {
            "user_id": 1,
            "username": "eval-runner",
            "patient_id": 1,
            "encounter_id": 1,
            "allowed_tools": allowed_tools,
            "allowed_source_types": list(intent.allowed_source_types) or ["patient_record"],
            "max_rows": safe_max_rows,
            "lookback_days": safe_lookback,
            "expires_at": _TOKEN_EXPIRES_AT,
            "request_id": f"req-eval-{intent.intent_id}",
            "trace_id": f"trace-eval-{intent.intent_id}",
            "key_version": "v1",
        },
    )


def _make_request(intent: IntentDefinition, user_goal: str) -> CopilotRunRequest:
    return CopilotRunRequest.model_validate(
        {
            "run_context": "signed.token.opaque",
            "intent_id": intent.intent_id,
            "user_goal": user_goal or None,
            "request_id": "11111111-2222-4333-8444-555555555555",
            "conversation_state": None,
        },
    )


def _build_final_response(
    *,
    payload: Mapping[str, Any],
    intent: IntentDefinition,
) -> CopilotRunResponse:
    """Shape a fixture's ``final_response`` block into a wire response."""
    claims_payload = list(payload.get("claims", []))
    citation_ids = list(payload.get("citation_ids", []))
    certainty = str(payload.get("certainty", "high"))
    verification_status = str(payload.get("verification_status", "passed"))
    answer_heading = str(payload.get("answer_block_heading", intent.label))

    claims = [
        Claim(
            text=str(c["text"]),
            citation_ids=list(c.get("citation_ids", [])),
            certainty=str(c.get("certainty", "high")),
        )
        for c in claims_payload
    ]
    block = AnswerBlock(heading=answer_heading, claims=claims)

    citations = [
        Citation(
            source_type=str(s["source_type"]),
            source_id=str(s["source_id"]),
            label=str(s["label"]),
            url=s.get("url"),
            snippet=s.get("snippet"),
        )
        for s in payload.get("citations", [])
    ]

    missing = [
        MissingOrUncertain(
            text=str(m["text"]),
            citation_ids=list(m.get("citation_ids", [])),
        )
        for m in payload.get("missing_or_uncertain", [])
    ]

    return CopilotRunResponse(
        answer_blocks=[block],
        claims=claims,
        citation_ids=citation_ids,
        certainty=certainty,
        missing_or_uncertain=missing,
        citations=citations,
        tool_sequence=[],
        verification_status=verification_status,
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id=f"trace-eval-{intent.intent_id}",
    )


def _build_scripted_turns(
    *,
    fixture: CopilotToolsFixture,
    intent: IntentDefinition,
) -> tuple[ScriptedTurn, ...]:
    out: list[ScriptedTurn] = []
    for entry in fixture.scripted_turns:
        if "tool_calls" in entry:
            tool_calls = tuple(
                LLMToolCallChoice(
                    call_id=str(tc["call_id"]),
                    tool_name=str(tc["tool_name"]),
                    arguments=dict(tc.get("arguments", {})),
                )
                for tc in entry["tool_calls"]
            )
            out.append(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(tool_calls=tool_calls),
                ),
            )
            continue
        if "final_response" in entry:
            response = _build_final_response(
                payload=entry["final_response"],
                intent=intent,
            )
            out.append(
                ScriptedTurn(
                    turn=LLMToolChoiceTurn(
                        final_message=LLMFinalMessage(parsed_response=response),
                    ),
                ),
            )
            continue
        raise ValueError(
            f"Unknown scripted turn shape in fixture {fixture.fixture_id!r}: "
            f"keys={list(entry)!r}",
        )
    return tuple(out)


# ---------------------------------------------------------------------------
# Per-fixture runner
# ---------------------------------------------------------------------------


def run_fixture(
    fixture: CopilotToolsFixture,
    *,
    intent_catalog: IntentCatalog | None = None,
) -> CopilotToolsCaseResult:
    """Drive the agent loop over one fixture and score the rubrics."""
    catalog = intent_catalog if intent_catalog is not None else default_catalog()
    try:
        intent = catalog.get(fixture.intent_id)
    except UnknownIntentError as exc:
        raise ValueError(
            f"Fixture {fixture.fixture_id!r} references unknown intent_id "
            f"{fixture.intent_id!r}",
        ) from exc

    registry = _build_registry_for_fixture(intent=intent, fixture=fixture)
    extra_tools: tuple[str, ...] = tuple(
        name
        for name in fixture.tool_payloads
        if name not in intent.allowed_tools
    )
    context = _make_run_context(
        intent=intent,
        extra_allowed_tools=extra_tools,
    )
    scripted = _build_scripted_turns(fixture=fixture, intent=intent)
    client = FakeLLMToolChoiceClient(script=scripted)
    recorder = RecordingEventRecorder()

    loop = AgentLoop(
        intent_catalog=catalog,
        registry_builder=lambda _ctx: registry,
        response_builder=ResponseBuilder(),
        verifier=AnswerVerifier(),
        llm_client=client,
        config=AgentLoopConfig(
            max_iterations=8,
            max_wall_time_s=30.0,
            max_tool_calls=12,
        ),
        event_recorder=recorder,
    )

    result = loop.run(
        request=_make_request(intent, fixture.user_goal),
        context=context,
    )

    rubrics = score_copilot_tools_rubrics(
        agent_loop_result=result,
        intent=intent,
        recorded_events=recorder.events,
    )

    expected = fixture.expected
    expected_failure = bool(expected.get("expected_failure", False))
    matches = _match_expected(
        result=result,
        rubrics=rubrics,
        expected=expected,
        expected_failure=expected_failure,
    )

    return CopilotToolsCaseResult(
        fixture_id=fixture.fixture_id,
        rubrics=rubrics,
        halt_reason=result.halt_reason,
        response_summary=_response_summary(result),
        is_regression=fixture.is_regression,
        expected_failure=expected_failure,
        matches_expected=matches,
    )


def _match_expected(
    *,
    result: AgentLoopResult,
    rubrics: CopilotToolsRubrics,
    expected: Mapping[str, Any],
    expected_failure: bool,
) -> bool:
    """Return True when the run's outcome lines up with the fixture's expectations."""
    expected_halt = expected.get("halt_reason")
    if isinstance(expected_halt, str) and result.halt_reason != expected_halt:
        return False

    expected_rubrics = expected.get("rubrics") or {}
    actual = rubrics.as_dict()
    for name, want in expected_rubrics.items():
        if name not in actual:
            return False
        if bool(want) is not bool(actual[name]):
            return False

    if expected_failure and rubrics.all_passed():
        # Regression fixture asked us to fail on purpose; passing every
        # rubric means the regression hook stopped working.
        return False

    return True


def _response_summary(result: AgentLoopResult) -> dict[str, Any]:
    """Return a small, PHI-safe summary used in suite reports."""
    response = result.response
    return {
        "verification_status": response.verification_status,
        "tool_calls": [
            _summarise_tool_call(record) for record in result.tool_sequence
        ],
        "claim_count": len(response.claims),
        "citation_count": len(response.citations),
        "missing_count": len(response.missing_or_uncertain),
        "halt_reason": result.halt_reason,
    }


def _summarise_tool_call(record: ToolCallRecord) -> dict[str, Any]:
    return {
        "tool_name": record.tool_name,
        "result_count": record.result_count,
        "error_class": record.error_class,
    }


# ---------------------------------------------------------------------------
# Suite runner
# ---------------------------------------------------------------------------


def run_copilot_tools_suite(
    *,
    fixtures_dirs: Sequence[Path] | None = None,
    regression_dirs: Sequence[Path] | None = None,
    intent_catalog: IntentCatalog | None = None,
) -> CopilotToolsSuiteReport:
    """Run the M22 ``copilot-tools`` eval suite end-to-end.

    The suite walks two fixture buckets:

    * ``fixtures_dirs`` -- positive cases.  Each fixture is expected to
      pass every rubric.
    * ``regression_dirs`` -- negative cases.  Each fixture explicitly
      fails at least one rubric; the report classifies them under
      ``regressions`` so a deliberate failure does not break the
      suite-level exit code.
    """
    catalog = intent_catalog if intent_catalog is not None else default_catalog()
    cases: list[CopilotToolsCaseResult] = []
    regressions: list[CopilotToolsCaseResult] = []

    primary_dirs = (
        list(fixtures_dirs)
        if fixtures_dirs is not None
        else [DEFAULT_COPILOT_TOOLS_FIXTURES_DIR]
    )
    regression_paths = (
        list(regression_dirs)
        if regression_dirs is not None
        else [DEFAULT_REGRESSION_FIXTURES_DIR]
    )

    for directory in primary_dirs:
        for fixture in load_fixtures(directory):
            target = regressions if fixture.is_regression else cases
            target.append(run_fixture(fixture, intent_catalog=catalog))

    for directory in regression_paths:
        for fixture in load_fixtures(directory):
            # Anything in the regression-only dir is treated as a regression
            # case even when the fixture forgot to declare it.  This keeps
            # the bucket semantics directory-driven so the CLI can split
            # buckets without inspecting per-file flags.
            forced = (
                fixture
                if fixture.is_regression
                else CopilotToolsFixture(
                    fixture_id=fixture.fixture_id,
                    intent_id=fixture.intent_id,
                    user_goal=fixture.user_goal,
                    scripted_turns=fixture.scripted_turns,
                    tool_payloads=fixture.tool_payloads,
                    expected=fixture.expected,
                    is_regression=True,
                    source_path=fixture.source_path,
                )
            )
            regressions.append(run_fixture(forced, intent_catalog=catalog))

    return CopilotToolsSuiteReport(
        cases=tuple(cases),
        regressions=tuple(regressions),
    )
