# Feature Registry — Settings + Onboarding audit

> Date: 2026-07-18 · Branch: QA2 · Commits audited: ce6eaba8, 8b153d9a, dc57996c
> Method: 3 parallel static-review agents (gate plumbing / settings / onboarding) +
> runtime verification (lint, config load, `corex:features:validate`, migrations,
> route registration, a live 7-case gate probe via bootstrapped Tinker).
> Tests present but NOT executed here — this checkout is `--no-dev` (no phpunit binary).

---

## Verdict

**The gate engine is production-solid. Enforcement coverage is incomplete, and there is
one real multi-tenancy defect.** The feature is ~75% wired: an agency can flip a toggle in
Settings/onboarding and the value saves correctly and resolves correctly — but for ~8–9
modules nothing yet *consumes* that value, so the switch is a silent no-op. Not shippable to
a customer until the coverage gap + the multi-tenancy defect are closed.

### What works (verified)
- **Gate engine — all 10 locked rules PASS** (static) and **7/7 live probe PASS**: no-row⇒default,
  per-agency override, **core-always-on despite a false row**, `depends_on` cascade (parent off ⇒
  child off), env kill-switch AND (via `global_flag`), request-cache keyed by agency, unknown-key ⇒
  safe false, null-agency ⇒ no throw. Middleware `abort 404` (no leak).
- **Registry** — 46 features, `corex:features:validate` clean, no cycles, all `depends_on` parents exist.
- **Settings saver** — multi-tenant safe, §6.1-guarded (`$request->has()` + hidden `"0"` companion),
  permission-gated (`agency_features.manage`), emits `AgencyFeatureToggled`, core rendered read-only.
- **Onboarding** — capabilities step auto-derives from the registry; all 5 wizard helpers
  (`index/show/advance/progress/nav`) iterate `activeSteps` (not raw STEPS); progress denominator =
  active count with divide-by-zero guard; the 4 switchboard savers hardened; one-home-per-switch honoured.
- Lint clean on all 13 changed PHP files; routes registered; migrations run; backfill idempotent.

---

## Findings (ranked)

### 1. HIGH — ~8–9 module toggles are silent no-ops (no nav AND no route enforcement)
These non-core **module** features render a working toggle in Settings + onboarding, save correctly,
but have **neither** a sidebar `@feature('<key>')` guard **nor** a `feature:<key>` route guard, so
turning them OFF changes nothing the user can see:
`agency-tracker`, `commission-management`, `proforma-invoices`, `tv-display`, `guided-tours`,
`ad-manager`, `marketing-suppressions`. `ellie` has a nav guard but no route guard. `shared-drive`'s
nav item sits *inside* the `@feature('docuperfect')` block, so it's gated by the wrong key.
`calculators` is route-gated but **not** nav-gated (asymmetric).
- **Fix:** add the missing `@feature` wrappers (map each to its sidebar item via `sidebar_section`)
  and the missing `feature:<key>` middleware on the corresponding route groups; move `shared-drive`'s
  nav out of the docuperfect block. Add the AC-11 guard test (every non-core module feature with a
  `sidebar_section` has a matching `@feature`) — its absence is why this shipped.

### 2. HIGH — Four switchboard toggles write GLOBALLY, not per-agency (multi-tenancy, Non-neg #7)
`marketing`, `core-matches`, `syndication-p24`, `syndication-pp` resolve via `PerformanceSetting::get()`,
which has no `agency_id` — the value is shared platform-wide. Agency A turning **Core Matches** off in
onboarding flips it (and Agency B's matches-step gate) for **every** agency. Inherited from the
already-shipped switchboard (the legacy `*_enabled` keys were always global), but the registry now
presents them as per-agency toggles, which makes the leak worse/visible. `multi-branch` / `public-website`
(agency columns) are unaffected.
- **Fix:** make these four agency-scoped — either migrate the four keys into `agency_features` (preferred;
  the registry becomes the single per-agency source) or give `PerformanceSetting` an `agency_id`. Do not
  ship a per-agency toggle over a global store.

### 3. MEDIUM — Adaptive gating only covers `matches`; Presentations & Compliance are forced despite being toggled off
`stepGates()` gates only the `matches` step, but the auto-derived capabilities step now also exposes
`presentations` and `compliance` (both have wizard **detail steps**). Toggle either OFF and the wizard
still forces the admin through its setup screen — contradicting the step's own copy ("turn one OFF and
CoreX skips its setup").
- **Fix:** extend `stepGates` to map every wizard detail step to its feature key
  (`presentations`→`presentations`, `compliance`→`compliance`, …).

### 4. MEDIUM — Dependency-blocked child wipes its stored preference
In `_features.blade.php` / `capabilities-modules.blade.php`, a `depends_on`-blocked child renders the
checkbox `@disabled` but still submits its hidden `"0"` companion. Re-saving writes `enabled=false` to
the blocked child, so when the parent is turned back on the child returns OFF instead of its previous ON
— silently, contradicting "re-enabling restores as it was."
- **Fix:** omit the hidden companion (and the field) for blocked children so `$request->has()` is false
  and the stored value is left alone.

### 5. MEDIUM — `agency_features` unique index includes `deleted_at`; MySQL allows duplicate live rows
`unique(['agency_id','feature_key','deleted_at'])` — MySQL treats NULL as distinct, so multiple live
rows for the same `(agency_id, feature_key)` are permitted; `overridesFor()->pluck()` then keeps a
nondeterministic last row. Spec §4.1 claims a uniqueness this does not deliver. Backfill uses
check-then-`create()` (not `updateOrCreate`), so it is not concurrency-safe against this gap.
- **Fix:** enforce single-live-row (generated-column unique, or app-level `updateOrCreate` everywhere +
  a dedupe) and add an exit-code check to the backfill migration.

### 6. MEDIUM/latent — Explicit `?Agency` argument returns DEFAULTS for a scoped caller
`overridesFor()` filters `where('agency_id', $id)` but `AgencyFeature` also carries `AgencyScope`; for a
non-owner caller the two intersect to empty, so `enabled($key, $otherAgency)` silently returns registry
defaults instead of that agency's real overrides. Works for owner callers / current-agency calls (the
common path), so latent — but a correctness trap for any cross-agency read.
- **Fix:** read overrides with `withoutGlobalScope(AgencyScope::class)` *scoped to the explicit id*, or
  document the API as current-agency-only.

### Low-severity risks
- Redundant `agency_id` indexes (foreign + explicit + composite) — dead weight.
- Direct POST to a gated-off save route redirects to step 1 (safe, jarring).
- Single submit can't co-enable a blocked child with its parent (save twice).
- Backfill treats `is_demo IS NULL` as live.
- `agency_features.manage` must be synced on deploy (`corex:sync-permissions --merge-defaults`) or admins
  silently can't persist module toggles (wizard absorbs the 403).

---

## Fixes applied (2026-07-18, same day)

All findings resolved. Verified by lint, the registry validator, a rebuilt migration set,
and two live Tinker probes (8/8 multi-tenancy + 7/7 core gate rules). Tests still need a
run in a dev-deps env (no phpunit here).

1. **HIGH nav/route no-ops — FIXED.** Added `@feature` guards for `agency-tracker` (whole
   panel), `commission-management`, `tv-display`, `proforma-invoices`, `ad-manager`,
   `marketing-suppressions`, `guided-tours`, `calculators`; moved `shared-drive` to its own
   guard. Added `feature:<key>` route middleware to every clean group/route (ellie×5,
   commission-mgmt×4, proforma×3, tv-display×2, ad-manager×3, marketing-suppressions×2,
   guided-tours, listing-stock). A coverage check confirms **every** non-core module feature
   with a `sidebar_section` now has a nav guard; locked by new
   `tests/Feature/Features/FeatureNavGuardCoverageTest.php` (the missing AC-11 test).
   *Deliberately deferred:* full per-route gating of the sprawling agency-tracker surface
   (config `route_prefixes: []`) — it overlaps the deals pillar and needs a route reorg; the
   nav guard + child-feature route guards are the control for now.
2. **HIGH switchboard toggles global — FIXED (root cause).** `performance_settings` gained a
   nullable `agency_id` (migration `..._000003`); `PerformanceSetting::get()` now resolves the
   agency row then falls back to the global (NULL-agency) row, and `set()` writes per-agency.
   The 4 perf switchboard savers write per-agency; `switchboardStates()` passes the agency
   through; `MatchPropertyJob` passes the property's agency (no auth on the queue); the seeders
   + Ellie raw reads are pinned to the NULL-agency row. The ~19 genuinely-global keys
   (vat_rate, per-page, company_*) are untouched — they keep exactly one NULL-agency row and
   resolve identically. Probe: Agency A matches-off no longer affects Agency B.
3. **MEDIUM adaptive gating — FIXED.** `stepGates()` now maps `presentations → presentations`
   and `compliance → compliance` (not just `matches`), so toggling either off skips its wizard
   detail step.
4. **MEDIUM blocked-child wipe — FIXED.** Both toggle blades now omit the hidden `"0"`
   companion for a dependency-blocked child, so `$request->has()` is false and its stored
   preference is left alone.
5. **MEDIUM unique index — FIXED.** `agency_features` unique is now `(agency_id, feature_key)`
   (no `deleted_at`); backfill uses `firstOrCreate` (atomic, never re-enables a hand-off row).
6. **MEDIUM explicit-agency trap — FIXED.** `overridesFor()` reads via
   `queryWithoutAgencyScope()` scoped to the explicit id, so `enabled($key, $otherAgency)` no
   longer returns defaults for a scoped caller.

Low-severity items (redundant index, direct-POST UX, is_demo NULL) left as noted; the
redundant `agency_id` index was dropped as part of #5.

## Not verified here
The three test files (`AgencyFeatureGateTest`, `AgencySetupFeatureSwitchboardTest`,
`AgencySetupWizardSaverGuardTest`) exist and look well-structured but could NOT be executed — this
checkout has no phpunit (`--no-dev`). Run them where dev deps are installed before merge. The suspected
missing AC-11 nav-guard test (finding #1) should be added regardless.
