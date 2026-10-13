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

Small samples, same direction. Re-checked on a fresh pod (`zls6se55saw8dd`, 14:30 UTC) after
restoring the run's artifacts: `make eval` gave the adapter 0.9907 again, and the hold-out tickets
gave base 4/5 / 2/5 and adapter 5/5 / 5/5 (the answer for one ticket differs from the first check).
The numbers above keep the first, lower result. The per-item predictions are in
`evals/{base,iter-1}/predictions.jsonl` of the run (copied to `../chirak-artifacts/mi350-2026-10-13-demo/`).

## Incident: local database wiped (2026-10-13, ~16:37 UTC)

`php artisan optimize` had cached the MariaDB config; a later `phpunit` run then ignored
`phpunit.xml`'s SQLite settings and `RefreshDatabase` migrated the real local database fresh. All
rows of runs 1–5 were lost (no binlog). Files were not affected. `tests/TestCase.php` now refuses to
run against a cached config or a non-SQLite connection.

Run 4 was rebuilt with `php artisan chirak:restore-run` from the worker's own job records (the
generate, train and evaluate results the pipeline had stored) and the run's files; every rebuilt row
carries `restored` with the job ids it came from, and the stop decision was recomputed by the same
`StopRules` code. Checked after the rebuild: eval sha256 `6ecec925…`, 215 rows, base 0.8406,
iteration 1 0.9907, stop reason "target met (0.991 >= 0.920)", 15 seeds and 5 hold-out tickets.
Not rebuilt: runs 1–3 (integration test and the two discarded runs; their files remain in
`app/storage/app/runs/` and `../chirak-artifacts/`) and run 5 (`merchant-triage-ieqgej`, lost at the
baseline step; its files are in `../chirak-artifacts/mi350-2026-10-13-pod3/`).

## eval-hard for run 4 (Task 2, 2026-10-13)

Run 4's 564 label-check rejects were only counted, not stored. The worker now writes them to
`<file>.dropped.jsonl`. A new pool was generated on the eval path (`purpose=pool`, `pool-1.jsonl`;
`eval.jsonl` untouched, still `6ecec925…`): 786 candidates, 575 rejected. After removing 27 items
sharing an 8-gram with the clean eval and 33 near-copies inside the pool, 120 were sampled, 17–18
per proposed category (`chirak_worker/hard_slice.py`).

Judge: `claude-sonnet-5-5` with the student's exact prompt. The API rejects `temperature` for this
model, so each item was judged twice and a disagreement between the two passes counts as no
answer (3 items). A first attempt used max_tokens 200; the model's adaptive thinking cut off 8 of
240 answers, so it was rerun with 4096 (attempt 1 kept in `eval-hard/attempt-1/`).

- Judge agrees with the proposed label: 14 items → eval-hard with that label.
- Judge disagrees: 106 items. In 82 of them the judge gives the same label as the generator's blind
  answer, so most rejects are text that does not fit its drawn label rather than ambiguous text.
- The owner delegated the review of the 106 to Claude Code (the agent building Chirak). These labels
  are marked `label_source: claude-code-delegated`, not human: 84 judge, 9 blind, 4 proposed,
  8 own label, 1 dropped (near-copy of another item). Rule applied: urgency high only for lost money,
  a missed deadline or a threat to leave; needs_human only for refunds, complaints or legal matters.

Frozen: `eval-hard.jsonl`, 119 items, sha256 `c5997a47…80a0f1`. Leakage vs train-1 (same guard):
0 by cosine, 12 by 8-gram, mostly stock phrases ("it's not a huge deal since I", "I need this fixed
immediately or I will"); one shares an order number. Not retrained, as instructed.

| Model | eval (clean) macro-F1 | eval-hard macro-F1 | eval-hard accuracy | urgency | needs_human | exact |
|---|---|---|---|---|---|---|
| Qwen3.5-4B base | 0.8406 | 0.7723 | 0.8151 | 0.7815 | 0.8235 | 0.5882 |
| + LoRA iter-1 | 0.9907 | 0.8254 | 0.8487 | 0.8824 | 0.8992 | 0.6555 |

Reproduce: `make eval RUN=merchant-triage-g4oirl ADAPTER=<base|…/iter-1/adapter> EVAL=eval-hard`.
