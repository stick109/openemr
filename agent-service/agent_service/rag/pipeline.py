"""Full RAG retrieval pipeline: BM25 -> Dense -> Fusion -> Rerank.

Orchestrates sparse and dense retrieval, merges results via Reciprocal
Rank Fusion, and reranks the fused candidates to produce final results
with citation metadata.
"""

from __future__ import annotations

from dataclasses import dataclass

from agent_service.rag.bm25_index import BM25Index
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.fusion import reciprocal_rank_fusion
from agent_service.rag.reranker import FakeReranker, Reranker, RerankedChunk


@dataclass(frozen=True)
class RetrievalResult:
    """Final retrieval result with citation metadata and scores."""

    chunk_id: str
    source_url: str
    section: str
    snippet: str
    score: float


class RAGPipeline:
    """Orchestrates the full RAG retrieval pipeline.

    Parameters
    ----------
    bm25_index:
        Pre-built BM25 sparse index.
    dense_index:
        Pre-built dense vector index.
    reranker:
        Reranker implementation (real or fake).
    embed_fn:
        Callable that converts a query string to an embedding vector.
        Defaults to :func:`fake_embed` for testing.
    sparse_top_k:
        Number of candidates to retrieve from BM25.
    dense_top_k:
        Number of candidates to retrieve from the dense index.
    fusion_top_k:
        Number of candidates to keep after RRF fusion.
    """

    def __init__(
        self,
        bm25_index: BM25Index,
        dense_index: DenseIndex,
        reranker: Reranker | None = None,
        embed_fn: object = None,
        sparse_top_k: int = 20,
        dense_top_k: int = 20,
        fusion_top_k: int = 20,
    ) -> None:
        self._bm25 = bm25_index
        self._dense = dense_index
        self._reranker: Reranker = reranker or FakeReranker()
        self._embed_fn = embed_fn or fake_embed
        self._sparse_top_k = sparse_top_k
        self._dense_top_k = dense_top_k
        self._fusion_top_k = fusion_top_k

    def retrieve(self, query: str, top_k: int = 5) -> list[RetrievalResult]:
        """Run the full retrieval pipeline for *query*.

        Steps:
        1. BM25 sparse search
        2. Dense vector search
        3. Reciprocal Rank Fusion
        4. Reranking

        Parameters
        ----------
        query:
            Natural-language search query.
        top_k:
            Maximum number of final results.

        Returns
        -------
        list[RetrievalResult]
            Reranked results with citation metadata.
        """
        # Step 1: Sparse retrieval
        bm25_results = self._bm25.search(query, top_k=self._sparse_top_k)

        # Step 2: Dense retrieval
        query_embedding = self._embed_fn(query)
        dense_results = self._dense.search(
            query_embedding, top_k=self._dense_top_k
        )

        # Step 3: Reciprocal Rank Fusion
        fused = reciprocal_rank_fusion(
            bm25_results, dense_results, top_k=self._fusion_top_k
        )

        # Step 4: Rerank
        reranked: list[RerankedChunk] = self._reranker.rerank(
            query, fused, top_k=top_k
        )

        # Convert to RetrievalResult with citation metadata.
        return [
            RetrievalResult(
                chunk_id=r.chunk.chunk_id,
                source_url=r.chunk.source_url,
                section=r.chunk.section,
                snippet=r.snippet,
                score=r.score,
            )
            for r in reranked
        ]
