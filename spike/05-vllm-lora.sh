#!/usr/bin/env bash
# Step 5: load the step-4 adapter into vLLM (--enable-lora), answer differs from base.
# Tries runtime hot-loading first; falls back to --lora-modules at startup.
. "$(dirname "$0")/lib.sh"
: "${STUDENT_MODEL:?set STUDENT_MODEL}" "${VLLM_IMAGE:?set VLLM_IMAGE}"
ADAPTER="${ADAPTER:-/data/spike/train-${BACKEND:-unsloth}/adapter}"
LORA_ARGS="--enable-lora --max-lora-rank 64 --max-loras 4 --max-model-len 4096 --gpu-memory-utilization 0.3"

vllm_docker chirak-vllm -e VLLM_ALLOW_RUNTIME_LORA_UPDATING=True -- "$STUDENT_MODEL" $LORA_ARGS
wait_vllm 900 || { docker logs --tail 100 chirak-vllm >"$OUT_DIR/05-vllm.log" 2>&1; result 05-vllm-lora FAIL "server not ready"; exit 1; }

hot=$(curl -s -o "$OUT_DIR/05-hotload.txt" -w '%{http_code}' -X POST "http://127.0.0.1:${VLLM_PORT}/v1/load_lora_adapter" \
  -H 'Content-Type: application/json' -d "{\"lora_name\":\"spike\",\"lora_path\":\"${ADAPTER}\"}")
mode="runtime hot-load"
if [ "$hot" != "200" ]; then
  echo "hot-load returned HTTP $hot: $(cat "$OUT_DIR/05-hotload.txt")" | tee -a "$OUT_DIR/05-vllm.log"
  mode="--lora-modules at startup (hot-load HTTP $hot)"
  vllm_docker chirak-vllm -- "$STUDENT_MODEL" $LORA_ARGS --lora-modules "spike=${ADAPTER}"
  wait_vllm 900 || { result 05-vllm-lora FAIL "restart with --lora-modules failed"; exit 1; }
fi

if python3 "$SPIKE_DIR/lora_diff.py" "$STUDENT_MODEL" spike 2>&1 | tee "$OUT_DIR/05-lora-diff.log"; then
  result 05-vllm-lora PASS "$mode; $(tail -1 "$OUT_DIR/05-lora-diff.log")"
else
  result 05-vllm-lora FAIL "$mode; $(tail -1 "$OUT_DIR/05-lora-diff.log")"
fi
docker logs chirak-vllm >>"$OUT_DIR/05-vllm.log" 2>&1
docker rm -f chirak-vllm >/dev/null
