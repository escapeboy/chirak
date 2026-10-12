# Shared helpers for spike steps. Source from each step script.
# Runs inside the RunPod pod (a container): vLLM is a local process, not a Docker container.
set -uo pipefail

SPIKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -f "${SPIKE_DIR}/../.env" ]; then
  set -a; . "${SPIKE_DIR}/../.env"; set +a
fi

# Everything durable lives on the network volume.
OUT_DIR="${SPIKE_OUT:-/data/spike/out}"
mkdir -p "$OUT_DIR"
export HF_HUB_DISABLE_XET=1
export HF_HOME="${HF_HOME:-/data/hf}"
VLLM_PORT="${VLLM_PORT:-8000}"
VLLM_BIN="${VLLM_BIN:-vllm}"
TRAIN_PY="${TRAIN_PY:-/opt/venv-train/bin/python}"

STEP_START=$(date +%s)

result() {
  # result <step> PASS|FAIL <note>
  local step="$1" status="$2" note="${3:-}"
  local secs=$(( $(date +%s) - STEP_START ))
  printf '%s\t%s\t%ss\t%s\n' "$step" "$status" "$secs" "$note" | tee -a "${OUT_DIR}/results.tsv"
  [ "$status" = "PASS" ]
}

vllm_start() {
  # vllm_start <log-name> <vllm serve args...>; extra env is inherited from the caller.
  local log="$1"; shift
  vllm_stop
  VLLM_ROCM_USE_AITER="${VLLM_ROCM_USE_AITER:-0}" \
    nohup "$VLLM_BIN" serve "$@" --host 127.0.0.1 --port "$VLLM_PORT" >"$OUT_DIR/$log" 2>&1 &
  echo $! > "$OUT_DIR/vllm.pid"
}

vllm_stop() {
  if [ -f "$OUT_DIR/vllm.pid" ]; then
    kill "$(cat "$OUT_DIR/vllm.pid")" 2>/dev/null
    for _ in $(seq 1 30); do kill -0 "$(cat "$OUT_DIR/vllm.pid")" 2>/dev/null || break; sleep 2; done
    kill -9 "$(cat "$OUT_DIR/vllm.pid")" 2>/dev/null
    rm -f "$OUT_DIR/vllm.pid"
  fi
}

wait_vllm() {
  # wait_vllm <timeout-seconds>; fails early if the server process died.
  local deadline=$(( $(date +%s) + ${1:-1200} ))
  until curl -sf "http://127.0.0.1:${VLLM_PORT}/v1/models" >/dev/null; do
    kill -0 "$(cat "$OUT_DIR/vllm.pid" 2>/dev/null)" 2>/dev/null || return 1
    [ "$(date +%s)" -ge "$deadline" ] && return 1
    sleep 10
  done
}

vram_peak_watch() {
  # vram_peak_watch <file>: background sampler of GPU VRAM used (bytes); prints max on stop.
  ( while :; do rocm-smi --showmeminfo vram --json 2>/dev/null \
      | python3 -c "import json,sys;d=json.load(sys.stdin);print(max(int(v.get('VRAM Total Used Memory (B)',0)) for v in d.values()))" ; sleep 2; done ) >"$1" 2>/dev/null &
  echo $!
}

vram_peak_gb() {
  sort -n "$1" | tail -1 | awk '{printf "%.1f", $1/1e9}'
}
