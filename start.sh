#!/usr/bin/env bash
# Boot-time start for UniSubmit (run by unisubmit.service).
# The Windows host (where Ollama runs) is this VM's default gateway, and its address can change
# after a reboot, so write it to .env before bringing the stack up.
set -euo pipefail
cd "$(dirname "$0")"

for i in $(seq 1 30); do
    GW=$(ip route | awk '/^default/ {print $3; exit}')
    [ -n "$GW" ] && break
    sleep 2
done
echo "OLLAMA_HOST=http://${GW}:11434" > .env
echo "UniSubmit: Ollama host is http://${GW}:11434"

docker compose up -d --remove-orphans
