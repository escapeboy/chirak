"""Send N concurrent chat requests to a vLLM OpenAI endpoint; print output tokens/s.

Stdlib only so it runs on the droplet host without a venv.
Usage: bench_vllm.py <model> [concurrency] [max_tokens]
"""
import json
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor

URL = "http://127.0.0.1:8000/v1/chat/completions"
model = sys.argv[1]
concurrency = int(sys.argv[2]) if len(sys.argv) > 2 else 32
max_tokens = int(sys.argv[3]) if len(sys.argv) > 3 else 256

PROMPTS = [
    "Write a short customer support reply to someone whose parcel is late.",
    "Напиши кратък отговор на клиент, който иска да върне продукт.",
    "List five common reasons an online payment gets declined.",
    "Обясни накратко разликата между доставка до офис и до адрес.",
]


def one(i):
    body = json.dumps({
        "model": model,
        "messages": [{"role": "user", "content": PROMPTS[i % len(PROMPTS)]}],
        "max_tokens": max_tokens,
        "temperature": 0.7,
        "chat_template_kwargs": {"enable_thinking": False},
    }).encode()
    req = urllib.request.Request(URL, body, {"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=600) as r:
        data = json.load(r)
    return data["usage"]["completion_tokens"], data["choices"][0]["message"]["content"]


start = time.time()
with ThreadPoolExecutor(concurrency) as ex:
    results = list(ex.map(one, range(concurrency)))
elapsed = time.time() - start
tokens = sum(t for t, _ in results)
print(json.dumps({
    "model": model,
    "concurrency": concurrency,
    "completion_tokens": tokens,
    "seconds": round(elapsed, 1),
    "tokens_per_s": round(tokens / elapsed, 1),
    "sample_bg": results[1][1][:300],
}, ensure_ascii=False, indent=2))
