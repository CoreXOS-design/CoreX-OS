#!/usr/bin/env python3
"""AT-525 - speak text with Chatterbox (Resemble AI, MIT) in the voice of a consented reference clip. CPU only.
usage: chatterbox-tts.py --ref reference.wav --batch   (stdin = JSON [{"text": "...", "out": "/path.wav"}, ...]; the model loads ONCE for the whole batch)
       chatterbox-tts.py --ref reference.wav --out file.wav     (stdin = the text)
Prints one line per file: 'OK <out> <audio seconds> <work seconds>'."""
import argparse, json, sys, time
import torch, torchaudio
from chatterbox.tts import ChatterboxTTS

ap = argparse.ArgumentParser()
ap.add_argument('--ref', required=True); ap.add_argument('--out'); ap.add_argument('--batch', action='store_true')
ap.add_argument('--exaggeration', type=float, default=0.5); ap.add_argument('--cfg', type=float, default=0.5)
a = ap.parse_args()
jobs = json.load(sys.stdin) if a.batch else [{'text': sys.stdin.read().strip(), 'out': a.out}]
if not jobs or not all(j.get('text') and j.get('out') for j in jobs):
    sys.exit('nothing to say')
torch.set_num_threads(max(1, torch.get_num_threads()))
t0 = time.time()
model = ChatterboxTTS.from_pretrained(device='cpu')
print(f'LOADED {time.time() - t0:.1f}', flush=True)
for j in jobs:
    t = time.time()
    wav = model.generate(j['text'], audio_prompt_path=a.ref, exaggeration=a.exaggeration, cfg_weight=a.cfg)
    torchaudio.save(j['out'], wav, model.sr)
    print(f"OK {j['out']} {wav.shape[-1] / model.sr:.2f} {time.time() - t:.1f}", flush=True)
