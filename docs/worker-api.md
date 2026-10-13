# Worker API — contract v1

The GPU worker (`worker/`, Python, FastAPI) and the control plane (`app/`, Laravel) talk only
through this contract. The worker never makes decisions; the control plane never touches GPU
libraries. Environment facts behind it: `spike/SETUP.md`.

## Transport

- Worker listens on `127.0.0.1:8100` inside the RunPod pod. The control plane reaches it through
  an SSH tunnel: `WORKER_BASE_URL=http://127.0.0.1:8100` on the control-plane side.
- Every request except `GET /health` carries `Authorization: Bearer $WORKER_BEARER_TOKEN`.
  Missing or wrong token → `401 {"detail": "unauthorized"}`. `GET /health` without a token
  returns only `{"status": "ok"}`; with the token it returns the full body.
- JSON in, JSON out, UTF-8. Errors: `4xx/5xx {"detail": "<message>"}`.
- All artifacts live under `/data/runs/<run_id>/`. `run_id` matches `^[a-z0-9][a-z0-9-]{2,63}$`.

## Jobs

`POST /generate`, `/train`, `/evaluate` are asynchronous: they return `202 {"job_id": "..."}`
at once. GPU jobs run **one at a time**, in submission order. Poll `GET /jobs/{job_id}`:

```json
{
  "id": "job_01J...",
  "type": "generate | train | evaluate",
  "run_id": "demo-1",
  "status": "queued | running | succeeded | failed",
  "created_at": "2026-10-13T10:00:00Z",
  "started_at": null,
  "finished_at": null,
  "progress": {"stage": "generating", "done": 40, "total": 200},
  "result": null,
  "error": null
}
```

`result` is set when `status = succeeded`; `error` (string) when `failed`. Job records are
persisted to `/data/runs/_jobs/<job_id>.json`, so they survive a worker restart; a job that was
`running` when the worker died is reported as `failed` with `error = "worker restarted"`.

## Task spec (shared by every endpoint)

```json
{
  "name": "merchant-ticket-triage",
  "description": "Triage incoming customer-support tickets of an online shop.",
  "languages": ["bg", "en"],
  "fields": [
    {"name": "category", "type": "enum", "primary": true,
     "values": ["orders", "shipping", "returns", "payments", "product_questions", "complaints", "other"]},
    {"name": "urgency", "type": "enum", "values": ["low", "normal", "high"]},
    {"name": "needs_human", "type": "bool"}
  ],
  "guidelines": "Free-text labelling rules written by the user."
}
```

Exactly one field has `"primary": true` and must be an `enum`; accuracy / macro-F1 / per-class /
confusion are computed on it. The worker builds the student's system prompt from the task spec
with one deterministic function, and uses the same prompt for training, evaluation and `/predict`.
The model answers with one compact JSON object containing exactly the spec's fields.

A dataset item (one JSONL line):

```json
{"id": "eval-0007", "input": "Здравейте, поръчка 1234 още не е пристигнала...",
 "output": {"category": "shipping", "urgency": "normal", "needs_human": false},
 "slice": "shipping", "lang": "bg", "source": "synthetic | seed"}
```

## POST /generate

```json
{
  "run_id": "demo-1",
  "purpose": "eval | train | pool",
  "iteration": 1,
  "task_spec": {...},
  "seed_examples": [{"input": "...", "output": {...}}],
  "n": 200,
  "target_slices": [{"name": "returns", "n": 150, "guidance": "Returns mixed with payment questions."}],
  "avoid_near": ["/data/runs/demo-1/eval.jsonl"]
}
```

- `purpose = eval`: lower temperature (0.7), a prompt asking for realistic, messy, edge-case
  tickets; the `seed_examples` are few-shot style references **and** are written verbatim into
  the eval file (`source: "seed"`). The output is `/data/runs/<run_id>/eval.jsonl` plus
  `eval.sha256`. If `eval.jsonl` already exists for the run → `409` (eval is frozen; a change
  means a new run). `n` counts synthetic items; seeds come on top.
- `purpose = train`: higher temperature (1.0), a different prompt template, **no seed examples
  and no eval content in any prompt**. `seed_examples` is ignored for train. Output:
  `/data/runs/<run_id>/train-<iteration>.jsonl`. When `target_slices` is given, the n items are
  split per slice and each slice's `guidance` goes into its prompt; otherwise items are spread
  evenly over the primary field's values.
- `purpose = pool`: the eval path (prompt, seeds in the prompt, temperature 0.7) into
  `/data/runs/<run_id>/pool-<iteration>.jsonl`, never frozen, seeds not written. It exists to
  collect the candidates the blind label check rejects, for eval-hard (`chirak_worker/hard_slice.py`).
  `409` if that pool file already exists.
- Labels come from conditioning: the worker picks the target output first and asks the
  generator for an input that matches it.
- Dedup inside the new file: embedding cosine ≥ 0.95 between two new items → drop one.
- Leakage guard (train only): every candidate is compared with every item in every
  `avoid_near` file. Dropped if bge-m3 cosine ≥ 0.92 **or** it shares any word 8-gram
  (lowercased, punctuation stripped) with an `avoid_near` item. The generator never sees those files.

Result:

```json
{
  "path": "/data/runs/demo-1/train-1.jsonl",
  "sha256": "…",
  "n": 1000,
  "n_candidates": 1180,
  "by_slice": {"orders": 143, "...": 0},
  "dropped": {"duplicate": 40, "leakage_cosine": 120, "leakage_ngram": 20, "label_mismatch": 90},
  "leakage_drop_rate": 0.1186,
  "frozen": false,
  "dropped_path": "/data/runs/demo-1/train-1.dropped.jsonl"
}
```

`leakage_drop_rate = (leakage_cosine + leakage_ngram) / n_candidates`, 0 for eval. For eval,
`frozen = true` and `sha256` is the hash of `eval.jsonl` (also written to `eval.sha256`).

Blind label check (eval and train): every candidate that passed the leakage guard is labelled again
by the generator with the student's exact prompt (task, fields, guidelines; no target label),
temperature 0. It is kept only if that answer equals its target output; the rest are counted in
`dropped.label_mismatch`. Label-conditioned generation draws urgency and flags at random, and the
generated text does not always support them (seen at CHECKPOINT 1 of run `merchant-triage-wiivan`).
Every rejected candidate is written to `<file stem>.dropped.jsonl` next to the output (`dropped_path`
in the result), with `proposed` (the target label), `blind` (the generator's answer, `null` if it did
not parse) and the generation `round`. Before 2026-10-13 these were only counted.

## POST /train

```json
{
  "run_id": "demo-1",
  "iteration": 1,
  "task_spec": {...},
  "train_files": ["/data/runs/demo-1/train-1.jsonl"],
  "base_model": null,
  "lora": {"r": 16, "alpha": 16, "dropout": 0.05,
           "target_modules": ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"]},
  "epochs": 2,
  "lr": 0.0002,
  "max_seq_len": 1024
}
```

`base_model = null` → `STUDENT_MODEL`. bf16 LoRA with transformers + peft + trl, adamw_torch,
loss on the assistant turn only. Stops any running vLLM first. Output in
`/data/runs/<run_id>/iter-<iteration>/`. Result:

```json
{
  "adapter_path": "/data/runs/demo-1/iter-1/adapter",
  "adapter_archive": "/data/runs/demo-1/iter-1/adapter.tar.gz",
  "loss_curve": [{"step": 10, "loss": 1.23}],
  "n_examples": 1000, "epochs": 2, "wall_s": 312.4, "peak_vram_gb": 14.2
}
```

## POST /evaluate

```json
{"run_id": "demo-1", "eval_file": "/data/runs/demo-1/eval.jsonl",
 "model": "base | /data/runs/demo-1/iter-1/adapter", "task_spec": {...}, "label": "iter-1"}
```

Runs every eval item through the student vLLM (temperature 0, thinking off), parses the answer,
writes `/data/runs/<run_id>/evals/<label>/{metrics.json,predictions.jsonl}`. Result = metrics:

```json
{
  "label": "iter-1", "model": "...", "eval_sha256": "…", "n": 215,
  "json_valid_rate": 0.97,
  "accuracy": 0.81, "macro_f1": 0.79,
  "per_class": {"orders": {"precision": 0.8, "recall": 0.9, "f1": 0.85, "support": 30}},
  "confusion": {"labels": ["orders", "...", "other", "__invalid__"], "matrix": [[27, 0, 3]]},
  "fields": {"urgency": {"accuracy": 0.7}, "needs_human": {"accuracy": 0.88}},
  "exact_match": 0.52,
  "latency_ms": {"p50": 180, "p95": 410},
  "failures": [{"id": "eval-0007", "input": "...", "expected": {...}, "got": {...} , "raw": "...", "error": null}]
}
```

Rules: an answer that is not valid JSON, or misses a field, or has a value outside the spec,
is **wrong** for every metric and has `got = null`, `error = "<why>"`. In the confusion matrix its
predicted label is `__invalid__` (rows = expected, columns = predicted). `failures` lists every
item whose primary field is wrong or invalid.

## POST /serve, DELETE /serve/{name}

`POST /serve {"name": "demo-1-iter-2", "adapter_path": "/data/runs/demo-1/iter-2/adapter"}`
→ synchronous `200 {"name": ..., "loaded": true}`. Starts the student vLLM if needed and
hot-loads the adapter. `DELETE /serve/{name}` → `200 {"name": ..., "loaded": false}`.

## POST /predict (playground)

`{"model": "base" | "<served name>", "task_spec": {...}, "input": "..."}` → synchronous
`200 {"raw": "...", "parsed": {...} | null, "valid": true, "error": null, "latency_ms": 143}`.
Returns `409` while a GPU job is holding the GPU in another mode (e.g. training).

## GET /artifacts/{run_id}/{path}

Streams a file under `/data/runs/<run_id>/` (path traversal rejected). The control plane uses it
to copy datasets, metrics and the adapter archive, so its UI keeps working when the pod is off.

## GET /health

```json
{"status": "ok", "gpu": {"visible": true, "name": "AMD Instinct MI300X", "vram_used_gb": 61.2,
 "vram_total_gb": 205.8}, "vllm": {"mode": "student | generator | none", "model": "Qwen/Qwen3.5-4B"},
 "adapters": ["demo-1-iter-2"], "current_job": null, "queue_length": 0}
```
