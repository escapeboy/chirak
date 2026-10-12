#!/usr/bin/env bash
# Step 7: generator vLLM at 0.5 GPU memory + LoRA training at the same time.
# PASS = both finish and the bench still answers. Otherwise the recorded decision
# is "stop vLLM during training".
. "$(dirname "$0")/lib.sh"
: "${GENERATOR_MODEL:?}" "${STUDENT_MODEL:?}" "${VLLM_IMAGE:?}"
PY="${TRAIN_PY:-/opt/chirak-venv/bin/python}"

vllm_docker chirak-vllm -- "$GENERATOR_MODEL" --max-model-len 8192 --gpu-memory-utilization 0.5 ${GEN_EXTRA_ARGS:-}
wait_vllm 1800 || { result 07-concurrency FAIL "generator at 0.5 did not start; decision: stop vLLM during training"; exit 1; }

"$PY" "$SPIKE_DIR/train_lora.py" --model "$STUDENT_MODEL" --out /data/spike/train-concurrent \
  --backend "${BACKEND:-unsloth}" --max-steps 150 >"$OUT_DIR/07-train.log" 2>&1 &
train_pid=$!
sleep 60
bench_ok=0
python3 "$SPIKE_DIR/bench_vllm.py" "$GENERATOR_MODEL" 32 256 >"$OUT_DIR/07-bench.json" 2>&1 && bench_ok=1
wait "$train_pid"; train_rc=$?
rocm-smi --showmeminfo vram >>"$OUT_DIR/07-train.log" 2>&1
docker rm -f chirak-vllm >/dev/null

if [ "$train_rc" = 0 ] && [ "$bench_ok" = 1 ]; then
  result 07-concurrency PASS "train $(tail -1 "$OUT_DIR/07-train.log" | cut -c1-60); bench ok while training"
else
  result 07-concurrency FAIL "train_rc=$train_rc bench_ok=$bench_ok; decision: stop vLLM during training"
fi
