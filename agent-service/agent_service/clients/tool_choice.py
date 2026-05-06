"""LLM tool-choice client protocol and deterministic fake (M13).

The agent loop introduced in M13 needs a narrower client surface than the
generic :class:`agent_service.clients.openai_client.LLMClient` provides
-- specifically a single ``tool_call_completion`` entry point that takes
the running message history plus the model-facing tool schemas and
returns either:

* ``LLMToolCallChoice`` instances (the model wants to invoke tools), or
* ``LLMFinalMessage`` (the model produced a final assistant response).

Two implementations are shipped:

* :class:`FakeLLMToolChoiceClient` -- replays a scripted sequence of
  responses keyed by call index. Used by tests to make the loop fully
  deterministic without standing up the real OpenAI SDK.
* No real OpenAI implementation lives in M13. The chart copilot loop is
  shipped behind the M4 fail-closed dependency, so the production wiring
  can stay swap-in for M16+ without affecting M13's tests.

Design notes
------------
* Both result types are frozen value-objects (``slots=True``) so they
  cannot be mutated by mistake while travelling through the loop.
* ``LLMFinalMessage.content`` carries the raw assistant text that the
  loop parses into ``Claim`` / ``AnswerBlock`` objects -- usually a JSON
  blob produced via structured output / function calling. The fake
  bypasses parsing by exposing a pre-built ``CopilotRunResponse`` via
  ``LLMFinalMessage.parsed_response``; that field is the canonical
  wire-shape the loop hands to the verifier.
* Tool calls carry an opaque ``call_id`` so the loop can correlate the
  model's request with the executor's outcome when synthesising the
  ``tool_sequence`` and the next-turn ``tool`` message.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any, Protocol, runtime_checkable

from agent_service.schemas.copilot import CopilotRunResponse


__all__ = [
    "FakeLLMToolChoiceClient",
    "LLMFinalMessage",
    "LLMToolCallChoice",
    "LLMToolChoiceClient",
    "LLMToolChoiceTurn",
    "ScriptedTurn",
]


# ---------------------------------------------------------------------------
# Result value objects
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class LLMToolCallChoice:
    """A single tool invocation requested by the model on a turn.

    Attributes
    ----------
    call_id
        Opaque, model-supplied correlation token. Tests can hard-code
        these to anchor on a specific request when debugging.
    tool_name
        Registered tool name the model wants to call.
    arguments
        Mapping of model-supplied arguments. Forbidden authority fields
        are detected and rejected by the executor (M6) -- the loop just
        forwards them.
    """

    call_id: str
    tool_name: str
    arguments: Mapping[str, Any]


@dataclass(frozen=True, slots=True)
class LLMFinalMessage:
    """A terminal assistant message produced by the model.

    Attributes
    ----------
    content
        Free-form assistant content. The loop tolerates the field being
        empty when ``parsed_response`` is supplied: in that case the
        builder/verifier work directly off the parsed shape.
    parsed_response
        Optional pre-built :class:`CopilotRunResponse`. The fake client
        sets this so tests can assert deterministic wire shapes without
        going through a JSON-string round trip.
    """

    content: str = ""
    parsed_response: CopilotRunResponse | None = None


@dataclass(frozen=True, slots=True)
class LLMToolChoiceTurn:
    """A single turn produced by the LLM client.

    Exactly one of ``tool_calls`` or ``final_message`` is populated.
    ``tool_calls`` may be the empty tuple together with a non-empty
    ``final_message`` to indicate "no further tool calls, here's the
    final answer".
    """

    tool_calls: tuple[LLMToolCallChoice, ...] = ()
    final_message: LLMFinalMessage | None = None

    def __post_init__(self) -> None:  # noqa: D401 - simple invariant
        if self.tool_calls and self.final_message is not None:
            raise ValueError(
                "LLMToolChoiceTurn must carry tool_calls XOR final_message, "
                "not both.",
            )
        if not self.tool_calls and self.final_message is None:
            raise ValueError(
                "LLMToolChoiceTurn must carry at least one tool call or a "
                "final_message; both empty is not a valid turn.",
            )


# ---------------------------------------------------------------------------
# Protocol
# ---------------------------------------------------------------------------


@runtime_checkable
class LLMToolChoiceClient(Protocol):
    """Boundary contract used by the M13 agent loop.

    The loop never depends on a concrete client; it accepts any object
    that implements :meth:`tool_call_completion`. Tests inject the
    deterministic fake below; production wiring (post-M13) injects an
    OpenAI / equivalent adapter.
    """

    def tool_call_completion(
        self,
        *,
        messages: Sequence[Mapping[str, Any]],
        tools: Sequence[Mapping[str, Any]],
    ) -> LLMToolChoiceTurn:
        """Return one turn given the running messages + tool schemas.

        ``messages`` is the running conversation -- system prompt, the
        user goal, and any tool result messages produced by the loop on
        previous iterations. ``tools`` is the model-facing schema list
        produced by :meth:`agent_service.tools.registry.ToolRegistry.model_facing_schemas`.
        """
        ...


# ---------------------------------------------------------------------------
# Fake implementation
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class ScriptedTurn:
    """A single scripted turn for :class:`FakeLLMToolChoiceClient`.

    ``raise_exc`` lets tests simulate a model-side failure on a specific
    iteration. When set, the corresponding call raises that exception
    instead of returning a turn.
    """

    turn: LLMToolChoiceTurn | None = None
    raise_exc: BaseException | None = None

    def __post_init__(self) -> None:
        if self.turn is None and self.raise_exc is None:
            raise ValueError(
                "ScriptedTurn requires either a turn or a raise_exc.",
            )
        if self.turn is not None and self.raise_exc is not None:
            raise ValueError(
                "ScriptedTurn cannot carry both a turn and a raise_exc.",
            )


@dataclass
class FakeLLMToolChoiceClient:
    """Deterministic fake replaying a scripted turn sequence.

    Each call to :meth:`tool_call_completion` consumes the next entry in
    ``script``. Calls that exceed the script length raise ``IndexError``
    so a misconfigured test fails loudly rather than silently looping.

    Attributes
    ----------
    script
        Ordered tuple of :class:`ScriptedTurn`. Built in test setup;
        consumed left-to-right by the loop.
    calls
        Mutated per invocation. Each entry stores the messages and tool
        schemas the loop passed in, plus the index, so tests can pin
        sequencing without exposing the loop's internals.
    """

    script: tuple[ScriptedTurn, ...] = ()
    calls: list[dict[str, Any]] = field(default_factory=list, init=False)

    def tool_call_completion(
        self,
        *,
        messages: Sequence[Mapping[str, Any]],
        tools: Sequence[Mapping[str, Any]],
    ) -> LLMToolChoiceTurn:
        index = len(self.calls)
        self.calls.append(
            {
                "index": index,
                "messages": tuple(dict(m) for m in messages),
                "tools": tuple(dict(t) for t in tools),
            },
        )
        if index >= len(self.script):
            raise IndexError(
                f"FakeLLMToolChoiceClient script exhausted at call {index}; "
                "configure additional ScriptedTurn entries.",
            )
        scripted = self.script[index]
        if scripted.raise_exc is not None:
            raise scripted.raise_exc
        # Invariant guaranteed by ``ScriptedTurn.__post_init__``.
        assert scripted.turn is not None  # noqa: S101 - dataclass invariant
        return scripted.turn
