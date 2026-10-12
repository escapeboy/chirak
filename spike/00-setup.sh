#!/usr/bin/env bash
# Run inside the RunPod pod. Builds the training venv on the CONTAINER disk (/opt/venv-train).
# The network volume (/data, MooseFS) is too slow for a venv: importing torch+unsloth from it
# hung for >5 min, I/O-bound. So the venv lives on local disk, and a tar of it is kept on
# /data; after a pod restart it is restored from that single file instead of reinstalled.
# The venv's base Python is the image's /usr/bin/python3 (3.12), so restore needs no uv Python.
# vLLM comes from the pod image itself (see spike/SETUP.md). Every pin must match SETUP.md.
set -euo pipefail

TORCH_VERSION="${TORCH_VERSION:-2.14.1}"
TORCH_INDEX="${TORCH_INDEX:-https://download.pytorch.org/whl/rocm7.2}"
VENV=/opt/venv-train
TARBALL=/data/venv-train.tar

mkdir -p /data/hf /data/runs /data/spike/out
export HF_HUB_DISABLE_XET=1 UV_CACHE_DIR=/data/uv/cache UV_LINK_MODE=copy

if [ ! -x "$VENV/bin/python" ] && [ -f "$TARBALL" ]; then
  tar -xf "$TARBALL" -C /opt
fi

if [ ! -x "$VENV/bin/python" ]; then
  command -v uv >/dev/null || curl -LsSf https://astral.sh/uv/install.sh | sh
  export PATH="$HOME/.local/bin:$PATH"
  uv venv --python /usr/bin/python3 "$VENV"
  # Unsloth AMD install (https://unsloth.ai/docs/get-started/install/amd):
  # ROCm torch first, then pin it so unsloth's deps cannot swap in a CUDA build.
  VIRTUAL_ENV=$VENV uv pip install "torch==${TORCH_VERSION}" torchvision torchaudio --index-url "$TORCH_INDEX"
  VIRTUAL_ENV=$VENV uv pip freeze | grep -E '^(torch|torchvision|torchaudio)==' > /data/torch-pins.txt
  echo "triton ; sys_platform == 'never'" >> /data/torch-pins.txt
  echo "xformers ; sys_platform == 'never'" >> /data/torch-pins.txt
  VIRTUAL_ENV=$VENV uv pip install "unsloth[amd]" sentence-transformers --overrides /data/torch-pins.txt
  # bf16 LoRA only: bitsandbytes must not be present.
  VIRTUAL_ENV=$VENV uv pip uninstall bitsandbytes 2>/dev/null || true
  VIRTUAL_ENV=$VENV uv pip freeze > /data/spike/out/pip-freeze-train.txt
  tar -cf "$TARBALL.tmp" -C /opt venv-train && mv "$TARBALL.tmp" "$TARBALL"
fi

"$(dirname "$0")/08-versions.sh"
