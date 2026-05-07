"""Production OpenAI adapter for the M13 tool-choice client surface.

M13 introduced :class:`agent_service.clients.tool_choice.LLMToolChoiceClient`
plus a deterministic :class:`FakeLLMToolChoiceClient` for tests, but the
production :func:`get_llm_tool_choice_client` dependency in
:mod:`agent_service.api.copilot` returned an
``_UnconfiguredLLMToolChoiceClient`` sentinel that always raised
``RuntimeError`` -- so every real request to ``POST /api/copilot/run``
collapsed into a generic ``model_error`` refusal. This module ships the
real adapter so the agent loop can drive the OpenAI Chat Completions
``tools`` API end-to-end.

Design notes
------------
* The adapter is intentionally minimal: it owns translation of the loop's
  ``messages`` / ``tools`` payload into the OpenAI request shape and
  translation of the response back into ``LLMToolChoiceTurn``. All
  retry, observability, and cost accounting belong to the loop or
  dependencies above this boundary.
* Network and rate-limit failures (``openai.APIError`` and friends) are
  re-raised verbatim. The agent loop's M13 try/except converts them into
  a typed ``model_error`` halt reason.
* Bad JSON in tool-call ``arguments`` is surfaced as ``ValueError`` with
  a generic message; the original payload is intentionally not embedded
  in the error so user prompt text never leaks into log lines.
* When the OPENAI_API_KEY setting is missing or empty, the dependency
  provider returns :class:`_MissingOpenAIKeyClient`. Calling its
  ``tool_call_completion`` raises :class:`LLMNotConfiguredError`, which
  the loop maps to a ``model_error`` refusal with a clearly-typed cause
  rather than a generic ``RuntimeError``.
"""

from __future__ import annotations

import json
import logging
import os
from collections.abc import Mapping, Sequence
from typing import Any

import openai

from agent_service.clients.tool_choice import (
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceTurn,
)


__all__ = [
    "DEFAULT_OPENAI_MODEL",
    "LLMNotConfiguredError",
    "OPENAI_MODEL_ENV_VAR",
    "OpenAIToolChoiceClient",
]


_LOGGER = logging.getLogger("agent_service.clients.tool_choice_openai")

DEFAULT_OPENAI_MODEL: str = "gpt-4o-mini"
OPENAI_MODEL_ENV_VAR: str = "OPENEMR_COPILOT_OPENAI_MODEL"


class LLMNotConfiguredError(RuntimeError):
    """Raised when the production tool-choice client is invoked with no API key.

    The agent loop catches this and routes through the ``model_error``
    halt reason, but the typed class lets log readers distinguish a
    deployment-misconfiguration miss from a transient network failure.
    """


def _resolve_model_name() -> str:
    """Return the chat-completions model name for tool-choice requests.

    The model is configurable via the :data:`OPENAI_MODEL_ENV_VAR`
    environment variable so deployments can pin a different SKU without a
    code change. A blank value falls back to :data:`DEFAULT_OPENAI_MODEL`.
    """
    raw = os.environ.get(OPENAI_MODEL_ENV_VAR, "").strip()
    return raw or DEFAULT_OPENAI_MODEL


def _coerce_messages(
    messages: Sequence[Mapping[str, Any]],
) -> list[dict[str, Any]]:
    """Translate the loop's internal messages into the OpenAI SDK shape.

    The agent loop produces three message shapes:

    1. ``role: system`` / ``role: user`` -- pass-through, just copy.
    2. ``role: assistant`` with ``tool_calls`` -- the loop emits each
       call as ``{"id", "tool_name", "arguments"}``. OpenAI requires
       ``{"id", "type": "function", "function": {"name", "arguments":
       <JSON-string>}}``; ``arguments`` MUST be a JSON-encoded string
       (not a dict) so the model's structured tool arguments survive
       round-trip exactly.
    3. ``role: tool`` -- the loop emits ``{"tool_call_id", "tool_name",
       "status", "result_count", "payload"}``. OpenAI requires
       ``{"role": "tool", "tool_call_id", "content": <string>}``. We
       fold the loop's status/result/payload into a compact JSON string
       on ``content`` so the model sees the actual tool output and can
       cite source IDs from it.

    Copying defensively keeps the loop's state untouched when the SDK
    normalises payloads in-place.
    """
    sdk_messages: list[dict[str, Any]] = []
    for message in messages:
        role = message.get("role")
        if role == "assistant" and message.get("tool_calls") is not None:
            sdk_messages.append(_translate_assistant_tool_calls(message))
            continue
        if role == "tool":
            sdk_messages.append(_translate_tool_result(message))
            continue
        # Plain user / system / pre-shaped assistant messages: copy.
        sdk_messages.append(dict(message))
    return sdk_messages


def _translate_assistant_tool_calls(
    message: Mapping[str, Any],
) -> dict[str, Any]:
    """Translate an assistant-with-tool-calls message to OpenAI's shape."""
    raw_calls = message.get("tool_calls") or ()
    sdk_calls: list[dict[str, Any]] = []
    for call in raw_calls:
        if not isinstance(call, Mapping):
            sdk_calls.append(dict(call))  # let SDK validate
            continue
        if call.get("type") == "function" and "function" in call:
            sdk_calls.append(dict(call))
            continue
        # Loop-internal shape: {id, tool_name, arguments(dict)}.
        arguments = call.get("arguments")
        if isinstance(arguments, Mapping):
            arguments_str = json.dumps(dict(arguments), separators=(",", ":"))
        elif isinstance(arguments, str):
            arguments_str = arguments
        else:
            arguments_str = "{}"
        sdk_calls.append(
            {
                "id": call.get("id") or call.get("call_id") or "",
                "type": "function",
                "function": {
                    "name": call.get("tool_name") or call.get("name"),
                    "arguments": arguments_str,
                },
            },
        )
    sdk_message: dict[str, Any] = {
        "role": "assistant",
        "tool_calls": sdk_calls,
    }
    content = message.get("content")
    # OpenAI accepts assistant content alongside tool_calls; only
    # forward when explicitly set so we do not introduce empty strings.
    if content is not None:
        sdk_message["content"] = content
    return sdk_message


def _translate_tool_result(
    message: Mapping[str, Any],
) -> dict[str, Any]:
    """Translate a tool-result message to OpenAI's ``role: tool`` shape."""
    if "content" in message and isinstance(message["content"], str):
        # Pre-shaped: pass through.
        return {
            "role": "tool",
            "tool_call_id": message.get("tool_call_id", ""),
            "content": message["content"],
        }
    # Loop-internal shape: build a compact JSON content body that
    # carries status + result_count + (sanitised) payload. The payload
    # is whatever the executor returned -- the M16 PHI scanner runs
    # earlier on observability events, not on tool payloads, so we
    # rely on the M5/M6 input-schema rejection of authority keys plus
    # the verifier (M15) to keep the final response PHI-safe.
    body: dict[str, Any] = {
        "status": message.get("status"),
    }
    if "result_count" in message:
        body["result_count"] = message["result_count"]
    if "error_class" in message:
        body["error_class"] = message["error_class"]
    if "payload" in message and message["payload"] is not None:
        body["payload"] = _jsonable(message["payload"])
    return {
        "role": "tool",
        "tool_call_id": message.get("tool_call_id", ""),
        "content": json.dumps(body, separators=(",", ":"), default=str),
    }


def _jsonable(value: Any) -> Any:
    """Best-effort coercion of arbitrary tool payloads into JSON types.

    Pydantic models expose ``model_dump``; dataclasses can be coerced
    via ``__dict__``. Tuples become lists. Everything else falls
    through to ``json.dumps(default=str)``.
    """
    if hasattr(value, "model_dump"):
        return value.model_dump()
    if isinstance(value, Mapping):
        return {k: _jsonable(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_jsonable(v) for v in value]
    return value


def _coerce_tools(
    tools: Sequence[Mapping[str, Any]],
) -> list[dict[str, Any]]:
    """Translate the registry's tool schemas into the OpenAI SDK shape.

    The agent-service tool registry emits Anthropic-style schemas:
    ``{"name": ..., "description": ..., "input_schema": {...}}``. The
    OpenAI Chat Completions ``tools`` parameter requires:
    ``{"type": "function", "function": {"name", "description",
    "parameters"}}``. If a caller already provides an OpenAI-shaped tool
    (with a top-level ``"type": "function"`` key) we pass it through
    unchanged so existing OpenAI-native tests keep working.
    """
    sdk_tools: list[dict[str, Any]] = []
    for entry in tools:
        if entry.get("type") == "function" and "function" in entry:
            sdk_tools.append(dict(entry))
            continue
        function_payload: dict[str, Any] = {"name": entry["name"]}
        description = entry.get("description")
        if description is not None:
            function_payload["description"] = description
        parameters = entry.get("parameters")
        if parameters is None:
            # The registry uses ``input_schema`` for JSON-Schema parameters.
            parameters = entry.get("input_schema")
        if parameters is not None:
            function_payload["parameters"] = dict(parameters)
        sdk_tools.append({"type": "function", "function": function_payload})
    return sdk_tools


def _parse_tool_arguments(raw: str | None) -> dict[str, Any]:
    """Parse a tool-call ``arguments`` JSON string into a dict.

    The OpenAI SDK returns the raw JSON string the model emitted; we
    decode it here so the agent loop sees a structured mapping. Any
    decode failure is turned into a generic :class:`ValueError`; the
    original JSON is omitted from the message so user-prompt text never
    appears in log lines.
    """
    if raw is None or raw == "":
        return {}
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ValueError(
            "OpenAI tool-call arguments were not valid JSON.",
        ) from exc
    if not isinstance(parsed, dict):
        raise ValueError(
            "OpenAI tool-call arguments must decode to a JSON object.",
        )
    return parsed


def _build_tool_calls(
    raw_tool_calls: Sequence[Any] | None,
) -> tuple[LLMToolCallChoice, ...]:
    """Translate the SDK's ``tool_calls`` list into our value objects.

    Each entry on the assistant message has ``id``, ``function.name``,
    and ``function.arguments`` (JSON string). We surface them as
    :class:`LLMToolCallChoice` instances so the loop can dispatch them
    through the M6 executor without knowing the SDK's shape.
    """
    if not raw_tool_calls:
        return ()
    out: list[LLMToolCallChoice] = []
    for call in raw_tool_calls:
        function = getattr(call, "function", None)
        if function is None:
            raise ValueError(
                "OpenAI tool call is missing the 'function' payload.",
            )
        name = getattr(function, "name", None)
        if not isinstance(name, str) or name == "":
            raise ValueError(
                "OpenAI tool call is missing a valid 'function.name'.",
            )
        call_id = getattr(call, "id", None)
        if not isinstance(call_id, str) or call_id == "":
            raise ValueError(
                "OpenAI tool call is missing a valid 'id'.",
            )
        arguments = _parse_tool_arguments(getattr(function, "arguments", None))
        out.append(
            LLMToolCallChoice(
                call_id=call_id,
                tool_name=name,
                arguments=arguments,
            ),
        )
    return tuple(out)


class OpenAIToolChoiceClient:
    """Production :class:`LLMToolChoiceClient` backed by the OpenAI SDK.

    The client targets the Chat Completions API with the modern ``tools``
    parameter (not the legacy ``functions`` API). The model name is read
    once at construction time from :data:`OPENAI_MODEL_ENV_VAR`, with
    :data:`DEFAULT_OPENAI_MODEL` as the fallback.
    """

    def __init__(
        self,
        *,
        api_key: str,
        model: str | None = None,
        client: openai.OpenAI | None = None,
    ) -> None:
        if not api_key:
            raise ValueError(
                "OpenAIToolChoiceClient requires a non-empty OpenAI API key.",
            )
        self._model = model if model is not None else _resolve_model_name()
        self._client = client if client is not None else openai.OpenAI(api_key=api_key)

    @property
    def model(self) -> str:
        """Return the model name this client is pinned to."""
        return self._model

    def tool_call_completion(
        self,
        *,
        messages: Sequence[Mapping[str, Any]],
        tools: Sequence[Mapping[str, Any]],
    ) -> LLMToolChoiceTurn:
        """Invoke chat completions and translate the response.

        Behaviour
        ---------
        * Tool calls win over content: if the assistant message carries
          any ``tool_calls`` the loop must execute them before reading
          the final answer, so we return them and ignore any sibling
          text.
        * Otherwise we surface ``content`` as
          :class:`LLMFinalMessage`. The loop converts an empty / un-
          parseable ``content`` into a deterministic refusal envelope.
        * Network / rate-limit / SDK failures propagate untouched.
        """
        sdk_messages = _coerce_messages(messages)
        sdk_tools = _coerce_tools(tools)

        kwargs: dict[str, Any] = {
            "model": self._model,
            "messages": sdk_messages,
        }
        if sdk_tools:
            kwargs["tools"] = sdk_tools

        response = self._client.chat.completions.create(**kwargs)

        choice = response.choices[0]
        message = choice.message

        tool_calls = _build_tool_calls(getattr(message, "tool_calls", None))
        if tool_calls:
            return LLMToolChoiceTurn(tool_calls=tool_calls)

        content = getattr(message, "content", None) or ""
        return LLMToolChoiceTurn(
            final_message=LLMFinalMessage(content=content),
        )


class _MissingOpenAIKeyClient:
    """Sentinel returned when ``OPENAI_API_KEY`` is unset.

    Construction time of the dependency provider must not raise (FastAPI
    resolves dependencies before body / auth validation), so we defer
    failure to the moment the agent loop actually tries to invoke the
    model. The error class is typed so downstream handlers can tell
    deployment-misconfiguration apart from runtime SDK failures.
    """

    def tool_call_completion(
        self,
        *,
        messages: Sequence[Mapping[str, Any]],  # noqa: ARG002 -- protocol shape
        tools: Sequence[Mapping[str, Any]],  # noqa: ARG002 -- protocol shape
    ) -> LLMToolChoiceTurn:
        raise LLMNotConfiguredError(
            "OpenAI tool-choice client is not configured: "
            "OPENAI_API_KEY is unset for this deployment.",
        )
