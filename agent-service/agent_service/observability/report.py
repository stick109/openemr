"""Markdown cost / latency report generator.

Loads :class:`RunRecord` documents from a JSONL store and aggregates
them into a developer-facing Markdown report with sections for:

* Summary -- total runs, success / refusal / error rates.
* Latency -- p50 / p95 / p99 of total latency, plus per-step p50/p95.
* Cost -- total dev spend, mean per-run cost, and projected daily cost
  at 100, 1 000 and 10 000 documents per day.
* Bottleneck analysis -- step with highest mean latency and the step
  with the largest p95-p50 spread.
* Retrieval stats -- mean hits per query and percentage with >= 5 hits.
* Confidence stats -- mean and p10/p50/p90 of extraction confidence.

Before returning the rendered Markdown, the generator runs the same
PHI scan used by the record validator over the entire string.  If any
SSN-like or ``Patient: <name>`` marker survives, generation aborts with
:class:`PhiInReportError`.

The module is also a CLI entry point (see ``__main__.py``).
"""

from __future__ import annotations

import argparse
import math
import statistics
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

from agent_service.observability.run_record import RunRecord, scan_for_phi
from agent_service.observability.storage import JSONLStorage


# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------


DEFAULT_RECORDS_PATH: Path = (
    Path(__file__).resolve().parent.parent / "eval" / "run-records.jsonl"
)
"""Default location written by the eval runner when records are enabled."""

DEFAULT_OUTPUT_PATH: Path = Path("./cost-latency-report.md").resolve()
"""Fallback Markdown output path if ``--out`` is not supplied."""

PROJECTION_VOLUMES: tuple[int, ...] = (100, 1_000, 10_000)
"""Document-per-day volumes used for the cost projection table."""

MIN_RETRIEVAL_HITS: int = 5
"""Threshold for the "queries with sufficient hits" retrieval stat."""


# ---------------------------------------------------------------------------
# Errors
# ---------------------------------------------------------------------------


class PhiInReportError(RuntimeError):
    """Raised when the generated Markdown report contains PHI markers."""


# ---------------------------------------------------------------------------
# Statistics helpers
# ---------------------------------------------------------------------------


def _percentile(values: list[float], pct: float) -> float:
    """Return the *pct* percentile of *values* using linear interpolation.

    ``pct`` is given on the [0, 100] scale.  Returns ``0.0`` for empty
    inputs so callers can render "n/a"-equivalent placeholders without
    branching.
    """
    if not values:
        return 0.0
    if len(values) == 1:
        return float(values[0])

    sorted_values = sorted(values)
    if pct <= 0:
        return float(sorted_values[0])
    if pct >= 100:
        return float(sorted_values[-1])

    # Linear interpolation (matches numpy's "linear" default).
    rank = (pct / 100.0) * (len(sorted_values) - 1)
    lower_index = int(math.floor(rank))
    upper_index = int(math.ceil(rank))
    if lower_index == upper_index:
        return float(sorted_values[lower_index])
    fraction = rank - lower_index
    lower = sorted_values[lower_index]
    upper = sorted_values[upper_index]
    return float(lower + (upper - lower) * fraction)


def _mean(values: Iterable[float]) -> float:
    """Return the arithmetic mean of *values* or ``0.0`` if empty."""
    materialised = list(values)
    if not materialised:
        return 0.0
    return statistics.fmean(materialised)


# ---------------------------------------------------------------------------
# Aggregation
# ---------------------------------------------------------------------------


def _per_step_latencies(records: list[RunRecord]) -> dict[str, list[float]]:
    """Group per-step latencies across every record by step name."""
    out: dict[str, list[float]] = {}
    for record in records:
        for step, ms in record.latency_ms_per_step.items():
            out.setdefault(step, []).append(float(ms))
    return out


def _format_ms(value: float) -> str:
    """Render a millisecond value with three significant figures."""
    if value <= 0:
        return "0.00 ms"
    return f"{value:.2f} ms"


def _format_usd(value: float) -> str:
    """Render a dollar amount with four decimal places of precision."""
    return f"${value:,.4f}"


# ---------------------------------------------------------------------------
# Report sections
# ---------------------------------------------------------------------------


def _render_summary(records: list[RunRecord]) -> str:
    """Render the Summary section."""
    total = len(records)
    if total == 0:
        return (
            "## Summary\n\n"
            "No run records available -- the report cannot summarise "
            "latency, cost, retrieval, or confidence.\n"
        )

    success = sum(1 for r in records if r.status == "success")
    refused = sum(1 for r in records if r.status == "refused")
    errored = sum(1 for r in records if r.status == "error")

    return (
        "## Summary\n\n"
        f"- Total runs: **{total}**\n"
        f"- Success rate: **{success / total:.2%}** ({success}/{total})\n"
        f"- Refusal rate: **{refused / total:.2%}** ({refused}/{total})\n"
        f"- Error rate: **{errored / total:.2%}** ({errored}/{total})\n"
    )


def _render_latency(records: list[RunRecord]) -> str:
    """Render the Latency section: total latency p50/p95/p99 + per-step."""
    if not records:
        return (
            "## Latency\n\n"
            "No latency data available.\n"
        )

    totals = [float(r.total_latency_ms) for r in records]
    p50 = _percentile(totals, 50.0)
    p95 = _percentile(totals, 95.0)
    p99 = _percentile(totals, 99.0)

    lines: list[str] = [
        "## Latency",
        "",
        "### Total latency (ms)",
        "",
        "| Percentile | Value |",
        "| ---------- | ----- |",
        f"| p50 | {_format_ms(p50)} |",
        f"| p95 | {_format_ms(p95)} |",
        f"| p99 | {_format_ms(p99)} |",
        "",
        "### Per-step latency",
        "",
        "| Step | p50 | p95 | mean |",
        "| ---- | --- | --- | ---- |",
    ]
    per_step = _per_step_latencies(records)
    if not per_step:
        lines.append("| _(none recorded)_ | - | - | - |")
    else:
        for step in sorted(per_step):
            samples = per_step[step]
            lines.append(
                f"| {step} "
                f"| {_format_ms(_percentile(samples, 50.0))} "
                f"| {_format_ms(_percentile(samples, 95.0))} "
                f"| {_format_ms(_mean(samples))} |"
            )
    lines.append("")
    return "\n".join(lines)


def _render_cost(records: list[RunRecord]) -> str:
    """Render the Cost section, including projected costs at standard volumes."""
    if not records:
        return (
            "## Cost\n\n"
            "No cost data available.\n"
        )

    total_spend = sum(float(r.cost_usd) for r in records)
    mean_per_run = _mean(float(r.cost_usd) for r in records)

    lines: list[str] = [
        "## Cost",
        "",
        f"- Total dev spend (across {len(records)} runs): "
        f"**{_format_usd(total_spend)}**",
        f"- Mean cost per run: **{_format_usd(mean_per_run)}**",
        "",
        "### Projected daily cost (at mean per-run cost)",
        "",
        "| Documents / day | Projected cost |",
        "| --------------- | -------------- |",
    ]
    for volume in PROJECTION_VOLUMES:
        projected = mean_per_run * volume
        lines.append(f"| {volume:,} | {_format_usd(projected)} |")
    lines.append("")
    return "\n".join(lines)


def _render_bottlenecks(records: list[RunRecord]) -> str:
    """Render the Bottleneck Analysis section."""
    per_step = _per_step_latencies(records)
    if not per_step:
        return (
            "## Bottleneck analysis\n\n"
            "No per-step latency samples available.\n"
        )

    means: dict[str, float] = {step: _mean(samples) for step, samples in per_step.items()}
    spreads: dict[str, float] = {
        step: _percentile(samples, 95.0) - _percentile(samples, 50.0)
        for step, samples in per_step.items()
    }

    highest_mean = max(means, key=lambda step: means[step])
    highest_spread = max(spreads, key=lambda step: spreads[step])

    return (
        "## Bottleneck analysis\n\n"
        f"- Highest mean latency: **{highest_mean}** "
        f"({_format_ms(means[highest_mean])} mean)\n"
        f"- Largest p95-p50 spread: **{highest_spread}** "
        f"({_format_ms(spreads[highest_spread])} spread)\n"
    )


def _render_retrieval(records: list[RunRecord]) -> str:
    """Render the Retrieval Stats section."""
    if not records:
        return (
            "## Retrieval stats\n\n"
            "No retrieval data available.\n"
        )

    hits = [int(r.retrieval_hit_count) for r in records]
    mean_hits = _mean(hits)
    qualifying = sum(1 for h in hits if h >= MIN_RETRIEVAL_HITS)
    qualifying_pct = qualifying / len(hits)

    return (
        "## Retrieval stats\n\n"
        f"- Mean hits per query: **{mean_hits:.2f}**\n"
        f"- Queries with >= {MIN_RETRIEVAL_HITS} hits: "
        f"**{qualifying_pct:.2%}** ({qualifying}/{len(hits)})\n"
    )


def _render_confidence(records: list[RunRecord]) -> str:
    """Render the Confidence Stats section."""
    if not records:
        return (
            "## Confidence stats\n\n"
            "No extraction-confidence data available.\n"
        )

    confidences = [float(r.extraction_confidence) for r in records]
    return (
        "## Confidence stats\n\n"
        f"- Mean extraction confidence: **{_mean(confidences):.3f}**\n"
        f"- p10: **{_percentile(confidences, 10.0):.3f}**\n"
        f"- p50: **{_percentile(confidences, 50.0):.3f}**\n"
        f"- p90: **{_percentile(confidences, 90.0):.3f}**\n"
    )


# ---------------------------------------------------------------------------
# Top-level rendering
# ---------------------------------------------------------------------------


def generate_report(records: list[RunRecord]) -> str:
    """Aggregate *records* into the full Markdown report string.

    The generated report is run through the PHI scanner before being
    returned; if any marker survives the sanitisation upstream, this
    raises :class:`PhiInReportError`.
    """
    timestamp = datetime.now(timezone.utc).isoformat()
    sections: list[str] = [
        "# Cost / Latency Report",
        "",
        f"_Generated: {timestamp}_",
        f"_Records analysed: {len(records)}_",
        "",
        _render_summary(records),
        _render_latency(records),
        _render_cost(records),
        _render_bottlenecks(records),
        _render_retrieval(records),
        _render_confidence(records),
    ]
    report = "\n".join(sections).rstrip() + "\n"

    hits = scan_for_phi(report)
    if hits:
        raise PhiInReportError(
            "Generated report contains PHI markers: " + "; ".join(hits),
        )
    return report


def write_report(records: list[RunRecord], output_path: Path) -> Path:
    """Render the report and write it to *output_path*; return the path."""
    report = generate_report(records)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(report, encoding="utf-8")
    return output_path


def load_records(records_path: Path) -> list[RunRecord]:
    """Load records from *records_path* using :class:`JSONLStorage`.

    A missing file returns an empty list -- the report renderer
    explicitly handles the empty case so the CLI does not need to
    error out on first run when no records have been emitted yet.
    """
    return JSONLStorage(records_path).load_all()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m agent_service.observability.report",
        description=(
            "Aggregate sanitized run records into a Markdown cost/latency "
            "report for the development team."
        ),
    )
    parser.add_argument(
        "--records",
        type=Path,
        default=DEFAULT_RECORDS_PATH,
        help=(
            "Path to the JSONL store of RunRecord documents. "
            f"Defaults to {DEFAULT_RECORDS_PATH}."
        ),
    )
    parser.add_argument(
        "--out",
        type=Path,
        default=DEFAULT_OUTPUT_PATH,
        help=(
            "Path to write the generated Markdown report. "
            f"Defaults to {DEFAULT_OUTPUT_PATH}."
        ),
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    """CLI entrypoint -- returns the process exit code."""
    parser = _build_parser()
    args = parser.parse_args(argv)

    records_path: Path = args.records
    output_path: Path = args.out

    records = load_records(records_path)
    try:
        written_to = write_report(records, output_path)
    except PhiInReportError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    print(
        f"Wrote cost/latency report ({len(records)} records analysed) to "
        f"{written_to}",
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
