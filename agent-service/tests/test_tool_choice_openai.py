"""Tests for the production OpenAI tool-choice client (M13 follow-up).

The agent loop's :class:`agent_service.clients.tool_choice.LLMToolChoiceClient`
contract is shipped behind a deterministic fake for the M13 unit tests.
This module verifies the *production* adapter that drives the OpenAI
Chat Completions ``tools`` API:

* Protocol conformance (the class structurally satisfies
  :class:`LLMToolChoiceClient`).
* Translation of ``tool_calls`` responses into
  :class:`LLMToolCallChoice` instances.
* Translation of plain ``content`` responses into
  :class:`LLMFinalMessage`.
* Defensive error handling: bad JSON in tool arguments raises
  :class:`ValueError`, and ``openai.APIError`` propagates untouched so
  the agent loop can attribute the failure to ``model_error``.
* Model-name overrides via the
  :data:`OPENEMR_COPILOT_OPENAI_MODEL` environment variable.
* The dependency provider returns a typed
  :class:`_MissingOpenAIKeyClient` when ``OPENAI_API_KEY`` is unset, and
  invoking it raises :class:`LLMNotConfiguredError`.

No real API calls are made -- the SDK's ``OpenAI`` class is stubbed via
``unittest.mock``.
"""

from __future__ import annotations

import json
import os
from types import SimpleNamespace
from typing import Any
from unittest.mock import MagicMock, patch

import openai
import pytest

from agent_service.api import copilot as copilot_api
from agent_service.clients.tool_choice import (
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceClient,
    LLMToolChoiceTurn,
)
from agent_service.clients.tool_choice_openai import (
    DEFAULT_OPENAI_MODEL,
    LLMNotConfiguredError,
    OPENAI_MODEL_ENV_VAR,
    OpenAIToolChoiceClient,
    _MissingOpenAIKeyClient,
)
from agent_service.config import get_settings


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------


_FAKE_API_KEY = "sk-test-not-real"


def _make_client(*, model: str | None = None) -> tuple[OpenAIToolChoiceClient, MagicMock]:
    """Construct a client wrapping a stubbed SDK instance.

    The mock SDK exposes the ``chat.completions.create`` method shape the
    adapter exercises; tests configure ``return_value`` per scenario.
    """
    sdk = MagicMock(spec=openai.OpenAI)
    sdk.chat = MagicMock()
    sdk.chat.completions = MagicMock()
    sdk.chat.completions.create = MagicMock()
    client = OpenAIToolChoiceClient(api_key=_FAKE_API_KEY, model=model, client=sdk)
    return client, sdk


def _tool_call_message(*, calls: list[dict[str, Any]]) -> Any:
    """Build a fake assistant message with ``tool_calls``.

    Uses :class:`SimpleNamespace` so attribute access mirrors what the
    OpenAI SDK returns (the SDK returns Pydantic-style objects, not
    dicts).
    """
    tool_calls = [
        SimpleNamespace(
            id=call["id"],
            type="function",
            function=SimpleNamespace(
                name=call["name"],
                arguments=call["arguments"],
            ),
        )
        for call in calls
    ]
    return SimpleNamespace(role="assistant", content=None, tool_calls=tool_calls)


def _content_message(*, content: str) -> Any:
    """Build a fake assistant message with text content only."""
    return SimpleNamespace(role="assistant", content=content, tool_calls=None)


def _completion(*, message: Any) -> Any:
    """Wrap an assistant message in a fake chat-completion response."""
    return SimpleNamespace(
        choices=[SimpleNamespace(index=0, finish_reason="stop", message=message)],
    )


# ===================================================================
# Protocol conformance
# ===================================================================


class TestProtocolConformance:
    """OpenAIToolChoiceClient satisfies the LLMToolChoiceClient protocol."""

    def test_instance_is_llm_tool_choice_client(self) -> None:
        client, _ = _make_client()
        assert isinstance(client, LLMToolChoiceClient)

    def test_class_satisfies_protocol_subclass_check(self) -> None:
        assert issubclass(OpenAIToolChoiceClient, LLMToolChoiceClient)

    def test_missing_key_client_satisfies_protocol(self) -> None:
        sentinel = _MissingOpenAIKeyClient()
        assert isinstance(sentinel, LLMToolChoiceClient)


# ===================================================================
# Construction guards
# ===================================================================


class TestConstruction:
    """Constructor enforces minimal invariants."""

    def test_empty_api_key_rejected(self) -> None:
        with pytest.raises(ValueError, match="non-empty OpenAI API key"):
            OpenAIToolChoiceClient(api_key="")

    def test_explicit_model_overrides_env(self) -> None:
        with patch.dict(os.environ, {OPENAI_MODEL_ENV_VAR: "ignored-from-env"}):
            client, _ = _make_client(model="gpt-4o")
            assert client.model == "gpt-4o"


# ===================================================================
# Tool-call translation
# ===================================================================


class TestToolCallTranslation:
    """Tool-call responses are translated into LLMToolCallChoice values."""

    def test_single_tool_call_with_arguments(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "call_abc",
                        "name": "get_current_medications",
                        "arguments": json.dumps({"limit": 10}),
                    },
                ],
            ),
        )

        turn = client.tool_call_completion(messages=[], tools=[])

        assert isinstance(turn, LLMToolChoiceTurn)
        assert turn.final_message is None
        assert len(turn.tool_calls) == 1
        only = turn.tool_calls[0]
        assert isinstance(only, LLMToolCallChoice)
        assert only.call_id == "call_abc"
        assert only.tool_name == "get_current_medications"
        assert only.arguments == {"limit": 10}

    def test_empty_arguments_string_yields_empty_dict(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "call_zero",
                        "name": "get_active_allergies",
                        "arguments": "",
                    },
                ],
            ),
        )

        turn = client.tool_call_completion(messages=[], tools=[])

        assert turn.tool_calls[0].arguments == {}

    def test_multiple_tool_calls_preserve_order(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "c0",
                        "name": "tool_one",
                        "arguments": json.dumps({"a": 1}),
                    },
                    {
                        "id": "c1",
                        "name": "tool_two",
                        "arguments": json.dumps({"b": 2}),
                    },
                ],
            ),
        )

        turn = client.tool_call_completion(messages=[], tools=[])

        assert [c.call_id for c in turn.tool_calls] == ["c0", "c1"]
        assert [c.tool_name for c in turn.tool_calls] == ["tool_one", "tool_two"]

    def test_tool_calls_win_over_content(self) -> None:
        """When both fields are populated the loop must run the tools first."""
        client, sdk = _make_client()
        message = _tool_call_message(
            calls=[
                {"id": "c0", "name": "tool_one", "arguments": json.dumps({})},
            ],
        )
        # The SDK could in theory put text on the same message; the
        # adapter must still surface the tool call.
        message.content = "ignored prose"
        sdk.chat.completions.create.return_value = _completion(message=message)

        turn = client.tool_call_completion(messages=[], tools=[])

        assert turn.tool_calls
        assert turn.final_message is None


# ===================================================================
# Final-message translation
# ===================================================================


class TestFinalMessageTranslation:
    """Content-only responses become LLMFinalMessage values."""

    def test_content_populates_final_message(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_content_message(content="The verified answer is X."),
        )

        turn = client.tool_call_completion(messages=[], tools=[])

        assert turn.tool_calls == ()
        assert turn.final_message is not None
        assert turn.final_message.content == "The verified answer is X."

    def test_null_content_becomes_empty_string(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=SimpleNamespace(content=None, tool_calls=None),
        )

        turn = client.tool_call_completion(messages=[], tools=[])

        assert turn.final_message is not None
        assert turn.final_message.content == ""


# ===================================================================
# Error handling
# ===================================================================


class TestErrorHandling:
    """Defensive error paths surface typed exceptions."""

    def test_bad_json_arguments_raises_value_error(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "c0",
                        "name": "tool_one",
                        "arguments": "{not valid json",
                    },
                ],
            ),
        )

        with pytest.raises(ValueError, match="not valid JSON"):
            client.tool_call_completion(messages=[], tools=[])

    def test_non_object_json_arguments_raises_value_error(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "c0",
                        "name": "tool_one",
                        "arguments": json.dumps([1, 2, 3]),
                    },
                ],
            ),
        )

        with pytest.raises(ValueError, match="JSON object"):
            client.tool_call_completion(messages=[], tools=[])

    def test_value_error_does_not_leak_payload(self) -> None:
        """Generic message must not embed user prompt content."""
        client, sdk = _make_client()
        sensitive_payload = '{"patient_name": "John Doe","mrn":"12345"'
        sdk.chat.completions.create.return_value = _completion(
            message=_tool_call_message(
                calls=[
                    {
                        "id": "c0",
                        "name": "tool_one",
                        "arguments": sensitive_payload,
                    },
                ],
            ),
        )

        with pytest.raises(ValueError) as excinfo:
            client.tool_call_completion(messages=[], tools=[])

        assert "patient_name" not in str(excinfo.value)
        assert "12345" not in str(excinfo.value)

    def test_api_error_propagates(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.side_effect = openai.APIError(
            message="upstream rate-limited",
            request=MagicMock(),
            body=None,
        )

        with pytest.raises(openai.APIError):
            client.tool_call_completion(messages=[], tools=[])

    def test_missing_function_name_raises_value_error(self) -> None:
        client, sdk = _make_client()
        bad_call = SimpleNamespace(
            id="c0",
            type="function",
            function=SimpleNamespace(name="", arguments="{}"),
        )
        sdk.chat.completions.create.return_value = _completion(
            message=SimpleNamespace(content=None, tool_calls=[bad_call]),
        )

        with pytest.raises(ValueError, match="function.name"):
            client.tool_call_completion(messages=[], tools=[])


# ===================================================================
# Model-name configuration
# ===================================================================


class TestModelNameConfiguration:
    """The model name comes from OPENEMR_COPILOT_OPENAI_MODEL when set."""

    @pytest.mark.parametrize(
        ("env_value", "expected"),
        [
            ("gpt-4o", "gpt-4o"),
            ("gpt-4-turbo", "gpt-4-turbo"),
            ("custom-model-id", "custom-model-id"),
        ],
    )
    def test_env_overrides_default(self, env_value: str, expected: str) -> None:
        with patch.dict(os.environ, {OPENAI_MODEL_ENV_VAR: env_value}):
            client, _ = _make_client()
            assert client.model == expected

    def test_default_when_env_unset(self) -> None:
        env = os.environ.copy()
        env.pop(OPENAI_MODEL_ENV_VAR, None)
        with patch.dict(os.environ, env, clear=True):
            client, _ = _make_client()
            assert client.model == DEFAULT_OPENAI_MODEL

    def test_blank_env_falls_back_to_default(self) -> None:
        with patch.dict(os.environ, {OPENAI_MODEL_ENV_VAR: "   "}):
            client, _ = _make_client()
            assert client.model == DEFAULT_OPENAI_MODEL

    def test_model_name_is_passed_to_sdk(self) -> None:
        client, sdk = _make_client(model="gpt-4o")
        sdk.chat.completions.create.return_value = _completion(
            message=_content_message(content="ok"),
        )

        client.tool_call_completion(messages=[{"role": "user", "content": "hi"}], tools=[])

        kwargs = sdk.chat.completions.create.call_args.kwargs
        assert kwargs["model"] == "gpt-4o"


# ===================================================================
# SDK request construction
# ===================================================================


class TestSdkRequestConstruction:
    """The adapter forwards messages and tools to the SDK correctly."""

    def test_tools_omitted_when_empty(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_content_message(content="ok"),
        )

        client.tool_call_completion(messages=[{"role": "user", "content": "hi"}], tools=[])

        kwargs = sdk.chat.completions.create.call_args.kwargs
        assert "tools" not in kwargs
        assert kwargs["messages"] == [{"role": "user", "content": "hi"}]

    def test_tools_forwarded_when_provided(self) -> None:
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_content_message(content="ok"),
        )
        tools = [{"type": "function", "function": {"name": "tool_one"}}]

        client.tool_call_completion(messages=[], tools=tools)

        kwargs = sdk.chat.completions.create.call_args.kwargs
        assert kwargs["tools"] == tools

    def test_registry_shape_tools_translated_to_openai_function_shape(self) -> None:
        """Tools coming from the agent-service registry use Anthropic-style
        ``{name, description, input_schema}`` keys.  OpenAI's chat-completions
        ``tools`` parameter requires the ``{type: "function", function: {...,
        parameters: ...}}`` envelope -- so the client must translate.
        Without this translation, OpenAI returns 400 Missing required
        parameter: 'tools[0].type'."""
        client, sdk = _make_client()
        sdk.chat.completions.create.return_value = _completion(
            message=_content_message(content="ok"),
        )
        registry_tools = [
            {
                "name": "get_current_medications",
                "description": "Return the patient's active medications.",
                "input_schema": {
                    "type": "object",
                    "properties": {},
                    "additionalProperties": False,
                },
            },
        ]

        client.tool_call_completion(messages=[], tools=registry_tools)

        kwargs = sdk.chat.completions.create.call_args.kwargs
        assert kwargs["tools"] == [
            {
                "type": "function",
                "function": {
                    "name": "get_current_medications",
                    "description": "Return the patient's active medications.",
                    "parameters": {
                        "type": "object",
                        "properties": {},
                        "additionalProperties": False,
                    },
                },
            },
        ]


# ===================================================================
# Dependency provider
# ===================================================================


class TestDependencyProvider:
    """``get_llm_tool_choice_client`` returns the right thing per env."""

    def _clear_settings(self) -> None:
        get_settings.cache_clear()

    def test_returns_openai_client_when_key_set(self) -> None:
        self._clear_settings()
        try:
            env = os.environ.copy()
            env["AGENT_SHARED_SECRET"] = "test-secret"
            env["OPENAI_API_KEY"] = "sk-real-but-fake-12345"
            # Pre-build the SDK stand-in *before* patching ``openai.OpenAI``
            # so ``spec=`` resolves to the real class, then patch the
            # constructor to return our mock.
            sdk_stub = MagicMock(spec=openai.OpenAI)
            with patch.dict(os.environ, env, clear=True):
                with patch(
                    "agent_service.clients.tool_choice_openai.openai.OpenAI",
                    return_value=sdk_stub,
                ):
                    client = copilot_api.get_llm_tool_choice_client()
            assert isinstance(client, OpenAIToolChoiceClient)
        finally:
            self._clear_settings()

    def test_returns_missing_key_client_when_key_unset(self) -> None:
        self._clear_settings()
        try:
            env = os.environ.copy()
            env.pop("OPENAI_API_KEY", None)
            env["AGENT_SHARED_SECRET"] = "test-secret"
            with patch.dict(os.environ, env, clear=True):
                client = copilot_api.get_llm_tool_choice_client()
            assert isinstance(client, _MissingOpenAIKeyClient)
        finally:
            self._clear_settings()

    def test_missing_key_client_raises_typed_error_on_invocation(self) -> None:
        sentinel = _MissingOpenAIKeyClient()
        with pytest.raises(LLMNotConfiguredError, match="OPENAI_API_KEY is unset"):
            sentinel.tool_call_completion(messages=[], tools=[])

    def test_returns_missing_key_client_when_settings_unconstructable(self) -> None:
        """Missing AGENT_SHARED_SECRET still yields a typed sentinel."""
        self._clear_settings()
        try:
            env = os.environ.copy()
            env.pop("AGENT_SHARED_SECRET", None)
            env.pop("OPENAI_API_KEY", None)
            with patch.dict(os.environ, env, clear=True):
                client = copilot_api.get_llm_tool_choice_client()
            assert isinstance(client, _MissingOpenAIKeyClient)
        finally:
            self._clear_settings()
