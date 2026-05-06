"""Client abstractions for external LLM services.

Provides a ``LLMClient`` protocol and concrete implementations:

* ``OpenAIClient``   -- real client backed by the ``openai`` SDK.
* ``FakeLLMClient``  -- deterministic fake for tests and offline evals.
"""

from agent_service.clients.openai_client import (
    FakeLLMClient,
    LLMClient,
    OpenAIClient,
)

__all__ = [
    "FakeLLMClient",
    "LLMClient",
    "OpenAIClient",
]
