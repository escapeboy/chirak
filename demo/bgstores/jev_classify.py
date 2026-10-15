"""Ask Jev (TypeSafe System One, hosted) for the category of each review: one choice question per review, with
the class descriptions of the task guidelines. A hosted reference point and a second opinion on the human labels;
the reviews are public (CC BY 4.0), so sending them out is allowed.

Usage: TYPESAFE_API_KEY=... python3 -I jev_classify.py <task.json> <in.jsonl> <out.jsonl>
Prints accuracy and macro-F1 against the labels in the file.
"""
import json
import os
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor

API = "https://api.typesafe.ai/v1/systemone"
task = json.load(open(sys.argv[1]))
rows = [json.loads(line) for line in open(sys.argv[2]) if line.strip()]
classes = task["fields"][0]["values"]
# class descriptions: the guideline line that starts with "- <class>:"
lines = {c: next(l[len(f"- {c}:"):].strip() for l in task["guidelines"].split("\n") if l.startswith(f"- {c}:")) for c in classes}
question = {"type": "choice", "instructions": "Reviews come from Bulgarian review sites about an online store. "
            "Pick the overall point of the review for the shop.", "criteria": lines}


def ask(row: dict) -> dict:
    body = json.dumps({"model": "jev-latest", "state": row["input"], "questions": {"category": question}}).encode()
    req = urllib.request.Request(API, data=body, method="POST", headers={
        "Authorization": f"Bearer {os.environ['TYPESAFE_API_KEY']}", "Content-Type": "application/json", "User-Agent": "chirak/1"})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                a = json.load(r)["answers"]["category"]
            return {"id": row["id"], "expected": row["output"]["category"], "choice": a.get("choice"), "confidence": a.get("confidence")}
        except Exception as e:  # noqa: BLE001
            err = f"{type(e).__name__}: {e}"
    return {"id": row["id"], "expected": row["output"]["category"], "choice": None, "confidence": None, "error": err}


with ThreadPoolExecutor(8) as ex:
    out = list(ex.map(ask, rows))
with open(sys.argv[3], "w") as f:
    for o in out:
        f.write(json.dumps(o, ensure_ascii=False) + "\n")

acc = sum(o["choice"] == o["expected"] for o in out) / len(out)
f1s = []
for c in classes:
    tp = sum(o["choice"] == c and o["expected"] == c for o in out)
    pp = sum(o["choice"] == c for o in out)
    ap = sum(o["expected"] == c for o in out)
    p, r = (tp / pp if pp else 0), (tp / ap if ap else 0)
    f1s.append(2 * p * r / (p + r) if p + r else 0)
print(json.dumps({"n": len(out), "errors": sum("error" in o for o in out), "accuracy": round(acc, 4),
                  "macro_f1": round(sum(f1s) / len(f1s), 4), "f1": dict(zip(classes, [round(x, 3) for x in f1s]))}))
