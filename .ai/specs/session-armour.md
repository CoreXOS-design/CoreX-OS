# Session Armour — persistent connection indicator (AT-220)

> Status: approved-inline (Johan, 2026-07-14 convoy). Governs the CoreX
> session-armour / connection-indicator feature.

## §1 — What this does and why
Long-lived CoreX screens (an agent leaves the document editor, the calendar, a
deal open for hours) run on a session that expires (`SESSION_DRIVER=database`,
`SESSION_LIFETIME=120`). When it expires, the page's CSRF token is stale and the
next write dies with a raw **419** — the Barbara Jackson incident (blocked
mid-Contract-of-Sale by "Save failed: HTTP 419"). An agent must NEVER see an
HTTP code, and a merely-idle tab must never silently rot.

Session Armour makes session/CSRF expiry a non-event on **every long-lived
authenticated screen**:
- **Prevention** — a heartbeat (well under `SESSION_LIFETIME`) refreshes the
  page-wide CSRF token AND slides the session, so an open tab never expires.
- **Visibility** — a **persistent connection indicator** (header area, top-right)
  is always present: a silent green dot when healthy; red **"Offline — click to
  reconnect"** when the connection drops.
- **Recovery** — **click-to-reconnect** on the indicator; on success a **"Back
  online ✓"** toast. Writes routed through `guardedSubmit()` auto-retry a 419
  once with a fresh token.
- **Honest worst case** — if the session is genuinely dead, a plain-language
  banner ("your work is safe in this tab, log in again") — never a 419/HTTP code.

## §2 — Pillar linkage
Cross-cutting infrastructure over the **Agent** pillar (the authenticated
practitioner's session). No new tables; no tenant data. Reads/writes only the
session + CSRF token.

## §3 — Data model / endpoints
- `GET /api/v1/csrf-token` (`api.v1.csrf-token`, web+auth) → `{ token }`; a dead
  session yields **401 JSON** (so the client shows the banner, never follows a
  302→login). No migration.

## §4 — Reusable component (the rebuild)
- `public/js/corex-session-guard.js` — `window.CoreXSessionGuard` (plain asset,
  no Vite build): `startHeartbeat` (idempotent, multi-sink), `guardedSubmit`
  (419 → refresh → retry once), the persistent indicator (`mountIndicator` /
  `setIndicatorState`), `reconnect` (+ "Back online ✓" toast), and the plain
  connection-lost banner.
- `resources/views/layouts/partials/_session-guard.blade.php` — the single
  reusable mount: loads the guard and starts one heartbeat whose refreshed token
  is written back to `<meta name="csrf-token">` (the token every CoreX AJAX
  reads). Auth-gated (`@auth`).

## §5 — UI placement / navigation
The indicator is a persistent, always-mounted element in the header area
(top-right) of **every authenticated app screen** — included globally via
`resources/views/layouts/corex-app.blade.php`. No nav entry (it is ambient
status, not a page).

## §6 — User flow
1. Any authenticated screen loads → guard mounts the indicator (silent green),
   heartbeat starts, session slides every 10 min.
2. Connection drops (server logout, laptop sleep past lifetime, network) → next
   heartbeat/ save can't refresh → indicator turns red "Offline — click to
   reconnect".
3. Agent clicks the indicator → reconnect attempt. Success → green + "Back
   online ✓" toast. Still dead → plain banner with re-login guidance.
4. A save that hits 419 is retried once transparently; only a genuinely dead
   session surfaces the banner.

## §7 — Permissions
None beyond authentication (`@auth`). Never renders for guests / public links.

## §8 — Acceptance criteria
- [ ] The guard + heartbeat mount on **every** authenticated `corex-app` screen,
      not just the two DocuPerfect editors.
- [ ] Guests / unauthenticated pages do NOT mount it.
- [ ] The persistent indicator is present and reflects connection state.
- [ ] Click-to-reconnect refreshes the token; a "Back online ✓" toast fires on
      recovery.
- [ ] The refreshed token is written to `<meta name="csrf-token">` so page-wide
      AJAX stays valid.
- [ ] No raw 419 / HTTP code is ever shown to an agent.
- [ ] The two legacy editor pages keep working (load-once guard, idempotent
      heartbeat, their own token sink still updates).
- [ ] Deliverable is `public/js` + Blade only — no `npm run build` required.

## §9 — Files
- `public/js/corex-session-guard.js` (enhanced: load-once, multi-sink heartbeat)
- `resources/views/layouts/partials/_session-guard.blade.php` (new reusable mount)
- `resources/views/layouts/corex-app.blade.php` (global include)
- `routes/web.php` — `GET /api/v1/csrf-token` (existing)
- `tests/Feature/Session/CsrfTokenEndpointTest.php` (existing, 3 tests)
- `tests/Feature/Session/SessionGuardMountTest.php` (new — global mount / auth-gating)
