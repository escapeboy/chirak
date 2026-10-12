#!/usr/bin/env bash
# Step 6: sentence-transformers embeddings on the GPU.
. "$(dirname "$0")/lib.sh"
PY="$TRAIN_PY"

if "$PY" "$SPIKE_DIR/06_embed.py" 2>&1 | tee "$OUT_DIR/06-embed.log"; then
  result 06-embed PASS "$(tail -1 "$OUT_DIR/06-embed.log")"
else
  result 06-embed FAIL "$(tail -1 "$OUT_DIR/06-embed.log")"
fi
