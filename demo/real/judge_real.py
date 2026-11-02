"""Claude judge (two passes, the student's exact prompt) over the real-text candidates.

- tweets: the judge's label becomes the label when both passes agree; unstable or unparsed items drop.
- abcd: labels stay the scenario labels; the judge only checks the category mapping (agreement per
  ABCD subflow), and it runs before any model is scored on the set.

Usage: python3 judge_real.py <task_spec.json> <in.jsonl> <out_dir> <tweets|abcd>   (ANTHROPIC_API_KEY set)
"""
import json
import sys
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path

from chirak_worker.judge import ClaudeJudge
from chirak_worker.schemas import TaskSpec

spec = TaskSpec(**json.loads(Path(sys.argv[1]).read_text()))
rows = [json.loads(line) for line in open(sys.argv[2]) if line.strip()]
out, kind = Path(sys.argv[3]), sys.argv[4]
out.mkdir(parents=True, exist_ok=True)

judge = ClaudeJudge()
with ThreadPoolExecutor(8) as ex:
    passes = [list(ex.map(lambda r: judge.label(spec, r["input"]), rows)) for _ in range(2)]

judged = []
for i, r in enumerate(rows):
    labels = [p[i][0] for p in passes]
    stable = labels[0] is not None and labels[0] == labels[1]
    judged.append({**r, "judge": labels[0] if stable else None, "judge_passes": labels})
(out / f"{kind}.judged.jsonl").write_text("".join(json.dumps(j, ensure_ascii=False) + "\n" for j in judged))

stats = {"model": judge.model, "passes": 2, "n": len(judged), "stable": sum(j["judge"] is not None for j in judged),
         "usage": judge.usage, "at": datetime.now(timezone.utc).isoformat(timespec="seconds")}
if kind == "abcd":
    by_flow = defaultdict(Counter)
    for j in judged:
        by_flow[j["abcd_flow"]][(j["judge"] or {}).get("category") == j["output"]["category"]] += 1
    stats["agreement"] = round(sum(c[True] for c in by_flow.values()) / len(judged), 4)
    stats["by_subflow"] = {k: {"agree": c[True], "n": sum(c.values())} for k, c in sorted(by_flow.items())}
    stats["judge_categories_on_disagreement"] = dict(Counter(
        f"{j['output']['category']}->{(j['judge'] or {}).get('category')}" for j in judged
        if (j["judge"] or {}).get("category") != j["output"]["category"]).most_common())
else:
    stats["categories"] = dict(Counter(j["judge"]["category"] for j in judged if j["judge"]))
(out / f"{kind}.judge.stats.json").write_text(json.dumps(stats, indent=1))
print(json.dumps({k: v for k, v in stats.items() if k != "by_subflow"}, indent=1))
