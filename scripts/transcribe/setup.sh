#!/usr/bin/env bash
#
# AT-163 — one-time on-box setup for CoreX voice-note transcription (whisper.cpp).
# Idempotent-ish; run on the staging/live host. Requires: git, cmake, make, a C/C++
# compiler, ffmpeg, curl. Installs to /opt/corex-transcribe (override with $DEST).
#
set -euo pipefail
DEST="${DEST:-/opt/corex-transcribe}"
MODELS=("${@:-medium}")   # models to fetch, e.g. ./setup.sh medium large-v3

mkdir -p "$DEST/models"
cd "$DEST"

if [ ! -d whisper.cpp ]; then
    git clone --depth 1 https://github.com/ggerganov/whisper.cpp.git
fi
cd whisper.cpp
cmake -B build -DCMAKE_BUILD_TYPE=Release -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_EXAMPLES=ON
cmake --build build --config Release -j "$(( $(nproc) - 2 ))"

for m in "${MODELS[@]}"; do
    f="$DEST/models/ggml-${m}.bin"
    [ -f "$f" ] || curl -sL --retry 3 -o "$f" "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-${m}.bin"
    echo "model ${m}: $(du -h "$f" | cut -f1)"
done

# The worker wrapper (ships in the repo next to this script).
cp "$(dirname "$0")/transcribe.sh" "$DEST/transcribe.sh"
chmod +x "$DEST/transcribe.sh"
echo "CoreX transcription ready at $DEST — set COREX_TRANSCRIBE_BIN=$DEST/transcribe.sh if non-default."
