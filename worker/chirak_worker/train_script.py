"""LoRA SFT subprocess (transformers + peft + trl). Runs in the training venv, never imported by the API process.

Usage: python -m chirak_worker.train_script <config.json>
Progress is reported as `PROGRESS {"step": s, "total": t}` lines on stdout.
"""
import json
import math
import os
import sys
import time

from .chat_format import assert_alignment, completion_text, end_of_turn, render_prompt
from .prompts import compact_json
from .schemas import TaskSpec


def load_examples(files: list[str]) -> list[dict]:
    out = []
    for f in files:
        with open(f, encoding="utf-8") as fh:
            out += [json.loads(line) for line in fh if line.strip()]
    return out


def main(cfg_path: str) -> None:
    import torch
    from datasets import Dataset
    from peft import LoraConfig
    from safetensors.torch import load_file, save_file
    from transformers import AutoModelForCausalLM, AutoTokenizer, TrainerCallback
    from trl import SFTConfig, SFTTrainer

    cfg = json.load(open(cfg_path))
    spec = TaskSpec(**cfg["task_spec"])
    out_dir = cfg["out_dir"]
    tokenizer = AutoTokenizer.from_pretrained(cfg["model"])

    end = end_of_turn(tokenizer)
    rows, too_long = [], 0
    for ex in load_examples(cfg["train_files"]):
        prompt = render_prompt(tokenizer, spec, ex["input"])
        completion = completion_text(ex["output"], end)
        if len(tokenizer(prompt + completion, add_special_tokens=False)["input_ids"]) > cfg["max_seq_len"]:
            too_long += 1  # truncation would cut the answer, so such examples are skipped
            continue
        rows.append({"prompt": prompt, "completion": completion})
    if not rows:
        raise SystemExit("no trainable examples")
    first = load_examples(cfg["train_files"])[0]
    assert_alignment(tokenizer, spec, first["input"], first["output"])
    print("EXAMPLE", json.dumps(rows[0], ensure_ascii=False), flush=True)

    model = AutoModelForCausalLM.from_pretrained(cfg["model"], torch_dtype=torch.bfloat16, device_map={"": 0})
    lora = cfg["lora"]
    peft_config = LoraConfig(r=lora["r"], lora_alpha=lora["alpha"], lora_dropout=lora["dropout"],
                             target_modules=lora["target_modules"], task_type="CAUSAL_LM")

    per_device, accum = 8, 2
    total_steps = math.ceil(len(rows) / (per_device * accum)) * cfg["epochs"]
    args = SFTConfig(
        output_dir=os.path.join(out_dir, "checkpoints"),
        num_train_epochs=cfg["epochs"],
        per_device_train_batch_size=per_device,
        gradient_accumulation_steps=accum,
        learning_rate=cfg["lr"],
        lr_scheduler_type="linear",
        warmup_steps=max(1, round(0.05 * total_steps)),
        bf16=True,
        optim="adamw_torch",
        logging_steps=max(1, min(10, total_steps // 10)),
        save_strategy="no",
        report_to="none",
        max_length=cfg["max_seq_len"],
        completion_only_loss=True,
        eos_token=end,
    )

    class Progress(TrainerCallback):
        def on_log(self, a, state, control, **kw):
            print("PROGRESS", json.dumps({"step": state.global_step, "total": state.max_steps}), flush=True)

    trainer = SFTTrainer(model=model, args=args, train_dataset=Dataset.from_list(rows),
                         processing_class=tokenizer, peft_config=peft_config, callbacks=[Progress()])
    torch.cuda.reset_peak_memory_stats()
    start = time.time()
    trainer.train()
    wall = time.time() - start

    adapter_dir = os.path.join(out_dir, "adapter")
    trainer.model.save_pretrained(adapter_dir)
    tokenizer.save_pretrained(adapter_dir)

    # Qwen3.5 is a vision-language checkpoint. AutoModelForCausalLM loads only its text model, so peft
    # saves keys as model.layers.*; vLLM serves the full VL model and silently ignores LoRA weights that
    # are not under model.language_model.layers.* (spike step 5: logprobs identical to base before the rename).
    path = os.path.join(adapter_dir, "adapter_model.safetensors")
    sd = load_file(path)
    old = "base_model.model.model.layers."
    if any(k.startswith(old) for k in sd):
        sd = {k.replace(old, "base_model.model.model.language_model.layers.", 1): v for k, v in sd.items()}
        save_file(sd, path, metadata={"format": "pt"})

    curve = [{"step": h["step"], "loss": h["loss"]} for h in trainer.state.log_history if "loss" in h]
    summary = {"n_examples": len(rows), "skipped_too_long": too_long, "epochs": cfg["epochs"],
               "wall_s": round(wall, 1), "peak_vram_gb": round(torch.cuda.max_memory_allocated() / 1e9, 1),
               "loss_curve": curve}
    with open(os.path.join(out_dir, "train_summary.json"), "w") as f:
        json.dump(summary, f)
    print("DONE", compact_json({k: v for k, v in summary.items() if k != "loss_curve"}), flush=True)


if __name__ == "__main__":
    main(sys.argv[1])
