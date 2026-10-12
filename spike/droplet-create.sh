#!/usr/bin/env bash
# Run locally. Creates the MI300X droplet on AMD Developer Cloud (DigitalOcean API).
# Needs: doctl, DIGITALOCEAN_ACCESS_TOKEN (AMD Developer Cloud team token), DROPLET_IMAGE, SSH key fingerprint.
# Billing: $2.59/h from creation until DESTROY. Power-off does not stop billing.
set -euo pipefail
: "${DIGITALOCEAN_ACCESS_TOKEN:?}" "${DROPLET_IMAGE:?find the slug with: doctl compute image list --public | grep -i rocm}" "${DROPLET_SSH_KEY:?fingerprint from doctl compute ssh-key list}"

NAME="${DROPLET_NAME:-chirak-gpu}"
doctl compute droplet create "$NAME" \
  --size gpu-mi300x1-192gb \
  --image "$DROPLET_IMAGE" \
  --region "${DROPLET_REGION:-atl1}" \
  --ssh-keys "$DROPLET_SSH_KEY" \
  --wait --format ID,Name,PublicIPv4,Status

echo "$(date -u +%FT%TZ) create $NAME" >> "$(dirname "$0")/gpu-hours.log"
