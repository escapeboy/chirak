#!/usr/bin/env bash
# Step 3: vLLM ROCm container serves the generator model; throughput at batch 32.
. "$(dirname "$0")/lib.sh"
: "${GENERATOR_MODEL:?set GENERATOR_MODEL}" "${VLLM_IMAGE:?set VLLM_IMAGE}"

docker pull "$VLLM_IMAGE" >"$OUT_DIR/03-pull.log" 2>&1
vllm_docker chirak-vllm -- "$GENERATOR_MODEL" \
  --max-model-len 8192 --gpu-memory-utilization "${GEN_GPU_UTIL:-0.85}" ${GEN_EXTRA_ARGS:-}

if ! wait_vllm 1800; then
  docker logs --tail 100 chirak-vllm >"$OUT_DIR/03-vllm.log" 2>&1
  result 03-vllm-generator FAIL "server not ready in 30 min, see out/03-vllm.log"
  exit 1
fi
docker logs chirak-vllm >"$OUT_DIR/03-vllm.log" 2>&1

if python3 "$SPIKE_DIR/bench_vllm.py" "$GENERATOR_MODEL" 32 256 | tee "$OUT_DIR/03-bench.json"; then
  tps=$(python3 -c "import json;print(json.load(open('$OUT_DIR/03-bench.json'))['tokens_per_s'])")
  result 03-vllm-generator PASS "$GENERATOR_MODEL on $VLLM_IMAGE, ${tps} tok/s @ batch 32"
else
  result 03-vllm-generator FAIL "bench failed"
fi
# Leave the container running only if the caller asks (step 7 starts its own).
[ "${KEEP_VLLM:-0}" = "1" ] || docker rm -f chirak-vllm >/dev/null
