"""GPU smoke checks against a RUNNING worker over HTTP. Fixtures are tiny; each check reuses the
artifacts of the previous one under the same run id (default smoke-1) and creates what is missing."""
import io
import json
import os
import struct
import tarfile
from pathlib import Path

from .client import WorkerClient, pretty

FIXTURES = Path(__file__).resolve().parent.parent / "tests" / "fixtures"
DATA = os.environ.get("DATA_DIR", "/data")
RUN = os.environ.get("SMOKE_RUN", "smoke-1")
OLD_PREFIX = "base_model.model.model.layers."
NEW_PREFIX = "base_model.model.model.language_model.layers."


def spec() -> dict:
    return json.loads((FIXTURES / "task_spec.json").read_text())


def _seeds(n: int = 2) -> list[dict]:
    rows = [json.loads(line) for line in (FIXTURES / "eval.jsonl").read_text().splitlines()]
    return [{"input": r["input"], "output": r["output"]} for r in rows[:n]]


def check(cond: bool, msg: str) -> None:
    if not cond:
        raise AssertionError(msg)
    print(f"  ok: {msg}")


def health(c: WorkerClient) -> None:
    h = c.health()
    print(pretty(h))
    check(h["status"] == "ok" and "gpu" in h and "vllm" in h, "health has gpu and vllm sections")
    check(h["gpu"]["visible"], "GPU visible through rocm-smi")


def generate(c: WorkerClient) -> None:
    if c.artifact_exists(RUN, "eval.jsonl"):
        print("  eval.jsonl already frozen for this run, reusing it")
    else:
        r = c.run("/generate", {"run_id": RUN, "purpose": "eval", "task_spec": spec(), "n": 6, "seed_examples": _seeds()})
        check(r["frozen"] and r["n"] > 0 and len(r["sha256"]) == 64, f"eval generated n={r['n']} frozen sha={r['sha256'][:12]}")
        check(len(c.artifact(RUN, "eval.jsonl").splitlines()) == r["n"] + 2, "eval.jsonl = synthetic items + 2 seeds")
    r = c.run("/generate", {"run_id": RUN, "purpose": "train", "iteration": 1, "task_spec": spec(), "n": 12,
                            "avoid_near": [f"{DATA}/runs/{RUN}/eval.jsonl"]})
    print(pretty({k: v for k, v in r.items() if k != "path"}))
    check(r["n"] > 0 and 0 <= r["leakage_drop_rate"] <= 1, f"train-1 generated n={r['n']}, leakage_drop_rate={r['leakage_drop_rate']}")


def _adapter_keys(archive: bytes) -> list[str]:
    with tarfile.open(fileobj=io.BytesIO(archive)) as t:
        f = t.extractfile("adapter/adapter_model.safetensors")
        n = struct.unpack("<Q", f.read(8))[0]
        return [k for k in json.loads(f.read(n)) if k != "__metadata__"]


def train(c: WorkerClient) -> None:
    if not c.artifact_exists(RUN, "train-1.jsonl"):
        generate(c)
    r = c.run("/train", {"run_id": RUN, "iteration": 1, "task_spec": spec(), "train_files": [f"{DATA}/runs/{RUN}/train-1.jsonl"],
                         "epochs": 1, "max_seq_len": 512})
    print(pretty({k: v for k, v in r.items() if k != "loss_curve"}))
    check(len(r["loss_curve"]) >= 1 and r["n_examples"] > 0, f"trained on {r['n_examples']} examples, {len(r['loss_curve'])} loss points")
    keys = _adapter_keys(c.artifact(RUN, "iter-1/adapter.tar.gz"))
    check(keys and not any(k.startswith(OLD_PREFIX) for k in keys) and any(k.startswith(NEW_PREFIX) for k in keys),
          "adapter keys renamed to language_model.layers.* (vLLM would ignore the old names)")


def evaluate(c: WorkerClient) -> None:
    if not c.artifact_exists(RUN, "iter-1/adapter.tar.gz"):
        train(c)
    results = {}
    for label, model in (("smoke-base", "base"), ("smoke-iter-1", f"{DATA}/runs/{RUN}/iter-1/adapter")):
        m = c.run("/evaluate", {"run_id": RUN, "eval_file": f"{DATA}/runs/{RUN}/eval.jsonl", "model": model,
                                "task_spec": spec(), "label": label})
        print(f"  {label}: accuracy={m['accuracy']} macro_f1={m['macro_f1']} json_valid={m['json_valid_rate']} p95={m['latency_ms']['p95']}ms")
        check(m["n"] > 0 and "__invalid__" in m["confusion"]["labels"], f"{label} metrics have the contract shape (n={m['n']})")
        results[label] = m
    sha = c.artifact(RUN, "eval.sha256").decode().strip()
    check(all(m["eval_sha256"] == sha for m in results.values()), "both evals ran against the frozen eval (same sha256)")
    check(c.artifact_exists(RUN, "evals/smoke-iter-1/predictions.jsonl"), "predictions.jsonl is downloadable")


def serve(c: WorkerClient) -> None:
    if not c.artifact_exists(RUN, "iter-1/adapter.tar.gz"):
        train(c)
    name = f"{RUN}-iter-1"
    r = c.request("POST", "/serve", json={"name": name, "adapter_path": f"{DATA}/runs/{RUN}/iter-1/adapter"}).json()
    check(r == {"name": name, "loaded": True}, "adapter hot-loaded")
    check(name in c.health()["adapters"], "health lists the served adapter")
    text = json.loads((FIXTURES / "eval.jsonl").read_text().splitlines()[0])["input"]
    out = {}
    for model in ("base", name):
        p = c.request("POST", "/predict", json={"model": model, "task_spec": spec(), "input": text}).json()
        print(f"  {model}: valid={p['valid']} raw={p['raw']!r} {p['latency_ms']}ms")
        check({"raw", "parsed", "valid", "error", "latency_ms"} <= set(p), f"predict({model}) has the contract shape")
        out[model] = p
    check(out[name]["valid"], "the fine-tuned adapter answers with valid JSON")
    check(c.request("DELETE", f"/serve/{name}").json() == {"name": name, "loaded": False}, "adapter unloaded")


STEPS = {"health": health, "generate": generate, "train": train, "evaluate": evaluate, "serve": serve}
