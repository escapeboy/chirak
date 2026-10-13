"""Manages the single local vLLM process (generator or student mode) and talks to its OpenAI API."""
import json
import os
import signal
import subprocess
import threading
import time
from pathlib import Path
from typing import Protocol

import httpx

from .config import Settings

GENERATOR_ARGS = ["--gpu-memory-utilization", "0.85", "--max-model-len", "8192"]
STUDENT_ARGS = [
    "--enable-lora", "--max-lora-rank", "64", "--max-loras", "4",
    "--max-model-len", "4096", "--gpu-memory-utilization", "0.5",
]


class VllmError(RuntimeError):
    pass


class LlmBackend(Protocol):
    mode: str
    adapters: dict[str, str]

    def ensure_mode(self, mode: str) -> None: ...
    def stop(self) -> None: ...
    def model_for(self, model: str) -> str: ...
    def chat(self, model: str, messages: list[dict], temperature: float, max_tokens: int, **extra) -> tuple[str, float]: ...
    def load_adapter(self, name: str, path: str) -> None: ...
    def unload_adapter(self, name: str) -> None: ...


class VllmManager:
    def __init__(self, settings: Settings):
        self.s = settings
        self.mode = "none"
        self.adapters: dict[str, str] = {}
        self._proc: subprocess.Popen | None = None
        self._lock = threading.RLock()
        self._http = httpx.Client(base_url=settings.vllm_url, timeout=httpx.Timeout(600, connect=10))
        self._state_file = settings.data_dir / "runs" / "_jobs" / "_adapters.json"
        self._load_registry()

    # --- adapter registry (survives worker and vLLM restarts) ---
    def _load_registry(self) -> None:
        try:
            self.adapters = json.loads(self._state_file.read_text())
        except (OSError, ValueError):
            self.adapters = {}

    def _save_registry(self) -> None:
        self._state_file.parent.mkdir(parents=True, exist_ok=True)
        self._state_file.write_text(json.dumps(self.adapters))

    @property
    def model_name(self) -> str:
        return {"generator": self.s.generator_model, "student": self.s.student_model}.get(self.mode, "")

    def model_for(self, model: str) -> str:
        """'base' -> the base model id; anything else is a hot-loaded adapter name."""
        return self.model_name if model == "base" else model

    # --- process control ---
    def _alive(self) -> bool:
        return self._proc is not None and self._proc.poll() is None

    def _port_in_use(self) -> bool:
        try:
            self._http.get("/v1/models", timeout=2)
            return True
        except httpx.HTTPError:
            return False

    def ensure_mode(self, mode: str) -> None:
        if mode not in ("generator", "student"):
            raise ValueError(mode)
        with self._lock:
            if self.mode == mode and self._alive():
                return
            self.stop()
            if self._port_in_use():
                raise VllmError(f"port {self.s.vllm_port} is already served by a process this worker does not own")
            self._start(mode)

    def _start(self, mode: str) -> None:
        model = self.s.generator_model if mode == "generator" else self.s.student_model
        args = GENERATOR_ARGS if mode == "generator" else STUDENT_ARGS
        env = {**os.environ, "VLLM_ROCM_USE_AITER": "0", "HF_HUB_DISABLE_XET": "1"}
        env.pop("HSA_OVERRIDE_GFX_VERSION", None)
        if mode == "student":
            env["VLLM_ALLOW_RUNTIME_LORA_UPDATING"] = "True"
        self.s.logs_dir.mkdir(parents=True, exist_ok=True)
        log_path = self.s.logs_dir / f"vllm-{mode}.log"
        cmd = [self.s.vllm_bin, "serve", model, *args, "--host", self.s.vllm_host, "--port", str(self.s.vllm_port)]
        with open(log_path, "ab") as log:
            self._proc = subprocess.Popen(cmd, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        self.mode = mode
        try:
            self._wait_ready(log_path)
            if mode == "student":
                for name, path in list(self.adapters.items()):
                    self._load_adapter_http(name, path)
        except Exception:
            self.stop()
            raise

    def _wait_ready(self, log_path: Path) -> None:
        deadline = time.time() + self.s.vllm_start_timeout_s
        while time.time() < deadline:
            if not self._alive():
                raise VllmError(f"vLLM {self.mode} exited with code {self._proc.returncode}; see {log_path}\n{_tail(log_path)}")
            try:
                if self._http.get("/v1/models", timeout=5).status_code == 200:
                    return
            except httpx.HTTPError:
                pass
            time.sleep(5)
        raise VllmError(f"vLLM {self.mode} not ready after {self.s.vllm_start_timeout_s}s; see {log_path}")

    def stop(self) -> None:
        with self._lock:
            proc, self._proc, self.mode = self._proc, None, "none"
            if proc is None or proc.poll() is not None:
                return
            os.killpg(proc.pid, signal.SIGTERM)
            try:
                proc.wait(60)
            except subprocess.TimeoutExpired:
                os.killpg(proc.pid, signal.SIGKILL)
                proc.wait()

    # --- adapters ---
    def _load_adapter_http(self, name: str, path: str) -> None:
        r = self._http.post("/v1/load_lora_adapter", json={"lora_name": name, "lora_path": path})
        if r.status_code != 200:
            raise VllmError(f"load_lora_adapter {name}: HTTP {r.status_code} {r.text[:300]}")

    def load_adapter(self, name: str, path: str) -> None:
        with self._lock:
            self.ensure_mode("student")
            if self.adapters.get(name) == path and self._is_loaded(name):
                return
            if name in self.adapters:
                self._unload_http(name)
            self._load_adapter_http(name, path)
            self.adapters[name] = path
            self._save_registry()

    def _is_loaded(self, name: str) -> bool:
        data = self._http.get("/v1/models").json().get("data", [])
        return any(m.get("id") == name for m in data)

    def _unload_http(self, name: str) -> None:
        self._http.post("/v1/unload_lora_adapter", json={"lora_name": name})

    def unload_adapter(self, name: str) -> None:
        with self._lock:
            if self.mode == "student" and self._alive():
                self._unload_http(name)
            self.adapters.pop(name, None)
            self._save_registry()

    # --- inference ---
    def chat(self, model: str, messages: list[dict], temperature: float, max_tokens: int, **extra) -> tuple[str, float]:
        """Returns (text, latency_ms). Thinking is always off so inference matches the training text."""
        body = {
            "model": self.model_for(model), "messages": messages, "temperature": temperature,
            "max_tokens": max_tokens, "chat_template_kwargs": {"enable_thinking": False}, **extra,
        }
        t0 = time.perf_counter()
        r = self._http.post("/v1/chat/completions", json=body)
        ms = (time.perf_counter() - t0) * 1000
        if r.status_code != 200:
            raise VllmError(f"vLLM chat HTTP {r.status_code}: {r.text[:300]}")
        return r.json()["choices"][0]["message"]["content"] or "", ms


def _tail(path: Path, n: int = 15) -> str:
    try:
        return "\n".join(path.read_text(errors="replace").splitlines()[-n:])
    except OSError:
        return ""
