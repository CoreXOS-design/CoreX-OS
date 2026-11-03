#!/usr/bin/env python3
"""AT-525 - speak text with Kokoro-82M (Apache-2.0, kokoro-onnx on CPU). Text on stdin, a 24 kHz mono wav out.
usage: kokoro-tts.py --model kokoro-v1.0.onnx --voices voices-v1.0.bin --voice bf_emma [--lang en-gb] [--speed 1.0] --out file.wav"""
import argparse, sys
import soundfile as sf
from kokoro_onnx import Kokoro

ap = argparse.ArgumentParser()
ap.add_argument('--model', required=True); ap.add_argument('--voices', required=True)
ap.add_argument('--voice', required=True); ap.add_argument('--lang', default=None)
ap.add_argument('--speed', type=float, default=1.0); ap.add_argument('--out', required=True)
a = ap.parse_args()
text = sys.stdin.read().strip()
if not text:
    sys.exit('no text')
lang = a.lang or ('en-gb' if a.voice.startswith('b') else 'en-us')
k = Kokoro(a.model, a.voices)
samples, rate = k.create(text, voice=a.voice, speed=a.speed, lang=lang)
sf.write(a.out, samples, rate)
