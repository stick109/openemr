"""Dense vector retrieval index over clinical guideline corpus chunks.

Stores pre-computed embeddings for each chunk and retrieves top-k results
via cosine similarity using numpy.
"""

from __future__ import annotations

import hashlib
import struct

import numpy as np

from agent_service.rag.bm25_index import ScoredChunk
from agent_service.rag.corpus_loader import GuidelineChunk


def _cosine_similarity(a: np.ndarray, b: np.ndarray) -> np.ndarray:
    """Compute cosine similarity between vector *a* and each row of matrix *b*.

    Parameters
    ----------
    a:
        Query vector of shape ``(dim,)``.
    b:
        Matrix of shape ``(n, dim)`` where each row is a document vector.

    Returns
    -------
    np.ndarray
        Array of shape ``(n,)`` with cosine similarities.
    """
    a_norm = np.linalg.norm(a)
    if a_norm == 0:
        return np.zeros(b.shape[0])
    b_norms = np.linalg.norm(b, axis=1)
    # Avoid division by zero for any zero-norm document vectors.
    b_norms = np.where(b_norms == 0, 1.0, b_norms)
    return (b @ a) / (a_norm * b_norms)


def fake_embed(text: str, dim: int = 64) -> list[float]:
    """Generate a deterministic fake embedding from *text* for testing.

    Uses MD5 hashing to produce a reproducible vector.  This is NOT a
    real embedding model -- it is intended only for unit tests where
    determinism matters more than semantic quality.

    Parameters
    ----------
    text:
        Input text to embed.
    dim:
        Dimensionality of the output vector.

    Returns
    -------
    list[float]
        A deterministic vector of length *dim* with values in [0, 1).
    """
    # Hash the text and expand to fill the required dimensions.
    digest = hashlib.md5(text.encode("utf-8")).digest()  # noqa: S324 -- not used for security
    # Repeat the digest bytes to cover `dim` floats (4 bytes each).
    needed_bytes = dim * 4
    repeated = digest * ((needed_bytes // len(digest)) + 1)
    raw_bytes = repeated[:needed_bytes]
    values = list(struct.unpack(f"<{dim}f", raw_bytes))
    # Normalise into [0, 1) so cosine similarity is well-behaved.
    min_val = min(values)
    max_val = max(values)
    spread = max_val - min_val
    if spread == 0:
        return [0.5] * dim
    return [(v - min_val) / spread for v in values]


class DenseIndex:
    """Dense vector index for cosine-similarity retrieval.

    Parameters
    ----------
    chunks:
        Guideline chunks in the same order as *embeddings*.
    embeddings:
        Pre-computed embedding vectors, one per chunk.  Each inner list
        must have the same dimensionality.
    """

    def __init__(
        self,
        chunks: list[GuidelineChunk],
        embeddings: list[list[float]],
    ) -> None:
        if not chunks:
            raise ValueError("Cannot build dense index from an empty chunk list")
        if len(chunks) != len(embeddings):
            raise ValueError(
                f"Chunk count ({len(chunks)}) does not match "
                f"embedding count ({len(embeddings)})"
            )
        self._chunks = list(chunks)
        self._embeddings = np.array(embeddings, dtype=np.float32)

    @property
    def size(self) -> int:
        """Number of chunks in the index."""
        return len(self._chunks)

    def search(
        self,
        query_embedding: list[float],
        top_k: int = 20,
    ) -> list[ScoredChunk]:
        """Retrieve the top-k most similar chunks for *query_embedding*.

        Parameters
        ----------
        query_embedding:
            Embedding vector for the query, same dimensionality as stored
            embeddings.
        top_k:
            Maximum number of results to return.

        Returns
        -------
        list[ScoredChunk]
            Chunks sorted by descending cosine similarity, limited to *top_k*.
        """
        if top_k <= 0:
            return []

        query_vec = np.array(query_embedding, dtype=np.float32)
        similarities = _cosine_similarity(query_vec, self._embeddings)

        # Get top-k indices by descending similarity.
        if top_k >= len(self._chunks):
            top_indices = np.argsort(similarities)[::-1]
        else:
            # Use argpartition for efficiency on large corpora.
            partitioned = np.argpartition(similarities, -top_k)[-top_k:]
            top_indices = partitioned[np.argsort(similarities[partitioned])[::-1]]

        return [
            ScoredChunk(chunk=self._chunks[i], score=float(similarities[i]))
            for i in top_indices
        ]

    @classmethod
    def from_chunks_with_fake_embeddings(
        cls,
        chunks: list[GuidelineChunk],
        dim: int = 64,
    ) -> DenseIndex:
        """Build a :class:`DenseIndex` using deterministic fake embeddings.

        Useful for testing without a real embedding model.
        """
        embeddings = [fake_embed(c.text, dim=dim) for c in chunks]
        return cls(chunks=chunks, embeddings=embeddings)
