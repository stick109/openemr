"""CLI entrypoint for the offline eval runner.

Usage examples
--------------

Run the full eval against the recorded baseline::

    py -m agent_service.eval --baseline agent_service/eval/baseline.json

Inject the ``drop-citations`` regression and verify that the
``citation_present`` rubric fails::

    py -m agent_service.eval \
        --baseline agent_service/eval/baseline.json \
        --inject-regression drop-citations

Inject a wrong-value regression and verify that ``factually_consistent``
fails (lab values mutated, intake chief concerns rewritten)::

    py -m agent_service.eval \
        --baseline agent_service/eval/baseline.json \
        --inject-regression wrong-value

Flip every lab abnormal flag and watch ``factually_consistent`` collapse::

    py -m agent_service.eval \
        --baseline agent_service/eval/baseline.json \
        --inject-regression flip-abnormal-flags

Persist a per-case JSON report next to the baseline::

    py -m agent_service.eval \
        --baseline agent_service/eval/baseline.json \
        --output agent_service/eval/last_run.json

Exit codes
----------

* ``0`` -- every rubric meets its threshold and (if baseline exists)
  did not regress more than :data:`REGRESSION_TOLERANCE`.
* ``1`` -- one or more rubrics failed.  A structured failure summary
  is printed to stderr, listing the regressed rubric, the delta vs.
  baseline, and the affected fixture IDs.
* ``2`` -- argument parsing or fixture-loading error.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from agent_service.eval.runner import (
    SUPPORTED_REGRESSIONS,
    EvalReport,
    compare_to_baseline,
    format_failure_summary,
    format_pass_rate_table,
    load_baseline,
    run_eval,
    write_baseline,
)
from agent_service.observability.storage import JSONLStorage


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m agent_service.eval",
        description=(
            "Run the offline 50-case eval and report per-rubric pass rates. "
            "Compares against a baseline and exits non-zero on regression."
        ),
    )
    parser.add_argument(
        "--baseline",
        type=Path,
        default=Path(__file__).resolve().parent / "baseline.json",
        help=(
            "Path to a baseline JSON file with previous pass rates. "
            "If the file does not exist, the runner writes the current "
            "results to it and exits 0."
        ),
    )
    parser.add_argument(
        "--inject-regression",
        choices=SUPPORTED_REGRESSIONS,
        default=None,
        help=(
            "Apply a regression hook to every fixture's recorded "
            "extraction response.  drop-citations strips source "
            "citations (breaks citation_present); wrong-value mutates "
            "key extracted fields (breaks factually_consistent); "
            "flip-abnormal-flags swaps high<->low on every lab row "
            "(breaks factually_consistent harder)."
        ),
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=None,
        help="Optional path to write per-case results as JSON.",
    )
    parser.add_argument(
        "--record-runs",
        type=Path,
        default=None,
        help=(
            "Optional path to a JSONL file. When supplied, the runner writes "
            "a sanitized RunRecord per fixture for downstream cost/latency "
            "reporting.  Off by default to preserve historical behaviour."
        ),
    )
    return parser


def _write_output(path: Path, report: EvalReport) -> None:
    """Serialise the per-case report to *path*."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report.as_dict(), indent=2) + "\n", encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    """CLI entrypoint -- returns the process exit code."""
    parser = _build_parser()
    args = parser.parse_args(argv)

    record_storage = (
        JSONLStorage(args.record_runs) if args.record_runs is not None else None
    )

    try:
        report = run_eval(
            inject_regression=args.inject_regression,
            record_storage=record_storage,
        )
    except FileNotFoundError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    print(format_pass_rate_table(report))

    if args.output is not None:
        _write_output(args.output, report)

    baseline_path: Path = args.baseline
    baseline = load_baseline(baseline_path)

    if baseline is None:
        # First run -- materialise the baseline and exit successfully.
        write_baseline(baseline_path, report)
        print(
            f"\nWrote baseline pass rates to {baseline_path}.",
            file=sys.stderr,
        )
        return 0

    passed, _failures = compare_to_baseline(report, baseline)
    if passed:
        print("\nAll rubrics meet thresholds and no regression detected.")
        return 0

    # Structured, demo-ready failure summary: per-rubric delta plus the
    # case IDs of the affected fixtures.
    print()
    print(format_failure_summary(report, baseline), file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
