"""Step 5 check: same prompts to base and adapter; PASS if answers differ.

Stdlib only. Usage: lora_diff.py <base model id> <adapter name>
"""
import json
import os
import sys
import urllib.request

URL = f"http://127.0.0.1:{os.environ.get('VLLM_PORT', '8000')}/v1/chat/completions"
base, adapter = sys.argv[1], sys.argv[2]
SYSTEM = 'Classify the news headline. Answer only with JSON: {"topic": one of ["world", "sports", "business", "science_tech"]}.'
HEADLINES = [
    "Stocks slide as oil prices hit a new record high",
    "Local team clinches championship in overtime thriller",
    "New chip promises tenfold speedup for AI workloads",
    "Leaders meet in Geneva to discuss ceasefire terms",
]


def ask(model, text):
    body = json.dumps({
        "model": model,
        "messages": [{"role": "system", "content": SYSTEM}, {"role": "user", "content": text}],
        "max_tokens": 64,
        "temperature": 0,
        "chat_template_kwargs": {"enable_thinking": False},
    }).encode()
    req = urllib.request.Request(URL, body, {"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.load(r)["choices"][0]["message"]["content"].strip()


rows = [{"input": h, "base": ask(base, h), "adapter": ask(adapter, h)} for h in HEADLINES]
print(json.dumps(rows, ensure_ascii=False, indent=2))
differ = sum(r["base"] != r["adapter"] for r in rows)
print(("PASS" if differ else "FAIL") + f" {differ}/{len(rows)} answers differ between base and adapter")
sys.exit(0 if differ else 1)
