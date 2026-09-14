#!/usr/bin/env bash
set -euo pipefail

SITE="/etc/nginx/sites-available/yfs-ai"
BACKUP="/var/www/yfs-ai/voice-runtime/deploy/nginx-yfs-ai.before.conf"
SNIPPET="/var/www/yfs-ai/voice-runtime/deploy/nginx-voice-engine.location.conf"

if grep -q "location /voice-engine/" "$SITE"; then
  echo "nginx already has /voice-engine/"
  nginx -t
  exit 0
fi

cp -a "$SITE" "$BACKUP"

python3 - <<'PY'
from pathlib import Path
site = Path("/etc/nginx/sites-available/yfs-ai")
snippet = Path("/var/www/yfs-ai/voice-runtime/deploy/nginx-voice-engine.location.conf").read_text()
text = site.read_text()
needle = "    location / {\n"
if needle not in text:
    raise SystemExit("Could not find Laravel location / block")
site.write_text(text.replace(needle, snippet + "\n" + needle, 1))
PY

nginx -t
systemctl reload nginx
echo "nginx reloaded with /voice-engine/"
