"""Agent loop package for the chart copilot (M13).

Exposes the :class:`AgentLoop` plus its config + result value objects.
The actual orchestration code lives in :mod:`agent_service.loop.agent_loop`;
the package surface here keeps imports terse for callers (``from
agent_service.loop import AgentLoop``).
"""

from __future__ import annotations

from agent_service.loop.agent_loop import (
    AgentLoop,
    AgentLoopConfig,
    AgentLoopResult,
    HaltReason,
    RegistryBuilder,
)


__all__ = [
    "AgentLoop",
    "AgentLoopConfig",
    "AgentLoopResult",
    "HaltReason",
    "RegistryBuilder",
]
