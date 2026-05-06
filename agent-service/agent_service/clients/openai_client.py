"""LLM client abstraction with real and fake implementations.

``LLMClient`` defines the boundary between the agent orchestration layer and
the OpenAI API.  All agent code depends on the protocol, never on the concrete
SDK, so tests and offline evals can swap in ``FakeLLMClient`` without touching
production wiring.
"""

from __future__ import annotations

import hashlib
import struct
from dataclasses import dataclass, field
from typing import Any, Protocol, runtime_checkable

import openai

from agent_service.config import get_settings


# ---------------------------------------------------------------------------
# Protocol
# ---------------------------------------------------------------------------


@runtime_checkable
class LLMClient(Protocol):
    """Boundary protocol for LLM operations used by the agent service."""

    def upload_pdf(self, file_path: str) -> str:
        """Upload a PDF file and return its file ID."""
        ...

    def extract_structured(self, file_id: str, schema: type, prompt: str) -> dict[str, Any]:
        """Extract structured data from an uploaded file.

        Uses structured outputs / function calling to return a dict
        conforming to *schema*.
        """
        ...

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        """Generate embedding vectors for *texts*."""
        ...


# ---------------------------------------------------------------------------
# Real implementation
# ---------------------------------------------------------------------------


class OpenAIClient:
    """Production ``LLMClient`` backed by the OpenAI Python SDK."""

    _EMBEDDING_MODEL = "text-embedding-3-small"
    _CHAT_MODEL = "gpt-4o"
    _EMBEDDING_DIMENSIONS = 1536

    def __init__(self, *, api_key: str | None = None) -> None:
        key = api_key or get_settings().openai_api_key
        if not key:
            raise RuntimeError(
                "No OpenAI API key available. Set the OPENAI_API_KEY environment "
                "variable or pass api_key explicitly."
            )
        self._client = openai.OpenAI(api_key=key)

    def upload_pdf(self, file_path: str) -> str:
        """Upload *file_path* via the Files API and return the file ID."""
        with open(file_path, "rb") as fh:
            result = self._client.files.create(file=fh, purpose="assistants")
        return result.id

    def extract_structured(self, file_id: str, schema: type, prompt: str) -> dict[str, Any]:
        """Call chat completions with structured output to extract data."""
        response = self._client.responses.parse(
            model=self._CHAT_MODEL,
            input=[
                {
                    "role": "system",
                    "content": prompt,
                },
                {
                    "role": "user",
                    "content": f"Extract structured data from file {file_id}.",
                },
            ],
            text_format=schema,
        )
        parsed = response.output_parsed
        if parsed is None:
            raise RuntimeError("Structured output parsing returned None")
        if isinstance(parsed, dict):
            return parsed
        # Pydantic model -- convert to dict
        return parsed.model_dump()  # type: ignore[union-attr]

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        """Generate embeddings via the Embeddings API."""
        response = self._client.embeddings.create(
            model=self._EMBEDDING_MODEL,
            input=texts,
        )
        return [item.embedding for item in response.data]


# ---------------------------------------------------------------------------
# Fake / fixture implementation
# ---------------------------------------------------------------------------


@dataclass
class _CallRecord:
    """An entry in the FakeLLMClient call log."""

    method: str
    args: tuple[Any, ...]
    kwargs: dict[str, Any]


@dataclass
class FakeLLMClient:
    """Deterministic fake ``LLMClient`` for tests and offline evals.

    * Constructor accepts pre-recorded responses.
    * Every call is tracked in ``calls`` for test assertions.
    * A guard prevents accidentally calling the real OpenAI API: if
      ``OPENAI_API_KEY`` is set in the environment the constructor raises
      unless ``allow_env_key=True`` is passed.
    """

    upload_responses: dict[str, str] = field(default_factory=dict)
    extract_responses: dict[str, dict[str, Any]] = field(default_factory=dict)
    calls: list[_CallRecord] = field(default_factory=list, init=False)
    allow_env_key: bool = field(default=False)
    _embedding_dim: int = field(default=1536)

    def __post_init__(self) -> None:
        import os

        if not self.allow_env_key and os.environ.get("OPENAI_API_KEY"):
            raise RuntimeError(
                "FakeLLMClient instantiated while OPENAI_API_KEY is set in the "
                "environment. This is a safety guard to prevent accidentally "
                "calling the real OpenAI API during tests/evals. Either unset "
                "the variable or pass allow_env_key=True."
            )

    # -- LLMClient interface -----------------------------------------------

    def upload_pdf(self, file_path: str) -> str:
        """Return a pre-recorded file ID or a deterministic fake."""
        self.calls.append(_CallRecord("upload_pdf", (file_path,), {}))
        return self.upload_responses.get(file_path, f"fake-file-{_stable_hash(file_path)}")

    def extract_structured(self, file_id: str, schema: type, prompt: str) -> dict[str, Any]:
        """Return a pre-recorded dict keyed by *file_id*."""
        self.calls.append(_CallRecord("extract_structured", (file_id, schema, prompt), {}))
        result = self.extract_responses.get(file_id)
        if result is None:
            raise KeyError(
                f"FakeLLMClient has no pre-recorded extract response for "
                f"file_id={file_id!r}. Register it in extract_responses."
            )
        return result

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        """Return deterministic fake embeddings derived from text hashes."""
        self.calls.append(_CallRecord("embed_texts", (texts,), {}))
        return [_fake_embedding(text, self._embedding_dim) for text in texts]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _stable_hash(value: str) -> str:
    """Return a short, stable hex digest for *value*."""
    return hashlib.sha256(value.encode()).hexdigest()[:12]


def _fake_embedding(text: str, dim: int) -> list[float]:
    """Produce a deterministic embedding vector from *text*.

    Uses SHA-256 to seed a repeatable sequence of floats in [-1, 1].  The
    result has exactly *dim* dimensions and is stable across runs for the
    same input.
    """
    digest = hashlib.sha256(text.encode()).digest()
    # Extend the digest to cover the requested dimensionality.
    # Each 4 bytes gives one float via struct unpack.
    chunks_needed = dim
    raw = digest
    while len(raw) < chunks_needed * 4:
        raw += hashlib.sha256(raw).digest()

    floats: list[float] = []
    for i in range(dim):
        # Unpack 4 bytes as an unsigned 32-bit int, map to [-1, 1].
        (val,) = struct.unpack_from(">I", raw, i * 4)
        floats.append(val / 0xFFFFFFFF * 2.0 - 1.0)
    return floats
