#!/usr/bin/env bash
# Step 5: load the step-4 adapter into vLLM (--enable-lora), answer differs from base.
# Tries runtime hot-loading first; falls back to --lora-modules at startup.
. "$(dirname "$0")/lib.sh"
: "${STUDENT_MODEL:?set STUDENT_MODEL}"
ADAPTER="${ADAPTER:-/data/spike/train-${BACKEND:-unsloth}/adapter}"
LORA_ARGS="--enable-lora --max-lora-rank 64 --max-loras 4 --max-model-len 4096 --gpu-memory-utilization 0.3"

w=$(vram_peak_watch "$OUT_DIR/05-vram.txt")
VLLM_ALLOW_RUNTIME_LORA_UPDATING=True vllm_start 05-vllm.log "$STUDENT_MODEL" $LORA_ARGS
wait_vllm 900 || { kill "$w"; vllm_stop; result 05-vllm-lora FAIL "server not ready"; exit 1; }

hot=$(curl -s -o "$OUT_DIR/05-hotload.txt" -w '%{http_code}' -X POST "http://127.0.0.1:${VLLM_PORT}/v1/load_lora_adapter" \
  -H 'Content-Type: application/json' -d "{\"lora_name\":\"spike\",\"lora_path\":\"${ADAPTER}\"}")
mode="runtime hot-load"
if [ "$hot" != "200" ]; then
  echo "hot-load returned HTTP $hot: $(cat "$OUT_DIR/05-hotload.txt")" | tee -a "$OUT_DIR/05-notes.txt"
  mode="--lora-modules at startup (hot-load HTTP $hot)"
  vllm_start 05-vllm-restart.log "$STUDENT_MODEL" $LORA_ARGS --lora-modules "spike=${ADAPTER}"
  wait_vllm 900 || { kill "$w"; vllm_stop; result 05-vllm-lora FAIL "restart with --lora-modules failed"; exit 1; }
fi

ok=0
python3 "$SPIKE_DIR/lora_diff.py" "$STUDENT_MODEL" spike 2>&1 | tee "$OUT_DIR/05-lora-diff.log" && ok=1
kill "$w"; vllm_stop
note="$mode; $(tail -1 "$OUT_DIR/05-lora-diff.log"); VRAM peak $(vram_peak_gb "$OUT_DIR/05-vram.txt") GB"
if [ "$ok" = 1 ]; then result 05-vllm-lora PASS "$note"; else result 05-vllm-lora FAIL "$note"; fi
