# RunPod pod — create, reach, stop

The GPU side of Chirak runs in one RunPod pod. A pod is a container: whatever is not on
the network volume at `/data` is lost when the pod is terminated.

## Create the pod

1. **Network volume** (once): Secure Cloud → Storage → New Network Volume, in a datacenter
   that offers MI300X. ~100 GB is enough (HF cache ~70 GB for the 27B generator + 4B student,
   venv ~15 GB, run artifacts small). Volumes must be attached at deploy time.
2. **Pod**: Secure Cloud, GPU `AMD Instinct MI300X OAM` ×1, On-Demand.
   - Image: `vllm/vllm-openai-rocm:v0.31.0`
   - Network volume: the one above, mount path **`/data`**
   - Expose TCP ports: `22` — nothing else
   - Env: `PUBLIC_KEY` = your SSH public key
   - Container entrypoint: `bash -c` (the image's default entrypoint would start `vllm serve` immediately)
   - Start command:
     ```bash
     apt-get update; DEBIAN_FRONTEND=noninteractive apt-get install -y openssh-server rsync; mkdir -p ~/.ssh; chmod 700 ~/.ssh; echo "$PUBLIC_KEY" >> ~/.ssh/authorized_keys; chmod 600 ~/.ssh/authorized_keys; service ssh start; [ -x /data/start.sh ] && /data/start.sh; sleep infinity
     ```
   Same via REST: `POST https://rest.runpod.io/v1/pods` with `imageName`,
   `gpuTypeIds: ["AMD Instinct MI300X OAM"]`, `cloudType: "SECURE"`, `networkVolumeId`,
   `volumeMountPath: "/data"`, `ports: ["22/tcp"]`, `env`, `dockerEntrypoint: ["bash","-c"]`,
   `dockerStartCmd: ["<start command above>"]`.
3. **SSH**: Connect → "SSH over exposed TCP" gives `ssh root@<ip> -p <port>`. Use that one;
   the `ssh.runpod.io` proxy has no scp/rsync. The mapped port can change after a pod reset.

## Put the code on the pod

```bash
rsync -az -e "ssh -p $POD_SSH_PORT" --exclude .git ./ root@$POD_SSH_HOST:/data/chirak/   # includes .env; .env is never committed
ssh -p $POD_SSH_PORT root@$POD_SSH_HOST 'cd /data/chirak && spike/00-setup.sh && spike/run-all.sh'
```

`00-setup.sh` is idempotent: on a fresh pod with the same volume it finds `/data/venv-train` and skips the install.

## Reach the worker

The worker and vLLM listen on `127.0.0.1` only. From the control plane, open an SSH tunnel:

```bash
ssh -N -L 8100:127.0.0.1:8100 -p $POD_SSH_PORT root@$POD_SSH_HOST
# WORKER_BASE_URL=http://127.0.0.1:8100 (requests still carry the bearer token)
```

Do not expose worker ports through `https://<podid>-<port>.proxy.runpod.net`: that URL is
public to anyone who has it, and Cloudflare cuts requests at 100 s.

## Restart after a pod restart

The start command runs `/data/start.sh` when it exists. That script (added with the worker,
after CHECKPOINT 0) brings up vLLM and the FastAPI worker. Manual equivalents will be
documented here with it.

## Billing

- **Stop**: releases the GPU and stops GPU billing. The network volume persists and keeps
  billing (~$0.07/GB-month). The container disk is lost; `/data` is not.
- **Terminate**: deletes the pod. The network volume still persists and still bills until you
  delete the volume itself.
- Stop the pod whenever no job is running. Log every start/stop in `spike/gpu-hours.log`
  (`<UTC time> start|stop <pod id>`); burn = hours × the pod's $/h shown in the console.
