"""FastAPI dependency providers for the copilot routes (M4).

This module wires the :func:`verify_copilot_run_context` function (M3)
into FastAPI as an endpoint-level dependency. The dependency runs
**before** any route body, so unsigned, expired, tampered, or
unknown-key requests are rejected with a fail-closed 401 before the
agent loop or any tool execution can begin.

Three small provider callables are exposed -- :func:`get_settings_dep`,
:func:`get_secret_resolver`, :func:`get_clock` -- so tests can override
each one independently via ``app.dependency_overrides``.
``get_settings`` is intentionally distinct from
:func:`agent_service.config.get_settings`: the latter reads env vars at
import time and is cached, whereas this provider can be swapped for a
fixture-supplied :class:`Settings` instance.

Security
--------
* The wire token is **never logged** and **never returned** in the HTTP
  response. On verifier failure we surface only the typed ``reason``.
* On any unexpected exception we collapse to a generic 500 response so
  internal details (file paths, stack frames) cannot leak.
* Logging hooks for observability arrive in M16 -- a TODO marker is
  left where the logger call will live.
"""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone
from typing import Annotated

from fastapi import Depends, HTTPException

from agent_service.auth import (
    CopilotRunContext,
    CopilotRunContextError,
    make_secret_resolver,
    verify_copilot_run_context,
)
from agent_service.auth.copilot_run_context import (
    CopilotRunContextErrorReason,
    SecretResolver,
)
from agent_service.config import Settings, get_settings
from agent_service.schemas.copilot import CopilotRunRequest


# ---------------------------------------------------------------------------
# Provider primitives -- the three units that tests override
# ---------------------------------------------------------------------------


def get_settings_dep() -> Settings:
    """Return application settings.

    Wraps :func:`agent_service.config.get_settings` so tests can override
    the dependency without touching the LRU cache on the real one.
    """
    return get_settings()


def get_secret_resolver(
    settings: Annotated[Settings, Depends(get_settings_dep)],
) -> SecretResolver:
    """Return a secret resolver bound to the current settings.

    The default resolver only knows ``"v1"``. Key rotation in later
    milestones extends :mod:`agent_service.auth.secret_resolver` rather
    than this provider.
    """
    return make_secret_resolver(settings)


def get_clock() -> Callable[[], int]:
    """Return a callable that yields the current UTC unix timestamp.

    Wrapped in a provider so tests can pin time via
    ``app.dependency_overrides[get_clock] = lambda: lambda: <fixed>``.
    """

    def _now() -> int:
        return int(datetime.now(tz=timezone.utc).timestamp())

    return _now


# ---------------------------------------------------------------------------
# Reason -> HTTP error mapping
# ---------------------------------------------------------------------------


def _http_error_for(reason: CopilotRunContextErrorReason) -> HTTPException:
    """Translate a verifier reason into a fail-closed HTTPException.

    Expired tokens get their own ``error`` discriminator so the UI can
    nudge the user to refresh; every other failure collapses to a
    generic ``invalid_run_context`` to avoid disclosing whether a check
    failed at HMAC, schema, or key-resolution time.
    """
    if reason is CopilotRunContextErrorReason.EXPIRED:
        return HTTPException(
            status_code=401,
            detail={"error": "expired_run_context"},
        )
    return HTTPException(
        status_code=401,
        detail={"error": "invalid_run_context", "reason": reason.value},
    )


# ---------------------------------------------------------------------------
# Public dependency -- attach to any endpoint that needs a verified context
# ---------------------------------------------------------------------------


def require_copilot_run_context(
    request_body: CopilotRunRequest,
    secret_resolver: Annotated[SecretResolver, Depends(get_secret_resolver)],
    clock: Annotated[Callable[[], int], Depends(get_clock)],
) -> CopilotRunContext:
    """Verify the request's ``run_context`` and yield the parsed context.

    The ``request_body`` parameter name is intentionally aligned with the
    route handler so FastAPI dedupes the body field across the dependency
    and the route.

    Parameters
    ----------
    request_body:
        The validated request body. Pydantic has already enforced shape
        and presence of ``run_context``; this dependency only deals with
        the cryptographic / temporal validity of the token.
    secret_resolver:
        Resolves the ``key_version`` claim to a shared secret. Injected
        so tests can substitute a deterministic resolver.
    clock:
        Returns the current unix timestamp. Injected for deterministic
        time control in tests.

    Returns
    -------
    CopilotRunContext
        The validated, frozen claim model. Routes use this -- never the
        raw wire token -- to drive tool selection and PHI scope.

    Raises
    ------
    HTTPException
        * 401 ``invalid_run_context`` for malformed / tampered /
          bad-signature / unknown-key-version tokens.
        * 401 ``expired_run_context`` for expired tokens.
        * 500 ``internal_error`` for any unexpected exception. The
          original cause is suppressed from the response body.
    """
    try:
        return verify_copilot_run_context(
            request_body.run_context,
            secret_resolver,
            now=clock,
        )
    except CopilotRunContextError as exc:
        raise _http_error_for(exc.reason) from exc
    except Exception as exc:
        # TODO(M16): emit a PHI-safe structured log here. Until then we
        # collapse to a generic 500 so internal state cannot leak via
        # exception messages or stack traces in the response body.
        raise HTTPException(
            status_code=500,
            detail={"error": "internal_error"},
        ) from exc


__all__ = [
    "get_clock",
    "get_secret_resolver",
    "get_settings_dep",
    "require_copilot_run_context",
]
