"""Build the files for the second task: Bulgarian online-store reviews labelled by people.

Source: Georgieva-Trifonova, Stefanova, Kalchev, "Dataset for Customer Feedback Text Analysis for Online
Stores Reviews in Bulgarian", Harvard Dataverse, https://doi.org/10.7910/DVN/TXIK9P, CC BY 4.0.
906 reviews from otzivi.bg and pazaruvaj.com about 87 online stores, manually labelled compliments,
complaints, mixed or suggestions. The train/test split is the one MTEB published
(https://huggingface.co/datasets/mteb/BulgarianStoreReviewSentimentClassfication, revision 0d00595e),
whose integer labels are 0 complaints, 1 compliments, 2 mixed, 3 suggestions (checked against the
Dataverse .txt: 548 of 906 texts matched exactly, all with that mapping).

Only the train split is read to choose seeds and hold-outs (fixed random draw, no hand picking). The test
split becomes eval-real-bgstores.jsonl and is never shown to the generator or used in training.

Usage: uv run --no-project --with pyarrow python -I build_bgstores.py <train.parquet> <test.parquet> <out dir>
"""
import hashlib
import json
import random
import sys
from datetime import datetime, timezone

import pyarrow.parquet as pq

LABELS = {0: "complaint", 1: "compliment", 2: "mixed", 3: "suggestion"}
SEEDS = {"compliment": 4, "complaint": 4, "mixed": 4, "suggestion": 3}
HOLDOUT = {"compliment": 2, "complaint": 1, "mixed": 1, "suggestion": 1}


def rows(path: str, split: str) -> list[dict]:
    out = []
    for i, r in enumerate(pq.read_table(path).to_pylist()):
        text = " ".join(r["text"].split())  # the source pads pros/cons sections with runs of spaces
        out.append({"id": f"bgstores-{split}-{i:04d}", "input": text, "output": {"category": LABELS[r["label"]]}})
    return out


def write(path: str, items: list[dict]) -> str:
    body = "".join(json.dumps(i, ensure_ascii=False) + "\n" for i in items)
    open(path, "w", encoding="utf-8").write(body)
    return hashlib.sha256(body.encode()).hexdigest()


def main() -> None:
    train_path, test_path, out = sys.argv[1:4]
    train, test = rows(train_path, "train"), rows(test_path, "test")
    rng = random.Random(20261015)
    # seeds and hold-outs are short enough to read in the wizard and in the playground
    pool = {lab: [r for r in train if r["output"]["category"] == lab and 60 <= len(r["input"]) <= 500] for lab in LABELS.values()}
    seeds, holdout = [], []
    for lab in LABELS.values():
        picked = rng.sample(pool[lab], SEEDS[lab] + HOLDOUT[lab])
        seeds += picked[:SEEDS[lab]]
        holdout += picked[SEEDS[lab]:]
    used = {r["id"] for r in seeds + holdout}
    train_real = [r for r in train if r["id"] not in used]

    hashes = {
        "seeds.jsonl": write(f"{out}/seeds.jsonl", [{"input": r["input"], "output": r["output"]} for r in seeds]),
        "holdout.jsonl": write(f"{out}/holdout.jsonl", [{"input": r["input"], "output": r["output"]} for r in holdout]),
        "train-real-candidates.jsonl": write(f"{out}/train-real-candidates.jsonl", train_real),
        "eval-real-bgstores.jsonl": write(f"{out}/eval-real-bgstores.jsonl", test),
        # for chirak:import-own-labelled: the test split is the run's own eval slice, the rest of train its training part
        "own-labelled.jsonl": write(f"{out}/own-labelled.jsonl", [{"input": r["input"], "output": r["output"], "split": s}
                                                                  for s, items in (("eval", test), ("train", train_real)) for r in items]),
    }
    count = lambda items: {lab: sum(r["output"]["category"] == lab for r in items) for lab in LABELS.values()}  # noqa: E731
    freeze = {"frozen_at": datetime.now(timezone.utc).isoformat(timespec="seconds"), "sha256": hashes,
              "n": {"seeds": count(seeds), "holdout": count(holdout), "train_real_candidates": count(train_real), "eval_real": count(test)}}
    json.dump(freeze, open(f"{out}/freeze.json", "w"), indent=1)
    print(json.dumps(freeze, indent=1))


if __name__ == "__main__":
    main()
