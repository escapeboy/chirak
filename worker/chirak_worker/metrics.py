"""Evaluation metrics. Invalid answers count as wrong everywhere and land in the __invalid__ column."""
import math

from .schemas import INVALID, TaskSpec


def percentile(values: list[float], p: float) -> float:
    """Nearest-rank percentile."""
    if not values:
        return 0.0
    s = sorted(values)
    return s[max(0, math.ceil(p / 100 * len(s)) - 1)]


def compute_metrics(spec: TaskSpec, rows: list[dict]) -> dict:
    """rows: {id, input, expected, got (dict|None), raw, error, latency_ms}."""
    primary = spec.primary.name
    classes = list(spec.primary.values)
    labels = classes + [INVALID]
    idx = {c: i for i, c in enumerate(labels)}
    n = len(rows)
    matrix = [[0] * len(labels) for _ in labels]
    failures = []
    exact = valid = 0
    field_ok = {f.name: 0 for f in spec.fields}

    for r in rows:
        exp, got = r["expected"], r["got"]
        pred = got[primary] if got is not None else INVALID
        matrix[idx[exp[primary]]][idx[pred]] += 1
        if got is not None:
            valid += 1
            for f in spec.fields:
                field_ok[f.name] += got[f.name] == exp[f.name]
            exact += all(got[f.name] == exp[f.name] for f in spec.fields)
        if pred != exp[primary]:
            failures.append({k: r[k] for k in ("id", "input", "expected", "got", "raw", "error")})

    per_class = {}
    for c in classes:
        i = idx[c]
        tp = matrix[i][i]
        support = sum(matrix[i])
        predicted = sum(matrix[j][i] for j in range(len(labels)))
        p = tp / predicted if predicted else 0.0
        rec = tp / support if support else 0.0
        f1 = 2 * p * rec / (p + rec) if p + rec else 0.0
        per_class[c] = {"precision": round(p, 4), "recall": round(rec, 4), "f1": round(f1, 4), "support": support}

    supported = [v["f1"] for v in per_class.values() if v["support"] > 0]
    lat = [r["latency_ms"] for r in rows]
    div = n or 1
    return {
        "n": n,
        "json_valid_rate": round(valid / div, 4),
        "accuracy": round(sum(matrix[idx[c]][idx[c]] for c in classes) / div, 4),
        "macro_f1": round(sum(supported) / len(supported), 4) if supported else 0.0,
        "per_class": per_class,
        "confusion": {"labels": labels, "matrix": matrix},
        "fields": {k: {"accuracy": round(v / div, 4)} for k, v in field_ok.items()},
        "exact_match": round(exact / div, 4),
        "latency_ms": {"p50": round(percentile(lat, 50)), "p95": round(percentile(lat, 95))},
        "failures": failures,
    }
