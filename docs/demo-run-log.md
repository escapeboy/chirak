# Demo run log

Every run started for the demo, including the discarded ones, and why. All times UTC, 2026-10-13.
Hardware: 1× AMD MI350X (gfx950) on RunPod, because MI300X was out of stock (see `spike/SETUP.md`).

| # | Run | Seeds | What happened | Status |
|---|---|---|---|---|
| 1 | `itest-sb2nlg` | template seeds (6) | Integration test of the whole loop against the real worker: eval 30, train 150, 3 iterations. Not a demo result. | completed |
| 2 | `merchant-triage-wiivan` | `demo/seeds.jsonl` | Stopped at CHECKPOINT 1. In a 10-item eval sample, about 1 category and 2–3 urgency/needs_human labels contradicted the text or the guidelines: label-conditioned generation draws those fields at random. Discarded before any training. | discarded |
| 3 | `merchant-triage-fund0k` | `demo/seeds.jsonl` | First run with the blind label check (commit `1bcfd9e`). The check rejected 69% of candidates, and the fixed oversampling froze an eval of 178/200 items with complaints at 16/28. Discarded at CHECKPOINT 1 for eval size and balance, decided before looking at any trained model. | discarded |
| 4 | `merchant-triage-g4oirl` | `demo/seeds.jsonl` | Rounds sized by each slice's acceptance rate (commit `8ceb3af`). Eval 200 synthetic + 15 seeds, 28–29 per class, sha256 `6ecec925…3c9160`. Train-1: 1000 items, leakage guard dropped 78 of 3923 candidates. | the demo run |

## Run 4 at CHECKPOINT 1

- Baseline (Qwen3.5-4B, no adapter) on the frozen eval: macro-F1 0.8406, accuracy 0.8465, JSON valid 100%.
- Run 2's noisier eval gave a baseline of 0.6725. The label check keeps only items on which the 27B
  generator, given the student's exact prompt, agrees with the target label. Those are the clearer
  cases, so the eval is cleaner and also easier. Scores on it are not comparable with run 2.
- The target was raised from 0.85 to 0.92 before any training, because 0.85 was 1 point above the
  baseline. That choice was made at the checkpoint by the owner's auto-answer hook (Jev) on Claude's
  recommendation, not typed by the owner.
- Independent checks (a script outside the worker): 0 train items share an 8-gram with eval or with
  the hold-out tickets; 0 exact duplicates; the 5 hold-out tickets appear in no dataset file.

## Run 4 result

Stopped after iteration 1 by the rule "target met (0.991 >= 0.920)". Training: 1000 examples,
2 epochs, 130 s, peak 12.2 GB VRAM.

| Model | macro-F1 | accuracy | urgency acc | needs_human acc | exact match | JSON valid |
|---|---|---|---|---|---|---|
| Qwen3.5-4B base | 0.8406 | 0.8465 | 0.8744 | 0.7674 | 0.5953 | 100% |
| + LoRA iter-1 | 0.9907 | 0.9907 | 0.9581 | 0.9535 | 0.9070 | 100% |

Reproduction with `make eval` (same eval sha256 `6ecec925…3c9160`):
- adapter: 0.9907 macro-F1 in 3 of 3 re-runs;
- base: 0.8406 (pipeline), then 0.8459, 0.8415, 0.8362 in re-runs, about 1 item of 215 flipping.
  Decoding is greedy (temperature 0). Hypothesis, not verified: vLLM batches the concurrent
  requests differently each run, which changes the numerics enough to flip near-tie answers;
  running the eval with concurrency 1 twice would confirm it. Treat the baseline as 0.84 ± 0.005.

What the 0.99 means: train and eval labels both passed the same blind label check by the 27B
generator, so the student is measured on cases where the generator's reading of the guidelines
is unambiguous. Two checks that did not pass through that filter:

| Subset | base category | adapter category | base exact | adapter exact |
|---|---|---|---|---|
| 15 seed tickets in eval (written by Claude, not filtered) | 13/15 | 15/15 | 7/15 | 11/15 |
| 5 hold-out tickets (in no dataset file), via `/predict` | 4/5 | 5/5 | 2/5 | 4/5 |

Small samples, same direction. The per-item predictions are in
`evals/{base,iter-1}/predictions.jsonl` of the run (copied to `../chirak-artifacts/mi350-2026-10-13-demo/`).
