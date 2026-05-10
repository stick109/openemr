"""Regression: BOM-stripping in environment-variable reads.

A leftover U+FEFF (UTF-8 BOM) at the start of OPENAI_API_KEY surfaced as

    UnicodeEncodeError: 'ascii' codec can't encode character '\\ufeff'
    in position 7: ordinal not in range(128)

inside the OpenAI SDK's Authorization-header builder, killing the
lab-PDF extractor on Railway. The deploy script's no-BOM stdin write
patches new env-var sets, but values written before that fix shipped
remained BOM-tainted at runtime, so the agent service must also strip
the BOM at the env-read boundary defensively.

These tests pin that boundary in :mod:`agent_service.config`.
"""

from __future__ import annotations

import importlib

import pytest

from agent_service import config


@pytest.fixture(autouse=True)
def _reset_settings_cache():
    config.get_settings.cache_clear()
    yield
    config.get_settings.cache_clear()


def test_require_env_strips_leading_bom(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AGENT_SHARED_SECRET", "﻿sk-real-secret")
    assert config._require_env("AGENT_SHARED_SECRET") == "sk-real-secret"


def test_optional_env_strips_leading_bom(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("OPENAI_API_KEY", "﻿sk-test-1234567890")
    assert config._optional_env("OPENAI_API_KEY") == "sk-test-1234567890"


def test_optional_env_strips_trailing_whitespace(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("OPENAI_API_KEY", "  sk-x  \r\n")
    assert config._optional_env("OPENAI_API_KEY") == "sk-x"


def test_int_env_strips_bom_before_parse(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("OPENEMR_DB_PORT", "﻿3306")
    assert config._int_env("OPENEMR_DB_PORT", default=3306) == 3306


def test_bool_env_strips_bom(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AGENT_DEBUG", "﻿true")
    assert config._bool_env("AGENT_DEBUG") is True


def test_require_env_treats_bom_only_value_as_empty(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AGENT_SHARED_SECRET", "﻿   ")
    with pytest.raises(RuntimeError, match="not set or empty"):
        config._require_env("AGENT_SHARED_SECRET")


def test_optional_env_unset_returns_default(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    # Default empty string remains empty (no .strip() blow-up on falsy).
    assert config._optional_env("OPENAI_API_KEY") == ""
    assert config._optional_env("OPENAI_API_KEY", default="fallback") == "fallback"
