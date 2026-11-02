# Demo flow (record-ready)

> **Recorded version (14 Oct 2026):** [chirak-demo.mp4](chirak-demo.mp4), made by
> [demo/video/record.js](../demo/video/record.js) with the narration in
> [demo/video/narration.tsv](../demo/video/narration.tsv), voiced by [tts.py](../demo/video/tts.py)
> (Kokoro) and laid on the recording by [mux.py](../demo/video/mux.py). It shows run 6 on the live demo
> (chirak.fleetq.net, CPU playground). The first recording (run 4, macOS `say`) is in git history at
> commit 6b28236. The table below is the original
> plan from before eval-hard; its 0.99 / "target met" lines describe iteration 1 on the clean slice only.

Two parts: a completed run that is already in the database (`merchant-triage-g4oirl`), and a short
live run. Every number on screen comes from the `evaluations` / `decisions` tables; nothing is typed in.
Do not record until CHECKPOINT 2 is closed (the owner has re-run `make eval` on the final adapter).

## Before recording

1. Pod: `RUNPOD_API_KEY=... python3 spike/runpod-create.py`, then `spike/10-fresh-pod.sh` on it
   (~5 min). Copy the demo run's artifacts back so the playground can serve its adapter:
   `rsync -az ../chirak-artifacts/mi350-2026-10-13-demo/runs/merchant-triage-g4oirl root@$POD_SSH_HOST:/data/runs/ -e "ssh -p $POD_SSH_PORT"`.
2. `make tunnel`, `make health-smoke`. Locally: `php artisan serve`, `php artisan horizon`.
3. Browser at 1440×900, logged in with the demo token. Terminal with `watch -n1 rocm-smi` over SSH.
4. GPU cost while recording: $5.49/h. Stop the pod right after.

## Script (≈3 min of screen)

| Time | Screen | Say |
|---|---|---|
| 0:00 | Title slide | Shops need a narrow model that runs on their own server, without a data science team. |
| 0:20 | `/runs/new`: task, fields, 15 seeds | You describe the job and give a few real examples. This is support triage for a Bulgarian shop: category, urgency, needs a human. |
| 0:40 | Dashboard of `merchant-triage-g4oirl`, eval card | Before anything is trained, the eval set is frozen: 215 tickets, this sha256. Then the base model is scored: 0.84 macro-F1. |
| 1:00 | Leakage card | Training data comes from a different prompt that never sees eval. 78 near-copies of eval were dropped. A blind label check dropped items whose text did not support their label. |
| 1:15 | Live run (below), terminal with `rocm-smi` | Training runs on one AMD Instinct GPU: 1000 examples, about two minutes. |
| 1:45 | Back to `merchant-triage-g4oirl`: baseline vs iteration table, per-class, confusion matrix | Iteration 1 reaches 0.99. The stop rule fired in code: target met. Claude was not consulted, because the rules ended the loop. |
| 2:10 | Say the caveat with the table on screen | This eval holds the clear cases. On tickets outside that filter, 5 hold-out tickets went from 2 of 5 fully right to 4 of 5. |
| 2:20 | Playground, a hold-out ticket, adapter vs base | Same Bulgarian ticket to both. The base model calls a shop-location question a product question; the adapter says "other". |
| 2:40 | Adapter download | The adapter is a 82 MB file you can serve with vLLM on your own hardware. |
| 2:50 | Architecture slide | Laravel decides, a FastAPI worker on the AMD GPU does the work: vLLM, ROCm, peft. |

## Live run (started at the 1:15 mark, finished off-camera)

Start from `/runs/new` with the default seeds, **eval 50, train 300, target macro-F1 0.92**, review on.
The eval and baseline take about 3 minutes, so start it before the take, approve at the checkpoint
on camera, and cut to the training step. A small eval gives noisy numbers; do not present this
run's metrics as the result, the result is `merchant-triage-g4oirl`.

"Not worth fine-tuning" (optional, 15 s): a run with the same seeds and target macro-F1 0.80. The
base model scored about 0.84 on this task, so the run should stop after the baseline with
"not worth fine-tuning". Not yet recorded on the GPU; check it once before relying on it.
