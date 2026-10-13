"""eval-hard: an arbitrated slice of the candidates the blind label check rejected.

    python -m chirak_worker.hard_slice sample  --run-dir D --dropped F [F ...] [--n 120]
    python -m chirak_worker.hard_slice judge   --run-dir D            (needs ANTHROPIC_API_KEY)
    python -m chirak_worker.hard_slice freeze  --run-dir D --reviewed F
    python -m chirak_worker.hard_slice leakage --run-dir D --train F   (needs the embedding model)

Authority for the final label: human > Claude judge > generator. Items where the judge agrees with the
proposed label keep it; every other item goes to a human, whose choice is final.
"""
import argparse
import hashlib
import json
import random
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path

from .leakage import LEAK_NGRAM, file_sha256, ngram_set
from .parsing import parse_student_answer
from .prompts import compact_json
from .schemas import TaskSpec

HUMAN_CHOICES = ("proposed", "judge", "blind", "drop")


def read_jsonl(p: Path) -> list[dict]:
    return [json.loads(line) for line in p.read_text(encoding="utf-8").splitlines() if line.strip()]


def write_jsonl(p: Path, rows: list[dict]) -> None:
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows), encoding="utf-8")


def load_spec(run_dir: Path) -> TaskSpec:
    return TaskSpec.model_validate_json((run_dir / "task_spec.json").read_text())


def stratified(groups: dict[str, list[dict]], n: int, rng: random.Random) -> list[dict]:
    """Equal share per class; a class with too few items gives its unused share to the others."""
    pools = {k: rng.sample(v, len(v)) for k, v in sorted(groups.items())}
    take = dict.fromkeys(pools, 0)
    while sum(take.values()) < n:
        open_ = [k for k in pools if take[k] < len(pools[k])]
        if not open_:
            break
        for k in sorted(open_, key=lambda k: take[k]):
            if sum(take.values()) == n:
                break
            take[k] += 1
    return [x for k in pools for x in pools[k][:take[k]]]


def cmd_sample(a) -> dict:
    run_dir = Path(a.run_dir)
    spec = load_spec(run_dir)
    primary = spec.primary.name
    eval_grams = set().union(*(ngram_set(r["input"]) for r in read_jsonl(run_dir / "eval.jsonl")))
    seen_grams: set = set()
    seen_text: set = set()
    kept, excluded = [], {"exact_duplicate": 0, "ngram_overlap_eval": 0, "ngram_overlap_pool": 0, "blind_unparsed": 0}
    for f in a.dropped:
        for r in read_jsonl(Path(f)):
            g = ngram_set(r["input"])
            norm = " ".join(r["input"].lower().split())
            if norm in seen_text:
                excluded["exact_duplicate"] += 1
            elif not g.isdisjoint(eval_grams):
                excluded["ngram_overlap_eval"] += 1
            elif not g.isdisjoint(seen_grams):
                excluded["ngram_overlap_pool"] += 1
            else:
                # blind None: the generator's answer did not parse; it is still an item the generator could not label
                excluded["blind_unparsed"] += r["blind"] is None
                kept.append({**r, "source_file": Path(f).name})
                seen_grams |= g
            seen_text.add(norm)
    groups: dict[str, list[dict]] = {}
    for r in kept:
        groups.setdefault(r["proposed"][primary], []).append(r)
    sample = stratified(groups, a.n, random.Random(a.seed))
    write_jsonl(run_dir / "eval-hard" / "sample.jsonl", sample)
    stats = {"pool": sum(len(read_jsonl(Path(f))) for f in a.dropped), "eligible": len(kept),
             "excluded": excluded, "n": len(sample), "seed": a.seed, "ngram": LEAK_NGRAM,
             "eligible_by_class": {k: len(v) for k, v in sorted(groups.items())},
             "sample_by_class": {k: sum(1 for r in sample if r["proposed"][primary] == k) for k in sorted(groups)},
             "dropped_files": {Path(f).name: file_sha256(Path(f)) for f in a.dropped}}
    (run_dir / "eval-hard" / "sample.stats.json").write_text(json.dumps(stats, indent=1))
    return stats


def cmd_judge(a) -> dict:
    from .judge import ClaudeJudge

    run_dir = Path(a.run_dir)
    spec = load_spec(run_dir)
    out = run_dir / "eval-hard"
    sample = read_jsonl(out / "sample.jsonl")
    judge = ClaudeJudge(model=a.model)
    with ThreadPoolExecutor(8) as ex:
        passes = [list(ex.map(lambda r: judge.label(spec, r["input"]), sample)) for _ in range(a.passes)]
    judged = []
    for i, r in enumerate(sample):
        labels = [p[i][0] for p in passes]
        stable = all(x == labels[0] for x in labels)  # an unstable judge gives no answer; a human decides
        judged.append({"id": r["id"], "input": r["input"], "lang": r["lang"], "proposed": r["proposed"],
                       "blind": r["blind"], "judge": labels[0] if stable else None, "judge_passes": labels,
                       "judge_raw": [p[i][1] for p in passes]})
    write_jsonl(out / "judged.jsonl", judged)
    agreed = [j for j in judged if j["judge"] == j["proposed"]]
    review = [{**{k: j[k] for k in ("id", "input", "lang", "proposed", "blind", "judge", "judge_passes")}, "human": None}
              for j in judged if j["judge"] != j["proposed"]]
    write_jsonl(out / "for-human-review.jsonl", review)
    (out / "for-human-review.md").write_text(review_table(review), encoding="utf-8")
    stats = {"model": judge.model, "temperature": "model default (the API rejects temperature for this model)",
             "passes": a.passes, "n": len(judged), "agreed": len(agreed), "for_human_review": len(review),
             "judge_unstable": sum(len({json.dumps(x, sort_keys=True) for x in j["judge_passes"]}) > 1 for j in judged),
             "judge_unparsed": sum(any(x is None for x in j["judge_passes"]) for j in judged),
             "judge_equals_blind": sum(j["judge"] == j["blind"] for j in judged),
             "usage": judge.usage, "sample_sha256": file_sha256(out / "sample.jsonl"),
             "at": datetime.now(timezone.utc).isoformat(timespec="seconds")}
    (out / "judge.stats.json").write_text(json.dumps(stats, indent=1))
    return stats


def review_table(review: list[dict]) -> str:
    """The same items as for-human-review.jsonl, for reading. Decisions go into the JSONL's `human` field."""
    def fmt(lbl):
        return "—" if lbl is None else " / ".join(str(v).lower() for v in lbl.values())
    lines = ["Fill `human` in for-human-review.jsonl: \"proposed\", \"judge\", \"blind\", \"drop\", "
             "or a full label object. Labels below: category / urgency / needs_human.", "",
             "| id | ticket | proposed | judge (Claude) | blind (generator) |", "|---|---|---|---|---|"]
    for r in review:
        text = r["input"].replace("|", "\\|").replace("\n", " ")
        judge = fmt(r["judge"]) if r["judge"] is not None else "unstable: " + " ≠ ".join(fmt(x) for x in r["judge_passes"])
        lines.append(f"| {r['id']} | {text} | {fmt(r['proposed'])} | {judge} | {fmt(r['blind'])} |")
    return "\n".join(lines) + "\n"


def resolve_human(spec: TaskSpec, item: dict) -> dict | None:
    """The reviewer's `human` field: "proposed" | "judge" | "blind" | "drop" | a full label object."""
    h = item["human"]
    if h is None:
        raise ValueError(f"{item['id']}: not arbitrated (human is null)")
    if h == "drop":
        return None
    if isinstance(h, str):
        if h not in HUMAN_CHOICES or item[h] is None:
            raise ValueError(f"{item['id']}: human must be one of {HUMAN_CHOICES} or a label object, got {h!r}")
        return item[h]
    got, err = parse_student_answer(compact_json(h), spec)
    if got is None:
        raise ValueError(f"{item['id']}: invalid label object: {err}")
    return got


def cmd_freeze(a) -> dict:
    run_dir = Path(a.run_dir)
    spec = load_spec(run_dir)
    out_path = run_dir / "eval-hard.jsonl"
    if out_path.exists():
        raise SystemExit(f"{out_path} already exists; eval-hard is frozen")
    primary = spec.primary.name
    judged = read_jsonl(run_dir / "eval-hard" / "judged.jsonl")
    reviewed = {r["id"]: r for r in read_jsonl(Path(a.reviewed))}
    expected = {j["id"] for j in judged if j["judge"] != j["proposed"]}
    if set(reviewed) != expected:
        raise SystemExit(f"reviewed file does not match for-human-review.jsonl: "
                         f"missing {sorted(expected - set(reviewed))[:5]}, extra {sorted(set(reviewed) - expected)[:5]}")
    rows, counts = [], {"judge+proposed": 0, "human": 0, "human_drop": 0}
    for j in judged:
        if j["judge"] == j["proposed"]:
            label, src = j["proposed"], "judge+proposed"
        else:
            label, src = resolve_human(spec, reviewed[j["id"]]), "human"
            if label is None:
                counts["human_drop"] += 1
                continue
        counts[src] += 1
        rows.append({"id": f"hard-{len(rows) + 1:04d}", "input": j["input"], "output": label, "slice": label[primary],
                     "lang": j["lang"], "source": "hard", "label_source": src, "origin_id": j["id"]})
    write_jsonl(out_path, rows)
    digest = file_sha256(out_path)
    frozen_at = datetime.now(timezone.utc).isoformat(timespec="seconds")
    (run_dir / "eval-hard.sha256").write_text(digest + "\n")
    stats = {"path": str(out_path), "sha256": digest, "n": len(rows), "frozen": True, "frozen_at": frozen_at,
             "label_sources": counts, "by_slice": {k: sum(1 for r in rows if r["slice"] == k) for k in spec.primary.values},
             "reviewed_sha256": hashlib.sha256(Path(a.reviewed).read_bytes()).hexdigest()}
    (run_dir / "eval-hard" / "freeze.json").write_text(json.dumps(stats, indent=1))
    return stats


def cmd_leakage(a) -> dict:
    """Same guard and thresholds as train generation: how many train items are near-copies of eval-hard."""
    from .embedding import SentenceTransformerEmbedder
    from .config import Settings
    from .leakage import LeakageGuard

    run_dir = Path(a.run_dir)
    hard = [r["input"] for r in read_jsonl(run_dir / "eval-hard.jsonl")]
    train = read_jsonl(Path(a.train))
    texts = [r["input"] for r in train]
    emb = SentenceTransformerEmbedder(Settings.from_env().embedding_model)
    hits = LeakageGuard(emb, hard).check(texts, emb.encode(texts))
    res = {"train": Path(a.train).name, "train_sha256": file_sha256(Path(a.train)), "n_train": len(texts),
           "eval_hard_sha256": file_sha256(run_dir / "eval-hard.jsonl"),
           "leakage_cosine": hits.count("leakage_cosine"), "leakage_ngram": hits.count("leakage_ngram"),
           "colliding_ids": [r["id"] for r, h in zip(train, hits) if h]}
    (run_dir / "eval-hard" / f"leakage-{Path(a.train).stem}.json").write_text(json.dumps(res, indent=1))
    return res


def main(argv=None) -> int:
    p = argparse.ArgumentParser(prog="chirak_worker.hard_slice")
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("sample")
    s.add_argument("--run-dir", required=True)
    s.add_argument("--dropped", nargs="+", required=True)
    s.add_argument("--n", type=int, default=120)
    s.add_argument("--seed", type=int, default=20261013)
    j = sub.add_parser("judge")
    j.add_argument("--run-dir", required=True)
    j.add_argument("--model")
    j.add_argument("--passes", type=int, default=2)
    f = sub.add_parser("freeze")
    f.add_argument("--run-dir", required=True)
    f.add_argument("--reviewed", required=True)
    lk = sub.add_parser("leakage")
    lk.add_argument("--run-dir", required=True)
    lk.add_argument("--train", required=True)
    a = p.parse_args(argv)
    res = {"sample": cmd_sample, "judge": cmd_judge, "freeze": cmd_freeze, "leakage": cmd_leakage}[a.cmd](a)
    print(json.dumps(res, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
