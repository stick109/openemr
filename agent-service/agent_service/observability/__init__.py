"""Observability primitives for the agent sidecar.

Captures sanitized per-run metrics (latency, cost, retrieval hits,
extraction confidence) into append-only run records and aggregates them
into Markdown cost/latency reports for the development team.

The records are designed to be PHI-free: every string field is checked
against SSN-like patterns and ``Patient: <name>`` markers before being
persisted, and the report generator runs the same scan over the
generated Markdown before returning it.
"""

from __future__ import annotations

from agent_service.observability.run_record import RunRecord
from agent_service.observability.storage import (
    JSONLStorage,
    RunRecordStorage,
    SQLiteStorage,
)

__all__ = [
    "RunRecord",
    "RunRecordStorage",
    "JSONLStorage",
    "SQLiteStorage",
]
