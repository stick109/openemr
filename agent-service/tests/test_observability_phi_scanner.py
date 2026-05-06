"""Tests for the shared observability PHI scanner (M16).

The scanner was extracted out of :mod:`agent_service.observability.run_record`
during M16 so :class:`RunEvent` and :class:`RunRecord` could share one set
of detection rules.  These tests exercise both the legacy surface (used
by ``RunRecord``) and the broader event-level surface (used by
``RunEvent``):

* :func:`scan_for_phi` -- SSN dashed, ``Patient: <name>`` markers.
* :func:`scan_event_field_for_phi` -- adds email, phone, address.

Negative tests guard against false positives on common safe strings
(tool names, refusal reasons, exception class names) so the scanner
does not start refusing valid agent-loop event values.
"""

from __future__ import annotations

import pytest

from agent_service.observability._phi_scanner import (
    scan_event_field_for_phi,
    scan_for_phi,
)


# ---------------------------------------------------------------------------
# scan_for_phi -- legacy RunRecord surface
# ---------------------------------------------------------------------------


class TestScanForPhi:
    """The legacy scanner only detects SSN-dashed and Patient: markers."""

    def test_clean_returns_empty(self) -> None:
        assert scan_for_phi("trace-001", "lab_pdf", "gpt-4o", "success") == []

    def test_empty_strings_skipped(self) -> None:
        assert scan_for_phi("", "  ") == []

    def test_ssn_dashed_detected(self) -> None:
        hits = scan_for_phi("patient SSN 123-45-6789 today")
        assert any(h.startswith("ssn-like:") for h in hits)

    def test_patient_name_colon_detected(self) -> None:
        hits = scan_for_phi("Patient: Jane Doe was seen")
        assert any(h.startswith("patient-name:") for h in hits)

    def test_patient_name_equals_detected(self) -> None:
        hits = scan_for_phi("Patient = John Smith")
        assert any(h.startswith("patient-name:") for h in hits)

    def test_no_email_detection_in_legacy_scanner(self) -> None:
        # The legacy scanner does not catch email patterns because S25
        # records never carry free-form text where they could appear.
        hits = scan_for_phi("alice@example.com")
        assert hits == []


# ---------------------------------------------------------------------------
# scan_event_field_for_phi -- M16 event surface (broader)
# ---------------------------------------------------------------------------


class TestScanEventFieldForPhi:
    """The event scanner unions the legacy detectors with email/phone/address."""

    @pytest.mark.parametrize(
        "phi_value,expected_prefix",
        [
            ("123-45-6789", "ssn-like"),
            ("Patient: Jane Doe", "patient-name"),
            ("Patient = John Smith", "patient-name"),
            ("alice.smith@example.com", "email"),
            ("call 555-867-5309 if needed", "phone"),
            ("123 Main Street", "address"),
        ],
    )
    def test_phi_pattern_detected(
        self, phi_value: str, expected_prefix: str
    ) -> None:
        hits = scan_event_field_for_phi(phi_value)
        assert any(h.startswith(f"{expected_prefix}:") for h in hits), (
            f"expected {expected_prefix} hit for {phi_value!r}; got {hits}"
        )

    def test_clean_event_field_returns_empty(self) -> None:
        # Real RunEvent string-typed values: trace IDs, tool names,
        # refusal reasons, error class names, event types.  None of
        # these should trip any detector.
        safe_values = [
            "trace-16-events",
            "trace-abc-001",
            "get_current_medications",
            "get_active_allergies",
            "tool_error",
            "fabricated_citation",
            "phi_in_output",
            "out_of_scope",
            "unsupported",
            "missing_data",
            "ToolNotAllowed",
            "ZeroDivisionError",
            "RuntimeError",
            "executor_raised",
            "context_expired",
            "tool.finished",
            "model.turn.started",
            "verifier.finished",
            "response.returned",
        ]
        for value in safe_values:
            assert scan_event_field_for_phi(value) == [], (
                f"false positive on {value!r}: {scan_event_field_for_phi(value)}"
            )

    def test_phone_requires_separator(self) -> None:
        # A bare 10-digit run (e.g. a request ID or counter) must not
        # be flagged as a phone number -- the detector requires an
        # explicit ``-`` / ``.`` / space separator.
        assert scan_event_field_for_phi("5558675309") == []
        # But a clearly-formatted phone number is flagged.
        assert any(
            h.startswith("phone:")
            for h in scan_event_field_for_phi("555-867-5309")
        )

    def test_multiple_hits_across_inputs(self) -> None:
        hits = scan_event_field_for_phi(
            "Patient: Jane Doe",
            "alice@example.com",
        )
        kinds = {h.split(":")[0] for h in hits}
        assert "patient-name" in kinds
        assert "email" in kinds
