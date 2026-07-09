# Agency Onboarding Setup Wizard — Spec

> Status: **Draft pending Johan approval** — 2026-07-07
> Owner: Andre (branch `AT-57-Setting-introduction-page`)
> Jira: AT-57 — "Settings introduction page"
> Pillars touched: Agent (User), Property, Contact, Deal — configures the agency-wide
> settings that govern all four. Reads/writes agency-scoped config only; ingests no
> property/contact/deal data (Non-negotiable #10 N/A).
> Sister specs: `.ai/specs/multi-tenancy.md`, `.ai/specs/corex-domain-events-spec.md`,
> `.ai/specs/roles-permissions.md`.

---

## 1. What this feature does and why (business requirement)

When a new agency is created in CoreX (Admin → Agency Management → Create), its Admin
currently lands in an empty system with ~28 settings sections scattered across
`/corex/settings`, no guidance on what any of them do, and no idea which ones matter
most. Commission splits, syndication portals, presentation thresholds, compliance
officer appointments — all default-configured but unexplained. First-run friction is
high and misconfiguration (e.g. wrong commission split feeding every Agency Tracker
calc) is silent and expensive.

**The wizard fixes this.** On agency + admin creation, CoreX emails the new Admin a
secure link. Clicking it logs them in (against their real CoreX credentials) and drops
them into a **guided, multi-step, resumable setup flow** that walks every important
agency setting in plain English: *what it does, what it affects, and a live control to
change it* — with sane defaults pre-filled so they can accept-and-continue fast. Every
save writes **live, immediately, through the exact same save path the normal settings
page uses** — the wizard is a guided front door onto existing settings, never a parallel
settings system.

The wizard is **not a one-shot**: the setup record persists, tracks progress, and stays
reachable from inside `/corex/settings` so the Admin can revisit and change anything
later. A **"Finish agency setup" banner** nudges Admins whose setup is incomplete.

A **platform-owner tracking page** (dev/Platform-Admin settings) lists every agency's
setup page with progress %, opened/last-activity/completed timestamps, open count, and a
copyable link — so Johan/Andre can see who has started, who has finished, and grab a
link to re-send if needed.

### Why it makes CoreX *best*, not merely *working*
No competitor onboards a real-estate agency by explaining every operational lever in the
agency's own vocabulary while writing live config through the same audited, permission-
gated, agency-scoped path the product uses forever after. "Built for agents, not for
screens": hours of *"what does this setting even do"* become a guided ten-minute walk
with defaults already correct.

---

## 2. Pillar connections

This feature configures **agency-wide behaviour** — it does not own pillar rows, it
tunes the settings that govern how every pillar behaves:

| Pillar | How the wizard touches it |
|--------|---------------------------|
| **Agent** (`User`) | Commission split/cap/tier config drives every agent payout; notification prefs; portal visibility; compliance officer appointments are agent (User) appointments. |
| **Property** | Per-page, default ordering, syndication portals (P24/PP), property types/statuses/mandate types/condition levels, presentation/CMA thresholds — all govern how Properties are listed, marketed, valued. |
| **Contact** | Contacts-per-page, contact types/sources/tags; Matches WhatsApp template governs buyer-contact outreach. |
| **Deal** | Commission & revenue-share settings feed every Deal commission calc + the Agency Tracker. |

State pillar connections satisfied per Non-negotiable #4: the wizard reads agency-scoped
config and writes enriched agency config back. It is not an island — it is the front
door to the settings that the pillars already read.

---

## 3. Architecture decisions (locked)

### 3.1 The wizard NEVER creates a parallel settings system
Every step writes through the **existing** `SettingsController` save methods (and the
sibling section controllers). Where the wizard and the settings page must share logic,
we extract that logic into a **service** so the two physically cannot drift. **No copied
save logic.** Confirmed save-path map in §6.

### 3.2 Public link + gate = clone the P24 onboarding portal stack, then add a login
The closest maintained pattern is the P24 onboarding portal
(`app\Models\P24OnboardingPortal`, `app\Http\Middleware\ResolveOnboardingPortal`,
`App\Http\Controllers\Public\OnboardingPortalController`, standalone layout
`resources/views/layouts/onboarding-portal.blade.php`, routes under `onboarding/{token}`).
We **mirror its shape** for the new model + resolver middleware + standalone layout.

**Divergence from P24 (net-new — nothing to copy):** the P24 portal has **no password**;
it is gated by the secret token only. The agency-setup wizard configures **live agency
data**, so the gate is the Admin's **real CoreX login** (see §3.3). This is a deliberate,
specced departure.

**Route-prefix collision avoidance (verified):** `onboarding/{token}` is already taken by
the P24 portal (a single-segment wildcard). The agency-setup wizard therefore uses a
**distinct top-level prefix `/agency-setup/{token}`** with its **own** middleware alias
`agency.setup.portal` (→ new `ResolveAgencySetupPortal`), so the two never shadow each
other. (`agency-setup` is not in the reserved-first-segment regex of the public
`/{agencySlug}/properties` route, and that route requires a literal `/properties` second
segment, so there is no collision there either.)

### 3.3 The gate is the Admin's real login (not a throwaway password box)
1. Emailed link → `GET /agency-setup/{token}` → `ResolveAgencySetupPortal` validates the
   token:
   - unknown → **404**
   - revoked / expired → **410** branded view
   - already `completed_at` → friendly "setup complete" view **but re-entry is allowed**
     (it is their setup, revisitable — see §3.5).
2. If the visitor is **not** already authenticated as this agency's Admin → show a
   **branded login screen** (email + password) on the onboarding layout. Validate with
   `Auth::attempt()` against the **real user record**, then assert the authenticated user
   is the Admin linked to this token's agency:
   **`$user->agency_id === $portal->agency_id` AND `$user->role === 'admin'`** (owner-role
   System Owners also permitted — they administer any agency). Reject otherwise with a
   clear message.
3. On success → full login → redirect into the wizard. From here **every save runs under
   normal `auth` + `agency.required` + `permission` middleware**, so writes are correctly
   scoped, permission-checked, and audited exactly like the settings page.
4. If the Admin is already logged in when they click the link → skip the login screen,
   still assert token↔agency match, go straight to the wizard.

The token stays valid so the Admin can **resume across sessions** until they finish.

### 3.3a Everything is INLINE — the Admin never leaves the wizard

**Hard rule (AT-57 follow-up):** no step deep-links out to the settings page. Every setting
the step configures is rendered and saved *inside* the wizard. Two rendering modes:

- **Data-driven controls** (`config.controls`) for simple scalar/toggle/select settings.
- **Inline partials** (`config.partial`, rendered inside the wizard's own `<form>`) for rich
  settings whose form is complex — commission (splits, caps, fees, mentor, revenue-share pool
  + 7-tier table), notifications (reminder + channel toggles), compliance (whistleblow routing).
  The partial's fields carry the exact input names the canonical saver expects, so it posts
  through the wizard save → the same `SettingsController`/`CommissionSettingsController` method.
- **Auxiliary collection editors** (`config.aux_partial`, rendered OUTSIDE the main form so its
  add/remove sub-forms aren't nested) for list-type settings — property types/statuses/mandate
  types/condition levels, and contact sources. These post to wizard sub-routes
  (`corex.agency-setup.collection.add|remove`) that delegate to the canonical
  `storePropertySettingItem`/`destroyPropertySettingItem` / `ContactSourceController` CRUD and
  redirect back to the step. Contact **types** are the six FIXED signing roles (Owner, Other,
  Seller, Buyer, Lessor, Lessee) — not configurable, so the wizard does not surface them at all.

Regression-guarded by `test_no_step_deep_links_out_of_the_wizard` (iterates all 9 steps).

### 3.4 Trigger: `App\Events\AgencyCreated` domain event (Non-negotiable #9)
- New agency + Admin are created atomically in
  `app\Http\Controllers\Admin\AgencyController@store()` inside a `DB::transaction`
  (`$adminPayload` is `null` for demo agencies — **demo agencies get no wizard, no email**).
- We introduce **`App\Events\AgencyCreated extends App\Events\AbstractDomainEvent`** — it
  does **not** exist yet (verified) and `roles-permissions.md` explicitly asks for it.
  > **Disambiguation:** this is the *domain* event class. It is distinct from the Eloquent
  > model event `Agency::created` boot hook that already fans role provisioning to
  > `RoleProvisioningService`. Different mechanism, no collision. The domain event is fired
  > **explicitly** from the controller (not from a model boot closure) so it fires once,
  > after commit, only on the real HTTP create path, with the admin's email in scope.
- Fire it **after the transaction commits** (both `$agency` and the admin User in scope),
  **guarded by `if ($adminPayload)`** so demo agencies never fire it. Carry `agencyId`,
  the admin `userId`, admin email, and `createdByUserId` in the payload.
- Add `AgencyCreated` to the event catalogue in
  `.ai/specs/corex-domain-events-spec.md` §5.

### 3.5 Listener creates the setup record + sends the email
`App\Listeners\Onboarding\CreateAgencySetupPortal` (sync) subscribes to `AgencyCreated`:
1. `AgencyOnboardingSetup::create()` — token (`Str::random(40)`, uniqueness-checked),
   slug, `agency_id`, `current_step = 1`, `completed_steps = []`, `expires_at =
   now()->addDays(30)` (generous — this is a resumable setup, not a P24 review window).
   **Idempotent:** `firstOrCreate` on `agency_id` — never a second live portal per agency
   (per E5 of the domain-events spec; the listener must be safe to run twice).
2. Sends `AgencyOnboardingSetupMail` to the admin email **via the `corex` mailer**
   (`Mail::mailer('corex')->to($email)->send(...)`) so it delivers even where the default
   mailer is `log` (staging). Subject: *"Welcome to CoreX — set up your agency."*

### 3.6 Completion vs revisit
`completed_at` is stamped when the Admin hits Finish on the last step. A completed portal:
- still resolves (re-entry allowed) so they can change settings later,
- is reachable from `/corex/settings` via a "Re-open setup guide" link,
- shows as `Completed` on the owner tracking page.
`isActive()` mirrors P24 semantics (`false` if revoked / expired), but **completion does
NOT make it inactive for re-entry** — that is the one deliberate difference from P24
(whose completed portal 410s programmatic callers). We keep the friendly completed view
for JSON/ajax callers but allow the browser wizard to re-open.

---

## 4. Data model / migrations

### 4.1 New table `agency_onboarding_setups`
Model `App\Models\AgencyOnboardingSetup` — `use BelongsToAgency, SoftDeletes` (Non-neg #1).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, **NOT NULL**, indexed | multi-tenancy day-one (#7); one live portal per agency |
| `token` | string(64), unique, indexed | `Str::random(40)`, uniqueness-checked like P24 |
| `slug` | string, unique, nullable, indexed | human-readable url key; `urlKey()` prefers slug else token |
| `created_by` | FK → users, nullable | the platform actor who created the agency (may be null on system create) |
| `admin_user_id` | FK → users, nullable, indexed | the agency Admin this setup belongs to (the login target) |
| `current_step` | unsigned tinyint, default 1 | resume pointer |
| `completed_steps` | json, nullable | array of completed step keys (e.g. `["identity","commission"]`) |
| `expires_at` | datetime, nullable | default now()+30d |
| `revoked_at` | datetime, nullable | soft-revoke without delete |
| `revoked_reason` | string, nullable | |
| `last_opened_at` | datetime, nullable | stamped by the resolver on each open |
| `open_count` | unsigned int, default 0 | incremented on each open |
| `completed_at` | datetime, nullable | Finish stamp |
| timestamps + `deleted_at` | | SoftDeletes |

Casts: `completed_steps => array`, all `*_at => datetime`.
Helper methods mirrored from P24: `generateToken()`, `generateSlug()`, `urlKey()`,
`isActive()`, `statusLabel()`, `publicUrl()` (→ `url('/agency-setup/'.$this->urlKey())`),
plus `progressPercent()` (= `count(completed_steps) / TOTAL_STEPS * 100`).

**Backfill (existing agencies):** agencies created BEFORE this feature never fired
`AgencyCreated`, so they have no setup record — they'd be invisible on the owner tracking
board and their admins would never see the nudge. A **data migration**
(`2026_07_07_000002_backfill_agency_onboarding_setups`) delegates to an idempotent command
`agency:backfill-onboarding-setups` that creates a record for every existing **live**
(non-demo) agency lacking one, linking each to its Admin. It runs on deploy via
`migrate --force` (so the backfill travels to every environment — BUILD_STANDARD §8) and is
safe to re-run. **No email by default** (existing agencies are already operating — a blast
would be wrong; `--email` sends deliberately). New migration ⇒ `php artisan schema:dump` +
commit snapshot (#12a).

### 4.2 No changes to existing settings tables
The wizard writes to the **existing** backing stores only (§6). No new settings columns.

---

## 5. Wizard structure (multi-step, ordered, resumable)

Steps mirror the real settings sections (`$railGroups` in
`resources/views/corex/settings.blade.php`). Must-configure-first things come early.
`TOTAL_STEPS = 9`.

| # | key | Step | Backing store(s) |
|---|-----|------|------------------|
| 1 | `identity` | Welcome / Agency identity | `agencies` company-identity fields |
| 2 | `branding` | Logo & agency colours | `agencies` (`logo_path`, `sidebar_color`, `icon_color`, `default_color`, `button_color`) |
| 3 | `branches` | Branches / offices | `branches`, `agencies.split_branches_enabled` |
| 4 | `commission` | Commission & revenue share | `commission_settings` |
| 5 | `properties` | Properties & listings | `performance_settings`, `agencies` sort fields, `property_setting_items` |
| 6 | `presentations` | Presentations / CMA | `agencies` (`presentations_*`,`comp_*`,`cma_*`) |
| 7 | `matches` | Matches | `performance_settings` (`matches_*`) |
| 8 | `contacts` | Contacts | `performance_settings` (`contacts_per_page`), `contact_sources` |
| 9 | `compliance` | Compliance | whistleblow columns on `agencies` |
| 10 | `notifications` | Notifications & dashboard | `AgencyDashboardSetting`, `agencies.dashboard_settings_mode` |
| 11 | `access` | Access & finish | `agencies.require_external_access_authorization`; review summary; mark complete |

**Step 3 — Branches.** Mirrors Company Settings → Branches: inline add (name + code), list, and
archive, delegating to the canonical `BranchAssignmentController::createBranch` /
`deleteBranch`. Archive is a **soft delete** (Non-negotiable #1) and `deleteBranch`'s
reassignment guard — a branch with agents still assigned cannot be archived — is surfaced in
the wizard (its flashed error bag survives the wizard's redirect; we suppress the "Removed."
success flash when errors are present). Also carries the `split_branches_enabled` toggle
(saver `SettingsController@updateSplitBranches`).

**Step 2 — Branding.** Logo upload (with live preview + remove), the four semantic brand
colours (`default_color` = headers/profiles, `button_color` = CTAs, `icon_color` = icons/links,
`sidebar_color` = sidebar highlight), **client-side colour auto-detection from the uploaded
logo** (canvas pixel bucketing → dominant palette + a suggested accent/dark pair; nothing is
applied until the admin clicks, and nothing persists until Save), and a **live preview** mock of
the header / sidebar / icon / button. Saver: `CompanySettingsController@update` — the canonical
branding write path, explicitly designed for sibling forms (only validated, *present* keys reach
`$agency->update()`), so posting just logo + colours never wipes the company-identity fields.
It takes `(Request, Agency)`, hence the per-saver `pass_agency` flag on the saver map.

**Each step provides:**
- Sane defaults pre-filled (system defaults) → accept-and-continue is one click.
- Every control shows: **title · plain-English explanation (what it does) · what it
  affects · the live control**. Copy is agent-facing, plain English (STANDARDS F.8).
- **Save & continue**, **Back**, **Skip for now** on every step. Saving writes live
  immediately via the shared settings path. Partial completion is fine and resumable.
- Progress indicator: *Step X of 9 · NN% complete*; progress persisted on the record
  (`current_step`, `completed_steps`).

**Explanation copy** lives in ONE authoritative, hand-written, reviewed place —
`config/agency-onboarding-copy.php` (a per-step / per-setting content map). **Not**
AI-generated at view time (must be accurate + stable). Views read from the config map.

---

## 6. Save-path map — every step reuses the settings page's write path

> **Rule (§3.1):** a wizard step must call the SAME write path the settings page uses.
> Where the existing method is a fat controller action, we extract the write into a
> service method both call. Confirmed methods (all in
> `app\Http\Controllers\CoreX\SettingsController.php` unless noted):

| Step | Setting group | Existing write path to reuse |
|------|---------------|------------------------------|
| 1 identity | Company/agency identity, logo, signature | `SettingsController@updateAgency` (line 680) |
| 2 commission | splits, caps, fees, revenue share, tiers | `Commission\CommissionSettingsController@update` (via `CommissionSetting::forAgency()`) |
| 3 properties | per-page | `@updatePropertiesPerPage` (599) |
| 3 properties | default ordering | `@updatePropertiesSort` (614) |
| 3 properties | marketing on/off | `@updateMarketingEnabled` (423) |
| 3 properties | syndication portals P24/PP | `@updateSyndicationPortals` (430) |
| 3 properties | types/statuses/mandate/condition/categories | `@storePropertySettingItem` (307) / `@updatePropertySettingItem` (335) — `PropertySettingItem` |
| 4 presentations | thresholds/comp scope/compute/holding-cost | `@updatePresentations` (441) |
| 4 presentations | default sections | `@updatePresentationSections` (547) |
| 5 matches | enable | `@updateMatchesEnabled` (576) |
| 5 matches | show-on-properties | `@updateMatchesShowOnProperties` (583) |
| 5 matches | visibility scope | `@updateMatchesVisibilityScope` (645) |
| 5 matches | WhatsApp template | `@updateMatchesWaMessage` (654) |
| 6 contacts | per-page | `@updateContactsPerPage` (590) |
| 6 contacts | contact types/sources/tags | existing contact-settings stores (as used by `feature-contacts` section) |
| 7 compliance | FICA/MLRO/Info-officer appointments | `FicaOfficerAppointment` / `InformationOfficerAppointment` stores |
| 7 compliance | whistleblow routing | `@saveWhistleblowSettings` (949) |
| 8 notifications | channel toggles + prefs | `@updateNotificationPreferences` (262) → `NotificationPreferenceService` |
| 8 dashboard | mode + agency reminders | `@updateDashboardMode` (819) / `@updateAgencyDashboardSettings` (866) |
| 9 access | remote-access consent | `@updateRemoteAccess` (916) |

**Extraction plan:** where a step needs a method that currently only exists as a
controller action bound to a settings route, the wizard controller **calls those same
routes/methods** (dispatching a sub-request or invoking a shared service). Preferred:
lift the validation+`updateOrCreate` bodies of the touched methods into
`App\Services\Settings\*` service methods and have BOTH the settings controller and the
wizard controller call them — so they can never diverge. Each lifted method keeps its
existing validation ranges verbatim (BUILD_STANDARD §2 input-space contract preserved).

---

## 7. UI placement & navigation (Non-negotiable #2 — same-day nav)

1. **Public wizard** — standalone onboarding layout (branded, no app sidebar), reached
   from the emailed link. Login screen → step pages.
2. **In-app re-open** — a "Re-open setup guide" link inside `/corex/settings` (Agency
   group) for Admins, visible whether or not setup is complete.
3. **Incomplete-setup banner** — for Admins whose `AgencyOnboardingSetup.completed_at` is
   null: a dismissible "Finish setting up your agency (NN% done) →" banner on the
   Settings page (and/or dashboard). Links straight into the wizard at `current_step`.
4. **Owner tracking page** — Platform-Admin settings → **"Agency Setup Progress"**: table
   of every agency (owner-scoped, `queryWithoutAgencyScope` since it is cross-agency
   platform tooling per multi-tenancy spec rule #5) with: agency, admin, status chip
   (Active/Completed/Expired/Revoked), progress %, opened / last-activity / completed
   timestamps, open count, and a **copy-link** button. Gated to owner role. Nav entry
   added in the Platform-Admin sidebar block same day.

---

## 8. Permissions (Non-negotiable #5)

- New keys in `config/corex-permissions.php` (module `settings`):
  - `agency_setup.run` — access the wizard (Admin + owner).
  - `agency_setup.track` — access the owner tracking page (owner only; also owner-role
    bypasses).
- The wizard requires the **Admin role + `access_settings`** AND, per step, the SAME
  section permission the settings page already enforces (e.g.
  `manage_performance_settings`, the `compliance.*` keys, `command_center.settings`,
  etc.). **A step the Admin could not write on the settings page must not be writable in
  the wizard** — reuse the existing per-section gates by reusing the existing save paths.
- Route middleware: public gate is unauthenticated → login; post-login wizard routes use
  `auth` + `agency.required` + `permission:agency_setup.run`; each save reuses the target
  method's own permission gate.
- After deploy: `php artisan corex:sync-permissions --merge-defaults` (per multi-tenancy
  spec) so the new keys reach existing roles.

---

## 9. User flow (step by step)

1. Owner creates a **live** agency + Admin (`AgencyController@store`). `AgencyCreated`
   fires (post-commit, `if ($adminPayload)`).
2. `CreateAgencySetupPortal` listener → creates `AgencyOnboardingSetup` (idempotent) →
   emails Admin the link via `corex` mailer.
3. Admin clicks link → `ResolveAgencySetupPortal`: token valid?
   404 unknown / 410 revoked-expired / friendly-completed (re-entry allowed).
   Stamps `last_opened_at`, increments `open_count`.
4. Not logged in as this Admin → branded login → `Auth::attempt()` → assert
   token↔agency + admin (or owner) → login → redirect to wizard at `current_step`.
5. Wizard step N: read defaults, show explanation + live control. Admin edits or accepts.
   **Save & continue** → validates + writes live via shared settings path → append step
   key to `completed_steps`, advance `current_step` → next step.
   **Skip for now** → advance without writing. **Back** → previous step.
6. Last step (access) → review summary → **Finish** → stamp `completed_at` → redirect to
   `/corex` dashboard with a success flash.
7. Later: Admin re-opens from Settings link any time; owner watches progress on the
   tracking page and can copy/re-send the link.

---

## 10. Input space / prevent-or-absorb (BUILD_STANDARD §2/§3)

Because every step reuses the settings page's write path, the **existing validation is
inherited verbatim** — no new validation surface is invented per setting. Wizard-specific
input space:

- **Unknown token** → 404 (prevent). **Revoked/expired** → 410 branded (absorb).
- **Wrong-agency / non-admin login** → rejected with a clear message, never a 500.
- **Skip a step** → accepted; NOT-NULL settings columns already carry DB/system defaults
  (no write = existing default stands). No step-skip can 500 a later step (steps are
  independent writes; no cross-step ordering dependency).
- **Resume mid-flow** → `current_step` may point past a skipped step; re-entry is always
  legal at any step (no forced sequence server-side).
- **Deleted admin / deleted agency** → resolver renders a friendly 404/expired, never a
  crash (deleted-related-record rule §4 of BUILD_STANDARD).
- **Double-fire of the listener** → `firstOrCreate` ⇒ one portal, one email attempt
  (idempotent, E5).
- **Public raw inserts stamp `agency_id`** — all writes go through Eloquent + the model's
  `BelongsToAgency` (auto-fill) or the existing settings methods; no raw inserts.

---

## 11. Acceptance criteria

1. Creating a **live** agency + Admin fires `AgencyCreated`, creates exactly one
   `AgencyOnboardingSetup`, and sends one email via the `corex` mailer.
2. Creating a **demo** agency (`is_demo=1`, no admin) fires **no** event and sends **no**
   email.
3. `GET /agency-setup/{unknown}` → 404; revoked/expired → 410; completed → friendly view
   with re-entry allowed.
4. Login gate rejects a user whose `agency_id ≠ portal.agency_id` or whose `role ≠ admin`
   (owner allowed); accepts the correct Admin and lands them in the wizard.
5. A wizard step writes to the **real** store — asserting the SAME value the settings page
   would produce (e.g. saving commission split on step 2 sets the identical
   `commission_settings` value `CommissionSettingsController@update` would).
6. Resume: `current_step` / `completed_steps` persist across sessions; re-entering lands
   at `current_step`.
7. Finish stamps `completed_at`; the record remains re-openable from Settings.
8. Incomplete-setup banner shows for Admins with null `completed_at`; hidden once complete.
9. Owner tracking page lists every agency's setup with progress %, timestamps, open count,
   copy-link — owner-gated, cross-agency.
10. Multi-tenancy: an Admin of agency A can never open/resolve agency B's setup; the
    tracking page is owner-only.
11. Nav entries present same day: Settings re-open link, incomplete banner, Platform-Admin
    tracking-page link.
12. No raw error reaches a user on any bad-input path (§10); transactions clean.

---

## 12. Test matrix (single focused file — Non-negotiable #13)

`tests/Feature/Onboarding/AgencySetupWizardTest.php` (+ fixtures). Cover:
- token gate: 404 unknown / 410 revoked / 410-or-friendly completed;
- login gate rejects wrong-agency user, rejects non-admin, accepts correct admin, accepts
  owner;
- a step write round-trips into the real store (assert equals settings-page result);
- resume/progress persists (`current_step`, `completed_steps`);
- `AgencyCreated` fires the email (`Mail::fake()`) on live create;
- demo agency → no event, no email;
- listener idempotency (fire twice → one portal, one send);
- skip-a-step leaves the underlying default intact (no 500 downstream).

Real-world data (real SA agency name/address, real commission %) per BUILD_STANDARD §5.
**Do NOT run the full suite** — run only this file.

---

## 13. Files to create / modify

**Create**
- `database/migrations/xxxx_create_agency_onboarding_setups_table.php`
- `database/migrations/xxxx_backfill_agency_onboarding_setups.php` (runs the backfill on deploy)
- `app/Console/Commands/BackfillAgencyOnboardingSetups.php` (idempotent existing-agency backfill)
- `app/Models/AgencyOnboardingSetup.php`
- `app/Events/AgencyCreated.php`
- `app/Listeners/Onboarding/CreateAgencySetupPortal.php`
- `app/Mail/AgencyOnboardingSetupMail.php`
- `resources/views/emails/agency-onboarding-setup.blade.php`
- `app/Http/Middleware/ResolveAgencySetupPortal.php`
- `app/Http/Controllers/Public/AgencySetupGateController.php` (token landing + login)
- `app/Http/Controllers/CoreX/AgencySetupWizardController.php` (steps + saves)
- `app/Http/Controllers/Admin/AgencySetupProgressController.php` (owner tracking page)
- `app/Services/Settings/*` shared save services (extracted from `SettingsController`)
- `config/agency-onboarding-copy.php` (explanation copy map)
- Wizard Blade views (multi-step) extending `layouts/onboarding-portal.blade.php`
- Login + landing Blade views on the onboarding layout
- `resources/views/admin/agency-setup-progress/index.blade.php`
- `tests/Feature/Onboarding/AgencySetupWizardTest.php`

**Modify**
- `app/Http/Controllers/Admin/AgencyController.php@store` — fire `AgencyCreated`
- `bootstrap/app.php` — register `agency.setup.portal` middleware alias
- `routes/web.php` — public `/agency-setup/{token}` group + in-app wizard + tracking routes
- `config/corex-permissions.php` — `agency_setup.run`, `agency_setup.track`
- `resources/views/corex/settings.blade.php` — Agency group "Re-open setup guide" link +
  incomplete-setup banner
- Platform-Admin sidebar — tracking-page nav entry
- `.ai/specs/corex-domain-events-spec.md` — add `AgencyCreated` to catalogue
- `.ai/CHAT_STARTER.md` — dated decision entry
- `database/schema/mysql-schema.sql` — re-dump after migration (#12a)

---

## 14. Build sequence (one concern per prompt)

1. **Spec** (this file) — approve step order + save-path map. ← *we are here*
2. Migration + `AgencyOnboardingSetup` model (+ schema dump).
3. `AgencyCreated` event + catalogue entry + `AgencyController@store` hook +
   `CreateAgencySetupPortal` listener + `AgencyOnboardingSetupMail` + email view.
4. `ResolveAgencySetupPortal` middleware + alias + public routes + gate/login controller
   + login/landing views.
5. Extract shared settings save services; wizard controller + step views + copy config.
6. Nav: settings re-open link + incomplete banner; permissions keys.
7. Owner tracking page + route + nav.
8. Focused test file; verification; commit/push; CHAT_STARTER; demo deploy.

---

**End of spec.**
