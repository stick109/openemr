"""Rubrics package for the offline eval runner.

This package collects rubric scoring helpers for the various eval suites.
The original 50-case eval lives in
:mod:`agent_service.eval.runner` and predates the per-suite split; the
M22 ``copilot-tools`` suite is implemented in
:mod:`agent_service.eval.rubrics.copilot_tools`.
"""

from __future__ import annotations

from agent_service.eval.rubrics.copilot_tools import (
    COPILOT_TOOLS_RUBRIC_NAMES,
    COPILOT_TOOLS_SAFE_REFUSAL_REASONS,
    CopilotToolsRubrics,
    score_copilot_tools_rubrics,
)


__all__ = [
    "COPILOT_TOOLS_RUBRIC_NAMES",
    "COPILOT_TOOLS_SAFE_REFUSAL_REASONS",
    "CopilotToolsRubrics",
    "score_copilot_tools_rubrics",
]
