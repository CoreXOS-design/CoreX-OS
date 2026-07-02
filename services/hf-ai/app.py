"""
HF AI service — Ellie chat + Whisper transcription.

Self-hosted FastAPI app served by `uvicorn app:app` on 127.0.0.1:3100.
Consumed by CoreX (Laravel) at:
  - POST /chat       (EllieController, AiChatProxyController)  -> Anthropic Claude
  - POST /transcribe (App\\Services\\AI\\SpeechToTextService)   -> self-hosted Whisper
  - GET  /health     (monitoring)

IMPORTANT — this file is the SINGLE SOURCE OF TRUTH for the service and lives in
the repo at services/hf-ai/app.py. It was rebuilt on 2026-06-18 after the live
/opt/hf-ai directory was found completely missing (the service had been crash-
looping with 203/EXEC, taking Ellie voice AND chat down across prod/staging/demo).
The original was never version-controlled and was unrecoverable. Do NOT let the
runtime copy at /opt/hf-ai drift from this file again — deploy from here.

Ellie chat runs on Anthropic Claude (the CoreX standard — latest/cheapest Claude),
matching the original working setup. Transcription stays on self-hosted Whisper
(POPIA: audio never leaves the box; Anthropic has no speech-to-text).

Environment (systemd EnvironmentFile=/etc/hf-ai/openai.env + unit Environment=):
  ANTHROPIC_API_KEY    required for /chat (same key the prod/staging Laravel uses)
  ANTHROPIC_MODEL      chat model (default: claude-haiku-4-5 — cheapest Claude)
  WHISPER_MODEL        faster-whisper model (default: small.en)
  WHISPER_MAX_SECONDS  reject clips longer than this (default: 30)
"""

import os
import re
import sys
import time
import tempfile

import anthropic
from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

# --- Config -----------------------------------------------------------------

ANTHROPIC_API_KEY   = os.environ.get("ANTHROPIC_API_KEY", "").strip()
ANTHROPIC_MODEL     = os.environ.get("ANTHROPIC_MODEL", "claude-haiku-4-5").strip() or "claude-haiku-4-5"
WHISPER_MODEL_NAME  = os.environ.get("WHISPER_MODEL", "small.en").strip() or "small.en"
WHISPER_MAX_SECONDS = float(os.environ.get("WHISPER_MAX_SECONDS", "30") or 30)

# Ellie persona. Reconstructed to match the CoreX "Ellie advises, humans decide"
# principle (.ai/specs/ellie.md). Tune freely — it only shapes /chat replies.
SYSTEM_PROMPT = (
    "You are Ellie, the AI assistant inside CoreX OS — the operating system for "
    "Home Finders Coastal, a real estate agency on the KZN South Coast, South Africa. "
    "You help estate agents with properties, contacts, deals, compliance (PPRA, FICA, "
    "POPIA), and day-to-day admin. Be concise, practical, and professional. "
    "Use South African context: currency is ZAR (format like 'R 1,250,000'), the "
    "regulator is the PPRA (never the EAAB). "
    "You ADVISE — humans decide. Never claim to have taken an action you cannot take. "
    "If you are unsure or lack data, say so plainly rather than inventing detail. "
    "Reply in PLAIN TEXT only — the chat does not render Markdown. Do NOT use asterisks "
    "for bold or italics, do not use backticks, and do not use '#' headings. For lists, "
    "start each line with a hyphen and a space, or use plain '1.' numbering. Write links "
    "as the bare path, e.g. /corex/properties. "
    "When navigation excerpts describe how to reach or do something, follow them EXACTLY. "
    "Do NOT invent buttons, pages, menu items, or steps that are not stated in the "
    "provided context. If a task is done from one place, give only that place."
)

# --- Anthropic client (lazy singleton) --------------------------------------

_anthropic_client = None


def get_anthropic():
    global _anthropic_client
    if _anthropic_client is None and ANTHROPIC_API_KEY:
        _anthropic_client = anthropic.AsyncAnthropic(api_key=ANTHROPIC_API_KEY)
    return _anthropic_client


# --- Whisper model (lazy-loaded, kept warm) ---------------------------------

_whisper_model = None
_whisper_error = None


def get_whisper():
    """Load the faster-whisper model once and keep it resident."""
    global _whisper_model, _whisper_error
    if _whisper_model is not None or _whisper_error is not None:
        return _whisper_model
    try:
        from faster_whisper import WhisperModel
        # int8 on CPU keeps RAM ~1GB on the 4GB box.
        _whisper_model = WhisperModel(WHISPER_MODEL_NAME, device="cpu", compute_type="int8")
        print(f"[hf-ai] Whisper '{WHISPER_MODEL_NAME}' loaded (cpu/int8)", file=sys.stderr, flush=True)
    except Exception as e:  # noqa: BLE001
        _whisper_error = str(e)
        print(f"[hf-ai] Whisper load FAILED: {e}", file=sys.stderr, flush=True)
    return _whisper_model


app = FastAPI(title="HF AI service", version="2026-06-18")


@app.on_event("startup")
def _warm():
    # Warm the model at boot so the first agent doesn't eat the load latency.
    get_whisper()


# --- Health -----------------------------------------------------------------

@app.get("/health")
def health():
    model = get_whisper()
    return {
        "whisper": "ready" if model is not None else ("error: " + (_whisper_error or "loading")),
        "kb": "ready",  # KB/RAG runs in Laravel (KnowledgeSearchService); kept for contract compatibility.
        "chat_provider": "anthropic" if ANTHROPIC_API_KEY else "unconfigured",
        "chat_model": ANTHROPIC_MODEL,
        "whisper_model": WHISPER_MODEL_NAME,
    }


# --- Helpers ----------------------------------------------------------------

def _strip_markdown(text: str) -> str:
    """
    The CoreX chat UI renders replies as plain text, so Markdown syntax shows up
    literally (**bold**, `code`, ### headings). The system prompt asks the model
    to avoid it, but that is not guaranteed — so we strip it here as a hard
    backstop. Deliberately conservative: only unwrap the constructs that actually
    leak (bold, inline code, headings), leaving ordinary text untouched.
    """
    if not text:
        return text
    # **bold** / __bold__ -> bold
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text, flags=re.S)
    text = re.sub(r"__(.+?)__", r"\1", text, flags=re.S)
    # `inline code` -> inline code
    text = re.sub(r"`([^`]+)`", r"\1", text)
    # leading #, ##, ### headings -> plain line
    text = re.sub(r"(?m)^\s{0,3}#{1,6}\s*", "", text)
    return text.strip()


def _coerce_history(history):
    """
    Laravel may send history as a list of {role, content}. Anthropic requires
    user/assistant only (system is a separate top-level param), the first turn
    to be 'user', and no empty content. Normalise defensively.
    """
    out = []
    if isinstance(history, list):
        for item in history:
            if not isinstance(item, dict):
                continue
            role = str(item.get("role", "")).lower()
            if role not in ("user", "assistant"):
                continue  # drop 'system' / unknown — system goes top-level
            content = str(item.get("content", "")).strip()
            if content:
                out.append({"role": role, "content": content})
    # Anthropic: first message must be 'user' — drop any leading assistant turns.
    while out and out[0]["role"] == "assistant":
        out.pop(0)
    return out[-12:]  # cap context window — last 12 turns is plenty.


# --- Chat (Anthropic Claude) ------------------------------------------------

@app.post("/chat")
async def chat(payload: dict):
    message = str((payload or {}).get("message", "")).strip()
    if not message:
        return JSONResponse({"reply": "", "mode": "error", "sources": []}, status_code=422)

    client = get_anthropic()
    if client is None:
        print("[hf-ai] /chat called but ANTHROPIC_API_KEY is empty", file=sys.stderr, flush=True)
        return {"reply": "Ellie is not configured (missing API key).", "mode": "error", "sources": []}

    knowledge_context = str((payload or {}).get("knowledge_context", "")).strip()
    context = (payload or {}).get("context")
    history = _coerce_history((payload or {}).get("history"))

    system = SYSTEM_PROMPT
    if context:
        system += f"\n\nCurrent app context:\n{context}"
    if knowledge_context:
        system += (
            "\n\nRelevant knowledge-base excerpts (use them when answering; "
            "do not fabricate beyond them). When an excerpt is a CoreX navigation "
            "entry or includes a 'Direct link:' URL, tell the user exactly where "
            "to go and give them that link verbatim:\n" + knowledge_context
        )

    messages = history + [{"role": "user", "content": message}]

    try:
        resp = await client.messages.create(
            model=ANTHROPIC_MODEL,
            max_tokens=1024,
            system=system,
            messages=messages,
        )
        reply = "".join(
            block.text for block in resp.content if getattr(block, "type", None) == "text"
        ).strip()
        reply = _strip_markdown(reply)
        if not reply:
            reply = "Sorry, I could not respond."
        return {"reply": reply, "mode": "kb" if knowledge_context else "chat", "sources": []}
    except Exception as e:  # noqa: BLE001
        print(f"[hf-ai] /chat exception: {e}", file=sys.stderr, flush=True)
        return {"reply": "Sorry, I hit an error. Please try again.", "mode": "error", "sources": []}


# --- Transcribe (self-hosted Whisper) ---------------------------------------

def _as_bool(v, default=False):
    if v is None:
        return default
    return str(v).strip().lower() in ("1", "true", "yes", "on")


def _as_float(v, default):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


@app.post("/transcribe")
def transcribe(
    audio: UploadFile = File(...),
    # Sensitivity knobs forwarded by SpeechToTextService. Defaults here are the
    # SENSITIVE values — agents record in moving cars / wind / soft speech, and
    # faster-whisper's stock VAD + 0.6 no-speech threshold was discarding whole
    # clips, producing "I didn't catch that". See .ai/specs/ellie-voice.md.
    vad_filter: str = Form(None),
    no_speech_threshold: str = Form(None),
    log_prob_threshold: str = Form(None),
    initial_prompt: str = Form(None),
):
    model = get_whisper()
    if model is None:
        return JSONResponse({"error": "Whisper not loaded: " + (_whisper_error or "unknown")},
                            status_code=503)

    use_vad = _as_bool(vad_filter, False)
    nst = _as_float(no_speech_threshold, 0.3)
    lpt = _as_float(log_prob_threshold, -1.0)
    iprompt = (initial_prompt or "").strip() or None

    suffix = os.path.splitext(audio.filename or "")[1] or ".webm"
    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=suffix)
    try:
        tmp.write(audio.file.read())
        tmp.flush()
        tmp.close()

        t0 = time.time()
        segments, info = model.transcribe(
            tmp.name,
            language="en",
            beam_size=5,
            vad_filter=use_vad,
            vad_parameters=dict(threshold=0.2, min_silence_duration_ms=500, speech_pad_ms=400),
            no_speech_threshold=nst,
            log_prob_threshold=lpt,
            condition_on_previous_text=False,   # stop prior-clip text bleeding in
            initial_prompt=iprompt,
            temperature=[0.0, 0.2, 0.4, 0.6],   # fallback temps recover hard clips
        )
        text = " ".join(seg.text for seg in segments).strip()
        elapsed_ms = int((time.time() - t0) * 1000)
        duration = round(float(getattr(info, "duration", 0.0)), 2)

        if duration > WHISPER_MAX_SECONDS:
            return JSONResponse(
                {"error": f"Audio clip exceeds {int(WHISPER_MAX_SECONDS)}s limit."},
                status_code=422,
            )

        return {
            "text": text,
            "language": getattr(info, "language", "en"),
            "duration_seconds": duration,
            "elapsed_ms": elapsed_ms,
        }
    except Exception as e:  # noqa: BLE001
        print(f"[hf-ai] /transcribe exception: {e}", file=sys.stderr, flush=True)
        return JSONResponse({"error": f"Transcription failed: {e}"}, status_code=422)
    finally:
        try:
            os.unlink(tmp.name)
        except OSError:
            pass
