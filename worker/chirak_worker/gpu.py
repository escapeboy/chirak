"""GPU info through rocm-smi. Absence of the tool or the GPU is reported, not raised."""
import json
import shutil
import subprocess


def _rocm_smi(*args: str) -> dict:
    out = subprocess.run(["rocm-smi", *args, "--json"], capture_output=True, text=True, timeout=10, check=True).stdout
    return json.loads(out[out.index("{"):])


def _num(d: dict, *needles: str) -> int | None:
    for k, v in d.items():
        if all(n in k.lower() for n in needles):
            try:
                return int(v)
            except (TypeError, ValueError):
                return None
    return None


def gpu_info() -> dict:
    if shutil.which("rocm-smi") is None:
        return {"visible": False, "name": None, "vram_used_gb": None, "vram_total_gb": None}
    try:
        mem = next(iter(_rocm_smi("--showmeminfo", "vram").values()))
        total, used = _num(mem, "total memory"), _num(mem, "used memory")
        name = None
        try:
            card = next(iter(_rocm_smi("--showproductname").values()))
            # RunPod's MI350X reports "N/A" for series and SKU; the gfx arch is the useful fallback.
            name = next((v for k, v in card.items() if ("series" in k.lower() or "sku" in k.lower()) and v != "N/A"),
                        card.get("GFX Version"))
        except Exception:
            pass
        gb = lambda b: round(b / 1e9, 1) if b is not None else None  # noqa: E731
        return {"visible": True, "name": name, "vram_used_gb": gb(used), "vram_total_gb": gb(total)}
    except Exception:
        return {"visible": False, "name": None, "vram_used_gb": None, "vram_total_gb": None}
