#!/usr/bin/env bash
# Boot-time start for UniSubmit (run by unisubmit.service).
set -euo pipefail
cd "$(dirname "$0")"
docker compose up -d --remove-orphans
