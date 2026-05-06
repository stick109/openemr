"""Tests for health route, shared-secret auth, and the run stub."""

from __future__ import annotations

import os
from unittest import mock

import pytest
from fastapi.testclient import TestClient

# The config module caches settings with @lru_cache.  We need to clear it
# between tests so that each test controls its own environment.
SHARED_SECRET = "test-secret-value"


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> None:
    """Clear the cached Settings singleton before every test."""
    from agent_service.config import get_settings

    get_settings.cache_clear()


@pytest.fixture()
def _env() -> None:
    """Patch the environment with valid required vars."""
    with mock.patch.dict(
        os.environ,
        {"AGENT_SHARED_SECRET": SHARED_SECRET},
        clear=False,
    ):
        yield  # type: ignore[misc]


@pytest.fixture()
def client(_env: None) -> TestClient:
    """Return a ``TestClient`` backed by the FastAPI app."""
    # Import *after* env is patched so get_settings() succeeds inside module
    # init if ever called at import time.
    from agent_service.main import app

    return TestClient(app, raise_server_exceptions=False)


# ---------------------------------------------------------------------------
# Health route
# ---------------------------------------------------------------------------


class TestHealthz:
    def test_returns_200_ok(self, client: TestClient) -> None:
        resp = client.get("/healthz")
        assert resp.status_code == 200
        assert resp.json() == {"status": "ok"}

    def test_no_auth_required(self, client: TestClient) -> None:
        """Health endpoint must not require X-Agent-Secret."""
        resp = client.get("/healthz")
        assert resp.status_code == 200


# ---------------------------------------------------------------------------
# Auth: missing / wrong / correct secret
# ---------------------------------------------------------------------------

_VALID_BODY = {
    "patient_id": 1,
    "file_path": "/tmp/test.pdf",
    "doc_type": "lab_pdf",
    "encounter_id": 1,
    "trace_id": "aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee",
}


class TestAuth:
    def test_missing_secret_returns_401(self, client: TestClient) -> None:
        resp = client.post("/api/agent/run", json=_VALID_BODY)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "unauthorized"

    def test_wrong_secret_returns_403(self, client: TestClient) -> None:
        resp = client.post(
            "/api/agent/run",
            json=_VALID_BODY,
            headers={"X-Agent-Secret": "wrong-secret"},
        )
        assert resp.status_code == 403
        body = resp.json()
        assert body["error"] == "forbidden"

    def test_correct_secret_returns_200(self, client: TestClient) -> None:
        fake_result = {
            "extracted": {"results": []},
            "evidence": [],
            "answer": "test answer",
            "citations": [],
            "cost_usd": 0.0,
            "latency_ms_per_step": {},
            "tool_sequence": ["extract", "retrieve", "finalize"],
            "extraction_confidence": 0.9,
            "status": "completed",
            "trace_id": _VALID_BODY["trace_id"],
        }

        class _FakeGraph:
            def invoke(self, state: dict) -> dict:  # type: ignore[type-arg]
                return {**state, **fake_result}

        with mock.patch(
            "agent_service.main._resolve_dependencies",
            return_value=(None, None),
        ), mock.patch(
            "agent_service.graph.build_graph",
            return_value=_FakeGraph(),
        ):
            resp = client.post(
                "/api/agent/run",
                json=_VALID_BODY,
                headers={"X-Agent-Secret": SHARED_SECRET},
            )
        assert resp.status_code == 200
        body = resp.json()
        assert "answer" in body
        assert body["tool_sequence"] == ["extract", "retrieve", "finalize"]


# ---------------------------------------------------------------------------
# Config validation
# ---------------------------------------------------------------------------


class TestConfig:
    def test_missing_shared_secret_raises(self) -> None:
        """get_settings() must fail fast when the secret is not set."""
        from agent_service.config import get_settings

        with mock.patch.dict(os.environ, {}, clear=True):
            get_settings.cache_clear()
            with pytest.raises(RuntimeError, match="AGENT_SHARED_SECRET"):
                get_settings()

    def test_debug_flag_defaults_false(self, _env: None) -> None:
        from agent_service.config import get_settings

        settings = get_settings()
        assert settings.debug is False

    def test_debug_flag_truthy(self) -> None:
        from agent_service.config import get_settings

        with mock.patch.dict(
            os.environ,
            {"AGENT_SHARED_SECRET": "s", "AGENT_DEBUG": "true"},
            clear=False,
        ):
            get_settings.cache_clear()
            settings = get_settings()
            assert settings.debug is True

    def test_log_level_default(self, _env: None) -> None:
        from agent_service.config import get_settings

        settings = get_settings()
        assert settings.log_level == "INFO"

    def test_log_level_override(self) -> None:
        from agent_service.config import get_settings

        with mock.patch.dict(
            os.environ,
            {"AGENT_SHARED_SECRET": "s", "AGENT_LOG_LEVEL": "debug"},
            clear=False,
        ):
            get_settings.cache_clear()
            settings = get_settings()
            assert settings.log_level == "DEBUG"
