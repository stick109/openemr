"""Contract tests for the M2 ``POST /api/copilot/run`` wire schemas.

These tests exercise only schema validation and round-trip serialization.
They deliberately do **not** decode the ``run_context`` token (that is the
M3 / M4 verifier's job) and do **not** hit the agent loop (M13).  The
endpoint stub is also smoke-tested to confirm the route is wired and
returns HTTP 501 when given a syntactically valid request.
"""

from __future__ import annotations

import os
from typing import Any
from unittest import mock

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from agent_service.schemas.copilot import (
    AnswerBlock,
    Citation,
    CopilotRunRequest,
    CopilotRunResponse,
    ToolCallRecord,
    USER_GOAL_MAX_CHARS,
)

SHARED_SECRET = "test-secret-value"


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> None:
    """Clear the cached Settings singleton before every test."""
    from agent_service.config import get_settings

    get_settings.cache_clear()


@pytest.fixture()
def _env() -> None:
    """Patch the environment with a valid required secret."""
    with mock.patch.dict(
        os.environ,
        {"AGENT_SHARED_SECRET": SHARED_SECRET},
        clear=False,
    ):
        yield  # type: ignore[misc]


@pytest.fixture()
def client(_env: None) -> TestClient:
    """Return a ``TestClient`` backed by the FastAPI app."""
    from agent_service.main import app

    return TestClient(app, raise_server_exceptions=False)


def _valid_request_payload(**overrides: Any) -> dict[str, Any]:
    """Return a minimal valid request payload with optional overrides."""
    payload: dict[str, Any] = {
        "run_context": "signed.token.opaque",
        "intent_id": "current_medications",
        "user_goal": None,
        "request_id": "11111111-2222-4333-8444-555555555555",
        "conversation_state": None,
    }
    payload.update(overrides)
    return payload


# ---------------------------------------------------------------------------
# Request schema validation
# ---------------------------------------------------------------------------


class TestCopilotRunRequest:
    def test_valid_request_with_intent_id_is_accepted(self) -> None:
        request = CopilotRunRequest.model_validate(_valid_request_payload())
        assert request.run_context == "signed.token.opaque"
        assert request.intent_id == "current_medications"
        assert request.user_goal is None

    def test_valid_request_with_user_goal_is_accepted(self) -> None:
        payload = _valid_request_payload(
            intent_id=None,
            user_goal="What are this patient's active allergies?",
        )
        request = CopilotRunRequest.model_validate(payload)
        assert request.intent_id is None
        assert request.user_goal == "What are this patient's active allergies?"

    def test_missing_run_context_is_rejected(self) -> None:
        payload = _valid_request_payload()
        del payload["run_context"]

        with pytest.raises(ValidationError) as excinfo:
            CopilotRunRequest.model_validate(payload)

        errors = excinfo.value.errors()
        assert any(err["loc"] == ("run_context",) for err in errors)

    def test_empty_run_context_is_rejected(self) -> None:
        with pytest.raises(ValidationError):
            CopilotRunRequest.model_validate(_valid_request_payload(run_context=""))

    def test_neither_intent_nor_goal_is_rejected(self) -> None:
        payload = _valid_request_payload(intent_id=None, user_goal=None)

        with pytest.raises(ValidationError) as excinfo:
            CopilotRunRequest.model_validate(payload)

        message = str(excinfo.value)
        assert "intent_id" in message
        assert "user_goal" in message

    def test_blank_intent_and_blank_goal_is_rejected(self) -> None:
        payload = _valid_request_payload(intent_id="   ", user_goal="   ")

        with pytest.raises(ValidationError):
            CopilotRunRequest.model_validate(payload)

    def test_user_goal_over_cap_is_rejected(self) -> None:
        oversized = "x" * (USER_GOAL_MAX_CHARS + 1)
        payload = _valid_request_payload(intent_id=None, user_goal=oversized)

        with pytest.raises(ValidationError) as excinfo:
            CopilotRunRequest.model_validate(payload)

        errors = excinfo.value.errors()
        assert any(err["loc"] == ("user_goal",) for err in errors)

    def test_user_goal_at_cap_is_accepted(self) -> None:
        at_cap = "x" * USER_GOAL_MAX_CHARS
        payload = _valid_request_payload(intent_id=None, user_goal=at_cap)

        request = CopilotRunRequest.model_validate(payload)
        assert request.user_goal is not None
        assert len(request.user_goal) == USER_GOAL_MAX_CHARS

    def test_extra_field_is_rejected(self) -> None:
        payload = _valid_request_payload(forbidden_field="oops")

        with pytest.raises(ValidationError) as excinfo:
            CopilotRunRequest.model_validate(payload)

        errors = excinfo.value.errors()
        assert any(err["type"] == "extra_forbidden" for err in errors)

    def test_empty_request_id_is_rejected(self) -> None:
        with pytest.raises(ValidationError):
            CopilotRunRequest.model_validate(_valid_request_payload(request_id=""))

    def test_conversation_state_is_passed_through(self) -> None:
        state = {"page": 2, "prior_intent": "allergies_to_confirm"}
        payload = _valid_request_payload(conversation_state=state)

        request = CopilotRunRequest.model_validate(payload)
        assert request.conversation_state == state


# ---------------------------------------------------------------------------
# Response schema round-trip
# ---------------------------------------------------------------------------


def _sample_response_dict() -> dict[str, Any]:
    """Return a hand-authored sample response payload."""
    return {
        "answer_blocks": [
            {
                "type": "paragraph",
                "content": "Patient is on lisinopril 10 mg daily.",
                "citation_indices": [0],
            },
            {
                "type": "list",
                "content": "- Lisinopril 10 mg PO daily\n- Atorvastatin 20 mg PO nightly",
                "citation_indices": [0, 1],
            },
        ],
        "missing_or_uncertain": [
            "Last refill date for atorvastatin not confirmed.",
        ],
        "citations": [
            {
                "source_type": "patient_record",
                "source_id": "med:1234",
                "label": "Active medication list",
                "url": None,
                "snippet": None,
            },
            {
                "source_type": "guideline",
                "source_id": "chunk:hypertension-2024-12",
                "label": "ACC/AHA hypertension guideline",
                "url": "https://example.org/guideline/hypertension",
                "snippet": "First-line therapy for stage 1 hypertension...",
            },
        ],
        "tool_sequence": [
            {
                "tool_name": "list_active_medications",
                "arguments_keys": ["lookback_days"],
                "result_count": 2,
                "latency_ms": 42,
                "error_class": None,
            },
            {
                "tool_name": "search_guidelines",
                "arguments_keys": ["query", "top_k"],
                "result_count": 5,
                "latency_ms": 118,
                "error_class": None,
            },
        ],
        "verification_status": "passed",
        "cost_usd": 0.0123,
        "latency_ms_per_step": {
            "plan": 14,
            "tool_calls": 160,
            "verify": 22,
        },
        "trace_id": "aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee",
    }


class TestCopilotRunResponse:
    def test_round_trip_preserves_all_fields(self) -> None:
        sample = _sample_response_dict()
        response = CopilotRunResponse.model_validate(sample)

        # Assert the structured Python objects round-trip the dict shape.
        dumped = response.model_dump()
        assert dumped == sample

    def test_answer_blocks_keep_citation_indices(self) -> None:
        sample = _sample_response_dict()
        response = CopilotRunResponse.model_validate(sample)

        assert response.answer_blocks[0].citation_indices == [0]
        assert response.answer_blocks[1].citation_indices == [0, 1]

    def test_tool_sequence_omits_argument_values(self) -> None:
        sample = _sample_response_dict()
        response = CopilotRunResponse.model_validate(sample)

        # Confirm the schema only carries keys for tool arguments -- never
        # values.  This is the central PHI-safety invariant of the contract.
        for record in response.tool_sequence:
            assert isinstance(record, ToolCallRecord)
            assert all(isinstance(k, str) for k in record.arguments_keys)
        dumped = response.model_dump()["tool_sequence"]
        for record in dumped:
            assert "arguments" not in record
            assert "argument_values" not in record

    def test_verification_status_must_be_known_literal(self) -> None:
        sample = _sample_response_dict()
        sample["verification_status"] = "weird"

        with pytest.raises(ValidationError):
            CopilotRunResponse.model_validate(sample)

    def test_cost_usd_must_be_non_negative(self) -> None:
        sample = _sample_response_dict()
        sample["cost_usd"] = -0.01

        with pytest.raises(ValidationError):
            CopilotRunResponse.model_validate(sample)

    def test_extra_field_is_rejected(self) -> None:
        sample = _sample_response_dict()
        sample["surprise"] = "boom"

        with pytest.raises(ValidationError):
            CopilotRunResponse.model_validate(sample)


# ---------------------------------------------------------------------------
# Sub-schema validation
# ---------------------------------------------------------------------------


class TestSubSchemas:
    def test_answer_block_requires_type_and_content(self) -> None:
        with pytest.raises(ValidationError):
            AnswerBlock.model_validate({"content": "hi"})

    def test_citation_url_and_snippet_optional(self) -> None:
        citation = Citation.model_validate(
            {
                "source_type": "guideline",
                "source_id": "chunk:1",
                "label": "Source",
            }
        )
        assert citation.url is None
        assert citation.snippet is None

    def test_tool_call_record_negative_latency_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ToolCallRecord.model_validate(
                {
                    "tool_name": "x",
                    "arguments_keys": [],
                    "result_count": 0,
                    "latency_ms": -1,
                    "error_class": None,
                }
            )

    def test_tool_call_record_optional_result_count(self) -> None:
        record = ToolCallRecord.model_validate(
            {
                "tool_name": "x",
                "arguments_keys": [],
                "result_count": None,
                "latency_ms": 0,
                "error_class": None,
            }
        )
        assert record.result_count is None


# ---------------------------------------------------------------------------
# Endpoint stub
# ---------------------------------------------------------------------------


class TestCopilotEndpointStub:
    def test_unsigned_request_returns_401(self, client: TestClient) -> None:
        # M4 wires the signed-context verifier into the route. A request
        # with a placeholder token now fails closed at 401 before it can
        # reach the M13 stub body. The "valid signed request reaches the
        # 501 stub" path is exercised in tests/test_copilot_auth.py
        # where tokens are minted with the test secret.
        resp = client.post("/api/copilot/run", json=_valid_request_payload())

        assert resp.status_code == 401
        body = resp.json()
        assert body["error"] == "invalid_run_context"

    def test_invalid_request_returns_422(self, client: TestClient) -> None:
        # Missing both intent_id and user_goal -- should fail validation
        # before reaching the stub body.
        payload = _valid_request_payload(intent_id=None, user_goal=None)

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 422

    def test_missing_run_context_returns_422(self, client: TestClient) -> None:
        payload = _valid_request_payload()
        del payload["run_context"]

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 422

    def test_extra_field_returns_422(self, client: TestClient) -> None:
        payload = _valid_request_payload(rogue_field="x")

        resp = client.post("/api/copilot/run", json=payload)
        assert resp.status_code == 422
