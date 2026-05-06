"""Secret resolver used by :func:`verify_copilot_run_context` (M4).

The verifier introduced in M3 takes a callable
``secret_resolver(key_version: str) -> str | None`` so the auth layer can
look up the shared secret matching the ``key_version`` claim on a wire
token. Returning ``None`` (or an empty string) signals "unknown version"
and is converted by the verifier into a typed
``CopilotRunContextErrorReason.UNKNOWN_KEY_VERSION`` error.

This module wires the resolver against the application's
:class:`agent_service.config.Settings` -- the canonical home of
``AGENT_SHARED_SECRET``. M4 supports a single key version, ``"v1"``;
later milestones can extend the resolver to support rotation
(``"v2"``, ``"v3"``, ...) without touching the verifier.

Notes
-----
* The resolver is intentionally pure: it only reads from a ``Settings``
  instance passed in at call time. That keeps it deterministic in tests
  -- callers override the settings via FastAPI's
  ``app.dependency_overrides`` rather than monkeypatching env vars.
* Returning ``None`` is the documented contract for unknown versions;
  the verifier's M3 spec raises with ``unknown_key_version``.
"""

from __future__ import annotations

from collections.abc import Callable

from agent_service.config import Settings, get_settings


SUPPORTED_KEY_VERSION: str = "v1"
"""The single key version recognised in M4. Future rotations add more."""


def make_secret_resolver(settings: Settings) -> Callable[[str], str | None]:
    """Build a secret resolver bound to a specific :class:`Settings`.

    The returned callable is what :func:`verify_copilot_run_context`
    expects: ``(key_version: str) -> str | None``. Unknown versions
    yield ``None`` so the verifier can raise the canonical
    ``unknown_key_version`` error -- the resolver itself never raises.

    Parameters
    ----------
    settings:
        Application settings carrying ``agent_shared_secret``.

    Returns
    -------
    Callable
        Resolver function suitable for the M3 verifier signature.
    """

    def _resolve(key_version: str) -> str | None:
        if key_version == SUPPORTED_KEY_VERSION:
            return settings.agent_shared_secret
        return None

    return _resolve


def default_secret_resolver(key_version: str) -> str | None:
    """Module-level resolver backed by :func:`get_settings`.

    Convenience entry point for callers that do not want to thread a
    ``Settings`` instance through. Prefer :func:`make_secret_resolver`
    in code paths that already have a ``Settings`` reference.

    Returns ``None`` for unknown key versions, matching the contract in
    :data:`agent_service.auth.copilot_run_context.SecretResolver`.
    """
    return make_secret_resolver(get_settings())(key_version)


__all__ = [
    "SUPPORTED_KEY_VERSION",
    "default_secret_resolver",
    "make_secret_resolver",
]
