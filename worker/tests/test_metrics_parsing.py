import json

from chirak_worker.metrics import compute_metrics, percentile
from chirak_worker.parsing import parse_generated_items, parse_student_answer
from chirak_worker.schemas import TaskSpec


def spec(spec_dict):
    return TaskSpec(**spec_dict)


def test_strict_parser_accepts_exact_object(spec_dict):
    got, err = parse_student_answer('{"category":"orders","urgent":true}', spec(spec_dict))
    assert got == {"category": "orders", "urgent": True} and err is None


def test_strict_parser_rejects_every_deviation(spec_dict):
    s = spec(spec_dict)
    cases = {
        "not json": "invalid JSON",
        "```json\n{}\n```": "invalid JSON",
        '["orders"]': "not a JSON object",
        '{"category":"orders"}': "missing field: urgent",
        '{"category":"shipping","urgent":false}': "value out of spec for category: 'shipping'",
        '{"category":"orders","urgent":"yes"}': "field urgent is not a boolean",
        '{"category":"orders","urgent":1}': "field urgent is not a boolean",
        '{"category":"orders","urgent":true,"why":"x"}': "unexpected field: why",
    }
    for raw, why in cases.items():
        got, err = parse_student_answer(raw, s)
        assert got is None and err == why, raw


def row(i, expected, got, raw="", error=None, ms=100):
    return {"id": f"e{i}", "input": f"t{i}", "expected": expected, "got": got, "raw": raw, "error": error, "latency_ms": ms}


def test_metrics_counts_invalid_as_wrong(spec_dict):
    s = spec(spec_dict)
    e = lambda c, u=False: {"category": c, "urgent": u}  # noqa: E731
    rows = [
        row(1, e("orders"), e("orders")),
        row(2, e("orders"), e("returns")),
        row(3, e("returns"), e("returns", True)),   # primary right, urgent wrong
        row(4, e("payments"), None, raw="oops", error="invalid JSON"),
    ]
    m = compute_metrics(s, rows)
    assert m["n"] == 4
    assert m["json_valid_rate"] == 0.75
    assert m["accuracy"] == 0.5
    assert m["exact_match"] == 0.25
    assert m["fields"]["urgent"]["accuracy"] == 0.5
    assert m["confusion"]["labels"] == ["orders", "returns", "payments", "__invalid__"]
    assert m["confusion"]["matrix"][2] == [0, 0, 0, 1]
    assert m["confusion"]["matrix"][0] == [1, 1, 0, 0]
    assert m["per_class"]["orders"] == {"precision": 1.0, "recall": 0.5, "f1": 0.6667, "support": 2}
    assert m["per_class"]["returns"]["precision"] == 0.5
    assert m["per_class"]["payments"] == {"precision": 0.0, "recall": 0.0, "f1": 0.0, "support": 1}
    assert m["macro_f1"] == round((0.6667 + 0.6667 + 0.0) / 3, 4)
    assert [f["id"] for f in m["failures"]] == ["e2", "e4"]
    assert m["failures"][1]["got"] is None and m["failures"][1]["error"] == "invalid JSON"
    json.dumps(m)


def test_macro_f1_includes_predicted_classes_without_support(spec_dict):
    s = spec(spec_dict)
    e = lambda c: {"category": c, "urgent": False}  # noqa: E731
    # "payments" has no eval item but is predicted once: it must pull macro-F1 down (sklearn default)
    rows = [row(1, e("orders"), e("orders")), row(2, e("returns"), e("returns")), row(3, e("orders"), e("payments"))]
    m = compute_metrics(s, rows)
    assert m["per_class"]["payments"]["support"] == 0
    assert m["macro_f1"] == round((0.6667 + 1.0 + 0.0) / 3, 4)


def test_percentile_nearest_rank():
    assert percentile([10, 20, 30, 40], 50) == 20
    assert percentile([10, 20, 30, 40], 95) == 40
    assert percentile([], 50) == 0.0


def test_generated_items_parser_is_tolerant():
    raw = '<think>hmm</think> Sure!\n```json\n[{"n": 1, "text": "a b"}, {"n": "2", "text": "c"}, {"n": 9, "text": "x"}, {"n": 3}, "junk"]\n```'
    assert parse_generated_items(raw, 3) == {1: "a b", 2: "c"}
    assert parse_generated_items("no json here", 3) == {}
    assert parse_generated_items('{"items": [{"n": 1, "text": "z"}]}', 2) == {1: "z"}
    assert parse_generated_items('[{"n": 1, "text": "ok"}, {"n": 2, "te', 2) == {1: "ok"}  # truncated array keeps complete items
