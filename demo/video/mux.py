"""Lays the narration wavs from tts.py onto the silent recording from record.js at the scene offsets.

Usage: python mux.py <dir with scenes.json and <scene>.wav> <out.mp4>
"""
import json
import subprocess
import sys
from pathlib import Path

d, out = Path(sys.argv[1]), sys.argv[2]
meta = json.loads((d / "scenes.json").read_text())
inputs, filters = ["-i", meta["video"]], []
for i, s in enumerate(meta["scenes"], start=1):
    inputs += ["-i", str(d / f"{s['key']}.wav")]
    ms = round((s["at"] + 0.4) * 1000)
    filters.append(f"[{i}:a]aresample=48000,adelay={ms}|{ms}[a{i}]")
n = len(meta["scenes"])
filters.append("".join(f"[a{i}]" for i in range(1, n + 1)) + f"amix=inputs={n}:normalize=0,loudnorm=I=-16[aout]")
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", *inputs, "-filter_complex", ";".join(filters),
                "-map", "0:v", "-map", "[aout]", "-c:v", "libx264", "-preset", "slow", "-crf", "22", "-pix_fmt", "yuv420p",
                "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart", out], check=True)
print(out)
