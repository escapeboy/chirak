"""Compare models on the same held-out reviews: accuracy, macro-F1 and paired bootstrap intervals against a reference.

Usage: python3 -I compare.py <reference label> <label>=<predictions.jsonl> ...
Predictions are worker files (predictions.jsonl: id, expected, got) or jev_classify.py output (expected, choice).
Worker rows are ordered by id (own-0001 = first row of the frozen file); Jev rows keep the input order. The
expected labels must match row by row, which proves the files score the same reviews in the same order.
"""
import json
import random
import sys

CLASSES = ["compliment", "complaint", "mixed", "suggestion"]


def load(path: str) -> list[tuple[str, str | None]]:
    rows = [json.loads(line) for line in open(path) if line.strip()]
    if "got" in rows[0]:
        rows.sort(key=lambda r: r["id"])
        return [(r["expected"]["category"], (r["got"] or {}).get("category")) for r in rows]
    return [(r["expected"], r["choice"]) for r in rows]


def scores(pairs: list[tuple[str, str | None]], idx: list[int]) -> tuple[float, float]:
    acc = sum(pairs[i][0] == pairs[i][1] for i in idx) / len(idx)
    f1 = []
    for c in CLASSES:
        tp = sum(pairs[i][0] == c and pairs[i][1] == c for i in idx)
        pp = sum(pairs[i][1] == c for i in idx)
        ap = sum(pairs[i][0] == c for i in idx)
        p, r = (tp / pp if pp else 0), (tp / ap if ap else 0)
        f1.append(2 * p * r / (p + r) if p + r else 0)
    return acc, sum(f1) / len(f1)


ref = sys.argv[1]
models = dict(arg.split("=", 1) for arg in sys.argv[2:])
preds = {name: load(path) for name, path in models.items()}
gold = [e for e, _ in preds[ref]]
for name, pairs in preds.items():
    assert [e for e, _ in pairs] == gold, f"{name} scores other reviews or another order"
n = len(gold)
rng = random.Random(0)
samples = [[rng.randrange(n) for _ in range(n)] for _ in range(5000)]
full = list(range(n))
print(f"{n} reviews; differences against {ref}, paired bootstrap 95% (5000 resamples)")
for name, pairs in preds.items():
    acc, f1 = scores(pairs, full)
    line = f"{name:28s} accuracy {acc:.3f}  macro-F1 {f1:.3f}"
    if name != ref:
        da, df = [], []
        for s in samples:
            a, b = scores(pairs, s), scores(preds[ref], s)
            da.append(a[0] - b[0])
            df.append(a[1] - b[1])
        q = lambda v: (sorted(v)[125], sorted(v)[4874])  # noqa: E731
        line += f"  Δacc {acc - scores(preds[ref], full)[0]:+.3f} [{q(da)[0]:+.3f}, {q(da)[1]:+.3f}]"
        line += f"  ΔF1 {f1 - scores(preds[ref], full)[1]:+.3f} [{q(df)[0]:+.3f}, {q(df)[1]:+.3f}]"
    print(line)
