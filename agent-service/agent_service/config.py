"""Application configuration loaded from environment variables.

Required env vars:
    AGENT_SHARED_SECRET  -- shared secret checked on every API request

Optional env vars:
    OPENAI_API_KEY       -- OpenAI API key (required for LLM workers)
    COHERE_API_KEY       -- Cohere API key (required for RAG reranking)
    HONEYCOMB_API_KEY    -- Honeycomb API key (observability)
    AGENT_DEBUG          -- enable debug mode (default: false)
    AGENT_LOG_LEVEL      -- log level (default: INFO)
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache


def _require_env(name: str) -> str:
    """Return the value of *name* from the environment or raise."""
    value = os.environ.get(name)
    if not value:
        raise RuntimeError(
            f"Required environment variable {name!r} is not set or empty. "
            "Set it before starting the agent service."
        )
    return value


def _optional_env(name: str, default: str = "") -> str:
    return os.environ.get(name, default)


def _bool_env(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name, "")
    if not raw:
        return default
    return raw.strip().lower() in ("1", "true", "yes")


@dataclass(frozen=True, slots=True)
class Settings:
    """Immutable application settings."""

    # Required
    agent_shared_secret: str

    # Optional -- may be empty until workers actually need them
    openai_api_key: str
    cohere_api_key: str
    honeycomb_api_key: str

    # Local toggles
    debug: bool
    log_level: str


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Build and cache a ``Settings`` instance from the environment.

    Raises ``RuntimeError`` with a descriptive message when a required
    variable is missing or empty.
    """
    return Settings(
        agent_shared_secret=_require_env("AGENT_SHARED_SECRET"),
        openai_api_key=_optional_env("OPENAI_API_KEY"),
        cohere_api_key=_optional_env("COHERE_API_KEY"),
        honeycomb_api_key=_optional_env("HONEYCOMB_API_KEY"),
        debug=_bool_env("AGENT_DEBUG"),
        log_level=_optional_env("AGENT_LOG_LEVEL", "INFO").upper(),
    )
