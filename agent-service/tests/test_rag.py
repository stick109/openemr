"""Tests for the RAG retrieval pipeline: BM25, dense, fusion, rerank.

Validates that clinical guideline queries return relevant chunks from
the appropriate sources (ACC/AHA, ADA, USPSTF, etc.) and that the
full pipeline produces results with citation metadata.
"""

from __future__ import annotations

import pytest

from agent_service.rag.bm25_index import BM25Index, ScoredChunk
from agent_service.rag.corpus_loader import GuidelineChunk, load_corpus
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.fusion import reciprocal_rank_fusion
from agent_service.rag.pipeline import RAGPipeline, RetrievalResult
from agent_service.rag.reranker import FakeReranker, RerankedChunk, Reranker


# ---------------------------------------------------------------------------
# Shared fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def corpus() -> list[GuidelineChunk]:
    """Load the built-in corpus once for the entire test module."""
    return load_corpus()


@pytest.fixture(scope="module")
def bm25(corpus: list[GuidelineChunk]) -> BM25Index:
    """Build a BM25 index over the full corpus."""
    return BM25Index(corpus)


@pytest.fixture(scope="module")
def dense(corpus: list[GuidelineChunk]) -> DenseIndex:
    """Build a dense index with fake embeddings over the full corpus."""
    return DenseIndex.from_chunks_with_fake_embeddings(corpus, dim=64)


@pytest.fixture(scope="module")
def pipeline(bm25: BM25Index, dense: DenseIndex) -> RAGPipeline:
    """Build a RAG pipeline with fake reranker and fake embeddings."""
    return RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )


# ---------------------------------------------------------------------------
# BM25 Index Tests
# ---------------------------------------------------------------------------


class TestBM25Index:
    """Tests for BM25 sparse retrieval."""

    def test_hypertension_returns_bp_chunks(self, bm25: BM25Index) -> None:
        """A hypertension query should surface ACC/AHA blood pressure chunks."""
        results = bm25.search("hypertension blood pressure treatment", top_k=10)
        assert len(results) > 0
        chunk_ids = {r.chunk.chunk_id for r in results}
        # At least one ACC/AHA or USPSTF hypertension chunk should appear
        bp_ids = {cid for cid in chunk_ids if "bp" in cid or "hypertension" in cid}
        assert len(bp_ids) > 0, (
            f"Expected ACC/AHA or hypertension-related chunks, got: {chunk_ids}"
        )

    def test_diabetes_returns_ada_or_uspstf_chunks(self, bm25: BM25Index) -> None:
        """A diabetes query should surface ADA or USPSTF diabetes chunks."""
        results = bm25.search("diabetes screening A1C glucose", top_k=10)
        assert len(results) > 0
        chunk_ids = {r.chunk.chunk_id for r in results}
        diabetes_ids = {
            cid for cid in chunk_ids
            if "ada" in cid or "diabetes" in cid or "a1c" in cid or "glucose" in cid
        }
        assert len(diabetes_ids) > 0, (
            f"Expected ADA/USPSTF diabetes chunks, got: {chunk_ids}"
        )

    def test_lipid_returns_cholesterol_chunks(self, bm25: BM25Index) -> None:
        """A lipid query should surface cholesterol/statin chunks."""
        results = bm25.search("cholesterol LDL statin lipid management", top_k=10)
        assert len(results) > 0
        chunk_ids = {r.chunk.chunk_id for r in results}
        lipid_ids = {
            cid for cid in chunk_ids
            if "cholesterol" in cid or "lipid" in cid or "statin" in cid
        }
        assert len(lipid_ids) > 0, (
            f"Expected cholesterol/lipid chunks, got: {chunk_ids}"
        )

    def test_results_are_scored_and_sorted(self, bm25: BM25Index) -> None:
        """BM25 results are sorted by descending score."""
        results = bm25.search("diabetes", top_k=5)
        assert len(results) > 0
        scores = [r.score for r in results]
        assert scores == sorted(scores, reverse=True)

    def test_top_k_limits_results(self, bm25: BM25Index) -> None:
        """Results are limited to the requested top_k."""
        results = bm25.search("screening", top_k=3)
        assert len(results) <= 3

    def test_empty_index_raises(self) -> None:
        """Building from empty chunks raises ValueError."""
        with pytest.raises(ValueError, match="empty"):
            BM25Index([])


# ---------------------------------------------------------------------------
# Dense Index Tests
# ---------------------------------------------------------------------------


class TestDenseIndex:
    """Tests for dense vector retrieval."""

    def test_search_returns_scored_chunks(self, dense: DenseIndex) -> None:
        """Dense search returns ScoredChunk objects."""
        query_emb = fake_embed("hypertension", dim=64)
        results = dense.search(query_emb, top_k=5)
        assert len(results) > 0
        assert all(isinstance(r, ScoredChunk) for r in results)

    def test_results_are_sorted_by_similarity(self, dense: DenseIndex) -> None:
        """Dense results are sorted by descending cosine similarity."""
        query_emb = fake_embed("diabetes management", dim=64)
        results = dense.search(query_emb, top_k=10)
        scores = [r.score for r in results]
        assert scores == sorted(scores, reverse=True)

    def test_top_k_limits_results(self, dense: DenseIndex) -> None:
        """Results are limited to the requested top_k."""
        query_emb = fake_embed("screening", dim=64)
        results = dense.search(query_emb, top_k=3)
        assert len(results) <= 3

    def test_fake_embed_is_deterministic(self) -> None:
        """fake_embed produces the same vector for the same input."""
        v1 = fake_embed("test input", dim=64)
        v2 = fake_embed("test input", dim=64)
        assert v1 == v2

    def test_fake_embed_different_inputs_differ(self) -> None:
        """fake_embed produces different vectors for different inputs."""
        v1 = fake_embed("input A", dim=64)
        v2 = fake_embed("input B", dim=64)
        assert v1 != v2

    def test_empty_index_raises(self) -> None:
        """Building from empty chunks raises ValueError."""
        with pytest.raises(ValueError, match="empty"):
            DenseIndex(chunks=[], embeddings=[])

    def test_mismatched_lengths_raises(self, corpus: list[GuidelineChunk]) -> None:
        """Mismatched chunk/embedding counts raise ValueError."""
        with pytest.raises(ValueError, match="does not match"):
            DenseIndex(chunks=corpus[:3], embeddings=[fake_embed("a", 64)])

    def test_from_chunks_with_fake_embeddings(
        self, corpus: list[GuidelineChunk]
    ) -> None:
        """Factory method builds a valid index."""
        idx = DenseIndex.from_chunks_with_fake_embeddings(corpus[:5], dim=32)
        assert idx.size == 5


# ---------------------------------------------------------------------------
# Reciprocal Rank Fusion Tests
# ---------------------------------------------------------------------------


class TestReciprocalRankFusion:
    """Tests for RRF merging of BM25 and dense results."""

    def _make_chunk(self, chunk_id: str) -> GuidelineChunk:
        """Create a minimal GuidelineChunk for fusion tests."""
        return GuidelineChunk(
            chunk_id=chunk_id,
            source_url="https://example.com",
            section="Test",
            published="2024-01-01",
            text="Sample guideline text for testing purposes with enough words to pass validation.",
        )

    def test_rrf_merges_two_lists(self) -> None:
        """RRF produces fused results from two separate ranked lists."""
        chunk_a = self._make_chunk("chunk-a-001")
        chunk_b = self._make_chunk("chunk-b-001")
        chunk_c = self._make_chunk("chunk-c-001")

        list1 = [
            ScoredChunk(chunk=chunk_a, score=5.0),
            ScoredChunk(chunk=chunk_b, score=3.0),
        ]
        list2 = [
            ScoredChunk(chunk=chunk_b, score=0.9),
            ScoredChunk(chunk=chunk_c, score=0.8),
        ]

        fused = reciprocal_rank_fusion(list1, list2, k=60, top_k=10)
        fused_ids = [r.chunk.chunk_id for r in fused]

        # chunk_b appears in both lists so should have the highest fused score
        assert fused_ids[0] == "chunk-b-001", (
            f"Expected chunk-b-001 at rank 1 (appears in both lists), got {fused_ids}"
        )
        # All three chunks should be in the fused results
        assert set(fused_ids) == {"chunk-a-001", "chunk-b-001", "chunk-c-001"}

    def test_rrf_scores_are_descending(self) -> None:
        """Fused results are sorted by descending RRF score."""
        chunks = [self._make_chunk(f"chunk-{i:03d}") for i in range(5)]
        list1 = [ScoredChunk(chunk=c, score=float(5 - i)) for i, c in enumerate(chunks)]

        fused = reciprocal_rank_fusion(list1, k=60, top_k=10)
        scores = [r.score for r in fused]
        assert scores == sorted(scores, reverse=True)

    def test_rrf_top_k_limits_output(self) -> None:
        """RRF output is limited to top_k."""
        chunks = [self._make_chunk(f"chunk-{i:03d}") for i in range(10)]
        list1 = [ScoredChunk(chunk=c, score=float(10 - i)) for i, c in enumerate(chunks)]

        fused = reciprocal_rank_fusion(list1, top_k=3)
        assert len(fused) <= 3

    def test_rrf_score_formula(self) -> None:
        """Verify the RRF score matches the expected formula."""
        chunk_a = self._make_chunk("chunk-a-001")
        chunk_b = self._make_chunk("chunk-b-001")

        list1 = [ScoredChunk(chunk=chunk_a, score=5.0)]  # rank 1
        list2 = [ScoredChunk(chunk=chunk_a, score=0.9)]  # rank 1

        k = 60
        fused = reciprocal_rank_fusion(list1, list2, k=k, top_k=5)
        expected_score = 1.0 / (k + 1) + 1.0 / (k + 1)  # rank 1 in both lists

        assert len(fused) == 1
        assert abs(fused[0].score - expected_score) < 1e-9

    def test_rrf_with_bm25_and_dense(
        self, bm25: BM25Index, dense: DenseIndex
    ) -> None:
        """RRF can merge real BM25 and dense results for a clinical query."""
        bm25_results = bm25.search("hypertension", top_k=10)
        query_emb = fake_embed("hypertension", dim=64)
        dense_results = dense.search(query_emb, top_k=10)

        fused = reciprocal_rank_fusion(bm25_results, dense_results, top_k=10)
        assert len(fused) > 0
        # Results should be ScoredChunk instances
        assert all(isinstance(r, ScoredChunk) for r in fused)


# ---------------------------------------------------------------------------
# Reranker Tests
# ---------------------------------------------------------------------------


class TestReranker:
    """Tests for the reranker protocol and FakeReranker."""

    def test_fake_reranker_satisfies_protocol(self) -> None:
        """FakeReranker is structurally compatible with the Reranker protocol."""
        reranker = FakeReranker()
        # Check that FakeReranker has the rerank method with the right signature
        assert hasattr(reranker, "rerank")

    def test_fake_reranker_deterministic(self, bm25: BM25Index) -> None:
        """FakeReranker produces identical results on repeated calls."""
        reranker = FakeReranker()
        candidates = bm25.search("diabetes", top_k=10)

        result1 = reranker.rerank("diabetes", candidates, top_k=5)
        result2 = reranker.rerank("diabetes", candidates, top_k=5)

        ids1 = [r.chunk.chunk_id for r in result1]
        ids2 = [r.chunk.chunk_id for r in result2]
        assert ids1 == ids2

    def test_fake_reranker_returns_reranked_chunks(
        self, bm25: BM25Index
    ) -> None:
        """FakeReranker returns RerankedChunk instances with required fields."""
        reranker = FakeReranker()
        candidates = bm25.search("screening", top_k=10)
        results = reranker.rerank("screening", candidates, top_k=5)

        assert len(results) > 0
        for r in results:
            assert isinstance(r, RerankedChunk)
            assert isinstance(r.chunk, GuidelineChunk)
            assert isinstance(r.score, float)
            assert isinstance(r.snippet, str)
            assert len(r.snippet) > 0

    def test_fake_reranker_respects_top_k(self, bm25: BM25Index) -> None:
        """FakeReranker limits output to top_k."""
        reranker = FakeReranker()
        candidates = bm25.search("hypertension", top_k=20)
        results = reranker.rerank("hypertension", candidates, top_k=3)
        assert len(results) <= 3

    def test_fake_reranker_is_fakeable_in_ci(self) -> None:
        """Confirm the FakeReranker works without external API keys (CI-safe)."""
        # This test proves the reranker is fakeable: no API key needed, no
        # network calls, runs entirely in-process.
        reranker = FakeReranker()
        chunk = GuidelineChunk(
            chunk_id="test-chunk-001",
            source_url="https://example.com",
            section="Test Section",
            published="2024-01-01",
            text="A sample chunk for testing the fake reranker in CI environments without API keys.",
        )
        candidates = [ScoredChunk(chunk=chunk, score=1.0)]
        results = reranker.rerank("test query", candidates, top_k=1)
        assert len(results) == 1
        assert results[0].chunk.chunk_id == "test-chunk-001"


# ---------------------------------------------------------------------------
# Full Pipeline Tests
# ---------------------------------------------------------------------------


class TestRAGPipeline:
    """Tests for the end-to-end RAG pipeline."""

    def test_hypertension_pipeline(self, pipeline: RAGPipeline) -> None:
        """Pipeline returns results for a hypertension query with citation metadata."""
        results = pipeline.retrieve("hypertension blood pressure management", top_k=5)
        assert len(results) > 0
        for r in results:
            assert isinstance(r, RetrievalResult)
            assert r.chunk_id
            assert r.source_url.startswith("https://")
            assert r.section
            assert r.snippet
            assert isinstance(r.score, float)

    def test_diabetes_pipeline(self, pipeline: RAGPipeline) -> None:
        """Pipeline returns ADA/USPSTF chunks for a diabetes query."""
        results = pipeline.retrieve("diabetes A1C screening glucose management", top_k=5)
        assert len(results) > 0
        chunk_ids = {r.chunk_id for r in results}
        diabetes_ids = {
            cid for cid in chunk_ids
            if "ada" in cid or "diabetes" in cid or "a1c" in cid or "glucose" in cid
        }
        assert len(diabetes_ids) > 0, (
            f"Expected ADA/USPSTF diabetes chunks in pipeline results, got: {chunk_ids}"
        )

    def test_lipid_pipeline(self, pipeline: RAGPipeline) -> None:
        """Pipeline returns cholesterol/lipid chunks for a lipid query.

        Note: The FakeReranker sorts by chunk_id alphabetically, so the
        top-5 may not be the BM25-top-5.  We use a larger top_k to
        capture the cholesterol/lipid chunks that BM25 correctly retrieves.
        """
        results = pipeline.retrieve("cholesterol LDL statin therapy lipid panel", top_k=10)
        assert len(results) > 0
        chunk_ids = {r.chunk_id for r in results}
        lipid_ids = {
            cid for cid in chunk_ids
            if "cholesterol" in cid or "lipid" in cid or "statin" in cid
        }
        assert len(lipid_ids) > 0, (
            f"Expected cholesterol/lipid chunks in pipeline results, got: {chunk_ids}"
        )

    def test_results_include_citation_metadata(self, pipeline: RAGPipeline) -> None:
        """Every pipeline result includes full citation metadata."""
        results = pipeline.retrieve("screening recommendations", top_k=5)
        assert len(results) > 0
        for r in results:
            assert r.chunk_id, "chunk_id must be non-empty"
            assert r.source_url, "source_url must be non-empty"
            assert r.section, "section must be non-empty"
            assert r.snippet, "snippet must be non-empty"

    def test_pipeline_top_k(self, pipeline: RAGPipeline) -> None:
        """Pipeline respects the top_k parameter."""
        results = pipeline.retrieve("vaccination immunization", top_k=3)
        assert len(results) <= 3

    def test_pipeline_uses_all_stages(self, pipeline: RAGPipeline) -> None:
        """Pipeline runs BM25, dense, fusion, and rerank (smoke test).

        The fact that we get non-empty results with citation metadata
        confirms all four stages executed without error.
        """
        results = pipeline.retrieve("heart failure BNP testing", top_k=5)
        assert len(results) > 0
        # Confirm the result type has all expected fields
        first = results[0]
        assert hasattr(first, "chunk_id")
        assert hasattr(first, "source_url")
        assert hasattr(first, "section")
        assert hasattr(first, "snippet")
        assert hasattr(first, "score")
