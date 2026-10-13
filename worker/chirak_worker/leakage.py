"""Near-duplicate removal and the train/eval leakage guard."""
import json
import re
from pathlib import Path

import numpy as np

from .embedding import Embedder

DEDUP_COSINE = 0.95
LEAK_COSINE = 0.92
LEAK_NGRAM = 8


def words(text: str) -> list[str]:
    return re.findall(r"\w+", text.lower())


def ngram_set(text: str, n: int = LEAK_NGRAM) -> set[tuple[str, ...]]:
    w = words(text)
    return {tuple(w[i:i + n]) for i in range(len(w) - n + 1)}


def read_avoid_texts(paths: list[str]) -> list[str]:
    out = []
    for p in paths:
        with open(p, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line:
                    out.append(json.loads(line)["input"])
    return out


def dedup(emb: np.ndarray, known: np.ndarray | None = None, threshold: float = DEDUP_COSINE) -> list[bool]:
    """Greedy: keep[i] is False when item i is >= threshold similar to an earlier kept item
    (or to a `known` row). Order decides which of two near-duplicates survives."""
    n_known = 0 if known is None else len(known)
    dim = emb.shape[1] if len(emb) else 1
    pool = np.zeros((n_known + len(emb), dim), dtype=np.float32)
    if n_known:
        pool[:n_known] = known
    size, keep = n_known, []
    for i in range(len(emb)):
        dup = size > 0 and float((pool[:size] @ emb[i]).max()) >= threshold
        keep.append(not dup)
        if not dup:
            pool[size] = emb[i]
            size += 1
    return keep


class LeakageGuard:
    """Cosine >= 0.92 against any avoid_near input, or any shared lowercased word 8-gram."""

    def __init__(self, embedder: Embedder, avoid_texts: list[str], cos_threshold: float = LEAK_COSINE):
        self.threshold = cos_threshold
        self.avoid_emb = embedder.encode(avoid_texts) if avoid_texts else None
        self.avoid_grams: set[tuple[str, ...]] = set()
        for t in avoid_texts:
            self.avoid_grams |= ngram_set(t)

    def check(self, texts: list[str], emb: np.ndarray) -> list[str | None]:
        """Per candidate: None (clean), 'leakage_cosine' or 'leakage_ngram'."""
        if self.avoid_emb is None:
            return [None] * len(texts)
        max_cos = np.zeros(len(texts), dtype=np.float32)
        for start in range(0, len(texts), 1024):
            max_cos[start:start + 1024] = (emb[start:start + 1024] @ self.avoid_emb.T).max(axis=1)
        out: list[str | None] = []
        for i, t in enumerate(texts):
            if max_cos[i] >= self.threshold:
                out.append("leakage_cosine")
            elif not ngram_set(t).isdisjoint(self.avoid_grams):
                out.append("leakage_ngram")
            else:
                out.append(None)
        return out


def file_sha256(path: Path) -> str:
    import hashlib

    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()
