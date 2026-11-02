import json

import httpx
from fastapi.testclient import TestClient

from chirak_worker.cpu_predict import create_app


def test_predict_routes_to_the_llama_server_of_the_model(spec_dict):
    seen = []

    def llama(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.content)
        seen.append((request.url.host, body))
        answer = '{"category":"orders","urgent":true}' if request.url.host == "tuned" else "not json"
        return httpx.Response(200, json={"choices": [{"message": {"content": answer}}]})

    client = httpx.Client(transport=httpx.MockTransport(llama))
    app = TestClient(create_app({"base": "http://base:8080", "demo-iter-2": "http://tuned:8080"}, "tok", client))
    h = {"Authorization": "Bearer tok"}

    r = app.post("/predict", json={"model": "demo-iter-2", "task_spec": spec_dict, "input": "hi"}, headers=h).json()
    assert r["parsed"] == {"category": "orders", "urgent": True} and r["valid"]
    r = app.post("/predict", json={"model": "base", "task_spec": spec_dict, "input": "hi"}, headers=h).json()
    assert r["parsed"] is None and r["error"] == "invalid JSON"
    host, body = seen[0]
    assert host == "tuned" and body["temperature"] == 0 and body["chat_template_kwargs"] == {"enable_thinking": False}
    assert body["messages"][1] == {"role": "user", "content": "hi"}

    assert app.post("/predict", json={"model": "x", "task_spec": spec_dict, "input": "hi"}, headers=h).status_code == 404
    assert app.post("/predict", json={"model": "base", "task_spec": spec_dict, "input": "hi"}).status_code == 401
    assert app.get("/health").json()["models"] == ["base", "demo-iter-2"]
