# AT-220 — Session Armour to-spec rebuild (READY TO LAND)

**Date:** 2026-07-14 · **Lane:** m1 · **Branch:** `AT-220-session-armor`
**Status:** BUILT + PROVEN on corex-dev (real vendor). Deploy held for conductor GO (dual-deploy).

---

## Spec conformance
Governing spec: **`.ai/specs/session-armour.md`** (written this session, approved-inline for the
convoy). This build satisfies **§4** (reusable component), **§5** (persistent header indicator on
every long-lived authenticated screen, global mount), **§6** (click-to-reconnect + "Back online ✓"),
and **§8** acceptance criteria. Also upholds **`.ai/STANDARDS.md` — "No Silent Locks / plain-English
labels"** (an agent never sees a raw 419/HTTP code; the worst case is a plain-language banner).

---

## What was there vs the spec
The session guard (`public/js/corex-session-guard.js`) already carried the full behaviour —
persistent indicator, click-to-reconnect, "Back online ✓" toast, heartbeat, guarded save. But it
was mounted only on **two DocuPerfect editor pages** (their own `<script>` include). The spec calls
for it on **every long-lived authenticated screen**, as a **reusable component**. It was a
2-page feature masquerading as a platform one.

## The rebuild (reusable, global — no Vite build)
1. **`resources/views/layouts/partials/_session-guard.blade.php`** (NEW) — the single reusable
   mount. `@auth`-gated: loads the guard asset and starts one heartbeat whose refreshed CSRF token
   is written back into `<meta name="csrf-token">` (the page-wide token every CoreX AJAX reads),
   sliding the session so an open tab never expires.
2. **Global inclusion** — added the partial to **both** authenticated app shells:
   `layouts.corex` (231 views incl. the two editors, calendar, deals…) and `layouts.corex-app`
   (155 views). Together = every long-lived agent screen.
3. **`public/js/corex-session-guard.js`** hardened for reuse:
   - **Load-once** guard (a second `<script>` include — the legacy editor pages still self-include —
     is a no-op, so it can't reset the heartbeat/registry).
   - **Multi-sink heartbeat**: `startHeartbeat` is idempotent (first caller owns the single
     interval); every fresh token fans out to all registered sinks via `_applyToken`. The global
     mount registers the `<meta>` sink; a long-lived editor still registers its own in-memory-token
     sink — both stay fresh off one heartbeat. `reconnect()` and `guardedSubmit()` also fan out.
4. **Legacy editors unchanged & still armoured** — they extend `layouts.corex`, so they now get the
   global mount too; their own include runs first (their content renders before the shell's tail),
   the guard is load-once, and their token sink coexists with the global one.

Deliverable is **`public/js` + Blade only** — `corex-session-guard.js` is a plain asset (not
Vite-bundled), so **no `npm run build`** is required for deploy. (`corex-api.js` was deliberately
NOT touched — it is Vite-bundled, and the heartbeat keeps the session alive so its token stays
valid; changing it would force a build with no behavioural gain.)

---

## Proof
`tests/Feature/Session/` — **5 tests, 13 assertions, green** (`OK`):
- `SessionGuardMountTest::test_authenticated_long_lived_screen_mounts_the_session_guard` — a real
  `layouts.corex` screen (the calendar) carries `js/corex-session-guard.js` + `startHeartbeat` +
  the `corex:csrf-refreshed` global token sink. (Initially RED — proved the calendar shell
  (`layouts.corex`) was NOT covered by the first corex-app-only include; fixed by mounting in both
  shells.)
- `SessionGuardMountTest::test_partial_is_auth_gated` — present for an authenticated user, **absent
  for a guest**.
- `CsrfTokenEndpointTest` (existing, 3) — endpoint returns the live token; dead session → 401 JSON
  (banner trigger), never a 302→login.
- `node --check public/js/corex-session-guard.js` — JS parses clean.

---

## Verification checklist
- [x] Guard mounts on every authenticated `corex` / `corex-app` screen (both shells).
- [x] Guests do not mount it (`@auth`).
- [x] Persistent indicator + click-to-reconnect + "Back online ✓" toast (JS, unchanged behaviour).
- [x] Refreshed token written to `<meta name="csrf-token">` (page-wide AJAX stays valid).
- [x] Legacy editors still armoured (load-once + idempotent heartbeat + own token sink).
- [x] No `npm run build` needed (public/js + Blade only).
- [x] `view:clear` / `route:clear`.
- [x] Session test suite green (5/5). Full/broad suite NOT run — Non-Negotiable #13.

## Deploy (on conductor GO — dual-deploy)
`git pull` → clears (`view:clear`/`route:clear`/`config:clear`) → reload the correct php-fpm pool.
**No migration, no seeder, no reference data, no asset build.** Verify by loading any authenticated
page and confirming the top-right connection dot + `js/corex-session-guard.js` in the source.

## Files
- `public/js/corex-session-guard.js` (load-once + multi-sink heartbeat)
- `resources/views/layouts/partials/_session-guard.blade.php` (new reusable mount)
- `resources/views/layouts/corex.blade.php`, `resources/views/layouts/corex-app.blade.php` (global include)
- `.ai/specs/session-armour.md` (governing spec)
- `tests/Feature/Session/SessionGuardMountTest.php` (new)
