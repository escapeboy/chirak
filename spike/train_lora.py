"""Step 4: bf16 LoRA SFT of the student model for N steps on a public dataset.

Task: AG News topic classification with strict JSON output, so the trained
adapter's answers are easy to tell apart from the base model's in step 5.
No quantization, no bitsandbytes: bf16 weights + adamw_torch only.

Usage: train_lora.py --model <hf id> --out <dir> [--backend unsloth|peft] [--max-steps 300]
"""
import argparse
import json
import os
import sys
import time

import torch
from datasets import load_dataset

LABELS = ["world", "sports", "business", "science_tech"]
SYSTEM = "Classify the news headline. Answer only with JSON: {\"topic\": one of " + json.dumps(LABELS) + "}."
TARGETS = ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"]

p = argparse.ArgumentParser()
p.add_argument("--model", required=True)
p.add_argument("--out", required=True)
p.add_argument("--backend", choices=["unsloth", "peft"], default="unsloth")
p.add_argument("--max-steps", type=int, default=300)
p.add_argument("--batch", type=int, default=8)
p.add_argument("--max-seq-len", type=int, default=1024)
args = p.parse_args()

if args.backend == "unsloth":
    # Unsloth must be imported before transformers/trl to patch them.
    from unsloth import FastLanguageModel

from trl import SFTConfig, SFTTrainer


def to_example(row):
    return {
        "prompt": [
            {"role": "system", "content": SYSTEM},
            {"role": "user", "content": row["text"]},
        ],
        "completion": [
            {"role": "assistant", "content": json.dumps({"topic": LABELS[row["label"]]})},
        ],
    }


n = args.max_steps * args.batch
ds = load_dataset("fancyzhx/ag_news", split=f"train[:{n}]").map(to_example, remove_columns=["text", "label"])

if args.backend == "unsloth":
    model, tokenizer = FastLanguageModel.from_pretrained(
        model_name=args.model,
        max_seq_length=args.max_seq_len,
        dtype=torch.bfloat16,
        load_in_4bit=False,
        load_in_8bit=False,
    )
    model = FastLanguageModel.get_peft_model(
        model, r=16, lora_alpha=16, lora_dropout=0.05, target_modules=TARGETS, bias="none",
    )
    peft_config = None
else:
    from peft import LoraConfig
    from transformers import AutoModelForCausalLM, AutoTokenizer

    tokenizer = AutoTokenizer.from_pretrained(args.model)
    model = AutoModelForCausalLM.from_pretrained(args.model, torch_dtype=torch.bfloat16, device_map={"": 0})
    peft_config = LoraConfig(r=16, lora_alpha=16, lora_dropout=0.05, target_modules=TARGETS, task_type="CAUSAL_LM")

cfg = SFTConfig(
    output_dir=os.path.join(args.out, "checkpoints"),
    max_steps=args.max_steps,
    per_device_train_batch_size=args.batch,
    learning_rate=2e-4,
    lr_scheduler_type="linear",
    warmup_steps=10,
    bf16=True,
    optim="adamw_torch",
    logging_steps=10,
    save_strategy="no",
    report_to="none",
    max_length=args.max_seq_len,
    completion_only_loss=True,
)
trainer = SFTTrainer(model=model, args=cfg, train_dataset=ds, processing_class=tokenizer, peft_config=peft_config)

torch.cuda.reset_peak_memory_stats()
start = time.time()
trainer.train()
wall = time.time() - start

adapter_dir = os.path.join(args.out, "adapter")
trainer.model.save_pretrained(adapter_dir)
tokenizer.save_pretrained(adapter_dir)

losses = [h["loss"] for h in trainer.state.log_history if "loss" in h]
first, last = sum(losses[:5]) / 5, sum(losses[-5:]) / 5
summary = {
    "backend": args.backend,
    "model": args.model,
    "steps": args.max_steps,
    "examples": n,
    "wall_s": round(wall, 1),
    "peak_vram_gb": round(torch.cuda.max_memory_allocated() / 1e9, 1),
    "loss_first5": round(first, 4),
    "loss_last5": round(last, 4),
    "loss_curve": losses,
    "adapter_dir": adapter_dir,
}
with open(os.path.join(args.out, "train_summary.json"), "w") as f:
    json.dump(summary, f, indent=2)
print(json.dumps({k: v for k, v in summary.items() if k != "loss_curve"}, indent=2))
ok = len(losses) >= 10 and last < first
print(("PASS" if ok else "FAIL") + f" loss {first:.3f} -> {last:.3f} in {wall:.0f}s")
sys.exit(0 if ok else 1)
