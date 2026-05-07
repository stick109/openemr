"""Application configuration loaded from environment variables.

Required env vars:
    AGENT_SHARED_SECRET  -- shared secret checked on every API request

OpenEMR read-only DB (M9): the sidecar's read repository fails closed
when any of these are missing or empty.

    OPENEMR_DB_NAME      -- target schema name
    OPENEMR_DB_USER_RO   -- read-only username (sidecar must not have writes)
    OPENEMR_DB_PASS_RO   -- read-only password (secret)

Optional env vars:
    OPENAI_API_KEY               -- OpenAI API key (required for LLM workers)
    COHERE_API_KEY               -- Cohere API key (required for RAG reranking)
    HONEYCOMB_API_KEY            -- Honeycomb API key (observability)
    AGENT_DEBUG                  -- enable debug mode (default: false)
    AGENT_LOG_LEVEL              -- log level (default: INFO)
    OPENEMR_DB_HOST              -- DB host (default: localhost)
    OPENEMR_DB_PORT              -- DB port (default: 3306)
    OPENEMR_DB_TIMEOUT_S         -- connect timeout in seconds (default: 5)
    OBSERVABILITY_EVENTS_PATH    -- M16 per-tool-call event JSONL path.  Unset
                                    means events are dropped on the floor
                                    (NullEventRecorder).
    OBSERVABILITY_EVENTS_STDOUT  -- when truthy ("1", "true", "yes"), the
                                    sidecar additionally emits each
                                    observability event as a JSON log
                                    record at INFO level so demo
                                    deployments can tail them via
                                    ``docker compose logs -f``.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path


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


def _int_env(name: str, default: int) -> int:
    """Parse *name* as an int, falling back to *default* when unset/empty.

    Raises ``RuntimeError`` if the variable is set but unparseable, so a
    typo (e.g. ``OPENEMR_DB_PORT=33o6``) fails loudly at startup rather
    than silently using the default.
    """
    raw = os.environ.get(name, "")
    if raw == "":
        return default
    try:
        return int(raw)
    except ValueError as exc:
        msg = (
            f"Environment variable {name!r}={raw!r} is not a valid integer. "
            "Fix or unset it before starting the agent service."
        )
        raise RuntimeError(msg) from exc


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

    # OpenEMR read-only DB (M9). ``openemr_db_name`` /
    # ``openemr_db_user_ro`` / ``openemr_db_pass_ro`` are required by the
    # repository factory and must be non-empty when the read repository
    # is constructed; they default to empty here so workers that never
    # touch the DB are not forced to set them. The repository's
    # ``from_settings`` factory enforces the fail-closed contract.
    openemr_db_host: str
    openemr_db_port: int
    openemr_db_name: str
    openemr_db_user_ro: str
    openemr_db_pass_ro: str
    openemr_db_timeout_s: int

    # M16: optional path for per-tool-call observability events.  ``None``
    # routes events through ``NullEventRecorder`` -- the agent loop still
    # builds them (so the PHI scan still runs) but they are dropped.
    # Default to ``None`` so existing test fixtures that construct
    # ``Settings`` positionally without naming this field continue to
    # compile.
    observability_events_path: Path | None = None

    # M16 follow-up: when ``True``, the sidecar emits each event as a
    # single JSON log record at INFO level (in addition to the JSONL file
    # if one is also configured).  Demo deployments enable this so the
    # event stream is visible via ``docker compose logs -f``; production
    # deployments leave it off and rely on the JSONL sink.
    observability_events_stdout: bool = False


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Build and cache a ``Settings`` instance from the environment.

    Raises ``RuntimeError`` with a descriptive message when a required
    variable is missing or empty.
    """
    raw_events_path = _optional_env("OBSERVABILITY_EVENTS_PATH").strip()
    events_path: Path | None = Path(raw_events_path) if raw_events_path else None

    return Settings(
        agent_shared_secret=_require_env("AGENT_SHARED_SECRET"),
        openai_api_key=_optional_env("OPENAI_API_KEY"),
        cohere_api_key=_optional_env("COHERE_API_KEY"),
        honeycomb_api_key=_optional_env("HONEYCOMB_API_KEY"),
        debug=_bool_env("AGENT_DEBUG"),
        log_level=_optional_env("AGENT_LOG_LEVEL", "INFO").upper(),
        openemr_db_host=_optional_env("OPENEMR_DB_HOST", "localhost"),
        openemr_db_port=_int_env("OPENEMR_DB_PORT", 3306),
        openemr_db_name=_optional_env("OPENEMR_DB_NAME"),
        openemr_db_user_ro=_optional_env("OPENEMR_DB_USER_RO"),
        openemr_db_pass_ro=_optional_env("OPENEMR_DB_PASS_RO"),
        openemr_db_timeout_s=_int_env("OPENEMR_DB_TIMEOUT_S", 5),
        observability_events_path=events_path,
        observability_events_stdout=_bool_env("OBSERVABILITY_EVENTS_STDOUT"),
    )
