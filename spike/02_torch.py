"""Step 2: PyTorch ROCm import, GPU visible, bf16 matmul correct and fast."""
import json
import sys
import time

import torch

info = {
    "torch": torch.__version__,
    "hip": torch.version.hip,
    "cuda_available": torch.cuda.is_available(),
}
if not info["cuda_available"]:
    print(json.dumps(info))
    print("FAIL torch.cuda.is_available() is False")
    sys.exit(1)

info["device"] = torch.cuda.get_device_name(0)
info["vram_gb"] = round(torch.cuda.get_device_properties(0).total_memory / 1e9, 1)

a = torch.randn(8192, 8192, device="cuda", dtype=torch.bfloat16)
b = torch.randn(8192, 8192, device="cuda", dtype=torch.bfloat16)
torch.cuda.synchronize()
start = time.time()
iters = 50
for _ in range(iters):
    c = a @ b
torch.cuda.synchronize()
elapsed = time.time() - start
info["bf16_tflops"] = round(2 * 8192**3 * iters / elapsed / 1e12, 1)

ref = (a[:256].float() @ b[:, :256].float())
err = (c[:256, :256].float() - ref).abs().max().item() / ref.abs().max().item()
info["rel_err_vs_fp32"] = round(err, 5)

print(json.dumps(info, indent=2))
ok = c.dtype == torch.bfloat16 and err < 0.02
print(("PASS" if ok else "FAIL") + f" {info['device']} bf16 {info['bf16_tflops']} TFLOPS")
sys.exit(0 if ok else 1)
