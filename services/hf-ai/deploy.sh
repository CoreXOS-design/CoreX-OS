#!/usr/bin/env bash
# Deploy / restore the HF AI service to /opt/hf-ai from this repo copy.
# Idempotent: safe to re-run. Run as root on the host.
#
#   sudo bash services/hf-ai/deploy.sh
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="/opt/hf-ai"
VENV="$DEST/.venv"

echo "==> Deploying hf-ai from $SRC -> $DEST"
mkdir -p "$DEST"
install -m 0644 "$SRC/app.py" "$DEST/app.py"
install -m 0644 "$SRC/requirements.txt" "$DEST/requirements.txt"

if [ ! -x "$VENV/bin/python" ]; then
  echo "==> Creating venv at $VENV"
  python3 -m venv "$VENV"
fi

echo "==> Installing dependencies (this downloads the Whisper model deps; first run is slow)"
"$VENV/bin/pip" install --upgrade pip wheel >/dev/null
"$VENV/bin/pip" install -r "$DEST/requirements.txt"

# Sync the unit file if it differs from the repo reference.
if ! cmp -s "$SRC/hf-ai.service" /etc/systemd/system/hf-ai.service 2>/dev/null; then
  echo "==> Updating /etc/systemd/system/hf-ai.service"
  install -m 0644 "$SRC/hf-ai.service" /etc/systemd/system/hf-ai.service
  systemctl daemon-reload
fi

echo "==> Restarting service"
systemctl enable hf-ai.service >/dev/null 2>&1 || true
systemctl restart hf-ai.service
sleep 3
systemctl --no-pager status hf-ai.service | head -n 12

echo "==> Health check"
curl -fsS http://127.0.0.1:3100/health && echo
echo "==> Done. Watch logs with: journalctl -u hf-ai.service -f"
