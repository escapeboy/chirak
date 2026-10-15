# Improvements after the real-text checks — requirements

Status (14 Oct 2026, evening): **R1–R4 done**; results in [demo-run-log.md](demo-run-log.md).
R3 follow-ups done the same evening: deleting the tickets also deletes the worker's copy (`DELETE /runs/{id}/own`),
and the owner can approve up to 5 examples for the public report. **R3.6 built (15 Oct, code + tests, no GPU
run):** at upload the owner sets aside 0/20/30/50% of the tickets for training (deterministic split before any
labelling); at freeze, training tickets that repeat an eval ticket or share a word 8-gram with one are dropped and
counted; "Train a new run with these tickets" starts a follow-up run with the same task, seeds and target, the
training part as its train-real data (put on the worker by the pipeline) and the same frozen eval-own slice
(same sha256). Deleting the tickets also deletes the follow-up run's copies, locally and on the worker, and is
refused while the follow-up still trains. **Run on a GPU on 15 Oct** with a second task whose data is labelled by
people and licensed for training (Bulgarian store reviews, CC BY 4.0): labelled tickets can now be uploaded as
they are (`chirak:import-own-labelled`, no judge), and run 7b, started with "Train a new run with these tickets",
trained on 700 of them. Result: no measurable gain over synthetic data alone on that task; see
[demo-run-log.md](demo-run-log.md#second-task-bulgarian-store-reviews-labelled-by-people-2026-10-15).
Open questions were settled by default after the owner said to do all four: Claude Code wrote the
guidelines; R2 uses real tickets as training data (in a separate run, so its effect is visible); the
tweets stay evaluation-only; the demo is at chirak.fleetq.net (owner's choice).
Inputs: [demo-run-log.md](demo-run-log.md#real-text-checks-2026-10-14). On human-written text the
served adapter gains only +3 to +6 pt on category, needs_human got worse on real tweets, and most
errors are shipping vs orders vs complaints, which the task guidelines leave open.

Owner's choices: both the hackathon (deadline Sat 17 Oct 2026, 12:00 Sofia) and the product after it.
Selected: R1 clearer guidelines + new run, R2 real text in training, R3 "bring your own tickets",
R4 live playground on Hetzner. Bulgarian real tickets: maybe after the hackathon.

## Goals

- G1. Better results on text written by people, not only on synthetic slices.
- G2. Judges can try the model live without a GPU running.
- G3. Every shop can measure the model on its own tickets (product).
- G4. Every number stays reproducible from stored artifacts; every slice reported separately.

## R1. Clearer task guidelines and a new run

Functional
- R1.1 Guidelines define every category in one or two sentences and give tie-break rules for the
  observed confusions: "where is my parcel" (shipping vs orders), anger about a late parcel
  (shipping vs complaints), a refund after a return (returns vs payments), shipping fees.
- R1.2 Explicit rules for urgency and for needs_human (what needs a person, what does not).
- R1.3 A new run with the new guidelines; run 4 and its evals stay untouched (additive).
- R1.4 The new adapter is scored on: its own clean eval, run 4's eval-hard, ABCD, ABCD-agreed, tweets.

Acceptance
- A1.1 Each slice is reported separately for base, run 4 iter-2 and the new adapter.
- A1.2 The new adapter is not worse than run 4 iter-2 on ABCD and tweets (category); needs_human on
  tweets is not below the base model (0.843), or the report says it is.
- A1.3 Where a new rule changes the meaning of an existing label (e.g. ABCD shipping vs orders),
  the affected eval is re-labelled under the new rule as a NEW frozen file, never edited in place.

## R2. Real text in training

Functional
- R2.1 Source: ABCD **train** split only (8034 chats, MIT). ABCD dev/test and all tweets used for
  evaluation never enter training.
- R2.2 Two possible uses (to choose in design): (a) real tickets as style examples for the
  generator, (b) real tickets with labels as part of the training set.
- R2.3 The leakage guard runs against every eval (clean, hard, ABCD, tweets); drop counts are stored.
- R2.4 The report states which share of the training data was real text and where it came from.

Acceptance
- A2.1 Zero items of the ABCD eval files or the tweet eval in any training file (ids and 8-gram).
- A2.2 The effect is measured on the same five slices as R1; no blended headline.

## R3. "Bring your own tickets" (product)

Functional
- R3.1 A shop uploads 100–1000 unlabelled tickets (CSV or JSONL, one ticket per row).
- R3.2 Before any text leaves the server, emails, phone numbers, order and card numbers are masked.
- R3.3 The Claude judge labels each ticket twice; stable answers are proposed labels.
- R3.4 A review screen shows unstable and low-confidence items; a person accepts, changes or drops
  each label. Who decided each label is stored (judge, human).
- R3.5 The reviewed set is frozen (sha256) as the run's "own tickets" eval and reported as its own slice.
- R3.6 Optional: a part of the uploaded tickets goes to training; that part is split off before
  labelling of the eval part and never overlaps it.

Non-functional
- Uploaded tickets are private to the run, deletable by the owner, never shown on the public report
  (only metrics and a few examples the owner approves).
- Labels written by a person and by the judge are always distinguishable in the report.

## R4. Live playground on Hetzner (CPU)

Functional
- R4.1 The served adapter is merged into the 4B base and quantized for CPU inference.
- R4.2 It runs on the owner's Hetzner server behind the existing demo-token and rate limit.
- R4.3 The playground shows the CPU model; the GPU path stays for runs.

Non-functional
- Answer in under ~5 s per ticket on CPU (to be measured).
- The quantized model is scored on all slices before it is shown, and the report states that the
  playground runs the quantized version, with its own numbers.
- No open ports beyond what the server already serves; no Funnel.

## Open questions for the owner

1. Order before Saturday: R1 and R4 first (fit the deadline), R2 if time allows, R3 after the
   hackathon — agree?
2. R1: who writes the new guidelines — the owner, or Claude Code with the owner approving them?
3. R2: (a) style examples or (b) direct training data? (b) is likely stronger on ABCD but makes
   ABCD a less independent check.
4. R4: which Hetzner host and domain/path; is a public playground (with the demo token) acceptable?
5. Budget: RunPod balance ≈ $20; R1 + R2 need about $5–10 of GPU. Top up, or stay within it?
6. Tweets are CC BY-NC-SA: fine for the hackathon evaluation; for a commercial product they cannot
   be used for training — agree to keep them evaluation-only?
