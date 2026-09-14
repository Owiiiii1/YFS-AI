#!/usr/bin/env bash
set -euo pipefail

SRC="/var/www/yfs-ai/voice-runtime/deploy/yfs-voice-runtime.service"
DEST="/etc/systemd/system/yfs-voice-runtime.service"

if [[ ! -f /var/www/yfs-ai/voice-runtime/dist/index.js ]]; then
  echo "Build first: npm run build" >&2
  exit 1
fi

install -m 644 "$SRC" "$DEST"
systemctl daemon-reload
systemctl enable yfs-voice-runtime.service
systemctl restart yfs-voice-runtime.service
systemctl --no-pager --full status yfs-voice-runtime.service
