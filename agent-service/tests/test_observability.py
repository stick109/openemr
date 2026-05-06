"""Tests for the observability run-record / report module (S25).

The suite covers four concerns:

* :class:`RunRecord` rejects PHI markers (SSN, ``Patient: <name>``)
  in any string field, alongside the other field-shape constraints.
* :class:`JSONLStorage` and :class:`SQLiteStorage` round-trip records
  losslessly and preserve insertion order.
* The Markdown report contains every required section (summary,
  latency p50/p95/p99, projected costs at 100/1k/10k, bottleneck
  analysis, retrieval, confidence) and refuses to emit reports that
  somehow contain PHI residues.
* The CLI :mod:`agent_service.observability.report` writes a non-empty
  report file to the requested ``--out`` path.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import pytest
from pydantic import ValidationError

from agent_service.observability import (
    JSONLStorage,
    RunRecord,
    SQLiteStorage,
)
from agent_service.observability.report import (
    PROJECTION_VOLUMES,
    PhiInReportError,
    generate_report,
    main as report_main,
    write_report,
)
from agent_service.observability.run_record import scan_for_phi


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


def _baseline_record(**overrides: Any) -> RunRecord:
    """Construct a valid baseline :class:`RunRecord`, overriding fields as needed."""
    defaults: dict[str, Any] = {
        "trace_id": "trace-abc-001",
        "doc_type": "lab_pdf",
        "timestamp": datetime(2026, 5, 6, 12, 0, 0, tzinfo=timezone.utc),
        "latency_ms_per_step": {"extract": 100.0, "retrieve": 50.0, "finalize": 5.0},
        "total_latency_ms": 155.0,
        "tokens_in": 1200,
        "tokens_out": 450,
        "model": "gpt-4o",
        "cost_usd": 0.0125,
        "retrieval_hit_count": 5,
        "extraction_confidence": 0.92,
        "status": "success",
    }
    defaults.update(overrides)
    return RunRecord(**defaults)


def _diverse_records() -> list[RunRecord]:
    """A small but representative set covering success / refused / error."""
    return [
        _baseline_record(trace_id="trace-001", total_latency_ms=120.0,
                         latency_ms_per_step={"extract": 80.0, "retrieve": 30.0, "finalize": 5.0},
                         retrieval_hit_count=6, extraction_confidence=0.95),
        _baseline_record(trace_id="trace-002", total_latency_ms=210.0,
                         latency_ms_per_step={"extract": 150.0, "retrieve": 50.0, "finalize": 8.0},
                         retrieval_hit_count=5, extraction_confidence=0.85),
        _baseline_record(trace_id="trace-003", total_latency_ms=320.0,
                         latency_ms_per_step={"extract": 250.0, "retrieve": 60.0, "finalize": 8.0},
                         retrieval_hit_count=4, extraction_confidence=0.70),
        _baseline_record(trace_id="trace-004", status="refused",
                         total_latency_ms=15.0,
                         latency_ms_per_step={"extract": 10.0, "refuse": 1.0},
                         retrieval_hit_count=0, extraction_confidence=0.10,
                         cost_usd=0.0),
        _baseline_record(trace_id="trace-005", status="error",
                         total_latency_ms=80.0,
                         latency_ms_per_step={"extract": 75.0, "refuse": 1.0},
                         retrieval_hit_count=0, extraction_confidence=0.05,
                         cost_usd=0.0001, doc_type="intake_form"),
    ]


# ---------------------------------------------------------------------------
# RunRecord shape & PHI validation
# ---------------------------------------------------------------------------


class TestRunRecord:
    """Field-level constraints on :class:`RunRecord`."""

    def test_baseline_record_constructs(self) -> None:
        record = _baseline_record()
        assert record.trace_id == "trace-abc-001"
        assert record.status == "success"
        assert record.tokens_in >= 0

    def test_negative_latency_rejected(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(total_latency_ms=-10.0)

    def test_negative_token_count_rejected(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(tokens_in=-1)

    def test_unknown_status_rejected(self) -> None:
        with pytest.raises(ValidationError) as exc_info:
            _baseline_record(status="weird")
        assert "status" in str(exc_info.value).lower()

    def test_extraction_confidence_must_be_in_unit_interval(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(extraction_confidence=1.5)
        with pytest.raises(ValidationError):
            _baseline_record(extraction_confidence=-0.1)

    def test_per_step_sum_cannot_exceed_total(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(
                latency_ms_per_step={"extract": 200.0, "retrieve": 200.0},
                total_latency_ms=10.0,
            )

    def test_extra_fields_rejected(self) -> None:
        with pytest.raises(ValidationError):
            RunRecord(
                trace_id="t",
                doc_type="lab_pdf",
                latency_ms_per_step={},
                total_latency_ms=0.0,
                tokens_in=0,
                tokens_out=0,
                model="gpt-4o",
                cost_usd=0.0,
                retrieval_hit_count=0,
                extraction_confidence=0.0,
                status="success",
                # Unknown attribute below.
                phi="leaked",  # type: ignore[call-arg]
            )

    # -- PHI scan ------------------------------------------------------------

    def test_rejects_ssn_in_trace_id(self) -> None:
        with pytest.raises(ValidationError) as exc_info:
            _baseline_record(trace_id="trace-123-45-6789")
        assert "phi" in str(exc_info.value).lower()

    def test_rejects_patient_marker_in_doc_type(self) -> None:
        with pytest.raises(ValidationError) as exc_info:
            _baseline_record(doc_type="Patient: Jane Doe")
        assert "phi" in str(exc_info.value).lower()

    def test_rejects_ssn_in_model_field(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(model="gpt-4o-123-45-6789")

    def test_rejects_patient_marker_in_step_name(self) -> None:
        with pytest.raises(ValidationError):
            _baseline_record(
                latency_ms_per_step={"Patient: Jane Doe": 10.0},
                total_latency_ms=10.0,
            )

    def test_scan_for_phi_helper(self) -> None:
        assert scan_for_phi("clean text") == []
        assert scan_for_phi("ssn 111-22-3333") == ["ssn-like: 111-22-3333"]
        assert scan_for_phi("Patient: First Last") == ["patient-name: Patient: First Last"]


# ---------------------------------------------------------------------------
# Storage round-trips
# ---------------------------------------------------------------------------


class TestJSONLStorage:
    """JSONL append + load_all preserves field values and order."""

    def test_round_trip_preserves_order(self, tmp_path: Path) -> None:
        path = tmp_path / "records.jsonl"
        store = JSONLStorage(path)
        originals = _diverse_records()
        for record in originals:
            store.append(record)

        loaded = store.load_all()
        assert len(loaded) == len(originals)
        assert [r.trace_id for r in loaded] == [r.trace_id for r in originals]

    def test_round_trip_field_equality(self, tmp_path: Path) -> None:
        path = tmp_path / "records.jsonl"
        store = JSONLStorage(path)
        record = _baseline_record()
        store.append(record)
        (loaded,) = store.load_all()
        assert loaded == record

    def test_load_missing_file_returns_empty(self, tmp_path: Path) -> None:
        path = tmp_path / "missing.jsonl"
        assert JSONLStorage(path).load_all() == []

    def test_blank_lines_skipped(self, tmp_path: Path) -> None:
        path = tmp_path / "records.jsonl"
        store = JSONLStorage(path)
        store.append(_baseline_record())
        # Inject a blank line manually.
        with path.open("a", encoding="utf-8") as fh:
            fh.write("\n\n")
        store.append(_baseline_record(trace_id="trace-002"))

        loaded = store.load_all()
        assert [r.trace_id for r in loaded] == ["trace-abc-001", "trace-002"]

    def test_corrupt_line_raises(self, tmp_path: Path) -> None:
        path = tmp_path / "records.jsonl"
        store = JSONLStorage(path)
        store.append(_baseline_record())
        with path.open("a", encoding="utf-8") as fh:
            fh.write("not valid json\n")
        with pytest.raises(ValueError, match="Invalid JSON"):
            store.load_all()

    def test_appended_lines_use_isoformat_timestamp(self, tmp_path: Path) -> None:
        path = tmp_path / "records.jsonl"
        store = JSONLStorage(path)
        store.append(_baseline_record())
        raw = path.read_text(encoding="utf-8").strip().splitlines()
        payload = json.loads(raw[0])
        # Sanity: timestamp is a string we can parse back.
        datetime.fromisoformat(payload["timestamp"])


class TestSQLiteStorage:
    """SQLite backend round-trips records and orders by id."""

    def test_round_trip(self, tmp_path: Path) -> None:
        path = tmp_path / "records.sqlite"
        store = SQLiteStorage(path)
        originals = _diverse_records()
        for record in originals:
            store.append(record)
        loaded = store.load_all()
        assert [r.trace_id for r in loaded] == [r.trace_id for r in originals]
        # Field equality on at least one round trip.
        assert loaded[0] == originals[0]


# ---------------------------------------------------------------------------
# Report generation
# ---------------------------------------------------------------------------


class TestReport:
    """End-to-end Markdown rendering."""

    def test_generated_report_has_required_sections(self) -> None:
        report = generate_report(_diverse_records())
        for heading in (
            "## Summary",
            "## Latency",
            "### Total latency",
            "### Per-step latency",
            "## Cost",
            "### Projected daily cost",
            "## Bottleneck analysis",
            "## Retrieval stats",
            "## Confidence stats",
        ):
            assert heading in report, f"missing section: {heading}"

    def test_report_contains_percentile_labels(self) -> None:
        report = generate_report(_diverse_records())
        assert "p50" in report
        assert "p95" in report
        assert "p99" in report
        assert "p10" in report
        assert "p90" in report

    def test_report_lists_all_projection_volumes(self) -> None:
        report = generate_report(_diverse_records())
        for volume in PROJECTION_VOLUMES:
            assert f"{volume:,}" in report, f"missing projection for {volume}"

    def test_report_highlights_a_bottleneck_step(self) -> None:
        records = _diverse_records()
        report = generate_report(records)
        # The "extract" step has the highest mean latency in the diverse
        # sample by construction (80/150/250 ms).
        assert "Highest mean latency" in report
        assert "extract" in report

    def test_report_includes_retrieval_threshold(self) -> None:
        report = generate_report(_diverse_records())
        assert "Mean hits per query" in report
        assert "Queries with >=" in report

    def test_empty_records_does_not_crash(self) -> None:
        report = generate_report([])
        assert "No run records available" in report

    def test_phi_in_report_is_rejected(self, monkeypatch: pytest.MonkeyPatch) -> None:
        """If the renderer somehow leaks PHI, the post-scan must catch it."""

        # Patch the cost renderer to inject a PHI marker.  This stands in for
        # the kind of bug the post-scan exists to catch.
        from agent_service.observability import report as report_mod

        def _bad_cost(records: list[RunRecord]) -> str:
            return "## Cost\n\nPatient: Jane Doe spent $1.00\n"

        monkeypatch.setattr(report_mod, "_render_cost", _bad_cost)
        with pytest.raises(PhiInReportError):
            generate_report(_diverse_records())

    def test_write_report_creates_file(self, tmp_path: Path) -> None:
        out = tmp_path / "nested" / "report.md"
        records = _diverse_records()
        written = write_report(records, out)
        assert written == out
        assert out.is_file()
        body = out.read_text(encoding="utf-8")
        assert len(body) > 200, "report file unexpectedly small"
        assert "# Cost / Latency Report" in body


# ---------------------------------------------------------------------------
# CLI entrypoint
# ---------------------------------------------------------------------------


class TestCLI:
    """``python -m agent_service.observability.report`` integration."""

    def test_cli_writes_report(self, tmp_path: Path) -> None:
        records_path = tmp_path / "records.jsonl"
        out_path = tmp_path / "report.md"

        store = JSONLStorage(records_path)
        for record in _diverse_records():
            store.append(record)

        exit_code = report_main([
            "--records", str(records_path),
            "--out", str(out_path),
        ])
        assert exit_code == 0
        assert out_path.is_file()
        body = out_path.read_text(encoding="utf-8")
        # PHI scan: the report must not contain any planted markers.
        assert "Patient:" not in body
        assert "## Summary" in body
        assert "## Cost" in body

    def test_cli_handles_missing_records_file(self, tmp_path: Path) -> None:
        # No records file written -- still produces an empty report.
        records_path = tmp_path / "missing.jsonl"
        out_path = tmp_path / "report.md"
        exit_code = report_main([
            "--records", str(records_path),
            "--out", str(out_path),
        ])
        assert exit_code == 0
        assert out_path.is_file()
        assert "No run records available" in out_path.read_text(encoding="utf-8")
