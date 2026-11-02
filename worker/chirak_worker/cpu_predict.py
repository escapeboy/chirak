"""CPU playground: the worker's /predict contract in front of llama.cpp servers, no GPU and no torch.

The playground of the control plane calls POST /predict {model, task_spec, input}. Here "base" and each
served name map to an OpenAI-compatible llama-server (one GGUF each: the base model and the base with
the adapter merged in). The prompt, decoding and parsing are the GPU worker's own.

Serve: CPU_MODELS='base=http://llama-base:8080,<served name>=http://llama-tuned:8080' \
       WORKER_BEARER_TOKEN=... python -m chirak_worker.cpu_predict serve --port 8100
Eval:  python -m chirak_worker.cpu_predict eval --url http://127.0.0.1:8080 --eval-file eval.jsonl \
       --spec task_spec.json --label gguf-q8.eval --out evals/   (writes <label>/metrics.json like the worker)
"""
import argparse
import hashlib
import json
import os
import secrets
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import httpx

from .metrics import compute_metrics
from .parsing import parse_student_answer
from .prompts import student_messages
from .schemas import TaskSpec


def chat(client: httpx.Client, url: str, spec: TaskSpec, text: str) -> tuple[str, float]:
    # the GPU worker's request (vllm.py chat, app.py predict): greedy, 256 tokens, thinking off
    body = {"model": "chirak", "messages": student_messages(spec, text), "temperature": 0, "max_tokens": 256,
            "chat_template_kwargs": {"enable_thinking": False}}
    t0 = time.perf_counter()
    r = client.post(f"{url}/v1/chat/completions", json=body)
    ms = (time.perf_counter() - t0) * 1000
    r.raise_for_status()
    return r.json()["choices"][0]["message"]["content"] or "", ms


def create_app(models: dict[str, str], token: str, client: httpx.Client | None = None):
    from fastapi import Depends, FastAPI, Header, HTTPException

    from .schemas import PredictRequest

    app = FastAPI(title="chirak-cpu-predict")
    client = client or httpx.Client(timeout=120)

    def auth(authorization: str = Header(default="")):
        if not secrets.compare_digest(authorization, f"Bearer {token}"):
            raise HTTPException(401, "bad token")

    @app.get("/health")
    def health():
        return {"ok": True, "mode": "cpu", "models": sorted(models)}

    @app.post("/predict", dependencies=[Depends(auth)])
    def predict(req: PredictRequest):
        if req.model not in models:
            raise HTTPException(404, f"model not served: {req.model}")
        raw, ms = chat(client, models[req.model], req.task_spec, req.input)
        parsed, err = parse_student_answer(raw, req.task_spec)
        return {"raw": raw, "parsed": parsed, "valid": parsed is not None, "error": err, "latency_ms": round(ms)}

    return app


def run_eval(a) -> dict:
    spec = TaskSpec(**json.loads(Path(a.spec).read_text()))
    body = Path(a.eval_file).read_bytes()
    items = [json.loads(line) for line in body.decode().splitlines() if line.strip()]
    client = httpx.Client(timeout=300)

    def one(item):
        raw, ms = chat(client, a.url, spec, item["input"])
        got, err = parse_student_answer(raw, spec)
        return {"id": item["id"], "input": item["input"], "expected": item["output"], "got": got, "raw": raw,
                "error": err, "latency_ms": ms}

    with ThreadPoolExecutor(a.concurrency) as ex:
        rows = list(ex.map(one, items))
    metrics = compute_metrics(spec, rows)
    metrics = {"label": a.label, "model": a.model_note, "eval_sha256": hashlib.sha256(body).hexdigest(), **metrics}
    out = Path(a.out) / a.label
    out.mkdir(parents=True, exist_ok=True)
    (out / "metrics.json").write_text(json.dumps(metrics, indent=1, ensure_ascii=False))
    (out / "predictions.jsonl").write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows))
    return metrics


def main(argv=None) -> int:
    p = argparse.ArgumentParser(prog="chirak_worker.cpu_predict")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("serve")
    s.add_argument("--host", default="127.0.0.1")
    s.add_argument("--port", type=int, default=8100)
    e = sub.add_parser("eval")
    e.add_argument("--url", required=True)
    e.add_argument("--eval-file", required=True)
    e.add_argument("--spec", required=True)
    e.add_argument("--label", required=True)
    e.add_argument("--out", required=True)
    e.add_argument("--model-note", default="gguf")
    e.add_argument("--concurrency", type=int, default=4)
    a = p.parse_args(argv)
    if a.cmd == "serve":
        import uvicorn

        models = dict(pair.split("=", 1) for pair in os.environ["CPU_MODELS"].split(","))
        uvicorn.run(create_app(models, os.environ["WORKER_BEARER_TOKEN"]), host=a.host, port=a.port)
    else:
        m = run_eval(a)
        print(json.dumps({k: m[k] for k in ("label", "n", "accuracy", "macro_f1", "json_valid_rate", "exact_match", "fields")}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
