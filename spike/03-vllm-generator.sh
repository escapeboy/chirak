#!/usr/bin/env bash
# Step 3: vLLM (ROCm) serves the generator model; throughput at batch 32.
. "$(dirname "$0")/lib.sh"
: "${GENERATOR_MODEL:?set GENERATOR_MODEL}"

"$VLLM_BIN" --version >"$OUT_DIR/03-vllm-version.txt" 2>&1
w=$(vram_peak_watch "$OUT_DIR/03-vram.txt")
vllm_start 03-vllm.log "$GENERATOR_MODEL" \
  --max-model-len 8192 --gpu-memory-utilization "${GEN_GPU_UTIL:-0.85}" ${GEN_EXTRA_ARGS:-}

if ! wait_vllm 1800; then
  kill "$w"; vllm_stop
  result 03-vllm-generator FAIL "server not ready, see $OUT_DIR/03-vllm.log"
  exit 1
fi

ok=0
python3 "$SPIKE_DIR/bench_vllm.py" "$GENERATOR_MODEL" 32 256 | tee "$OUT_DIR/03-bench.json" && ok=1
kill "$w"
[ "${KEEP_VLLM:-0}" = "1" ] || vllm_stop

if [ "$ok" = 1 ]; then
  tps=$(python3 -c "import json;print(json.load(open('$OUT_DIR/03-bench.json'))['tokens_per_s'])")
  result 03-vllm-generator PASS "$GENERATOR_MODEL, vllm $(tail -1 "$OUT_DIR/03-vllm-version.txt"), ${tps} tok/s @ batch 32, VRAM peak $(vram_peak_gb "$OUT_DIR/03-vram.txt") GB"
else
  result 03-vllm-generator FAIL "bench failed"
fi
