#!/usr/bin/env bash
# Step 2: PyTorch ROCm bf16 sanity, inside the training venv.
. "$(dirname "$0")/lib.sh"
PY="$TRAIN_PY"

if "$PY" "$SPIKE_DIR/02_torch.py" 2>&1 | tee "$OUT_DIR/02-torch.log"; then
  result 02-torch PASS "$(tail -1 "$OUT_DIR/02-torch.log")"
else
  result 02-torch FAIL "$(tail -1 "$OUT_DIR/02-torch.log")"
fi
