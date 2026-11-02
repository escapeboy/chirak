#!/usr/bin/env bash
# Bring up a pod that has no prepared /data (e.g. a different region than the network volume):
# build the training venv and download the three models IN PARALLEL, then start the worker.
# Needs /data/chirak (rsync of the repo) and /data/chirak/.env with HF_TOKEN and WORKER_BEARER_TOKEN.
set -uo pipefail

REPO=/data/chirak
mkdir -p /data/logs /data/hf
set -a; . "$REPO/.env"; set +a
export HF_HOME=/data/hf HF_HUB_DISABLE_XET=1

"$REPO/spike/00-setup.sh" >/data/logs/setup.log 2>&1 &
SETUP=$!

DL=()
for m in "${GENERATOR_MODEL:-Qwen/Qwen3.8-27B}" "${STUDENT_MODEL:-Qwen/Qwen3.5-4B}" "${EMBEDDING_MODEL:-BAAI/bge-m3}"; do
  python3 -c "import sys; from huggingface_hub import snapshot_download as d; d(sys.argv[1], max_workers=16)" "$m" \
    >"/data/logs/dl-${m//\//_}.log" 2>&1 &
  DL+=($!)
done

rc=0
for p in "${DL[@]}"; do wait "$p" || { echo "model download failed (pid $p), see /data/logs/dl-*.log"; rc=1; }; done
echo "models done $(date -u +%T)"
wait "$SETUP" || { echo "venv setup failed, see /data/logs/setup.log"; tail -20 /data/logs/setup.log; rc=1; }
echo "venv done $(date -u +%T)"
[ "$rc" = 0 ] || exit 1

cp "$REPO/worker/start.sh" /data/start.sh
/data/start.sh
