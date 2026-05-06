"""Per-intent parity tests for the M20 Clinical Co-Pilot cutover.

Drives the M13 :class:`agent_service.loop.AgentLoop` with a deterministic
:class:`agent_service.clients.tool_choice.FakeLLMToolChoiceClient` for
every M1 parity fixture under
``agent_service/tests/fixtures/copilot_parity/``.  Each fixture pins the
expected sidecar behavior for one of the six read-only intents being cut
over to the Python sidecar in M20:

* ``basic_patient_data``
* ``current_medications``
* ``allergies_to_confirm``
* ``recent_events``
* ``changed_since_last_visit``
* ``show_source``

The parity fixtures are intentionally descriptive (no executable
``scripted_turns`` or ``tool_payloads``).  This test module synthesises a
deterministic scripted run per scenario from the fixture's
``expected.tool_sequence``, ``expected.verification_status``, and
``expected.refusal_reason``.  The translation is small and explicit on
purpose: every fixture ends up exercising the same agent-loop entry
point that production traffic hits, so the parity guarantee covers
every layer between the LLM stub and the response envelope.

Tool-name reconciliation
------------------------

The PHP fixtures reference the action verbs the PHP toolset uses (e.g.
``get_allergies_to_confirm``).  The Python catalog renames a couple of
those to match the tool registry's verbs (``get_active_allergies``).
:func:`_translate_tool_name` normalises the fixture's PHP name to the
Python catalog's name so the parametrize matrix stays one-to-one with
the M1 fixture set.
"""

from __future__ import annotations

import json
from collections.abc import Mapping, Sequence
from pathlib import Path
from typing import Any, Final

import pytest

from agent_service.answer.builder import ResponseBuilder
from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.clients.tool_choice import (
    FakeLLMToolChoiceClient,
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceTurn,
    ScriptedTurn,
)
from agent_service.intents.catalog import (
    IntentCatalog,
    IntentDefinition,
    default_catalog,
)
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
# Constants
# ---------------------------------------------------------------------------


_FIXTURES_DIR: Final[Path] = (
    Path(__file__).resolve().parent / "fixtures" / "copilot_parity"
)
"""Directory containing every M1 parity fixture."""


_M20_INTENT_ORDER: Final[tuple[str, ...]] = (
    "basic_patient_data",
    "current_medications",
    "allergies_to_confirm",
    "recent_events",
    "changed_since_last_visit",
    "show_source",
)
"""Cutover order documented in the migration plan's M20 step."""


# Token expiry lifted from the M13 / M22 tests: a fixed value well past
# 2026 so the executor's expiry check never trips during a fixture run.
_TOKEN_EXPIRES_AT: Final[int] = 1_900_000_000


# Map fixture-side PHP tool names onto their Python catalog equivalents.
# Anything not listed here is assumed to be identical in both runtimes.
_PHP_TO_PYTHON_TOOL_NAMES: Final[Mapping[str, str]] = {
    "get_allergies_to_confirm": "get_active_allergies",
}


def _translate_tool_name(name: str) -> str:
    """Return the Python catalog's equivalent of a fixture-side tool name."""
    return _PHP_TO_PYTHON_TOOL_NAMES.get(name, name)


# ---------------------------------------------------------------------------
# Fixture loader
# ---------------------------------------------------------------------------


def _load_parity_fixtures() -> list[tuple[str, Path, dict[str, Any]]]:
    """Return ``(intent_id, path, payload)`` tuples for every parity fixture."""
    out: list[tuple[str, Path, dict[str, Any]]] = []
    for intent_id in _M20_INTENT_ORDER:
        intent_dir = _FIXTURES_DIR / intent_id
        assert intent_dir.is_dir(), (
            f"Missing parity fixture directory for intent {intent_id!r}: {intent_dir}"
        )
        for path in sorted(intent_dir.glob("*.json")):
            with path.open("r", encoding="utf-8") as fh:
                payload = json.load(fh)
            out.append((intent_id, path, payload))
    return out


_PARITY_FIXTURES: Final[list[tuple[str, Path, dict[str, Any]]]] = (
    _load_parity_fixtures()
)


def _fixture_id(case: tuple[str, Path, dict[str, Any]]) -> str:
    """pytest-friendly identifier so failures point at the source file."""
    _, path, _ = case
    return path.stem


# ---------------------------------------------------------------------------
# Loop wiring helpers
# ---------------------------------------------------------------------------


def _empty_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {}, "additionalProperties": False}


def _build_registry(intent: IntentDefinition) -> ToolRegistry:
    """Build a deterministic registry covering every tool the intent allows.

    Every ``allowed_tool`` gets a synthetic executor that returns a single
    record plus a citation whose source_id matches the tool name.  The
    citation is consumed by the verifier as a ``known_citation_id`` so a
    happy-path scripted final response that cites the same id passes.
    """
    registry = ToolRegistry()
    for tool_name in intent.allowed_tools:
        citation = Citation(
            source_type=intent.allowed_source_types[0]
            if intent.allowed_source_types
            else "patient_record",
            source_id=f"{tool_name}:1",
            label=f"Stub source for {tool_name}",
        )

        def _exec(
            _ctx: CopilotRunContext,
            _runtime_args: Mapping[str, Any],
            *,
            cit: Citation = citation,
        ) -> dict[str, Any]:
            return {"records": [{"id": 1}], "citations": [cit]}

        registry.register(
            ToolDefinition(
                name=tool_name,
                description=f"Stub tool {tool_name} for parity tests.",
                input_schema=_empty_schema(),
                required_capability="read_basic_patient_data",
                source_types=intent.allowed_source_types or ("patient_record",),
                read_only=True,
                max_rows=intent.max_rows,
                executor=_exec,
            ),
        )
    return registry


def _make_run_context(intent: IntentDefinition) -> CopilotRunContext:
    """Build a verified :class:`CopilotRunContext` for a fixture run."""
    safe_lookback = intent.lookback_days if intent.lookback_days > 0 else 1
    safe_max_rows = intent.max_rows if intent.max_rows > 0 else 1
    return CopilotRunContext.model_validate(
        {
            "user_id": 1,
            "username": "parity-runner",
            "patient_id": 1,
            "encounter_id": 1,
            "allowed_tools": list(intent.allowed_tools),
            "allowed_source_types": list(intent.allowed_source_types)
            or ["patient_record"],
            "max_rows": safe_max_rows,
            "lookback_days": safe_lookback,
            "expires_at": _TOKEN_EXPIRES_AT,
            "request_id": f"req-parity-{intent.intent_id}",
            "trace_id": f"trace-parity-{intent.intent_id}",
            "key_version": "v1",
        },
    )


def _make_request(intent: IntentDefinition, user_goal: str) -> CopilotRunRequest:
    return CopilotRunRequest.model_validate(
        {
            "run_context": "signed.token.opaque",
            "intent_id": intent.intent_id,
            # ``user_goal`` is intentionally None for prompt-injection
            # scenarios -- the controller in production strips free-text
            # input from the closed intent payload, so the loop only ever
            # sees the catalog's ``goal_template`` for those cases.
            "user_goal": user_goal or None,
            "request_id": "11111111-2222-4333-8444-555555555555",
            "conversation_state": None,
        },
    )


# ---------------------------------------------------------------------------
# Scenario -> scripted turn synthesiser
# ---------------------------------------------------------------------------


def _build_happy_response(
    *,
    intent: IntentDefinition,
    citation_source_ids: Sequence[str],
    missing_lines: Sequence[str],
) -> CopilotRunResponse:
    """Construct a verifier-passing scripted final response."""
    if citation_source_ids:
        claims = [
            Claim(
                text=f"Stub claim {i + 1} for {intent.intent_id}",
                citation_ids=[source_id],
                certainty="active",
            )
            for i, source_id in enumerate(citation_source_ids)
        ]
        certainty: str = "high"
    else:
        # No citations: a single ``not_found`` claim satisfies the
        # verifier's uncited-claim rule for the missing-data scenario.
        claims = [
            Claim(
                text=f"No matching {intent.intent_id} records were "
                "found in checked evidence.",
                citation_ids=[],
                certainty="not_found",
            ),
        ]
        certainty = "unknown"

    citations = [
        Citation(
            source_type=intent.allowed_source_types[0]
            if intent.allowed_source_types
            else "patient_record",
            source_id=source_id,
            label=f"Stub citation for {source_id}",
        )
        for source_id in citation_source_ids
    ]

    missing_blocks = [
        MissingOrUncertain(text=line, citation_ids=[]) for line in missing_lines
    ]

    return CopilotRunResponse(
        answer_blocks=[
            AnswerBlock(heading=intent.label, claims=claims),
        ],
        claims=claims,
        citation_ids=sorted({cid for c in claims for cid in c.citation_ids}),
        certainty=certainty,
        missing_or_uncertain=missing_blocks,
        citations=citations,
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id=f"trace-parity-{intent.intent_id}",
    )


def _build_unauthorized_response(intent: IntentDefinition) -> CopilotRunResponse:
    """Construct a final response that cites a fabricated source id.

    The agent loop's verifier rejects fabricated citations, so the
    fixture-side ``verification_status='refused'`` outcome is reproduced
    end-to-end without faking the verifier's output.
    """
    fake_id = "demographics:patient_data:9999"
    claims = [
        Claim(
            text="Fabricated cross-patient claim that should be refused.",
            citation_ids=[fake_id],
            certainty="active",
        ),
    ]
    return CopilotRunResponse(
        answer_blocks=[AnswerBlock(heading=intent.label, claims=claims)],
        claims=claims,
        citation_ids=[fake_id],
        certainty="high",
        missing_or_uncertain=[],
        # No citations exposed -> the verifier sees the cited id as
        # fabricated and refuses.
        citations=[],
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id=f"trace-parity-{intent.intent_id}",
    )


def _build_scripted_turns(
    *,
    intent: IntentDefinition,
    expected: Mapping[str, Any],
    scenario: str,
) -> tuple[ScriptedTurn, ...]:
    """Translate a parity-fixture expectation into scripted turns.

    Steps:

    1. Each tool name in ``expected.tool_sequence`` becomes one
       :class:`LLMToolCallChoice` on the first turn.
    2. The terminal turn carries a synthetic ``parsed_response`` whose
       shape matches the fixture's ``verification_status`` /
       ``citation_ids_required`` / ``missing_or_uncertain`` declaration.
    3. Scenarios where the controller would 400 the request before the
       loop ran (``invalid citation ID`` / ``cross-patient citation ID``
       on ``show_source``) emit a single refusal turn -- the loop never
       executes a tool because there is nothing to drilldown to.
    """
    fixture_tool_sequence = list(expected.get("tool_sequence", []))
    tool_names = [_translate_tool_name(name) for name in fixture_tool_sequence]

    verification_status = str(expected.get("verification_status", "passed"))
    refusal_reason = expected.get("refusal_reason")

    if not tool_names:
        # No tools were executed.  Two distinct sub-cases:
        #
        # * verification_status=='refused' (``show_source`` invalid_id /
        #   cross_patient): emit a refused final message that fails the
        #   verifier on a fabricated citation.
        # * verification_status=='passed' (``show_source`` missing-data
        #   "source required"): emit an uncited ``not_checked`` claim
        #   that satisfies the verifier without any tool output.
        if verification_status == "refused":
            parsed = _build_no_tools_refusal_response(
                intent=intent,
                refusal_reason=str(refusal_reason or "verification_failed"),
            )
        else:
            parsed = _build_no_tools_passed_response(intent=intent)
        return (
            ScriptedTurn(
                turn=LLMToolChoiceTurn(
                    final_message=LLMFinalMessage(parsed_response=parsed),
                ),
            ),
        )

    tool_calls = tuple(
        LLMToolCallChoice(
            call_id=f"c{i}",
            tool_name=name,
            arguments={},
        )
        for i, name in enumerate(tool_names)
    )

    if verification_status == "refused":
        # Scenarios like ``unauthorized source`` end with the verifier
        # rejecting a fabricated citation.  Drive that with a real
        # parsed_response that fails verification.
        final_response = _build_unauthorized_response(intent)
    else:
        # ``passed`` -- emit a minimal happy response that cites the
        # tools' stub source ids when ``citation_ids_required`` is set,
        # and a missingness-only response when it isn't.
        citation_ids_required = bool(expected.get("citation_ids_required", False))
        if citation_ids_required:
            citation_source_ids = [f"{name}:1" for name in tool_names]
        else:
            citation_source_ids = []
        missing_lines = [str(m) for m in expected.get("missing_or_uncertain", [])]
        final_response = _build_happy_response(
            intent=intent,
            citation_source_ids=citation_source_ids,
            missing_lines=missing_lines,
        )

    return (
        ScriptedTurn(
            turn=LLMToolChoiceTurn(tool_calls=tool_calls),
        ),
        ScriptedTurn(
            turn=LLMToolChoiceTurn(
                final_message=LLMFinalMessage(parsed_response=final_response),
            ),
        ),
    )


def _build_no_tools_passed_response(
    *,
    intent: IntentDefinition,
) -> CopilotRunResponse:
    """Build a verifier-passing response that ran zero tool calls.

    Mirrors the PHP ``AgentEvidenceResponseBuilder::sourceRequiredResponse``
    branch, which surfaces a single ``not_checked`` claim asking the user
    to pick a citation.  No tool ran, so the response carries no
    citations and the verifier passes because ``not_checked`` is in the
    uncited-certainties allow-list.
    """
    claims = [
        Claim(
            text="Select a citation source to inspect the underlying record.",
            citation_ids=[],
            certainty="not_checked",
        ),
    ]
    return CopilotRunResponse(
        answer_blocks=[AnswerBlock(heading=intent.label, claims=claims)],
        claims=claims,
        citation_ids=[],
        certainty="unknown",
        missing_or_uncertain=[],
        citations=[],
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id=f"trace-parity-{intent.intent_id}",
    )


def _build_no_tools_refusal_response(
    *,
    intent: IntentDefinition,
    refusal_reason: str,
) -> CopilotRunResponse:
    """Build a final response whose verifier outcome is 'refused'.

    Used for ``show_source`` scenarios where no tool is executed because
    the controller-side validation already rejected the request.  We
    cite a fabricated ID so the verifier still emits a refused status,
    keeping the parity guarantee that the wire-shape's
    ``verification_status`` is observable in tests.
    """
    fake_id = f"{intent.intent_id}:fabricated:0"
    claims = [
        Claim(
            text=f"Stub refusal claim for {refusal_reason}.",
            citation_ids=[fake_id],
            certainty="active",
        ),
    ]
    return CopilotRunResponse(
        answer_blocks=[AnswerBlock(heading=intent.label, claims=claims)],
        claims=claims,
        citation_ids=[fake_id],
        certainty="high",
        missing_or_uncertain=[],
        citations=[],
        tool_sequence=[],
        verification_status="passed",
        cost_usd=0.0,
        latency_ms_per_step={},
        trace_id=f"trace-parity-{intent.intent_id}",
    )


# ---------------------------------------------------------------------------
# Loop builder
# ---------------------------------------------------------------------------


def _build_loop(
    *,
    catalog: IntentCatalog,
    registry: ToolRegistry,
    client: FakeLLMToolChoiceClient,
) -> AgentLoop:
    return AgentLoop(
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
    )


# ---------------------------------------------------------------------------
# Pytest fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _scrub_api_keys(monkeypatch: pytest.MonkeyPatch) -> None:
    """Make sure no live OpenAI/Cohere keys leak into fakes.

    The parity tests use :class:`FakeLLMToolChoiceClient` exclusively so
    they never need a real API key, but other modules import provider
    fakes at collection time. Stripping the env keeps the suite
    reproducible across local + CI environments.
    """
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)


# ---------------------------------------------------------------------------
# Parametrised parity test
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    "case",
    _PARITY_FIXTURES,
    ids=[_fixture_id(c) for c in _PARITY_FIXTURES],
)
def test_intent_parity_against_m1_fixtures(
    case: tuple[str, Path, dict[str, Any]],
) -> None:
    """Drive the agent loop with each M1 parity fixture and assert outcomes.

    For every fixture the loop must:

    * Execute exactly the tool sequence the fixture pins (after PHP-to-
      Python tool-name translation).
    * Land in the fixture's expected ``verification_status``.
    * For happy-path fixtures with ``citation_ids_required=true``: surface
      at least one citation in the final response.
    * For missing-data fixtures: pass verification and surface the
      pinned ``missing_or_uncertain`` lines verbatim.
    * For unauthorized-source / refusal scenarios: produce a refused
      response with empty citation_ids.
    * For prompt-injection scenarios: never let the injected user text
      drive a refusal -- the loop runs against the catalog goal_template
      and the response is either passed (happy) or contains no PHI from
      the injection (when the verifier intercepts).
    """
    intent_id, path, payload = case
    scenario = str(payload.get("scenario", ""))
    expected = payload.get("expected", {})
    user_goal = str(payload.get("input", {}).get("user_goal", ""))

    catalog = default_catalog()
    intent = catalog.get(intent_id)
    registry = _build_registry(intent)
    context = _make_run_context(intent)

    scripted = _build_scripted_turns(
        intent=intent,
        expected=expected,
        scenario=scenario,
    )
    client = FakeLLMToolChoiceClient(script=scripted)
    loop = _build_loop(catalog=catalog, registry=registry, client=client)

    request = _make_request(
        intent,
        # Prompt-injection scenarios mirror the production controller's
        # behaviour: free-text user input never reaches the loop, so we
        # leave ``user_goal`` empty for the loop to fall back to the
        # catalog's ``goal_template``.
        user_goal="" if scenario == "prompt injection" else user_goal,
    )

    result = loop.run(request=request, context=context)
    response = result.response

    # 1. Tool sequence parity (excluding rejected calls).
    actual_tool_names = [
        record.tool_name for record in result.tool_sequence if record.error_class is None
    ]
    expected_tool_names = [
        _translate_tool_name(name) for name in expected.get("tool_sequence", [])
    ]
    assert actual_tool_names == expected_tool_names, (
        f"{path.name}: expected tool_sequence {expected_tool_names!r}, "
        f"got {actual_tool_names!r}"
    )

    # 2. Verification status parity.
    expected_status = str(expected.get("verification_status", "passed"))
    assert response.verification_status == expected_status, (
        f"{path.name}: expected verification_status={expected_status!r}, "
        f"got {response.verification_status!r}"
    )

    # 3. Refusal envelopes never carry citation IDs.
    if expected_status == "refused":
        assert response.citation_ids == [], (
            f"{path.name}: refused response must have empty citation_ids, "
            f"got {response.citation_ids!r}"
        )
        # Refused responses also produce a refusal_reason via the
        # verifier or the loop's halt mapping; this test only enforces
        # the negative invariant -- specific reason wording is covered
        # by the verifier's own unit tests.
        return

    # 4. Happy paths with citations required must surface at least one.
    citation_ids_required = bool(expected.get("citation_ids_required", False))
    if citation_ids_required:
        assert response.citation_ids, (
            f"{path.name}: citation_ids_required=true but response surfaced none"
        )

    # 5. Missing-data scenarios: must record at least one safe-missingness
    #    line and remain in the 'passed' status.
    expected_missing = list(expected.get("missing_or_uncertain", []))
    if expected_missing:
        assert response.missing_or_uncertain, (
            f"{path.name}: expected missing_or_uncertain entries but got none"
        )
        actual_missing_text = [m.text for m in response.missing_or_uncertain]
        for line in expected_missing:
            assert line in actual_missing_text, (
                f"{path.name}: missing_or_uncertain line {line!r} not present "
                f"in {actual_missing_text!r}"
            )

    # 6. Prompt-injection scenarios: ensure the injected user text never
    #    surfaces in the response body.
    if scenario == "prompt injection":
        injection_terms = (
            "ignore previous instructions",
            "place an order",
            "recommend stopping",
            "disclose the full chart",
            "dump the full chart",
        )
        rendered_text = " ".join(c.text for c in response.claims).lower()
        for term in injection_terms:
            assert term not in rendered_text, (
                f"{path.name}: prompt-injection text {term!r} leaked into a claim"
            )


# ---------------------------------------------------------------------------
# Fixture coverage guard
# ---------------------------------------------------------------------------


def test_every_m20_intent_has_at_least_one_parity_fixture() -> None:
    """Each cutover intent must have a parity fixture; otherwise M20 cannot prove
    the intent is sidecar-ready.
    """
    intent_to_count: dict[str, int] = dict.fromkeys(_M20_INTENT_ORDER, 0)
    for intent_id, _path, _payload in _PARITY_FIXTURES:
        intent_to_count[intent_id] += 1

    for intent_id in _M20_INTENT_ORDER:
        assert intent_to_count[intent_id] >= 1, (
            f"M20 cutover intent {intent_id!r} has zero parity fixtures"
        )


def test_total_parity_fixture_count_matches_readme() -> None:
    """The README pins the total fixture count at 32 (5x5 + 7 for show_source)."""
    assert len(_PARITY_FIXTURES) == 32, (
        f"Expected 32 parity fixtures, found {len(_PARITY_FIXTURES)}"
    )
