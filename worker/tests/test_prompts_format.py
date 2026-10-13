import json

from chirak_worker.chat_format import assert_alignment, completion_text, end_of_turn, render_prompt
from chirak_worker.prompts import (build_eval_generation_prompt, build_student_system_prompt,
                                   build_train_generation_prompt, compact_json, student_messages)
from chirak_worker.schemas import TaskSpec


class QwenLikeTokenizer:
    """Mimics the Qwen3.5 chat template observed on the real tokenizer (thinking off)."""

    def apply_chat_template(self, messages, add_generation_prompt=False, enable_thinking=True, tokenize=False):
        assert tokenize is False and enable_thinking is False
        out = ""
        for i, m in enumerate(messages):
            body = m["content"]
            if m["role"] == "assistant":
                body = "<think>\n\n</think>\n\n" + body
            out += f"<|im_start|>{m['role']}\n{body}<|im_end|>\n"
        if add_generation_prompt:
            out += "<|im_start|>assistant\n<think>\n\n</think>\n\n"
        return out


def test_student_prompt_is_deterministic_and_complete(spec_dict):
    spec = TaskSpec(**spec_dict)
    p = build_student_system_prompt(spec)
    assert p == build_student_system_prompt(TaskSpec(**spec_dict))
    assert '"category": one of ["orders", "returns", "payments"]' in p
    assert '"urgent": true or false' in p
    assert spec_dict["guidelines"] in p
    assert student_messages(spec, "hi") == [{"role": "system", "content": p}, {"role": "user", "content": "hi"}]


def test_train_prompt_has_no_seed_or_eval_content(spec_dict):
    spec = TaskSpec(**spec_dict)
    targets = [{"lang": "bg", "output": {"category": "orders", "urgent": False}, "slice": "orders", "guidance": ""}]
    seeds = [{"input": "SEED-SECRET-TEXT", "output": {"category": "orders", "urgent": False}}]
    train = json.dumps(build_train_generation_prompt(spec, targets, "returns mixed with payments"))
    eval_ = json.dumps(build_eval_generation_prompt(spec, targets, seeds))
    assert "SEED-SECRET-TEXT" not in train and "returns mixed with payments" in train
    assert "SEED-SECRET-TEXT" in eval_
    assert train != eval_ and "typos" in eval_ and "typos" not in train


def test_alignment_with_template(spec_dict):
    spec, tok = TaskSpec(**spec_dict), QwenLikeTokenizer()
    out = {"category": "orders", "urgent": False}
    assert end_of_turn(tok) == "<|im_end|>"
    assert completion_text(out, "<|im_end|>") == '{"category":"orders","urgent":false}<|im_end|>'
    assert render_prompt(tok, spec, "hi").endswith("<|im_start|>assistant\n<think>\n\n</think>\n\n")
    assert_alignment(tok, spec, "hi", out)


def test_alignment_detects_template_mismatch(spec_dict):
    import pytest

    class Broken(QwenLikeTokenizer):
        def apply_chat_template(self, messages, add_generation_prompt=False, **kw):
            s = super().apply_chat_template(messages, add_generation_prompt, **kw)
            return s if add_generation_prompt else s.replace("<think>\n\n</think>\n\n", "")

    with pytest.raises(AssertionError):
        assert_alignment(Broken(), TaskSpec(**spec_dict), "hi", {"category": "orders", "urgent": False})
    assert compact_json({"a": "б"}) == '{"a":"б"}'
