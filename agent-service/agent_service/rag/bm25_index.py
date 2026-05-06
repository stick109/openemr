"""BM25 sparse retrieval index over clinical guideline corpus chunks.

Builds a BM25Okapi index from guideline chunks and supports top-k retrieval
with scored results.
"""

from __future__ import annotations

from dataclasses import dataclass

from rank_bm25 import BM25Okapi

from agent_service.rag.corpus_loader import GuidelineChunk


@dataclass(frozen=True)
class ScoredChunk:
    """A guideline chunk paired with a retrieval score."""

    chunk: GuidelineChunk
    score: float


def _tokenize(text: str) -> list[str]:
    """Tokenize by lowercasing and splitting on whitespace."""
    return text.lower().split()


class BM25Index:
    """BM25Okapi index over a list of :class:`GuidelineChunk` objects.

    Parameters
    ----------
    chunks:
        Pre-loaded guideline chunks to index.
    """

    def __init__(self, chunks: list[GuidelineChunk]) -> None:
        if not chunks:
            raise ValueError("Cannot build BM25 index from an empty chunk list")
        self._chunks = list(chunks)
        tokenized_corpus = [_tokenize(c.text) for c in self._chunks]
        self._index = BM25Okapi(tokenized_corpus)

    @property
    def size(self) -> int:
        """Number of chunks in the index."""
        return len(self._chunks)

    def search(self, query: str, top_k: int = 20) -> list[ScoredChunk]:
        """Retrieve the top-k most relevant chunks for *query*.

        Parameters
        ----------
        query:
            Natural-language search query.
        top_k:
            Maximum number of results to return.

        Returns
        -------
        list[ScoredChunk]
            Chunks sorted by descending BM25 score, limited to *top_k*.
        """
        if top_k <= 0:
            return []

        tokenized_query = _tokenize(query)
        scores = self._index.get_scores(tokenized_query)

        # Pair each chunk with its score and sort descending.
        scored = [
            ScoredChunk(chunk=self._chunks[i], score=float(scores[i]))
            for i in range(len(self._chunks))
        ]
        scored.sort(key=lambda sc: sc.score, reverse=True)
        return scored[:top_k]
