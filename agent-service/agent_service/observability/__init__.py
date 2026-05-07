"""Observability primitives for the agent sidecar.

Two layers of capture are provided:

* Per-run :class:`RunRecord` documents (S25) -- one summary record per
  graph invocation, persisted via :class:`JSONLStorage` /
  :class:`SQLiteStorage` and aggregated into Markdown cost/latency
  reports.

* Per-tool-call :class:`RunEvent` documents (M16) -- structured event
  spans for each phase of the agent loop (run.received, model turns,
  tool started/finished, verifier, response.returned), persisted via
  :class:`JsonlEventRecorder` (or routed through
  :class:`NullEventRecorder` in tests that don't observe events).

Both layers share a single PHI scanner exposed via
:func:`observability._phi_scanner.scan_for_phi` (and its event-level
extension :func:`scan_event_field_for_phi`).  Every string field is
checked against SSN-like patterns, ``Patient: <name>`` markers, and --
for events -- email / phone / address heuristics before being persisted.
"""

from __future__ import annotations

from agent_service.observability.events import (
    EventType,
    RunEvent,
    RunEventPhiError,
    VerifierOutcome,
)
from agent_service.observability.recorder import (
    EventRecorder,
    JsonlEventRecorder,
    LoggingEventRecorder,
    MultiplexEventRecorder,
    NullEventRecorder,
)
from agent_service.observability.run_record import RunRecord
from agent_service.observability.storage import (
    JSONLStorage,
    RunRecordStorage,
    SQLiteStorage,
)

__all__ = [
    "EventRecorder",
    "EventType",
    "JSONLStorage",
    "JsonlEventRecorder",
    "LoggingEventRecorder",
    "MultiplexEventRecorder",
    "NullEventRecorder",
    "RunEvent",
    "RunEventPhiError",
    "RunRecord",
    "RunRecordStorage",
    "SQLiteStorage",
    "VerifierOutcome",
]
