# Spec: Sidebar Favourites

> Status: **DRAFT — awaiting Andre's approval** · Owner: Andre · Branch: `AT-438-Multiple-Things`
> Created: 2026-09-28 · Last updated: 2026-09-28

---

## 1. Business requirement

An agent who lives on one or two pages — Properties, say — currently pays the
same navigation cost every single time: open Real Estate, slide the panel,
find the page, click. Multiply that by fifty times a day, every day, for every
agent.

**Favourites** lets each user pin the handful of pages they actually use to a
personal section that sits at the bottom of the sidebar, directly above their
own name, one click from anywhere in CoreX. They choose those pages themselves
from a new tab in My Profile, which lists every page their permissions let them
open. They can also have the section open itself automatically when they sign
in, so their pages are in front of them the moment CoreX loads.

This is personal navigation, not a permission change: a user can only pin pages
they can already reach, and pinning grants nothing.

---

## 2. Pillars

Cross-cutting presentation layer over the **Agent** pillar (`User`). Reads the
route table and the user's own permissions; writes one per-user preference row
set. No pillar data is created or modified.

---

## 3. Decisions (confirmed with Andre, 2026-09-28)

| # | Question | Ruling |
|---|----------|--------|
| 1 | What can be pinned? | **Only actual pages** — links that open a page. Section headings (`Real Estate`, `Compliance`…) that merely slide a sub-menu open are NOT pinnable. Every favourite is one click to a page. |
| 2 | Auto-open behaviour | **Open once per login, stays until closed.** The panel opens on the first authenticated page load of a session. Once the user closes it, it stays closed for the rest of that session. |
| 3 | Ordering | **User-ordered — drag to reorder** in the My Profile picker. |
| 4 | Empty state | **Button always visible.** With nothing pinned, opening it says "No favourites yet" and links straight to My Profile → Favourites. |

### Decisions taken by the lane (implementation calls, stated for the record)

- **No new permission key.** Favourites are a personal preference over pages the
  user already has access to. Gate is `auth`; every row is hard-scoped to
  `Auth::id()`. See §7.
- **Maximum 25 favourites per user**, enforced server-side. This is the
  prevent-or-absorb call required by BUILD_STANDARD §3 — an unbounded list means
  unbounded per-request route resolution on every single page in CoreX. 25 is far
  past what the feature is for (a handful of daily pages) and is rejected with a
  plain-language message, never silently truncated.
- **One save, one form.** The picker posts the whole list (keys, order, auto-open
  flag) in a single request. No hidden JSON endpoint is introduced — CLAUDE.md
  Non-negotiable #7 bans those, and nothing here needs one.

---

## 4. Source of truth — no hand-maintained page registry

The sidebar is ~3,100 lines of hand-written Blade gated by `@permission`. It
already ships `window.CorexNavSearch.build()`
(`layouts/corex-sidebar.blade.php:3012`), which walks the rendered
`.corex-nav-root` and returns `{label, href, parent, group}` for every entry the
current user can actually see — permissions, feature flags and route
availability are already baked into the DOM.

**The Favourites picker reuses that exact walker**, filtering to entries with an
`href` (decision #1). This is the same mechanism the Demo Sidebar curator
already uses (`admin/dev-settings/demo-sidebar.blade.php`,
`.ai/specs/demo-sidebar-curation.md`) — INVESTIGATE → COPY → ADAPT, zero drift,
nothing to keep in sync.

Consequence, deliberately inherited: a demo-agency user whose sidebar has been
curated down sees only the curated pages in the picker, because the curation
pass removes them from the DOM before the index builds.

### Stable key

`nav_key` = **`p:<pathname>`** — identical to the convention the demo curation
spec already established (`p:/corex/properties`). One convention across both
features; nothing new invented.

---

## 5. Data model / migrations

### 5.1 New table — `user_nav_favourites`

```
id
user_id      → users, NOT NULL, indexed
nav_key      string(191)  NOT NULL   -- 'p:/corex/properties'
label        string(100)  NOT NULL   -- sidebar label at pin time, denormalised
sort_order   unsignedInteger NOT NULL default 0
timestamps
softDeletes
UNIQUE (user_id, nav_key)
```

**No `agency_id`, no `AgencyScope`** — deliberate, and consistent with the
existing per-user preference tables (`calendar_user_preferences`,
`user_dashboard_settings`), neither of which carries one. A favourite belongs to
exactly one user; every query is `where('user_id', Auth::id())`, so no
cross-agency read is reachable and the scope would add no security. It would
however introduce a real bug: an owner who pins pages while switched into agency
A would lose them the moment they switch to agency B (the documented
AgencyScope owner-switcher blind spot). Declared here rather than left implicit.

**`label` is denormalised on purpose.** It is captured from the rendered sidebar
at pin time so the panel renders without re-walking the DOM, and refreshed
whenever the picker is saved. If a page is later renamed in the sidebar, the
favourite keeps its old label until the user next saves the picker — an
acceptable, self-healing staleness, stated plainly.

**UNIQUE + SoftDeletes is the documented trap** (BUILD_STANDARD §5a): MySQL's
unique index has no soft-delete awareness, and Eloquent's default scope hides
the trashed row from the very query that would find it. Un-pinning then
re-pinning the same page is therefore an explicit
`withTrashed()->first()` → `restore()`, never a naive `firstOrNew()->save()`
that would throw a raw duplicate-key error at the user. This path has its own
named test (§10).

### 5.2 New column — `users.nav_favourites_autoopen`

`boolean NOT NULL default false`. Same shape and precedent as the recently
added `users.daily_digest_enabled`.

### 5.3 Schema snapshot

Both migrations are followed by `schema:dump` **against the test database**,
DEFINER clauses stripped with `perl -i -pe` (not PowerShell `Set-Content`,
which injects a BOM), committed in the same commit — CLAUDE.md §12a and
Standard −1b.

---

## 6. UI

### 6.1 Sidebar — the Favourites section

Placement: a new block inserted as the **last child of `.corex-user-section`
before `.corex-user-profile`** — i.e. directly above the user's name, below the
impersonation banner when one is showing. Exactly where Andre asked for it.

**The button** (always rendered for any authenticated user, decision #4):

```
★  Favourites            (3)   ⌃
```

star icon · label · count of live favourites · chevron pointing up.

**The panel** opens **upwards, overlaying the sidebar**:

- `position: absolute; bottom: 100%; left: 0; right: 0` within
  `.corex-user-section` (which becomes `position: relative`).
- **Height is capped at exactly half the sidebar.** Computed in Alpine when the
  panel opens and on window resize — `Math.floor(sidebarEl.clientHeight / 2)` —
  and bound as an inline `max-height`. A fixed `50vh` is NOT used: the sidebar is
  `height: 100%` of its `<aside>`, which is the viewport minus the env banner
  (24px on local/demo/staging, 0 on live), so `50vh` would overshoot half the
  sidebar on every non-production environment. The computation is a **method on
  the component** called from `x-init`/`@click`, never a multi-statement
  attribute body — Standard −1 point 4.
- Scrolls internally with `.corex-brand-scroll` once content exceeds the cap.
- Closes on click-outside and on Escape.
- Each row is a plain `<a>` to the favourite's path, with its own active-state
  highlight matching `.corex-nav-subitem.active`.
- Empty state inside the panel: *"No favourites yet."* + *"Choose your pages in
  My Profile → Favourites"* linking to `/my-portal#favourites`.

**Styling** uses existing design tokens only (`var(--token, #fallback)` per
STANDARDS "Design System Compliance"). New classes live in `resources/css/corex.css`
beside the existing `.corex-user-*` block: `.corex-fav-toggle`, `.corex-fav-panel`,
`.corex-fav-item`, `.corex-fav-empty`. No hardcoded colours.

### 6.2 Auto-open (decision #2)

`sessionStorage` key `corex-fav-autoopen-seen`. On page load, when
`nav_favourites_autoopen` is on **and** the key is absent, the panel opens and
the key is set. Closing the panel does not clear it. A new login = a new browser
session = the key is absent again.

`sessionStorage` is the right store precisely because it is per-tab and
per-session and is allowed to come back empty — worst case the panel opens once
more than strictly necessary, which is the harmless direction. Every read and
write is wrapped in `try/catch` so a private window or blocked storage can never
break the sidebar.

### 6.3 My Profile — new "Favourites" tab

`resources/views/agent/portal.blade.php` gains `'favourites' => 'Favourites'` in
`$portalTabs`, placed directly after `'profile'`. Hash-addressable as
`/my-portal#favourites`, consistent with every other tab on that page.

The tab contains one form:

1. **Auto-open toggle** — *"Open my Favourites automatically when I sign in"*,
   with the plain-English consequence beneath it.
2. **My favourites** — the chosen pages as an ordered, drag-to-reorder list
   (decision #3), each with a remove control. Order is the saved `sort_order`.
   Empty state: *"You haven't chosen any pages yet — tick them below."*
3. **All pages you can open** — the full checklist, built client-side from
   `CorexNavSearch.build()`, grouped by sidebar section (standalone pages first
   under "Pages"), ticked = favourited. **This list scrolls inside itself**, capped
   at 420px (`box-sizing: border-box`, `.corex-brand-scroll`, `overscroll-behavior:
   contain`). With ~150 pages across a dozen sections an unbounded list pushed the
   Save button thousands of pixels down the page — the user had to scroll past the
   whole menu just to save. The pane shrinks to fit when a search narrows it, and is
   hidden entirely when nothing matches.
4. **Save Favourites** button.

Drag-reorder uses the HTML5 drag events already available — no new dependency.
Keyboard fallback: each row carries ▲/▼ buttons, so reordering never requires a
mouse (Mobile Awareness / accessibility).

### 6.4 List-screen completeness for the picker (BUILD_STANDARD §1b)

| Requirement | How it is met |
|---|---|
| **Search** | A filter box above the checklist, matching **the page label** and **its section label** (the two named fields). Live, client-side. |
| **Sort** | Grouped by section; sections A→Z; pages A→Z within each section; standalone pages first. **Stated default: alphabetical.** The user's own favourites list is ordered by `sort_order` (default: order added). |
| **Filter** | *Show:* **All pages** / **Only my favourites** — the meaningful status axis for a configuration checklist. |
| **Bounded height** | The checklist scrolls inside a 420px pane so Save is always a short reach away, whatever the menu size. |
| **Pagination** | **Declared not applicable, with reason.** The set is a bounded, fully client-side list of roughly 150 entries read from the DOM that is already on the page. There is no query, no unbounded result set, and no server round-trip to paginate. Search + section grouping do the work pagination would. |
| **Empty state** | Two real ones: no favourites yet (§6.3 item 2), and *"Couldn't read your menu — please reload the page"* if the index returns nothing. |

---

## 7. Permissions and scoping

**No new permission key.** Route middleware is `auth` only.

| Surface | OWN | BRANCH | AGENCY |
|---|---|---|---|
| Sidebar panel | Renders only `user_id = Auth::id()` rows | n/a | n/a |
| My Profile picker | Same | n/a | n/a |
| Save endpoint | Writes only `user_id = Auth::id()` | n/a | n/a |

BRANCH and AGENCY levels are **declared not applicable**: a favourite is a
personal preference with no meaning at branch or agency level. Nobody — including
an owner or an admin — reads or writes another user's favourites anywhere in this
feature.

**Direct-URL-by-ID access is structurally impossible, not merely unlinked:** no
route in this feature takes a favourite id or a user id. The single endpoint
acts on `Auth::user()` and resolves rows by `(user_id = Auth::id(), nav_key)`.
A crafted request has no id to substitute. Asserted by test (§10).

**A favourite can never grant access.** On save, every submitted path is resolved
against the route table and checked against the same gates the routes themselves
use — `permission:<key>` → `hasPermission()`, `owner_only` → `isOwnerRole()` —
the identical mechanism `NavigationAtlasService::resolve()` already uses
(`app/Services/AI/NavigationAtlasService.php:257`). Anything the user cannot open
is rejected. Routes gated in-controller rather than by middleware pass this check,
exactly as they do for the Navigation Atlas — stated honestly rather than
overclaimed: the destination page still enforces its own access on arrival, so the
worst case is a favourite that lands on a refusal, never one that bypasses a gate.

---

## 8. Input space, prevent-or-absorb (BUILD_STANDARD §2, §3)

| Input | Decision | Behaviour |
|---|---|---|
| No pages ticked, Save pressed | **Absorb** | Clears the list. Panel shows its empty state. Not an error. |
| Same page ticked twice (duplicate key posted) | **Absorb** | De-duplicated server-side before write. |
| Page previously un-pinned, now re-pinned | **Absorb** | `withTrashed()` → `restore()`, order reset. No duplicate-key error can reach the user. |
| Path the user has no permission for | **Prevent** | Rejected: *"One of the pages you chose isn't available to you any more. It has been removed from your list."* Everything else still saves. |
| Path matching no registered route (renamed/removed page) | **Prevent** | Rejected on save with the same plain message; and at render time such a favourite is skipped, never rendered as a dead link. |
| Path not starting `p:/` / absolute URL / external host | **Prevent** | Validation rejects. Only same-origin absolute paths are storable, so a favourite can never be an off-site link. |
| More than 25 pages ticked | **Prevent** | Rejected with *"You can keep up to 25 favourites — please untick a few."* Nothing is silently dropped. |
| `label` missing or over-long from a tampered post | **Absorb** | Falls back to the label derived from the route; truncated to 100 chars. `label` is NOT NULL and always gets a value — every input combination. |
| Whitespace around a submitted key/label | **Absorb** | Trimmed before validation. |
| Auto-open checkbox absent from the post | **Absorb** | Read with `$request->boolean()` on a form that always renders the control — the checkbox-coercion trap called out in CLAUDE.md #10a §6.1. |
| `sessionStorage` unavailable / throws | **Absorb** | `try/catch`; the panel simply does not auto-open. The sidebar never breaks. |
| JS disabled / index unreadable | **Absorb** | Server-rendered favourites still render in the panel; the picker shows its "couldn't read your menu" empty state. |

No raw error, no 500, no SQLSTATE reaches the user on any path above.

---

## 9. Files

**New**
- `database/migrations/2026_09_28_000000_create_user_nav_favourites_table.php`
- `database/migrations/2026_09_28_000001_add_nav_favourites_autoopen_to_users_table.php`
- `app/Models/UserNavFavourite.php` (SoftDeletes)
- `app/Services/Navigation/NavFavouriteService.php` — resolve/validate paths against
  the route table + permissions; sync the user's list (create/restore/soft-delete/reorder)
- `app/Http/Controllers/Agent/NavFavouriteController.php` — one `update` action
- `tests/Feature/Navigation/SidebarFavouritesTest.php`

**Modified**
- `routes/web.php` — `PUT /my-portal/favourites` → `agent.portal.favourites.update` (`auth`)
- `app/Http/Controllers/Agent/AgentPortalController.php` — pass the user's
  favourites + auto-open flag to the portal view
- `resources/views/agent/portal.blade.php` — `Favourites` tab
- `resources/views/layouts/corex-sidebar.blade.php` — button + panel above the user name
- `resources/css/corex.css` — `.corex-fav-*` classes
- `app/Models/User.php` — `navFavourites()` relation + `nav_favourites_autoopen` cast
- `database/schema/mysql-schema.sql` — refreshed snapshot
- `.ai/CHAT_STARTER.md` — status entry

---

## 10. Test matrix (BUILD_STANDARD §5)

`tests/Feature/Navigation/SidebarFavouritesTest.php`

1. **Happy path** — pin three real pages with an explicit order; all three render
   in the sidebar panel in that order.
2. **Lazy-but-valid shortcut** — pin exactly one page, no reorder touched; works
   end to end.
3. **Each optional field omitted** — save with the auto-open checkbox absent; save
   with no `label` posted; save with `sort_order` absent. Each accepted, `label`
   and `sort_order` always populated.
4. **Empty save** — no pages ticked; list clears, no error, panel empty state.
5. **Un-pin soft-deletes** — row survives with `deleted_at` set; no hard delete.
6. **Re-pin after un-pin restores** — the UNIQUE + SoftDeletes collision path,
   asserted explicitly: create → soft-delete → recreate, no `QueryException`,
   exactly one row.
7. **Reorder persists** — drag order round-trips.
8. **Permission-denied path rejected** — a user without `view_rentals` posting the
   rentals path gets the plain-language rejection; their other picks still save.
9. **Unknown path rejected** — a path matching no route.
10. **Malformed key rejected** — `https://evil.example/x`, `../../etc`, empty string.
11. **Over the cap rejected** — 26 pages, clear message, nothing saved silently.
12. **Own-scoping** — user A's favourites never appear for user B; a post from B
    cannot touch A's rows (no id is accepted in the payload).
13. **Renamed/removed page renders gracefully** — a stored favourite whose route
    no longer exists is skipped in the panel, never a dead link or a crash.
14. **Auto-open flag persists** both directions.

Test data uses real CoreX paths and real sidebar labels, not `Test / Test`.

### Browser-level verification (Standard −1, mandatory — this touches Blade + Alpine)

Both the sidebar and the portal page are Blade with Alpine, so PHPUnit green is
explicitly **not** sufficient:

- `node scripts/verify-alpine-render.mjs` against the rendered sidebar and the
  rendered `#favourites` tab — zero leaked attribute text, every component
  constructs, every Alpine expression compiles.
- A real browser (Puppeteer, local) driving the actual controls: open the panel,
  assert it is **not taller than half the sidebar**, click a favourite and assert
  the navigation happens, tick/untick/reorder/save in the picker and assert the
  sidebar reflects it. Standard −1f — proving the endpoint is not proving the
  feature; the control a human clicks gets clicked.
- A **before** capture of the sidebar is taken prior to any edit (Standard −1o).

---

## 11. Deliberately NOT in the Setup Wizard

`users.nav_favourites_autoopen` is **not** added to
`config/agency-onboarding-copy.php`, and this is a declared omission, not an
oversight.

CLAUDE.md Non-negotiable #10a governs **agency settings** — "anything an agency
configures about how CoreX behaves *for them*": a column on `agencies`, a
`PerformanceSetting` key, a toggle on Company Settings. This is a **per-user
personal preference**, stored on `users`, set by each individual in their own My
Profile. There is nothing for an agency to configure at onboarding — the wizard
has no per-user step and no user to configure it for.

**Flagged for Johan** per #10a item 3: if he wants an agency-level default for
new users, that is a different (and larger) feature, and his call to make.

---

## 11a. Audit findings (2026-09-28) — fixed in build

Three issues found auditing the feature before it lands. All fixed; each has a
named check.

1. **Resolving a favourite mutated the live request's route.** `RouteCollection::match()`
   calls `Route::bind()`, which writes parameters onto the SHARED Route object in
   the global collection — the same objects serving the current request. Since the
   sidebar resolves favourites on every render, a favourite pointing at a
   parameterised path could rewrite the live route's parameters mid-render.
   Replaced with a read-only `uri => Route` map over PARAMETERLESS GET routes
   only. Also tightens security: a crafted post of `/corex/properties/5` now
   resolves to nothing and is refused, so a favourite can never be a deep link to
   one record.

2. **Demo-curated pages stayed reachable through Favourites.** The demo hide pass
   only walks `.corex-nav-root`; the Favourites panel lives in the user section, so
   a demo user who pinned a page before it was curated out kept a live shortcut to
   it. `$_navFavourites` is now filtered against `$_demoHiddenNav` when
   `$_demoNavApply` is true. (Curation is decluttering, not access control — but a
   walkthrough should not show a page the curator removed.)

3. **The empty state offered a link the user could not open.** My Portal is gated on
   `access_my_portal`; a user without it got a 403 from the "choose your pages"
   link — a dead button, which STANDARDS forbids. The block now renders only when
   the user can reach the picker OR already has favourites, and the link only when
   they can reach the picker. The gate is derived from the route's own middleware,
   not a hardcoded permission key, so it cannot drift.

### Known limitation, stated rather than hidden

Two sidebar entries differ from a sibling only by a query string —
"History & Logs" (`tools.commission?section=history`) and "Authorise Documents"
(`…myDocuments?filter=authorisation`). §8 strips query strings, so each collapses
onto its plain sibling's key: ticking one appears to tick the other, and pinning
"Authorise Documents" lands on the unfiltered My E-Sign Documents. 2 of ~150
entries. Fixing it means carrying a bounded query string in `nav_key`; deferred
rather than changed blind while the test database is down.

## 12. Acceptance criteria

1. A **Favourites** button sits directly above the user's name in the sidebar for
   every signed-in user, showing a live count.
2. Clicking it opens a panel **upwards over the sidebar** that is **never taller
   than half the sidebar**, scrolling internally past that point, on both a live
   (no env banner) and a local/demo (24px banner) layout.
3. **My Profile → Favourites** lists every page the signed-in user can open,
   grouped by sidebar section, with search and an All / Only-my-favourites filter.
4. Ticking pages and saving makes them appear in the sidebar panel immediately on
   the next page load, in the user's chosen order.
5. Dragging (or ▲/▼) reorders them, and the order persists.
6. Un-ticking removes a page from the panel and **soft-deletes** the row; re-ticking
   it later restores it with no error.
7. With auto-open on, the panel opens by itself on the first page after signing in,
   and stays closed for the rest of the session once the user closes it.
8. A user with no favourites sees the button and an empty state pointing them at
   My Profile.
9. A user never sees, and can never pin, a page their permissions do not allow.
10. No user can read or write another user's favourites by any route.

---

## 13. Spec conformance

No governing spec existed for this feature; this document is it. Related and
deliberately reused: `.ai/specs/demo-sidebar-curation.md` (the `CorexNavSearch`
walker and the `p:` / `g:` key convention) and
`.ai/specs/ellie-navigation-atlas.md` (route-table permission derivation).
