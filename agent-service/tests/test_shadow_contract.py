"""Shadow-mode wire-contract tests (M18).

These tests pin the subset of the ``CopilotRunResponse`` schema that the
PHP M18 ``ShadowComparator`` reads when comparing the legacy PHP path
against the Python sidecar in shadow mode.  They are deliberately
narrow: every assertion mirrors a field the PHP comparator references,
so an accidental rename or removal on the Python side surfaces as a
failed assertion here rather than as a silent shadow-mode regression
that would only show up at runtime.

The PHP comparator inspects:

* ``verification_status``
* ``citations[].source_id``
* ``missing_or_uncertain[]`` (length, not text)
* ``answer_blocks[].heading``

Plus JSON-serializability: the sidecar's response must round-trip
through ``json.dumps`` / ``json.loads`` so the PHP DTO can decode it
verbatim.
"""

from __future__ import annotations

import json
from typing import Any

from agent_service.schemas.copilot import CopilotRunResponse


SAMPLE_TRACE_ID = "aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee"


def _shadow_friendly_response_dict() -> dict[str, Any]:
    """Hand-authored response covering every field the PHP comparator reads."""
    return {
        "answer_blocks": [
            {
                "heading": "Current medications",
                "claims": [
                    {
                        "text": "Lisinopril 10 mg PO daily",
                        "citation_ids": ["med:1234"],
                        "certainty": "active",
                    },
                ],
                "body_markdown": None,
            },
            {
                "heading": "Allergies",
                "claims": [
                    {
                        "text": "No active allergies on file.",
                        "citation_ids": [],
                        "certainty": "not_found",
                    },
                ],
                "body_markdown": None,
            },
        ],
        "claims": [
            {
                "text": "Lisinopril 10 mg PO daily",
                "citation_ids": ["med:1234"],
                "certainty": "active",
            },
            {
                "text": "No active allergies on file.",
                "citation_ids": [],
                "certainty": "not_found",
            },
        ],
        "citation_ids": ["med:1234"],
        "certainty": "high",
        "missing_or_uncertain": [
            {
                "text": "Last refill date for atorvastatin not confirmed.",
                "citation_ids": [],
            },
        ],
        "citations": [
            {
                "source_type": "patient_record",
                "source_id": "med:1234",
                "label": "Active medication list",
                "url": None,
                "snippet": None,
            },
        ],
        "tool_sequence": [],
        "verification_status": "passed",
        "cost_usd": 0.0042,
        "latency_ms_per_step": {"plan": 5, "verify": 22},
        "trace_id": SAMPLE_TRACE_ID,
    }


class TestShadowContractFieldsRequiredByPhpComparator:
    """Each test pins one comparator-visible field."""

    def test_verification_status_is_present_and_a_string(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        assert isinstance(response.verification_status, str)
        assert response.verification_status == "passed"

    def test_each_citation_carries_a_source_id(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        assert len(response.citations) >= 1
        for citation in response.citations:
            assert isinstance(citation.source_id, str)
            assert citation.source_id != ""

    def test_missing_or_uncertain_is_a_list_with_known_length(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        # The PHP comparator only counts entries -- it does not read text.
        assert isinstance(response.missing_or_uncertain, list)
        assert len(response.missing_or_uncertain) == 1

    def test_answer_blocks_expose_heading_strings(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        headings = [block.heading for block in response.answer_blocks]
        assert headings == ["Current medications", "Allergies"]
        for heading in headings:
            assert isinstance(heading, str)
            assert heading.strip() != ""

    def test_trace_id_is_a_non_empty_string(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        assert isinstance(response.trace_id, str)
        assert response.trace_id == SAMPLE_TRACE_ID


class TestShadowContractWireSerialization:
    """Confirm the response shape PHP receives is JSON-decodable."""

    def test_response_is_json_round_trippable(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        wire = json.dumps(response.model_dump())
        decoded = json.loads(wire)

        # The PHP comparator walks the same dotted paths; verifying the
        # decoded shape matches the schema dump guarantees no silent loss
        # along the wire (e.g. enum -> str collapse, tuple -> list, etc.).
        assert decoded == response.model_dump()

    def test_decoded_shape_exposes_php_comparator_paths(self) -> None:
        response = CopilotRunResponse.model_validate(_shadow_friendly_response_dict())
        decoded = json.loads(json.dumps(response.model_dump()))

        # Mirrors the dotted paths the PHP comparator dereferences.
        assert "verification_status" in decoded
        assert "citations" in decoded and isinstance(decoded["citations"], list)
        for citation in decoded["citations"]:
            assert "source_id" in citation
        assert "missing_or_uncertain" in decoded
        assert isinstance(decoded["missing_or_uncertain"], list)
        assert "answer_blocks" in decoded
        assert isinstance(decoded["answer_blocks"], list)
        for block in decoded["answer_blocks"]:
            assert "heading" in block
            assert isinstance(block["heading"], str)
        assert "trace_id" in decoded


class TestShadowContractEmptyEdgeCases:
    """Shadow comparison must still work when the sidecar refuses or finds nothing."""

    def test_refused_response_with_no_citations_validates(self) -> None:
        payload = _shadow_friendly_response_dict()
        payload["verification_status"] = "refused"
        payload["citations"] = []
        payload["citation_ids"] = []
        payload["claims"] = []
        payload["answer_blocks"] = []
        payload["missing_or_uncertain"] = []
        payload["certainty"] = "low"

        response = CopilotRunResponse.model_validate(payload)

        assert response.verification_status == "refused"
        assert response.citations == []
        assert response.answer_blocks == []
        assert response.missing_or_uncertain == []
