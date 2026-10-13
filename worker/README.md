# Chirak GPU worker

FastAPI service that runs on a RunPod AMD Instinct pod (MI300X or MI350X, see spike/SETUP.md). It generates data with a vLLM-served generator,
trains a bf16 LoRA on the student model (transformers + peft + trl), evaluates strictly, and serves
adapters. It makes no decisions; the Laravel control plane calls it. The API is specified in
[docs/worker-api.md](../docs/worker-api.md). Environment facts: [spike/SETUP.md](../spike/SETUP.md).

## Run

On the pod (the venv, vLLM and the models are already there, see `docs/runpod.md`):

```bash
rsync -az --no-owner --no-group -e "ssh -p $POD_SSH_PORT" --exclude .git --exclude worker/.venv ./ root@$POD_SSH_HOST:/data/chirak/
ssh -p $POD_SSH_PORT root@$POD_SSH_HOST 'cp /data/chirak/worker/start.sh /data/start.sh && /data/start.sh'
```

`start.sh` restores `/opt/venv-train`, installs `worker/requirements.txt`, sources `/data/chirak/.env`
and starts the worker on `127.0.0.1:8100` (log: `/data/logs/worker.log`). Reach it through
`ssh -N -L 8100:127.0.0.1:8100 ...`. Alternatively use the image `ghcr.io/escapeboy/chirak-worker`.

Local, no GPU (unit tests): `uv venv .venv --python 3.12 && uv pip install -r requirements-dev.txt && make test`.

## Environment

| Variable | Default | |
|---|---|---|
| `WORKER_BEARER_TOKEN` | required | worker refuses to start without it |
| `DATA_DIR` | `/data` | runs live in `$DATA_DIR/runs/<run_id>/`, job records in `runs/_jobs/` |
| `WORKER_HOST` / `WORKER_PORT` | `127.0.0.1` / `8100` | never bind `0.0.0.0` unless you know why |
| `GENERATOR_MODEL` | `Qwen/Qwen3.8-27B` | vLLM `generator` mode |
| `STUDENT_MODEL` | `Qwen/Qwen3.5-4B` | vLLM `student` mode and the LoRA base |
| `EMBEDDING_MODEL` | `BAAI/bge-m3` | dedup and leakage guard |
| `VLLM_BIN`, `VLLM_PORT` | `vllm`, `8000` | vLLM runs as a local process owned by the worker |
| `VLLM_START_TIMEOUT_S` | `1500` | mode start takes 2 to 7 minutes |
| `GEN_CONCURRENCY` | `32` | parallel requests to vLLM |
| `TRAIN_PYTHON` | worker's interpreter | python used for the training subprocess |

## Smoke tests and CLI (need a running worker)

Set `WORKER_BASE_URL` (default `http://127.0.0.1:8100`) and `WORKER_BEARER_TOKEN`, then in this directory:

```bash
make health-smoke generate-smoke train-smoke evaluate-smoke serve-smoke   # tiny fixtures, run id smoke-1
make eval RUN=demo-1 ADAPTER=/data/runs/demo-1/iter-1/adapter              # or ADAPTER=base; prints metrics JSON
```

Each smoke step reuses the artifacts of the previous one and creates what is missing. The first call
in a vLLM mode includes the 2 to 7 minute model start. `RUN_GPU_SMOKE=1 pytest -m gpu` runs the same steps.
`make eval` needs `task_spec.json` in the run directory, or pass `EVAL_ARGS="--spec file.json"`.
