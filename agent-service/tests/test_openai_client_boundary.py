"""Tests for the LLM client boundary layer (S6).

Validates that ``FakeLLMClient`` satisfies the ``LLMClient`` protocol, returns
pre-recorded responses, tracks calls, and guards against accidental live API
usage.
"""

from __future__ import annotations

import os
from typing import Any
from unittest.mock import patch

import pytest

from agent_service.clients.openai_client import (
    FakeLLMClient,
    LLMClient,
    OpenAIClient,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_SAMPLE_EXTRACT: dict[str, Any] = {
    "test_name": "Hemoglobin",
    "value": "14.2",
    "unit": "g/dL",
}


def _make_fake(
    *,
    upload_responses: dict[str, str] | None = None,
    extract_responses: dict[str, dict[str, Any]] | None = None,
    allow_env_key: bool = True,
) -> FakeLLMClient:
    """Build a ``FakeLLMClient`` with sensible defaults for tests.

    ``allow_env_key`` defaults to ``True`` so CI environments that happen to
    have ``OPENAI_API_KEY`` set do not trip the safety guard.
    """
    return FakeLLMClient(
        upload_responses=upload_responses or {},
        extract_responses=extract_responses or {},
        allow_env_key=allow_env_key,
    )


# ===================================================================
# Protocol conformance
# ===================================================================


class TestProtocolConformance:
    """FakeLLMClient and OpenAIClient both satisfy the LLMClient protocol."""

    def test_fake_is_llm_client(self) -> None:
        fake = _make_fake()
        assert isinstance(fake, LLMClient)

    def test_openai_client_is_llm_client(self) -> None:
        """OpenAIClient structurally satisfies LLMClient (no instantiation)."""
        assert issubclass(OpenAIClient, LLMClient)

    def test_fake_has_upload_pdf(self) -> None:
        fake = _make_fake()
        assert callable(getattr(fake, "upload_pdf", None))

    def test_fake_has_extract_structured(self) -> None:
        fake = _make_fake()
        assert callable(getattr(fake, "extract_structured", None))

    def test_fake_has_embed_texts(self) -> None:
        fake = _make_fake()
        assert callable(getattr(fake, "embed_texts", None))


# ===================================================================
# Pre-recorded responses
# ===================================================================


class TestPreRecordedResponses:
    """FakeLLMClient returns the exact pre-recorded data."""

    def test_upload_pdf_returns_prerecorded_id(self) -> None:
        fake = _make_fake(upload_responses={"/tmp/lab.pdf": "file-abc123"})
        result = fake.upload_pdf("/tmp/lab.pdf")
        assert result == "file-abc123"

    def test_upload_pdf_returns_deterministic_fallback(self) -> None:
        fake = _make_fake()
        result = fake.upload_pdf("/tmp/unknown.pdf")
        assert result.startswith("fake-file-")
        # Same input always produces the same ID.
        assert fake.upload_pdf("/tmp/unknown.pdf") == result

    def test_upload_pdf_different_paths_give_different_ids(self) -> None:
        fake = _make_fake()
        id_a = fake.upload_pdf("/a.pdf")
        id_b = fake.upload_pdf("/b.pdf")
        assert id_a != id_b

    def test_extract_structured_returns_prerecorded(self) -> None:
        fake = _make_fake(extract_responses={"file-abc123": _SAMPLE_EXTRACT})
        result = fake.extract_structured("file-abc123", dict, "Extract lab data")
        assert result == _SAMPLE_EXTRACT

    def test_extract_structured_missing_key_raises(self) -> None:
        fake = _make_fake()
        with pytest.raises(KeyError, match="no pre-recorded extract response"):
            fake.extract_structured("nonexistent", dict, "prompt")


# ===================================================================
# Call tracking
# ===================================================================


class TestCallTracking:
    """FakeLLMClient records every call for test assertions."""

    def test_calls_initially_empty(self) -> None:
        fake = _make_fake()
        assert fake.calls == []

    def test_upload_pdf_tracked(self) -> None:
        fake = _make_fake()
        fake.upload_pdf("/tmp/test.pdf")
        assert len(fake.calls) == 1
        assert fake.calls[0].method == "upload_pdf"
        assert fake.calls[0].args == ("/tmp/test.pdf",)

    def test_extract_structured_tracked(self) -> None:
        fake = _make_fake(extract_responses={"fid": {"key": "val"}})
        fake.extract_structured("fid", dict, "do it")
        assert len(fake.calls) == 1
        assert fake.calls[0].method == "extract_structured"
        assert fake.calls[0].args == ("fid", dict, "do it")

    def test_embed_texts_tracked(self) -> None:
        fake = _make_fake()
        fake.embed_texts(["hello"])
        assert len(fake.calls) == 1
        assert fake.calls[0].method == "embed_texts"

    def test_multiple_calls_tracked_in_order(self) -> None:
        fake = _make_fake(extract_responses={"fid": {}})
        fake.upload_pdf("/a.pdf")
        fake.extract_structured("fid", dict, "p")
        fake.embed_texts(["x"])
        methods = [c.method for c in fake.calls]
        assert methods == ["upload_pdf", "extract_structured", "embed_texts"]


# ===================================================================
# Eval-mode safety guard
# ===================================================================


class TestEvalSafetyGuard:
    """FakeLLMClient prevents accidental live API calls during evals."""

    def test_raises_when_openai_api_key_set(self) -> None:
        with patch.dict(os.environ, {"OPENAI_API_KEY": "sk-test-key-12345"}):
            with pytest.raises(RuntimeError, match="OPENAI_API_KEY"):
                FakeLLMClient()

    def test_allows_when_key_unset(self) -> None:
        with patch.dict(os.environ, {}, clear=False):
            env = os.environ.copy()
            env.pop("OPENAI_API_KEY", None)
            with patch.dict(os.environ, env, clear=True):
                client = FakeLLMClient()
                assert isinstance(client, LLMClient)

    def test_allow_env_key_bypasses_guard(self) -> None:
        with patch.dict(os.environ, {"OPENAI_API_KEY": "sk-test-key-12345"}):
            client = FakeLLMClient(allow_env_key=True)
            assert isinstance(client, LLMClient)


# ===================================================================
# Embeddings
# ===================================================================


class TestEmbedTexts:
    """embed_texts returns correct shapes and deterministic values."""

    def test_default_dimensionality(self) -> None:
        fake = _make_fake()
        result = fake.embed_texts(["hello world"])
        assert len(result) == 1
        assert len(result[0]) == 1536

    def test_custom_dimensionality(self) -> None:
        fake = FakeLLMClient(allow_env_key=True, _embedding_dim=256)
        result = fake.embed_texts(["test"])
        assert len(result[0]) == 256

    def test_multiple_texts(self) -> None:
        fake = _make_fake()
        result = fake.embed_texts(["a", "b", "c"])
        assert len(result) == 3
        for vec in result:
            assert len(vec) == 1536

    def test_values_in_range(self) -> None:
        fake = _make_fake()
        [vec] = fake.embed_texts(["test input"])
        assert all(-1.0 <= v <= 1.0 for v in vec)

    def test_deterministic_same_input(self) -> None:
        fake = _make_fake()
        [vec_a] = fake.embed_texts(["determinism check"])
        [vec_b] = fake.embed_texts(["determinism check"])
        assert vec_a == vec_b

    def test_different_inputs_differ(self) -> None:
        fake = _make_fake()
        [vec_a] = fake.embed_texts(["input one"])
        [vec_b] = fake.embed_texts(["input two"])
        assert vec_a != vec_b


# ===================================================================
# OpenAIClient construction guard
# ===================================================================


class TestOpenAIClientConstruction:
    """OpenAIClient refuses to start without an API key."""

    def test_raises_without_api_key(self) -> None:
        from agent_service.config import get_settings

        # Clear the lru_cache so get_settings() re-reads the patched env.
        get_settings.cache_clear()
        try:
            env = os.environ.copy()
            env.pop("OPENAI_API_KEY", None)
            env.setdefault("AGENT_SHARED_SECRET", "test-secret")
            with patch.dict(os.environ, env, clear=True):
                with pytest.raises(RuntimeError, match="No OpenAI API key"):
                    OpenAIClient()
        finally:
            # Clear again so subsequent tests are not affected.
            get_settings.cache_clear()
