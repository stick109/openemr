"""Shared PHI-detection helper used by every observability sink.

The S25 ``RunRecord`` validator and the M16 ``RunEvent`` validator both
funnel string fields through the same scanner so a single fix to a
pattern updates every persistence layer at once.

Two scan surfaces are exposed:

* :func:`scan_for_phi` -- the conservative pair used by ``RunRecord``
  (SSN-like dashed pattern, ``Patient: <name>`` markers).  Behaviour is
  preserved verbatim from the original ``run_record.py`` location so
  existing PHI rejections in the cost/latency report layer still hold.

* :func:`scan_event_field_for_phi` -- the broader scanner used by
  ``RunEvent``.  Adds email, US phone, and street-address heuristics so
  per-tool-call event spans cannot leak data the cost/latency record
  schema already happened to keep at arm's length (events carry tool
  names, error class names, and refusal reasons, but the scanner runs
  defence-in-depth against any free-form text that ever sneaks in).
"""

from __future__ import annotations

import re
from typing import Final


# ---------------------------------------------------------------------------
# RunRecord-level patterns (kept identical to the historical S25 scanner)
# ---------------------------------------------------------------------------


_SSN_PATTERN: Final[re.Pattern[str]] = re.compile(r"\b\d{3}-\d{2}-\d{4}\b")
"""Matches XXX-XX-XXXX strings that look like US Social Security Numbers."""

_PATIENT_NAME_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\bPatient\s*[:=]\s*[A-Z][a-zA-Z'\-]+(?:\s+[A-Z][a-zA-Z'\-]+)+",
)
"""Matches ``Patient: Jane Doe`` / ``Patient = First Last`` markers."""


# ---------------------------------------------------------------------------
# Additional event-level patterns
# ---------------------------------------------------------------------------


_EMAIL_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b",
    re.IGNORECASE,
)
"""Matches RFC-ish email addresses."""


_PHONE_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b\d{3}[\.\-\s]\d{3}[\.\-\s]\d{4}\b",
)
"""Matches US-style 10-digit phone numbers with explicit separators.

We require the dot/dash/space separator (``.``, ``-``, or ASCII space)
rather than allowing the no-separator form to avoid false positives on
arbitrary 10-digit IDs that may legitimately appear in event names
(timestamps, request IDs, monotonic counters).
"""


_ADDRESS_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"\b\d{1,5}\s+([A-Z][a-zA-Z]+\s+){1,4}"
    r"(St|Street|Ave|Avenue|Blvd|Boulevard|Rd|Road|Dr|Drive|Ln|Lane|"
    r"Ct|Court|Way|Pl|Place|Pkwy|Parkway)"
    r"\b\.?",
)
"""Matches a US street-address heuristic identical to the verifier's."""


# ---------------------------------------------------------------------------
# Scan helpers
# ---------------------------------------------------------------------------


def scan_for_phi(*texts: str) -> list[str]:
    """Return human-readable descriptions of any PHI hits in *texts*.

    Empty / falsy strings are skipped.  The returned list is empty when
    the inputs are PHI-clean.  Behaviour matches the historical S25
    scanner exactly so the run-record validator and the report
    generator continue to reject the same patterns.
    """
    hits: list[str] = []
    for text in texts:
        if not text:
            continue
        for match in _SSN_PATTERN.finditer(text):
            hits.append(f"ssn-like: {match.group(0)}")
        for match in _PATIENT_NAME_PATTERN.finditer(text):
            hits.append(f"patient-name: {match.group(0)}")
    return hits


def scan_event_field_for_phi(*texts: str) -> list[str]:
    """Return PHI hit descriptions for a :class:`RunEvent` string field.

    Unions :func:`scan_for_phi` with email / phone / address detectors so
    per-event spans get the same defence-in-depth used by the verifier.
    """
    hits = scan_for_phi(*texts)
    for text in texts:
        if not text:
            continue
        for match in _EMAIL_PATTERN.finditer(text):
            hits.append(f"email: {match.group(0)}")
        for match in _PHONE_PATTERN.finditer(text):
            hits.append(f"phone: {match.group(0)}")
        for match in _ADDRESS_PATTERN.finditer(text):
            hits.append(f"address: {match.group(0)}")
    return hits


__all__ = [
    "scan_event_field_for_phi",
    "scan_for_phi",
]
