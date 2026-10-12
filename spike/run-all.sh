#!/usr/bin/env bash
# Run spike steps 1-7 in order on the droplet. Results: spike/out/results.tsv
# Steps 2-5 are the gate: if one fails, the run stops (and step 4 retries once with BACKEND=peft).
. "$(dirname "$0")/lib.sh"
: > "$OUT_DIR/results.tsv"
S="$SPIKE_DIR"

"$S/01-rocm.sh"
"$S/02-torch.sh" || exit 1
"$S/03-vllm-generator.sh" || exit 1
if ! "$S/04-train-lora.sh"; then
  BACKEND=peft "$S/04-train-lora.sh" || exit 1
  export BACKEND=peft
fi
"$S/05-vllm-lora.sh" || exit 1
"$S/06-embed.sh"
"$S/07-concurrency.sh"

echo; column -t -s $'\t' "$OUT_DIR/results.tsv"
