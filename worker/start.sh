#!/usr/bin/env bash
# Pod boot script: copy to /data/start.sh (the pod start command runs it). Idempotent.
# Code lives in /data/chirak (rsync from the repo), secrets in /data/chirak/.env (never committed).
set -uo pipefail

REPO="${REPO:-/data/chirak}"
VENV=/opt/venv-train
TARBALL=/data/venv-train.tar
LOG=/data/logs/worker.log
PORT="${WORKER_PORT:-8100}"

mkdir -p /data/logs /data/hf /data/runs /data/uv/cache
export HF_HOME=/data/hf HF_HUB_DISABLE_XET=1 UV_CACHE_DIR=/data/uv/cache UV_LINK_MODE=copy
export PATH="$HOME/.local/bin:$PATH"

# The venv must live on container disk (the network volume is too slow for imports); restore it from the tar.
if [ ! -x "$VENV/bin/python" ]; then
  if [ -f "$TARBALL" ]; then
    echo "restoring $VENV from $TARBALL"
    tar -xf "$TARBALL" -C /opt || { echo "venv restore failed"; exit 1; }
  else
    echo "no $VENV and no $TARBALL: run spike/00-setup.sh first"; exit 1
  fi
fi

command -v uv >/dev/null || curl -LsSf https://astral.sh/uv/install.sh | sh
export PATH="$HOME/.local/bin:$PATH"
OVERRIDES=()
[ -f /data/torch-pins.txt ] && OVERRIDES=(--overrides /data/torch-pins.txt)  # keep ROCm torch from being swapped
VIRTUAL_ENV=$VENV uv pip install -r "$REPO/worker/requirements.txt" "${OVERRIDES[@]}" || { echo "pip install failed"; exit 1; }

if [ -f "$REPO/.env" ]; then set -a; . "$REPO/.env"; set +a; fi
if [ -z "${WORKER_BEARER_TOKEN:-}" ]; then
  echo "WORKER_BEARER_TOKEN is not set (put it in $REPO/.env)"; exit 1
fi

if curl -sf "http://127.0.0.1:$PORT/health" >/dev/null; then
  echo "worker already running on :$PORT"; exit 0
fi

DATA_DIR=/data PYTHONPATH="$REPO/worker" nohup "$VENV/bin/python" -m chirak_worker >>"$LOG" 2>&1 &
for _ in $(seq 1 30); do
  curl -sf "http://127.0.0.1:$PORT/health" >/dev/null && { echo "worker up on 127.0.0.1:$PORT (log: $LOG)"; exit 0; }
  sleep 2
done
echo "worker did not come up; see $LOG"; tail -20 "$LOG"; exit 1
