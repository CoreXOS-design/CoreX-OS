# HF AI service

Self-hosted FastAPI service powering **Ellie chat** and **Whisper voice transcription**
for CoreX OS. Runs on the production host at `127.0.0.1:3100` under systemd
(`hf-ai.service`), served by `uvicorn app:app`.

## Why this is in the repo

On 2026-06-18 the live `/opt/hf-ai` directory was found **completely missing** — the
service had been crash-looping (`status=203/EXEC`, 286k+ restarts), which took Ellie
**voice and chat** down across production, staging, and demo (all three installs default
`HF_AI_BASE_URL` to `127.0.0.1:3100`). The original code was never version-controlled and
was unrecoverable, so it was rebuilt from the Laravel-side contract and committed here.

**`/opt/hf-ai` is now a deploy target, not the source of truth. This folder is.**

## Endpoints (contract consumed by Laravel)

| Method | Path | Caller | Notes |
|---|---|---|---|
| GET | `/health` | monitoring | `{whisper, kb, chat_provider, chat_model, whisper_model}` |
| POST | `/chat` | `EllieController`, `AiChatProxyController` | JSON `{message, user, context, history, knowledge_context}` → `{reply, mode, sources}`. Runs on **Anthropic Claude**. |
| POST | `/transcribe` | `App\Services\AI\SpeechToTextService` | multipart `audio` + tuning fields → `{text, language, duration_seconds, elapsed_ms}`. Runs on **self-hosted Whisper**. |

Ellie chat runs on **Anthropic Claude** (`ANTHROPIC_MODEL`, default `claude-haiku-4-5` —
the cheapest Claude, matching the original setup and the CoreX "latest Claude" standard).
Transcription stays on self-hosted Whisper: POPIA requires audio never leave the box, and
Anthropic has no speech-to-text. RAG/knowledge search runs in Laravel
(`KnowledgeSearchService`) and is passed to `/chat` as `knowledge_context` — the service
injects it into the system prompt.

Requires `ANTHROPIC_API_KEY` in `/etc/hf-ai/openai.env` (the systemd `EnvironmentFile`) —
use the same key the prod/staging Laravel `.env` already carries.

## Listening sensitivity

`/transcribe` accepts `vad_filter`, `no_speech_threshold`, `log_prob_threshold`,
`initial_prompt`. Laravel sends them from `config/services.php` → `hf_ai` (overridable via
`AI_VOICE_*` env). Defaults are deliberately **sensitive** (`vad_filter=false`,
`no_speech_threshold=0.3`) so soft / accented / in-car field audio isn't discarded.
If hallucination-on-silence appears, raise the threshold toward 0.5 in CoreX `.env`.

## Deploy / restore

```bash
# From a repo checkout on the host:
sudo bash services/hf-ai/deploy.sh
```

Requires `/etc/hf-ai/openai.env` with `OPENAI_API_KEY` and `OPENAI_MODEL` (already present
on the host). First run downloads the faster-whisper `small.en` model (~150MB) on first
transcription. Python 3.12, CPU-only, int8 (~1GB RAM).
