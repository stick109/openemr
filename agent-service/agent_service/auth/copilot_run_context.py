"""Verifier for the signed CopilotRunContext minted by the PHP host (M3).

The PHP minter (``OpenEMR\\Services\\Agent\\Copilot\\CopilotRunContext``)
emits a JWT-like compact wire string::

    <base64url(canonical_payload_json)>.<base64url(hmac_sha256_signature)>

The payload is canonical JSON: object keys sorted lexicographically at every
level, no insignificant whitespace, ``ensure_ascii=False`` (i.e. unescaped
unicode), unescaped slashes. PHP and Python therefore produce byte-identical
HMAC inputs.

Verification semantics:

* Resolve the secret to use via the ``key_version`` claim (callers supply a
  ``secret_resolver``). Unknown versions are rejected -- the verifier never
  silently falls back.
* Recompute HMAC-SHA256 and compare with :func:`hmac.compare_digest` to
  prevent timing oracles.
* Reject expired tokens (``expires_at < now()``). The clock is injected so
  tests are deterministic.
* Reject malformed wire strings, missing/empty signature segments, missing
  required claims, and tampered payloads.

The verifier returns a Pydantic v2 :class:`CopilotRunContext` on success;
all error paths raise :class:`CopilotRunContextError` with a typed
``reason`` so callers can map failures to fail-closed responses.
"""

from __future__ import annotations

import base64
import binascii
import hashlib
import hmac
import json
from collections.abc import Callable
from dataclasses import dataclass
from datetime import datetime, timezone
from enum import StrEnum
from typing import Annotated, Any

from pydantic import BaseModel, ConfigDict, Field, field_validator


# ---------------------------------------------------------------------------
# Errors
# ---------------------------------------------------------------------------


class CopilotRunContextErrorReason(StrEnum):
    """Discriminator for verification failures.

    The values map to the reasons enumerated by the M3 spec: ``expired``,
    ``tampered``, ``bad_signature``, ``unknown_key_version``, ``malformed``.
    """

    EXPIRED = "expired"
    TAMPERED = "tampered"
    BAD_SIGNATURE = "bad_signature"
    UNKNOWN_KEY_VERSION = "unknown_key_version"
    MALFORMED = "malformed"


class CopilotRunContextError(Exception):
    """Raised when a CopilotRunContext token fails verification.

    Carries a machine-readable :class:`CopilotRunContextErrorReason` so the
    sidecar can return a fail-closed response without leaking which check
    failed at the message-string level.
    """

    def __init__(self, reason: CopilotRunContextErrorReason, message: str) -> None:
        super().__init__(message)
        self.reason: CopilotRunContextErrorReason = reason


# ---------------------------------------------------------------------------
# Validated claim model
# ---------------------------------------------------------------------------


class CopilotRunContext(BaseModel):
    """Validated authority claims unpacked from a verified token.

    Every field corresponds to a claim minted by the PHP host. The model is
    strict: extra keys are rejected and types are not coerced. The Pydantic
    validators enforce shape invariants that mirror the PHP value object.
    """

    model_config = ConfigDict(extra="forbid", strict=True, frozen=True)

    user_id: Annotated[int, Field(gt=0, description="OpenEMR user ID")]
    username: Annotated[str, Field(min_length=1, description="OpenEMR username")]
    patient_id: Annotated[int, Field(gt=0, description="OpenEMR patient PID")]
    encounter_id: int | None = Field(default=None, description="OpenEMR encounter ID, or null")
    allowed_tools: list[str] = Field(description="Names of tools this run may invoke")
    allowed_source_types: list[str] = Field(description="Source-type filters allowed for this run")
    max_rows: Annotated[int, Field(gt=0, description="Per-tool row cap")]
    lookback_days: Annotated[int, Field(gt=0, description="Per-tool history window")]
    expires_at: Annotated[int, Field(gt=0, description="Unix timestamp when this token expires")]
    request_id: Annotated[str, Field(min_length=1, description="Per-request UUID")]
    trace_id: Annotated[str, Field(min_length=1, description="Distributed trace correlation ID")]
    key_version: Annotated[str, Field(min_length=1, description="Secret key generation, e.g. 'v1'")]

    @field_validator("encounter_id")
    @classmethod
    def _encounter_id_must_be_positive_when_set(cls, value: int | None) -> int | None:
        if value is not None and value <= 0:
            msg = "encounter_id must be positive when set"
            raise ValueError(msg)
        return value

    @field_validator("allowed_tools", "allowed_source_types")
    @classmethod
    def _strings_must_be_non_empty(cls, value: list[str]) -> list[str]:
        for item in value:
            if not isinstance(item, str) or item == "":
                msg = "list entries must be non-empty strings"
                raise ValueError(msg)
        return value


# ---------------------------------------------------------------------------
# Verifier
# ---------------------------------------------------------------------------


SecretResolver = Callable[[str], str | None]
"""Maps a ``key_version`` to its shared secret, or ``None`` when unknown.

Returning ``None`` is the canonical way to reject an unknown key version.
The verifier raises :class:`CopilotRunContextError` with reason
``unknown_key_version`` when the resolver returns ``None`` or empty string.
"""


@dataclass(frozen=True, slots=True)
class _DecodedWire:
    """Internal split of a wire token into its three structural pieces."""

    payload_bytes: bytes
    signature_bytes: bytes
    claims: dict[str, Any]


def _utc_now_seconds() -> int:
    """Return the current UTC time as an int unix timestamp.

    Defined at module scope so tests can monkeypatch a deterministic clock
    without instantiating any fixtures.
    """
    return int(datetime.now(tz=timezone.utc).timestamp())


def _b64url_decode(segment: str) -> bytes:
    """Decode an unpadded base64url segment, padding internally."""
    padding = (-len(segment)) % 4
    try:
        return base64.urlsafe_b64decode(segment + ("=" * padding))
    except (binascii.Error, ValueError) as exc:
        msg = "wire segment is not valid base64url"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg) from exc


def _canonical_payload(claims: dict[str, Any]) -> bytes:
    """Re-encode claims to canonical JSON for signature verification.

    Matches the PHP minter: sorted keys, no whitespace, unicode unescaped.
    Lists preserve order; only dicts have keys sorted (``sort_keys=True``
    handles every nesting level).
    """
    return json.dumps(
        claims,
        separators=(",", ":"),
        sort_keys=True,
        ensure_ascii=False,
    ).encode("utf-8")


def _split_wire(wire: str) -> _DecodedWire:
    """Split a wire string into payload + signature, decoding both."""
    if not isinstance(wire, str) or wire == "":
        msg = "wire token must be a non-empty string"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    parts = wire.split(".")
    if len(parts) != 2:
        msg = "wire format must be <payload>.<signature>"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    payload_segment, signature_segment = parts
    if payload_segment == "" or signature_segment == "":
        msg = "wire payload or signature segment is empty"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    payload_bytes = _b64url_decode(payload_segment)
    signature_bytes = _b64url_decode(signature_segment)

    try:
        decoded = json.loads(payload_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        msg = "payload is not valid JSON"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg) from exc

    if not isinstance(decoded, dict):
        msg = "payload must decode to a JSON object"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    return _DecodedWire(
        payload_bytes=payload_bytes,
        signature_bytes=signature_bytes,
        claims=decoded,
    )


def verify_copilot_run_context(
    wire: str,
    secret_resolver: SecretResolver,
    *,
    now: Callable[[], int] = _utc_now_seconds,
) -> CopilotRunContext:
    """Verify and parse a CopilotRunContext wire token.

    Parameters
    ----------
    wire:
        The compact wire string produced by the PHP minter.
    secret_resolver:
        Callable invoked with the ``key_version`` claim; must return the
        shared secret bytes-as-str, or ``None`` to signal "unknown version".
    now:
        Injected clock returning the current unix timestamp. Defaults to
        UTC ``time.time()``-equivalent. Tests pass a deterministic stub.

    Returns
    -------
    CopilotRunContext
        The validated, frozen Pydantic model.

    Raises
    ------
    CopilotRunContextError
        With ``reason`` set to one of:
        ``malformed``, ``unknown_key_version``, ``bad_signature``,
        ``tampered``, or ``expired``. Callers should map every reason to a
        fail-closed 401/403 response and never leak the wire claims to the
        client.
    """
    decoded = _split_wire(wire)
    claims = decoded.claims

    key_version = claims.get("key_version")
    if not isinstance(key_version, str) or key_version == "":
        msg = "missing or empty key_version claim"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    secret = secret_resolver(key_version)
    if not secret:
        msg = f"unknown key_version: {key_version!r}"
        raise CopilotRunContextError(CopilotRunContextErrorReason.UNKNOWN_KEY_VERSION, msg)

    expected_signature = hmac.new(
        secret.encode("utf-8"),
        _canonical_payload(claims),
        hashlib.sha256,
    ).digest()

    if not hmac.compare_digest(expected_signature, decoded.signature_bytes):
        # We cannot tell whether the payload was modified or the secret was
        # wrong without distinguishing trust models, so we default to the
        # "tampered" reason. Callers can decide whether to differentiate.
        msg = "signature does not match payload + secret"
        raise CopilotRunContextError(CopilotRunContextErrorReason.TAMPERED, msg)

    expires_at = claims.get("expires_at")
    if not isinstance(expires_at, int) or expires_at <= 0:
        msg = "missing or invalid expires_at claim"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg)

    if expires_at < now():
        msg = "token expired"
        raise CopilotRunContextError(CopilotRunContextErrorReason.EXPIRED, msg)

    try:
        return CopilotRunContext.model_validate(claims)
    except ValueError as exc:
        # Pydantic validation failure on a token that already passed HMAC
        # means the structure was malformed at mint time.
        msg = f"claims failed schema validation: {exc}"
        raise CopilotRunContextError(CopilotRunContextErrorReason.MALFORMED, msg) from exc
