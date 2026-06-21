# Demo Sidebar Curation + Permanent System Owner

> Status: building · Owner: Andre · Branch: `AT-65-demo-page-to-only-show-certian-pages`
> Last updated: 2026-06-21

## Business requirement

The demo environment (`demo1.corexos.co.za`) is reset frequently. Two gaps:

1. **The System Owner login is lost on every reset.** When demo mode is on, a
   "System Owner" entry appears in the sidebar user menu and posts to
   `DemoOwnerLoginController`, which authenticates a *real* owner-role user. But
   no seeder guarantees such a user exists after a wipe, so the button leads to
   "Invalid credentials". We need a **permanent, always-seeded** owner account.

2. **Demos show the whole product.** A walkthrough for a small agency should
   only surface the relevant pages. We need the System Owner to be able to
   **curate which sidebar items (and sub-pages) appear** for demo-agency users,
   so a demo "only shows certain pages".

## Pillars touched

Cross-cutting (Agent/User pillar for the owner identity; presentation-layer
curation). No new pillar data — the curation config is a system-wide dev
setting, the owner is a `User` with the existing owner role.

## Decisions (confirmed with Andre, 2026-06-21)

- **Curation config is one global `dev_settings` row** (`demo_hidden_sidebar`),
  applied identically to every demo agency. Not per-agency.
- **The restriction affects only demo-agency members** — users whose effective
  agency has `is_demo = true` *and* who are not effective owners. System Owners
  and all real/production users always see the full sidebar.
- **The permanent owner login stays demo/non-prod only**, exactly like the
  existing `DemoOwnerLoginController` gate (`DemoLoginController::isEnabled()` =
  non-production AND `demo_mode_enabled`). The account is only *seeded* in
  `local`/`demo` environments — no standing root credential on production.

## Permanent System Owner

- Credentials: `Demo@corexos.co.za` / `Demo@1024`, role `super_admin`
  (the existing `is_owner` role), `is_active = true`, `agency_id = null`
  (platform identity).
- `database/seeders/SystemOwnerSeeder.php` — idempotent `updateOrCreate` on the
  email. Re-running never duplicates and always resets the password/role so a
  reset restores a known-good login.
- Wired into `DatabaseSeeder` **inside the `local`/`demo` environment gate**
  (alongside the demo seeders), so every `db:seed` / `migrate:fresh --seed` on a
  demo/local box recreates it, and staging/production never get the credential.
- Login flow is unchanged — the existing "System Owner" sidebar entry and
  `DemoOwnerLoginController` already do the right thing once the user exists.

## Sidebar curation

### Source of truth — no hand-maintained registry

The sidebar (`layouts/corex-sidebar.blade.php`) is ~100 hardcoded Blade items
gated by `@permission`. It already ships `window.CorexNavSearch.build()`, which
walks the rendered `.corex-nav-root` and returns `{label, href, parent, group}`
for every nav entry (top-level link, expandable group toggle, and panel
sub-item). The curator UI reuses this exact walker, so the checklist always
mirrors what the sidebar actually renders — zero drift, nothing to keep in sync.

### Stable keys

Each curatable entry gets a string key:
- Expandable group (whole section): `g:<groupKey>` where `<groupKey>` is the
  Alpine `push('<groupKey>')` id (e.g. `g:real-estate`, `g:compliance`).
- Link / sub-page: `p:<pathname>` — the URL path of the anchor href
  (e.g. `p:/corex/properties`). Same identifier the search index already keys on.

### Storage

`DevSetting` row `demo_hidden_sidebar` = JSON array of hidden keys.
Default `[]` (everything visible).

### Curator UI

New section on `/admin/dev-settings` (owner-only page), directly under the demo
mode toggle. Client-side it calls `CorexNavSearch.build()`, groups entries
(expandable sections with their sub-items, plus standalone pages), renders a
checkbox per entry (checked = hidden), pre-checks from the saved set, and on
submit posts the hidden keys to `PUT admin.dev-settings.demo-sidebar`.

### Enforcement (presentation)

In `corex-sidebar.blade.php`:
- `$_demoHiddenNav` = decoded saved set (always injected as `window.__demoNavHidden`,
  so the curator can pre-check).
- `$_demoNavApply` = `is_demo agency member && !effective owner`
  (injected as `window.__demoNavApply`).

A small JS pass (added to the existing sidebar `<script>`) runs only when
`__demoNavApply` is true: it removes group wrappers (`g:` keys), removes
anchors whose pathname matches (`p:` keys), drops any group panel left with no
sub-items, then refreshes the search index. Because it runs before the search
cache builds, search respects the curation automatically.

This is presentation curation of pages the demo user already has permission to
see — it declutters the showcase. It is non-production only (gated by demo
mode) and never touches real users or owners.

## Demo-mode toggle password gate

The "Enable demo mode (bypass login)" toggle on `/admin/dev-settings` is an
authentication bypass, so **changing it in either direction** (on→off or
off→on) requires the demo control password `Demo@on&off@$`.

- `DevSettingsController::update()` compares the current vs requested
  `demo_mode_enabled`; only when it changes does it require
  `demo_toggle_password` to match (constant-time `hash_equals`). Wrong/blank →
  redirect back with a validation error, demo mode left unchanged (other
  settings still save). Unchanged demo mode needs no password.
- The view reveals a password field (Alpine `x-show`) only when the toggle
  differs from its initial state, and shows the error inline.
- The password is a hardcoded controller constant (`DEMO_TOGGLE_PASSWORD`) —
  it's a confirmation gate, not a per-user secret.

## Files

- `database/seeders/SystemOwnerSeeder.php` (new)
- `database/seeders/DatabaseSeeder.php` (call seeder in the demo gate)
- `app/Http/Controllers/Admin/DevSettingsController.php` (index passes saved set;
  new `updateDemoSidebar`)
- `routes/web.php` (new `PUT admin/dev-settings/demo-sidebar`)
- `resources/views/admin/dev-settings/index.blade.php` (curator section)
- `resources/views/layouts/corex-sidebar.blade.php` (inject globals + hide pass)
- `tests/Feature/Admin/DemoSidebarCurationTest.php` (new)

## Acceptance criteria

1. After `php artisan migrate:fresh --seed` (local/demo), `Demo@corexos.co.za` /
   `Demo@1024` logs in via the System Owner entry and lands on the dashboard.
2. The seeder does not create the account on staging/production.
3. Dev Settings shows a "Demo sidebar" curator listing every sidebar item and
   sub-page, with saved hidden items pre-checked.
4. Saving hides the chosen items only for demo-agency members; owners and real
   users see the full sidebar; sidebar search no longer returns hidden items
   for demo members.
5. Hiding an entire section removes the section and its panel; hiding all of a
   section's sub-items collapses the now-empty section.
