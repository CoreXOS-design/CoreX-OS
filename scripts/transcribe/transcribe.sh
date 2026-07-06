#!/usr/bin/env bash
#
# CoreX — voice-note transcription worker (AT-163 Stage 2).
#
# Local, on-box transcription so client audio never leaves the box (POPIA).
# Decodes the WhatsApp .oga/.opus to 16 kHz mono PCM with ffmpeg, then runs
# whisper.cpp (multilingual, Afrikaans/English/mixed). Emits whisper's native
# JSON (result.language + transcription[].text) on stdout; the PHP
# TranscriptionService parses it. nice'd so it never starves the app.
#
# Usage: transcribe.sh <audio_path> [model] [threads] [language]
#   model    — medium (default) | large-v3 | small ...  (ggml-<model>.bin)
#   threads  — whisper thread count (default 8 = half the box's 16 cores)
#   language — whisper -l hint: auto (default, per-note detect) | af | en | ...
#
set -euo pipefail

AUDIO="${1:-}"
MODEL="${2:-medium}"
THREADS="${3:-8}"
# AT-194 — per-agency whisper language hint (arg 4). Default 'auto' = per-note
# auto-detect, so an existing 3-arg caller behaves EXACTLY as before (backward-compat).
LANG_HINT="${4:-auto}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WHISPER="$DIR/whisper.cpp/build/bin/whisper-cli"
MODELBIN="$DIR/models/ggml-${MODEL}.bin"

fail() { printf '{"error":%s}\n' "$1"; exit "${2:-1}"; }

[ -n "$AUDIO" ] && [ -f "$AUDIO" ] || fail '"audio_not_found"' 2
[ -x "$WHISPER" ] || fail '"whisper_binary_missing"' 3
[ -f "$MODELBIN" ] || fail '"model_missing"' 4
command -v ffmpeg >/dev/null 2>&1 || fail '"ffmpeg_missing"' 5

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
WAV="$TMP/audio.wav"
OUT="$TMP/out"

# Decode to the exact format whisper expects: 16 kHz, mono, signed 16-bit PCM.
ffmpeg -nostdin -loglevel error -y -i "$AUDIO" -ar 16000 -ac 1 -c:a pcm_s16le "$WAV" 2>/dev/null \
    || fail '"ffmpeg_decode_failed"' 6

# Transcribe. -l <hint> = language: 'auto' per-note detect (handles code-mixed at
# segment level) or a pinned language (e.g. 'af') which anchors the model and skips
# the detection pass; -nt = no inline timestamps in text; --output-json writes OUT.json.
nice -n 15 "$WHISPER" -m "$MODELBIN" -f "$WAV" -t "$THREADS" -l "$LANG_HINT" -nt \
    --output-json -of "$OUT" >/dev/null 2>&1 \
    || fail '"whisper_failed"' 7

[ -f "$OUT.json" ] || fail '"no_output"' 8
cat "$OUT.json"
