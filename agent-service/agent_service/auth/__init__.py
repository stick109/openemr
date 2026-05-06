"""Authentication and authority-context utilities for the agent sidecar.

This package houses the verifier for ``CopilotRunContext`` -- the signed,
short-lived authority token minted by the PHP host and required on every
copilot route. PHP creates the token using HMAC-SHA256 over a canonical
JSON payload; this package validates it.

Public surface:

* :class:`CopilotRunContext` -- Pydantic v2 model of the validated claims.
* :func:`verify_copilot_run_context` -- parse + verify a wire token.
* :class:`CopilotRunContextError` -- raised on any verification failure.
"""

from __future__ import annotations

from agent_service.auth.copilot_run_context import (
    CopilotRunContext,
    CopilotRunContextError,
    verify_copilot_run_context,
)
from agent_service.auth.secret_resolver import (
    SUPPORTED_KEY_VERSION,
    default_secret_resolver,
    make_secret_resolver,
)

__all__ = [
    "SUPPORTED_KEY_VERSION",
    "CopilotRunContext",
    "CopilotRunContextError",
    "default_secret_resolver",
    "make_secret_resolver",
    "verify_copilot_run_context",
]
