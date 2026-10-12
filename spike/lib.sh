# Shared helpers for spike steps. Source from each step script.
set -uo pipefail

SPIKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${SPIKE_DIR}/out"
mkdir -p "$OUT_DIR"

if [ -f "${SPIKE_DIR}/../.env" ]; then
  set -a; . "${SPIKE_DIR}/../.env"; set +a
fi

export HF_HUB_DISABLE_XET=1
export HF_HOME="${HF_HOME:-/data/hf}"
VLLM_PORT="${VLLM_PORT:-8000}"

STEP_START=$(date +%s)

result() {
  # result <step> PASS|FAIL <note>
  local step="$1" status="$2" note="${3:-}"
  local secs=$(( $(date +%s) - STEP_START ))
  printf '%s\t%s\t%ss\t%s\n' "$step" "$status" "$secs" "$note" | tee -a "${OUT_DIR}/results.tsv"
  [ "$status" = "PASS" ]
}

vllm_docker() {
  # vllm_docker <container-name> <extra docker args...> -- <vllm serve args...>
  local name="$1"; shift
  local docker_args=() ; while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do docker_args+=("$1"); shift; done
  shift
  docker rm -f "$name" >/dev/null 2>&1
  docker run -d --name "$name" \
    --device=/dev/kfd --device=/dev/dri --group-add video \
    --ipc=host --shm-size 16g --security-opt seccomp=unconfined \
    -p 127.0.0.1:${VLLM_PORT}:8000 \
    -v /data:/data -e HF_HOME=/data/hf -e HF_TOKEN -e HF_HUB_DISABLE_XET=1 \
    -e VLLM_ROCM_USE_AITER="${VLLM_ROCM_USE_AITER:-0}" \
    "${docker_args[@]}" \
    "$VLLM_IMAGE" \
    vllm serve "$@" --host 0.0.0.0 --port 8000
}

wait_vllm() {
  # wait_vllm <timeout-seconds>
  local deadline=$(( $(date +%s) + ${1:-1200} ))
  until curl -sf "http://127.0.0.1:${VLLM_PORT}/v1/models" >/dev/null; do
    [ "$(date +%s)" -ge "$deadline" ] && return 1
    sleep 10
  done
}
