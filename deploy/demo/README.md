# Public read-only demo on a CPU host

What runs at https://chirak.fleetq.net (fleetq-01, Hetzner, no GPU):

| Service | What |
|---|---|
| `app` (`chirak-web`) | The Laravel control plane with `CHIRAK_READONLY=1`: stored run reports, dashboards and the playground; no new runs. SQLite copy of the exported runs. |
| `predict` | `chirak_worker.cpu_predict`: the GPU worker's `/predict` contract (same prompt, greedy decoding, same strict parser) in front of the two llama.cpp servers. |
| `llama-base`, `llama-tuned` | `llama-server` with the base Qwen3.5-4B and with the served adapter merged in, both GGUF q8_0, 2 threads and 6 GB each. |

The playground therefore runs a **quantized CPU build** of the adapter, not the bf16 model the GPU
evaluated. Its own scores on every eval slice are measured with `cpu_predict eval` before it is shown
(see docs/demo-run-log.md) and the playground page says which build answers.

## Build the models (on a GPU pod, which has the base model and the training venv)

```bash
# demo/cpu/merge_adapter.py + llama.cpp's convert_hf_to_gguf.py, see the scratch script in the run log
python demo/cpu/merge_adapter.py Qwen/Qwen3.5-4B hf-base
python demo/cpu/merge_adapter.py Qwen/Qwen3.5-4B hf-tuned /data/runs/<run>/iter-<i>/adapter
python llama.cpp/convert_hf_to_gguf.py hf-base  --outtype q8_0 --no-mtp --outfile base.q8_0.gguf
python llama.cpp/convert_hf_to_gguf.py hf-tuned --outtype q8_0 --no-mtp --outfile <run>-iter-<i>.q8_0.gguf
```

## Score the GGUF build (anywhere with llama.cpp)

```bash
llama-server -m <model>.q8_0.gguf --port 8090 -np 4 -c 16384 --jinja &
python -m chirak_worker.cpu_predict eval --url http://127.0.0.1:8090 --eval-file <eval>.jsonl \
  --spec task_spec.json --label <name>.<eval> --out evals/
```

## Deploy

```bash
php artisan chirak:export-demo /tmp/chirak-demo <slug> [<slug> ...] --served=<slug>=<served name>
rsync -a /tmp/chirak-demo/ fleetq-01:/opt/chirak/deploy/demo/data/     # database.sqlite + storage/app/runs
rsync -a <models>/*.gguf fleetq-01:/opt/chirak/deploy/demo/models/
# on fleetq-01, in /opt/chirak (a checkout of this repo), deploy/demo/.env from .env.example:
docker compose -f deploy/demo/docker-compose.yml up -d --build
sudo docker network connect chirak_proxy nginx-proxy
sudo cp deploy/demo/chirak.fleetq.net.conf /var/docker/nginx/conf.d/
sudo docker exec nginx-proxy nginx -t && sudo docker exec nginx-proxy nginx -s reload
```

DNS: `chirak.fleetq.net` A 195.201.195.167, proxied by Cloudflare (same as the other fleetq.net hosts).

Limits: llama servers 2 CPUs and 6 GB each, predict 0.5 CPU, app 1 CPU. The playground is open without
the demo token (`CHIRAK_PLAYGROUND_PUBLIC=1`): 5 compares a minute per address, and 6 a minute for all
visitors without the token together (about what the two CPU servers answer); the token holder skips the
shared budget. Set `CHIRAK_PLAYGROUND_PUBLIC=0` to require the token again. The per-address limit sees the
visitor's address only because the shared nginx-proxy restores it from Cloudflare (`00-real-ip.conf`,
`real_ip_header CF-Connecting-IP`); without that every visitor behind one Cloudflare server would share
one bucket. One address can use 5 of the 6 shared compares a minute.
