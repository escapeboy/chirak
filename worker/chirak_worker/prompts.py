"""Prompt builders. The student prompt is one deterministic function used for train, eval and predict."""
import json

from .schemas import TaskSpec

LANG_NAMES = {"bg": "Bulgarian", "en": "English"}


def compact_json(obj: dict) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


def _field_line(f) -> str:
    if f.type == "enum":
        return f'- "{f.name}": one of {json.dumps(f.values, ensure_ascii=False)}'
    return f'- "{f.name}": true or false'


def build_student_system_prompt(spec: TaskSpec) -> str:
    parts = [
        spec.description.strip(),
        "Answer with exactly one compact JSON object and nothing else. It must have exactly these keys, in this order:",
        "\n".join(_field_line(f) for f in spec.fields),
    ]
    if spec.guidelines.strip():
        parts.append("Labelling rules:\n" + spec.guidelines.strip())
    return "\n\n".join(parts)


def student_messages(spec: TaskSpec, text: str) -> list[dict]:
    return [
        {"role": "system", "content": build_student_system_prompt(spec)},
        {"role": "user", "content": text},
    ]


def lang_name(code: str) -> str:
    return LANG_NAMES.get(code, code)


def describe_target(spec: TaskSpec, output: dict) -> str:
    return ", ".join(f"{k}={json.dumps(v, ensure_ascii=False)}" for k, v in output.items())


def _task_block(spec: TaskSpec) -> str:
    fields = "\n".join(_field_line(f) for f in spec.fields)
    rules = f"\nLabelling rules written by the task owner:\n{spec.guidelines.strip()}" if spec.guidelines.strip() else ""
    return f"Task: {spec.description.strip()}\nEach input is labelled with these fields:\n{fields}{rules}"


def _targets_block(spec: TaskSpec, targets: list[dict]) -> str:
    lines = []
    for i, t in enumerate(targets, 1):
        lines.append(f'{i}. language={lang_name(t["lang"])}; label: {describe_target(spec, t["output"])}')
    return "\n".join(lines)


_REPLY_FORMAT = (
    'Reply with one JSON array and nothing else. It has one object per numbered item, '
    'shaped {"n": <item number>, "text": "<the input text>"}.'
)


def build_eval_generation_prompt(spec: TaskSpec, targets: list[dict], seeds: list[dict], guidance: str = "") -> list[dict]:
    """Eval items: realistic, messy, edge cases. Seeds are style references only."""
    shots = ""
    if seeds:
        shots = "\n\nReal examples, for style only (never copy them):\n" + "\n".join(
            f'- "{s["input"]}" -> {compact_json(s["output"])}' for s in seeds[:6]
        )
    extra = f"\nExtra focus for this batch: {guidance.strip()}" if guidance.strip() else ""
    user = (
        f"{_task_block(spec)}{shots}\n\n"
        "Write realistic inputs the way real people write them: typos, slang, missing context, mixed topics, "
        "very short or rambling messages, borderline cases that are easy to mislabel. "
        f"Each text must clearly deserve its label.{extra}\n\n"
        f"Write one input for each item:\n{_targets_block(spec, targets)}\n\n{_REPLY_FORMAT}"
    )
    return [
        {"role": "system", "content": "You write realistic test data for text classification."},
        {"role": "user", "content": user},
    ]


def build_train_generation_prompt(spec: TaskSpec, targets: list[dict], guidance: str = "") -> list[dict]:
    """Train items. Takes no seeds and no eval content by construction: there is no parameter for them."""
    extra = f"\nScenario to emphasise: {guidance.strip()}" if guidance.strip() else ""
    user = (
        f"{_task_block(spec)}\n\n"
        "You are producing diverse training examples. Vary length, tone, vocabulary, formality and detail; "
        "include concrete details (names, numbers, products) and some ambiguous wording, "
        f"but keep the stated label correct.{extra}\n\n"
        f"Items to write, one input each:\n{_targets_block(spec, targets)}\n\n{_REPLY_FORMAT}"
    )
    return [
        {"role": "system", "content": "You create varied, natural-sounding labelled training data."},
        {"role": "user", "content": user},
    ]
