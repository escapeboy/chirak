"""Step 6: multilingual embeddings on the GPU; BG/EN paraphrases score higher than unrelated pairs."""
import json
import os
import sys
import time

import torch
from sentence_transformers import SentenceTransformer

name = os.environ.get("EMBEDDING_MODEL", "BAAI/bge-m3")
model = SentenceTransformer(name, device="cuda")

pairs = [
    ("Where is my order? It was due yesterday.", "Поръчката ми трябваше да пристигне вчера, къде е?"),
    ("I want to return these shoes.", "Искам да върна тези обувки."),
]
unrelated = ("My card was charged twice.", "Каква е гаранцията на този лаптоп?")

texts = [t for pair in pairs + [unrelated] for t in pair]
emb = model.encode(texts, normalize_embeddings=True, convert_to_tensor=True)
sims = [round(float(emb[i] @ emb[i + 1]), 3) for i in range(0, len(texts), 2)]

batch = [f"Тестов текст номер {i} за поръчка и доставка." for i in range(2000)]
torch.cuda.synchronize()
start = time.time()
model.encode(batch, batch_size=128, normalize_embeddings=True)
torch.cuda.synchronize()
rate = 2000 / (time.time() - start)

out = {
    "model": name,
    "device": str(model.device),
    "paraphrase_sims": sims[:-1],
    "unrelated_sim": sims[-1],
    "texts_per_s": round(rate),
}
print(json.dumps(out, indent=2))
ok = model.device.type == "cuda" and min(sims[:-1]) > sims[-1]
print(("PASS" if ok else "FAIL") + f" {name} on {model.device}, {round(rate)} texts/s, VRAM peak {torch.cuda.max_memory_allocated() / 1e9:.1f} GB (torch)")
sys.exit(0 if ok else 1)
