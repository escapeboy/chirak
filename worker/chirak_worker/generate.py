"""Label-conditioned data generation: pick the target output first, ask the generator for a matching input."""
import json
import math
import os
import random
import re
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np

from .config import Settings
from .embedding import Embedder
from .jobs import JobContext
from .leakage import LeakageGuard, dedup, file_sha256, ngram_set, read_avoid_texts
from .parsing import parse_generated_items
from .prompts import build_eval_generation_prompt, build_train_generation_prompt
from .schemas import GenerateRequest, TaskSpec
from .vllm import LlmBackend

BATCH = 5
MAX_ROUNDS = 4
OVERSAMPLE = 1.25
DROP_KEYS = ("duplicate", "leakage_cosine", "leakage_ngram")
GUIDANCE_NGRAM = 5


def plan_slices(req: GenerateRequest) -> list[dict]:
    """[{name, n, guidance, primary (value|None)}]: where the n items go."""
    spec, values = req.task_spec, req.task_spec.primary.values
    if req.target_slices:
        return [{"name": t.name, "n": t.n, "guidance": t.guidance, "primary": t.name if t.name in values else None}
                for t in req.target_slices]
    base, extra = divmod(req.n, len(values))
    return [{"name": v, "n": base + (i < extra), "guidance": "", "primary": v} for i, v in enumerate(values) if base + (i < extra)]


def make_targets(spec: TaskSpec, sl: dict, count: int, rng: random.Random, offset: int = 0) -> list[dict]:
    """Choose the labels (the whole target output) and the language for `count` items of one slice."""
    values = spec.primary.values
    out = []
    for i in range(count):
        output = {}
        for f in spec.fields:
            if f.primary:
                output[f.name] = sl["primary"] or values[(offset + i) % len(values)]
            elif f.type == "bool":
                output[f.name] = rng.random() < 0.4
            else:
                output[f.name] = rng.choice(f.values)
        out.append({"slice": sl["name"], "guidance": sl["guidance"], "output": output,
                    "lang": spec.languages[(offset + i) % len(spec.languages)]})
    return out


def _batches(targets: list[dict]) -> list[list[dict]]:
    by_slice: dict[str, list[dict]] = {}
    for t in targets:
        by_slice.setdefault(t["slice"], []).append(t)
    return [ts[i:i + BATCH] for ts in by_slice.values() for i in range(0, len(ts), BATCH)]


def _atomic_write(path: Path, text: str) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(text, encoding="utf-8")
    os.replace(tmp, path)


def _detect_lang(text: str, languages: list[str]) -> str:
    cyr = len(re.findall(r"[Ѐ-ӿ]", text))
    letters = len(re.findall(r"[^\W\d_]", text)) or 1
    if "bg" in languages and cyr / letters > 0.3:
        return "bg"
    return "en" if "en" in languages else languages[0]


def run_generate(req: GenerateRequest, settings: Settings, llm: LlmBackend, embedder: Embedder,
                 ctx: JobContext, avoid_files: list[str]) -> dict:
    spec = req.task_spec
    rdir = settings.runs_dir / req.run_id
    rdir.mkdir(parents=True, exist_ok=True)
    is_eval = req.purpose == "eval"
    out_path = rdir / ("eval.jsonl" if is_eval else f"train-{req.iteration}.jsonl")
    if is_eval and out_path.exists():
        raise RuntimeError("eval.jsonl already exists; the eval set is frozen")
    if is_eval:
        # `make eval` re-scores an adapter with the run's own spec, read from here.
        _atomic_write(rdir / "task_spec.json", spec.model_dump_json(indent=2))

    seeds = [s.model_dump() for s in req.seed_examples] if is_eval else []
    rng = random.Random(f"{req.run_id}/{req.purpose}/{req.iteration}")
    slices = plan_slices(req)
    quota = {s["name"]: s["n"] for s in slices}
    avoid_texts = [] if is_eval else read_avoid_texts(avoid_files)
    guard = None if is_eval else LeakageGuard(embedder, avoid_texts)
    # Slice guidance comes from Claude's failure analysis, which sees eval failures. Guidance that
    # quotes eval text would put eval content into the train prompt, so it is dropped here.
    guidance_rejected = 0
    if avoid_texts:
        avoid_grams = set().union(*(ngram_set(t, GUIDANCE_NGRAM) for t in avoid_texts))
        for sl in slices:
            if sl["guidance"] and not ngram_set(sl["guidance"], GUIDANCE_NGRAM).isdisjoint(avoid_grams):
                sl["guidance"] = ""
                guidance_rejected += 1

    ctx.progress("starting generator")
    llm.ensure_mode("generator")

    accepted: list[dict] = []
    accepted_emb = [embedder.encode([s["input"] for s in seeds])] if seeds else []
    dropped = dict.fromkeys(DROP_KEYS, 0)
    n_candidates = 0
    total_target = sum(quota.values())

    for rnd in range(MAX_ROUNDS):
        have = {name: sum(1 for a in accepted if a["slice"] == name) for name in quota}
        targets: list[dict] = []
        for sl in slices:
            deficit = quota[sl["name"]] - have[sl["name"]]
            if deficit > 0:
                count = math.ceil(deficit * (OVERSAMPLE if rnd == 0 else 1.5))
                targets += make_targets(spec, sl, count, rng, offset=rng.randrange(1000))
        if not targets:
            break
        candidates = _generate_round(req, spec, llm, settings, targets, seeds, ctx, len(accepted), total_target)
        n_candidates += len(candidates)
        if not candidates:
            continue
        texts = [c["input"] for c in candidates]
        emb = embedder.encode(texts)
        leaks = guard.check(texts, emb) if guard else [None] * len(texts)
        clean = [i for i, r in enumerate(leaks) if r is None]
        for r in leaks:
            if r:
                dropped[r] += 1
        known = np.vstack(accepted_emb) if accepted_emb else None
        keep = dedup(emb[clean], known) if clean else []
        new_emb = []
        for i, k in zip(clean, keep):
            if k:
                accepted.append(candidates[i])
                new_emb.append(emb[i])
            else:
                dropped["duplicate"] += 1
        if new_emb:
            accepted_emb.append(np.vstack(new_emb))

    if not accepted:
        raise RuntimeError(f"generator produced no usable items ({n_candidates} candidates, dropped {dropped})")

    final: list[dict] = []
    for name, q in quota.items():
        final += [a for a in accepted if a["slice"] == name][:q]
    rng.shuffle(final)

    prefix = "eval" if is_eval else f"train-{req.iteration}"
    primary = spec.primary.name
    rows = [{"id": f"eval-{i:04d}", "input": s["input"], "output": s["output"], "slice": s["output"][primary],
             "lang": _detect_lang(s["input"], spec.languages), "source": "human"} for i, s in enumerate(seeds, 1)]
    start = len(rows) + 1
    rows += [{"id": f"{prefix}-{i:04d}", "input": c["input"], "output": c["output"], "slice": c["slice"],
              "lang": c["lang"], "source": "synthetic"} for i, c in enumerate(final, start)]
    _atomic_write(out_path, "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows))
    digest = file_sha256(out_path)
    if is_eval:
        _atomic_write(rdir / "eval.sha256", digest + "\n")

    by_slice: dict[str, int] = {}
    for c in final:
        by_slice[c["slice"]] = by_slice.get(c["slice"], 0) + 1
    leaked = dropped["leakage_cosine"] + dropped["leakage_ngram"]
    return {
        "path": str(out_path), "sha256": digest, "n": len(final), "n_candidates": n_candidates,
        "by_slice": by_slice, "dropped": dropped,
        "leakage_drop_rate": 0 if is_eval or not n_candidates else round(leaked / n_candidates, 4),
        "frozen": is_eval,
        "guidance_rejected": guidance_rejected,
    }


def _generate_round(req, spec, llm, settings, targets, seeds, ctx, accepted_n, total) -> list[dict]:
    temp = 0.7 if req.purpose == "eval" else 1.0
    batches = _batches(targets)
    done = 0

    def one(batch: list[dict]) -> list[dict]:
        guidance = batch[0]["guidance"]
        if req.purpose == "eval":
            messages = build_eval_generation_prompt(spec, batch, seeds, guidance)
        else:
            messages = build_train_generation_prompt(spec, batch, guidance)
        try:
            raw, _ = llm.chat("base", messages, temperature=temp, max_tokens=300 * len(batch) + 200, top_p=0.95)
        except Exception:
            return []  # one failed request costs a batch; the next round tops up
        items = parse_generated_items(raw, len(batch))
        return [{"slice": t["slice"], "output": t["output"], "lang": t["lang"], "input": items[n]}
                for n, t in enumerate(batch, 1) if n in items]

    out: list[dict] = []
    with ThreadPoolExecutor(settings.gen_concurrency) as ex:
        for res in ex.map(one, batches):
            out += res
            done += 1
            ctx.progress("generating", accepted_n + min(len(out), total), total)
    return out
