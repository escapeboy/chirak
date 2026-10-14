# Chirak

*Чирак* — apprentice. A master model teaches a small apprentice model one craft until it can do it alone.

Chirak turns a task description and a handful of examples into a small, self-hosted specialist model.
It freezes an eval set first, measures the untouched base model, synthesizes training data, LoRA
fine-tunes a 4B model on one AMD Instinct GPU, lets Claude analyse the failures, regenerates data for
the weak classes and stops on deterministic rules. Built for the AMD Developer Hackathon ACT III,
track *Reinvent Commerce*. Demo task: support-ticket triage for an online shop, Bulgarian and English.

![Public report of the demo run](docs/img/report.png)

Demo video (2 min): [docs/chirak-demo.mp4](docs/chirak-demo.mp4). Recorded on 14 Oct 2026 by a
Playwright script ([demo/video/](demo/video/)) with a synthetic voice (macOS `say`) and captions. The
playground answers in it are live calls to the iteration-2 adapter on an MI350X.

## Result of the demo run

Run `merchant-triage-g4oirl`, 13 Oct 2026, one AMD Instinct MI350X. Every number below is in the
`evaluations` table and reproducible with `make eval ADAPTER=<path> EVAL=<eval|eval-hard>` (see
[docs/demo-run-log.md](docs/demo-run-log.md)). Public report: **https://escapeboy.github.io/chirak/**

The run is measured on two frozen eval slices, reported separately:

- **Clean slice** (`eval`, 215 tickets, sha256 `6ecec925…3c9160`): tickets where the 27B generator's
  label and its own blind re-reading agree. These are the clear cases; easier than real traffic.
- **Hard slice** (`eval-hard`, 119 tickets, sha256 `c5997a47…80a0f1`): tickets the blind check
  rejected, re-labelled by a Claude judge (two passes) and reviewed. The 106 disagreements were
  reviewed by Claude Code on the owner's delegation, not by a human.

| macro-F1 (category) | Clean slice | Hard slice |
|---|---|---|
| Qwen3.5-4B, no fine-tune | 0.841 | 0.772 |
| + Chirak LoRA, iteration 1 | 0.991 | 0.825 |
| **+ Chirak LoRA, iteration 2 (served)** | **0.982** | **0.844** |
| + Chirak LoRA, iteration 3 | 0.981 | 0.833 |

| Hard slice, base → iteration 2 | category acc | urgency acc | needs_human acc | all 3 fields right | valid JSON |
|---|---|---|---|---|---|
| | 0.82 → 0.87 | 0.78 → 0.84 | 0.82 → 0.92 | 0.59 → 0.66 | 100% → 100% |

The target was macro-F1 ≥ 0.92 on the hard slice. **It was not met**: the loop ran the maximum of 3
iterations and serves iteration 2. The weak class is *complaints* (F1 0.67), mostly confused with
*returns* and *other*. Iterations 2–3 were steered by Claude's analysis of hard-slice failures; its
guidance sometimes describes those failures closely, and the train data moved slightly closer to
eval-hard (no train ticket reaches cosine 0.90). Read the +1.9 pt of iteration 2 with that in mind.

5 hold-out tickets that are in no dataset file (written by Claude at the owner's request, not by a
human), all three fields right: base 2/5, iteration 2 5/5 — a spot check, not a metric. Two earlier
demo runs were discarded at the human checkpoint before any training; the log says why.

## How it works

```mermaid
flowchart LR
    U[Task description<br/>+ seed examples] --> CP
    subgraph CP[Control plane: Laravel 12, Livewire 3, MariaDB, Horizon]
      SM[Run state machine] --> SR[Deterministic stop rules]
      SM --> FA[Failure analyst<br/>Claude, strict JSON]
    end
    CP -- bearer token over SSH tunnel --> W
    subgraph W[GPU worker: FastAPI on one AMD Instinct GPU]
      G[vLLM: Qwen3.8-27B generator] --> LG[Leakage guard<br/>bge-m3 cosine + 8-gram]
      LG --> LC[Blind label check]
      T[bf16 LoRA training<br/>transformers + peft + trl]
      E[vLLM: Qwen3.5-4B + hot-loaded LoRA<br/>strict JSON eval]
    end
```

1. **Eval first.** The generator writes the eval set (temperature 0.7, seeds as style examples); it
   is frozen with a sha256 and never changes for the run.
2. **Baseline.** The untouched 4B student is scored on it. If it already meets the target, the run
   ends with "not worth fine-tuning".
3. **Train data.** A different prompt at temperature 1.0 that has no parameter for seeds or eval
   items. Every candidate goes through the leakage guard (cosine ≥ 0.92 with bge-m3, or a shared
   word 8-gram, against eval) and the blind label check (the generator labels the text with the
   student's exact prompt and no target; mismatches are dropped). Both drop counts are stored.
4. **Human checkpoint.** The run pauses; a person compares eval and train samples and approves.
5. **Train, evaluate.** bf16 LoRA (r 16, no QLoRA, no bitsandbytes), then strict scoring: a reply
   that is not exactly the JSON object with valid values counts as wrong.
6. **Failure analysis.** Claude gets the metrics, confusion matrix and failures and answers in
   strict JSON with the next data slices. Its guidance is dropped if it shares a 5-gram with eval.
7. **Stop rules, in code:** target met, 3 iterations, gain under 1 point, or leakage drops over 30%.
8. **Hard slice (optional).** Tickets the blind check rejected are judged by Claude, reviewed and
   frozen as `eval-hard`; `chirak:retarget-hard` then iterates against it while still reporting the
   clean slice. The generator is never the final authority for eval-hard labels.

The eval rules and why they exist: [docs/worker-api.md](docs/worker-api.md). Worker API contract:
same file. Run history with the discarded runs: [docs/demo-run-log.md](docs/demo-run-log.md).

## What runs on AMD

| Step | Where | Measured on the demo run (MI350X) |
|---|---|---|
| Eval and train generation, Qwen3.8-27B | vLLM 0.31 ROCm | 200 eval items from 777 candidates in ~2 min; 1000 train items from 3923 candidates in ~6 min |
| Leakage guard embeddings, bge-m3 | PyTorch ROCm | same GPU, inside generation |
| LoRA training, Qwen3.5-4B, bf16 | torch 2.14.1+rocm7.2, peft 0.21.2, trl 1.15.0 | 1000 examples, 2 epochs, 130 s, 12.2 GB peak |
| Evaluation and playground | vLLM with runtime LoRA hot-load | 215 tickets per eval at 128 concurrent requests: adapter p50 0.69 s, p95 1.28 s per request |

GPU cost of the demo run itself: about 17 minutes of a $5.49/h MI350X, ≈ $1.60, for iteration 1;
building eval-hard and the two extra iterations took about 2.3 h more (≈ $12.6, about 70 min of it
idle). All GPU time of the project is logged in [spike/gpu-hours.log](spike/gpu-hours.log).

The original target was an MI300X (192 GB). The environment spike passed on it on 12 Oct; on 13 Oct
RunPod had no MI300X in any region, so the demo moved to an MI350X (gfx950, 288 GB). The spike's
LoRA training and adapter-serving checks were re-run there and passed ([spike/SETUP.md](spike/SETUP.md)).

## Reproduce

You need a RunPod account with credit, an SSH key, and locally PHP 8.3, Composer, Node, Docker.

```bash
git clone https://github.com/escapeboy/chirak && cd chirak
cp .env.example .env                       # set HF_TOKEN, WORKER_BEARER_TOKEN, ANTHROPIC_API_KEY, DEMO_TOKEN
RUNPOD_API_KEY=... python3 spike/runpod-create.py     # MI350X pod; put its SSH host/port into .env
rsync -az --exclude .git ./ root@$POD_SSH_HOST:/data/chirak/ -e "ssh -p $POD_SSH_PORT"   # the pod's .env needs only HF_TOKEN + WORKER_BEARER_TOKEN
ssh -p $POD_SSH_PORT root@$POD_SSH_HOST /data/chirak/spike/10-fresh-pod.sh   # venv + models in parallel, starts the worker
make tunnel &                              # worker on 127.0.0.1:8100, never a public port
make health-smoke generate-smoke train-smoke evaluate-smoke serve-smoke
(cd app && cp .env.example .env && docker compose up -d && composer install && npm ci && npm run build && php artisan key:generate && php artisan migrate)
(cd app && php artisan serve & php artisan horizon)   # open http://127.0.0.1:8000/runs/new
make eval RUN=<run_id> ADAPTER=/data/runs/<run_id>/iter-2/adapter EVAL=eval-hard   # re-score any adapter (or base) on a frozen eval
```

Tests without a GPU: `make test` (worker: pytest with a fake vLLM and embedder; app: PHPUnit).

## What predates the event, and who wrote what

- Before 12 Oct: research notes only (not in this repo).
- 12 Oct: the environment spike (`spike/`). Everything else, worker, control plane and orchestrator,
  was written 12–13 Oct; the commit log shows the order.
- The code was written by Claude Code (Anthropic's coding agent) under review by the project owner,
  who set the scope, the eval rules and the human checkpoints.
- The 20 seed tickets in `demo/seeds.jsonl` were written by Claude at the owner's request, not by a
  human; eval rows built from them carry `source: "seed"`.
- Unsloth was the planned trainer. It refuses to import without bitsandbytes, which this project
  rules out, so training uses the documented fallback: transformers + peft + trl.

## Layout

| Path | What |
|---|---|
| `worker/` | FastAPI GPU worker: generate, train, evaluate, serve, predict ([README](worker/README.md)) |
| `app/` | Laravel control plane: wizard, dashboard, playground, public report ([README](app/README.md)) |
| `spike/` | Environment checks, pinned versions, pod bootstrap |
| `demo/` | Seed tickets of the demo task, the video recording script, the static report export |
| `docs/` | Worker API contract, run log, RunPod notes, screenshots |

## License

MIT
