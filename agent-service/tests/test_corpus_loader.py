"""Tests for the clinical guideline corpus loader."""

from __future__ import annotations

import re

import pytest

from agent_service.rag.corpus_loader import GuidelineChunk, load_corpus

# ---------------------------------------------------------------------------
# Patterns that indicate synthetic patient PHI
# ---------------------------------------------------------------------------
_SSN_PATTERN = re.compile(r"\b\d{3}-\d{2}-\d{4}\b")
_PATIENT_PREFIX_PATTERN = re.compile(r"\bPatient:\s", re.IGNORECASE)
_MRN_PATTERN = re.compile(r"\bMRN\s*[:#]?\s*\d+", re.IGNORECASE)
_DOB_LABEL_PATTERN = re.compile(r"\bDOB\s*:\s*\d", re.IGNORECASE)

_PHI_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    (_SSN_PATTERN, "SSN-like pattern"),
    (_PATIENT_PREFIX_PATTERN, "'Patient:' prefix"),
    (_MRN_PATTERN, "MRN identifier"),
    (_DOB_LABEL_PATTERN, "DOB label with data"),
]


@pytest.fixture(scope="module")
def corpus() -> list[GuidelineChunk]:
    """Load the built-in corpus once for the entire test module."""
    return load_corpus()


class TestCorpusLoader:
    """Tests for load_corpus() and the built-in guideline chunks."""

    def test_returns_all_chunks(self, corpus: list[GuidelineChunk]) -> None:
        """Loader returns at least 50 guideline chunks."""
        assert len(corpus) >= 50, f"Expected >= 50 chunks, got {len(corpus)}"

    def test_chunk_ids_are_unique(self, corpus: list[GuidelineChunk]) -> None:
        """Every chunk_id in the corpus must be unique."""
        ids = [c.chunk_id for c in corpus]
        assert len(ids) == len(set(ids)), "Duplicate chunk_ids detected"

    def test_every_chunk_has_citation_metadata(self, corpus: list[GuidelineChunk]) -> None:
        """Every chunk has all required citation metadata fields populated."""
        for chunk in corpus:
            assert chunk.chunk_id, f"Missing chunk_id on chunk: {chunk}"
            assert chunk.source_url, f"Missing source_url on chunk: {chunk.chunk_id}"
            assert chunk.section, f"Missing section on chunk: {chunk.chunk_id}"
            assert chunk.published, f"Missing published on chunk: {chunk.chunk_id}"
            assert chunk.text, f"Missing text on chunk: {chunk.chunk_id}"

    def test_published_date_format(self, corpus: list[GuidelineChunk]) -> None:
        """Published dates are in ISO 8601 YYYY-MM-DD format."""
        date_pattern = re.compile(r"^\d{4}-\d{2}-\d{2}$")
        for chunk in corpus:
            assert date_pattern.match(chunk.published), (
                f"Chunk {chunk.chunk_id}: published date '{chunk.published}' "
                f"is not in YYYY-MM-DD format"
            )

    def test_source_url_is_valid(self, corpus: list[GuidelineChunk]) -> None:
        """Source URLs start with https://."""
        for chunk in corpus:
            assert chunk.source_url.startswith("https://"), (
                f"Chunk {chunk.chunk_id}: source_url does not start with https://"
            )

    def test_no_synthetic_phi(self, corpus: list[GuidelineChunk]) -> None:
        """No chunk contains patterns indicative of synthetic patient PHI."""
        for chunk in corpus:
            full_text = f"{chunk.chunk_id} {chunk.section} {chunk.text}"
            for pattern, label in _PHI_PATTERNS:
                assert not pattern.search(full_text), (
                    f"Chunk {chunk.chunk_id}: found {label} in text — "
                    f"corpus must contain only public clinical guidelines, not patient data"
                )

    def test_chunk_text_length(self, corpus: list[GuidelineChunk]) -> None:
        """Chunk text should be a reasonable length (non-trivial content)."""
        for chunk in corpus:
            word_count = len(chunk.text.split())
            assert word_count >= 30, (
                f"Chunk {chunk.chunk_id}: text has only {word_count} words, "
                f"expected at least 30"
            )

    def test_chunk_id_format(self, corpus: list[GuidelineChunk]) -> None:
        """Chunk IDs follow the expected naming convention."""
        id_pattern = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*-\d{3}$")
        for chunk in corpus:
            assert id_pattern.match(chunk.chunk_id), (
                f"Chunk ID '{chunk.chunk_id}' does not match expected pattern "
                f"(lowercase-kebab-case ending with 3-digit number)"
            )
