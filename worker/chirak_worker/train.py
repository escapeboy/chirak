"""Training job: stop vLLM, run the LoRA subprocess, package the adapter."""
import json
import os
import subprocess
import sys
import tarfile
from pathlib import Path

from .config import Settings
from .jobs import JobContext
from .schemas import TrainRequest
from .vllm import LlmBackend

PACKAGE_PARENT = Path(__file__).resolve().parent.parent


def pack_adapter(adapter_dir: Path, archive: Path) -> None:
    tmp = archive.with_suffix(".tmp")
    with tarfile.open(tmp, "w:gz") as tar:
        tar.add(adapter_dir, arcname=adapter_dir.name)
    os.replace(tmp, archive)


def run_train(req: TrainRequest, settings: Settings, llm: LlmBackend, ctx: JobContext, train_files: list[str]) -> dict:
    out_dir = settings.runs_dir / req.run_id / f"iter-{req.iteration}"
    out_dir.mkdir(parents=True, exist_ok=True)
    cfg = {
        "model": req.base_model or settings.student_model,
        "task_spec": req.task_spec.model_dump(),
        "train_files": train_files,
        "out_dir": str(out_dir),
        "lora": req.lora.model_dump(),
        "epochs": req.epochs,
        "lr": req.lr,
        "max_seq_len": req.max_seq_len,
    }
    cfg_path = out_dir / "train_config.json"
    cfg_path.write_text(json.dumps(cfg, ensure_ascii=False, indent=1))

    ctx.progress("stopping vllm")
    llm.stop()

    env = {**os.environ, "HF_HUB_DISABLE_XET": "1", "HF_HOME": os.environ.get("HF_HOME", str(settings.data_dir / "hf")),
           "PYTHONPATH": os.pathsep.join(filter(None, [str(PACKAGE_PARENT), os.environ.get("PYTHONPATH")]))}
    cmd = [settings.train_python or sys.executable, "-m", "chirak_worker.train_script", str(cfg_path)]
    log_path = out_dir / "train.log"
    ctx.progress("training", 0, 0)
    tail: list[str] = []
    with open(log_path, "w", encoding="utf-8") as log:
        proc = subprocess.Popen(cmd, cwd=PACKAGE_PARENT, env=env, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, text=True, bufsize=1)
        for line in proc.stdout:
            log.write(line)
            tail = (tail + [line.rstrip()])[-15:]
            if line.startswith("PROGRESS "):
                p = json.loads(line[len("PROGRESS "):])
                ctx.progress("training", p["step"], p["total"])
        code = proc.wait()
    if code != 0:
        raise RuntimeError(f"training exited with code {code}; see {log_path}\n" + "\n".join(tail))

    summary = json.loads((out_dir / "train_summary.json").read_text())
    ctx.progress("packaging")
    pack_adapter(out_dir / "adapter", out_dir / "adapter.tar.gz")
    return {
        "adapter_path": str(out_dir / "adapter"),
        "adapter_archive": str(out_dir / "adapter.tar.gz"),
        "loss_curve": summary["loss_curve"],
        "n_examples": summary["n_examples"],
        "epochs": summary["epochs"],
        "wall_s": summary["wall_s"],
        "peak_vram_gb": summary["peak_vram_gb"],
    }
