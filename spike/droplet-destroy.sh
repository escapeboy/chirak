#!/usr/bin/env bash
# Run locally. Pulls spike results and run artifacts back, then destroys the droplet.
set -euo pipefail
: "${DIGITALOCEAN_ACCESS_TOKEN:?}" "${DROPLET_HOST:?}"
NAME="${DROPLET_NAME:-chirak-gpu}"
HERE="$(dirname "$0")"

mkdir -p "$HERE/out"
rsync -az "${DROPLET_USER:-root}@${DROPLET_HOST}:/root/chirak/spike/out/" "$HERE/out/" || echo "warn: could not copy spike/out"

doctl compute droplet list --format ID,Name,PublicIPv4,Status | grep -w "$NAME"
read -r -p "Destroy droplet $NAME? [y/N] " ok
[ "$ok" = "y" ] || exit 1
doctl compute droplet delete "$NAME" --force
echo "$(date -u +%FT%TZ) destroy $NAME" >> "$HERE/gpu-hours.log"
