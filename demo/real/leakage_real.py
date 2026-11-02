"""Runs on the pod: drop train items that are near-copies of any eval item (bge-m3 cosine >= 0.92, the
worker's leakage threshold, or a shared word 8-gram). Prints the counts per eval file.

Usage: python leakage_real.py <train.jsonl> <out.jsonl> <eval.jsonl> [<eval.jsonl> ...]
"""
import json
import re
import sys

sys.path.insert(0, "/data/chirak/worker")
from chirak_worker.config import Settings  # noqa: E402
from chirak_worker.embedding import SentenceTransformerEmbedder  # noqa: E402

read = lambda p: [json.loads(line) for line in open(p) if line.strip()]  # noqa: E731
grams = lambda t: {tuple(w[i:i + 8]) for w in [re.findall(r"\w+", t.lower())] for i in range(len(w) - 7)}  # noqa: E731

train = read(sys.argv[1])
emb = SentenceTransformerEmbedder(Settings.from_env().embedding_model)
T = emb.encode([r["input"] for r in train])
drop, report = set(), {}
for path in sys.argv[3:]:
    ev = read(path)
    E = emb.encode([r["input"] for r in ev])
    cos = (T @ E.T).max(axis=1)
    g = set().union(*(grams(r["input"]) for r in ev))
    hits_cos = {i for i in range(len(train)) if cos[i] >= 0.92}
    hits_ng = {i for i, r in enumerate(train) if grams(r["input"]) & g}
    report[path.rsplit("/", 1)[-1]] = {"cosine": len(hits_cos), "ngram": len(hits_ng), "max_cos": round(float(cos.max()), 4)}
    drop |= hits_cos | hits_ng
kept = [r for i, r in enumerate(train) if i not in drop]
with open(sys.argv[2], "w") as f:
    for i, r in enumerate(kept, 1):
        f.write(json.dumps({"id": f"real-{i:04d}", **r}, ensure_ascii=False) + "\n")
print(json.dumps({"n_in": len(train), "dropped": len(drop), "kept": len(kept), "by_eval": report}, indent=1))
