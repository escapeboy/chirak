"""Run an eval file through the student vLLM (temperature 0, thinking off) and score it strictly."""
import json
import os
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

from .config import Settings
from .jobs import JobContext
from .leakage import file_sha256
from .metrics import compute_metrics
from .parsing import parse_student_answer
from .prompts import student_messages
from .schemas import EvaluateRequest
from .vllm import LlmBackend


def read_jsonl(path: Path) -> list[dict]:
    with open(path, encoding="utf-8") as f:
        return [json.loads(line) for line in f if line.strip()]


def eval_adapter_name(run_id: str, label: str) -> str:
    return f"eval-{run_id}-{label}"


def run_evaluate(req: EvaluateRequest, settings: Settings, llm: LlmBackend, ctx: JobContext, eval_path: Path,
                 adapter_path: str | None) -> dict:
    spec = req.task_spec
    items = read_jsonl(eval_path)
    out_dir = settings.runs_dir / req.run_id / "evals" / req.label
    out_dir.mkdir(parents=True, exist_ok=True)

    ctx.progress("starting student", 0, len(items))
    llm.ensure_mode("student")
    model, temp_adapter = "base", None
    if adapter_path:
        # reuse an already served adapter with the same path, else load one just for this run
        served = next((n for n, p in llm.adapters.items() if p == adapter_path), None)
        model = served or eval_adapter_name(req.run_id, req.label)
        if not served:
            llm.load_adapter(model, adapter_path)
            temp_adapter = model

    done = 0

    def one(item: dict) -> dict:
        try:
            raw, ms = llm.chat(model, student_messages(spec, item["input"]), temperature=0, max_tokens=256)
        except Exception as e:  # an infrastructure error is a wrong answer here, not a crashed job
            raw, ms = "", 0.0
            got, err = None, f"request failed: {e}"
        else:
            got, err = parse_student_answer(raw, spec)
        return {"id": item["id"], "input": item["input"], "expected": item["output"], "got": got,
                "raw": raw, "error": err, "latency_ms": round(ms, 1)}

    rows = []
    try:
        with ThreadPoolExecutor(settings.gen_concurrency) as ex:
            for row in ex.map(one, items):
                rows.append(row)
                done += 1
                if done % 10 == 0 or done == len(items):
                    ctx.progress("evaluating", done, len(items))
    finally:
        if temp_adapter:
            llm.unload_adapter(temp_adapter)

    if rows and all((r["error"] or "").startswith("request failed") for r in rows):
        raise RuntimeError(rows[0]["error"])  # vLLM is unreachable: scoring this would report 0% as if it were a result
    metrics = {"label": req.label, "model": req.model, "eval_sha256": file_sha256(eval_path),
               **compute_metrics(spec, rows)}
    tmp = out_dir / "metrics.json.tmp"
    tmp.write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    os.replace(tmp, out_dir / "metrics.json")
    (out_dir / "predictions.jsonl").write_text(
        "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows), encoding="utf-8")
    return metrics
