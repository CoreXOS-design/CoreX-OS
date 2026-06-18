# Spec: Ellie Voice — Mobile Voice Commands

**Status:** Draft — awaiting approval
**Author:** Andre (drafted via Claude)
**Date:** 2026-05-28

---

## What this feature does and why

Lets an agent on mobile press a mic button (Phase 1) or say "Hey Ellie" (Phase 2) and issue a natural-language command — most commonly "schedule a viewing at 11 tomorrow with John Smith at 12 Marine Drive". Ellie transcribes, extracts intent + slots, performs the action (calendar event creation in Phase 1), and **tags the result as AI-created** so the agent has a clear visual signal.

**Business reason:** Agents drive between viewings. Typing into a calendar form on a phone is friction; voice-to-action removes it. POPIA requires voice data stays on-shore — hence self-hosted Whisper, not OpenAI API.

---

## Pillar connections

| Pillar | Read | Write |
|---|---|---|
| Contact | Match name from voice → existing Contact for linking | — |
| Property | Match address from voice → existing Property for linking | — |
| Agent (User) | Owner of the created event = authenticated user | — |
| (Calendar is cross-pillar) | — | Creates `calendar_events` row with `created_by_ai = true` |

---

## Architecture

```
Mobile (PWA)  ──[audio blob]──►  Laravel /api/mobile/ellie/voice
                                       │
                                       ├──► POST hf-ai:3100/transcribe   (faster-whisper, small.en)
                                       │       └── returns transcript
                                       │
                                       ├──► Claude Haiku 4.5 (intent extraction)
                                       │       system prompt: "extract {intent, datetime, contact_name, property_ref, title, notes}"
                                       │       returns structured JSON
                                       │
                                       ├──► IntentDispatcher
                                       │       intent=schedule_event → CalendarEventService::createManual(..., source='voice', created_by_ai=true)
                                       │       intent=other          → fallback to existing Ellie chat
                                       │
                                       └──► Response: { transcript, action, event_id?, confirmation_text }
```

---

## Data model / migrations

**Migration 1 — `add_ai_attribution_to_calendar_events`:**
- `created_by_ai` boolean default false
- `ai_source` string nullable (`'ellie_voice'`, `'ellie_chat'`, future)
- `ai_transcript` text nullable (raw voice transcript for audit)

No new tables. Existing `calendar_events.event_type='manual'` is reused.

---

## Self-hosted Whisper (server side)

- Extend `/opt/hf-ai/app.py` with a `POST /transcribe` endpoint
- Model: `faster-whisper` `small.en` (good for SA-accented English; CPU-only ~1-2s per 10s clip)
- Audio: accepts `audio/webm`, `audio/mp4`, `audio/wav`; max 30s clip enforced server-side
- Model loaded once at service start (kept warm in RAM ~1GB)
- Health endpoint: `GET /health` returns `{whisper: 'ready', kb: 'ready'}`
- `systemd` unit already exists (`hf-ai.service`) — only the Python code changes; restart manually after deploy per existing convention

---

## UI placement and navigation entry

- **Mobile shell:** PWA (existing Blade + mobile-responsive). Recommended over native — no app-store friction, mic/camera work in modern mobile Chrome/Safari, lowest lift.
- **Mic button:** floating action button (bottom-right) on all mobile-mode pages, behind the `access_ellie` permission. Long-press to record, release to send.
- **Result UI:** an Ellie message bubble appears showing transcript + action card ("✓ Scheduled — Viewing with John Smith, Thu 28 May 11:00 — [Open] [Undo]"). The created `calendar_events` row shows a small **"AI" badge** wherever it's rendered (calendar grid, day list, command center).
- **Visual badge:** small purple chip with bot icon next to event title. Component: `resources/views/components/ai-badge.blade.php` (new).
- **Sidebar entry:** none — voice is invoked from the FAB, no dedicated page. Calendar entries appear in the existing calendar view with the AI badge.

---

## User flow (Phase 1 — push-to-talk)

1. Agent on mobile, anywhere in CoreX, taps & holds the floating mic FAB.
2. Browser requests mic permission (first time only); recording starts; FAB shows pulsing red dot.
3. Agent says: *"Schedule a viewing tomorrow at 11 with John Smith at 12 Marine Drive."*
4. Agent releases FAB; UI shows "Listening…" → "Thinking…".
5. Audio POSTed to `/api/mobile/ellie/voice` as `audio/webm`.
6. Backend:
   a. Sends to local Whisper → `"Schedule a viewing tomorrow at 11 with John Smith at 12 Marine Drive."`
   b. Sends to Claude Haiku 4.5 with the intent-extraction prompt → `{intent:'schedule_event', datetime:'2026-05-29T11:00:00+02:00', contact_name:'John Smith', property_ref:'12 Marine Drive', title:'Viewing'}`
   c. Resolves contact + property via existing search services (best match, agency-scoped).
   d. Creates `calendar_events` row with `created_by_ai=true, ai_source='ellie_voice', ai_transcript=<raw>`.
7. Response → mobile shows action card with AI badge and Undo button (soft-deletes the event within 30s).
8. Event appears on the agent's calendar with the AI badge persistent.

## User flow (Phase 2 — wake word)

Same flow, but FAB is replaced by background listening (on-device Porcupine wake-word model triggered by "Hey Ellie"). **Out of scope for Phase 1 build**, but data model + endpoint are designed to support it without breaking changes.

---

## Permissions

- New permission key: `use_ellie_voice` in `CoreXPermissionSeeder.php`
- Default-granted to roles that already have `access_ellie`
- Sidebar/FAB gate: `@can('use_ellie_voice')`
- Route middleware: `can:use_ellie_voice`
- Controller checks: `$this->authorize('use_ellie_voice')`

---

## Ellie principle exception (REQUIRES amendment to `.ai/specs/ellie.md`)

Current principle: *"Ellie advises, humans decide — non-negotiable."*

This spec proposes an **explicit, narrow exception**:

> Ellie MAY perform reversible, soft, audit-tagged actions on the user's own data (their calendar, their notes) when explicitly invoked via voice. Each such action MUST:
> 1. Be created with `created_by_ai = true` and visible AI badge.
> 2. Be soft-deletable / undoable within 30 seconds of creation via inline Undo.
> 3. Record the raw transcript on the entity (`ai_transcript`).
> 4. Never affect other users' data, deals, money, compliance state, or documents.

The principle stays intact for the high-stakes pillars (Deals, Compliance, Documents). Calendar entries are low-stakes scratchpad data — auto-create is appropriate. This amendment must land in `.ai/specs/ellie.md` as part of this build.

---

## Acceptance criteria

1. Local Whisper endpoint responds in ≤ 2s for a 10s clip on the production server.
2. Agent can record a voice command on mobile Chrome (Android) and mobile Safari (iOS) and see a calendar event created in ≤ 5s end-to-end.
3. Created event carries an AI badge in: month view, week view, day view, dashboard upcoming list, command center event list.
4. Undo within 30s removes the event (soft delete) and shows confirmation toast.
5. If intent extraction fails or confidence is low, no event is created; Ellie replies in chat asking for clarification.
6. POPIA: no voice audio is sent to any third-party service. Transcripts are stored only on `calendar_events.ai_transcript` and purged with the event.
7. Permission `use_ellie_voice` gates FAB visibility, route access, and controller action.
8. Multi-tenancy: created events are agency-scoped via existing `BelongsToAgency`.
9. `scripts/dev-check.ps1` passes with 0 new failures.

---

## Files to create or modify

### New
- `database/migrations/YYYY_MM_DD_add_ai_attribution_to_calendar_events.php`
- `app/Services/AI/SpeechToTextService.php` — wraps `POST hf-ai:3100/transcribe`
- `app/Services/AI/IntentExtractionService.php` — wraps Claude Haiku 4.5 intent prompt
- `app/Services/AI/Intents/ScheduleEventIntentHandler.php` — calls `CalendarEventService::createManual()` with AI flags
- `app/Http/Controllers/Api/MobileEllieVoiceController.php` — `POST /api/mobile/ellie/voice`
- `resources/views/components/ai-badge.blade.php`
- `resources/js/mobile/voice-fab.js` — push-to-talk FAB, MediaRecorder
- `/opt/hf-ai/app.py` — add `/transcribe` route (server-side, not in repo)

### Modify
- `app/Models/CalendarEvent.php` — `$fillable` adds `created_by_ai`, `ai_source`, `ai_transcript`; cast `created_by_ai` to boolean
- `app/Services/CalendarEventService.php` — `createManual()` accepts optional AI metadata
- `resources/views/corex/calendar/*` — render AI badge where events are listed (5-6 view files)
- `resources/views/layouts/corex-app.blade.php` — mount the mobile voice FAB component
- `database/seeders/CoreXPermissionSeeder.php` — add `use_ellie_voice`
- `routes/api.php` — register mobile voice route under `/api/mobile/ellie/voice` (sanctum-protected)
- `.ai/specs/ellie.md` — append the principle-exception amendment above

---

## Out of scope (Phase 2+)

- Wake-word "Hey Ellie" (Porcupine on-device model)
- Voice-triggered note creation, task creation, contact creation
- Multi-turn voice dialogues
- Bilingual support (Afrikaans, Zulu) — Whisper supports both; deferred until volume justifies tuning
- Voice replies (TTS) — Ellie currently replies in text only

---

## Known issues / follow-ups

### Timezone — backend (FIXED 2026-06-10)
Incident: a "tomorrow at 11" command (event 5741) was stored at 09:00. Root
cause: the LLM expresses the time as UTC ("09:00Z" == 11:00 SAST), and Eloquent
persists a Carbon's own wall-clock without converting, so the time landed two
hours early. Fix: `ScheduleEventIntentHandler` now `setTimezone(config('app.timezone'))`
on the parsed datetime, and the extraction prompt is hardened to always emit a
"+02:00" offset (never "Z") plus an `understood_time` echo. Regression guard:
`tests/Feature/AI/EllieVoiceTimezoneTest.php`. Per-request diagnostics
(`transcript`, `model_raw`, `slot_datetime`, `event_date`) are logged from
`MobileEllieVoiceController` for future incidents.

### Listening sensitivity — empty transcripts (FIXED 2026-06-18)
Incident: agents reported Ellie frequently replying *"I didn't catch that — please
try again"* even when they spoke clearly. Root cause: that message fires in
`MobileEllieVoiceController` only when Whisper returns an **empty transcript**, and
faster-whisper's stock VAD (`vad_filter=True`) plus the default `no_speech_threshold`
(0.6) were discarding entire clips of soft / SA-accented / in-car field audio before
transcription. The Laravel side was a pure pass-through and sent no tuning.

Fix (this repo): `config/services.php` → `hf_ai` now carries sensitivity knobs
(`vad_filter` default **false**, `no_speech_threshold` default **0.3**,
`log_prob_threshold`, `initial_prompt`), env-overridable via `AI_VOICE_*`.
`SpeechToTextService` forwards them as multipart form fields to `/transcribe`.

Fix (server, NOT in this repo): `/opt/hf-ai/app.py`'s `/transcribe` route must read
those form fields and pass them into `model.transcribe(...)`, defaulting to the
sensitive values if absent. Deploy = edit app.py + `systemctl restart hf-ai`.

Fix (mobile, NOT in this repo): the PWA recorder should drop `noiseSuppression`/
`autoGainControl`, enforce a ~400ms minimum hold, and add a short stop-delay so the
tail of speech isn't clipped. Tracked for the mobile repo owner.

Tuning is now a config dial — if hallucination-on-silence appears, raise
`AI_VOICE_NO_SPEECH_THRESHOLD` back toward 0.5 and/or set `AI_VOICE_VAD_FILTER=true`.

### Timezone — mobile display (OPEN — not in this repo)
Even with the backend storing 11:00 correctly, the agent reported seeing 07:00.
The API returns `event_date` as `2026-06-11T09:00:00+02:00` (`toIso8601String()`);
if the mobile client renders that ISO string in UTC (or a UTC-set device locale)
it shows the wall-clock two hours early. This is a **client-side** rendering bug
in the mobile app, not the Laravel backend. Owner of `resources/js/mobile/` /
the PWA must ensure event times are rendered in `Africa/Johannesburg`, not the
device/UTC zone. Tracked here so it is not lost.

---

## Cost estimate (production, 10 agents, ~20 voice commands/day each = 200/day)

| Component | Per call | Per day | Per month |
|---|---|---|---|
| Self-hosted Whisper | R0 | R0 | R0 (uses existing server) |
| Claude Haiku 4.5 intent extraction | ~$0.001 | $0.20 | ~$6 (~R110) |
| **Total** | | | **~R110/month** |

POPIA compliance + sub-second latency justify self-hosting Whisper even though OpenAI Whisper API would be only ~R110/month.
