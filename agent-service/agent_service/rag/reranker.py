"""Reranker abstractions for post-retrieval relevance scoring.

Defines a :class:`Reranker` protocol and provides two implementations:

* :class:`CohereReranker` -- calls the Cohere rerank API (production).
* :class:`FakeReranker`   -- deterministic pass-through for CI/tests.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

from agent_service.rag.bm25_index import ScoredChunk
from agent_service.rag.corpus_loader import GuidelineChunk


@dataclass(frozen=True)
class RerankedChunk:
    """A chunk after reranking, with updated score and an extracted snippet."""

    chunk: GuidelineChunk
    score: float
    snippet: str


class Reranker(Protocol):
    """Protocol for reranking a list of candidate chunks against a query."""

    def rerank(
        self,
        query: str,
        chunks: list[ScoredChunk],
        top_k: int = 5,
    ) -> list[RerankedChunk]:
        """Rerank *chunks* by relevance to *query* and return the top-k.

        Parameters
        ----------
        query:
            Natural-language search query.
        chunks:
            Candidate chunks from the fusion stage.
        top_k:
            Maximum number of results to return.

        Returns
        -------
        list[RerankedChunk]
            Reranked chunks with updated scores and extracted snippets.
        """
        ...  # pragma: no cover


class FakeReranker:
    """Deterministic reranker for tests and CI.

    Sorts candidates by ``chunk_id`` in ascending alphabetical order and
    assigns linearly decreasing scores.  The snippet is the first 200
    characters of the chunk text.
    """

    def rerank(
        self,
        query: str,
        chunks: list[ScoredChunk],
        top_k: int = 5,
    ) -> list[RerankedChunk]:
        """Rerank by sorting on chunk_id (deterministic for tests)."""
        sorted_chunks = sorted(chunks, key=lambda sc: sc.chunk.chunk_id)
        results: list[RerankedChunk] = []
        for i, sc in enumerate(sorted_chunks[:top_k]):
            score = 1.0 - (i * 0.1)
            snippet = sc.chunk.text[:200]
            results.append(
                RerankedChunk(chunk=sc.chunk, score=score, snippet=snippet)
            )
        return results


class CohereReranker:
    """Reranker backed by the Cohere rerank API.

    Parameters
    ----------
    api_key:
        Cohere API key.
    model:
        Rerank model name.  Defaults to ``rerank-english-v3.0``.
    """

    def __init__(
        self,
        api_key: str,
        model: str = "rerank-english-v3.0",
    ) -> None:
        self._api_key = api_key
        self._model = model

    def rerank(
        self,
        query: str,
        chunks: list[ScoredChunk],
        top_k: int = 5,
    ) -> list[RerankedChunk]:
        """Call the Cohere rerank API to rescore chunks.

        This implementation lazily imports ``httpx`` to avoid a hard
        dependency in test environments.
        """
        import httpx  # noqa: PLC0415 -- lazy import

        documents = [sc.chunk.text for sc in chunks]
        chunk_map = {sc.chunk.text: sc.chunk for sc in chunks}

        response = httpx.post(
            "https://api.cohere.ai/v1/rerank",
            headers={
                "Authorization": f"Bearer {self._api_key}",
                "Content-Type": "application/json",
            },
            json={
                "model": self._model,
                "query": query,
                "documents": documents,
                "top_n": top_k,
            },
            timeout=30.0,
        )
        response.raise_for_status()
        data = response.json()

        results: list[RerankedChunk] = []
        for item in data["results"]:
            idx = item["index"]
            doc_text = documents[idx]
            chunk = chunk_map[doc_text]
            score = float(item["relevance_score"])
            snippet = doc_text[:200]
            results.append(
                RerankedChunk(chunk=chunk, score=score, snippet=snippet)
            )
        return results
