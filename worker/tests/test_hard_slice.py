import json

import httpx
import pytest

from chirak_worker import hard_slice
from chirak_worker.judge import ClaudeJudge

SPEC = {"name": "t", "description": "Triage.", "languages": ["en"], "fields": [
    {"name": "category", "type": "enum", "primary": True, "values": ["orders", "returns", "other"]},
    {"name": "urgent", "type": "bool"}]}


def lbl(cat, urgent=False):
    return {"category": cat, "urgent": urgent}


@pytest.fixture
def run_dir(tmp_path):
    d = tmp_path / "run"
    d.mkdir()
    (d / "task_spec.json").write_text(json.dumps(SPEC))
    hard_slice.write_jsonl(d / "eval.jsonl", [{"id": "eval-0001", "input": "one two three four five six seven eight nine",
                                              "output": lbl("orders")}])
    return d


def dropped_rows(n_per_class):
    rows = []
    for cat, n in n_per_class.items():
        for i in range(n):
            rows.append({"id": f"pool-1-drop-{cat}-{i}", "input": f"ticket {cat} number {i} about something",
                         "proposed": lbl(cat), "blind": lbl(cat, True), "slice": cat, "lang": "en", "round": 0,
                         "reason": "label_mismatch"})
    return rows


def test_sample_is_stratified_and_excludes_copies_of_eval(run_dir):
    rows = dropped_rows({"orders": 10, "returns": 10, "other": 2})
    rows.append({**rows[0], "id": "dup", "input": rows[0]["input"].upper()})  # exact duplicate
    rows.append({**rows[1], "id": "leak", "input": "x one two three four five six seven eight nine"})  # 8-gram of eval
    f = run_dir / "pool-1.dropped.jsonl"
    hard_slice.write_jsonl(f, rows)
    hard_slice.main(["sample", "--run-dir", str(run_dir), "--dropped", str(f), "--n", "12"])
    stats = json.loads((run_dir / "eval-hard" / "sample.stats.json").read_text())
    assert stats["excluded"]["exact_duplicate"] == 1 and stats["excluded"]["ngram_overlap_eval"] == 1
    assert stats["sample_by_class"] == {"orders": 5, "other": 2, "returns": 5}  # "other" is short; others fill in
    ids = [r["id"] for r in hard_slice.read_jsonl(run_dir / "eval-hard" / "sample.jsonl")]
    assert "dup" not in ids and "leak" not in ids and len(set(ids)) == 12


def fake_judge(answers: dict):
    def handler(req: httpx.Request) -> httpx.Response:
        body = json.loads(req.content)
        assert "temperature" not in body and body["system"].startswith("Triage.")
        text = answers[body["messages"][0]["content"]]
        return httpx.Response(200, json={"content": [{"type": "text", "text": text}],
                                         "usage": {"input_tokens": 10, "output_tokens": 5}})
    return ClaudeJudge(api_key="k", model="m", client=httpx.Client(transport=httpx.MockTransport(handler)))


def test_judge_splits_agreements_from_items_for_human_review(run_dir, monkeypatch):
    sample = [{"id": "a", "input": "A", "proposed": lbl("orders"), "blind": lbl("orders", True), "lang": "en"},
              {"id": "b", "input": "B", "proposed": lbl("returns"), "blind": lbl("returns", True), "lang": "en"},
              {"id": "c", "input": "C", "proposed": lbl("other"), "blind": lbl("orders"), "lang": "en"}]
    hard_slice.write_jsonl(run_dir / "eval-hard" / "sample.jsonl", sample)
    judge = fake_judge({"A": '```json\n{"category":"orders","urgent":false}\n```',
                        "B": '{"category":"returns","urgent":true}', "C": "not json"})
    monkeypatch.setattr("chirak_worker.judge.ClaudeJudge", lambda model=None: judge)
    hard_slice.main(["judge", "--run-dir", str(run_dir)])
    stats = json.loads((run_dir / "eval-hard" / "judge.stats.json").read_text())
    assert stats["agreed"] == 1 and stats["for_human_review"] == 2 and stats["judge_unparsed"] == 1
    assert stats["judge_equals_blind"] == 1 and stats["usage"]["requests"] == 6 and stats["judge_unstable"] == 0
    review = hard_slice.read_jsonl(run_dir / "eval-hard" / "for-human-review.jsonl")
    assert [r["id"] for r in review] == ["b", "c"] and all(r["human"] is None for r in review)
    assert set(review[0]) == {"id", "input", "lang", "proposed", "blind", "judge", "judge_passes", "human"}


def test_freeze_applies_the_human_choice_and_refuses_unreviewed_items(run_dir):
    judged = [{"id": "a", "input": "A", "lang": "en", "proposed": lbl("orders"), "blind": None, "judge": lbl("orders")},
              {"id": "b", "input": "B", "lang": "en", "proposed": lbl("returns"), "blind": lbl("other"), "judge": lbl("returns", True)},
              {"id": "c", "input": "C", "lang": "en", "proposed": lbl("other"), "blind": lbl("orders"), "judge": None},
              {"id": "d", "input": "D", "lang": "en", "proposed": lbl("other"), "blind": lbl("orders"), "judge": lbl("orders")}]
    hard_slice.write_jsonl(run_dir / "eval-hard" / "judged.jsonl", judged)
    reviewed = run_dir / "reviewed.jsonl"
    hard_slice.write_jsonl(reviewed, [{**j, "human": None} for j in judged[1:]])
    with pytest.raises(ValueError, match="not arbitrated"):
        hard_slice.main(["freeze", "--run-dir", str(run_dir), "--reviewed", str(reviewed)])
    assert not (run_dir / "eval-hard.jsonl").exists()
    choices = ["judge", "drop", {"category": "returns", "urgent": False}]
    hard_slice.write_jsonl(reviewed, [{**j, "human": h} for j, h in zip(judged[1:], choices)])
    hard_slice.main(["freeze", "--run-dir", str(run_dir), "--reviewed", str(reviewed)])
    rows = hard_slice.read_jsonl(run_dir / "eval-hard.jsonl")
    assert [(r["origin_id"], r["output"], r["label_source"]) for r in rows] == [
        ("a", lbl("orders"), "judge+proposed"), ("b", lbl("returns", True), "human"), ("d", lbl("returns"), "human")]
    freeze = json.loads((run_dir / "eval-hard" / "freeze.json").read_text())
    assert freeze["label_sources"] == {"judge+proposed": 1, "human": 2, "human_drop": 1}
    assert (run_dir / "eval-hard.sha256").read_text().strip() == freeze["sha256"]
    with pytest.raises(SystemExit, match="frozen"):
        hard_slice.main(["freeze", "--run-dir", str(run_dir), "--reviewed", str(reviewed)])
