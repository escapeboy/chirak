"""Job records persisted as JSON files plus a single-thread runner (one GPU job at a time, FIFO)."""
import json
import os
import queue
import secrets
import threading
import time
import traceback
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

RESTART_ERROR = "worker restarted"


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def new_job_id() -> str:
    # millisecond prefix keeps ids sortable by submission time
    return f"job_{int(time.time() * 1000):012x}{secrets.token_hex(3)}"


class JobStore:
    def __init__(self, directory: Path):
        self.dir = Path(directory)
        self.dir.mkdir(parents=True, exist_ok=True)
        self._lock = threading.Lock()

    def _path(self, job_id: str) -> Path:
        return self.dir / f"{job_id}.json"

    def save(self, job: dict) -> None:
        with self._lock:
            tmp = self._path(job["id"]).with_suffix(".tmp")
            tmp.write_text(json.dumps(job, ensure_ascii=False))
            os.replace(tmp, self._path(job["id"]))

    def load(self, job_id: str) -> dict | None:
        if not job_id.startswith("job_") or "/" in job_id or ".." in job_id:
            return None
        p = self._path(job_id)
        return json.loads(p.read_text()) if p.exists() else None

    def recover(self) -> int:
        """Jobs left queued/running by a dead worker cannot resume: mark them failed."""
        n = 0
        for p in self.dir.glob("job_*.json"):
            job = json.loads(p.read_text())
            if job["status"] in ("queued", "running"):
                job.update(status="failed", error=RESTART_ERROR, finished_at=now_iso())
                self.save(job)
                n += 1
        return n


class JobContext:
    def __init__(self, runner: "JobRunner", job: dict):
        self._runner, self._job = runner, job

    def progress(self, stage: str, done: int = 0, total: int = 0) -> None:
        self._job["progress"] = {"stage": stage, "done": done, "total": total}
        self._runner.store.save(self._job)


class JobRunner:
    def __init__(self, store: JobStore):
        self.store = store
        self.store.recover()
        self._q: queue.Queue = queue.Queue()
        self._current: dict | None = None
        self._thread = threading.Thread(target=self._loop, name="job-runner", daemon=True)
        self._thread.start()

    def submit(self, job_type: str, run_id: str, fn: Callable[[JobContext], dict]) -> str:
        job = {
            "id": new_job_id(), "type": job_type, "run_id": run_id, "status": "queued",
            "created_at": now_iso(), "started_at": None, "finished_at": None,
            "progress": {"stage": "queued", "done": 0, "total": 0}, "result": None, "error": None,
        }
        self.store.save(job)
        self._q.put((job, fn))
        return job["id"]

    @property
    def current(self) -> dict | None:
        return self._current

    @property
    def queue_length(self) -> int:
        return self._q.qsize()

    def _loop(self) -> None:
        while True:
            job, fn = self._q.get()
            job.update(status="running", started_at=now_iso())
            self._current = job
            self.store.save(job)
            try:
                job["result"] = fn(JobContext(self, job))
                job["status"] = "succeeded"
            except Exception as e:  # a failed job must never kill the runner
                traceback.print_exc()
                job["status"], job["error"] = "failed", f"{type(e).__name__}: {e}"
            job["finished_at"] = now_iso()
            self.store.save(job)
            self._current = None
