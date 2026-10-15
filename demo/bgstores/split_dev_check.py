"""Split the 700 real train reviews (run 7b's train-real, near-copies of test reviews removed) into two halves,
stratified by label, with a fixed seed:
  dev   - read to write the v2 guidelines and used as real training data;
  check - never read, never trained on; scored together with the test split.
Usage: python3 -I split_dev_check.py <train-real.jsonl> <out dir>
"""
import hashlib
import json
import random
import sys
from datetime import datetime, timezone

rows = [json.loads(line) for line in open(sys.argv[1]) if line.strip()]
rng = random.Random(20261016)
dev, check = [], []
for label in sorted({r["output"]["category"] for r in rows}):
    group = [r for r in rows if r["output"]["category"] == label]
    rng.shuffle(group)
    half = len(group) // 2
    dev += group[:half]
    check += group[half:]
out = sys.argv[2]
sha = {}
for name, items in (("dev", dev), ("check", check)):
    body = "".join(json.dumps({"id": r["id"], "input": r["input"], "output": r["output"]}, ensure_ascii=False) + "\n" for r in items)
    open(f"{out}/{name}.jsonl", "w").write(body)
    sha[f"{name}.jsonl"] = hashlib.sha256(body.encode()).hexdigest()
count = lambda items: {k: sum(r["output"]["category"] == k for r in items) for k in sorted({r["output"]["category"] for r in rows})}  # noqa: E731
freeze = {"frozen_at": datetime.now(timezone.utc).isoformat(timespec="seconds"), "sha256": sha, "n": {"dev": count(dev), "check": count(check)}}
json.dump(freeze, open(f"{out}/split.json", "w"), indent=1)
print(json.dumps(freeze, indent=1))
