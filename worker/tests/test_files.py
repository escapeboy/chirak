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
