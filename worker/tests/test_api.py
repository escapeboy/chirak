import hashlib
import json

from chirak_worker.evaluate import read_jsonl
from conftest import FIXTURES, wait_job


def gen_body(spec, **kw):
    return {"run_id": "demo-1", "purpose": "eval", "iteration": 1, "task_spec": spec, "n": 9, **kw}


def test_generate_eval_writes_frozen_file_with_seeds(client, settings, spec_dict, llm):
    seeds = [{"input": "Seed ticket about the parcel", "output": {"category": "orders", "urgent": True}}]
    r = client.post("/generate", json=gen_body(spec_dict, seed_examples=seeds))
    assert r.status_code == 202
    job = wait_job(client, r.json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    res = job["result"]
    path = settings.runs_dir / "demo-1" / "eval.jsonl"
    assert res["path"] == str(path) and res["frozen"] is True and res["leakage_drop_rate"] == 0
    assert res["n"] == 9 and set(res["by_slice"]) == {"orders", "returns", "payments"}
    assert set(res["dropped"]) == {"duplicate", "leakage_cosine", "leakage_ngram"}
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    assert res["sha256"] == digest and (path.parent / "eval.sha256").read_text().strip() == digest
    rows = read_jsonl(path)
    assert len(rows) == 10 and rows[0]["source"] == "human" and rows[0]["input"] == seeds[0]["input"]
    assert {r["source"] for r in rows[1:]} == {"synthetic"} and {r["lang"] for r in rows[1:]} == {"bg", "en"}
    assert all(set(r) == {"id", "input", "output", "slice", "lang", "source"} for r in rows)
    mode, temp, msgs = llm.prompts[0]
    assert mode == "generator" and temp == 0.7 and "Seed ticket" in json.dumps(msgs)


def test_eval_is_frozen_409(client, spec_dict):
    first = client.post("/generate", json=gen_body(spec_dict)).json()["job_id"]
    assert wait_job(client, first)["status"] == "succeeded"
    r = client.post("/generate", json=gen_body(spec_dict))
    assert r.status_code == 409 and "frozen" in r.json()["detail"]


def test_generate_train_uses_leakage_guard_and_hotter_temperature(client, settings, spec_dict, llm):
    eval_path = settings.runs_dir / "demo-1" / "eval.jsonl"
    eval_path.parent.mkdir(parents=True)
    eval_path.write_text((FIXTURES / "eval.jsonl").read_text())
    # make the fake generator collide with eval item 1 for exactly the first candidate
    orig = llm.chat
    leaked = {"done": False}

    def chat(model, messages, temperature, max_tokens, **extra):
        text, ms = orig(model, messages, temperature, max_tokens, **extra)
        if not leaked["done"]:
            leaked["done"] = True
            text = text.replace("unique sample 1 ", "Здравейте, поръчка 4412 още не е пристигнала, а мина седмица. ")
        return text, ms

    llm.chat = chat
    body = {"run_id": "demo-1", "purpose": "train", "iteration": 2, "task_spec": spec_dict, "n": 12,
            "seed_examples": [{"input": "SEED-MUST-NOT-APPEAR", "output": {"category": "orders", "urgent": False}}],
            "avoid_near": [str(eval_path)]}
    job = wait_job(client, client.post("/generate", json=body).json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    res = job["result"]
    assert res["path"].endswith("train-2.jsonl") and res["frozen"] is False
    assert res["n"] == 12 and sum(res["by_slice"].values()) == 12
    assert res["dropped"]["leakage_cosine"] + res["dropped"]["leakage_ngram"] >= 1
    assert res["leakage_drop_rate"] == round(
        (res["dropped"]["leakage_cosine"] + res["dropped"]["leakage_ngram"]) / res["n_candidates"], 4)
    rows = read_jsonl(settings.runs_dir / "demo-1" / "train-2.jsonl")
    assert not any("поръчка 4412 още не е пристигнала" in r["input"] for r in rows)
    assert all(t == 1.0 for _, t, _ in llm.prompts)
    assert "SEED-MUST-NOT-APPEAR" not in json.dumps(llm.prompts) and "4412" not in json.dumps(llm.prompts)


def test_generate_train_target_slices(client, spec_dict):
    body = {"run_id": "demo-2", "purpose": "train", "iteration": 1, "task_spec": spec_dict, "n": 8,
            "target_slices": [{"name": "returns", "n": 6, "guidance": "Returns mixed with payment questions."},
                              {"name": "payments", "n": 2}]}
    job = wait_job(client, client.post("/generate", json=body).json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    assert job["result"]["by_slice"] == {"returns": 6, "payments": 2}


def test_generate_rejects_bad_input(client, settings, spec_dict):
    assert client.post("/generate", json={**gen_body(spec_dict), "run_id": "Bad_ID"}).status_code == 422
    bad_spec = {**spec_dict, "fields": [{"name": "x", "type": "bool"}]}
    assert client.post("/generate", json=gen_body(bad_spec)).status_code == 422
    outside = {**gen_body(spec_dict), "purpose": "train", "avoid_near": ["/etc/passwd"]}
    assert client.post("/generate", json=outside).status_code == 400
    missing = {**gen_body(spec_dict), "purpose": "train", "avoid_near": [str(settings.runs_dir / "demo-1" / "nope.jsonl")]}
    assert client.post("/generate", json=missing).status_code == 404


def test_generate_fails_clearly_when_generator_is_useless(client, spec_dict, llm):
    llm.chat = lambda *a, **k: ("sorry, I cannot do that", 1.0)
    job = wait_job(client, client.post("/generate", json=gen_body(spec_dict)).json()["job_id"])
    assert job["status"] == "failed" and "no usable items" in job["error"]


def _eval_setup(settings):
    d = settings.runs_dir / "demo-1"
    d.mkdir(parents=True, exist_ok=True)
    (d / "eval.jsonl").write_text((FIXTURES / "eval.jsonl").read_text())
    return d / "eval.jsonl"


def test_evaluate_base_scores_and_writes_files(client, settings, spec_dict, llm):
    ev = _eval_setup(settings)
    llm.student_reply = lambda model, user: (
        json.dumps({"category": "orders", "urgent": False}) if ("parcel" in user or "поръчка" in user.lower()) else "no idea")
    body = {"run_id": "demo-1", "eval_file": str(ev), "model": "base", "task_spec": spec_dict, "label": "baseline"}
    job = wait_job(client, client.post("/evaluate", json=body).json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    m = job["result"]
    assert m["label"] == "baseline" and m["model"] == "base" and m["n"] == 10
    assert m["eval_sha256"] == hashlib.sha256(ev.read_bytes()).hexdigest()
    assert 0 < m["json_valid_rate"] < 1 and m["accuracy"] > 0  # other inputs get a non-JSON answer
    assert m["confusion"]["labels"][-1] == "__invalid__"
    assert {"p50", "p95"} <= set(m["latency_ms"])
    out = settings.runs_dir / "demo-1" / "evals" / "baseline"
    assert json.loads((out / "metrics.json").read_text())["n"] == 10
    preds = read_jsonl(out / "predictions.jsonl")
    assert len(preds) == 10 and any(p["got"] is None and p["error"] for p in preds)
    assert llm.adapters == {}


def test_evaluate_adapter_is_loaded_then_unloaded(client, settings, spec_dict, llm):
    ev = _eval_setup(settings)
    ad = settings.runs_dir / "demo-1" / "iter-1" / "adapter"
    ad.mkdir(parents=True)
    seen = []
    llm.student_reply = lambda model, user: (seen.append((model, dict(llm.adapters))) or
                                             json.dumps({"category": "orders", "urgent": False}))
    body = {"run_id": "demo-1", "eval_file": str(ev), "model": str(ad), "task_spec": spec_dict, "label": "iter-1"}
    job = wait_job(client, client.post("/evaluate", json=body).json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    assert job["result"]["accuracy"] == 0.4 and job["result"]["json_valid_rate"] == 1.0
    assert seen and all(m == "eval-demo-1-iter-1" and a == {m: str(ad.resolve())} for m, a in seen)
    assert llm.adapters == {}


def test_evaluate_rejects_paths_outside_runs(client, settings, spec_dict):
    base = {"run_id": "demo-1", "model": "base", "task_spec": spec_dict, "label": "x"}
    assert client.post("/evaluate", json={**base, "eval_file": "/etc/hosts"}).status_code == 400
    ev = _eval_setup(settings)
    assert client.post("/evaluate", json={**base, "eval_file": str(ev), "model": "/etc"}).status_code == 400
    assert client.post("/evaluate", json={**base, "eval_file": str(ev), "label": "../x"}).status_code == 422


def test_train_job_uses_injected_trainer(client, settings, spec_dict, llm):
    d = settings.runs_dir / "demo-1"
    d.mkdir(parents=True)
    (d / "train-1.jsonl").write_text((FIXTURES / "train.jsonl").read_text())
    llm.mode = "student"
    body = {"run_id": "demo-1", "iteration": 1, "task_spec": spec_dict, "train_files": [str(d / "train-1.jsonl")]}
    job = wait_job(client, client.post("/train", json=body).json()["job_id"])
    assert job["status"] == "succeeded", job["error"]
    assert job["result"]["adapter_path"].endswith("iter-1/adapter") and llm.mode == "none"
    assert client.post("/train", json={**body, "train_files": ["/etc/hosts"]}).status_code == 400


def test_serve_predict_unserve_and_health(client, settings, spec_dict, llm):
    ad = settings.runs_dir / "demo-1" / "iter-2" / "adapter"
    ad.mkdir(parents=True)
    assert client.post("/serve", json={"name": "demo-1-iter-2", "adapter_path": str(ad)}).json() == {
        "name": "demo-1-iter-2", "loaded": True}
    h = client.get("/health").json()
    assert h["adapters"] == ["demo-1-iter-2"] and h["vllm"] == {"mode": "student", "model": "stu-model"}
    assert h["gpu"]["visible"] is False and h["current_job"] is None and h["queue_length"] == 0

    body = {"model": "demo-1-iter-2", "task_spec": spec_dict, "input": "my orders are late"}
    p = client.post("/predict", json=body).json()
    assert p["valid"] is True and p["parsed"] == {"category": "orders", "urgent": False} and p["error"] is None
    bad = client.post("/predict", json={**body, "input": "hello"}).json()
    assert bad["valid"] is False and bad["parsed"] is None and bad["error"] == "invalid JSON"
    assert client.post("/predict", json={**body, "model": "ghost"}).status_code == 404

    assert client.delete("/serve/demo-1-iter-2").json() == {"name": "demo-1-iter-2", "loaded": False}
    assert client.delete("/serve/demo-1-iter-2").status_code == 404
    assert client.post("/serve", json={"name": "x", "adapter_path": "/tmp/adapter"}).status_code == 400


def test_predict_and_serve_409_while_training(client, services, spec_dict):
    services.runner._current = {"id": "job_x", "type": "train"}
    body = {"model": "base", "task_spec": spec_dict, "input": "orders"}
    assert client.post("/predict", json=body).status_code == 409
    assert client.post("/serve", json={"name": "x", "adapter_path": "/data/runs/a"}).status_code == 409
    assert client.get("/health").json()["current_job"] == "job_x"
    services.runner._current = None


def test_evaluate_fails_when_vllm_is_unreachable(client, settings, spec_dict, llm):
    ev = _eval_setup(settings)

    def boom(*a, **k):
        raise RuntimeError("connection refused")

    llm.chat = boom
    body = {"run_id": "demo-1", "eval_file": str(ev), "model": "base", "task_spec": spec_dict, "label": "b"}
    job = wait_job(client, client.post("/evaluate", json=body).json()["job_id"])
    assert job["status"] == "failed" and "request failed" in job["error"]
