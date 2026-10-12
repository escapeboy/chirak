#!/usr/bin/env bash
# Step 4: bf16 LoRA fine-tune of the student, 300 steps, loss must decrease.
# BACKEND=peft runs the documented fallback (transformers+peft+trl).
. "$(dirname "$0")/lib.sh"
: "${STUDENT_MODEL:?set STUDENT_MODEL}"
PY="${TRAIN_PY:-/opt/chirak-venv/bin/python}"
BACKEND="${BACKEND:-unsloth}"
OUT="/data/spike/train-${BACKEND}"
mkdir -p "$OUT"

if "$PY" "$SPIKE_DIR/train_lora.py" --model "$STUDENT_MODEL" --out "$OUT" --backend "$BACKEND" --max-steps "${STEPS:-300}" \
    2>&1 | tee "$OUT_DIR/04-train-${BACKEND}.log"; then
  cp "$OUT/train_summary.json" "$OUT_DIR/04-train-${BACKEND}.json"
  result "04-train-${BACKEND}" PASS "$(tail -1 "$OUT_DIR/04-train-${BACKEND}.log")"
else
  result "04-train-${BACKEND}" FAIL "$(tail -1 "$OUT_DIR/04-train-${BACKEND}.log")"
fi
