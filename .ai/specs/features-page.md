# Features page + "OFF really means off" — Spec

> Status: **Part A (OFF really means off) and Part B (the page, style 1 "Clean list") BUILT on QA2 (2026-10-12).**
> Owner: Andre's lane (QA2). Requested by Johan/Andre 2026-10-12.
> Extends: `.ai/specs/corex-feature-registry.md` (§6.4 Settings → Features), `.ai/specs/roles-permissions.md`.
> Pillars: configuration layer over all four (Property, Contact, Deal, Agent) — it decides which modules an agency runs.

---

## 1. What this does and why

Three asks, one feature:

1. **Own page.** Features (on/off per module) moves out of the Settings rail into its own page with its own sidebar entry.
2. **OFF really means off — including in Role Manager.** A feature an agency switches off must not appear in Role Manager.
3. **Prove every switch works, on and off.**

Audit finding that shaped the work (2026-10-12): switching a feature off hid its menu item and ~65 route groups, but whole modules
stayed reachable by URL — e.g. Rentals' applications/inspections/inventories/work orders (~350 routes), Outreach (36), Agency Tracker (54),
Calculators (27), PDF splitter (19), Training (15) — because only routes someone remembered to wrap in `feature:` were closed.
Role Manager never looked at features at all.

## 2. Business behaviour (plain language)

Switching a feature **off** for an agency:
- hides it from the menu (unchanged);
- makes every page of it unreachable, even by typing the address — it simply "does not exist" (404);
- hides its permissions from Role Manager, with a one-line note naming the hidden features;
- **keeps everything**: records, and every role's saved permissions. Nothing is deleted.

Switching it back **on** restores the pages, and each role's permissions are exactly as they were.
A feature being on never *gives* anyone a permission — it only allows the area to exist; Role Manager still decides who may use it.
Switching off a parent also closes its children (e.g. Payroll → Leave; Marketing → Ad Manager).

## 3. Scope of "off" — what is and is not closed

Closed: every route that checks a permission owned by the feature, every route wrapped in `feature:<key>`, the sidebar, Role Manager.
Deliberately **not** closed (documented residual, 2026-10-12 measurement: 901 routes sit under switchable features' registered prefixes;
868 are covered; 33 are open):
- **Public token links** — `agent-onboarding` (12 routes, `onboarding/{token}…`) and `document-library` (3, `documents/download/{token}…`):
  recipients outside the agency must keep working links; nobody inside can create new ones while the feature is off.
- `communications` → `PUT corex/settings/email-setup/mail-intercept`: the **owner-only platform kill switch**; must never be feature-gated.
- `prospecting` → `GET /prospecting`: a 301 redirect to Market Intelligence, whose pages are gated.
- Training (15) and Core Matches index (1) were open and are now gated (`feature:training`, `feature:core-matches`).

## 4. Permission ownership (the mechanism)

Each registry row (`config/corex-features.php`) may declare:
- `permission_modules` — whole permission modules the feature owns (the grain Role Manager already groups by);
- `permission_keys` — individual keys it owns; **these win over module ownership**, so one module can be split
  (e.g. `manage_staff_take_on` belongs to *Staff Take-On* though it sits in the `leave` module; Agency Tracker lists its
  tracker keys individually because the same module also holds branch administration and the Buyers Report, which stay available).

`AgencyFeatureService`: `featureForPermission($key)`, `permissionBlockedByFeature($key)`, `blockedPermissionKeys()`,
`offFeaturesOwningPermissions()`. A permission is blocked when its owning feature resolves OFF (same resolution as everywhere:
core → always on, parent cascade, env kill-switch, per-agency row, default).

Enforcement points — one definition feeds all of them:
- **`CheckPermission` middleware** (every `permission:` route, web and API): a blocked permission → **404**, same as `feature:<key>`.
  With several keys (`permission:a,b`, OR semantics) the route closes only when *all* are blocked.
- **Role Manager** (`RoleManagerController`): blocked permissions are not listed; `savePermissions` and `copyPermissions`
  never delete or overwrite a blocked key's grants and ignore crafted posts for them (the save used to rebuild *every* grant
  from the form — hiding keys without this would have wiped them).
- `corex:features:validate` checks the new fields (real modules/keys, nothing owned twice, core owns nothing).
- **`FeaturePermissionOwnershipTest`** fails if a permission module is neither owned by a feature nor on its explicit
  always-available list (with reasons) — a new module cannot ship undecided.

## 5. The page (Part B — built, style 1 "Clean list")

- **Route/nav:** `GET /corex/features` (`corex.features.index`), sidebar entry under Settings, gated by `permission:agency_features.manage`
  (existing key) + core feature. `GET /corex/settings?s=features` redirects to it. The "Features (on/off)" entry leaves the Settings rail
  (`settings.blade.php` rail + the `_features` include). Saver stays `FeatureSettingsController@update` (its redirect target changes to the new page).
- **Layout (Johan's brief):** a **search bar**; the **feature list scrolls inside its own panel — the page itself does not scroll**.
- **Search:** label, explain, category, "what this changes". **Filter:** All / On / Off (+ category). **Sort:** by category (default, registry order), name.
  Counts ("12 of 47 on"). Empty state when nothing matches. Core rows locked "Always on"; switchboard rows read-only "Managed in its own settings";
  a child whose parent is off is disabled with "Turn on <parent> first".
- **Saver safety (spec §6.1):** every rendered toggle posts a hidden `0` companion; filtering must hide rows with CSS/`x-show`, never remove them from the form,
  and a blocked child posts nothing — so searching never changes a switch.
- **Multi-agency:** page and saver operate on `effectiveAgencyId()` only; no agency-specific wording or defaults.
- **Setup wizard (non-negotiable #10a):** no new setting is added — the wizard's capabilities step already auto-derives from the same registry.
- **Styling:** `.ai/DESIGN-SYSTEM.md` (AT-336 neutral system) tokens only; the chosen style applies within it.

## 6. Acceptance criteria / tests

Built and tested (Part A):
1. `tests/Feature/Features/FeaturePermissionOwnershipTest.php` (static): every module owned or allow-listed; key-over-module resolution; core owns nothing; validator passes.
2. `tests/Feature/Features/FeatureOffBehaviourTest.php`: for **every** feature that owns permissions — ON: all its permissions pass for a user who holds them; OFF: every one 404s; parent-off closes children;
   multi-key rule; core permissions and branch administration never blocked; Role Manager hides + names the feature; save keeps frozen grants and ignores crafted posts; copy leaves frozen grants alone;
   re-enable restores access with grants intact; the saver switches every module feature off and on and never touches core; the six switchboard features follow their own stores; agency isolation.
3. Existing guards still green: `FeatureNavGuardCoverageTest`, `AgencyFeatureGateTest`, `RoleManagerFunctionalTest`.

Part B (`tests/Feature/Features/FeaturesPageTest.php`, rendered over HTTP): page renders with search, every registry feature and the scrolling list; every module feature keeps its hidden input in the DOM (filter/search can never change a switch); gated by `agency_features.manage` (agent 403); `settings?s=features` redirects; Features is gone from the Settings rail and sections and the old partial is deleted; sidebar entry gated by the permission; saving returns to the new page and shows the saved state; a child of an off parent renders `disabled` and posts nothing.

## 7. Design styles (pick one)

Five mockups were produced (search bar + inner-scrolling feature list): 1 Clean list, 2 Card grid, 3 Category rail + list, 4 Compact table, 5 Dark command-style. **Chosen 2026-10-12 (Johan): 1 — Clean list.** Built with the design-system tokens (`--surface`, `--border`, `--text-*`, `--brand-button`), 6px radius, no agency colours or wording.

## 8. Files

Part A (done): `config/corex-features.php`, `app/Services/Features/AgencyFeatureService.php`, `app/Http/Middleware/CheckPermission.php`,
`app/Http/Controllers/CoreX/RoleManagerController.php`, `resources/views/corex/role-manager.blade.php`, `app/Console/Commands/ValidateFeatures.php`,
`routes/web.php` (training + core-matches gates), the two test files above.
Part B (done): new `app/Http/Controllers/CoreX/FeaturePageController.php`, `resources/views/corex/features/index.blade.php`, `routes/web.php` (`GET /corex/features` = `corex.features.index`; the saver keeps `POST /corex/settings/features` = `corex.settings.features.update`), `layouts/corex-sidebar.blade.php` (Features entry now lives in the **Settings** sub menu — see §Sidebar sub menu — no longer in the Company group), `corex/settings.blade.php` (rail entry + section removed), `corex/settings/_features.blade.php` (deleted), `SettingsController@index` (redirect), `FeatureSettingsController` (redirects to the new page), `role-manager.blade.php` (notice links to it), `tests/Feature/Features/FeaturesPageTest.php`. No new permission (reuses `agency_features.manage`).


## Sidebar sub menu (Johan 2026-10-12)

The flat Settings sidebar item became a **Settings** slide-panel group (`$activeGroup = 'settings'`) holding: **Settings** (`corex.settings`, `access_settings`), **Features** (`corex.features.index`, `agency_features.manage`) and **Users** (`admin.users`, `manage_users`). The group shows when the user holds any of the three; each child keeps its own permission. Features left the Company group. The group's active-group rule sits before the broad `deals-v2.*` rule so the service-types page (under `deals-v2.settings.*`) still opens it. Ellie's navigation atlas lists Features (`corex.features.index`). No new permission, no route change. Users was not previously in the sidebar at all. Test: `FeaturesPageTest::test_settings_is_a_sub_menu_...`.

Role Manager (Johan 2026-10-12): the `manage_users` switch (key unchanged, so no grant or route changes) now shows as **Users** under the **Settings** heading, beside Access Settings and Manage Features — three independent on/off switches (it used to be "Manage Users" under Franchise Admin). Run `corex:sync-permissions` to refresh the stored label/heading.

Role Manager in the sub menu (Andre 2026-10-14): the **Role Manager** entry (`corex.role-manager`, `access_role_manager`) moved out of the Company group into the Settings sub menu, after Users. The Settings group now also shows when the user holds `access_role_manager`, and the Company group no longer counts it. Route, permission and the page itself are unchanged; `corex.role-manager*` lights up the Settings group. Covered by the same `FeaturesPageTest` sub-menu test.

## Agency type (2026-10-14)

The page opens with a small card, **"What does your agency do?"** (Sales and rentals / Sales only / Rentals only), saved by its own
form to `corex.settings.agency-type.update` (`AgencyTypeController`, permission `agency_features.manage`) so it never posts, or
depends on, the switches below it. Changing it switches the matching tools on or off (`config/agency-types.php`); everything else
stays as set. Spec: `agency-onboarding-setup.md` §5.3. Test: `AgencyTypeFeatureSeedTest`.
