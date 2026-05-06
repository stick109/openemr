"""Intent catalog for the sidecar chart copilot (M7).

This subpackage owns the canonical list of agent intents (the
buttons the UI exposes today) ported from the PHP
``OpenEMR\\Services\\Agent\\AgentIntentCatalog``.

Each intent maps to:

- a stable, snake_case ``intent_id``,
- a human-readable ``label`` (the button caption),
- a ``goal_template`` -- the LLM-facing user goal seed,
- an ``allowed_tools`` allow-list drawn from M5's tool registry,
- per-intent capability caps (``max_rows``, ``lookback_days``) and
  ``allowed_source_types`` for the executor's source-type cap.

Public surface
--------------
- :class:`IntentDefinition`
- :class:`IntentCatalog`
- :class:`UnknownIntentError` / :class:`UnknownToolReferenceError`
- :func:`default_catalog`
"""

from __future__ import annotations

from agent_service.intents.catalog import (
    IntentCatalog,
    IntentDefinition,
    UnknownIntentError,
    UnknownToolReferenceError,
    default_catalog,
)

__all__ = [
    "IntentCatalog",
    "IntentDefinition",
    "UnknownIntentError",
    "UnknownToolReferenceError",
    "default_catalog",
]
