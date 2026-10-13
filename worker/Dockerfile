# linux/amd64 only (ROCm). Built in CI, see .github/workflows/worker-image.yml.
# Pins mirror spike/SETUP.md: bf16 LoRA with transformers + peft + trl. No bitsandbytes, no unsloth.
FROM vllm/vllm-openai-rocm:v0.31.0

ENV HF_HOME=/data/hf \
    HF_HUB_DISABLE_XET=1 \
    DATA_DIR=/data \
    VLLM_ROCM_USE_AITER=0 \
    UV_LINK_MODE=copy \
    PYTHONUNBUFFERED=1

RUN pip install --no-cache-dir uv || pip install --no-cache-dir --break-system-packages uv

# The venv sits on container disk; the base Python is the image's /usr/bin/python3 (3.12), as on the pod.
RUN uv venv --python /usr/bin/python3 /opt/venv-train \
 && VIRTUAL_ENV=/opt/venv-train uv pip install --no-cache \
      "torch==2.14.1" torchvision torchaudio --index-url https://download.pytorch.org/whl/rocm7.2 \
 && VIRTUAL_ENV=/opt/venv-train uv pip freeze | grep -E '^(torch|torchvision|torchaudio)==' > /opt/torch-pins.txt \
 && VIRTUAL_ENV=/opt/venv-train uv pip install --no-cache --overrides /opt/torch-pins.txt \
      "transformers==5.17.0" "trl==1.15.0" "peft==0.21.2" "sentence-transformers==6.1.0"

COPY worker/requirements.txt /opt/chirak/worker/requirements.txt
RUN VIRTUAL_ENV=/opt/venv-train uv pip install --no-cache --overrides /opt/torch-pins.txt -r /opt/chirak/worker/requirements.txt
COPY worker/chirak_worker /opt/chirak/worker/chirak_worker

ENV PYTHONPATH=/opt/chirak/worker \
    WORKER_HOST=127.0.0.1 \
    WORKER_PORT=8100
WORKDIR /opt/chirak/worker
# The base image's entrypoint starts `vllm serve`; the worker starts vLLM itself, per mode.
ENTRYPOINT []
CMD ["/opt/venv-train/bin/python", "-m", "chirak_worker"]
