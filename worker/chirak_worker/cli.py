"""CLI for a running worker: python -m chirak_worker.cli {health|wait|eval|smoke} ..."""
import argparse
import json
import os
import sys
from pathlib import Path

from .client import WorkerClient, pretty


def cmd_eval(c: WorkerClient, a) -> None:
    data = os.environ.get("DATA_DIR", "/data")
    spec = json.loads(Path(a.spec).read_text()) if a.spec else json.loads(c.artifact(a.run, "task_spec.json"))
    base = a.adapter == "base"
    label = a.label or ("base" if base else Path(a.adapter).parent.name)
    if not a.label and a.eval != "eval":
        label += f".{a.eval}"  # keep results on another eval set apart from evals/<label> of the frozen eval
    m = c.run("/evaluate", {"run_id": a.run, "eval_file": a.eval_file or f"{data}/runs/{a.run}/{a.eval}.jsonl",
                            "model": a.adapter, "task_spec": spec, "label": label})
    if not a.full:
        m = {**m, "failures": f"{len(m['failures'])} items (use --full to print)"}
    print(pretty(m))


def main(argv=None) -> int:
    p = argparse.ArgumentParser(prog="chirak_worker.cli")
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("health")
    w = sub.add_parser("wait")
    w.add_argument("job_id")
    e = sub.add_parser("eval", help="evaluate an adapter (or 'base') on a run's frozen eval set and print the metrics")
    e.add_argument("--run", required=True)
    e.add_argument("--adapter", required=True, help="adapter directory or 'base'")
    e.add_argument("--label")
    e.add_argument("--eval-file")
    e.add_argument("--eval", default="eval", help="dataset name in the run directory: eval (default) or eval-hard")
    e.add_argument("--spec", help="task spec JSON; default: <run>/task_spec.json from the worker")
    e.add_argument("--full", action="store_true")
    s = sub.add_parser("smoke")
    s.add_argument("step", choices=["health", "generate", "train", "evaluate", "serve"])
    a = p.parse_args(argv)

    c = WorkerClient()
    try:
        if a.cmd == "health":
            print(pretty(c.health()))
        elif a.cmd == "wait":
            print(pretty(c.wait(a.job_id)))
        elif a.cmd == "eval":
            cmd_eval(c, a)
        else:
            from .smoke import STEPS

            print(f"smoke {a.step} against {c.base} (run {os.environ.get('SMOKE_RUN', 'smoke-1')})")
            STEPS[a.step](c)
            print(f"PASS smoke {a.step}")
    except (RuntimeError, AssertionError, TimeoutError, OSError) as ex:
        print(f"FAIL: {ex}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
