#!/usr/bin/env bash
# Run on a fresh droplet as root: training venv, vLLM image, ROCm CLI.
# Every pin here must match spike/SETUP.md.
set -euo pipefail

TORCH_VERSION="${TORCH_VERSION:-2.14.1}"
TORCH_INDEX="${TORCH_INDEX:-https://download.pytorch.org/whl/rocm7.2}"
VENV=/opt/chirak-venv

mkdir -p /data/hf /data/runs /data/spike
export HF_HUB_DISABLE_XET=1

command -v uv >/dev/null || curl -LsSf https://astral.sh/uv/install.sh | sh
export PATH="$HOME/.local/bin:$PATH"

uv venv --python 3.12 "$VENV"
# shellcheck disable=SC1091
. "$VENV/bin/activate"

# Unsloth AMD install (https://unsloth.ai/docs/get-started/install/amd):
# ROCm torch first, then pin it so unsloth's deps cannot swap in a CUDA build.
uv pip install "torch==${TORCH_VERSION}" torchvision torchaudio --index-url "$TORCH_INDEX"
uv pip freeze | grep -E '^(torch|torchvision|torchaudio)==' > /opt/torch-pins.txt
echo "triton ; sys_platform == 'never'" >> /opt/torch-pins.txt
echo "xformers ; sys_platform == 'never'" >> /opt/torch-pins.txt
uv pip install "unsloth[amd]" sentence-transformers --overrides /opt/torch-pins.txt
# bf16 LoRA only: bitsandbytes must not be present.
uv pip uninstall bitsandbytes 2>/dev/null || true

docker pull "${VLLM_IMAGE:-vllm/vllm-openai-rocm:v0.31.0}"

command -v rocm >/dev/null || curl -fsSL https://raw.githubusercontent.com/ROCm/rocm-cli/main/install.sh | sh || echo "rocm CLI install failed (optional)"

uv pip freeze > /data/spike/pip-freeze.txt
python -c "import torch, transformers, trl, peft, unsloth; print('torch', torch.__version__, 'hip', torch.version.hip); print('transformers', transformers.__version__, 'trl', trl.__version__, 'peft', peft.__version__, 'unsloth', unsloth.__version__)"
