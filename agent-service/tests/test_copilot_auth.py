"""Tests for the M4 sidecar context-verification dependency.

These tests exercise the FastAPI wiring around
:func:`require_copilot_run_context`. They confirm the fail-closed
behavior required by the migration spec:

* ``/healthz`` is unaffected -- still public, still 200.
* Requests with no ``run_context`` field fail body validation (422).
* Requests with malformed / tampered / expired tokens fail with 401
  and a typed ``error`` discriminator.
* Requests with valid tokens reach the M2 stub handler (501).
* The wire token is never echoed in the response body.

Tokens are minted in-process with a tiny Python helper that matches the
PHP minter byte-for-byte (see ``test_copilot_run_context.py`` for the
cross-language proof). The secret resolver and clock are overridden via
``app.dependency_overrides`` so the suite is fully deterministic.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
from collections.abc import Callable, Iterator
from typing import Any
from unittest import mock

import pytest
from fastapi.testclient import TestClient

from agent_service.api.dependencies import (
    get_clock,
    get_secret_resolver,
    get_settings_dep,
)
from agent_service.auth.copilot_run_context import SecretResolver
from agent_service.config import Settings


# ---------------------------------------------------------------------------
# Constants and helpers shared across tests
# ---------------------------------------------------------------------------


SHARED_SECRET = "auth-test-shared-secret"
KEY_VERSION = "v1"
FROZEN_NOW = 1_900_000_000  # well before the default token expiry

VALID_CLAIMS: dict[str, Any] = {
    "user_id": 17,
    "username": "dr.smith",
    "patient_id": 42,
    "encounter_id": 100,
    "allowed_tools": ["get_basic_patient_data"],
    "allowed_source_types": ["patient"],
    "max_rows": 50,
    "lookback_days": 365,
    "expires_at": FROZEN_NOW + 600,
    "request_id": "req-1234-5678",
    "trace_id": "trace-abcd-efgh",
    "key_version": KEY_VERSION,
}


def _b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def _mint(claims: dict[str, Any], secret: str) -> str:
    """Minimal mirror of the PHP minter for deterministic test tokens."""
    payload_bytes = json.dumps(
        claims,
        separators=(",", ":"),
        sort_keys=True,
        ensure_ascii=False,
    ).encode("utf-8")
    signature = hmac.new(secret.encode("utf-8"), payload_bytes, hashlib.sha256).digest()
    return f"{_b64url(payload_bytes)}.{_b64url(signature)}"


def _resolver_for(version_to_secret: dict[str, str]) -> SecretResolver:
    def _resolve(version: str) -> str | None:
        return version_to_secret.get(version)

    return _resolve


def _request_payload(**overrides: Any) -> dict[str, Any]:
    """Return a syntactically valid request payload."""
    payload: dict[str, Any] = {
        "run_context": _mint(VALID_CLAIMS, SHARED_SECRET),
        "intent_id": "current_medications",
        "user_goal": None,
        "request_id": "11111111-2222-4333-8444-555555555555",
        "conversation_state": None,
    }
    payload.update(overrides)
    return payload


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> None:
    from agent_service.config import get_settings

    get_settings.cache_clear()


@pytest.fixture()
def _env() -> Iterator[None]:
    with mock.patch.dict(
        os.environ,
        {"AGENT_SHARED_SECRET": SHARED_SECRET},
        clear=False,
    ):
        yield


@pytest.fixture()
def settings_override(_env: None) -> Settings:
    """Build a Settings with the test secret, decoupled from the LRU cache."""
    return Settings(
        agent_shared_secret=SHARED_SECRET,
        openai_api_key="",
        cohere_api_key="",
        honeycomb_api_key="",
        debug=False,
        log_level="INFO",
    )


@pytest.fixture()
def client(settings_override: Settings) -> Iterator[TestClient]:
    """TestClient with a deterministic clock and settings injected."""
    from agent_service.main import app

    def _override_settings() -> Settings:
        return settings_override

    def _override_resolver() -> SecretResolver:
        return _resolver_for({KEY_VERSION: SHARED_SECRET})

    def _override_clock() -> Callable[[], int]:
        return lambda: FROZEN_NOW

    app.dependency_overrides[get_settings_dep] = _override_settings
    app.dependency_overrides[get_secret_resolver] = _override_resolver
    app.dependency_overrides[get_clock] = _override_clock
    try:
        yield TestClient(app, raise_server_exceptions=False)
    finally:
        app.dependency_overrides.pop(get_settings_dep, None)
        app.dependency_overrides.pop(get_secret_resolver, None)
        app.dependency_overrides.pop(get_clock, None)


# ---------------------------------------------------------------------------
# Public health route must remain unauthenticated
# ---------------------------------------------------------------------------


class TestHealthzIsPublic:
    def test_healthz_returns_200_without_run_context(self, client: TestClient) -> None:
        resp = client.get("/healthz")
        assert resp.status_code == 200
        assert resp.json() == {"status": "ok"}


# ---------------------------------------------------------------------------
# Body validation runs before the auth dependency
# ---------------------------------------------------------------------------


class TestRequestBodyValidation:
    def test_missing_run_context_returns_422(self, client: TestClient) -> None:
        payload = _request_payload()
        del payload["run_context"]

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 422

    def test_neither_intent_nor_goal_returns_422(self, client: TestClient) -> None:
        # Schema validator rejects the body before the auth dependency runs,
        # so this is 422, not 401.
        payload = _request_payload(intent_id=None, user_goal=None)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# Negative auth paths -- each maps to a fail-closed 401
# ---------------------------------------------------------------------------


class TestRunContextRejection:
    def test_malformed_token_returns_401_invalid(self, client: TestClient) -> None:
        payload = _request_payload(run_context="not.a.real.token")

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"
        assert body["reason"] == "malformed"
        # The wire token must never be echoed back.
        assert "not.a.real.token" not in resp.text

    def test_completely_garbage_token_returns_401(self, client: TestClient) -> None:
        payload = _request_payload(run_context="this-is-not-a-token")

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"

    def test_tampered_token_returns_401_invalid(self, client: TestClient) -> None:
        # Build a token, then swap the signed payload for a different one
        # while keeping the original signature -- classic tamper attack.
        original = _mint(VALID_CLAIMS, SHARED_SECRET)
        original_signature = original.split(".")[1]

        tampered_claims = {**VALID_CLAIMS, "patient_id": 9_999}
        tampered_payload = json.dumps(
            tampered_claims,
            separators=(",", ":"),
            sort_keys=True,
            ensure_ascii=False,
        ).encode("utf-8")
        tampered_token = f"{_b64url(tampered_payload)}.{original_signature}"

        payload = _request_payload(run_context=tampered_token)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"
        assert body["reason"] == "tampered"

    def test_wrong_secret_returns_401_invalid(self, client: TestClient) -> None:
        # Token signed with a secret the server does not know.
        bad_token = _mint(VALID_CLAIMS, "wrong-secret-not-on-server")
        payload = _request_payload(run_context=bad_token)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"
        assert body["reason"] == "tampered"

    def test_unknown_key_version_returns_401_invalid(self, client: TestClient) -> None:
        # Mint with a key_version the resolver does not understand.
        future_claims = {**VALID_CLAIMS, "key_version": "v999-future"}
        bad_token = _mint(future_claims, SHARED_SECRET)
        payload = _request_payload(run_context=bad_token)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"
        assert body["reason"] == "unknown_key_version"

    def test_expired_token_returns_401_expired(self, client: TestClient) -> None:
        expired_claims = {**VALID_CLAIMS, "expires_at": FROZEN_NOW - 60}
        expired_token = _mint(expired_claims, SHARED_SECRET)
        payload = _request_payload(run_context=expired_token)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "expired_run_context"
        # Expired tokens get their own discriminator -- no leak of "reason".
        assert "reason" not in body


# ---------------------------------------------------------------------------
# Positive auth paths -- valid token reaches the stub handler
# ---------------------------------------------------------------------------


class TestRunContextAccepted:
    def test_valid_token_with_intent_id_reaches_stub(self, client: TestClient) -> None:
        resp = client.post("/api/copilot/run", json=_request_payload())

        assert resp.status_code == 501
        body = resp.json()
        assert body["error"] == "not_implemented"
        assert "M13" in body["message"] or "stub" in body["message"].lower()

    def test_valid_token_with_user_goal_reaches_stub(self, client: TestClient) -> None:
        payload = _request_payload(
            intent_id=None,
            user_goal="What are this patient's active allergies?",
        )

        resp = client.post("/api/copilot/run", json=payload)

        assert resp.status_code == 501
        body = resp.json()
        assert body["error"] == "not_implemented"

    def test_response_body_never_echoes_the_wire_token(self, client: TestClient) -> None:
        # Sanity check: the success path must not include the signed
        # token, even indirectly.
        payload = _request_payload()
        resp = client.post("/api/copilot/run", json=payload)

        assert payload["run_context"] not in resp.text
