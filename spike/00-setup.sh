#!/usr/bin/env bash
# Run once inside the RunPod pod. Builds the training venv ON THE NETWORK VOLUME
# (/data/venv-train), so it survives pod stop/terminate and is not rebuilt each time.
# vLLM comes from the pod image itself (see spike/SETUP.md).
# Every pin here must match spike/SETUP.md.
set -euo pipefail

TORCH_VERSION="${TORCH_VERSION:-2.14.1}"
TORCH_INDEX="${TORCH_INDEX:-https://download.pytorch.org/whl/rocm7.2}"
VENV=/data/venv-train

mkdir -p /data/hf /data/runs /data/spike/out
export HF_HUB_DISABLE_XET=1
# uv's own Python and cache must also live on the volume, or the venv breaks after a restart.
export UV_PYTHON_INSTALL_DIR=/data/uv/python UV_CACHE_DIR=/data/uv/cache

command -v uv >/dev/null || curl -LsSf https://astral.sh/uv/install.sh | sh
export PATH="$HOME/.local/bin:$PATH"

if [ ! -x "$VENV/bin/python" ]; then
  uv venv --python 3.12 "$VENV"
  # Unsloth AMD install (https://unsloth.ai/docs/get-started/install/amd):
  # ROCm torch first, then pin it so unsloth's deps cannot swap in a CUDA build.
  VIRTUAL_ENV=$VENV uv pip install "torch==${TORCH_VERSION}" torchvision torchaudio --index-url "$TORCH_INDEX"
  VIRTUAL_ENV=$VENV uv pip freeze | grep -E '^(torch|torchvision|torchaudio)==' > /data/torch-pins.txt
  echo "triton ; sys_platform == 'never'" >> /data/torch-pins.txt
  echo "xformers ; sys_platform == 'never'" >> /data/torch-pins.txt
  VIRTUAL_ENV=$VENV uv pip install "unsloth[amd]" sentence-transformers --overrides /data/torch-pins.txt
  # bf16 LoRA only: bitsandbytes must not be present.
  VIRTUAL_ENV=$VENV uv pip uninstall bitsandbytes 2>/dev/null || true
fi

"$(dirname "$0")/08-versions.sh"
