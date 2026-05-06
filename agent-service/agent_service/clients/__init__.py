"""Client abstractions for external LLM services.

Provides a ``LLMClient`` protocol and concrete implementations:

* ``OpenAIClient``   -- real client backed by the ``openai`` SDK.
* ``FakeLLMClient``  -- deterministic fake for tests and offline evals.

Tool-choice (M13) primitives are exposed too:

* ``LLMToolChoiceClient`` -- protocol used by the agent loop.
* ``FakeLLMToolChoiceClient`` -- deterministic scripted fake.
* ``LLMFinalMessage`` / ``LLMToolCallChoice`` / ``LLMToolChoiceTurn`` --
  value-object shapes the loop consumes.
"""

from agent_service.clients.openai_client import (
    FakeLLMClient,
    LLMClient,
    OpenAIClient,
)
from agent_service.clients.tool_choice import (
    FakeLLMToolChoiceClient,
    LLMFinalMessage,
    LLMToolCallChoice,
    LLMToolChoiceClient,
    LLMToolChoiceTurn,
    ScriptedTurn,
)

__all__ = [
    "FakeLLMClient",
    "FakeLLMToolChoiceClient",
    "LLMClient",
    "LLMFinalMessage",
    "LLMToolCallChoice",
    "LLMToolChoiceClient",
    "LLMToolChoiceTurn",
    "OpenAIClient",
    "ScriptedTurn",
]
