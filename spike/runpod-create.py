#!/usr/bin/env python3
"""Create a RunPod pod for Chirak: vLLM ROCm image, bash entrypoint, sshd, port 22 only.

    RUNPOD_API_KEY=... python3 spike/runpod-create.py [--gpu "AMD Instinct MI350 OAM"] [--template ID]

The REST API's gpuTypeIds enum lacks MI350X, so the pod is created through GraphQL
(podFindAndDeployOnDemand). GraphQL cannot override the image entrypoint (vLLM's image starts
`vllm serve`), so the entrypoint lives in a private template, created here when --template is absent.
Prints the pod id; read the SSH host/port from the RunPod console or GraphQL `pod { runtime { ports } }`.
"""
import argparse
import json
import os
import urllib.error
import urllib.request

START = ('apt-get update; DEBIAN_FRONTEND=noninteractive apt-get install -y openssh-server rsync; mkdir -p ~/.ssh; '
         'chmod 700 ~/.ssh; echo "$PUBLIC_KEY" >> ~/.ssh/authorized_keys; chmod 600 ~/.ssh/authorized_keys; '
         'service ssh start; [ -x /data/start.sh ] && /data/start.sh; sleep infinity')
UA = {"User-Agent": "curl/8.7.1", "Content-Type": "application/json"}  # Cloudflare rejects urllib's default UA


def call(url: str, body: dict, headers: dict) -> dict:
    req = urllib.request.Request(url, json.dumps(body).encode(), {**UA, **headers})
    try:
        return json.loads(urllib.request.urlopen(req).read())
    except urllib.error.HTTPError as e:
        raise SystemExit(f"HTTP {e.code}: {e.read().decode()[:500]}")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--gpu", default="AMD Instinct MI350 OAM")
    ap.add_argument("--template")
    ap.add_argument("--volume-gb", type=int, default=300)
    ap.add_argument("--pubkey", default=os.path.expanduser("~/.ssh/id_ed25519.pub"))
    a = ap.parse_args()
    key = os.environ["RUNPOD_API_KEY"]

    template = a.template
    if not template:
        t = call("https://rest.runpod.io/v1/templates", {
            "name": "chirak-vllm-rocm-ssh", "imageName": "vllm/vllm-openai-rocm:v0.31.0", "isPublic": False,
            "dockerEntrypoint": ["bash", "-c"], "dockerStartCmd": [START],
            "env": {"PUBLIC_KEY": open(a.pubkey).read().strip()}, "ports": ["22/tcp"],
            "containerDiskInGb": 100, "volumeInGb": a.volume_gb, "volumeMountPath": "/data",
        }, {"Authorization": f"Bearer {key}"})
        template = t["id"]
        print("template", template)

    inp = {"name": "chirak", "templateId": template, "gpuTypeId": a.gpu, "gpuCount": 1, "cloudType": "SECURE",
           "volumeInGb": a.volume_gb, "volumeMountPath": "/data", "containerDiskInGb": 100, "ports": "22/tcp",
           "supportPublicIp": True}
    q = ("mutation($i: PodFindAndDeployOnDemandInput) { podFindAndDeployOnDemand(input: $i) "
         "{ id desiredStatus costPerHr machine { gpuDisplayName location } } }")
    print(json.dumps(call(f"https://api.runpod.io/graphql?api_key={key}", {"query": q, "variables": {"i": inp}}, {})))


if __name__ == "__main__":
    main()
