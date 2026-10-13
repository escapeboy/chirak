"""Embedding access. sentence-transformers is imported lazily so unit tests run without torch."""
import threading
from typing import Protocol

import numpy as np


class Embedder(Protocol):
    def encode(self, texts: list[str]) -> np.ndarray:
        """Return L2-normalised float32 vectors, shape (len(texts), dim)."""


class SentenceTransformerEmbedder:
    def __init__(self, model_name: str):
        self.model_name = model_name
        self._model = None
        self._lock = threading.Lock()

    def _load(self):
        with self._lock:
            if self._model is None:
                from sentence_transformers import SentenceTransformer

                self._model = SentenceTransformer(self.model_name, device="cuda")
        return self._model

    def encode(self, texts: list[str]) -> np.ndarray:
        if not texts:
            return np.zeros((0, 1), dtype=np.float32)
        emb = self._load().encode(texts, batch_size=128, normalize_embeddings=True)
        return np.asarray(emb, dtype=np.float32)
