# CoreX Desk — in-app screen share for support (phase 1)

> Approved by Johan 27 Oct 2026. Status: phase 1 in build on QA1 (lane `corex-desk-phase1-2026-10-27`).
> Name on screen: **"CoreX Desk"**. "Switch to agent" (impersonation) stays exactly as it is — Desk never logs in as the agent.

## 1. Purpose

CoreX's own in-app screen share for support. An agent clicks **Get help**, reads a 6-digit code to support (normally on a
phone or Zoom call), and support sees the agent's **CoreX tab** live, points at things and highlights them. The agent does
the clicking. It is not remote desktop: only the CoreX tab is ever shared — never other tabs, the desktop or files.

Why: new agencies need hands-on support. Support can see what the agent sees without installing anything or asking for a
password, and the agency can prove afterwards exactly who looked at what and when.

## 2. Pillars

- **Agent** (`User`) — the person asking for help and the support user joining. Both are recorded on every session.
- **Contact / Property / Deal** — not read or written by Desk. Support sees whatever the agent's page shows (same as
  looking over their shoulder) — nothing is queried on their behalf, nothing is stored from the screen.
- Audit: sessions and events follow the `ImpersonationLog` audit pattern (who / when / from where), plus soft deletes.

## 3. Agent side

- **Get help** button in the top bar of every CoreX page (left of the Ellie / help icon, in the sidebar's top row — that row
  is on every page in both layouts, My Portal included).
- One screen: the **6-digit code** (valid 10 minutes), the text *"Read this code to CoreX support. They will see only this
  CoreX window."*, and **Cancel**.
- When support joins: a thin red bar across the top of every page — *"CoreX Desk — {support name} is viewing this window —
  [End session]"*. The agent sees support's pointer (a labelled cursor, "Support") and highlights (outline + optional note,
  the Guided Tour / driver.js look).
- A session ends on: End (either side), logout, tab close (30 s heartbeat timeout), or 10 minutes with nobody joining.
- Nothing is recorded or sent until support has joined. Passwords and fields marked `data-desk-mask` are masked inside the
  agent's browser before anything leaves it.
- When the agency has Desk switched off, **Get help** shows *"Phone CoreX support on {platform number}"* instead.

## 4. Support side — console `/corex/desk`

- **Join by code** → shows agent name, agency, current page, elapsed time, End.
- **Live view**: rrweb live-mode replay of the agent's tab (scroll and page changes included, about 0.5 s behind).
- **Pointer**: the mouse over the replayed view is sent to the agent as a labelled "Support" cursor.
- **Highlight**: click an element in the replayed view → the console resolves a `[data-tour]` anchor if there is one, else a
  generated CSS path → the agent's page outlines it (with an optional note). One at a time. **Clear** removes it.
- **List of live sessions** (waiting + live) for the support user's scope, and recent ended ones.
- The console loads assets with the support user's own session (no privilege leak). No video is recorded.

## 5. Privacy and audit (POPIA)

Johan: a permanent audit so claims can be proven or dismissed.

- `desk_sessions` — one row per session: agency, agent, support user, status, code (hashed through `OtpService`), started /
  joined / ended times, who ended it, last page (path only — never a query string).
- `desk_session_events` — append-only trail per session: `created`, `joined`, `page` (path), `highlight` (selector + note),
  `highlight_cleared`, `pointer_on` / `pointer_off`, `ended`; `control_*` reserved for phase 2.
- Both tables soft delete; nothing is ever hard-deleted.
- The screen content itself is **never stored** — it travels browser to browser over the live channel only.
- Agency admins see their own agency's sessions under **Compliance › Reports & Logs › Desk sessions**. The CoreX owner
  console lists every agency's sessions.

## 6. Settings and permissions

- `agencies.desk_enabled` — default **ON**. Company Settings toggle + Setup Wizard access step (non-negotiable 10a).
  When off, no session can be started for that agency and Get help shows the phone-support message.
- Role Manager keys (`config/corex-permissions.php`, synced by `corex:sync-permissions`):
  - `desk.request_help` — default for every role.
  - `desk.support` — granted to the owner role; available per agency so an agency admin can run Desk for their own agents.
    An agency admin sees **only own-agency** sessions; cross-agency only for owner roles.
- Console route group: `permission:desk.support`.

## 7. Technical design

### 7.1 Transport — Laravel Reverb (WebSocket)

- No WebSocket existed before (`BROADCAST_CONNECTION=log`; toasts poll). Added `laravel/reverb`, `laravel-echo`, `pusher-js`.
- Reverb listens on `127.0.0.1:8085` (8080 is taken on this box). nginx `location /app` on the QA1 vhost proxies the WebSocket
  (`Upgrade` / `Connection` headers); the server-side broadcast API (`/apps`) is only ever called on localhost, never exposed.
- QA1 runs it as a systemd unit `corex-qa1-reverb.service` — mirrors the existing `corex-qa1-queue.service` pattern (QA1's
  workers are systemd, not supervisor).
- The browser gets the Reverb key/host/port from the page (a small JSON block rendered by the Desk partial), not from
  build-time `VITE_*` variables — so the same build works on every box and a key change needs no rebuild.
- Reverb limits raised for this app: `max_message_size` / `max_request_size` 1 MB.

### 7.2 Channel and event protocol (reused unchanged by the phase-3 extension)

One private channel per session: **`private-desk.session.{id}`**. Channel auth (`routes/channels.php`) admits only the
session's agent user, or the support user recorded on that session once it is `live`, and never an ended session.

Server-originated events (Laravel `broadcast()`, always audited, JSON payload):

| Event | From | Payload | Effect |
|---|---|---|---|
| `desk.joined` | server | `{support_name, joined_at}` | agent shows the red bar and starts recording |
| `desk.highlight` | server (support POST) | `{selector, note}` | agent outlines the element |
| `desk.highlight_cleared` | server | `{}` | agent removes the outline |
| `desk.pointer_state` | server | `{on: bool}` | pointer shown / hidden (audited on/off only) |
| `desk.ended` | server | `{by}` | both sides tear down |

Client events (Pusher "whispers" — browser to browser, not stored, not audited per message):

| Event | From | Payload |
|---|---|---|
| `client-frame` | agent | one chunk `{id, i, n, z, d}` of a batch of rrweb events (JSON, gzip+base64 when `z=1`, chunks ≤ 7 KB) |
| `client-resync` | support | `{}` — agent answers with a fresh rrweb full snapshot |
| `client-pointer` | support | `{x, y}` — ratios 0–1 of the agent's viewport |

Agent to server (HTTP, `/api/v1/desk/…`, session-authenticated): `POST sessions` (create + code), `GET sessions/current`,
`POST sessions/{id}/heartbeat {page}` (every 30 s and on each page load), `POST sessions/{id}/leaving` (beacon on tab
close), `POST sessions/{id}/end`.
Support to server (`/corex/desk/…`): `POST join {code}`, `POST sessions/{id}/highlight`, `…/highlight/clear`,
`…/pointer {on}`, `…/end`.

The extension client (phase 3) speaks the same protocol: it subscribes to the same channel, answers `client-resync`,
emits `client-frame`, and honours `desk.*`. Only the recorder's host page differs.

### 7.3 Lifecycle

`waiting` (code issued, 10 min) → `live` (support joined) → `ended`. `ended_by`: `agent`, `support`, `logout`, `timeout`
(heartbeat lost), `expired` (nobody joined in 10 min), `disabled`. Stale sessions are closed lazily whenever sessions are
read (join / current / console list) and by the `desk:expire` command, so no scheduler is required on a box without one.
One open session per agent: starting a new one closes the old.

### 7.4 Code handling

`OtpService::issue('desk_join', 'desk:{sessionId}', deliver:false, expires 10 min)` — hashed at rest. A join submits only the
code, so the join service tests the code against the unexpired waiting sessions' hashes, then consumes the matching one
through `OtpService::verify`. Wrong guesses are rate-limited per support user. A non-owner support user whose agency does not
match the session gets the same "code not found" answer as a wrong code.

### 7.5 Browser code

All Desk JS is its own Vite entry (`resources/js/desk/index.js`) loaded by both layouts (`corex.blade.php`,
`corex-app.blade.php`) through one partial. It is a tiny loader; rrweb and Echo are imported dynamically only when a session
exists or the console is open. The console has its own entry (`resources/js/desk/console.js`). No CSP exists today and none is
added. Masking: `maskInputOptions.password`, `maskInputFn` for `[data-desk-mask]`, `maskTextSelector: '[data-desk-mask]'`,
`blockClass: 'rr-block'` on all Desk UI. `data-desk-mask` is added to card and bank inputs.

## 8. Files (phase 1)

Migrations (sessions, events, `agencies.desk_enabled`), `App\Models\DeskSession` / `DeskSessionEvent`, `App\Services\Desk\*`
(session lifecycle, join code), `App\Events\Desk\*` broadcast events, `routes/channels.php`, `routes/api.php`,
`routes/web.php`, `App\Http\Controllers\Desk\*`, views `desk/*` + `layouts/partials/desk-client.blade.php`,
`config/reverb.php`, `config/broadcasting.php`, `config/corex-permissions.php`, `config/agency-onboarding-copy.php`,
`ComplianceReportsTabs`, Company Settings, `resources/js/desk/*`, `app/Console/Commands/DeskExpire`, tests under `tests/Feature/Desk/`.
Infra (QA1 only): `corex-qa1-reverb.service`, nginx `location /app` in the `qatesting1` vhost, `.env` Reverb keys.

## 9. Search / sort / filter / scoping (BUILD_STANDARD §1a)

- **Desk sessions list** (Compliance tab and console history): search by agent name, support name, agency name (owner only);
  sort by started (default, newest first), agent, support, duration, status; filter by status and date range; pagination;
  empty state *"No Desk sessions yet."*
- **Scoping at the query layer**: an agent sees nothing of the audit list; an agency admin / `desk.support` agency user sees
  only own-agency sessions; owner roles see all. Direct URL access by id is refused the same way.
- No create/edit/delete UI for audit rows (append-only). Archive/restore is a soft-delete by system only.

## 10. Acceptance criteria — week 1

1. Reverb runs on QA1 behind nginx; a browser on `https://qatesting1…` opens a WebSocket on `/app`.
2. Get help creates a `waiting` session and shows a 6-digit code.
3. Console join with that code makes the session `live`; the agent's page shows the red bar within 2 s (via Reverb).
4. End from either side ends the session on both.
5. Both Role Manager keys, the agency setting and the wizard step exist.
6. `desk_sessions` / `desk_session_events` rows are written for the lifecycle.
7. Tests cover the session lifecycle and channel authorisation.

## 11. Phases

- **Phase 2** (not now): take-over with Allow / Stop.
- **Phase 3** (next release, Chrome extension): the same recorder inside the extension content script for allowed sites
  (CMA Info, Virtual Agent, Property24, Private Property, agency websites, Lightstone, Loom); the agent picks which tab to
  share. The protocol in 7.2 is designed so the extension reuses it unchanged.

## 12. Navigation links (added as built)

- **Get help** — top bar of every CoreX page (sidebar top row, left of the Ellie icon).
- **Desk console** — `/corex/desk` (route `corex.desk.console`) — sidebar entry under the Compliance / admin tools for users with `desk.support`.
- **Desk sessions** — Compliance › Reports & Logs › *Desk sessions* tab (route `compliance.desk-sessions.index`).
