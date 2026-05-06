"""CLI entrypoint for the observability report generator.

Delegates to :func:`agent_service.observability.report.main` so
``python -m agent_service.observability`` and
``python -m agent_service.observability.report`` behave identically.

Usage examples
--------------

Generate a report with the default record path::

    py -m agent_service.observability.report --out ../cost-latency-report.md

Override the record source for an ad-hoc report::

    py -m agent_service.observability.report \
        --records ./run-records.jsonl \
        --out ./report.md
"""

from __future__ import annotations

import sys

from agent_service.observability.report import main


if __name__ == "__main__":
    sys.exit(main())
