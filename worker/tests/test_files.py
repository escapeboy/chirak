import hashlib

from fastapi.testclient import TestClient

from chirak_worker.app import create_app

BODY = b'{"id":"own-0001","input":"hello","output":{"category":"orders"},"source":"own","label_source":"judge"}\n'


def test_put_file_writes_under_the_run_dir(client, settings):
    r = client.put("/runs/demo-run/files/eval-own.jsonl", content=BODY)
    assert r.status_code == 200
    assert r.json() == {"path": str(settings.runs_dir / "demo-run" / "eval-own.jsonl"),
                        "sha256": hashlib.sha256(BODY).hexdigest(), "bytes": len(BODY)}
    assert (settings.runs_dir / "demo-run" / "eval-own.jsonl").read_bytes() == BODY
    assert not list((settings.runs_dir / "demo-run").glob("*.tmp"))


def test_put_file_is_write_once(client, settings):
    assert client.put("/runs/demo-run/files/eval-own.jsonl", content=BODY).status_code == 200
    assert client.put("/runs/demo-run/files/eval-own.jsonl", content=BODY).status_code == 200  # same content
    r = client.put("/runs/demo-run/files/eval-own.jsonl", content=BODY + BODY)
    assert r.status_code == 409 and "different content" in r.json()["detail"]
    assert (settings.runs_dir / "demo-run" / "eval-own.jsonl").read_bytes() == BODY


def test_put_file_refuses_bad_names_and_run_ids(client, settings):
    for name in ("eval.txt", "Eval.jsonl", "a%20b.jsonl", ".jsonl", ".hidden.jsonl", "a.jsonl.tmp", "%2e%2e%2fx.jsonl"):
        assert client.put(f"/runs/demo-run/files/{name}", content=BODY).status_code in (400, 404), name
    assert client.put("/runs/Bad_Run/files/x.jsonl", content=BODY).status_code == 400
    assert not (settings.runs_dir / "demo-run").exists()


def test_put_file_needs_the_token(settings, services):
    anon = TestClient(create_app(settings, services))
    r = anon.put("/runs/demo-run/files/eval-own.jsonl", content=BODY)
    assert r.status_code == 401 and r.json() == {"detail": "unauthorized"}


def _own_run(settings):
    rdir = settings.runs_dir / "demo-run"
    (rdir / "evals" / "base.eval-own").mkdir(parents=True)
    (rdir / "evals" / "demo-iter-1.eval-own").mkdir(parents=True)
    (rdir / "evals" / "base").mkdir(parents=True)
    (rdir / "evals" / "base.eval-hard").mkdir(parents=True)
    for f in ("eval-own.jsonl", "eval.jsonl", "evals/base.eval-own/metrics.json", "evals/demo-iter-1.eval-own/predictions.jsonl",
              "evals/base/metrics.json", "evals/base.eval-hard/metrics.json"):
        (rdir / f).write_text("x")
    return rdir


def test_delete_own_removes_the_file_and_the_eval_own_dirs_only(client, settings):
    rdir = _own_run(settings)
    r = client.delete("/runs/demo-run/own")
    assert r.status_code == 200
    assert sorted(r.json()["deleted"]) == ["eval-own.jsonl", "evals/base.eval-own", "evals/demo-iter-1.eval-own"]
    assert not (rdir / "eval-own.jsonl").exists()
    assert not list((rdir / "evals").glob("*.eval-own"))
    assert (rdir / "eval.jsonl").exists()
    assert (rdir / "evals" / "base" / "metrics.json").exists()
    assert (rdir / "evals" / "base.eval-hard" / "metrics.json").exists()


def test_delete_own_is_idempotent(client, settings):
    assert client.delete("/runs/demo-run/own").json() == {"deleted": []}
    _own_run(settings)
    assert client.delete("/runs/demo-run/own").status_code == 200
    r = client.delete("/runs/demo-run/own")
    assert r.status_code == 200 and r.json() == {"deleted": []}


def test_delete_own_does_not_follow_symlinks_out_of_the_run(client, settings, tmp_path):
    outside = tmp_path / "outside"
    outside.mkdir()
    (outside / "keep.txt").write_text("x")
    rdir = settings.runs_dir / "demo-run"
    (rdir / "evals").mkdir(parents=True)
    (rdir / "evals" / "evil.eval-own").symlink_to(outside, target_is_directory=True)
    assert client.delete("/runs/demo-run/own").json() == {"deleted": []}
    assert (outside / "keep.txt").exists()


def test_delete_own_refuses_bad_run_ids(client):
    assert client.delete("/runs/Bad_Run/own").status_code == 400


def test_delete_own_needs_the_token(settings, services):
    anon = TestClient(create_app(settings, services))
    r = anon.delete("/runs/demo-run/own")
    assert r.status_code == 401 and r.json() == {"detail": "unauthorized"}


def test_delete_own_removes_train_real_only_when_asked(client, settings):
    rdir = _own_run(settings)
    (rdir / "train-real.jsonl").write_text("x")
    assert "train-real.jsonl" not in client.delete("/runs/demo-run/own").json()["deleted"]
    assert (rdir / "train-real.jsonl").exists()
    assert client.delete("/runs/demo-run/own?train_real=1").json() == {"deleted": ["train-real.jsonl"]}
    assert not (rdir / "train-real.jsonl").exists()
