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
  a CoreX ring + name label + note card — spec 4g; it clears itself when the agent clicks it).
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
- **One toolbar, pinned** (ruling 27 Oct 2026 — it used to float and slide away after 3 s), reusing the CoreX button styles: Agent · Support (the display name,
  4c) · Page · Elapsed · state pill (**Viewing / Requested / In control**) · [Request control | Withdraw request | Hand back] · [Highlight toggle + note +
  Clear] · [one button per tab when the agent has more than one: CoreX tabs and shared Chrome tabs, 4f] · [Full screen] [Back] [End session]. It is always
  visible (in full screen too) and never covers the picture: the viewer is a column — the toolbar on top (one row of about 45 px where there is room, wrapping
  on a narrow window), **the mirror below it filling the rest of the window**, refitted whenever that room changes (window resize, full screen, the toolbar
  wrapping — a `ResizeObserver` on the picture area). While in control a thin amber frame is drawn over the picture.
  Proof: `toolbar-proof.mjs` — at 1920×1080, 1366×768 and 1280×720 the bar is still at top 0 after 5 s idle, the picture sits below it and fits the remaining
  room exactly (width or height), and Full screen keeps the bar.
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

### 4e. Geometry and speed (viewer round 2, 27 Oct 2026)

**One size, always current.** The mirror is laid out at the agent's own window size and scaled by `min(window w / agent w, window h / agent h)`. Pointer and
click positions are sent as **fractions (0..1) of the agent's viewport** and resolved on the agent against its own *current* `innerWidth` / `innerHeight`; the
console never sends absolute pixels. The size is not learned once: besides rrweb's own resize event, the agent announces `client-viewport` on join, on every resync
and on every resize / zoom, the console applies it to the mirror iframe and refits (a `ResizeObserver` on the iframe also refits whatever changes its size), and
the pointer whisper's `vw`/`vh` make a stale size self-correcting. The mirror's clip no longer depends on a once-computed width.

**The pointer's last position always arrives.** The console throttles pointer whispers to one per 40 ms with a *trailing* send: the old throttle dropped the move
that fell inside the window, so where you stopped the agent's cursor stayed a step behind (more at a larger agent window).

**Speed.** Measured on QA1 (agent and support in two Chromiums on one machine, both themes): pointer ≈ 12 ms; typing / DOM updates were ≈ 530 ms because events
waited `FLUSH_MS = 500` to be batched. Batching is now 60 ms (agent page and extension) and scroll sampling 50 ms. Targets: < 300 ms pointer and typing, < 1 s DOM.
Proof: `geometry-proof.mjs` (agent 1366×768 @ DPR 1.25 and 1920×1080 @ 1, support 1536×735, dark and light): iframe size = agent size, cards on screen equal,
pointer error ≤ 3 px on a 5×5 grid, latencies, and the same after the agent's window / zoom changes mid-session.

**Lag round (27 Oct 2026, Johan + Andre on QA1: "lag still heavy" over SA ↔ Hetzner DE ↔ SA).** Measured first, then cut what is ours.

*What the numbers showed* (`lag-proof.mjs`, two real Chromiums on the QA1 box, so the network between them is ~0 and only OUR cost shows):

| | before (82c0aea26) | after |
|---|---|---|
| bytes sent when support joins | **503 KB** — three full snapshots of 167 KB each | **59 KB** — one snapshot, in two whispers |
| one full snapshot, raw / on the wire | 906 KB / 165 KB | 384 KB / 59 KB |
| join → first picture | 1.8 s | 1.6 s (the page's own load dominates; on a slow link the bytes above are what is saved) |
| a change on the agent's page → in the mirror | 98 ms | 47 ms |
| a keystroke → in the mirror | 82 ms | 33 ms |
| support's pointer → agent's screen | 25 ms | 28 ms (unchanged: 25 moves a second) |
| a 2 s storm of 600 attribute changes + 60 new nodes: messages / bytes | 30 / 22.8 KB | 31 / 24.4 KB (that run now also carries nothing the mirror cannot show) |

*What was ours and is now cut* (all in `resources/js/desk/`):
1. **One snapshot, not three.** On join the agent used to send a snapshot when its recorder started (before the console had even subscribed — it went nowhere),
   another when the console asked, and a third "settle" snapshot 2.5 s later; the console's retry could ask again while the first was still on its way.
   Now: the recorder's first snapshot is kept only if the console already asked (or support was already in the session — a page change, a new tab); the
   console's ask is answered once (asks within 900 ms are one); no settle snapshot (later changes arrive as mutations); the console does not ask again while
   a picture is still arriving, and backs off (3 s, 6 s … 15 s). The periodic checkout is 120 s (was 45 s).
2. **Stylesheets are not sent.** rrweb used to inline every `<link>` stylesheet into the snapshot (492 KB of CSS, twice). The mirror now loads the page's own
   stylesheets by URL (same site, so from the support person's cache). `inlineStylesheet: false`.
3. **Slimming (`slim.js`)** — only what the mirror cannot show: Alpine's `x-data="{…}"` (some 6 KB each), `@click` / `:class` bindings, `on…` handlers, and
   `data-` / `aria-` attributes that no stylesheet of the page selects on (`[x-cloak]`, `[data-state=open]`, `[aria-expanded=true]` are kept; `data-tour`,
   `data-desk*` and `aria-label` are always kept); an attribute change that sets the value the mirror already has is not sent; pictures are marked
   `loading="lazy"`. Text, structure, classes, styles and form values are never touched.
4. **Compression.** The first snapshot is gzipped through `CompressionStream` (already so); batches under 300 bytes now go as they are (the gzip header cost
   more than it saved); whisper chunks are 32 000 characters (were 7 000: a snapshot was 24 whispers, now 2).
5. **One message per frame at most.** After a quiet moment a change goes out within 16 ms; in the middle of a burst the agent waits 50 ms and sends one message
   for the lot (a small message costs nearly as many bytes as a bigger one: 16 ms batching alone measured 60 messages / 40 KB for the storm above).
6. **Pointer** ≤ 25 moves a second (one per 40 ms, trailing send — unchanged). **Console side:** no re-render beyond rrweb's own patching; a page change
   is a new snapshot only because the page is new.

*What is NOT ours — the network floor.* Agent and support are both in South Africa and every message goes through the Reverb server in Germany: agent → DE →
support. A round trip SA ↔ DE is about 170–190 ms, so **a message needs about 90 ms to reach the server and about 90 ms to come back down — roughly 0.2 s
end to end, however small it is.** Server-originated events (highlight, take-over, a new tab announced) also pass through a PHP request on the server: about
0.2 s on QA1 (measured: the server's own answer 217 ms, Reverb adds under 10 ms). We cannot get below that without a relay in South Africa. Measured on the
QA1 box the browsers share a machine, so these numbers show OUR processing only; the real session is that plus the floor.

**Timing panel (diagnostic, off by default).** `config('desk.timing_overlay')` (env `DESK_TIMING_OVERLAY`, default false; deliberately not an agency
setting and not in the Setup Wizard). When on, the console shows a small panel (bottom left of the viewer): per kind of message (snapshot / mutation / input
/ scroll / pointer) the time from the agent's tab to the console (the agent stamps each message with its own clock; the console compares the two clocks from
ping / pong whispers — a round trip of a few ms through Reverb), the agent's encode time, the console's decode and apply time; the size of the last snapshot
(raw and on the wire) and the ten biggest mutation batches; the relay round trip; and a server-stamped event timed from the request that caused it next to
the server's own answer. `POST /corex/desk/sessions/{id}/timing` exists only while it is on (404 otherwise). `window.__deskTiming.snapshot()` returns the numbers.

### 4f. One session, every CoreX tab (Johan + Andre, 27 Oct 2026)

A Desk session belongs to the **user**, not to a tab. A button in CoreX that opens a new tab (`target="_blank"`, `window.open`) used to open a tab support could
not see. Now **every CoreX tab the agent has — the one that asked for the code, tabs opened later, tabs reloaded or navigated — joins the live session by itself.**

- **Tab keys.** `main` = the tab that asked for the code; `c<letters/digits>` = every other CoreX tab; `x<chrome tab id>` = a tab shared through the extension
  (unchanged). A tab keeps its key across its own page changes (sessionStorage); a tab opened from another can inherit a *copy* of that storage, so a brand-new
  tab never reuses a stored key, and the key is also claimed with a Web Lock held for as long as the page lives (a duplicated tab takes a new one).
- **Joining.** The page's own inline script (`layouts/partials/desk-client.blade.php`) sends `POST /api/v1/desk/sessions/{id}/tabs {action: join, tab, path,
  title, from?}` **as the page starts parsing**, and the Desk scripts are fetched at the same time (`modulepreload`), so support learns of the tab as early as
  it can. The server audits `tab_join` (host `CoreX`, with the page path and title), broadcasts `desk.tab_joined`, and `tab_update` / `desk.tab_updated` when
  the tab's page or title changes. The **red bar shows in every joined tab**; the Get help code is shown **once**, in the tab that asked (what a tab
  remembers about the session lives in localStorage, shared by all of them; Get help in another tab while a session is live says so).
- **Leaving.** A closing tab says so (`leaving` beacon with its key). The server does not act at once — the same tab loading its next page says "leaving" and
  then "joined" again within a moment — it makes a real `tab_leave` (audited, `desk.tab_left`, and control ended with reason `tab` if that tab was controlled)
  only when nobody came back within `desk.tab_leave_grace_seconds` (3) — or `desk.tab_leave_grace_controlled_seconds` (15) for a tab support is controlling, so a slow next page after a replayed click never ends the take-over. A tab that crashes without a word is dropped after `desk.tab_silent_seconds` (100) of no
  heartbeat (every tab sends `tab` with its heartbeat). The console's one-second poll settles a closed tab, so it disappears within about 3–4 s.
- **The console.** One button per tab in the toolbar, labelled **title · path** (CoreX tabs) or title · host (shared Chrome tabs); shown when there is more than one.
  **A newly opened CoreX tab is switched to by itself** (and asks that tab for its picture); a picture that arrives before the server's announcement is filed
  under a placeholder that the announcement names. When the selected tab closes the console returns to the first (`main`, else the oldest). Control, pointer
  and highlight address the **selected** tab: the take-over question is asked only in that tab; actions are reported with its key.
- **Control follows a click into a new tab.** If support (in control) clicks a button that opens a new CoreX tab, control moves with it: the agent's browser
  notes, as it replays each click, that this tab did it (`localStorage corex.desk.replay`); the new tab joins with `from` = that tab; only if control is
  granted on exactly that tab and the note is under 10 s old does the server move `control_tab`, audited as **`control_moved`**
  (`desk.control_moved {tab, from}`). A tab the agent opens on their own never receives control. (A browser may block a pop-up opened by a *replayed* click —
  that is the browser's rule, not ours; support can ask the agent to open it.)
- **Ending.** `desk.ended` reaches every tab; each tears down. The session survives one tab closing — the others keep it alive.
- **Audit.** `tab_join`, `tab_update`, `tab_leave`, `control_moved` rows (CoreX tabs read "CoreX tab - title (path)").

Proof (`newtab-proof.mjs`, two real Chromiums on QA1, both `target="_blank"` link and `window.open` button): the agent clicks a CoreX button that opens a new
tab → the console lists it and switches to it (**about 0.3–0.5 s after the new page's HTML arrives; 1.3–2 s from the click** — the property page's own server answer
is ~0.9 s of that) and shows its picture once the page has loaded (~3 s from the click); the red bar is in both tabs, no second code; Request control → Allow in
the new tab → In control in ~0.4 s → a click in the viewer replays in the new tab; closing the new tab returns the console to the first within ~2–3 s; the audit
trail holds `tab_join` and `tab_leave`.

### 4g. The highlight (27 Oct 2026)

Support's highlight is **not a Guided Tour step** any more: no driver.js, no Next / Previous, nothing to press.
- **Look.** A CoreX ring (brand colour, 3 px, rounded, soft glow) round the element, the supporter's display name on its top edge as a small pill, and — when
  there is a note — a small CoreX card (surface colour, border, shadow, brand accent on its left edge) next to the element, below it or above when there is no
  room, at the right edge when the element is near it. Works in light and dark (it uses the theme's variables). The ring follows the element while the page scrolls
  or resizes. It is real DOM, so the console's picture shows it too.
- **Auto-clear.** When the agent clicks the highlighted element **or anything inside it** the highlight clears on the agent's side and the agent's tab tells the
  server (`POST /api/v1/desk/sessions/{id}/highlight/clear {tab, reason: clicked}`): audited (`highlight_cleared`, `by agent`), and `desk.highlight_cleared
  {tab, reason}` makes the console clear its state and toast **"Done - the agent clicked it."** A highlight also clears **on navigation**: leaving the page
  (beacon on `pagehide`) or a hash / history change clears it, and the console toasts "The agent moved to another page - the highlight cleared."
- **Console.** Unchanged: a click in Highlight mode points; the toast names the element in words; Clear removes it.
- Proof (`highlight-proof.mjs`, including auto-clear): ring within 3 px of the element's box plus its padding; label = the supporter's display name; note in the card; no driver.js
  element in the page, no buttons; light and dark screenshots; the agent's click inside the element clears it in ~30 ms and the console says Done in ~0.3 s; leaving
  the page clears it and the console says so in ~1 s.

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
| `client-frame` | agent | one chunk `{id, i, n, z, d, t?}` of a batch of rrweb events (JSON, gzip+base64 when `z=1`, chunks ≤ 32 KB since 27 Oct; see 7.6) |
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
- **Highlight look:** superseded 27 Oct 2026 — see 4g (a CoreX ring + label + note card drawn by Desk itself; it used to be the Guided Tour's driver.js
  popover, `tour-theme`, which Desk no longer includes).
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
| `client-frame` | agent | `{id, i, n, z, d}` — chunk `i` of `n` (0-based) of batch `id`. Reassemble `d` by `i`; base64-decode; if `z=1` gunzip; `JSON.parse` → an **array of rrweb events**. `d` ≤ 7000 chars (32 000 from the CoreX page since 27 Oct; the extension still uses 7000 — receivers accept any). Incomplete batches are dropped after 30 s |
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
- Whisper `client-viewport {t, w, h, dpr}` (agent → console): the agent's window size (CSS px) — sent on join, on every resync request and on every resize / zoom (80 ms debounce, `window` and `visualViewport`). The console lays the mirror out at exactly this size and refits. The `client-pointer` whisper carries the size the console believes (`vw`, `vh`); an agent that sees a difference of more than 2 px re-announces its size at once, so a stale size heals on the next pointer move.
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

**Protocol 1.3 (27 Oct 2026, spec 4e–4g) — additive again.**
- **CoreX tabs.** Tab keys `main` and `c<3–10 letters/digits>` for the agent's CoreX tabs (alongside `x<id>`); a CoreX tab names itself in every whisper it
  sends (`t`), including `client-frame` chunks, and reacts only to what is addressed to it. `client-viewport`, `client-control_ack` and `client-action_result`
  carry its key. Server: `POST /api/v1/desk/sessions/{id}/tabs {action: join|update|leave, tab, path, title, from?}` (session auth, own session; `from` = the tab
  whose replayed click opened this one), `heartbeat {page, tab}`, `leaving` (multipart `tab`). `desk.tab_joined / tab_updated {tab, host: "CoreX", title,
  path}`, `desk.tab_left {tab}`. New: `desk.control_moved {tab, from, idle_seconds}`; new audit types `control_moved`. `GET /corex/desk/sessions` rows now
  carry `tabs` and the response `server_ms`.
- **`desk.highlight_cleared {tab, reason?}`** — `reason` `clicked` | `navigated` when the agent's tab cleared it; agent → server: `POST
  /api/v1/desk/sessions/{id}/highlight/clear {tab, reason}`.
- **`client-frame` chunk** `{id, i, n, z, d, t?, m?}`: `t` the tab, `m` the timing stamp (only with the timing panel on). `d` ≤ 32 000 chars; `z=0` for
  batches under 300 bytes.
- **Timing panel (config only):** whispers `client-tping {id, c}` (console → all tabs) and `client-tpong {id, c, a, t}`; `POST /corex/desk/sessions/{id}/timing`
  → event `desk.timing {sent_ms}`.
- The recorder no longer sends a snapshot nobody asked for: after the first join, a snapshot goes out when the console asks (`client-resync`), or at once for a
  tab joining a session support is already in.

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
   agent's page (a CoreX ring and note card, 4g), and Clear removes it.
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
"Support" cursor, and the CoreX highlight ring + note card (4g).

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


### 14a. Take-over must not be lost to a busy minute (27 Oct 2026, live on QA1)

**What happened.** Support asked, the agent's bar showed the question, the agent pressed Allow — and the console stayed on *Requested* for good. The agent's
`POST …/control/respond` had answered **429**: every route carrying a plain `throttle:30,1` shares ONE counter per signed-in user, so a minute of pointer calls,
polls and heartbeats (and a support person who is also the agent) used it up. The agent's page then treated the failure as "the request lapsed" and quietly
dropped the question; nothing told the console, because the server's state had never changed.

**Rules now.**
- **Own limiters.** Desk routes use `throttle:desk-state` (ask, Allow, take back, join, attach, end: 60 / min) and `throttle:desk-live` (pointer, actions,
  heartbeat, polls, highlights: 600 / min). Each counts **per signed-in user and per route name**, so no route can use up another's allowance. Config:
  `desk.state_per_minute`, `desk.live_per_minute`.
- **The answer is never lost silently.** The agent's page retries Allow / Decline (3 tries, 1 s then 2 s apart) on 429, a dropped connection or a 5xx; if it still
  cannot be sent, the question stays on screen reading "… could not send your answer - press Allow again". Only a genuine lapse (the request already ended) ends it.
- **The console reconciles with the server every second** (was 2 s until the 27 Oct round - spec 4f) (`reconcile()`): it reads `GET /corex/desk/sessions` (whose rows carry `control: {state, tab}`) and falls into
  line — *In control* if the agent allowed it, back to *Viewing* if the request ended or control was ended, closed if the session is no longer open. A missed
  websocket event (dropped connection, Reverb restart) therefore heals within a second or two instead of leaving the console waiting.
