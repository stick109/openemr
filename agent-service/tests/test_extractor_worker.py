"""Tests for the ExtractorWorker (S9).

Validates that the extractor worker correctly:
- Extracts and validates LabPdf from fake LLM responses
- Extracts and validates IntakeForm from fake LLM responses
- Retries once with validation feedback on malformed responses
- Refuses (returns error) on repeated validation failure
- Never returns partial extraction as success
- Preserves trace_id through the worker
"""

from __future__ import annotations

from typing import Any

import pytest

from agent_service.clients.openai_client import FakeLLMClient
from agent_service.workers.extractor import ExtractorWorker


# ---------------------------------------------------------------------------
# Helpers -- valid fixture data
# ---------------------------------------------------------------------------

_TRACE_ID = "550e8400-e29b-41d4-a716-446655440000"


def _valid_citation(**overrides: object) -> dict[str, object]:
    """Return a minimal valid SourceCitation dict."""
    data: dict[str, object] = {
        "page": 1,
        "bbox": [72.0, 200.0, 540.0, 230.0],
        "field_name": "hemoglobin",
    }
    data.update(overrides)
    return data


def _valid_lab_result(**overrides: object) -> dict[str, object]:
    """Return a minimal valid LabResult dict."""
    data: dict[str, object] = {
        "test_name": "Hemoglobin",
        "value": "14.2",
        "unit": "g/dL",
        "reference_range": "13.5-17.5",
        "collection_date": "2026-05-01",
        "abnormal_flag": "normal",
        "source_citation": _valid_citation(),
    }
    data.update(overrides)
    return data


def _valid_lab_pdf_dict(**overrides: object) -> dict[str, Any]:
    """Return a dict that validates as LabPdf."""
    data: dict[str, Any] = {
        "results": [_valid_lab_result()],
        "extraction_confidence": 0.95,
        "patient_name": "John Doe",
        "ordering_provider": "Dr. Smith",
        "lab_name": "Quest Diagnostics",
    }
    data.update(overrides)
    return data


def _valid_intake_form_dict(**overrides: object) -> dict[str, Any]:
    """Return a dict that validates as IntakeForm."""
    data: dict[str, Any] = {
        "demographics": {"name": "Jane Doe", "dob": "1985-03-15", "gender": "female"},
        "chief_concern": "Persistent headache for 2 weeks",
        "current_medications": [{"name": "Ibuprofen", "dosage": "400mg", "frequency": "as needed"}],
        "allergies": [{"allergen": "Penicillin", "reaction": "rash", "severity": "moderate"}],
        "family_history": [{"relation": "mother", "condition": "hypertension"}],
        "source_citations": [_valid_citation(field_name="demographics")],
        "extraction_confidence": 0.88,
    }
    data.update(overrides)
    return data


def _make_state(
    *,
    file_path: str = "/tmp/test.pdf",
    doc_type: str = "lab_pdf",
    trace_id: str = _TRACE_ID,
) -> dict[str, Any]:
    """Build a minimal graph state dict."""
    return {
        "file_path": file_path,
        "doc_type": doc_type,
        "trace_id": trace_id,
    }


def _make_fake_client(
    file_path: str = "/tmp/test.pdf",
    extract_response: dict[str, Any] | None = None,
) -> FakeLLMClient:
    """Build a FakeLLMClient wired to return *extract_response* for *file_path*."""
    # The fake client maps file_id -> response.  upload_pdf returns a
    # deterministic fake file ID for the given path, so we need to use
    # the same key.
    fake = FakeLLMClient(allow_env_key=True)
    file_id = fake.upload_pdf(file_path)
    # Reset calls so upload from setup is not counted.
    fake.calls.clear()

    if extract_response is not None:
        fake.extract_responses[file_id] = extract_response
    return fake


# ===================================================================
# Lab PDF extraction -- happy path
# ===================================================================


class TestLabPdfExtraction:
    """Fake OpenAI response validates into LabPdf schema successfully."""

    def test_valid_lab_pdf_returns_extracted(self) -> None:
        response = _valid_lab_pdf_dict()
        client = _make_fake_client(extract_response=response)
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="lab_pdf"))

        assert "error" not in result
        assert "extracted" in result
        assert result["extracted"]["results"][0]["test_name"] == "Hemoglobin"
        assert result["extraction_confidence"] == 0.95

    def test_doc_type_preserved_for_lab_pdf(self) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="lab_pdf"))

        assert result["doc_type"] == "lab_pdf"

    def test_upload_and_extract_called(self) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)

        worker.run(_make_state(doc_type="lab_pdf"))

        methods = [c.method for c in client.calls]
        assert "upload_pdf" in methods
        assert "extract_structured" in methods

    def test_multiple_lab_results(self) -> None:
        response = _valid_lab_pdf_dict(
            results=[
                _valid_lab_result(test_name="Hemoglobin"),
                _valid_lab_result(
                    test_name="WBC",
                    value="7.5",
                    unit="K/uL",
                    reference_range="4.5-11.0",
                ),
            ]
        )
        client = _make_fake_client(extract_response=response)
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="lab_pdf"))

        assert "error" not in result
        assert len(result["extracted"]["results"]) == 2


# ===================================================================
# Intake form extraction -- happy path
# ===================================================================


class TestIntakeFormExtraction:
    """Fake OpenAI response validates into IntakeForm schema successfully."""

    def test_valid_intake_form_returns_extracted(self) -> None:
        response = _valid_intake_form_dict()
        client = _make_fake_client(extract_response=response)
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="intake_form"))

        assert "error" not in result
        assert "extracted" in result
        assert result["extracted"]["chief_concern"] == "Persistent headache for 2 weeks"
        assert result["extraction_confidence"] == 0.88

    def test_doc_type_preserved_for_intake_form(self) -> None:
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="intake_form"))

        assert result["doc_type"] == "intake_form"

    def test_intake_form_with_empty_optional_lists(self) -> None:
        response = _valid_intake_form_dict(
            current_medications=[],
            allergies=[],
            family_history=[],
        )
        client = _make_fake_client(extract_response=response)
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="intake_form"))

        assert "error" not in result
        assert result["extracted"]["current_medications"] == []
        assert result["extracted"]["allergies"] == []


# ===================================================================
# Retry on malformed response
# ===================================================================


class TestRetryOnMalformed:
    """Malformed response (missing required field) retries once with feedback."""

    def test_retry_succeeds_on_second_attempt(self) -> None:
        """First response missing required field, second is valid."""
        file_path = "/tmp/retry_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        # First call returns malformed data (empty results list)
        malformed = _valid_lab_pdf_dict(results=[])
        valid = _valid_lab_pdf_dict()

        call_count = 0

        def patched_extract(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            nonlocal call_count
            call_count += 1
            if call_count == 1:
                return malformed
            return valid

        fake.extract_structured = patched_extract  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert "error" not in result
        assert "extracted" in result
        assert call_count == 2  # one attempt + one retry

    def test_retry_prompt_contains_validation_errors(self) -> None:
        """The retry prompt includes the validation error feedback."""
        file_path = "/tmp/feedback_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])  # violates min_length=1
        valid = _valid_lab_pdf_dict()

        prompts_seen: list[str] = []
        call_count = 0

        def tracking_extract(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            nonlocal call_count
            call_count += 1
            prompts_seen.append(prompt)
            if call_count == 1:
                return malformed
            return valid

        fake.extract_structured = tracking_extract  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert len(prompts_seen) == 2
        # The retry prompt should contain validation error feedback
        assert "VALIDATION ERRORS" in prompts_seen[1]
        assert "fix" in prompts_seen[1].lower()


# ===================================================================
# Refusal on repeated validation failure
# ===================================================================


class TestRefusalOnRepeatedFailure:
    """Second malformed response after retry produces refusal/error."""

    def test_double_failure_returns_error(self) -> None:
        """Both first and retry attempts fail validation -> error in state."""
        file_path = "/tmp/refuse_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        # Both attempts return malformed data (empty results violates min_length=1)
        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert "error" in result
        assert "extracted" not in result

    def test_double_failure_for_intake_form(self) -> None:
        """IntakeForm double failure also returns error."""
        file_path = "/tmp/refuse_intake.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        file_id = fake.upload_pdf(file_path)
        fake.calls.clear()

        # Missing required chief_concern field
        malformed: dict[str, Any] = {
            "demographics": {},
            "source_citations": [_valid_citation(field_name="demographics")],
            "extraction_confidence": 0.5,
        }

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="intake_form"))

        assert "error" in result
        assert "extracted" not in result

    def test_error_contains_validation_details(self) -> None:
        """The error message includes validation details."""
        file_path = "/tmp/details_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert "error" in result
        assert "validation" in result["error"].lower()


# ===================================================================
# No partial extraction returned as success
# ===================================================================


class TestNoPartialExtraction:
    """No partial extraction is returned as success."""

    def test_missing_required_field_not_returned_as_success(self) -> None:
        """A response missing a required field must not appear in 'extracted'."""
        file_path = "/tmp/partial_test.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        fake.upload_pdf(file_path)
        fake.calls.clear()

        # Missing extraction_confidence (required)
        partial: dict[str, Any] = {
            "results": [_valid_lab_result()],
            # extraction_confidence is missing
        }

        def always_partial(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return partial

        fake.extract_structured = always_partial  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert "error" in result
        assert "extracted" not in result

    def test_invalid_confidence_not_returned_as_success(self) -> None:
        """extraction_confidence outside [0, 1] must not pass."""
        file_path = "/tmp/bad_conf.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        fake.upload_pdf(file_path)
        fake.calls.clear()

        bad = _valid_lab_pdf_dict(extraction_confidence=2.0)

        def always_bad(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return bad

        fake.extract_structured = always_bad  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        result = worker.run(_make_state(file_path=file_path, doc_type="lab_pdf"))

        assert "error" in result
        assert "extracted" not in result

    def test_success_result_always_has_extracted_key(self) -> None:
        """A successful run always includes 'extracted' and no 'error'."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="lab_pdf"))

        assert "extracted" in result
        assert "error" not in result
        # Verify the extracted data is complete (not partial)
        assert "results" in result["extracted"]
        assert "extraction_confidence" in result["extracted"]


# ===================================================================
# Trace ID preservation
# ===================================================================


class TestTraceIdPreservation:
    """trace_id is preserved through the worker."""

    def test_trace_id_preserved_on_success(self) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)
        custom_trace = "12345678-1234-4234-8234-123456789abc"

        result = worker.run(_make_state(trace_id=custom_trace))

        assert result["trace_id"] == custom_trace

    def test_trace_id_preserved_on_error(self) -> None:
        file_path = "/tmp/trace_err.pdf"
        fake = FakeLLMClient(allow_env_key=True)
        fake.upload_pdf(file_path)
        fake.calls.clear()

        malformed = _valid_lab_pdf_dict(results=[])

        def always_malformed(fid: str, schema: type, prompt: str) -> dict[str, Any]:
            return malformed

        fake.extract_structured = always_malformed  # type: ignore[assignment]

        worker = ExtractorWorker(fake)
        custom_trace = "abcdef01-2345-4678-9abc-def012345678"
        result = worker.run(_make_state(file_path=file_path, trace_id=custom_trace))

        assert result["trace_id"] == custom_trace

    def test_different_trace_ids_are_independent(self) -> None:
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)

        result_a = worker.run(_make_state(trace_id="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"))
        result_b = worker.run(_make_state(trace_id="bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb"))

        assert result_a["trace_id"] == "aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
        assert result_b["trace_id"] == "bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb"


# ===================================================================
# Auto doc type detection
# ===================================================================


class TestAutoDocType:
    """Auto mode resolves the document type from extraction content."""

    def test_auto_resolves_to_lab_pdf(self) -> None:
        """Response with 'results' key resolves to lab_pdf."""
        client = _make_fake_client(extract_response=_valid_lab_pdf_dict())
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="auto"))

        assert "error" not in result
        assert result["doc_type"] == "lab_pdf"
        assert "extracted" in result

    def test_auto_resolves_to_intake_form(self) -> None:
        """Response with 'chief_concern' key resolves to intake_form."""
        client = _make_fake_client(extract_response=_valid_intake_form_dict())
        worker = ExtractorWorker(client)

        result = worker.run(_make_state(doc_type="auto"))

        assert "error" not in result
        assert result["doc_type"] == "intake_form"
        assert "extracted" in result
