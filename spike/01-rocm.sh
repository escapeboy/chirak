#!/usr/bin/env bash
# Step 1: ROCm stack visible, MI300X (gfx942) present.
. "$(dirname "$0")/lib.sh"

{
  echo "== rocminfo"; rocminfo | grep -E 'Marketing Name|Name:\s+gfx' | sort -u
  echo "== rocm-smi"; rocm-smi --showproductname --showmeminfo vram
  echo "== ROCm version"; cat /opt/rocm/.info/version 2>/dev/null
  echo "== amd-smi"; amd-smi version 2>/dev/null || echo "amd-smi not found"
  echo "== rocm examine"; command -v rocm >/dev/null && rocm examine || echo "rocm CLI not installed"
  echo "== HSA_OVERRIDE_GFX_VERSION=${HSA_OVERRIDE_GFX_VERSION:-<unset>}"
} 2>&1 | tee "$OUT_DIR/01-rocm.log"

if rocminfo 2>/dev/null | grep -q gfx942; then
  result 01-rocm PASS "ROCm $(cat /opt/rocm/.info/version 2>/dev/null), gfx942 visible"
else
  result 01-rocm FAIL "gfx942 not found in rocminfo"
fi
