# Spike setup — AMD Developer Cloud, 1× MI300X

Status: **scripts written, not yet run on hardware.** Every value below marked
`[planned]` comes from documentation read on 2026-10-12 and becomes `[verified]`
only after the matching spike step prints PASS on the droplet. `spike/out/results.tsv`
is the evidence.

## Pins

| Component | Value | Status | Source |
|---|---|---|---|
| Droplet size | `gpu-mi300x1-192gb` ($2.59/h, billed until destroyed) | [planned] | docs.digitalocean.com/products/amd/how-to/create |
| Droplet image | ROCm / "vLLM Quick Start" image, slug TBD | [unknown] | `doctl compute image list --public` |
| ROCm (host) | TBD | [unknown] | step 1 |
| vLLM image | `vllm/vllm-openai-rocm:v0.31.0` (ROCm 7.2.3, torch 2.13 per Dockerfile) | [planned] | hub.docker.com/r/vllm/vllm-openai-rocm |
| torch (training venv) | `2.14.1` from `https://download.pytorch.org/whl/rocm7.2` | [planned] | download.pytorch.org/whl/torch |
| Unsloth | `unsloth[amd]` 2026.10.x with torch overrides | [planned] | unsloth.ai/docs/get-started/install/amd |
| Generator | `Qwen/Qwen3.8-27B` (Apache-2.0, dense 27.8B) | [planned] | huggingface.co/Qwen/Qwen3.8-27B |
| Generator fallback | `google/gemma-4-26B-A4B-it` (check licence before use) | [planned] | |
| Student | `Qwen/Qwen3.5-4B` (Apache-2.0); fallback `Qwen/Qwen3.5-9B` | [planned] | huggingface.co/Qwen/Qwen3.5-4B |
| Embeddings | `BAAI/bge-m3` (MIT) | [planned] | |
| Python | 3.12 (uv venv at `/opt/chirak-venv`) | [planned] | |

## Env

| Var | Value | Why |
|---|---|---|
| `HF_HUB_DISABLE_XET` | `1` | Unsloth AMD docs: fixes HF downloads on AMD |
| `HF_HOME` | `/data/hf` | shared model cache for venv and container |
| `VLLM_ROCM_USE_AITER` | `0` default; step 3 also tried with `1` | AITER + LoRA on Qwen3.5 hybrid attention is unverified |
| `VLLM_ALLOW_RUNTIME_LORA_UPDATING` | `True` (student server only) | hot-loading adapters; vLLM says trusted network only, our worker is localhost-bound |
| `HSA_OVERRIDE_GFX_VERSION` | **not set** | gfx942 is natively supported; set only if step 1/2 fails without it |

Not used: `VLLM_USE_TRITON_FLASH_ATTN` (no longer exists in vLLM 0.31), bitsandbytes, QLoRA.

## Known unknowns the spike must answer

1. Does `Qwen3.5-4B` (`Qwen3_5ForConditionalGeneration`, a vision-language architecture) load through Unsloth `FastLanguageModel` and through `AutoModelForCausalLM` (peft fallback)? Step 4.
2. Does TRL's prompt/completion format with `completion_only_loss=True` apply the Qwen3.5 chat template with thinking disabled, or does it insert `<think>` blocks into the training target? Inspect one tokenized example in step 4 log.
3. Does `vllm/vllm-openai-rocm:v0.31.0` load `Qwen3.8-27B` (vLLM docs list the architecture via Qwen3.5-9B, not 3.8 itself)? Step 3.
4. Runtime LoRA hot-load on MI300X — works, or fall back to `--lora-modules` at startup? Step 5.
5. Is vLLM at 0.5 memory + training stable on one GPU? Step 7. If not, decision: stop vLLM during training.

## Run

```bash
# local
export DIGITALOCEAN_ACCESS_TOKEN=... DROPLET_IMAGE=... DROPLET_SSH_KEY=...
spike/droplet-create.sh
rsync -az --exclude .git ./ root@$DROPLET_HOST:/root/chirak/   # .env included, never committed
# droplet
ssh root@$DROPLET_HOST 'cd /root/chirak && spike/00-setup.sh && spike/run-all.sh'
# local, when idle
spike/droplet-destroy.sh
```

## Results

Filled from `spike/out/results.tsv` after the run. Nothing here yet.

## Workarounds needed

None recorded yet.
