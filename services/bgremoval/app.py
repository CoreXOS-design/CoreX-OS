"""
CoreX background-removal service — self-hosted AI segmentation (rembg /
u2net_human_seg), replacing the paid-API build from an earlier round of this
same feature. Photoroom/remove.bg drivers remain in the Laravel app, unused,
selectable via BG_REMOVAL_DRIVER as a config-only fallback if this service
or model ever needs bypassing.

Self-hosted FastAPI app served by `uvicorn app:app` on 127.0.0.1:{PORT}.
Consumed by CoreX (Laravel) App\\Services\\Images\\BackgroundRemoval\\RembgDriver.

IMPORTANT — this file is the SINGLE SOURCE OF TRUTH and lives in the repo at
services/bgremoval/app.py (mirrors services/hf-ai/app.py's own hard lesson:
the /opt/hf-ai runtime copy was found completely missing once because it was
never version-controlled — see that file's docstring). Do NOT let the runtime
copy at /mnt/HC_Volume_103099143/corex-bgremoval-svc drift from this file —
deploy from here.

The model is loaded ONCE at process startup, not per request — the volume
spike that justified this build measured ~0.7s of a photo's ~1.1s total cost
as model load; a fresh process per call would re-pay that every time. A
persistent service pays it once per process lifetime.

Environment:
  BGREMOVAL_MODEL   rembg model name (default: u2net_human_seg)
  U2NET_HOME        model cache dir — MUST be set (the systemd unit points it
                     at the volume) or rembg defaults to ~/.u2net, which would
                     put a ~168MB model file on the root disk.
"""

import io
import os
import time

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse, Response
from PIL import Image
from rembg import new_session, remove

MODEL_NAME = os.environ.get("BGREMOVAL_MODEL", "u2net_human_seg").strip() or "u2net_human_seg"

app = FastAPI()

_session = None
_model_load_seconds = None
_requests_served = 0


@app.on_event("startup")
def load_model():
    global _session, _model_load_seconds
    t0 = time.time()
    _session = new_session(MODEL_NAME)
    _model_load_seconds = time.time() - t0


@app.get("/health")
def health():
    return {
        "ok": _session is not None,
        "model": MODEL_NAME,
        "model_load_seconds": _model_load_seconds,
        "requests_served": _requests_served,
    }


@app.post("/remove-background")
async def remove_background(image: UploadFile = File(...)):
    global _requests_served

    if _session is None:
        return JSONResponse(status_code=503, content={"error": "model not loaded"})

    try:
        raw = await image.read()
        img = Image.open(io.BytesIO(raw)).convert("RGB")
    except Exception as e:
        return JSONResponse(status_code=422, content={"error": f"unreadable image: {e}"})

    try:
        cutout = remove(img, session=_session)
    except Exception as e:
        return JSONResponse(status_code=500, content={"error": f"segmentation failed: {e}"})

    _requests_served += 1

    buf = io.BytesIO()
    cutout.save(buf, format="PNG")
    return Response(content=buf.getvalue(), media_type="image/png")
