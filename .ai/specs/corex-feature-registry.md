# CoreX Per-Agency Feature Registry — Spec

> Status: **Draft pending Johan approval** — 2026-07-18
> Owner: (QA2 lane)
> Pillars touched: ALL FOUR (Property, Contact, Deal, Agent) — the registry governs which
> *modules* an agency runs, and every module reads/writes one or more pillars. The registry
> itself owns only agency-scoped capability config (Non-negotiable #10 N/A — ingests no
> property/contact/deal data).
> Sister specs: `.ai/specs/multi-tenancy.md`, `.ai/specs/corex-domain-events-spec.md`,
> `.ai/specs/agency-onboarding-setup.md`, `.ai/specs/agency-onboarding-feature-switchboard.md`
> (which becomes the *onboarding face* of this registry — see §7).

---

## 0. One-paragraph summary

CoreX has ~50 modules (Presentations, DocuPerfect, Compliance, Payroll, Rentals, Prospecting,
Communications, …) but no per-agency way to say *"this agency uses these ten and not the other
forty."* Everything is gated only by **permission** ("may this USER touch it"), which is the
wrong axis for "does this AGENCY use this module at all" — an agency that doesn't do rentals
still has every agent's Role Manager showing rental permissions, and the Rentals panel still
renders for anyone holding `view_rentals`. This spec introduces a **per-agency feature gate**
that is a **new, orthogonal layer** on top of permissions: nav + routes require **permission
AND feature**; a feature turned OFF hides the module for the whole agency (never deletes data);
one **registry** (`config/corex-features.php`) is the single source that feeds nav-gating,
route-gating, the Settings page, AND the onboarding wizard — so adding a feature once surfaces
it in all four automatically. This is how Non-negotiables #2 (nav same-day) and #10a (wizard
surfacing) become **structural** instead of manual.

---

## 1. What this feature does and why (business requirement)

**Today.** A new agency gets *all* of CoreX. There is no supported way to sell/enable CoreX
module-by-module, to hide a module an agency will never use, or to phase a module in. The
`agency-onboarding-feature-switchboard.md` step added six capability toggles
(`marketing`, `syndication_p24`, `syndication_pp`, `matches`, `split_branches`, `website`) —
but those are six hand-coded rows writing to six ad-hoc stores (`PerformanceSetting` keys +
`agencies` columns). There is no registry, no generalised gate, and nav/route visibility is not
driven by them. The switchboard is the right idea at the wrong scale.

**This registry generalises it.** One catalogue of every toggleable feature; one per-agency
enablement store; one universal gate (`AgencyFeatureService::enabled($key)`); one
`@feature`/`feature:` pair mirroring `@permission`/`permission:`. The six switchboard toggles
become the **first six registry rows** (§7) — nothing is wasted, the switchboard becomes the
onboarding *face* of the registry.

### Why it makes CoreX *best*, not merely *working*
A permission system alone is *working*. A product that an agency can be **sold and provisioned
module-by-module**, whose nav adapts to what they bought, whose onboarding walks only the
modules they enabled, and whose Role Manager isn't cluttered with permissions for modules they
don't run — that is the shape of a real operating system, and it is the front door to per-module
pricing (ties into `agency-billing.md`). Critically, the registry makes "a new page appears in
nav the same day" and "a new setting appears in the wizard the same day" **structural** — you
add one registry row and all four surfaces pick it up — instead of four manual steps a future
build will forget.

---

## 2. Pillar connections

The registry configures **agency-wide capability state**; it does not own pillar rows, it
governs which modules (each of which reads/writes pillars) are live:

| Pillar | How a feature gate touches it |
|--------|-------------------------------|
| **Property** | Marketing / syndication / presentations / viewing-packs / prospecting govern how Properties are marketed, valued, and pushed to portals; the public website governs how they are shown. |
| **Contact** | Core Matches / Outreach / Communications govern how buyer-Contacts are matched, contacted, and archived. |
| **Deal** | Agency Tracker / Commission / DocuPerfect / Compliance feed the deal lifecycle and its paperwork. |
| **Agent** (`User`) | Payroll / Leave / Multi-branch / Training govern how agents are paid, grouped, and developed; permissions still gate *which* agents may act. |

Per Non-negotiable #4: reads agency-scoped capability config and writes enriched config back.
Not an island — it is the switchboard the pillars' modules already answer to.

---

## 3. Architecture decisions (LOCKED)

### 3.1 The feature gate is a NEW layer ORTHOGONAL to permissions
Two independent axes, **AND-composed**:

- **Feature** = *"does this AGENCY use this module?"* — per-agency, set by an admin/owner.
- **Permission** = *"may this USER touch it?"* — per-user (per-role), set in Role Manager.

Nav visibility and route access require **BOTH**: `@feature('rentals') && @permission('view_rentals')`.
- A feature being ON **never grants a permission** (a user still needs the permission).
- A feature being OFF hides the module for **everyone** in the agency, regardless of permission.
- The two never merge into one store. A feature key is not a permission key; `hasFeature()` is
  not `hasPermission()`. This separation is the load-bearing decision — it is what stops the
  registry from becoming a second, conflicting access-control system.

### 3.2 OFF hides only — it never deletes data (Non-neg #1/#6)
Turning a feature off hides its nav + 404s its routes. It does **not** soft-delete or touch any
record. Re-enabling restores access to every existing row exactly as it was. A feature toggle is
a *visibility/entry* control, never a data operation. (This mirrors `mentor_program_enabled` in
`agency-onboarding-setup.md` §5.2: "off means off, not merely hidden" applied to a whole module
— off means the module's doors are shut, but its filing cabinets are untouched.)

### 3.3 CORE features are never toggleable
The four pillars plus the surfaces every user needs are `core: true` and always resolve enabled
— the gate short-circuits them before any store lookup. Core set (§5.2). A `core` feature can
still carry a registry row (so nav/onboarding can reference it) but its toggle is never rendered
and `enabled()` returns true unconditionally.

### 3.4 The registry is the single source of truth for four surfaces
`config/corex-features.php` feeds — automatically, from one row:
1. **Nav gating** (`@feature` on the sidebar item — §5),
2. **Route gating** (`feature:<key>` middleware on the module's prefix group — §6/§8),
3. **Settings page** (the Features section renders the registry — §6),
4. **Onboarding wizard** (the capabilities step auto-derives its toggle list — §7).

Adding a feature = adding one registry row (+ wiring its nav/route in the same commit, per
Non-neg #2). The row is what makes #2 and #10a structural.

### 3.5 Resolution order (LOCKED)
`AgencyFeatureService::enabled($key, ?Agency)` resolves, in order, short-circuiting on the first
decisive answer:

1. **Unknown key** → `false` (fail-closed; log a warning — a typo must not silently pass).
2. **Global env kill-switch** (`config/features.php`, if the feature maps to a global flag) is
   **off** → `false` for everyone (outer AND; a platform-wide disable wins over any agency ON).
3. **`core: true`** → `true` (always; before any store read).
4. **`depends_on` parent resolves off** → `false` (dependency cascade; a child cannot be on
   while its parent is off — §3.6).
5. **`agency_features` row exists** for `(agency_id, feature_key)` → that row's `enabled`.
6. **No row** → the registry entry's `default` (bool).

Request-cached: the full resolved feature map for the current agency is computed once per request
(one query, `WHERE agency_id = :id`) and memoised, so `enabled()` in a Blade loop is O(1).

### 3.6 Dependency cascade (`depends_on`)
A feature may declare `depends_on: [parentKey, …]`. If **any** parent resolves off, the child
resolves off (regardless of its own row). This is evaluated in the resolver, not stored, so
toggling a parent off instantly cascades. Cycles are forbidden (validated by a test + the sync
command). Example: `esign` `depends_on` `docuperfect`; `leave` `depends_on` `payroll` (proposed
— §17 open decision).

### 3.7 Multi-tenancy (Non-neg #7 / multi-tenancy spec)
`agency_features` carries `agency_id` from migration day one, uses `BelongsToAgency` (auto-fill +
`AgencyScope`). No `withoutGlobalScope` in request code. The **owner tracking / cross-agency**
paths (if any) use `queryWithoutAgencyScope()` explicitly, gated on `isOwnerRole()`. The gate
reads the agency via the canonical `$user->effectiveAgencyId()` (never `session('active_agency_id')`
directly), so an owner using the agency switcher sees the switched-into agency's features.

### 3.8 Domain event (Non-neg #9)
Toggling a feature emits `App\Events\AgencyFeatureToggled` (past-tense fact) carrying
`agencyId`, `featureKey`, `enabled` (new state), `changedByUserId`. Added to the events
catalogue (`corex-domain-events-spec.md` §5). **Registration reality (memory / AT-261):** event
auto-discovery is OFF — any listener must be explicitly registered in `AppServiceProvider::boot()`,
and a **queued** listener on a domain event fatals (`AbstractDomainEvent`'s readonly `$eventId`
can't be restored in the child scope). So: the listener is **sync** and does only cheap work
(bust the request cache / write an audit row); anything heavier dispatches a **Job carrying
scalars**. v1 needs no downstream listener beyond the universal `RecordDomainEvent` audit writer.

---

## 4. Data model / migrations

### 4.1 New table `agency_features`
Model `App\Models\AgencyFeature` — `use BelongsToAgency, SoftDeletes` (Non-neg #1).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, **NOT NULL**, indexed | multi-tenancy day-one (#7) |
| `feature_key` | string(80), indexed | matches a `config/corex-features.php` key |
| `enabled` | boolean, default true | the per-agency override |
| `updated_by` | FK → users, nullable | who last flipped it (audit) |
| timestamps + `deleted_at` | | SoftDeletes |

- **Unique index** `(agency_id, feature_key)` among non-soft-deleted rows (one live override per
  feature per agency). Resolution is registry-default when no row exists — the table stores only
  *deviations from default*, so it stays small.
- Casts: `enabled => boolean`.
- No `enabled_at`/history table in v1 — the domain-event audit log (`domain_event_log`) is the
  history of toggles.

### 4.2 Backfill migration + idempotent command (BUILD_STANDARD §8)
Agencies that exist before this feature must see **no change on deploy** — every module currently
visible to them must resolve ON. Because "no row ⇒ default", and defaults are set so the
*current* behaviour is preserved (§5.3), a fresh agency needs **no backfill rows at all** for
default-ON features. The backfill exists for the few features whose registry **default is OFF**
but which some existing agency is currently *using* (e.g. an agency that already has the
switchboard's `matches_enabled` PerformanceSetting on, or rentals data): it writes an explicit
`enabled = true` row so a default-OFF feature they already use is not hidden.

- Migration `xxxx_backfill_agency_features` delegates to idempotent command
  `agency:backfill-features` (runs on `migrate --force`, safe to re-run).
- The command, per existing live (non-demo) agency, sets `enabled = true` for any feature the
  agency is **currently exercising** — detected from existing signals: the switchboard's
  `PerformanceSetting`/`agencies` columns (marketing/matches/syndication/split-branches/website),
  presence of rows in a module's tables (e.g. `rental_*`, `payroll_*`, `presentations`), or the
  module's `access_*` permission being granted to any role in that agency. **Conservative:
  default-ON features are left rowless (they resolve ON anyway); only default-OFF-but-in-use gets
  an explicit ON row.** No feature is ever turned OFF by the backfill.
- New migration ⇒ `php artisan schema:dump` (against the TEST DB — memory
  [[schema_dump_from_test_db]]) + commit the snapshot (#12a).

### 4.3 No changes to existing tables
The switchboard's existing stores (`PerformanceSetting` keys, `agencies.split_branches_enabled`,
`agencies.website_enabled`) are **kept** as the backing store for those six capability toggles
(§7) — the registry does not migrate them into `agency_features`; it *reads through* to them for
those six keys via a per-feature resolver adapter (§7.2), so the switchboard and the settings
page keep writing where they always did. New module features (rentals, payroll, …) use
`agency_features` rows. This avoids a risky data migration and keeps the six switchboard toggles
on their proven savers.

---

## 5. The catalogue — `config/corex-features.php`

### 5.1 Entry shape
```php
'rentals' => [
    'label'            => 'Rentals',
    'category'         => 'People & Property',
    'explain'          => 'The full rental workflow — lease capture, active/expired lease '
                        . 'tracking, rental document types and rent-related reminders.',
    'affects'          => 'Whether the Rentals area and its leases appear for your agents, and '
                        . 'whether CoreX chases rental renewals. Off hides it; existing leases '
                        . 'are untouched and reappear when you turn it back on.',
    'default'          => false,        // bool — on/off for a brand-new agency
    'core'             => false,        // core features are never toggleable (always on)
    'depends_on'       => [],           // [feature keys] — off if any parent is off
    'nav_permission'   => ['view_rentals'], // the permission(s) the nav item already checks
    'sidebar_section'  => 'hidden.rentals',  // sidebar panel/group key (documentation + nav map)
    'settings_section' => 'feature-rentals', // $railGroups anchor in corex/settings.blade.php
    'route_prefixes'   => ['rental', 'rentals'], // for the phase-4 feature:<key> middleware
    'global_flag'      => null,         // optional key in config/features.php for the outer AND
],
```
- `explain` — a full sentence: what the module is (STANDARDS F.8, no jargon, no codenames).
- `affects` — rendered "What this changes:" — a concrete, observable consequence; **tautologies
  forbidden** (parent onboarding §5.1). "Whether rentals are enabled" is banned; "whether the
  Rentals area appears and whether CoreX chases rental renewals" is required.
- `route_prefixes` — the `->prefix('…')` strings (verified from `routes/web.php`) the phase-4
  `feature:<key>` middleware attaches to. Empty for pure-capability toggles (§7) with no prefix.
- `nav_permission` / `sidebar_section` — the mapping the sidebar `@feature` guard uses (§5.4).

### 5.2 CORE features (never toggleable — `core: true`)
Confirmed from the sidebar + route enumeration. These carry a registry row (so nav/onboarding can
reference them) but their toggle is never rendered and `enabled()` is always true:

`dashboard` (Command Center), `properties`, `contacts`, `deals` (Deal Register DR1),
`agents` (Users/people admin), `my-portal`, `settings`, `company-settings`,
`role-manager` (permissions governance). The entire **System Developer** band (`@if($isOwner)`)
is platform-owner tooling — **not tenant features at all** and excluded from the registry.

### 5.3 The toggleable catalogue (derived — grain is an OPEN DECISION §17)
Grouped by category. `def` = proposed default for a NEW agency. Route prefixes are the verified
`->prefix()` strings; a `+` means "several — see the route audit". **Grain, defaults, and
core-vs-toggle are exactly what Johan signs off (§17)** — this is the derived starting point, not
a fait accompli.

**Switchboard-origin capability toggles (folded in as the FIRST 6 rows — §7):**

| key | label | def | backing store (kept) |
|-----|-------|-----|----------------------|
| `marketing` | Marketing | on | `PerformanceSetting: marketing_enabled` |
| `syndication-p24` | Publish to Property24 | off | `PerformanceSetting: syndication_p24_enabled` |
| `syndication-pp` | Publish to Private Property | off | `PerformanceSetting: syndication_pp_enabled` |
| `core-matches` | Core Matches | on | `PerformanceSetting: matches_enabled` |
| `multi-branch` | Multi-branch offices | off | `agencies.split_branches_enabled` |
| `public-website` | Public website | off | `agencies.website_enabled` |

**Module/page features (new `agency_features` rows):**

| key | label | category | def | depends_on | nav_permission | route_prefixes |
|-----|-------|----------|-----|------------|----------------|----------------|
| `presentations` | Presentations & CMA | Valuations | on | — | `access_presentations` | `presentations`, `corex/presentations/refresh-requests` |
| `commercial-evaluations` | Commercial Evaluations | Valuations | off | — | `access_commercial_evaluations` | `commercial-evaluations` |
| `prospecting` | Market Intelligence / Prospecting | Prospecting | on | — | `access_prospecting` | `prospecting`, `corex/market-intelligence`, `corex/tracked-properties` |
| `viewing-packs` | Viewing Packs | Properties | on | — | `access_viewing_packs` | `viewing-packs` |
| `portal-leads` | Portal Leads | Prospecting | off | — | `access_portal_leads` | `real-estate/portal-leads` |
| `outreach` | Seller Outreach & Canvassing | Prospecting | off | — | `outreach.summary.view` | `real-estate/outreach-*`, `contacts/{c}/outreach`, `settings/outreach-templates`, `compliance/seller-info` |
| `ad-manager` | Ad Manager | Marketing | off | `marketing` | `access_ad_manager` | `tools/ad-manager` (loose) |
| `docuperfect` | DocuPerfect (documents & e-sign) | Documents | on | — | `access_docuperfect` | `docuperfect` (incl. `docuperfect/compiler`, e-sign) |
| `document-library` | Document Library | Documents | on | — | `access_document_library` | `documents` |
| `shared-drive` | Shared Drive | Documents | off | — | `access_shared_drive` | `documents/shared-drive` |
| `filing-register` | Filing Register | Documents | on | — | `access_filing_register` | `filing-register` (loose) |
| `compliance` | Compliance (FICA/RMCP/Policy/Screening) | Compliance | on | — | `access_compliance` | `compliance/*` (fica, rmcp-dashboard, policy-dashboard, screenings, verification-queue, document-types, agency-settings, whistleblow) |
| `communications` | Communications (WhatsApp/email capture & archive) | Communications | off | — | `access_communication`+ | `communications/*`, `compliance/communication*`, `settings/email-setup`, `my-portal/communication-capture` |
| `agency-tracker` | Agency Tracker (worksheet/targets/performance/deal register) | Deals | on | — | `access_agency_tracker` | worksheet/`bm`/`admin` performance + `deals-dr2` |
| `commission-management` | Commission Management | Deals | on | `agency-tracker` | (role) | `commission` (loose) |
| `payroll` | Payroll | People | off | — | `manage_payroll` | `payroll` |
| `leave` | Leave Management | People | off | `payroll` | `manage_leave`+ | `payroll/leave`, `my-portal/leave` |
| `staff-take-on` | Staff Take-On | People | off | — | `manage_staff_take_on` | `staff-take-on` |
| `agent-onboarding` | Agent Onboarding (applications) | People | off | — | (role) | `onboarding` |
| `rentals` | Rentals | People & Property | off | — | `view_rentals` | `rental` |
| `ellie` | Ellie AI | AI & Learning | on | — | `access_ellie` | `ellie` (loose) |
| `training` | Training / LMS | AI & Learning | on | — | (route-exists) | `training`, `training-help` |
| `knowledge-base` | Knowledge Base | AI & Learning | on | — | `access_knowledge_base` | `admin/knowledge` |
| `pdf-suite` | PDF Suite | Tools | on | — | `access_pdf_suite` | `tools/pdf-suite` |
| `image-converter` | Image Converter | Tools | on | — | `access_image_converter` | `tools/image-converter` |
| `calculators` | Calculators (commission/CMA/deposit) | Tools | on | — | `access_calculators` | `deposit-interest-calculator`, `calculators` (loose) |
| `trust-interest` | Trust Interest Register | Tools | off | — | `access_trust_interest` | `admin/deposit-trust-interest` |
| `proforma-invoices` | Proforma Invoices | Deals | off | — | `proforma.manage` | `proforma` |
| `marketing-suppressions` | Marketing Suppressions | Marketing | off | `marketing` | `marketing_suppressions.view` | `admin/marketing-suppressions` (loose) |
| `tv-display` | TV Display | Tools | off | — | `manage_tv_messages` | `admin/tv-messages`, `bm/tv-messages` (loose) |
| `guided-tours` | Guided Tours | Tools | on | — | (none) | `corex/guided-tours` (loose) |

That is **6 capability + 31 module = 37 toggleable features** across 12 categories — inside the
20-40 target, at the fly-out-panel / settings-section grain. §17 lists the merges Johan may want
(e.g. fold `commission-management` into `agency-tracker`, `ad-manager` into `marketing`,
`trust-interest` into `calculators`, split `docuperfect`/`esign`).

### 5.4 Sidebar/route cross-reference is VERIFIED, not guessed
Every `route_prefixes` value above is a real `->prefix('…')` string from `routes/web.php`; every
`nav_permission` is the real `@permission(...)` guard on that sidebar item. "(loose)" marks a
prefix that lives inside the broad `corex` auth group or has no dedicated prefix group — those
route-gate **per-route**, not by prefix (§8). This honesty is the point of the investigation.

---

## 6. The gate mechanism (mirror the permission system exactly)

Investigated verbatim (agent audit); mirror each 1:1.

### 6.1 `AgencyFeatureService`
`App\Services\Features\AgencyFeatureService`:
- `enabled(string $key, ?Agency $agency = null): bool` — the §3.5 resolver, request-cached.
- `all(?Agency $agency = null): array` — the resolved `key => bool` map (drives Settings + the
  wizard's auto-derive).
- `resolveAgencyId()` — copies the canonical guarded idiom from `BelongsToAgency`:
  `method_exists($user,'effectiveAgencyId') ? $user->effectiveAgencyId() : ($user->agency_id ?? null)`.
- Request cache: a `['agencyId' => map]` memo on the singleton; busted by `AgencyFeatureToggled`.
- The six switchboard keys resolve through a small **adapter** that reads their existing store
  (§7.2) instead of `agency_features`, so there is one `enabled()` API over both stores.

### 6.2 `hasFeature()` on User + `feature()` helper + `@feature` directive
- `User::hasFeature(string $key): bool` → `app(AgencyFeatureService::class)->enabled($key)`
  (mirrors `User::hasPermission`).
- `function feature(string $key): bool` global helper (mirrors the existing permission helper).
- **Blade directive** registered right after `@permission` at `AppServiceProvider.php:702`,
  identical shape (`Blade::if`, not `Blade::directive`):
  ```php
  Blade::if('feature', fn (string $key) => auth()->check() && auth()->user()->hasFeature($key));
  ```
  Usage: `@feature('rentals') … @endfeature`. Guests → false (fail-closed).

### 6.3 `CheckFeature` middleware + alias
`App\Http\Middleware\CheckFeature` mirrors `CheckPermission` (variadic OR semantics) but
**`abort(404)`** when off — a disabled module must be *invisible*, not *forbidden* (a 403 leaks
that the module exists):
```php
public function handle(Request $request, Closure $next, string ...$featureKeys): Response
{
    $svc = app(AgencyFeatureService::class);
    $allowed = collect($featureKeys)->contains(fn ($k) => $svc->enabled($k));
    abort_unless($allowed, 404);
    return $next($request);
}
```
Alias `'feature' => \App\Http\Middleware\CheckFeature::class` added to the `$middleware->alias([…])`
block in `bootstrap/app.php` (alongside `permission`, `agency.setup.portal`, …). Route usage:
`->middleware('feature:rentals')` — composes with the existing `permission:view_rentals` (both
must pass).

### 6.4 The Settings "Features" page
- New **"Features"** rail entry in the **Modules** `$railGroups` group of
  `resources/views/corex/settings.blade.php` (in-page section, anchor `features`). It renders the
  registry **grouped by `category`**, each feature a toggle showing its `label` + `explain` +
  "What this changes:" `affects`; core features are shown as a locked "Always on" row (no toggle);
  a feature whose `depends_on` parent is off renders disabled with a "Turn on <parent> first" note.
- **One canonical saver** `FeatureSettingsController@update`:
  - `$request->has($key)`-guarded per toggle (parent onboarding §6.1 — MANDATORY); the form posts
    a **hidden `"0"` companion** for every rendered toggle so unchecked still saves false and an
    *absent* key leaves the value alone. Never a bare `$request->boolean()`.
  - Writes each toggled key: switchboard-six → their existing savers (§7.2); module features →
    `AgencyFeature::updateOrCreate(['agency_id'=>…,'feature_key'=>$key],['enabled'=>…,'updated_by'=>…])`.
  - Emits `AgencyFeatureToggled` per changed key (only on an actual state change).
- **New permission** `agency_features.manage` in `config/corex-permissions.php` (module
  `settings`, `admin` via all-minus-exclude, owner bypass) — gates the Features section (sidebar
  entry + route middleware + controller). After deploy: `corex:sync-permissions --merge-defaults`
  (multi-tenancy spec). Surfaced in the wizard per §7 (the capabilities step *is* the surfacing).

### 6.5 `corex:sync-features` (optional, symmetry with permissions)
The registry is a config file (no DB catalogue table needed — `agency_features` stores only
overrides, keyed by the config key). A light `corex:features:validate` command asserts the config
is well-formed (no dependency cycles, every `route_prefixes`/`nav_permission` resolvable, no
orphan `settings_section`) and is run in the focused test. No table sync is required (unlike
permissions, which need a `nexus_permissions` catalog row per key for Role Manager).

---

## 7. Onboarding integration — the capabilities step auto-derives from the registry

### 7.1 Auto-derive (supersedes the hand-coded 6)
`agency-onboarding-feature-switchboard.md`'s `capabilities` step currently hand-codes six toggle
controls. Under this registry it **auto-derives** its control list by iterating
`AgencyFeatureService::all()` for the **non-core** features, grouped by `category`. Adding a
registry row therefore surfaces the toggle in onboarding automatically — Non-neg #10a becomes
structural. The step's copy (`what` card) is unchanged; each toggle's `explain`/`affects` come
straight from the registry entry (single source — the settings page and the wizard render the
same strings, they cannot drift).

### 7.2 The six switchboard toggles become the first six registry rows
The six existing toggles are registered as registry entries (§5.3) whose **backing store is kept**
(PerformanceSetting keys + `agencies` columns). A per-feature **store adapter** on
`AgencyFeatureService` routes those six keys' reads/writes to their existing store and their
existing savers (`updateMarketingEnabled`, `updateSyndicationPortals`, `updateMatchesEnabled`,
`updateSplitBranches`, `toggleWebsite`), so:
- the switchboard's already-shipped savers + §6.1 hardening are reused (no rewrite),
- `enabled('core-matches')` returns the same value the matches gate already reads,
- nothing about the shipped switchboard behaviour changes — it just now *is* the onboarding face
  of the registry.

### 7.3 Adaptive step-gating generalises
The switchboard spec's step-gating (`matches` step skipped when `matches_enabled` off) generalises:
`AgencyOnboardingSetup::stepGates()` reads `AgencyFeatureService::enabled($key)` for each
feature-detail step, and the progress denominator is the active-step count. Adding a feature with
a detail step registers one `stepGates` entry. (This is a refactor of the switchboard's already-
generic `stepGates()`/`activeSteps()` — see that spec §7.)

### 7.4 Required edit to the switchboard spec (on approval)
On approval, `agency-onboarding-feature-switchboard.md` gets a note that its `capabilities` step
**now derives its toggle list from the feature registry** (the six controls become the first six
registry rows; the step iterates `AgencyFeatureService::all()` non-core by category). Listed in
§14 files-to-modify. **Not edited now** — that spec stays as shipped until this registry is
approved, so we don't pre-empt Johan.

---

## 8. Honest route-gating scope (no over-claim)

Route-gating is **defense-in-depth**; nav-gating delivers the visible win. The route audit
(97 prefix groups) classifies cleanly:

| Class | Count | Route-gating approach |
|-------|-------|-----------------------|
| **(a) Clean single-module prefix group** | ~61 | Add `feature:<key>` to the group `->middleware([...])` — one line per group. **Phase 4 targets, one prompt per module.** |
| **(b) CORE pillar group** | ~21 | **Never feature-gate** (properties/contacts/deals/dashboard/settings/api). Permission-gated as today. |
| **(c) Broad / shared per-route** | ~15 | Owner-only admin bundles + mixed-permission groups. Route-gate **per-route where a clean feature applies**; otherwise nav-gating + the controller's existing permission is the guard. Tracked per module, added incrementally — **no blanket claim that every route is feature-gated in v1.** |

**Stated limitation:** many routes live inside the one broad `Route::middleware(['auth','verified'])
->prefix('corex')` group (lines ~1401–3125) with per-route permissions. Feature route-gating
applies **cleanly to the ~61 class-(a) prefix groups first**; loose routes (marked "(loose)" in
§5.3) are added per-module as each module's phase-4 slice lands. **Nav-gating (phase 3) hides the
entry for the whole agency immediately; route-gating (phase 4) is the belt-and-braces that stops
a hand-typed URL.** A feature is "nav-gated" the day its `@feature` guard lands; it is
"route-gated" when its prefix group (or each loose route) gets `feature:<key>`. The spec does not
pretend these happen together.

### 8.1 Sidebar integration — guard item-by-item (recommended), do NOT re-render from the registry
**Recommendation: wrap each gated sidebar item with a parallel `@feature('key')` alongside its
existing `@permission`, mapped via the registry's `sidebar_section`/`nav_permission` metadata.**
Justification: `corex-sidebar.blade.php` is a hand-tuned 2,333-line Blade with five role bands,
nested fly-out panels, per-item role logic, `Route::has` guards and careful ordering. A
registry-driven re-render would be a high-risk rewrite that regresses that UX for no user-visible
gain (INVESTIGATE→COPY→ADAPT; CLAUDE.md "no risky rewrites"). Item-by-item `@feature` guards are
surgical, reviewable, and diff-clean. The registry's nav metadata *documents* the mapping so a
future phase could template it; v1 wraps existing items. A test asserts every non-core registry
feature with a `sidebar_section` has a matching `@feature('<key>')` guard in the sidebar (so a new
feature can't ship nav-ungated — Non-neg #2 structural).

---

## 9. Permissions (Non-neg #5)

- New key `agency_features.manage` (module `settings`) — gates the Settings → Features section
  (sidebar entry + `feature`/`permission` route middleware + `FeatureSettingsController` check).
  `admin` gets it via all-minus-exclude; owner bypasses. `corex:sync-permissions --merge-defaults`
  on deploy (multi-tenancy §"Permissions sync").
- The registry **does not** add a permission per feature — feature ≠ permission (§3.1). Each
  module keeps its existing `access_*`/action permissions; the feature gate is the orthogonal
  layer on top.
- Onboarding wizard access is unchanged (`agency_setup.run`); the capabilities step writes through
  the same savers under the same gates.

---

## 10. User flow (step by step)

1. **Owner/admin** opens **Settings → Features** (permission `agency_features.manage`). Sees the
   registry grouped by category — each non-core feature a toggle with `explain` + `affects`; core
   features shown "Always on"; dependency-blocked children disabled with a "turn on parent" note.
2. Flips features. **Save** → `FeatureSettingsController@update` writes each changed key (module →
   `agency_features`; switchboard-six → their existing store), emits `AgencyFeatureToggled`, busts
   the request cache.
3. **Immediately**: nav re-renders with the changed set (`@feature` guards); a feature turned off
   drops from the sidebar for **every** user in the agency; its routes 404 (once phase-4 gating
   for that module has landed) or remain permission-gated only (until then).
4. **Onboarding**: a new agency's admin meets the same features in the wizard's capabilities step
   (auto-derived), and a feature left off there **skips its detail step** (adaptive gating).
5. **Re-enable** any time → the module's nav + routes + existing records return exactly as before
   (OFF hid, never deleted).

---

## 11. Input space / prevent-or-absorb (BUILD_STANDARD §2/§3)

- **Unknown feature key** (typo in `@feature`/`feature:`/config) → resolver returns `false` +
  logs a warning (fail-closed; a typo hides a feature loudly-in-logs, never silently passes).
  `corex:features:validate` + the focused test catch config typos at build time.
- **Direct URL to an off-feature route** → `CheckFeature` `abort(404)` (absorb; never a 403 that
  leaks existence, never a 500). Where phase-4 gating hasn't reached a loose route yet, the
  route's existing permission still guards it — no regression, just not-yet-defense-in-depth.
- **Dependency cascade** → child auto-off when parent off; the Settings toggle for a blocked child
  is rendered disabled (prevent) AND the resolver returns false even if a stale `agency_features`
  row says true (absorb). Cycle in config → `validate` command + test fail the build (prevent).
- **Core feature** → `enabled()` returns true before any store read; no toggle rendered; a
  `feature:properties` middleware (if ever mis-applied) still passes. Core can never be turned off.
- **Env kill-switch off** (global `config/features.php`) → feature off for everyone regardless of
  agency row (outer AND) — a platform-wide disable is honoured.
- **Missing `agency_features` row** → registry default (absorb; the common case, keeps the table
  small).
- **Owner with agency switcher active** → gate reads `effectiveAgencyId()`, so the owner sees the
  switched-into agency's features, not a blank set (the AgencyScope owner-blind-spot, memory
  [[agencyscope_owner_switcher_blindspot]] — the service reads the effective id explicitly).
- All writes via Eloquent + `BelongsToAgency` (agency-scoped auto-fill); no raw inserts.

---

## 12. Acceptance criteria

1. `config/corex-features.php` returns the catalogue; every entry has `label`, `category`,
   `explain`, `affects` (no tautology), `default`, `core`, `depends_on`, `nav_permission`,
   `settings_section`, `route_prefixes`; `corex:features:validate` passes (no cycles, keys resolve).
2. `AgencyFeatureService::enabled()` resolves per §3.5: unknown⇒false, core⇒true, env-off⇒false,
   parent-off⇒false, agency row⇒row, else default. Request-cached (one query per request).
3. A per-agency override round-trips: flip `rentals` on for Agency A ⇒ `enabled('rentals')` true
   for A, false (default) for Agency B — proven with two agencies (multi-tenant isolation).
4. `@feature`/`@endfeature` renders/hides a block; `feature('x')` helper matches the service;
   `CheckFeature` **404s** an off-feature route and passes an on one.
5. `depends_on`: turning a parent off makes the child resolve off regardless of the child's row;
   turning it back on restores the child to its own row/default.
6. Env kill-switch AND: a feature mapped to a global `config/features.php` flag that is off
   resolves off for every agency even with an `agency_features` row = true.
7. OFF hides only: disabling a module 404s its routes / hides its nav but deletes no row;
   re-enabling restores access to the pre-existing records.
8. The six switchboard toggles resolve through the registry to their existing stores, unchanged
   (`enabled('core-matches')` == the matches gate's current read).
9. Settings → Features renders the registry grouped by category with explain/affects; the saver is
   §6.1-guarded (absent key ⇒ unchanged; present "0" ⇒ off) and emits `AgencyFeatureToggled`.
10. Backfill: an existing live agency currently using a default-OFF feature gets an explicit ON
    row so deploy hides nothing; a default-ON feature needs no row; demo agencies skipped.
11. Nav: every non-core registry feature with a `sidebar_section` has a matching `@feature` guard
    in the sidebar (guard test) — a new feature cannot ship nav-ungated.
12. `AgencyFeatureToggled` fires on a real toggle (sync listener, scalar payload); catalogue entry
    added to `corex-domain-events-spec.md` §5.
13. Multi-tenancy: Agency A can never read/write Agency B's `agency_features`; no
    `withoutGlobalScope` in request code.
14. No raw error reaches a user on any bad-input path (§11); unknown key logs + fails closed.

---

## 13. Test matrix (single focused file per phase — Non-neg #13)

Phase 1: `tests/Feature/Features/AgencyFeatureGateTest.php` — default resolution, per-agency
override, core-always-on, `depends_on` cascade (+ cycle rejected), env kill-switch AND,
`CheckFeature` 404-on-off / pass-on, `@feature` render/hide, multi-tenant isolation, request-cache
(one query), unknown-key fail-closed, `corex:features:validate`. Real SA agency data
(BUILD_STANDARD §5). **Do NOT run the full suite** (Non-neg #13) — this file only.
Later phases add their own focused files (settings-saver guard extends
`AgencySetupWizardSaverGuardTest`; nav guard test; per-module route-gate tests).

---

## 14. Files to create / modify

**Phase 1 (gate plumbing) — create**
- `config/corex-features.php` — the catalogue.
- `database/migrations/xxxx_create_agency_features_table.php`
- `database/migrations/xxxx_backfill_agency_features.php`
- `app/Console/Commands/BackfillAgencyFeatures.php` (`agency:backfill-features`, idempotent)
- `app/Console/Commands/ValidateFeatures.php` (`corex:features:validate`)
- `app/Models/AgencyFeature.php`
- `app/Services/Features/AgencyFeatureService.php`
- `app/Http/Middleware/CheckFeature.php`
- `app/Events/AgencyFeatureToggled.php`
- `tests/Feature/Features/AgencyFeatureGateTest.php`

**Phase 1 — modify**
- `app/Models/User.php` — `hasFeature()`.
- `app/Providers/AppServiceProvider.php` — `@feature` Blade::if (after `@permission` at :702);
  register any sync listener + the helper.
- `app/helpers.php` (or the autoloaded helpers file) — `feature()`.
- `bootstrap/app.php` — `'feature'` middleware alias.
- `.ai/specs/corex-domain-events-spec.md` — `AgencyFeatureToggled` catalogue row.
- `database/schema/mysql-schema.sql` — re-dump (from TEST DB) after the migration (#12a).

**Later phases — modify (each its own prompt)**
- Phase 3 (nav): `resources/views/layouts/corex-sidebar.blade.php` — `@feature` guards per item.
- Phase 4 (routes): `routes/web.php` — `feature:<key>` on the ~61 clean prefix groups, per module.
- Phase 5 (settings): `resources/views/corex/settings.blade.php` (Features rail entry + section),
  `app/Http/Controllers/.../FeatureSettingsController.php`, `config/corex-permissions.php`
  (`agency_features.manage`).
- Phase 6 (onboarding): `app/Models/AgencyOnboardingSetup.php` + the capabilities step /
  `config/agency-onboarding-copy.php` (auto-derive from registry);
  `.ai/specs/agency-onboarding-feature-switchboard.md` (note the derive — §7.4).
- `.ai/CHAT_STARTER.md` — dated entry per phase.

---

## 15. Phased build sequence (one concern per prompt)

1. **Spec** (this file) — approve grain, core set, dependency edges, route-gating scope. ← *here*
2. **Phase 1 — gate plumbing** (Prompt 2): catalogue + `agency_features` + model + service +
   `hasFeature`/`feature()`/`@feature` + `CheckFeature` + alias + `AgencyFeatureToggled` +
   backfill + `AgencyFeatureGateTest`. **No nav/settings/onboarding UI.**
3. **Phase 2 — backfill verification + switchboard adapter**: wire the six switchboard keys
   through the service's store adapter; prove `enabled('core-matches')` == current matches gate.
4. **Phase 3 — nav gating**: `@feature` guards on every gated sidebar item + the nav guard test.
5. **Phase 4 — route gating (heaviest; ONE PROMPT PER MODULE)**: `feature:<key>` on each clean
   prefix group, module by module, each with its own route-gate test. Loose routes tracked/added
   per module.
6. **Phase 5 — Settings → Features page**: `FeatureSettingsController` (§6.1-guarded) + rail entry
   + section + `agency_features.manage` permission + sidebar gating.
7. **Phase 6 — onboarding auto-derive**: capabilities step iterates the registry; generalise
   `stepGates`; update the switchboard spec.
8. **Phase 7 — full verification + demo/staging deploy**: parity, `corex:sync-permissions
   --merge-defaults`, backfill on the target, CHAT_STARTER, promote.

---

## 16. Pillars / data-model / permissions recap (standard sections)

- **Data model:** one new table `agency_features` (§4.1); no changes to existing tables (§4.3);
  new permission `agency_features.manage` (§9); one new domain event (§3.8).
- **Permissions:** exactly one new key; feature ≠ permission (orthogonal, §3.1).
- **Multi-tenancy:** `BelongsToAgency` + `AgencyScope` on `agency_features`; `effectiveAgencyId()`
  in the service; owner cross-agency only via explicit `queryWithoutAgencyScope()` (§3.7).

---

## 17. Open decisions for Johan (confirm at approval — BEFORE any build)

1. **Grain** — is the §5.3 list (37 toggleable) the right granularity? Candidate merges:
   `commission-management`→`agency-tracker`; `ad-manager`+`marketing-suppressions`→`marketing`;
   `trust-interest`→`calculators`; `training`(LMS)+`knowledge-base`→one "Learning";
   split `docuperfect` into `documents`+`esign` (task listed E-Sign separately). Your call sets
   the row count.
2. **Which features are CORE (never toggle)?** Proposed core: dashboard, properties, contacts,
   deals(DR1), agents, my-portal, settings, company-settings, role-manager (§5.2). **Is
   `agency-tracker` core or toggleable?** The performance/worksheet spine is arguably core; I've
   listed it toggleable (default on) — confirm. Same question for `presentations` and `docuperfect`
   (listed toggleable default-on; some would argue core).
3. **Dependency edges** — confirm/deny the proposed `depends_on`: `esign`→`docuperfect` (if split);
   `leave`→`payroll` (routes nest leave under payroll — but can an agency run leave without
   payroll?); `ad-manager`/`marketing-suppressions`→`marketing`; `commission-management`→
   `agency-tracker`. Any I've missed (e.g. `commercial-evaluations`→`presentations`)?
4. **Defaults for a NEW agency** — confirm the §5.3 `def` column (which modules a brand-new agency
   gets ON out of the box). This is the "what does CoreX look like on day one" decision.
5. **Route-gating scope for v1** — accept that phase 4 gates the ~61 clean prefix groups first and
   loose routes are added per-module incrementally (nav-gating is the immediate visible win)?
6. **Global `config/features.php` mapping** — should any registry features AND against a global
   env flag (e.g. `document-library` already has `document_library_v1`; `presentations` has
   `presentations`), or keep the env layer for platform-wide kill-switches only?
7. **The six switchboard toggles keep their existing stores** (adapter, §7.2) rather than
   migrating into `agency_features` — confirm (recommended: yes, no risky data migration).

**STOP — spec only. No code until Johan approves §17.**

---

**End of spec.**
