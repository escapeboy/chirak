"""Training text, built to match what vLLM renders at inference time (thinking off)."""
from .prompts import compact_json, student_messages
from .schemas import TaskSpec


def render_prompt(tokenizer, spec: TaskSpec, text: str) -> str:
    # Same call vLLM makes for chat requests carrying chat_template_kwargs {"enable_thinking": false}.
    return tokenizer.apply_chat_template(
        student_messages(spec, text), add_generation_prompt=True, enable_thinking=False, tokenize=False
    )


def end_of_turn(tokenizer) -> str:
    """The token the template puts after an assistant answer (<|im_end|> for Qwen3.5)."""
    probe = tokenizer.apply_chat_template(
        [{"role": "user", "content": "u"}, {"role": "assistant", "content": "XYZ"}],
        enable_thinking=False, tokenize=False,
    )
    tail = probe.split("XYZ", 1)[1].strip()
    if not tail:
        raise RuntimeError("cannot determine the end-of-turn token from the chat template")
    return tail


def completion_text(output: dict, end: str) -> str:
    return compact_json(output) + end


def assert_alignment(tokenizer, spec: TaskSpec, text: str, output: dict) -> None:
    """The full conversation as the template renders it must be exactly prompt + completion (plus trailing
    newline), otherwise training sees text the model is never asked to continue at inference."""
    end = end_of_turn(tokenizer)
    full = tokenizer.apply_chat_template(
        student_messages(spec, text) + [{"role": "assistant", "content": compact_json(output)}],
        enable_thinking=False, tokenize=False,
    )
    expect = render_prompt(tokenizer, spec, text) + completion_text(output, end)
    if full.rstrip() != expect.rstrip():
        raise AssertionError(f"training text differs from the chat template:\n{full!r}\n{expect!r}")
