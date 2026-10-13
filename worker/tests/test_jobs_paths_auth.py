import json
import time

from fastapi.testclient import TestClient

from chirak_worker.app import create_app
from chirak_worker.jobs import RESTART_ERROR, JobRunner, JobStore
from chirak_worker.train import pack_adapter
from conftest import AUTH, TOKEN


def test_jobs_run_one_at_a_time_in_order(tmp_path):
    runner = JobRunner(JobStore(tmp_path))
    log = []

    def make(name, delay):
        def fn(ctx):
            log.append(("start", name))
            time.sleep(delay)
            log.append(("end", name))
            return {"name": name}
        return fn

    a = runner.submit("generate", "run-aaa", make("a", 0.1))
    b = runner.submit("train", "run-aaa", make("b", 0))
    deadline = time.time() + 5
    while runner.store.load(b)["status"] != "succeeded" and time.time() < deadline:
        time.sleep(0.01)
    assert log == [("start", "a"), ("end", "a"), ("start", "b"), ("end", "b")]
    assert runner.store.load(a)["result"] == {"name": "a"}


def test_failed_job_records_error_and_runner_survives(tmp_path):
    runner = JobRunner(JobStore(tmp_path))
    bad = runner.submit("train", "run-aaa", lambda ctx: 1 / 0)
    good = runner.submit("train", "run-aaa", lambda ctx: {"ok": True})
    deadline = time.time() + 5
    while runner.store.load(good)["status"] != "succeeded" and time.time() < deadline:
        time.sleep(0.01)
    j = runner.store.load(bad)
    assert j["status"] == "failed" and "ZeroDivisionError" in j["error"] and j["finished_at"]


def test_restart_marks_running_and_queued_jobs_failed(tmp_path):
    store = JobStore(tmp_path)
    for jid, status in (("job_1", "running"), ("job_2", "queued"), ("job_3", "succeeded")):
        store.save({"id": jid, "status": status, "error": None, "finished_at": None, "result": {"x": 1} if status == "succeeded" else None})
    JobRunner(JobStore(tmp_path))
    assert store.load("job_1")["status"] == "failed" and store.load("job_1")["error"] == RESTART_ERROR
    assert store.load("job_2")["error"] == RESTART_ERROR
    assert store.load("job_3")["status"] == "succeeded"
    assert store.load("../etc/passwd") is None


def test_auth_required_everywhere_but_health(client, settings, services):
    anon = TestClient(create_app(settings, services))
    assert anon.get("/health").json() == {"status": "ok"}
    for method, url in (("get", "/jobs/job_x"), ("post", "/generate"), ("post", "/serve"), ("get", "/artifacts/abc/x")):
        r = getattr(anon, method)(url)
        assert r.status_code == 401 and r.json() == {"detail": "unauthorized"}, url
    bad = TestClient(create_app(settings, services), headers={"Authorization": "Bearer nope"})
    assert bad.get("/jobs/job_x").status_code == 401
    assert client.get("/jobs/job_x").status_code == 404


def test_empty_server_token_never_authenticates(settings, services):
    from dataclasses import replace

    c = TestClient(create_app(replace(settings, bearer_token=""), services), headers={"Authorization": "Bearer "})
    assert c.get("/jobs/job_x").status_code == 401


def test_artifacts_stream_and_reject_traversal(client, settings):
    d = settings.runs_dir / "demo-1"
    d.mkdir(parents=True)
    (d / "eval.jsonl").write_text("hello")
    (d / "evals" / "iter-1").mkdir(parents=True)
    (d / "evals" / "iter-1" / "metrics.json").write_text('{"n":1}')
    (settings.runs_dir / "secret.txt").write_text("nope")
    assert client.get("/artifacts/demo-1/eval.jsonl").text == "hello"
    assert client.get("/artifacts/demo-1/evals/iter-1/metrics.json").json() == {"n": 1}
    for bad in ("%2e%2e/secret.txt", "..%2fsecret.txt", "evals/%2e%2e/%2e%2e/secret.txt", "%2fetc%2fpasswd"):
        assert client.get(f"/artifacts/demo-1/{bad}").status_code == 400, bad
    assert client.get("/artifacts/demo-1/missing.txt").status_code == 404
    assert client.get("/artifacts/demo-1/evals").status_code == 404
    assert client.get("/artifacts/BAD_ID/eval.jsonl").status_code == 400
    (d / "link").symlink_to(settings.runs_dir / "secret.txt")
    assert client.get("/artifacts/demo-1/link").status_code == 400


def test_pack_adapter_roundtrip(tmp_path):
    import tarfile

    ad = tmp_path / "adapter"
    ad.mkdir()
    (ad / "adapter_config.json").write_text("{}")
    pack_adapter(ad, tmp_path / "adapter.tar.gz")
    with tarfile.open(tmp_path / "adapter.tar.gz") as t:
        assert t.getnames() == ["adapter", "adapter/adapter_config.json"]
