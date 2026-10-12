# Spike setup — RunPod, 1× AMD MI300X

Status: **scripts written, not yet run on hardware.** Values marked `[planned]` come from
documentation read on 2026-10-12. A value becomes `[verified]` only after the matching
spike step prints PASS on the pod; the evidence is `/data/spike/out/results.tsv` and
`/data/spike/out/versions.txt`. How to (re)create the pod: [docs/runpod.md](../docs/runpod.md).

The pod is a container. There is no OS-level setup: the base comes from the pod image,
and everything below "Added on top" is installed onto the network volume at `/data`.

## Pod

| Item | Value | Status |
|---|---|---|
| Provider / cloud | RunPod Secure Cloud, On-Demand | [planned] |
| GPU type | `AMD Instinct MI300X OAM` ×1 (192 GB), ~$2.39/h | [planned] |
| Pod image | `vllm/vllm-openai-rocm:v0.31.0` (ROCm 7.2.3 base, vLLM 0.31.0) | [planned] |
| RunPod template | none — custom image, entrypoint overridden (see docs/runpod.md) | [planned] |
| Network volume | mounted at `/data` | [planned] |
| Exposed ports | `22/tcp` only | [planned] |
| ROCm (in image) | TBD — step 1 | [unknown] |
| torch (in image, used by vLLM) | TBD — step 8 | [unknown] |

## Added on top (all on `/data`)

| Component | Value | Status |
|---|---|---|
| uv Python + cache | `/data/uv/python`, `/data/uv/cache` | [planned] |
| Training venv | `/data/venv-train`, Python 3.12 | [planned] |
| torch (training) | `2.14.1` from `https://download.pytorch.org/whl/rocm7.2` | [planned] |
| unsloth | `unsloth[amd]` 2026.10.x, installed with torch overrides | [planned] |
| transformers / trl / peft | whatever unsloth resolves — pinned here after step 8 | [unknown] |
| sentence-transformers | latest, same venv | [unknown] |
| bitsandbytes | **not installed** (checked by step 8) | [planned] |

## Models

| Role | HF id | Status |
|---|---|---|
| Generator | `Qwen/Qwen3.8-27B` (Apache-2.0, dense 27.8B) | [planned] |
| Generator fallback | `google/gemma-4-26B-A4B-it` (check licence before use) | [planned] |
| Student | `Qwen/Qwen3.5-4B` (Apache-2.0); fallback `Qwen/Qwen3.5-9B` | [planned] |
| Embeddings | `BAAI/bge-m3` (MIT) | [planned] |

## Env

| Var | Value | Why |
|---|---|---|
| `HF_HOME` | `/data/hf` | model cache survives pod termination |
| `HF_HUB_DISABLE_XET` | `1` | Unsloth AMD docs: fixes HF downloads on AMD |
| `VLLM_ROCM_USE_AITER` | `0` default | AITER + LoRA on Qwen3.5 hybrid attention is unverified; try `1` after PASS |
| `VLLM_ALLOW_RUNTIME_LORA_UPDATING` | `True` (student server only) | adapter hot-loading; vLLM bound to 127.0.0.1 only |
| `HSA_OVERRIDE_GFX_VERSION` | **not set** | gfx942 is natively supported; set only if steps 1–2 fail without it |

Not used: `VLLM_USE_TRITON_FLASH_ATTN` (removed in vLLM 0.31), bitsandbytes, QLoRA, Docker-in-pod.

## Known unknowns the spike must answer

1. Do `/dev/kfd`, `rocminfo`, `rocm-smi` exist inside the vLLM image on RunPod? Step 1.
2. Does `Qwen3.5-4B` (`Qwen3_5ForConditionalGeneration`, a vision-language architecture) load through Unsloth `FastLanguageModel` and through `AutoModelForCausalLM` (peft fallback)? Step 4.
3. Does TRL's prompt/completion format with `completion_only_loss=True` insert `<think>` blocks into the training target? Inspect one tokenized example in the step 4 log.
4. Does vLLM 0.31 load `Qwen3.8-27B`? Step 3.
5. Runtime LoRA hot-load on MI300X, or `--lora-modules` at startup? Step 5.
6. vLLM at 0.5 memory + training on one GPU: stable? Step 7. If not: "stop vLLM during training", and the worker implements that.

## Run (inside the pod)

```bash
cd /data/chirak && spike/00-setup.sh && spike/run-all.sh
```

## Results

Filled from `/data/spike/out/results.tsv` after the run. Nothing here yet.

## Workarounds needed

None recorded yet.
