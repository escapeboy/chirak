"""Synthesizes narration.tsv with Kokoro (Apache-2.0, runs locally) into <dir>/<scene>.wav and <dir>/durations.txt.

Model files: kokoro-v1.0.onnx and voices-v1.0.bin from
https://github.com/thewh1teagle/kokoro-onnx/releases/tag/model-files-v1.0

Usage: uv run --python 3.12 --with kokoro-onnx --with soundfile python tts.py <model_dir> <out_dir> [voice]
"""
import sys
from pathlib import Path

import soundfile as sf
from kokoro_onnx import Kokoro

model_dir, out = Path(sys.argv[1]), Path(sys.argv[2])
voice = sys.argv[3] if len(sys.argv) > 3 else "af_heart"
out.mkdir(parents=True, exist_ok=True)
kokoro = Kokoro(str(model_dir / "kokoro-v1.0.onnx"), str(model_dir / "voices-v1.0.bin"))

lines = []
for row in (Path(__file__).parent / "narration.tsv").read_text().strip().split("\n"):
    key, text = row.split("\t")
    audio, rate = kokoro.create(text, voice=voice, speed=1.0, lang="en-us")
    sf.write(out / f"{key}.wav", audio, rate)
    lines.append(f"{key} {len(audio) / rate:.2f}")
(out / "durations.txt").write_text("\n".join(lines) + "\n")
print("\n".join(lines))
