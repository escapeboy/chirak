import hashlib
import json
import re
import time
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient

from chirak_worker.app import Services, create_app
from chirak_worker.config import Settings
from chirak_worker.jobs import JobRunner, JobStore

import os

FIXTURES = Path(__file__).parent / "fixtures"
TOKEN = "test-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


@pytest.fixture
def spec_dict():
    return json.loads((FIXTURES / "task_spec.json").read_text())


class FakeEmbedder:
    """Hashed bag of words: texts sharing words get a high cosine, no model needed."""

    def encode(self, texts):
        out = np.zeros((len(texts), 256), dtype=np.float32)
        for i, t in enumerate(texts):
            for w in re.findall(r"\w+", t.lower()):
                out[i, int(hashlib.md5(w.encode()).hexdigest(), 16) % 256] += 1
        norm = np.linalg.norm(out, axis=1, keepdims=True)
        return out / np.where(norm == 0, 1, norm)


class FakeLlm:
    """Stands in for vLLM. Generator prompts get one unique text per numbered item; student prompts
    get the label whose name appears in the input (or whatever `student_reply` returns)."""

    def __init__(self):
        self.mode = "none"
        self.adapters = {}
        self.prompts = []
        self.student_reply = None
        self.counter = 0

    @property
    def model_name(self):
        return {"generator": "gen-model", "student": "stu-model"}.get(self.mode, "")

    def model_for(self, model):
        return self.model_name if model == "base" else model

    def ensure_mode(self, mode):
        self.mode = mode

    def stop(self):
        self.mode = "none"

    def load_adapter(self, name, path):
        self.mode = "student"
        self.adapters[name] = path

    def unload_adapter(self, name):
        self.adapters.pop(name, None)

    def chat(self, model, messages, temperature, max_tokens, **extra):
        self.prompts.append((self.mode, temperature, messages))
        user = messages[-1]["content"]
        if self.mode == "generator":
            items = re.findall(r"^(\d+)\. language=(\w+); label: (.*)$", user, re.M)
            out = []
            for n, lang, label in items:
                self.counter += 1
                out.append({"n": int(n), "text": f"unique sample {self.counter} {lang} {label} zq{self.counter}x"})
            if items or "language=" in user:
                return "```json\n" + json.dumps(out) + "\n```", 5.0
            # blind re-label of a generated text: the fake texts carry their label, so echo it back
            pairs = re.findall(r'(\w+)=("[^"]*"|true|false)', user)
            return json.dumps({k: json.loads(v) for k, v in pairs}, separators=(",", ":")), 5.0
        if self.student_reply is not None:
            return self.student_reply(model, user), 12.0
        for cat in ("orders", "returns", "payments"):
            if cat in user:
                return json.dumps({"category": cat, "urgent": False}, separators=(",", ":")), 12.0
        return "no idea", 12.0


@pytest.fixture
def settings(tmp_path):
    return Settings(data_dir=tmp_path, bearer_token=TOKEN)


@pytest.fixture
def llm():
    return FakeLlm()


@pytest.fixture
def services(settings, llm):
    runner = JobRunner(JobStore(settings.jobs_dir))

    def fake_train(req, settings_, llm_, ctx, files):
        llm_.stop()
        out = settings_.runs_dir / req.run_id / f"iter-{req.iteration}"
        (out / "adapter").mkdir(parents=True)
        (out / "adapter" / "adapter_config.json").write_text("{}")
        ctx.progress("training", 1, 2)
        return {"adapter_path": str(out / "adapter"), "adapter_archive": str(out / "adapter.tar.gz"),
                "loss_curve": [{"step": 1, "loss": 1.0}], "n_examples": len(files), "epochs": req.epochs,
                "wall_s": 1.0, "peak_vram_gb": 1.0}

    return Services(llm=llm, embedder=FakeEmbedder(), runner=runner,
                    gpu_info=lambda: {"visible": False, "name": None, "vram_used_gb": None, "vram_total_gb": None},
                    train=fake_train)


@pytest.fixture
def client(settings, services):
    return TestClient(create_app(settings, services), headers=AUTH)


def wait_job(client, job_id, timeout=15):
    deadline = time.time() + timeout
    while time.time() < deadline:
        job = client.get(f"/jobs/{job_id}").json()
        if job["status"] in ("succeeded", "failed"):
            return job
        time.sleep(0.02)
    raise TimeoutError(job_id)


def pytest_collection_modifyitems(config, items):
    if os.environ.get("RUN_GPU_SMOKE") == "1":
        return
    skip = pytest.mark.skip(reason="GPU smoke test: set RUN_GPU_SMOKE=1 with a running worker")
    for item in items:
        if "gpu" in item.keywords:
            item.add_marker(skip)
