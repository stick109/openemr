"""Tests for :class:`LoggingEventRecorder` and the env-driven recorder routing.

These cover the M16 follow-up that wires agent-loop observability events
through Python's ``logging`` module so demo deployments can tail them via
``docker compose logs -f agent-service``.

Three concerns are exercised:

* :class:`LoggingEventRecorder` emits one INFO record per event,
  containing the event's JSON payload.  ``caplog`` is used to inspect
  the records without touching real stdout.
* :class:`MultiplexEventRecorder` fans out to every wrapped recorder
  even when one of them raises.
* :func:`agent_service.api.copilot.get_event_recorder` returns the
  correct sink for every combination of
  ``OBSERVABILITY_EVENTS_PATH`` / ``OBSERVABILITY_EVENTS_STDOUT``.
"""

from __future__ import annotations

import json
import logging
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from unittest import mock

import pytest

from agent_service.observability.events import RunEvent
from agent_service.observability.recorder import (
    EventRecorder,
    JsonlEventRecorder,
    LoggingEventRecorder,
    MultiplexEventRecorder,
    NullEventRecorder,
)


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(autouse=True)
def _reset_settings_cache() -> None:
    """Clear the cached Settings singleton before every test."""
    from agent_service.config import get_settings

    get_settings.cache_clear()
    yield
    get_settings.cache_clear()


@pytest.fixture()
def _propagating_observability_logger() -> Any:
    """Force ``agent_service.observability`` to propagate during a test.

    The application's :func:`agent_service.main._configure_observability_logging`
    deliberately sets ``propagate=False`` on this logger so the dedicated
    stdout handler doesn't double-print through uvicorn's root handler in
    production. ``caplog`` works by capturing on the root logger, so the
    propagation toggle prevents the recorder's records from reaching the
    captured stream during tests when ``main.py`` has already been
    imported by an earlier test.

    This fixture flips propagation back on for the duration of one test
    and restores the original value on exit, so tests assert on the
    recorder's behaviour without depending on the order in which other
    tests import the application package.
    """
    obs_logger = logging.getLogger("agent_service.observability")
    original_propagate = obs_logger.propagate
    obs_logger.propagate = True
    try:
        yield obs_logger
    finally:
        obs_logger.propagate = original_propagate


def _make_event(**overrides: Any) -> RunEvent:
    """Construct a baseline clean :class:`RunEvent`, overriding fields."""
    defaults: dict[str, Any] = {
        "trace_id": "trace-log-001",
        "event_type": "run.received",
        "occurred_at": datetime(2026, 5, 7, 12, 0, 0, tzinfo=timezone.utc),
    }
    defaults.update(overrides)
    return RunEvent(**defaults)


# ---------------------------------------------------------------------------
# LoggingEventRecorder
# ---------------------------------------------------------------------------


class TestLoggingEventRecorder:
    """The recorder emits one INFO log record per event."""

    def test_records_emit_at_info_level(
        self,
        caplog: pytest.LogCaptureFixture,
        _propagating_observability_logger: logging.Logger,
    ) -> None:
        recorder = LoggingEventRecorder()
        event = _make_event()

        with caplog.at_level(logging.INFO, logger="agent_service.observability"):
            recorder.record(event)

        records = [
            r for r in caplog.records
            if r.name == "agent_service.observability"
        ]
        assert len(records) == 1
        assert records[0].levelno == logging.INFO

    def test_record_contains_event_json(
        self,
        caplog: pytest.LogCaptureFixture,
        _propagating_observability_logger: logging.Logger,
    ) -> None:
        recorder = LoggingEventRecorder()
        event = _make_event(
            event_type="tool.finished",
            tool_name="get_current_medications",
            latency_ms=42,
            result_count=3,
            cost_usd_delta=0.0001,
        )

        with caplog.at_level(logging.INFO, logger="agent_service.observability"):
            recorder.record(event)

        message = caplog.records[-1].getMessage()
        # The recorder prefixes the JSON with ``run_event`` so log
        # aggregators can filter on the literal token; the JSON itself
        # is the second whitespace-delimited token.
        assert message.startswith("run_event ")
        payload = json.loads(message.split(" ", 1)[1])
        assert payload["trace_id"] == "trace-log-001"
        assert payload["event_type"] == "tool.finished"
        assert payload["tool_name"] == "get_current_medications"
        assert payload["latency_ms"] == 42
        assert payload["result_count"] == 3
        assert payload["cost_usd_delta"] == 0.0001
        # ``occurred_at`` round-trips as an ISO-8601 string -- the same
        # convention the JSONL recorder uses, so a single set of tools
        # works against both sinks.
        assert datetime.fromisoformat(payload["occurred_at"])

    def test_exclude_none_keeps_payload_compact(
        self,
        caplog: pytest.LogCaptureFixture,
        _propagating_observability_logger: logging.Logger,
    ) -> None:
        # ``run.received`` carries only the three required fields; the
        # logger should not pad the JSON with explicit ``null`` entries
        # for every optional field.
        recorder = LoggingEventRecorder()
        event = _make_event()

        with caplog.at_level(logging.INFO, logger="agent_service.observability"):
            recorder.record(event)

        payload = json.loads(caplog.records[-1].getMessage().split(" ", 1)[1])
        assert "tool_name" not in payload
        assert "latency_ms" not in payload
        assert "error_class" not in payload

    def test_logger_promoted_to_info_when_default_warning(self) -> None:
        # A pristine logger inherits NOTSET; the recorder must raise the
        # effective level to INFO so a stricter root level (the Python
        # default WARNING) does not swallow the events.
        named_logger = logging.getLogger(
            "agent_service.observability.tests.promotion",
        )
        # Reset to the inherited default so the test is hermetic when
        # repeated in the same interpreter.
        named_logger.setLevel(logging.NOTSET)

        LoggingEventRecorder(logger=named_logger)

        assert named_logger.level == logging.INFO

    def test_custom_logger_is_used(
        self, caplog: pytest.LogCaptureFixture,
    ) -> None:
        # Use a logger outside the ``agent_service.observability``
        # hierarchy so the application's ``propagate=False`` setting on
        # that logger does not block ``caplog``'s root-level capture.
        custom = logging.getLogger(
            "agent_service.tests.custom_logger",
        )
        recorder = LoggingEventRecorder(logger=custom)

        with caplog.at_level(
            logging.INFO,
            logger="agent_service.tests.custom_logger",
        ):
            recorder.record(_make_event())

        names = {r.name for r in caplog.records}
        assert "agent_service.tests.custom_logger" in names


# ---------------------------------------------------------------------------
# MultiplexEventRecorder
# ---------------------------------------------------------------------------


class _MemoryRecorder:
    """Simple test double that captures events for inspection."""

    def __init__(self) -> None:
        self.events: list[RunEvent] = []

    def record(self, event: RunEvent) -> None:
        self.events.append(event)


class _AlwaysFailingRecorder:
    """Test double that raises on every record call."""

    def record(self, event: RunEvent) -> None:
        raise RuntimeError("recorder unavailable")


class TestMultiplexEventRecorder:
    def test_dispatches_to_every_wrapped_sink(self) -> None:
        a = _MemoryRecorder()
        b = _MemoryRecorder()
        multiplex = MultiplexEventRecorder([a, b])
        event = _make_event()

        multiplex.record(event)

        assert a.events == [event]
        assert b.events == [event]

    def test_failing_sink_does_not_blind_other_sinks(self) -> None:
        # A flaky filesystem must not stop the stdout sink from running
        # (and vice versa).  The multiplexer records to every sink and
        # surfaces the first error after dispatch.
        memory = _MemoryRecorder()
        multiplex = MultiplexEventRecorder(
            [_AlwaysFailingRecorder(), memory],
        )
        event = _make_event()

        with pytest.raises(RuntimeError, match="recorder unavailable"):
            multiplex.record(event)

        # The healthy sink still saw the event.
        assert memory.events == [event]

    def test_recorders_property_preserves_order(self) -> None:
        a = _MemoryRecorder()
        b = _MemoryRecorder()
        multiplex = MultiplexEventRecorder([a, b])
        assert list(multiplex.recorders) == [a, b]


# ---------------------------------------------------------------------------
# get_event_recorder routing
# ---------------------------------------------------------------------------


class TestGetEventRecorderRouting:
    """``get_event_recorder`` selects the right sink from environment vars."""

    def test_returns_null_recorder_when_neither_var_set(self) -> None:
        from agent_service.api.copilot import get_event_recorder

        with mock.patch.dict(
            os.environ,
            {"AGENT_SHARED_SECRET": "test-secret"},
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, NullEventRecorder)

    def test_returns_jsonl_when_only_path_set(self, tmp_path: Path) -> None:
        from agent_service.api.copilot import get_event_recorder

        events_path = tmp_path / "events.jsonl"
        with mock.patch.dict(
            os.environ,
            {
                "AGENT_SHARED_SECRET": "test-secret",
                "OBSERVABILITY_EVENTS_PATH": str(events_path),
            },
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, JsonlEventRecorder)
        assert recorder.path == events_path

    def test_returns_logging_when_only_stdout_set(self) -> None:
        from agent_service.api.copilot import get_event_recorder

        with mock.patch.dict(
            os.environ,
            {
                "AGENT_SHARED_SECRET": "test-secret",
                "OBSERVABILITY_EVENTS_STDOUT": "1",
            },
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, LoggingEventRecorder)

    @pytest.mark.parametrize(
        "truthy",
        ["1", "true", "TRUE", "yes", "Yes"],
    )
    def test_stdout_var_is_case_insensitive(self, truthy: str) -> None:
        from agent_service.api.copilot import get_event_recorder

        with mock.patch.dict(
            os.environ,
            {
                "AGENT_SHARED_SECRET": "test-secret",
                "OBSERVABILITY_EVENTS_STDOUT": truthy,
            },
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, LoggingEventRecorder)

    def test_stdout_falsy_routes_through_null(self) -> None:
        from agent_service.api.copilot import get_event_recorder

        with mock.patch.dict(
            os.environ,
            {
                "AGENT_SHARED_SECRET": "test-secret",
                "OBSERVABILITY_EVENTS_STDOUT": "0",
            },
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, NullEventRecorder)

    def test_returns_multiplex_when_both_set(self, tmp_path: Path) -> None:
        from agent_service.api.copilot import get_event_recorder

        events_path = tmp_path / "events.jsonl"
        with mock.patch.dict(
            os.environ,
            {
                "AGENT_SHARED_SECRET": "test-secret",
                "OBSERVABILITY_EVENTS_PATH": str(events_path),
                "OBSERVABILITY_EVENTS_STDOUT": "1",
            },
            clear=True,
        ):
            recorder = get_event_recorder()

        assert isinstance(recorder, MultiplexEventRecorder)
        kinds = [type(r) for r in recorder.recorders]
        assert JsonlEventRecorder in kinds
        assert LoggingEventRecorder in kinds

    def test_returns_null_when_settings_unavailable(self) -> None:
        # ``AGENT_SHARED_SECRET`` is required by ``get_settings``.  When
        # it is unset the routing layer must fall through to a null
        # recorder rather than crashing the request.
        from agent_service.api.copilot import get_event_recorder

        with mock.patch.dict(os.environ, {}, clear=True):
            recorder = get_event_recorder()

        assert isinstance(recorder, NullEventRecorder)


# ---------------------------------------------------------------------------
# Protocol conformance
# ---------------------------------------------------------------------------


class TestProtocolConformance:
    """Each recorder satisfies the :class:`EventRecorder` protocol."""

    def test_logging_event_recorder_is_an_event_recorder(self) -> None:
        recorder = LoggingEventRecorder()
        assert isinstance(recorder, EventRecorder)

    def test_multiplex_event_recorder_is_an_event_recorder(self) -> None:
        recorder = MultiplexEventRecorder([NullEventRecorder()])
        assert isinstance(recorder, EventRecorder)
