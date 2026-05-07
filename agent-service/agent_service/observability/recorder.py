"""Append-only sinks for :class:`RunEvent` documents (M16).

Three implementations are shipped:

* :class:`JsonlEventRecorder` -- one event per line in a ``.jsonl`` file.
  This is the production default for offline analysis.  Concurrent
  appends within a process are guarded by a ``threading.Lock``;
  cross-process concurrency relies on the OS atomicity guarantee for
  ``O_APPEND`` writes below ``PIPE_BUF`` (well above our event size).
* :class:`LoggingEventRecorder` -- emits each event as a single JSON log
  record at INFO level via :mod:`logging`.  The intended sink is the
  process's stdout stream (uvicorn's logging configuration), so demo
  deployments can ``docker compose logs -f agent-service`` and watch
  the agent-loop event sequence in real time.
* :class:`MultiplexEventRecorder` -- fan-out wrapper that delegates each
  ``record`` call to one or more underlying sinks, so a deployment can
  persist events to JSONL *and* tail them from stdout simultaneously.
* :class:`NullEventRecorder` -- no-op sink for tests that exercise the
  agent loop without caring about observability.

Callers depend on the :class:`EventRecorder` protocol so the backend
can be swapped without modifying the loop.
"""

from __future__ import annotations

import json
import logging
import threading
from collections.abc import Iterable, Sequence
from datetime import datetime, timezone
from pathlib import Path
from typing import Protocol, runtime_checkable

from agent_service.observability.events import RunEvent


def _parse_iso8601(value: str) -> datetime:
    """Parse an ISO-8601 timestamp; default to UTC if no zone is set.

    Mirrors :func:`agent_service.observability.storage._parse_iso8601` so
    the run-record and event sinks accept the same wire format.
    """
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


# ---------------------------------------------------------------------------
# Protocol
# ---------------------------------------------------------------------------


@runtime_checkable
class EventRecorder(Protocol):
    """Append-only sink for :class:`RunEvent` documents.

    Implementations must be safe under concurrent ``record`` calls from
    multiple threads within a single process.
    """

    def record(self, event: RunEvent) -> None:
        """Persist a single event."""
        ...


# ---------------------------------------------------------------------------
# JSONL sink (default)
# ---------------------------------------------------------------------------


class JsonlEventRecorder:
    """Append-only JSONL backend mirroring :class:`JSONLStorage`.

    Events are serialised with the same ``separators=(",", ":")`` /
    ISO-8601 timestamp conventions as :class:`RunRecord` so a single set
    of tools (``jq``, ``grep``) works over both files.
    """

    def __init__(self, *, path: str | Path) -> None:
        self._path: Path = Path(path)
        self._lock: threading.Lock = threading.Lock()
        # Create the parent directory eagerly so the first ``record``
        # call does not race with directory creation.  The file itself
        # is created lazily on first append; reading from a never-
        # written sink is a deliberate no-op upstream.
        self._path.parent.mkdir(parents=True, exist_ok=True)

    @property
    def path(self) -> Path:
        """Filesystem path to the JSONL file."""
        return self._path

    def record(self, event: RunEvent) -> None:
        """Append one JSON-encoded event terminated with ``\\n``."""
        payload = event.model_dump()
        # Pydantic returns ``datetime`` objects unchanged; JSON-encode
        # them as ISO-8601 strings so the file remains stable across
        # readers (the run-record store uses the same convention).
        payload["occurred_at"] = event.occurred_at.isoformat()
        line = json.dumps(payload, separators=(",", ":"))
        with self._lock:
            with self._path.open("a", encoding="utf-8") as fh:
                fh.write(line)
                fh.write("\n")

    def load_all(self) -> list[RunEvent]:
        """Return every event currently in the file, in append order.

        Provided primarily for tests and ad-hoc inspection; the
        production code path only writes.  Blank lines are skipped;
        malformed JSON raises immediately so corruption is surfaced.
        """
        if not self._path.is_file():
            return []
        events: list[RunEvent] = []
        with self._path.open("r", encoding="utf-8") as fh:
            for lineno, raw in enumerate(fh, start=1):
                stripped = raw.strip()
                if not stripped:
                    continue
                try:
                    payload = json.loads(stripped)
                except json.JSONDecodeError as exc:
                    raise ValueError(
                        f"Invalid JSON in {self._path} on line {lineno}: {exc}",
                    ) from exc
                # ``RunEvent`` runs in ``strict=True`` mode, which forbids
                # the implicit string-to-datetime coercion Pydantic v2
                # otherwise allows.  The ``record`` path serialises
                # ``occurred_at`` as an ISO string, so we re-parse it
                # back into a ``datetime`` before validating.
                if isinstance(payload, dict) and isinstance(
                    payload.get("occurred_at"), str
                ):
                    payload["occurred_at"] = _parse_iso8601(
                        payload["occurred_at"],
                    )
                events.append(RunEvent.model_validate(payload))
        return events


# ---------------------------------------------------------------------------
# Logging sink
# ---------------------------------------------------------------------------


_DEFAULT_LOGGER_NAME = "agent_service.observability"


class LoggingEventRecorder:
    """Emit each event as a single JSON-encoded INFO log record.

    Intended for demo / development deployments that want the agent-loop
    event sequence to surface in container logs (``docker compose logs
    -f agent-service``) without spinning up a JSONL tail.

    The events are already PHI-scrubbed at construction by
    :class:`agent_service.observability.events.RunEvent`, so this sink
    does no further redaction -- the bytes that hit ``logger.info`` are
    the same bytes :class:`JsonlEventRecorder` would have appended to a
    file.

    Logging configuration
    ---------------------
    Uvicorn installs a stdout handler on the root logger at INFO level
    when started with default flags.  Records emitted on the
    ``agent_service.observability`` logger therefore propagate up the
    hierarchy and reach stdout without further configuration.  The
    constructor enforces a minimum log level of ``INFO`` on the logger
    itself so a stricter root level (e.g. ``WARNING``) does not silently
    swallow these events.
    """

    def __init__(
        self,
        *,
        logger: logging.Logger | None = None,
        level: int = logging.INFO,
    ) -> None:
        self._logger: logging.Logger = (
            logger if logger is not None else logging.getLogger(_DEFAULT_LOGGER_NAME)
        )
        # Uvicorn does not configure non-uvicorn loggers, so the
        # ``agent_service.observability`` logger inherits whatever level
        # the root logger has.  We force INFO on this specific logger so
        # the event stream surfaces even when the root logger is left at
        # the Python default (WARNING).
        if self._logger.level == logging.NOTSET or self._logger.level > level:
            self._logger.setLevel(level)
        self._level: int = level

    @property
    def logger(self) -> logging.Logger:
        """Return the underlying :class:`logging.Logger` for inspection."""
        return self._logger

    def record(self, event: RunEvent) -> None:
        """Log one JSON-encoded event at INFO level."""
        # ``model_dump`` then ``json.dumps`` mirrors the JSONL recorder's
        # encoding (``separators=(",", ":")`` for compact output, ISO
        # 8601 occurred_at) so a single set of tools (``jq``, ``grep``)
        # works over both sinks.
        payload = event.model_dump(mode="python", exclude_none=True)
        # ``mode="python"`` returns a ``datetime`` for ``occurred_at``;
        # serialise it the same way the JSONL recorder does so the wire
        # format stays stable.
        if "occurred_at" in payload:
            payload["occurred_at"] = event.occurred_at.isoformat()
        line = json.dumps(payload, separators=(",", ":"))
        # Use the closed-set event_type as the log message so log
        # aggregators can group on it without parsing the JSON suffix.
        self._logger.log(self._level, "run_event %s", line)


# ---------------------------------------------------------------------------
# Multiplex / fan-out sink
# ---------------------------------------------------------------------------


class MultiplexEventRecorder:
    """Fan-out wrapper that records every event to several backends.

    Construction order is preserved: each underlying recorder receives
    the event in the order supplied, even if an earlier sink raises.
    Failures from one sink do not stop the rest from running, so a flaky
    file system does not blind the stdout stream (and vice versa).
    """

    def __init__(self, recorders: Iterable[EventRecorder]) -> None:
        self._recorders: tuple[EventRecorder, ...] = tuple(recorders)

    @property
    def recorders(self) -> Sequence[EventRecorder]:
        """Return the underlying recorders (insertion order)."""
        return self._recorders

    def record(self, event: RunEvent) -> None:
        """Dispatch the event to every wrapped recorder."""
        first_error: BaseException | None = None
        for recorder in self._recorders:
            try:
                recorder.record(event)
            except BaseException as exc:  # noqa: BLE001 -- best-effort fan-out
                if first_error is None:
                    first_error = exc
        if first_error is not None:
            raise first_error


# ---------------------------------------------------------------------------
# Null sink
# ---------------------------------------------------------------------------


class NullEventRecorder:
    """No-op recorder for unit tests that don't care about events."""

    def record(self, event: RunEvent) -> None:
        """Drop the event on the floor."""
        # Touch the argument so static analysers do not flag it as unused.
        del event


__all__ = [
    "EventRecorder",
    "JsonlEventRecorder",
    "LoggingEventRecorder",
    "MultiplexEventRecorder",
    "NullEventRecorder",
]
