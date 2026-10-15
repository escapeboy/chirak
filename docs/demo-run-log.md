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

## Iterating against eval-hard (Task 3, 2026-10-13)

`php artisan chirak:retarget-hard merchant-triage-g4oirl` reopened the completed run with eval-hard as
the target slice (target macro-F1 ≥ 0.92). Each iteration is evaluated on both slices; decisions and
the stop rules use the hard slice, the clean-slice decision of iteration 1 stays as it was. Train data
for targeted slices avoids both evals (`avoid_near` = eval + eval-hard) and sends up to 400 label-check
rejects per iteration to the Claude judge; a reject is kept only when the judge's label equals the
label the generator was asked for.

| Model | clean macro-F1 | clean exact | hard macro-F1 | hard accuracy | hard exact | hard complaints F1 |
|---|---|---|---|---|---|---|
| Qwen3.5-4B base | 0.8406 | 0.5953 | 0.7723 | 0.8151 | 0.5882 | – |
| + LoRA iter-1 | 0.9907 | 0.9070 | 0.8254 | 0.8487 | 0.6555 | 0.55 |
| + LoRA iter-2 (served) | 0.9815 | 0.9023 | 0.8441 | 0.8655 | 0.6639 | 0.67 |
| + LoRA iter-3 | 0.9813 | 0.9023 | 0.8332 | 0.8655 | 0.6555 | 0.53 |

Clean base values are the pipeline's own evaluation (`app/storage/app/runs/merchant-triage-g4oirl/evals/base/`); the pod copy of `evals/base` was overwritten by a later re-run (0.8362, see the baseline note above).

Stopped: maximum of 3 iterations. The target was not met. Iteration 2 is the best on the hard slice
and is served. The clean slice dipped from 0.9907 to 0.9815 (2 of 215 tickets).

- Judge rescue of train rejects: iteration 2 accepted 25 of 400, iteration 3 36 of 400. Rejects of
  the train path are mostly mislabels too, so the judge adds few items.
- Leakage guard dropped 84 (iter-2) and 61 (iter-3) train items by 8-gram, 0 by cosine.
- Caveat: the failure analyst sees eval-hard failures and its slice guidance sometimes describes
  them closely (e.g. "GDPR, data breach, contract threats") although the prompt forbids quoting
  tickets. Measured closeness of each eval-hard ticket to its nearest train ticket (bge-m3 cosine,
  `scratchpad/closeness.py`): train-1 median 0.743, p90 0.794, max 0.890, ≥0.85: 1; train-2 median
  0.730, p90 0.809, max 0.888, ≥0.85: 4. No train item reaches 0.90. The tail moved slightly toward
  eval-hard; the hard-slice gain (+1.9 pt) should be read with this in mind.
- Reproducibility check: `make eval RUN=merchant-triage-g4oirl ADAPTER=/data/runs/merchant-triage-g4oirl/iter-2/adapter EVAL=eval-hard`
  (label `verify-iter-2.eval-hard`) gave macro-F1 0.8441, equal to the stored value; exact match was
  0.6723 vs 0.6639 stored (1 of 119 tickets differs, vLLM batching is not bit-deterministic).

## Held-out examples (Task 4)

The 5 held-out tickets were never written to any dataset file. They were written by Claude at the
owner's request, not by a human. `php artisan chirak:holdout merchant-triage-g4oirl` ran them through
the playground path (`/predict`) for the base model and the served adapter (iter-2) and stored the
answers in `holdout_predictions`. Full JSON match: base 2/5, iter-2 5/5. Five examples are a
spot check, not a metric.

## Burn (Tasks 2–4, pod 0d23ljhj8ofgn6, MI350X $5.49/h)

GPU: 137 min ≈ 2.29 h ≈ $12.6 (about 70 min of it idle after the laptop killed the SSH tunnel and
Horizon for low memory at 19:55Z). Anthropic judge (`claude-sonnet-5-5`): eval-hard 240 requests,
75.7k in / 10.3k out; train-2 800 requests, 295.2k / 37.5k; train-3 800 requests, 289.7k / 39.9k.
Failure-analyst calls (3) are not counted here; their usage was not stored.

## Real-text checks (2026-10-14)

Two public datasets with text written by people, scored for the base model and the served adapter
(iteration 2). Builders: `demo/real/build_abcd.py`, `demo/real/build_twcs.py`, `demo/real/judge_real.py`.
Files, judge output and metrics: `../chirak-artifacts/real-evals-2026-10-14/` (not in this repo: the
tweets are CC BY-NC-SA). All three files were frozen (`freeze.json`, 09:49Z) before any model was scored.

- **ABCD** (ASAPP, MIT): chats with an online clothing store, written by crowd workers who were given a
  scenario. The label is the scenario mapped to our category; ABCD has no complaints, urgency or
  needs_human, so only category is scored (the worker now skips fields missing from the expected
  output). 300 tickets, 50 per class, from the dev and test splits; subflows that read two ways under
  our guidelines (shipping fee, shipping-speed upgrades, a refund missing after a return, store-wide
  questions, out of stock) were dropped. sha256 `f90e7577…b252`.
- **ABCD-agreed**: the 215 of those where the Claude judge (two passes) gives the same category as the
  scenario. The judge agreed on only 72%; most disagreements are "my order has not arrived" (scenario:
  shipping, judge: orders). Our task guidelines do not say which one that is. sha256 `078c3fc4…0f0b`.
- **Tweets** (Customer Support on Twitter, 2017, CC BY-NC-SA 4.0): the first tweet of a customer
  thread answered by an online retailer (Amazon 231, Target 48, M&S 48, eBay 26, Argos 23, others),
  English, 8+ words, handles and links removed. 400 sampled; labels from the Claude judge, kept where
  both passes agree: 357. A spot check of 25 by Claude Code agreed with the category in about 22;
  urgency and needs_human are less certain. sha256 `046498b8…fd5e`.
- 8-gram overlap with train-1..3: ABCD 2 items (stock phrases), tweets 0.

| | ABCD (300) accuracy | ABCD macro-F1, 6 classes | ABCD-agreed (215) accuracy | Tweets (357) macro-F1 | Tweets category acc | urgency | needs_human | all 3 right |
|---|---|---|---|---|---|---|---|---|
| Qwen3.5-4B base | 0.740 | 0.741 | 0.912 | 0.687 | 0.723 | 0.622 | 0.843 | 0.457 |
| + LoRA iter-2 | 0.797 | 0.811 | 0.977 | 0.713 | 0.751 | 0.742 | 0.731 | 0.445 |

Macro-F1 as stored by the worker also counts classes that are predicted but absent from the file;
for ABCD that is complaints (F1 0), so the stored values are 0.635 / 0.695 (all) and 0.912 / 0.838
(agreed). The table shows the 6 classes ABCD has.

What this says:
- On text written by people the adapter is still better than the base model on category: +5.7 pt
  accuracy on ABCD, +2.8 pt on tweets. The gain is much smaller than on the synthetic clean slice
  (0.84 → 0.98), and on real tweets both models are around 0.7.
- needs_human got worse on tweets (0.84 → 0.73) and all-3-fields did not improve: the adapter learned
  the synthetic data's idea of when a human is needed, which differs from the judge's on real tweets.
- Main confusions: shipping vs orders vs complaints. "My parcel is late and I'm angry" can be any of
  the three under the current guidelines. Clearer guidelines would help more than more data.

Reproduce: `make eval RUN=merchant-triage-g4oirl ADAPTER=<base|/data/runs/merchant-triage-g4oirl/iter-2/adapter> EVAL=eval-real-abcd`
(and `eval-real-abcd-agreed`, `eval-real-tweets`) with the three files copied into the run directory.
GPU: about 23 min (14 min of it a pod created without the template, which never started), ≈ $2.1.
Anthropic judge: ABCD 600 requests (161k in / 30k out), tweets 800 requests (224k in / 82k out).

## R1 + R2: guidelines v2 and real tickets in training (2026-10-14)

Requirements: [requirements-improvements.md](requirements-improvements.md). Guidelines v2 were written
by Claude Code (not by a human) after the real-text checks: one definition per category, tie-breaks
for the confused pairs (tone does not decide; delivery address is shipping; a faulty item is returns),
and rules for urgency and needs_human (`app/app/Support/TaskTemplate.php`). Seeds: `demo/seeds-v2.jsonl`
= `demo/seeds.jsonl` with 2 labels changed where v2 disagrees (slow site: complaints → other;
wrong phone case received, hold-out: complaints → returns). Runs 1–4 and their files are unchanged.

Because v2 changes the meaning of some labels, the judge-labelled evals were re-labelled under v2 as
new frozen files (`real-evals-2026-10-14/v2/freeze.json`, 10:25Z, before any v2 model was scored):
`eval-real-tweets-v2` (356, sha `feaf4ad5…`), `eval-hard-v2` (108, judge-only labels, sha `e2c16e58…`),
`eval-real-abcd-agreed-v2` (240, sha `1a91dd79…`). ABCD's scenario labels already follow v2's rules
and stay as they were (`eval-real-abcd`, sha `f90e7577…`). With v2 the judge agrees with the ABCD
scenarios on 80% (72% under v1): the guidelines are clearer.

Runs (one MI350X pod, 10:17–):
- **Run 5 `merchant-triage-j1zjro`** (v2, target macro-F1 0.92): stopped after the baseline, "not
  worth fine-tuning": the base model with v2 scored 0.935 macro-F1 on its own clean eval (0.84 with
  v1). Clearer guidelines alone fixed most of the category errors on clean synthetic tickets.
- **Run 5b `merchant-triage-ejqchw`** (v2, target exact match 0.92, all 3 fields right): base 0.656
  → iteration 1 0.949; target met.
- **Run 6 `merchant-triage-orv1o6`** (v2 + real tickets, target exact match 0.92): 378 tickets from
  the ABCD **train** split (`train-real`, sha `b7f10952…`) go into every training step next to the
  synthetic data. Category from the writer's scenario, urgency and needs_human from the Claude judge
  (v2), kept only where the judge's category agreed. 159 of 537 candidates were dropped as near-copies
  of an eval item (bge-m3 cosine ≥ 0.92 or a shared 8-gram; ABCD writers reuse the same phrases); 0
  overlap with the run's own eval after that. Base 0.651 → iteration 1 0.907 → iteration 2 0.926; target met.

Same frozen files, each model with its own prompt (v1 or v2):

| | ABCD (300) cat. acc | ABCD-agreed-v2 (240) | Tweets-v2 (356) cat. | urgency | needs_human | all 3 right | eval-hard-v2 (108) cat. | all 3 right |
|---|---|---|---|---|---|---|---|---|
| Base, v1 prompt | 0.740 | 0.833 | 0.612 | 0.469 | 0.803 | 0.292 | 0.787 | 0.518 |
| Run 4 iter-2, v1 | 0.797 | 0.887 | 0.668 | 0.764 | 0.753 | 0.427 | 0.843 | 0.574 |
| Base, v2 prompt | 0.787 | 0.921 | 0.694 | 0.548 | 0.801 | 0.345 | 0.880 | 0.630 |
| Run 5b iter-1, v2 | 0.797 | 0.933 | 0.747 | 0.823 | 0.837 | 0.559 | 0.944 | 0.685 |
| **Run 6 iter-2, v2 + real** | 0.840* | 0.975* | 0.742 | **0.846** | **0.857** | **0.593** | 0.907 | 0.667 |

\* Run 6 trained on other ABCD chats, so ABCD is no longer an independent check for it (same store,
same writing style); the tweets are. The base v2 row is shared by runs 5b and 6 (identical task spec).

Caveats: the tweet and eval-hard-v2 labels come from the same Claude judge reading the same v2
guidelines the v2 models were trained on, which favours v2 models on those files. ABCD labels do not
depend on the guidelines: there v2 adds +4.7 pt for the base model and run 5b ties run 4.

Conclusion: on real tweets, all three fields right went 0.29 (base, v1) → 0.43 (run 4) → 0.59 (run 6).
needs_human on tweets is now above the base model (0.857 vs 0.80; it fell to 0.75 with run 4).
Run 6 iteration 2 is the model the public demo serves.

## R4: CPU playground (llama.cpp, GGUF q8_0)

`demo/cpu/merge_adapter.py` merges an adapter into the text-only Qwen3.5-4B (undoing the vLLM key
rename) and llama.cpp's `convert_hf_to_gguf.py --no-mtp` writes q8_0 GGUF (4.5 GB). Without `--no-mtp`
the file announced Qwen3.5's multi-token-prediction block that the text-only checkpoint does not
contain; the first two files were repaired with `demo/cpu/drop_mtp_gguf.py`. Scored with
`chirak_worker.cpu_predict eval` (the worker's prompt, greedy decoding and parser) on llama.cpp b11429:

| Run 4, same files | clean macro-F1 | eval-hard macro-F1 | eval-hard all 3 | ABCD acc | tweets acc |
|---|---|---|---|---|---|
| iter-2 bf16 on vLLM (GPU) | 0.9815 | 0.8441 | 0.6639 | 0.797 | 0.751 |
| iter-2 GGUF q8_0 (CPU build, run on Metal) | 0.9769 | 0.8441 | 0.6639 | 0.800 | 0.748 |
| base bf16 on vLLM | 0.8406 | 0.7723 | 0.5882 | 0.740 | 0.723 |
| base GGUF q8_0 | 0.8211 | 0.7799 | 0.5798 | 0.750 | 0.726 |

The q8_0 build moves each score by about one ticket. Metrics:
`../chirak-artifacts/cpu-gguf/evals/`. GGUF sha256: base `fcde04ce…`, run 4 iter-2 `924c985c…`,
run 6 iter-2 `551dd24a…`.

## R3: a shop's own tickets, end to end (2026-10-14)

Built on branch `feat/r3-own-tickets` by a separate agent, merged into main, then run end to end on
run 6 through the browser. The end-to-end run found two defects the feature tests had missed: the
Livewire action was named `upload`, which is also `$wire.upload` (Livewire's own file-upload API), so
the real button stored nothing (renamed `uploadTickets`); and review decisions were always recorded as
`human` (they now record who decided; `label_source` was widened by an additive migration).

Input: 150 first tweets of customer threads to the same retailers, sampled from TwCS and never used
in any eval or training file (`own-tickets-150.origin.json`). Masked on upload; Claude (two passes,
the student's prompt, guidelines v2) gave a stable label to 128; the 22 unstable ones were reviewed
by Claude Code on the owner's delegation: 18 labelled, 4 dropped as ambiguous. Frozen: 146 tickets,
sha256 `d04ea746…`, label sources judge 128, claude-code-delegated 18. Judge usage 300 requests,
217k in / 46k out. Evaluated on the GPU worker (run 6, iteration 2 served):

| Own tickets (146) | category acc | macro-F1 | urgency | needs_human | all 3 right |
|---|---|---|---|---|---|
| Base, v2 prompt | 0.678 | 0.585 | 0.480 | 0.767 | 0.329 |
| Run 6 iteration 2 | 0.712 | 0.602 | 0.801 | 0.856 | 0.548 |

These agree with the tweets-v2 eval (0.345 → 0.593 all three right). The public demo shows the scores
and counts only; `chirak:export-demo` never copies the tickets.

## Public demo (R4), deployed 2026-10-14

https://chirak.fleetq.net on fleetq-01 (`deploy/demo/`): runs 4, 5, 5b and 6 exported to SQLite, app
in read-only mode, playground on CPU with run 6 iteration 2 (GGUF q8_0, `551dd24a…`) and the base
model (`fcde04ce…`), 2 threads and 6 GB each. Measured through the app on that host: first request
~30 s per model (the long system prompt), then 4–6 s per model; base and adapter are asked in parallel,
so a comparison takes ~6 s. DNS `chirak.fleetq.net` A → fleetq-01, proxied by Cloudflare; vhost in the
shared nginx-proxy, which joined the network `chirak_proxy`. Demo token: 1Password, AI Agent vault,
"Chirak demo token".

## Burn, 14 Oct

GPU (MI350X, $5.49/h): real-text evals 23 min (14 of them a pod created without the template); runs
5/5b/6, GGUF builds and external evals 10:17–12:23 and 12:28–12:46 ≈ 2.4 h. RunPod balance after:
$6.30. Anthropic judge on 14 Oct: 4,658 requests, 2.72M input / 0.46M output tokens (real-text labels v1
and v2, ABCD train labels, eval-hard v2: 4,358 requests, 2.51M / 0.42M, summed from the
`*.judge.stats.json` files; own tickets: 300 requests, 0.22M / 0.05M).

## Second task: Bulgarian store reviews labelled by people (2026-10-15)

Why: every real-text label above comes from a Claude judge, and no real ticket set we had could be used
for training (TwCS is CC BY-NC-SA). This task has both: labels made by people and a licence that allows
training.

**Data.** Georgieva-Trifonova, Stefanova, Kalchev, *Dataset for Customer Feedback Text Analysis for Online
Stores Reviews in Bulgarian*, Harvard Dataverse, doi:10.7910/DVN/TXIK9P, **CC BY 4.0**: 906 reviews of 87
online stores from otzivi.bg and pazaruvaj.com, labelled by the authors as compliments, complaints, mixed
or suggestions. Split as published by MTEB (`mteb/BulgarianStoreReviewSentimentClassfication`, revision
`0d00595e`): 724 train, 182 test. The integer labels were checked against the Dataverse `.txt` (548 texts
matched exactly, all as 0 complaint, 1 compliment, 2 mixed, 3 suggestion). Builder:
`demo/bgstores/build_bgstores.py`; files and `freeze.json` (07:50Z, before any model was scored):
`../chirak-artifacts/bgstores-2026-10-15/`.

- Seeds (15) and hold-outs (5) were drawn at random from the train split (fixed seed, 60–500 characters,
  4/4/4/3 and 2/1/1/1 per class). The test split was not opened before scoring.
- Task: one field, `category` ∈ {compliment, complaint, mixed, suggestion}, Bulgarian only
  (`demo/bgstores/task.json`). **The guidelines were written by Claude Code after reading the train split,
  not by a person.** Target macro-F1 0.95 on the synthetic eval, eval 200, train 1000 per iteration.
- The 182 test reviews are the run's own slice, uploaded with their labels by the new
  `chirak:import-own-labelled` (label_source `dataset`: no judge, no review); the 704 other train reviews
  are its training part. 2 of those share a word 8-gram with a test review and were left out at the
  freeze (702 kept). eval-own sha256 `49917a73…10b6` (the texts after PII masking).

**Runs** (one MI350X pod, 07:52–08:49 UTC). Both checkpoints before training were approved by Claude Code on the
owner's instruction to run the whole plan: for run 7 after reading 10 eval and 10 train items, the leakage counts and
the eval hash; for run 7b after the leakage counts, the eval hash and the base score (same prompts as run 7):
- **Run 7 `store-review-triage-bg-2cri7b`**, synthetic data only. Eval: 200 + 15 seeds, sha256
  `7993e197…b342`, 7 of 276 candidates dropped by the blind label check. Train-1: 1000 of 1368 candidates,
  11 dropped as near-copies of the eval. Macro-F1 on its synthetic eval 0.903 → 0.981 after iteration 1,
  target met. Training 176 s.
- **Run 7b `store-review-triage-bg-own-tickets-ujdok9`**, started with "Train a new run with these
  tickets" (R3.6): the same task plus the 702 human-labelled train reviews in every iteration. Before
  approving, `demo/real/leakage_real.py` found 2 of them with bge-m3 cosine ≥ 0.92 to a test review (one
  0.98: the same review posted twice) and 0 against the run's synthetic eval; those 2 were removed, so
  train-real has 700 rows (sha256 `dc19e504…f4d2`). Its own synthetic eval sha256 `b40fb79d…5198`. Macro-F1
  on it 0.903 → 0.977 after iteration 1, target met. Training 295 s (1700 examples).

**On the 182 test reviews labelled by people** (the same frozen file for all three):

| | Accuracy | Macro-F1 | compliment F1 | complaint F1 | mixed F1 | suggestion F1 |
|---|---|---|---|---|---|---|
| Always "compliment" | 0.648 | – | | | | |
| Qwen3.5-4B, no fine-tune | 0.758 | 0.623 | 0.863 | 0.882 | 0.520 | 0.229 |
| Run 7 iteration 1 (synthetic only) | **0.835** | 0.698 | 0.911 | 0.857 | 0.478 | 0.545 |
| Run 7b iteration 1 (synthetic + 700 real) | **0.835** | 0.715 | 0.918 | 0.844 | 0.552 | 0.545 |

Paired bootstrap, 5000 resamples of the 182 reviews (`../chirak-artifacts/mi350-2026-10-15-bgstores/bootstrap.txt`):
- run 7 − base: accuracy +0.077, 95% CI [+0.016, +0.137]; macro-F1 +0.075, CI [−0.038, +0.173];
- run 7b − base: accuracy +0.077, CI [+0.022, +0.137]; macro-F1 +0.091, CI [−0.023, +0.190];
- run 7b − run 7: accuracy 0.000, CI [−0.044, +0.044]; macro-F1 +0.017, CI [−0.042, +0.083].

What this says:
- Fine-tuning on synthetic data written from 15 real examples made the 4B model better on real reviews
  labelled by people: accuracy 0.76 → 0.84, and the interval excludes zero. The base model called many
  compliments "suggestion" (16 of 118); the adapters almost never do.
- Macro-F1 also rose, but its interval includes zero: the test split has only 6 suggestions and 25 mixed
  reviews, so those classes swing on a few reviews.
- Adding 700 real labelled reviews to training made no measurable difference over synthetic data alone.
  We did not show that real training data helps here.
- The gap between the synthetic eval (0.98) and the human labels (0.70 macro-F1) is large. Mixed reviews
  are the main error; the human labels are themselves debatable in places (several "suggestion" reviews
  in the train split read as compliments).

Reproduce: `make eval RUN=<slug> ADAPTER=<base|/data/runs/<slug>/iter-1/adapter> EVAL=eval-own` gave
exactly the stored numbers for all three rows (0.7582/0.6234, 0.8352/0.6981, 0.8352/0.7147).
Artifacts (eval files, train files, adapters, predictions, logs; 55 files, sha256-checked against the pod):
`../chirak-artifacts/mi350-2026-10-15-bgstores/`.

Defect found on the way: `own_sets.eval_stage` was 32 characters, too short for the served name
`store-review-triage-bg-2cri7b-iter-1`, so MariaDB refused the update and the own-slice evaluation of the
adapter failed; widened to 255 (migration `2026_10_15_000006`). SQLite, used by the tests and the public
demo, does not enforce the length.

GPU: 57 min on MI350X ($5.49/h) ≈ $5.0; RunPod balance after: $51.05. No Anthropic judge calls (the labels
came with the data); the failure analyst was not called, since both runs met the target after iteration 1.
