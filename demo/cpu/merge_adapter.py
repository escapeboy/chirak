"""Runs on the GPU pod (/opt/venv-train): write a text-only HF checkpoint of the base model, or of the
base model with a Chirak LoRA adapter merged in, for conversion to GGUF (llama.cpp, CPU serving).

The adapter keys were renamed to model.language_model.layers.* for vLLM (train_script.py); the
text-only model that AutoModelForCausalLM loads expects model.layers.*, so the rename is undone first.

Usage: python merge_adapter.py <base_model> <out_dir> [adapter_dir]
"""
import json
import shutil
import sys
import tempfile
from pathlib import Path

import torch
from safetensors.torch import load_file, save_file
from transformers import AutoModelForCausalLM, AutoTokenizer

base, out = sys.argv[1], Path(sys.argv[2])
adapter = sys.argv[3] if len(sys.argv) > 3 else None

model = AutoModelForCausalLM.from_pretrained(base, torch_dtype=torch.bfloat16)
if adapter:
    from peft import PeftModel

    tmp = Path(tempfile.mkdtemp())
    for f in Path(adapter).iterdir():
        if f.is_file():
            shutil.copy(f, tmp / f.name)
    sd = load_file(tmp / "adapter_model.safetensors")
    new = "base_model.model.model.language_model.layers."
    sd = {k.replace(new, "base_model.model.model.layers.", 1): v for k, v in sd.items()}
    save_file(sd, tmp / "adapter_model.safetensors", metadata={"format": "pt"})
    target = json.loads((tmp / "adapter_config.json").read_text())["target_modules"]
    name = next(n for n, _ in model.named_parameters() if any(f".{t}.weight" in n for t in target))
    before = dict(model.named_parameters())[name].detach().clone()
    model = PeftModel.from_pretrained(model, tmp).merge_and_unload()
    # a key mismatch would load nothing and leave the weights equal to base
    delta = (dict(model.named_parameters())[name].detach() - before).abs().max().item()
    assert delta > 0, f"adapter not applied ({name} unchanged)"
    print(json.dumps({"checked": name, "max_abs_delta": delta}))
# The text-only model drops Qwen3.5's multi-token-prediction layer (mtp.*) but keeps it in the config:
# convert with `convert_hf_to_gguf.py --no-mtp`, or llama.cpp expects one block more than the file has.
model.save_pretrained(out, safe_serialization=True)
AutoTokenizer.from_pretrained(base).save_pretrained(out)
print(json.dumps({"out": str(out), "adapter": adapter, "architectures": model.config.architectures}))
