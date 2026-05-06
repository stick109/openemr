"""Tests for the CopilotRunContext verifier (M3).

Covers all pass criteria from the migration spec:

* PHP-minted token round-trips correctly.
* Tampered payload is rejected.
* Wrong secret is rejected.
* Expired token is rejected.
* Missing signature segment is rejected.
* Unknown ``key_version`` is rejected.

The PHP-minted fixture (``PHP_MINTED_TOKEN``) below is captured by running
the PHP minter once via the helper documented in the constant's docstring.
This avoids requiring a PHP runtime in CI while still exercising the real
cross-language wire format.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
from typing import Any

import pytest

from agent_service.auth import (
    CopilotRunContext,
    CopilotRunContextError,
    verify_copilot_run_context,
)
from agent_service.auth.copilot_run_context import CopilotRunContextErrorReason


# ---------------------------------------------------------------------------
# Fixtures shared across tests
# ---------------------------------------------------------------------------


SECRET = "fixture-secret-for-cross-language-tests"
KEY_VERSION = "v1"


# A token minted by the PHP value object
# ``OpenEMR\Services\Agent\Copilot\CopilotRunContext::mint(...)`` over the
# claims defined in :data:`PHP_MINTED_CLAIMS` with the secret in
# :data:`SECRET`. This proves PHP <-> Python wire compatibility without
# requiring a PHP runtime in CI.
#
# Regeneration procedure (one-shot, run from the openemr repo root):
#
#   1. Save this script as ``mint_fixture.php`` next to ``composer.json``::
#
#       <?php
#       declare(strict_types=1);
#       require __DIR__ . "/vendor/autoload.php";
#       use OpenEMR\Services\Agent\Copilot\CopilotRunContext;
#       echo CopilotRunContext::mint(
#           [
#               "user_id" => 17,
#               "username" => "dr.smith",
#               "patient_id" => 42,
#               "encounter_id" => 100,
#               "allowed_tools" => ["get_basic_patient_data", "get_current_medications"],
#               "allowed_source_types" => ["patient", "medication"],
#               "max_rows" => 50,
#               "lookback_days" => 365,
#               "expires_at" => 1900000000,
#               "request_id" => "req-1234-5678",
#               "trace_id" => "trace-abcd-efgh",
#           ],
#           "fixture-secret-for-cross-language-tests",
#           "v1",
#       );
#
#   2. Run ``php mint_fixture.php`` and copy stdout into ``PHP_MINTED_TOKEN``.
#   3. Delete ``mint_fixture.php``.
PHP_MINTED_TOKEN = (
    "eyJhbGxvd2VkX3NvdXJjZV90eXBlcyI6WyJwYXRpZW50IiwibWVkaWNhdGlvbiJdLCJhbGxvd2VkX3Rvb2xzIjpb"
    "ImdldF9iYXNpY19wYXRpZW50X2RhdGEiLCJnZXRfY3VycmVudF9tZWRpY2F0aW9ucyJdLCJlbmNvdW50ZXJfaWQi"
    "OjEwMCwiZXhwaXJlc19hdCI6MTkwMDAwMDAwMCwia2V5X3ZlcnNpb24iOiJ2MSIsImxvb2tiYWNrX2RheXMiOjM2"
    "NSwibWF4X3Jvd3MiOjUwLCJwYXRpZW50X2lkIjo0MiwicmVxdWVzdF9pZCI6InJlcS0xMjM0LTU2NzgiLCJ0cmFj"
    "ZV9pZCI6InRyYWNlLWFiY2QtZWZnaCIsInVzZXJfaWQiOjE3LCJ1c2VybmFtZSI6ImRyLnNtaXRoIn0"
    ".-0mzS4X97qL-iqxBwmLyWGCKJU_sPyBbYTVr21lAlhg"
)

PHP_MINTED_CLAIMS: dict[str, Any] = {
    "user_id": 17,
    "username": "dr.smith",
    "patient_id": 42,
    "encounter_id": 100,
    "allowed_tools": ["get_basic_patient_data", "get_current_medications"],
    "allowed_source_types": ["patient", "medication"],
    "max_rows": 50,
    "lookback_days": 365,
    "expires_at": 1_900_000_000,
    "request_id": "req-1234-5678",
    "trace_id": "trace-abcd-efgh",
    "key_version": "v1",
}


def _frozen_clock_before_expiry() -> int:
    """Deterministic clock that always reports a time before token expiry."""
    return PHP_MINTED_CLAIMS["expires_at"] - 60


def _frozen_clock_after_expiry() -> int:
    return PHP_MINTED_CLAIMS["expires_at"] + 60


def _resolver(known_versions: dict[str, str]):
    """Return a secret_resolver that consults a fixed map."""

    def _resolve(version: str) -> str | None:
        return known_versions.get(version)

    return _resolve


def _b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def _python_mint(claims: dict[str, Any], secret: str) -> str:
    """Minimal Python re-implementation of the PHP minter.

    Used inside tests to construct adversarial tokens (tampered payload,
    wrong secret, etc.) without coupling those tests to the PHP runtime.
    Behaves identically to the PHP minter for canonical-JSON encoding.
    """
    payload_bytes = json.dumps(
        claims,
        separators=(",", ":"),
        sort_keys=True,
        ensure_ascii=False,
    ).encode("utf-8")
    signature = hmac.new(secret.encode("utf-8"), payload_bytes, hashlib.sha256).digest()
    return f"{_b64url(payload_bytes)}.{_b64url(signature)}"


# ---------------------------------------------------------------------------
# Cross-language compatibility (PHP -> Python)
# ---------------------------------------------------------------------------


class TestPhpMintedTokenRoundTrip:
    """Validate that PHP-minted tokens deserialize cleanly in Python."""

    def test_php_minted_token_verifies(self) -> None:
        ctx = verify_copilot_run_context(
            PHP_MINTED_TOKEN,
            secret_resolver=_resolver({KEY_VERSION: SECRET}),
            now=_frozen_clock_before_expiry,
        )

        assert isinstance(ctx, CopilotRunContext)
        assert ctx.user_id == PHP_MINTED_CLAIMS["user_id"]
        assert ctx.username == PHP_MINTED_CLAIMS["username"]
        assert ctx.patient_id == PHP_MINTED_CLAIMS["patient_id"]
        assert ctx.encounter_id == PHP_MINTED_CLAIMS["encounter_id"]
        assert ctx.allowed_tools == PHP_MINTED_CLAIMS["allowed_tools"]
        assert ctx.allowed_source_types == PHP_MINTED_CLAIMS["allowed_source_types"]
        assert ctx.max_rows == PHP_MINTED_CLAIMS["max_rows"]
        assert ctx.lookback_days == PHP_MINTED_CLAIMS["lookback_days"]
        assert ctx.expires_at == PHP_MINTED_CLAIMS["expires_at"]
        assert ctx.request_id == PHP_MINTED_CLAIMS["request_id"]
        assert ctx.trace_id == PHP_MINTED_CLAIMS["trace_id"]
        assert ctx.key_version == PHP_MINTED_CLAIMS["key_version"]

    def test_php_minted_token_rejected_after_expiry(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                PHP_MINTED_TOKEN,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_after_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.EXPIRED


# ---------------------------------------------------------------------------
# Negative paths -- one per failure mode in the M3 spec
# ---------------------------------------------------------------------------


class TestVerifierRejectsBadTokens:
    """Each test asserts a single, distinct rejection reason."""

    def test_rejects_tampered_payload(self) -> None:
        tampered_claims = {**PHP_MINTED_CLAIMS, "patient_id": 9_999}
        # Re-encode payload with the new patient_id but reuse the original
        # signature bytes to simulate an attacker swapping the payload.
        original_signature = PHP_MINTED_TOKEN.split(".")[1]
        tampered_payload = json.dumps(
            tampered_claims,
            separators=(",", ":"),
            sort_keys=True,
            ensure_ascii=False,
        ).encode("utf-8")
        tampered_wire = f"{_b64url(tampered_payload)}.{original_signature}"

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                tampered_wire,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.TAMPERED

    def test_rejects_wrong_secret(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                PHP_MINTED_TOKEN,
                secret_resolver=_resolver({KEY_VERSION: "definitely-not-the-real-secret"}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.TAMPERED

    def test_rejects_unknown_key_version(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                PHP_MINTED_TOKEN,
                secret_resolver=_resolver({"v2-only": SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.UNKNOWN_KEY_VERSION

    def test_rejects_resolver_returning_none(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                PHP_MINTED_TOKEN,
                secret_resolver=lambda _: None,
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.UNKNOWN_KEY_VERSION

    def test_rejects_missing_signature_segment(self) -> None:
        # Strip everything after (and including) the dot.
        no_signature = PHP_MINTED_TOKEN.split(".")[0]

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                no_signature,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_rejects_empty_signature_segment(self) -> None:
        empty_signature = PHP_MINTED_TOKEN.split(".")[0] + "."

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                empty_signature,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_rejects_completely_malformed_string(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                "not-a-token-at-all",
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_rejects_empty_wire(self) -> None:
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                "",
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_rejects_non_base64url_payload(self) -> None:
        # Real signature bytes don't matter -- payload decode fails first.
        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                "###not-base64###.AAAA",
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_rejects_payload_that_is_not_json_object(self) -> None:
        # Encode a JSON array instead of an object.
        bogus_payload = json.dumps([1, 2, 3], separators=(",", ":")).encode("utf-8")
        wire = f"{_b64url(bogus_payload)}.AAAA"

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                wire,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED


# ---------------------------------------------------------------------------
# Python-internal mint/verify symmetry
# ---------------------------------------------------------------------------


class TestPythonMintedSymmetry:
    """The Python helper used for adversarial cases must round-trip too."""

    def test_python_mint_matches_php_mint_byte_for_byte(self) -> None:
        python_minted = _python_mint(PHP_MINTED_CLAIMS, SECRET)
        assert python_minted == PHP_MINTED_TOKEN, (
            "Python and PHP minters must produce identical wire output for the same claims + secret."
        )

    def test_python_minted_token_verifies(self) -> None:
        wire = _python_mint(PHP_MINTED_CLAIMS, SECRET)
        ctx = verify_copilot_run_context(
            wire,
            secret_resolver=_resolver({KEY_VERSION: SECRET}),
            now=_frozen_clock_before_expiry,
        )
        assert ctx.patient_id == PHP_MINTED_CLAIMS["patient_id"]


# ---------------------------------------------------------------------------
# Schema enforcement on already-trusted payloads
# ---------------------------------------------------------------------------


class TestPayloadSchemaEnforcement:
    """Even after HMAC passes, malformed claims are rejected."""

    def test_negative_patient_id_in_signed_payload_is_rejected(self) -> None:
        bad_claims = {**PHP_MINTED_CLAIMS, "patient_id": -1}
        wire = _python_mint(bad_claims, SECRET)

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                wire,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED

    def test_extra_claim_is_rejected_as_malformed(self) -> None:
        bad_claims = {**PHP_MINTED_CLAIMS, "shadow_admin": True}
        wire = _python_mint(bad_claims, SECRET)

        with pytest.raises(CopilotRunContextError) as exc_info:
            verify_copilot_run_context(
                wire,
                secret_resolver=_resolver({KEY_VERSION: SECRET}),
                now=_frozen_clock_before_expiry,
            )
        assert exc_info.value.reason is CopilotRunContextErrorReason.MALFORMED
