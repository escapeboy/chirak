# Spike setup — RunPod, 1× AMD MI300X

Status: **all 8 steps PASS on 2026-10-12** (pod `jyipvchu8hbclj`, EU-RO-1). A value is
`[verified]` because the matching spike step printed PASS on the pod; the evidence is `/data/spike/out/results.tsv` and
`/data/spike/out/versions.txt`. How to (re)create the pod: [docs/runpod.md](../docs/runpod.md).

The pod is a container. There is no OS-level setup: the base comes from the pod image,
and everything below "Added on top" is added on top of it. Durable state (models, runs, venv backup) lives on the network volume at `/data`.

## Pod

| Item | Value | Status |
|---|---|---|
| Provider / cloud | RunPod Secure Cloud, On-Demand, EU-RO-1 | [verified] |
| GPU type | `AMD Instinct MI300X OAM` ×1 (192 GB, SKU MI3SRIOV, gfx942), $2.39/h | [verified] |
| Pod image | `vllm/vllm-openai-rocm:v0.31.0` (ROCm 7.2.3 base, vLLM 0.31.0+rocm723) | [verified] |
| RunPod template | none — custom image, entrypoint overridden (see docs/runpod.md) | [verified] |
| Network volume | `729cerk1o4`, 150 GB, mounted at `/data` | [verified] |
| Exposed ports | `22/tcp` only | [verified] |
| ROCm (in image) | 7.2.3, amdgpu driver 6.10.5 | [verified] |
| torch (in image, used by vLLM) | 2.13.0+git733fca1 (hip 7.2.53211); transformers 5.17.0 | [verified] |

## Added on top

| Component | Value | Status |
|---|---|---|
| uv cache | `/data/uv/cache` | [verified] |
| Training venv | `/opt/venv-train` (container disk), base `/usr/bin/python3` 3.12; tar backup `/data/venv-train.tar` (16.4 GB) | [verified] |
| torch (training) | `2.14.1+rocm7.2` from `https://download.pytorch.org/whl/rocm7.2` | [verified] |
| unsloth | `unsloth[amd]` 2026.10.4 installed, **not usable**: import requires bitsandbytes (see Workarounds) | [verified] |
| transformers / trl / peft | 5.17.0 / 1.15.0 / 0.21.2 — the training backend (`BACKEND=peft`) | [verified] |
| sentence-transformers | 6.1.0, same venv | [verified] |
| bitsandbytes | **not installed** (checked by step 8) | [verified] |

## Models

| Role | HF id | Status |
|---|---|---|
| Generator | `Qwen/Qwen3.8-27B` (Apache-2.0, dense 27.8B) | [verified] |
| Generator fallback | `google/gemma-4-26B-A4B-it` (check licence before use) | [planned] |
| Student | `Qwen/Qwen3.5-4B` (Apache-2.0); fallback `Qwen/Qwen3.5-9B` | [verified] (4B) |
| Embeddings | `BAAI/bge-m3` (MIT) | [verified] |

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

## Results (2026-10-12, final run of each step)

| Step | Result | Wall | VRAM peak | Note |
|---|---|---|---|---|
| 1 ROCm | PASS | 0 s | — | ROCm 7.2.3, gfx942 visible, `/dev/kfd` present |
| 2 torch bf16 | PASS | 7 s | — | 8192² bf16 matmul 191.7 TFLOPS, error vs fp32 within 0.02 |
| 3 vLLM generator | PASS | 406 s | 171.8 GB | Qwen3.8-27B at 0.85 memory, 889.3 tok/s at 32 concurrent × 256 tokens; fluent Bulgarian |
| 4 LoRA train | PASS (peft) | 228 s (171 s training) | 11.3 GB | 300 steps, bf16, adamw_torch; loss 0.307 → 0.029 (mean of first/last 5 logs) |
| 5 LoRA in vLLM | PASS | 147 s | 62.5 GB (whole GPU, vLLM at 0.3) | runtime hot-load `/v1/load_lora_adapter` HTTP 200; 4/4 answers differ (base `"business"`, adapter `"B"`) |
| 6 embeddings | PASS | 31 s | 2.6 GB | bge-m3 on GPU, 6779 texts/s; BG/EN paraphrase 0.89/0.98 vs unrelated 0.47 |
| 7 concurrency | PASS | 569 s | 205.9 GB (whole GPU, at the limit) | generator at 0.5 + `--max-num-seqs 256`, 150-step training alongside: train OK (96 s), bench 782.9 tok/s |
| 8 versions | PASS | — | — | see tables above; bitsandbytes absent |

Evidence: `/data/spike/out/` on the volume (`results.tsv`, `results-run1.tsv` = first run with
the failures below, per-step logs, `versions.txt`, `pip-freeze-train.txt`).

Decision from step 7: vLLM may stay up during training, but VRAM peaks at the GPU limit, so
the worker still stops the generator before a training job when it does not need it.

## Workarounds needed

1. **Unsloth cannot be used without bitsandbytes.** `unsloth` 2026.10.4 fails on import:
   `ImportError: Unsloth: Please install bitsandbytes`. Installing it is forbidden (bf16 LoRA
   only), so the documented fallback transformers + peft + trl is the training backend.
2. **Adapter key names for Qwen3.5 (VL checkpoint).** `AutoModelForCausalLM` loads only the
   text model, so peft saves keys as `base_model.model.model.layers.*`. vLLM serves
   `Qwen3_5ForConditionalGeneration` and **silently ignores** those weights: hot-load returns
   200, but logprobs are identical to the base model to 5 decimals. Renaming the keys to
   `base_model.model.model.language_model.layers.*` makes vLLM apply them (logprobs change).
   `train_lora.py` does the rename after saving; the worker must do the same.
3. **Step 5 test task.** With word labels the base model already answered AG News correctly,
   so identical answers proved nothing. The adapter is now trained to answer with codes
   (W/S/B/T) while the prompt names the topics in words.
4. **Step 7: `max_num_seqs`.** Qwen3.8-27B is hybrid (Mamba-style cache). At 0.5 memory there are
   679 cache blocks and vLLM's default `max_num_seqs` 1024 fails at startup. `--max-num-seqs 256`.
5. **Venv on container disk.** Importing from a venv on the `/data` network volume hung for
   >5 min (I/O). The venv lives in `/opt/venv-train`, restored from `/data/venv-train.tar`.
6. **`rocminfo | grep -q` under `pipefail`** gave a false FAIL (SIGPIPE); step 1 captures first.

## MI350X re-verification (2026-10-13)

MI300X was out of stock in every RunPod region from 06:12 UTC, so the work moved to
`AMD Instinct MI350 OAM` (MI350X, gfx950, 288 GB, $5.49/h, US, pod `kjek315yyuob1d`) with a 300 GB pod
volume instead of the EU-RO-1 network volume. Same image and pins; `spike/10-fresh-pod.sh` builds
the venv and downloads the three models in parallel (models 2 min, venv 1 min).

| Check | Result | Wall | Note |
|---|---|---|---|
| 2 torch bf16 | PASS | 4 s | 49.4 TFLOPS (MI300X: 191.7); torch 2.14.1+rocm7.2 is not tuned for gfx950 yet |
| 4 LoRA train (peft) | PASS | 184 s | loss 0.308 → 0.029 in 127 s, 11.3 GB |
| 5 LoRA in vLLM | PASS | 66 s | 4/4 answers differ (base `"science_tech"`, adapter `"T"`) |
| worker smoke: health / generate / train / evaluate / serve | PASS | 219 / 126 / 206 / 6 s | through the SSH tunnel |
| end-to-end Laravel run (template seeds, eval 30, train 150) | completed | 18 min | stopped by "3 iterations"; `make eval` reproduced iter-3 macro-F1 0.8501 on the same eval sha |

RunPod notes: the REST API's `gpuTypeIds` enum has no MI350X, so the pod is created through
GraphQL `podFindAndDeployOnDemand`; GraphQL cannot override the image entrypoint, so it uses a
private template (`8syzxxdnn2`) that sets `dockerEntrypoint: ["bash","-c"]`.
