"""Append-only storage backends for :class:`RunRecord`.

Two implementations are provided:

* :class:`JSONLStorage` -- one record per line in a ``.jsonl`` file.
  This is the default backend: appends are atomic on POSIX (write to
  end-of-file under O_APPEND), the format is human-readable, and
  rotation can be done with standard tooling.

* :class:`SQLiteStorage` -- records persisted to a single-table SQLite
  database.  Useful when many readers want to query without parsing the
  whole file, or when a more structured store is required for ad-hoc
  analytics.

Both backends speak the :class:`RunRecordStorage` protocol; callers
should depend on the protocol, not on either concrete class, so the
backend can be swapped without code changes elsewhere.
"""

from __future__ import annotations

import json
import sqlite3
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable, Protocol, runtime_checkable

from agent_service.observability.run_record import RunRecord


# ---------------------------------------------------------------------------
# Protocol
# ---------------------------------------------------------------------------


@runtime_checkable
class RunRecordStorage(Protocol):
    """Append-only storage interface for :class:`RunRecord` documents."""

    def append(self, record: RunRecord) -> None:
        """Persist a single record.  Must be safe across concurrent calls."""
        ...

    def load_all(self) -> list[RunRecord]:
        """Return every record currently persisted, ordered by insertion."""
        ...


# ---------------------------------------------------------------------------
# JSONL backend (default)
# ---------------------------------------------------------------------------


class JSONLStorage:
    """Append-only JSONL backend.

    Each call to :meth:`append` opens the file in append mode, writes
    one JSON-encoded record followed by a newline, and closes the file.
    The OS guarantees atomic appends below ``PIPE_BUF`` for
    ``O_APPEND``-mode writes; the JSON encoding for our small record
    schema is well below that threshold.

    A process-local lock guards against interleaving inside a single
    Python process where two threads might call ``append`` concurrently.
    Cross-process concurrency relies on the OS append guarantee.
    """

    def __init__(self, path: str | Path) -> None:
        self._path: Path = Path(path)
        self._lock: threading.Lock = threading.Lock()
        # Create the parent directory on construction so callers don't
        # have to worry about it existing.  The file itself is created
        # lazily on first append so reading from a never-written store
        # still returns an empty list.
        self._path.parent.mkdir(parents=True, exist_ok=True)

    @property
    def path(self) -> Path:
        """Filesystem path to the JSONL file."""
        return self._path

    def append(self, record: RunRecord) -> None:
        """Append one JSON-encoded record terminated with ``\\n``."""
        line = json.dumps(record.to_jsonl_dict(), separators=(",", ":"))
        with self._lock:
            with self._path.open("a", encoding="utf-8") as fh:
                fh.write(line)
                fh.write("\n")

    def load_all(self) -> list[RunRecord]:
        """Return every record currently in the file, in append order.

        Blank lines are tolerated (and skipped); malformed JSON raises
        immediately so corruption is surfaced rather than silently
        eaten by the report generator.
        """
        if not self._path.is_file():
            return []
        records: list[RunRecord] = []
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
                records.append(RunRecord.model_validate(payload))
        return records


# ---------------------------------------------------------------------------
# SQLite backend
# ---------------------------------------------------------------------------


class SQLiteStorage:
    """Single-table SQLite backend.

    Schema::

        CREATE TABLE run_records (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            trace_id              TEXT NOT NULL,
            doc_type              TEXT NOT NULL,
            timestamp             TEXT NOT NULL,           -- ISO-8601
            latency_ms_per_step   TEXT NOT NULL,           -- JSON object
            total_latency_ms      REAL NOT NULL,
            tokens_in             INTEGER NOT NULL,
            tokens_out            INTEGER NOT NULL,
            model                 TEXT NOT NULL,
            cost_usd              REAL NOT NULL,
            retrieval_hit_count   INTEGER NOT NULL,
            extraction_confidence REAL NOT NULL,
            status                TEXT NOT NULL
        )

    The ``id`` autoincrement preserves insertion order on
    :meth:`load_all`.  Concurrent appends are serialised by SQLite's
    own write lock; we additionally hold a process-local lock to keep
    error handling tidy.
    """

    _SCHEMA: str = """
        CREATE TABLE IF NOT EXISTS run_records (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            trace_id              TEXT NOT NULL,
            doc_type              TEXT NOT NULL,
            timestamp             TEXT NOT NULL,
            latency_ms_per_step   TEXT NOT NULL,
            total_latency_ms      REAL NOT NULL,
            tokens_in             INTEGER NOT NULL,
            tokens_out            INTEGER NOT NULL,
            model                 TEXT NOT NULL,
            cost_usd              REAL NOT NULL,
            retrieval_hit_count   INTEGER NOT NULL,
            extraction_confidence REAL NOT NULL,
            status                TEXT NOT NULL
        )
    """

    _INSERT: str = """
        INSERT INTO run_records (
            trace_id, doc_type, timestamp,
            latency_ms_per_step, total_latency_ms,
            tokens_in, tokens_out, model, cost_usd,
            retrieval_hit_count, extraction_confidence, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """

    _SELECT_ALL: str = """
        SELECT
            trace_id, doc_type, timestamp,
            latency_ms_per_step, total_latency_ms,
            tokens_in, tokens_out, model, cost_usd,
            retrieval_hit_count, extraction_confidence, status
        FROM run_records
        ORDER BY id ASC
    """

    def __init__(self, path: str | Path) -> None:
        self._path: Path = Path(path)
        self._lock: threading.Lock = threading.Lock()
        self._path.parent.mkdir(parents=True, exist_ok=True)
        with self._connect() as conn:
            conn.executescript(self._SCHEMA)
            conn.commit()

    def _connect(self) -> sqlite3.Connection:
        """Open a short-lived connection.

        Each call returns a fresh connection so the backend is safe to
        share across threads without configuring ``check_same_thread``.
        """
        return sqlite3.connect(self._path)

    @property
    def path(self) -> Path:
        """Filesystem path to the SQLite file."""
        return self._path

    def append(self, record: RunRecord) -> None:
        """Insert one record."""
        params = (
            record.trace_id,
            record.doc_type,
            record.timestamp.isoformat(),
            json.dumps(record.latency_ms_per_step, separators=(",", ":")),
            float(record.total_latency_ms),
            int(record.tokens_in),
            int(record.tokens_out),
            record.model,
            float(record.cost_usd),
            int(record.retrieval_hit_count),
            float(record.extraction_confidence),
            record.status,
        )
        with self._lock:
            with self._connect() as conn:
                conn.execute(self._INSERT, params)
                conn.commit()

    def load_all(self) -> list[RunRecord]:
        """Return every record ordered by insertion."""
        with self._connect() as conn:
            rows = conn.execute(self._SELECT_ALL).fetchall()

        records: list[RunRecord] = []
        for row in rows:
            (
                trace_id,
                doc_type,
                timestamp_iso,
                latency_json,
                total_latency_ms,
                tokens_in,
                tokens_out,
                model,
                cost_usd,
                retrieval_hit_count,
                extraction_confidence,
                status,
            ) = row
            records.append(
                RunRecord(
                    trace_id=trace_id,
                    doc_type=doc_type,
                    timestamp=_parse_iso8601(timestamp_iso),
                    latency_ms_per_step=json.loads(latency_json),
                    total_latency_ms=float(total_latency_ms),
                    tokens_in=int(tokens_in),
                    tokens_out=int(tokens_out),
                    model=model,
                    cost_usd=float(cost_usd),
                    retrieval_hit_count=int(retrieval_hit_count),
                    extraction_confidence=float(extraction_confidence),
                    status=status,
                ),
            )
        return records


# ---------------------------------------------------------------------------
# Bulk helpers
# ---------------------------------------------------------------------------


def append_many(storage: RunRecordStorage, records: Iterable[RunRecord]) -> int:
    """Append every record in *records* and return the count.

    Convenience helper for tests and the eval runner -- both call sites
    typically batch a list of records into a fresh store.
    """
    count = 0
    for record in records:
        storage.append(record)
        count += 1
    return count


def _parse_iso8601(value: str) -> datetime:
    """Parse an ISO-8601 timestamp; default to UTC if no zone is set."""
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed
