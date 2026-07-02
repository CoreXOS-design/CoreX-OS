# CoreX — WhatsApp Capture Linking (My Portal → Tools)

**Status:** Build (AT-156). Staging-first, held from live.
**Owner:** Johan (domain) · Build: CC.
**Extends:** `claude_communication_capture_setup_spec.md` §3.2 (WhatsApp admin/self device provisioning), AT-149 (WAHA webhook), AT-148 (media), AT-153 (capture-owner must be a real agency agent), AT-136 (agent capture consent).

## Purpose
Let an agency agent link their own WhatsApp capture device from inside CoreX — a proper in-app page, no external token URL. Replaces the interim token-gated QR page (`/opt/corex-waha-qr` + its nginx location), which is removed once this lands.

## Placement / nav
A **"WhatsApp Link"** section on the existing **My Portal → Tools** tab (`resources/views/agent/portal.blade.php`, after the Client QR card). My Portal is already in the sidebar; Tools is a tab on that page — no new nav entry. Agent-facing.

## Pillars
Agent (`User`) ←→ Contact (captured WA threads attach to contacts via existing ingestion). Writes `communication_wa_devices` (owned by the agent).

## States (one Alpine section, server-authoritative via a status endpoint)
1. **disabled** — agency toggle `wa_self_link_enabled` is off → explain, no action.
2. **blocked** — logged-in user is not a real agency agent (super_admin / no agency) → clear message, no action (AT-153 enforced server-side).
3. **not_linked** — explain capture + show AT-136 consent summary + **Link WhatsApp** button.
4. **awaiting_scan** — live server-proxied QR (auto-refresh) + instructions incl. the doctrine warning: **the capture number must NEVER be the outreach/sending number**. Cancel returns to not_linked.
5. **linked** — device number, linked-since, session health (WORKING/…), **Unlink** (soft + audited).
6. **waha_down** — WAHA unreachable → graceful message + Retry.
7. **failed** — session FAILED → **Restart** from the UI.

## Session model
- One WAHA session per agent: name = `{prefix}-agent-{userId}`, `prefix` = agency `wa_session_prefix` (default `agency{ID}`), sanitised `[a-z0-9-]`. Agency-scoped.
- Session webhook = staging webhook URL + the configured HMAC secret (unchanged AT-149 path).
- On session `WORKING`, ensure a `communication_wa_devices` row (agency_id, user_id, waha_session, wa_number from session `me`, active). Idempotent.

## Data model
- `agencies.wa_self_link_enabled` boolean default **true**.
- `agencies.wa_session_prefix` string nullable.
- No new tables. Reuses `communication_wa_devices` (SoftDeletes, BelongsToAgency).

## Security
- Page section + all endpoints gated by `permission:access_communication` + `agency.required`.
- WAHA API key stays server-side (never sent to the client); QR is proxied by an auth-gated controller action (`response()` of the PNG bytes, `no-store`).
- AT-153 enforced in code: super_admin / agency-less **cannot** link (blocked state + endpoint refuses).
- Webhook HMAC unchanged.

## Robustness (BUILD_STANDARD)
- WAHA down → `waha_down` + Retry; no 500 reaches the user.
- Session FAILED → `failed` + Restart.
- Double-link absorbed: `link` is idempotent (existing session → return current state).
- Unlink while messages in-flight: soft-delete device (active=false) + WAHA logout; later webhooks for the dead session resolve no active device and are dropped gracefully.
- Unlink is soft (recoverable by admin) + audited (Log with actor/device/session).

## Endpoints (`communications/wa-link`, name `communications.wa-link.*`)
- `GET status` → JSON state.
- `GET qr` → image/png (server proxy) or 204.
- `POST link` → start/create session; returns state.
- `POST unlink` → soft-delete device + WAHA logout; audited.
- `POST restart` → stop+start a failed session.

## Acceptance
Each state renders; link starts a SCAN_QR_CODE session and QR shows in-app; on WORKING a device row is created for the agent; unlink soft-deletes + logs; super_admin blocked; agency toggle hides/shows; WAHA-down degrades gracefully. Token page + nginx block removed.
