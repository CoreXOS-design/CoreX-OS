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

### 4a. Mirror fidelity (added 27 Oct 2026 after Johan's real-Chrome test)

A support console that shows a different page than the agent has is worse than none. Rules, each found the hard way and pinned by
`DeskMirrorFidelityTest` + the browser proof (menu counts and computed colours, real page vs mirror, on the dashboard, Properties, a
property page and Settings):
- **Never freeze animations.** rrweb's live replayer adds `html.rrweb-paused` and pauses every CSS animation (`pauseAnimation`, default on).
  CoreX menus, panels and cards fade in, so frozen they were invisible: the replayer is created with `pauseAnimation: false`.
- **A blocked element keeps its room but not rrweb's colour.** Desk's own UI is `.rr-block`; rrweb paints blocked boxes `currentColor` (a dark
  strip where the agent's red bar is). `insertStyleRules: ['.rr-block { background: transparent !important; }']`.
- **The mirror shows the page as it IS, not arriving.** Every full snapshot rebuilds the iframe document, which would replay each staggered entrance
  animation (late menu items invisible for seconds). The replayer injects `* { animation-duration: .001s; animation-delay: 0s; transition-duration: 0s }`
  (`insertStyleRules`). A `<select>` must NOT be in rrweb's `maskInputOptions`: with selects masked rrweb drops `option.selected`, so the mirror
  shows the first option instead of the chosen one (the proof compares every select's choice real vs mirror).
- **No drift.** Properties a script sets (a `<select>`'s value, a checkbox) raise no DOM mutation, so a long-lived mirror can drift. Both
  recorders take a fresh full snapshot every 45 s (`checkoutEveryNms`) and once ~2.5 s after recording starts (after a page has drawn itself).
- **Real button classes.** Console buttons use `corex-btn-primary` / `corex-btn-outline` (the only classes that exist in `corex.css`; `ds-btn` has
  no CSS and renders as plain text), and `hidden` must still hide them.
- **Fit the whole agent window, scaled, never reflowed.** The iframe keeps the agent's own viewport width and height (the Meta event), so the
  agent's media queries, sidebar and content layout are exactly the agent's; it is only scaled by transform: `scale = min(1, pane width / agent
  width, pane height / agent height)`. The pane's size comes from the *window* (what is left under the header, the session bar and the sessions
  list), and the mirror is an absolutely positioned child of an `overflow:hidden` pane — so a 1440 px page can never stretch the console, add inner
  scrollbars, or make the console page scroll (a scrollbar appearing used to steal 15 px and push the mirror past its pane). Refitted on window
  resize and whenever the bar / tab bar / list change size (ResizeObserver).
- **No veil.** Nothing may sit over or fade the mirror: no opacity, filter or backdrop on the pane, wrapper or iframe (the proof asserts every
  ancestor's opacity is 1). The amber "in control" frame is an `outline` on the pane, drawn outside the content.
- **Sub-menus and anything script-revealed** (the sidebar's slide-in Real Estate / Dashboard lists, Properties cards) come through entrance animations
  that start at opacity 0; with animations paused they were an empty list. Covered by `pauseAnimation: false`; the proof clicks Real Estate and
  compares the sub-menu's link count real vs mirror.
- **Say when the agent cannot answer.** The agent's client whispers `control_ack` as soon as the take-over question is on screen (CoreX page
  and extension tab alike). If the console has not seen it within 6 s it tells support the agent's page is probably older than the update and
  needs a refresh — instead of staying silent.

### 4b. The console page, and re-opening a session (added 27 Oct 2026)

**The page.** A standard CoreX page: left menu and the sticky page header (`<x-page-header title="CoreX Desk">`) like Properties search; below it only
content. In the header: *Agent's code* + **Join** on one line. When joined: a sticky **session bar** (Agent, Agency, Page, Elapsed, a short state chip
"Waiting for Allow…" / "In control", the highlight note, **Request control**, **Clear highlight**, **End session**), the tab bar when the agent has
shared extension tabs, and the mirror filling the remaining height. **Sessions open now** sits below. No helper paragraphs anywhere — labels carry the
meaning; only feedback ("That field is private…", "The agent declined.") appears as a transient line.

**Re-opening and sharing.** A code is spent at the first join, but the session lives on. A support person who reloads or closes the console opens
their live session from **Sessions open now** (an **Open** button on each live row) — no code — and a second support person of the same scope can
open the same session. `POST /corex/desk/sessions/{id}/attach` (same `desk.support` + own-agency scope as everything else; an ended, waiting, or
out-of-scope session answers "not found"). The first to join stays `support_user_id`; anyone else is in `support_user_ids`; all of them may open the
private channel, point, highlight and ask for control (`control_support_user_id` records who asked, so the audit names the person who acted). Every
attach is a `joined` audit row ("opened the session again" / "a second support person"). A waiting session is still joined by its code only.

### 4c. Who is the support person? (Johan + Andre, 27 Oct 2026)

The support person is identified in full everywhere the agent sees them — the red bar, the consent prompt ("… wants to take control of this
window"), the "in control" bar, the pointer's label, the highlight popover's title, the console's session bar, the audit rows and the Desk sessions list:

| Who | Reads | Example |
|---|---|---|
| CoreX platform staff (an owner role — agency-less) | `{full name} — CoreX {designation}` (designation from their record, default *Support*) | Johan Reichel — CoreX Support |
| An agency's own staff | `{full name} — {agency display name}, {designation}` (no designation: their role's label) | Elize Reichel — Home Finders Coastal, Principal |

**Where it comes from.** `users.designation` is the user's own field — edited on the user's record (Admin › Users › edit, from the `designations`
pick-list; also set in staff take-on / onboarding). Platform staff are the owner roles (`roles.is_owner`, e.g. System Owner); an agency's name is
`Agency::displayNameFor()`. Nothing is hardcoded (`App\Support\Desk\DeskSupporterLabel`).

**Frozen.** The string is computed once at join time and stored on the session (`desk_sessions.support_display`; a second supporter's is on their own
`joined` audit row), so the audit stays true if a title changes later; a new session reads the new title. The protocol field `support_name` carries it
(`desk.joined`, `desk.control_requested`, every session answer). The pointer whisper carries `who` (the supporter's own label) so two supporters are told
apart; the audit's "By" column shows the frozen label for a support actor and just the name for the agent.

### 4d. The console viewer (AnyDesk-like; Johan, 27 Oct 2026 — supersedes the 4b "pane under the header" and the supporter's-menu collapse)

The console has two states.

**List** — the standard CoreX page (left menu, sticky header): the code field + Join on one line, and *Sessions open now* (Open / join by code),
plus a link to the past sessions. Nothing else; no helper text.

**Viewer** — the moment a session is joined (or opened again) the console switches to a full-window viewer:
- The mirror fills the **whole browser window** (100 vw × 100 vh). Scale = min(window width / agent width, window height / agent height), centred, on a dark
  background: letterbox only where the aspect differs. The iframe keeps the agent's own viewport size (so its layout and media queries are the agent's);
  only a transform scales it (up to 3×). No page header, no left menu, no test-site banner, no *Sessions open now*, no helper or technical line:
  the viewer element sits on `<body>` above everything (z-index 2 000 000), the page behind it does not scroll.
- **Full screen** is one button (`requestFullscreen` on the viewer). Esc leaves full screen and stays in the viewer.
- **One thin floating toolbar**, reusing the CoreX button styles: Agent · Support (the display name, 4c) · Page · Elapsed · state pill (**Viewing /
  Requested / In control**) · [Request control | Withdraw request | Hand back] · [Highlight toggle + note + Clear] · [tab buttons when the extension shares tabs]
  · [Full screen] [Back] [End session]. It slides away after 3 s of no mouse activity and returns when the mouse goes to the top edge (it stays while the
  pointer is on it or a field in it has focus). While in control the window has a thin amber frame.
- **Highlight mode**: on while viewing (a click points something out), off once in control (a click is a click); the toggle overrides either way and
  Shift+click always points. A highlight shows a small toast naming the element in words (its label / text / placeholder) — never a selector.
- **Back** returns to the list and leaves the session open (Open it again from *Sessions open now*); **End session** ends it and returns to the list.
  The agent ending the session also returns to the list with the reason.

#### Click accuracy (every scale)

A click in control mode must land on the element under the cursor in the mirror:
1. **Position.** The console converts the pointer to the agent's own CSS pixels: `(clientX − the mirror iframe's on-screen left) / scale`, where scale is
   `iframe on-screen width / iframe.offsetWidth` (never the mirror document's `clientWidth`, which a scrollbar makes narrower). The same point is used to
   find the element in the mirror (`elementFromPoint` in the mirror document, which already includes the agent's replayed window and inner-container scroll).
2. **Exact element.** The selector sent for a click is the element's own (id when unique, else a `tag:nth-of-type` path) — not the nearest `[data-tour]`
   section (right for a highlight, wrong for a click on a button inside it). The click also carries the point (fractions of the agent's window) and the
   element's box in the mirror (`b = [x, y, w, h]`, CSS px).
3. **Verified on the agent.** The agent finds the element by selector and by point, and clicks the selector's element only if it really sits where the mirror
   showed it (box within 8 px) and is what is under that point (or contains / is inside it); otherwise the element under the point if *that* matches the
   mirror's box; otherwise it refuses with `view_out_of_date` and nothing is clicked.
4. **Told back.** A refused action is whispered back (`client-action_result` `{id, ok:false, reason}`, from the CoreX page and the extension): the console says
   why in words and, for `view_out_of_date` / `not_found`, asks for a fresh snapshot — so the next click is on a current picture.
5. **Real events.** The agent dispatches pointer/mouse events with the pointed-at position (kept inside the element), so handlers that read it behave as for a mouse.

Proof (`viewer-click-proof.mjs`, two real Chromium browsers on QA1): a grid of 20 clicks per page — dashboard, Properties search, a property page — before and
after the agent scrolls, at support window sizes 1920×1080, 1366×768 and 1280×720 (agent window 1600×900, so scales 1.2, 0.85 and 0.8); each click must
land on the same element (tag, id, classes, label, box) on the agent's page and in the mirror, plus typing into a field and choosing a dropdown option at each size.

## 5. Privacy and audit (POPIA)

Johan: a permanent audit so claims can be proven or dismissed.

- `desk_sessions` — one row per session: agency, agent, support user, status, code (hashed through `OtpService`), started /
  joined / ended times, who ended it, last page (path only — never a query string).
- `desk_session_events` — append-only trail per session: `created`, `joined`, `page` (path), `highlight` (selector + note),
  `highlight_cleared`, `pointer_on` / `pointer_off`, `ended`; phase 3: `tab_join` / `tab_update` / `tab_leave`; phase 2: `control_requested` / `control_granted` / `control_declined` / `control_revoked` / `control_ended` and one `control_action` per thing replayed (section 14).
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
- Reverb listens on `127.0.0.1:8085` (8080 is taken on this box). nginx `location /app/` on the QA1 vhost proxies the WebSocket
  (`Upgrade` / `Connection` headers; the trailing slash is deliberate — a bare `/app` would also swallow any CoreX URL that starts
  with `/app`); the server-side broadcast API (`/apps`) is only ever called on localhost, never exposed.
- Where Reverb is not configured (no `REVERB_APP_KEY` / `REVERB_PUBLIC_HOST` — e.g. a box that has not been set up yet), Desk
  degrades by itself: Get help shows the phone-support message and no session can start.
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
exists or the console is open (`agent.js`). The console has its own entry (`console.js`). `echo.js` (one connection per tab) and
`frames.js` (the frame codec) are shared. No CSP exists today and none is added.

- **Masking (in the agent's browser, before anything is sent):** every input type is passed to rrweb's `maskInputOptions`, and
  `maskInputFn` keeps the real value unless the field is `type="password"` or sits inside `[data-desk-mask]`;
  `maskTextSelector: '[data-desk-mask]'` hides displayed text (e.g. a bank number shown as text). `data-desk-mask` is set on the
  bank account and branch-code inputs (contacts, payroll, staff take-on, platform company, the rental application's bank account
  field). Any future card/bank input must carry it. All Desk UI carries `rr-block`, so it is never part of what support sees.
- **Highlight look:** drawn by the Guided Tour's own engine (vendored driver.js) with the tour's own theme —
  `layouts/partials/tour-theme.blade.php` (moved out of `tour-engine.blade.php` unchanged, included on every page by the Desk
  partial): overlay colour, stage padding/radius and the `corex-tour` popover, in the "Spot Help" hands-on mode
  (`body.corex-guide-live`) so the agent can still click and type under the dimming.
- **Mirror:** rrweb `record` on the agent, `Replayer` in live mode on the console. The console stamps each arriving batch with its
  own clock (the Replayer plays a live event when its timestamp comes round on the local clock, so the agent's clock must not
  matter). A page change is a new page load: the new page's recorder emits Meta + full snapshot, the Replayer rebuilds. Frames
  wait in the agent's buffer until its channel is subscribed, so a snapshot is never whispered into the void.
- **Assets:** the replayed page is rebuilt in an iframe inside the console, so images and fonts load with the support user's own
  session; the stylesheets travel inlined in the snapshot.

### 7.6 Protocol reference (protocol version 1.2 — 1.1 added the tab key for the extension, 1.2 adds take-over)

**Channel.** `private-desk.session.{id}` on the Reverb/Pusher protocol. Auth: `POST /broadcasting/auth` (session cookie + CSRF);
allowed for the session's agent, and for the support user who joined it while it is `live`. An ended session admits nobody.

**Server events** (`broadcast`, JSON, Echo listens with a leading dot, e.g. `.desk.joined`). All audited in `desk_session_events`.

| Event | Payload | Meaning / what a client does |
|---|---|---|
| `desk.joined` | `{support_name, joined_at}` | agent: show the red bar, **start recording** (nothing is recorded or sent before this) |
| `desk.highlight` | `{selector, note}` | agent: outline `selector` (scroll it into view); `note` optional, ≤ 200 chars, plain text |
| `desk.highlight_cleared` | `{}` | agent: remove the outline |
| `desk.pointer_state` | `{on}` | agent: show/hide the "Support" cursor (the moves themselves are whispers) |
| `desk.ended` | `{by}` | both: tear down. `by` ∈ `agent`, `support`, `logout`, `timeout`, `expired`, `disabled`, `replaced` |

**Client events** ("whispers", `client-` prefix, browser to browser; never stored or audited per message):

| Event | From | Payload |
|---|---|---|
| `client-frame` | agent | `{id, i, n, z, d}` — chunk `i` of `n` (0-based) of batch `id`. Reassemble `d` by `i`; base64-decode; if `z=1` gunzip; `JSON.parse` → an **array of rrweb events**. `d` ≤ 7000 chars. Incomplete batches are dropped after 30 s |
| `client-resync` | support | `{}` — agent must emit a fresh rrweb **full snapshot** (`record.takeFullSnapshot(true)`). Sent on subscribing and every 3 s until the first snapshot arrives |
| `client-pointer` | support | `{x, y}` — ratios 0–1 of the agent's viewport (so any window size works); the agent hides the cursor after 4 s idle |

**HTTP.** Agent (`/api/v1/desk/sessions`, session-auth): `POST /` create → `{session, code, code_minutes}`; `GET /current`;
`POST /{id}/heartbeat {page}` every 30 s and on each page load (answers `{status, ended_by}` — a dead session is noticed here);
`POST /{id}/leaving` (beacon on `pagehide`); `POST /{id}/end`. Support (`/corex/desk`): `POST /join {code}`,
`POST /sessions/{id}/highlight {selector, note}`, `/highlight/clear`, `/pointer {on}`, `/end`; `GET /sessions` (live list).

**Protocol 1.1 (phase 3) — additive only; a 1.0 client keeps working.** A session can now hold several *tabs*: the agent's CoreX page
(`main`) and any Chrome tabs the CoreX extension shares (`x<chrome tab id>`, e.g. `x412`).
- `client-frame` chunks, `client-resync` and `client-pointer` carry an optional **`t`** (the tab key). No `t` = `main`.
- `desk.highlight`, `desk.highlight_cleared` and `desk.pointer_state` carry **`tab`** (`main` unless an extension tab is addressed; an
  unknown key falls back to `main`). A client reacts only to its own tab; the CoreX page ignores any other.
- New server events: `desk.tab_joined {tab, host, title}`, `desk.tab_updated {tab, host, title}`, `desk.tab_left {tab}` — the console keeps its
  tab bar from them (the join answer also lists the tabs already open).
- New audit event types: `tab_join`, `tab_update` (the tab moved to a *different site*; a mere title change is broadcast, not audited),
  `tab_leave` — each with `{tab, host, title}`; highlight / clear / pointer audit rows now carry `tab`.

**Protocol 1.2 (phase 2, take-over) — additive again.**
- Server events: `desk.control_requested {tab, support_name}`, `desk.control_granted {tab, idle_seconds}`, `desk.control_declined {tab, reason}`
  (`declined` / `no_answer`), `desk.control_revoked {tab}` (the agent took it back), `desk.control_ended {tab, by, reason}`
  (`support` / `idle` / `session` / `tab`). A client reacts only to its own tab; control state also rides on every session answer
  (`control: {state: none|requested|granted, tab}`) so a page change restores it.
- Whisper `client-control_ack {t}` (agent → console): the take-over question is on the agent's screen.
- Whisper `client-action_result {t, id, ok:false, reason}` (agent → console): an action could not be done (`view_out_of_date`, `not_found`, `hidden`, `private_field`, `disabled`, `desk_ui`); only failures are sent. A `client-action` click also carries `b` = the element's box in the mirror `[x,y,w,h]` (CSS px of the agent's window) — section 4d.
- Whisper `client-action` (support → agent, never stored, never through the server): `{t, id, type, s?, x?, y?, v?, k?, sh?, dx?, dy?, n?}` where
  `type` ∈ `click`, `dblclick`, `type` (v = the text), `key` (k ∈ Enter, Tab, Escape, Backspace, Delete, arrows, Home, End), `scroll`
  (s = scroller selector or empty for the page; dx, dy in the agent's pixels), `select` (v = option value), `focus`. `s` is a selector the
  console resolved on its mirror; a click may also carry `x`, `y` (ratios) as a fallback.
- HTTP, agent side (CoreX page: `/api/v1/desk/sessions/{id}/control/…`, extension: `/api/v1/desk/ext/sessions/{id}/control/…`):
  `respond {allow}`, `revoke {reason?: agent|idle}`, `actions {tab, actions: [{type, selector, label, ok, reason, n}]}` — the agent's own
  browser reports what it ran and what it refused; typed text is never in a report. Support side: `/corex/desk/sessions/{id}/control/request {tab}`,
  `/control/end`.
- **Consent rule for every client:** nothing is replayed until the *agent's own* Allow has been pressed on that very page and the server's
  `desk.control_granted` has come back; a `client-action` arriving in any other state is ignored.

**Rules every recorder must follow.**
1. Record only the sharing tab, only while the session is `live`; stop on `desk.ended`.
2. Mask before sending: password inputs, anything inside `[data-desk-mask]`; never send content of elements marked `rr-block`.
3. Subscribe to the channel before whispering; keep the buffer until subscribed.
4. Answer every `client-resync` with a full snapshot; a page change must emit a new snapshot.
5. The page path (never the query string) is the only page information that reaches the server (heartbeat `page`).
6. A client never trusts a selector blindly: an unmatched or invalid `selector` is ignored, never evaluated.

**Selectors.** The console sends a `[data-tour="…"]` anchor when the clicked element (or an ancestor) has one; otherwise a CSS path
of `tag:nth-of-type(n)` steps from `body` (shortened to the nearest stable `#id`).

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

## 10b. Acceptance criteria — week 2

1. The console shows a live mirror of the agent's CoreX page, including scroll and page changes (re-snapshot on navigation).
2. Passwords and `[data-desk-mask]` fields arrive masked; normal fields arrive as typed.
3. The support pointer shows on the agent page at the matching position; a click in the mirror highlights the same element on the
   agent's page, drawn with the Guided Tour's styling, and Clear removes it.
4. The protocol (7.6) is documented and pinned by `tests/Feature/Desk/DeskProtocolTest.php`.

## 11. Phases

- **Phase 2** (built 27 Oct 2026 — see section 14): take-over, always with the agent's Allow.
- **Phase 3** (built 27 Oct 2026 — see section 13): the Chrome extension mirrors the agent's other tabs through the same protocol.

## 12. Navigation links (added as built)

CoreX Desk lives under the **Admin** section of the left menu as one group, **Desk** (Johan's ruling, 27 Oct 2026 — it was not findable before;
it is not under Compliance or Settings; menu path **Admin › Desk**):

| Menu item | Goes to | Visible to |
|---|---|---|
| **Desk console** | `/corex/desk` (`corex.desk.console`) | `desk.support` holders (owner roles; an agency's role only if its admin granted it) |
| **Desk sessions** | `/corex/desk-log` (`corex.desk.log.index` / `.show`) — the audit list, moved out of Compliance › Reports & Logs | `desk.support` holders, and an agency admin (`manage_users`) for their own agency |
| **Desk settings** | Company Settings › CoreX Desk tab (`admin.company-settings#desk`) — the agency switch and the take-over idle limit | anyone who may edit settings (`access_settings` / `manage_performance_settings`) |

The group shows when the user holds `desk.support` or `manage_users`; the Admin section itself opens for a `desk.support` holder. Opening any
`corex.desk.*` page opens the Desk panel. Other places:
- **Get help** — top bar of every CoreX page (sidebar top row, left of the Ellie icon; both layouts, My Portal included).
- **Extension** — the CoreX Chrome extension popup › *CoreX Desk* card (phase 3, section 13).
- **Take over** — console › *Request control* (needs `desk.control`); the agent answers in the red bar (CoreX page) or the bar on the shared tab.
- **Agency switch** — Company Settings › *CoreX Desk* tab, and the Setup Wizard's last step (*Access & finish*); both post to `corex.settings.desk`.
- Removed: the *Desk sessions* tab of Compliance › Reports & Logs, and the old *CoreX Desk* item in the Settings menu.

## 13. Phase 3 — extension tabs (built 27 Oct 2026, QA1)

**Why.** An agent on a site CoreX does not own (CMA Info, Lightstone, Property24, Private Property, The Virtual Agent, Loom, the
agency's own website) needs help that used to take AnyDesk. Now the agent presses **Get help** in CoreX as usual, then shares *that
tab* from the CoreX extension; support sees it in the same console, next to the CoreX page.

**What the agent does.** (1) CoreX › Get help › reads the code; support joins. (2) On the other site: CoreX extension icon › the
**CoreX Desk** card › **Share this tab with CoreX support**. The card only appears while a session is open; it says "support has not
joined yet" until then, "this site cannot be shared" off the allowed list, and offers **Stop sharing this tab** once shared. The
shared tab shows a red bar — "CoreX Desk — {support name} is viewing this window" with **Stop sharing** — drawn in a closed shadow
root the site cannot restyle and the recording cannot contain. Nothing is read from a tab until the agent shares it.

**What support sees.** The console gets a **tab bar** (one button per tab: page title · site; the CoreX page is "CoreX page").
Switching asks that tab for a fresh snapshot. The pointer, highlight and note go to the selected tab; the agent sees a labelled
"Support" cursor, and a blue ring + note bubble in the Guided Tour's Spot Help look (dimmed page that stays clickable).

**Allowed sites (server-enforced).** `config/desk.php` `extension_hosts` — `cmainfo.co.za`, `thevirtualagent.co.za`,
`property24.com`, `privateproperty.co.za`, `lightstone.co.za`, `lightstoneproperty.co.za`, `loom.com` (each matches itself and its
subdomains, never a look-alike) — plus the agency's own `website_url` host. The server refuses a tab join for any other host with
422 whatever the extension sends; the extension also hides the button. To add a site: add it to the list (no extension release).

**Settings and permissions — nothing new.** `agencies.desk_enabled` gates everything (off = no session, no card). The extension API
needs `desk.request_help`; the console needs `desk.support`. No new Role Manager key, no new setting, nothing for the Setup Wizard.

**Masking on third-party sites.** Same pipeline as the CoreX page (7.5), stricter default: passwords, `[data-desk-mask]`, and any
input whose name / id / autocomplete / placeholder / aria-label / label text looks like a card, ID, passport, bank account, branch
code, tax number, PIN or one-time code (`desk-lib.js` `isSensitiveField`) are starred out in the agent's browser before anything is
sent. Displayed *text* is not masked: support sees what the agent sees on screen (say so when explaining Desk to an agency).
Known limits: cross-origin iframes inside a tab are not recorded; images/CSS that need the agent's own login do not load for
support (the replay runs under support's session); stylesheets from the page's own origin travel inlined.

**Audit.** `tab_join` / `tab_leave` (host + title, never a URL path or query string) and `tab_update` when a shared tab moves to
another site; shown on the session's page under Compliance › Reports & Logs › Desk sessions as "Browser tab shared / moved to another
site / no longer shared". A tab that navigates off the allowed list stops being shared (and is audited as leaving).

**How it works.**
- Auth: the extension's existing Sanctum bearer token (`chrome.storage.local` `apiToken`). New routes under `auth:sanctum`
  (`routes/api.php`, `DeskExtensionController`): `GET /api/v1/desk/ext/session` (open session, Reverb details, allowed hosts, open
  tabs), `POST /api/v1/desk/ext/broadcasting-auth` (the private-channel handshake — **Desk channels only**, a token can never authorise any
  other channel), `POST /api/v1/desk/ext/sessions/{id}/heartbeat`, `POST /api/v1/desk/ext/sessions/{id}/tabs {action: join|update|leave,
  tab, url, title}` (only while the session is live; only the token's own session).
- Service worker (`desk-ext.js`, pulled in by `background.js`): one websocket to Reverb speaking the Pusher protocol directly (no
  library), channel `private-desk.session.{id}`; injects `vendor/rrweb-record.min.js` (+ `desk-lib.js`, `desk-content.js`) into a shared tab
  with `chrome.scripting`, chunks and whispers its events (`desk-lib.js` `encodeBatch`, same wire format as `frames.js`), routes
  `client-resync` / `client-pointer` / `desk.highlight` to the right tab, re-injects after a full page load, heartbeats every 30 s, stops
  every tab on `desk.ended`, reconnects with back-off, and survives a worker restart (`chrome.storage.session`).
- Console (`console.js`): one Replayer per tab, a tab bar, `t` / `tab` on everything it sends.
- Reverb must accept the extension's websocket origin (`chrome-extension://…`): QA1 sets `REVERB_ALLOWED_ORIGINS=*`. Origin is not the
  security boundary — nothing flows without a channel authorisation (session cookie + CSRF, or the agent's own token).

**Files.** Extension (`public/chrome-extension/portal-capture/`): `manifest.json` (3.12.0, `optional_host_permissions: ["https://*/*"]`),
`background.js` (one `importScripts`), `desk-lib.js`, `desk-ext.js`, `desk-content.js`, `popup-desk.js`, `popup.html` (the card),
`vendor/rrweb-record.min.js` (+ licence note), `tests/desk-lib.test.cjs`. Server: `DeskExtensionController`, `DeskSessionService` (tab events, allowed hosts),
`DeskSessionEvent` (types), `DeskBroadcast` payloads, `config/desk.php`, `routes/api.php`, audit show view. Browser JS: `console.js`, `agent.js`.

**Release steps (Chrome Web Store).** 1. `scripts/build-extension-zip.sh` (validates the manifest and every referenced file; version is
3.12.0). 2. Store listing: the new permission is `optional_host_permissions` (https://*/*) — no always-on access is added; update the
permission justification and privacy text (`public/legal/extension-privacy`, `.ai/specs/chrome-web-store-listing.md`): *"When you press Share this
tab, the tab's content is shown live to the CoreX support person you are on a call with; nothing is stored; inputs that look like
passwords, cards, IDs or bank details are hidden before sending."* 3. Submit; review takes days — until then agents can load the
unpacked folder. 4. Reverb must be reachable from agents' networks wherever the extension runs (nginx `/app/` + `REVERB_ALLOWED_ORIGINS`).
5. On a box without Reverb the card simply never appears (the API answers `enabled: false`).

**Not built / reported.** The CoreX Get-help screen does not mention the extension (a one-line hint would help discoverability).
Support cannot ask an agent to share a tab; the agent starts it. Clipboard, file and download access are not part of Desk. The stale
duplicate `chrome-extension/portal-capture/` (no `public/`) was left alone, as the packaging script already reports.

## 14. Phase 2 — take-over (built 27 Oct 2026, QA1)

**Rulings (Johan):** take-over yes, with the agent's explicit consent; full audit yes.

**What the agent sees.** Support presses **Request control** in the console. The agent's red bar changes (CoreX page, or the bar on a
shared extension tab) to: *"CoreX Desk — {name} wants to take control of this window"* with **Allow** and **Decline** — nothing moves until
Allow. While support is in control it reads *"{name} is in control of this window"* with **Take back control** (always there) and **End
session**. Ending the session always ends control. A request nobody answers lapses after 60 seconds; control nobody uses ends after the
agency's **idle limit** (Settings › CoreX Desk, default 5 minutes, 1–60; also in the Setup Wizard's last step). A page change does not drop
control: the new page picks the state up from the session.

**What support can do.** On the selected tab while in control (an amber frame shows it): **click**, **double-click**, **type** (click a text
field first), **special keys** (Enter, Tab, Escape, Backspace, Delete, arrows, Home, End), **paste** text, **scroll** (wheel over the mirror), and
**choose from a dropdown** (the console lists its options). **Shift+click** still highlights instead of clicking; the pointer and highlight
keep working throughout. One control at a time, one tab at a time.

**Private fields.** The agent's browser decides, not the console. A field the recorder masks is never clicked into, typed into or changed:
passwords and `[data-desk-mask]` on the CoreX page; on third-party tabs also anything that looks like a card, ID, passport, bank, tax,
PIN or one-time-code field. The attempt is reported as blocked ("a private field"). The console also tells support "that field is private —
ask the agent to fill it in" and never reads a field back — the mirror shows stars. Desk's own UI (`.rr-block`, `#corex-desk-…`) is untouchable,
so support can never press Allow or Take back control for the agent.

**Executor.** `public/chrome-extension/portal-capture/desk-exec.js` — one source, loaded by the CoreX page as a plain script (its URL carries the
file's modified time) and injected into shared tabs by the extension. It resolves the selector, refuses what it must (private field, Desk UI, hidden,
disabled, wrong kind of control), then replays the action in the page (a real click sequence; text inserted at the caret the way typing does, so
frameworks see it; a key as key events plus its normal effect; scroll by pixels; a dropdown by value with input + change events). It returns only
`{ok, reason}` and a report line. A reporter merges consecutive keystrokes/scrolls into one line with a count and sends batches; an idle timer
ends control locally and tells the server.

**Audit** (`desk_session_events`, shown in plain words on the session's page — `App\Support\Desk\DeskAuditText`): `control_requested`
(support), `control_granted` / `control_declined` (agent; or the system for `no_answer`), `control_revoked` (agent), `control_ended` (support /
idle / session / tab closed), each with the tab (CoreX page, or host + title). Every replayed action is a `control_action` row — who (support),
what (clicked / typed into / pressed keys in / scrolled / chose an option in), which element (selector + a short label such as the button text or
field name), how many (keystrokes), and whether it ran or was blocked — **never a typed value**. The report comes from the *agent's browser*
(so it is a record of what actually ran, including refusals), the actor on the row is the support user.

**State** (`desk_sessions`): `control_state` (none → requested → granted → none), `control_tab`, `control_requested_at`, `control_granted_at`,
`control_last_action_at` (the idle clock; only an action that ran counts). Setting: `agencies.desk_control_idle_minutes`.

**Settings and Role Manager.** New key **`desk.control`** — "Take control of an agent's window (with their OK)": owner roles via `*`, **excluded from the
admin default** exactly like `desk.support` (an agency grants it in Role Manager; they still need `desk.support` to run the console, and the agent still
has to Allow every time). The console shows **Request control** only to holders. Navigation: no new page — Request control lives in the console toolbar.
Wizard (Setup Guide › Access & finish) and Company Settings › CoreX Desk carry the idle limit (non-negotiable 10a).

**Get help screen.** One line: *"Need to show a tab from another website too? Open the CoreX Chrome extension on that tab and choose Share this tab."*

**Files.** Server: migration `2026_12_11_100000_add_desk_control_columns`, `DeskSessionService` (requestControl / respondControl / revokeControl /
endControlBySupport / recordControlActions / closeControl + idle expiry in expireStale), `DeskControlException`, controllers (`DeskSessionController`,
`DeskExtensionController`, `DeskConsoleController`), routes (`web.php`, `api.php`), `desk.control` in `corex-permissions.php`, `SettingsController::updateDesk`,
`DeskAuditText`. Browser: `agent.js`, `index.js`, `console.js`, `desk-client.blade.php`. Extension: `desk-exec.js`, `desk-content.js`, `desk-ext.js`.
Tests: `DeskControlTest`, `DeskProtocolTest`, `tests/desk-exec.test.cjs`.

**Not built / reported.** Support cannot upload/download files or use the clipboard beyond pasting text into a field the agent has allowed;
drag-and-drop, right-click and file pickers are not replayed; cross-origin iframes cannot be controlled (they are not recorded either); a
control request on a tab shown only as "CoreX page" while the agent is mid-navigation may need a second click. There is no per-agency switch
to forbid take-over entirely — an agency that does not want it simply never grants `desk.control` (owner roles always have it, and the agent
must still Allow).
