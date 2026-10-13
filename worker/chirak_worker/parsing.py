"""Strict parsing of student answers and tolerant parsing of generator output."""
import json
import re

from .schemas import TaskSpec


def parse_student_answer(raw: str, spec: TaskSpec) -> tuple[dict | None, str | None]:
    """Strict: any deviation from the spec returns (None, reason)."""
    try:
        obj = json.loads(raw.strip())
    except (ValueError, TypeError):
        return None, "invalid JSON"
    if not isinstance(obj, dict):
        return None, "not a JSON object"
    names = [f.name for f in spec.fields]
    missing = [n for n in names if n not in obj]
    if missing:
        return None, f"missing field: {missing[0]}"
    extra = [k for k in obj if k not in names]
    if extra:
        return None, f"unexpected field: {extra[0]}"
    for f in spec.fields:
        v = obj[f.name]
        if f.type == "bool":
            if not isinstance(v, bool):
                return None, f"field {f.name} is not a boolean"
        elif not isinstance(v, str) or v not in (f.values or []):
            return None, f"value out of spec for {f.name}: {v!r}"
    return {n: obj[n] for n in names}, None


def parse_generated_items(raw: str, n_expected: int) -> dict[int, str]:
    """Return {item number: text}. Scans for {"n":..,"text":..} objects, so thinking blocks, code fences,
    prose around the array and a truncated array all still yield every complete item."""
    s = re.sub(r"<think>.*?</think>", "", raw or "", flags=re.S)
    dec = json.JSONDecoder()
    out: dict[int, str] = {}
    pos = s.find("{")
    while pos != -1:
        try:
            obj, end = dec.raw_decode(s, pos)
        except ValueError:
            pos = s.find("{", pos + 1)
            continue
        n, text = obj.get("n"), obj.get("text")
        if isinstance(n, str) and n.strip().isdigit():
            n = int(n)
        if isinstance(n, int) and not isinstance(n, bool) and isinstance(text, str):
            if 1 <= n <= n_expected and text.strip():
                out.setdefault(n, text.strip())
            pos = s.find("{", end)
        else:
            pos = s.find("{", pos + 1)  # a wrapper object: look inside it
    return out
