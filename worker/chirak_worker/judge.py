"""Label arbitration by a model that is not the generator: Claude gets the student's exact prompt.
The orchestrator's model rejects `temperature` ("deprecated for this model", checked 2026-10-13), so the
request uses the model's default sampling; hard_slice.judge asks twice and treats a disagreement as no answer."""
import os
import re
import threading
import time

import httpx

from .parsing import parse_student_answer
from .prompts import student_messages
from .schemas import TaskSpec

API = "https://api.anthropic.com/v1/messages"
DEFAULT_MODEL = "claude-sonnet-5-5"  # the orchestrator's model (app/config/chirak.php)


class ClaudeJudge:
    def __init__(self, api_key: str | None = None, model: str | None = None, client: httpx.Client | None = None):
        self.api_key = api_key or os.environ["ANTHROPIC_API_KEY"]
        self.model = model or os.environ.get("ORCHESTRATOR_MODEL") or DEFAULT_MODEL
        self.client = client or httpx.Client(timeout=60)
        self.usage = {"requests": 0, "input_tokens": 0, "output_tokens": 0}
        self._lock = threading.Lock()

    def label(self, spec: TaskSpec, text: str) -> tuple[dict | None, str]:
        """(parsed label or None, raw answer). Parsed with the student's strict parser; a code fence around
        the JSON is removed first, since the judge is not the model under test."""
        msgs = student_messages(spec, text)
        # The model thinks adaptively by default; with a small max_tokens the thinking used up the budget and
        # the answer came back empty or cut off (8 of 240 answers on 2026-10-13). Leave room for it.
        body = {"model": self.model, "max_tokens": 4096, "system": msgs[0]["content"], "messages": msgs[1:]}
        headers = {"x-api-key": self.api_key, "anthropic-version": "2023-06-01"}
        for attempt in range(6):
            r = self.client.post(API, json=body, headers=headers)
            if r.status_code in (429, 500, 502, 503, 529) and attempt < 5:
                time.sleep(2 ** attempt)
                continue
            r.raise_for_status()
            break
        data = r.json()
        with self._lock:
            self.usage["requests"] += 1
            self.usage["input_tokens"] += data["usage"]["input_tokens"]
            self.usage["output_tokens"] += data["usage"]["output_tokens"]
        raw = "".join(b.get("text", "") for b in data["content"] if b["type"] == "text")
        if data.get("stop_reason") == "max_tokens":
            return None, raw  # cut off: no answer
        unfenced = re.sub(r"^```(?:json)?\s*|\s*```$", "", raw.strip())
        return parse_student_answer(unfenced, spec)[0], raw
