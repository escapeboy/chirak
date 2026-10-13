"""Small HTTP client for the worker API (used by the CLI and the smoke checks)."""
import json
import os
import sys
import time

import httpx


class WorkerClient:
    def __init__(self, base_url: str | None = None, token: str | None = None):
        self.base = (base_url or os.environ.get("WORKER_BASE_URL", "http://127.0.0.1:8100")).rstrip("/")
        token = token if token is not None else os.environ.get("WORKER_BEARER_TOKEN", "")
        self.http = httpx.Client(base_url=self.base, headers={"Authorization": f"Bearer {token}"}, timeout=httpx.Timeout(900, connect=10))

    def request(self, method: str, path: str, ok: tuple[int, ...] = (200, 202), **kw) -> httpx.Response:
        r = self.http.request(method, path, **kw)
        if r.status_code not in ok:
            raise RuntimeError(f"{method} {path} -> HTTP {r.status_code}: {r.text[:500]}")
        return r

    def health(self) -> dict:
        return self.request("GET", "/health").json()

    def wait(self, job_id: str, timeout_s: int = 3600, poll_s: float = 3.0, quiet: bool = False) -> dict:
        deadline, last = time.time() + timeout_s, None
        while time.time() < deadline:
            job = self.request("GET", f"/jobs/{job_id}").json()
            if not quiet and job["progress"] != last:
                last = job["progress"]
                print(f"  [{job['status']}] {last['stage']} {last['done']}/{last['total']}", file=sys.stderr, flush=True)
            if job["status"] == "succeeded":
                return job["result"]
            if job["status"] == "failed":
                raise RuntimeError(f"job {job_id} failed: {job['error']}")
            time.sleep(poll_s)
        raise TimeoutError(f"job {job_id} still running after {timeout_s}s")

    def run(self, path: str, body: dict, timeout_s: int = 3600) -> dict:
        r = self.request("POST", path, json=body)
        return self.wait(r.json()["job_id"], timeout_s)

    def artifact(self, run_id: str, rel: str) -> bytes:
        return self.request("GET", f"/artifacts/{run_id}/{rel}").content

    def artifact_exists(self, run_id: str, rel: str) -> bool:
        return self.request("GET", f"/artifacts/{run_id}/{rel}", ok=(200, 404)).status_code == 200


def pretty(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, indent=2)
