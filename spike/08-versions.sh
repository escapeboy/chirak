#!/usr/bin/env bash
# Step 8 input: print every version that goes into spike/SETUP.md.
. "$(dirname "$0")/lib.sh"
{
  echo "pod_id=${RUNPOD_POD_ID:-?} gpu=$(rocm-smi --showproductname 2>/dev/null | grep -m1 -i 'card series' | sed 's/.*: *//')"
  echo "rocm=$(cat /opt/rocm/.info/version 2>/dev/null)"
  echo "== serving env (pod image)"
  python3 -c "import torch, vllm, transformers; print('torch', torch.__version__, 'hip', torch.version.hip); print('vllm', vllm.__version__); print('transformers', transformers.__version__)" 2>&1
  echo "== training venv ($TRAIN_PY)"
  "$TRAIN_PY" -c "import torch, transformers, trl, peft, unsloth, sentence_transformers as st; print('torch', torch.__version__, 'hip', torch.version.hip); print('transformers', transformers.__version__); print('trl', trl.__version__); print('peft', peft.__version__); print('unsloth', unsloth.__version__); print('sentence-transformers', st.__version__)" 2>&1 | grep -vE '^(🦥|Unsloth:)'
  "$TRAIN_PY" -c "import bitsandbytes" 2>/dev/null && echo "WARNING: bitsandbytes is installed" || echo "bitsandbytes: not installed (correct)"
} | tee "$OUT_DIR/versions.txt"
