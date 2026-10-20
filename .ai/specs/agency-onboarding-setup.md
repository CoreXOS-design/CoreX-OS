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

### 3.5 Listener creates the setup record; the email is deferred (AMENDED 2026-08-12, Johan — see .ai/specs/agency-admin-rule.md §R1a/§R1b)
`App\Listeners\Onboarding\CreateAgencySetupPortal` (sync) subscribes to `AgencyCreated`:
1. `AgencyOnboardingSetup::create()` — token (`Str::random(40)`, uniqueness-checked),
   slug, `agency_id`, `current_step = 1`, `completed_steps = []`, `expires_at =
   now()->addDays(30)` (generous — this is a resumable setup, not a P24 review window).
   **Idempotent:** `firstOrCreate` on `agency_id` — never a second live portal per agency
   (per E5 of the domain-events spec; the listener must be safe to run twice).
2. **Superseded:** this listener no longer sends `AgencyOnboardingSetupMail` at creation
   time. As of §R1a, the new Admin has no password yet at this point (email-only invite —
   `UserInviteMail` is what's sent here, from `AgencyController@store`, not this listener).
   `AgencyOnboardingSetupMail` (subject *"Welcome to CoreX — set up your agency"*, via the
   `corex` mailer, unchanged) now fires on the Admin's **first successful login** — see §R1b
   — stamping `agency_onboarding_setups.invite_email_sent_at` so the trigger fires once. It
   can also be **manually re-sent** from the owner tracking page (§7.4) independent of login
   state.

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
`TOTAL_STEPS = 13`. Step 2 is the **feature switchboard** (spec
`.ai/specs/agency-onboarding-feature-switchboard.md`) — the front door onto every
top-level capability. A capability switched OFF there **skips its dedicated detail
step** (adaptive step-gating); "Step X of N" counts only the ACTIVE steps for that
agency, so the visible count is ≤ 13.

| # | key | Step | Backing store(s) |
|---|-----|------|------------------|
| 1 | `identity` | Welcome / Agency identity | `agencies` company-identity fields |
| 2 | `capabilities` | **Feature switchboard** — turn features on/off | `performance_settings` (`marketing_enabled`, `matches_enabled`, `syndication_*`) + `agencies` (`split_branches_enabled`, `website_enabled`) |
| 3 | `branding` | Logo & agency colours | `agencies` (`logo_path`, `sidebar_color`, `icon_color`, `default_color`, `button_color`, `ncc_registration_number` — AT-234, added 2026-08-04) |
| 4 | `branches` | Branches / offices | `branches` (the `split_branches_enabled` master moved to step 2) |
| 5 | `commission` | Commission & revenue share | `commission_settings` |
| 6 | `properties` | Properties & listings | `performance_settings` (per-page; marketing + syndication masters moved to step 2); `agency_deal_sync_settings` (`flag_property_under_offer_on_deal`, `sold_milestone`, `revert_property_on_deal_declined` — DR2 Wave 2, added 2026-08-04); `agencies.other_agency_stock_visible_roles` + `other_agency_stock_consent_wording` (Other Agency Stock — 2026-10-07, new `role_multiselect` control type, `other_agency_stock` source, saver `SettingsController@updateOtherAgencyStock`); `title_deed_settings` (all 17 Title Deed Register settings (round 4 adds the "Reference prefix" text control under the heading "Reference numbers") — AT-450, 2026-10-13, round 3 2026-10-14: the four "required" ticks became Required / Optional / Not used selects and the booleans Yes / No selects with the SAME wording as Settings -> Title Deeds, `spec title-deed-register.md` §15; source `title_deeds` resolved by `TitleDeedSetting::{camelKey}For`, saver `TitleDeedSettingsController@save`, every field `has()`-guarded; spec `title-deed-register.md` §9) |
| 6c | `market_intelligence` | Market Intelligence | `suggested_action_thresholds.listing_off_market_days` (saver `StaleRulesController@updateListingWindow`) and `performance_settings.report_ai_read_enabled` (2026-10-20, report recogniser, spec `mic-complete-spec.md` §8.9: "Read unrecognised market reports with AI", toggle, default On, source `perf`, saver `StaleRulesController@updateReportAiRead`, `has()`-guarded; same stored value as the toggle on Settings -> Prospecting -> Stale-claim rules) |
| 7 | `presentations` | Presentations / CMA | `agencies` (`presentations_*`,`comp_*`,`cma_*`) |
| 8 | `matches` | Matches | `performance_settings` (`matches_show_on_properties`, `matches_visibility_scope`, `matches_wa_message`) and `agency_contact_settings.core_matches_excluded_buyer_states` (Won/Lost buyers — 2026-10-07, new `multiselect` control type + `_present` marker, `core_matches` source) — **gated on `matches_enabled`** (step 2); the `matches_enabled` master moved to step 2 |
| 8b | `road_to_sold` | Road to Sold | `rts_settings` (AT-454, 2026-10-13, spec `road-to-sold.md` §4): the agency default weekday targets (`default_target_1`..`7` -> `default_weekday_targets`), `half_day_leave_halves_target`, `lock_agents_to_areas`, `area_refresh_warn_days`, `missed_items_mode`, `no_answer_drop_after`, `hold_days`; source `road_to_sold` (explicit per-key arm in `currentValues`, read through `SettingsService`); saver `RoadToSold\SettingsController@saveFromWizard` (maps `default_target_N` onto the settings saver, every field `has()`-guarded, 403 before any write) — **gated on the `road-to-sold` feature** (which depends on `prospecting`) | **13 Oct 2026 16:03 (Johan):** the agency’s suburbs are no longer chosen anywhere - they are derived from its stock and Market Intelligence data (road-to-sold.md section 13); the step now carries `min_area_properties` (a suburb counts as a working area from N properties, default 1) beside Lock agents to their areas.
| 9 | `contacts` | Contacts | `performance_settings` (`contacts_per_page`), `contact_sources` |
| 10 | `compliance` | Compliance | whistleblow columns on `agencies`; `agencies.fica_referral_enabled` / `fica_referral_recipient_user_id` (AT-236, added 2026-08-04 — was flagged 2026-07-14 as a deliberate-omission candidate pending Johan's call; resolved to include rather than exclude); `agencies.ppra_employment_letter_address_block` (PPRA employment letter addressee — 2026-10-07, saver `SettingsController@savePpraEmploymentLetterSettings`); `agencies.rental_trust_account_name` (the name of the trust account the FICA form tells tenants and landlords to pay rent and deposit into — 14 Oct 2026, text control, saved by `FicaOfficerAppointmentsController@saveWindowSettings` beside the FICA time windows, `has()`-guarded, blank = the neutral "the agency's trust account"; spec `compliance.md`) |
| 11 | `notifications` | Notifications & dashboard | `AgencyDashboardSetting` (incl. `alert_catchup_minutes` — how far back a freshly opened screen looks for unread alerts to pop up, default 10, 1 to 240, added 14 Oct 2026, a `controls` entry with source `alert_settings` read by `AlertSwitchService::catchupMinutesFor`, saved by the same `updateAgencyDashboardSettings`; and `alert_poll_seconds` — how often screens check for a new alert to pop up, default 30, min 15, added 2026-10-12 AT-451; saver `updateAgencyDashboardSettings` only touches it when `$request->has('alert_poll_seconds')`), `agencies.dashboard_settings_mode` |
| 12 | `roles` | How roles & permissions work (explainer) | — (explainer only; no savers) |
| 13 | `access` | Access & finish | `agencies.require_external_access_authorization`; review summary; mark complete |

### 5.1 Deliberately NOT in the wizard (Johan, 2026-07-11)

These were built and then removed on Johan's call. They are out of scope by decision, not
by oversight — do not reinstate them without asking:

- **Report recogniser platform thresholds - `report_ai_max_pages`, `report_ai_min_confidence`, `report_layout_min_match`, `report_layout_selftest_min`** (2026-10-20, report recogniser, spec `mic-complete-spec.md` §8.9; **recorded for Johan - pending his confirmation, §10a makes the call his**). PLATFORM settings (`dev_settings`, edited by CoreX owner roles on the Learned layouts screen), not agency ones: they cap how much of the platform's AI bill one report can cost and how trustworthy a free deterministic read must be, so an agency never configures them. Defaults 40 pages / 0.50 / 0.85 / 0.90. **Also `report_ai_monthly_cap`** (AI reads per agency per month, default 5) **and `report_ai_platform_daily_cap`** (AI reads per day across all agencies, default 10) - Johan's 20 Oct ruling: same reasoning, and they are the cost controls an agency must not be able to raise itself. The one agency-level setting of the recogniser (`report_ai_read_enabled`) IS in the wizard (Market Intelligence step).
- **`ai_template_max_failed_per_day` - how many paid-but-failed AI design attempts an agency may have per day** (2026-10-18, audit-fix-ai-spend, Johan's decision D-M2). A PLATFORM setting (Dev Settings -> AI Spend Safety, beside the alert / switch-off thresholds), not an agency one: it protects the platform's AI bill, so an agency never configures it and it has no place in onboarding. Default 5.

- **Notice templates (breach notice - payment, breach notice - other, notice to vacate, and any other the agency adds)** (2026-10-14, qa1, `rental-notices.md` §3; **recorded for Johan**). They are the agency's OWN uploaded documents (Word, with placeholders), managed on their own screen (Settings -> Rental Notice Templates) like every other template - CoreX seeds none and they are not a switch to set once. The settings that came with notices ARE in the wizard (Leases step): `lease_settings.cpa_early_cancel_business_days` and `lease_settings.breach_remedy_business_days`.

- **"Who normally pays" defaults on a fault type (`rental_fault_type_causes`)** (2026-10-13, qa1 AT-452 stage 4; **recorded for Johan**). It is fault-catalogue DATA (a short list of self-checks and causes per fault type, like the first-aid steps, which are not wizard controls either), seeded neutral for every agency and edited on the fault type's own screen - not an agency switch. Spec `rentals-faults-work-orders.md` §22.13.7.

- **"Who looks at faults first" (per-property / per-owner first responders, `rental_first_responders`)** (2026-10-12, qa1 AT-452 family; **recorded for Johan**). It is per-property and per-owner DATA with a change log - like the owner's work terms, which are not wizard controls either - not an agency setting, so there is nothing to switch on in onboarding. Spec `rentals-faults-work-orders.md` §21.4.

- **`lease_settings.allow_advance_as_of_date` - "run a lease's date checks as if today were another day"** (2026-10-14, qa1; the brief itself says the
  setting is hidden unless APP_ENV is not production). A testing aid for QA / Staging boxes that have no scheduler
  (rental-renewals.md §22.1). The wizard is what a NEW agency on the live system walks through, and the control does not exist
  there, so it cannot be a wizard step. Default off.

- **`lease_settings.default_pre_let_status` — "the status that means back on the market"** (2026-10-08, qa1-cc2, **pending
  Johan's confirmation**). It is a pick from the agency's OWN property statuses, which a wizard control cannot list (wizard
  `options` are static config; a closure would break `config:cache`). It is on Settings → Leases, default `active`, valid for
  every agency. Its three sibling switches (`auto_readvertise_on_notice`, `auto_restore_status_on_lease_ended`,
  `auto_restore_status_on_lease_cancelled`) ARE in the `leases` step. If Johan wants the status in the wizard too, the step
  needs a dynamic `options` source.

- **Portal credentials (P24 / Private Property) and advanced portal settings.** Not an
  onboarding concern. They stay on the agency-edit page. The narrow
  `SettingsController@updatePortalCredentials` saver written for this was removed with them.
- **"Your property lists"** (the inline property type / status / mandate / condition-level
  editors). The properties step keeps only its scalar controls; the lists are managed on the
  settings page.
- **"Invite your team".** Onboarding configures the agency; adding people stays in User
  Management.
- **Auctions — the sale-running settings (AT-432, 2026-10-02).** The wizard's Auctions step
  carries only the four settings that decide how an agency *advertises* auctions
  (advertising-only, who conducts, reserve visibility, guide price). Fees, bidding modes,
  proxy bidding, registration/deposit rules, purchase-deposit and settlement terms stay on
  Settings → Auctions: they only matter once an agency has switched "Advertising only" off,
  and they are expert knobs. **Recorded as a decision for Johan to confirm — not the lane's call.**
- **"Public website" toggle (`website_enabled`)** (Johan, 2026-08-12 — alongside the
  "features on by default" change below). Taking the agency's public site live is a
  deliberate action, never an onboarding default — removed from the capabilities step's
  `controls` AND its `toggleWebsite` saver (leaving the saver registered with no matching
  control would fail `'website_enabled' => 'required|boolean'` validation on every step
  save). Stays on `Admin\AgencyApiKeyController@toggleWebsite`, reachable from the
  agency's website-settings page. `agencies.website_enabled` and
  `config/corex-features.php`'s `public-website` registry row both still default `false`.
- **Buyer-notes visibility (`buyer_notes.view`, 2026-10-07).** A per-role Role Manager data scope (Own / Branch / Agency), not an agency setting — the wizard does not configure the role matrix. Recorded as a decision, not an oversight.

- **E-sign legal warning flag (`document_types.esign_warning_required`, 2026-10-08).** Which document types ask the admin to acknowledge a legal warning when e-signing is switched on for a template. `document_types` is a platform-wide table shared by every agency (no `agency_id`), switched only by a CoreX owner-role user on Document Types settings — not an agency setting, so the wizard cannot configure it. Recorded as a decision for Johan to confirm (§10a makes the call his, not the lane's). Spec `ESIGN-CANON.md` §7.

- **Address-matching strictness (`address_match_settings`, 2026-10-07).** Expert thresholds for "is this the same property?" (which exact rules are on, how many columns must agree for a possible match, how neighbouring suburbs count, the GPS radius, how a missing unit counts). Safe defaults; admin-only page `/corex/settings/prospecting/address-matching`. Johan's decision (conductor's brief, 2026-10-07: "system settings with sensible defaults, admin-only, not in the Setup Wizard") — recorded as a decision, not an oversight. Spec `structured-address-matching.md` §9.

- **MIC tile-count cache window** (`suggested_action_thresholds.mic_counts_cache_fresh_seconds` /
  `.mic_counts_cache_stale_seconds`, added 2026-08-27). An internal cache-tuning knob, not a
  decision an agency makes about how CoreX behaves for them: with the claim-write cache-version
  invalidation in place it has no observable product effect except on the rare non-claim
  recompute. Reasoning recorded in `.ai/specs/mic-complete-spec.md` §10; registered here so the
  omission sits in this central list rather than only in the module spec. Configured on
  Settings → Prospecting Setup → Stale-claim rules. **Pending Johan's confirmation** — §10a makes
  the keep-it-out call his, not the lane's.

- **PPRA employment letter + Other Agency Stock — IN the wizard (2026-10-07).** Both were shipped
  with a recorded "not in the wizard" omission (their own specs, written by the lane, not by Johan —
  §10a makes that call his) and a staging-plan audit (`/tmp/staging-plan-noteonly-ppra-oas-2026-10-07.md`)
  found neither reached onboarding. Now surfaced, no new settings, no behaviour change, same saver as the
  settings page (one source of truth):
  - **Compliance step:** `agencies.ppra_employment_letter_address_block` — textarea, blank = the PPRA's own
    published address (`PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK`, shown as the greyed placeholder,
    never pre-filled as a value so a save cannot freeze the default into the agency). Saver
    `SettingsController@savePpraEmploymentLetterSettings`, now `$request->has()`-guarded (§6.1). The
    reminder cadence setting no longer exists (removed 6 Oct, wet-ink flow) so it is not here. The
    agency's PPRA registration number and trading name the letter prints are already controls on the
    Identity step; the Role-Manager "can receive a letter" permission is a role setting, not an agency
    control (Role Manager screen; the Roles explainer step already points there).
  - **Properties step:** `agencies.other_agency_stock_visible_roles` (a tick-list of THIS agency's own
    roles — new `role_multiselect` control type; every role ticked = stored NULL = visible to everyone,
    the default) and `agencies.other_agency_stock_consent_wording` (textarea, blank = the standard
    wording as placeholder). Saver `SettingsController@updateOtherAgencyStock` (already guarded: roles by
    their `_submitted` marker, wording by `has()`); the roles list is the same `Role::allRoles()` list
    Company Settings offers.
  - New generic view features on `text`/`textarea` controls: optional `placeholder`, and `rows` for
    textarea. Current-value source `other_agency_stock` is an explicit per-key match (§6.2).
  Tests: `tests/Feature/Onboarding/AgencySetupWizardPpraOasTest.php`.

- **Portal-listing stale window — IN the wizard (2026-10-07).** `suggested_action_thresholds.listing_off_market_days`
  (days a portal listing may go un-sighted before it is presumed off the market; default 90, Johan: "a
  mandate normally runs 90 days") is a control on the **Market Intelligence (`market_intelligence`) step**
  (source `prospecting_thresholds`, saver `StaleRulesController::updateListingWindow`, which writes only when
  the field was posted — §6.1). It is the same value as the "Portal stock freshness" field on Settings →
  Prospecting Setup → Stale-claim rules. Spec: `.ai/specs/mic-sold-offmarket-ref-tracking.md`.

- **RULED IN (owner, 2026-09-30) — no longer omitted.** These were recorded here as "Pending Johan's
  ruling" (audit fix MED-3); the owner has ruled they belong in the wizard and they now live in the
  **Rentals (`leases`) step**:
  - `rental_application_qualifying_settings.credit_bureau_name` and `tenanted_label` (text controls;
    savers `RentalApplicationSettingsController::updateCreditBureau` / `updateTenantedLabel`, now
    `has()`-guarded so a post that never rendered them leaves the value alone).
  - `lease_settings.show_lease_type_field` (toggle; `LeaseSettingsController::update`, already guarded).
  - `rental_inspection_settings.omr_unsure_below` and `note_read_min_confidence` (numbers, 0.1-0.95, default 0.50 each; AT-472, 20 Oct 2026, form spec §13.15 - the scanned-form confidence lines for "ticks to check" and "handwritten notes to type in"; filled()-guarded in `RentalInspectionSettingsController::update`),
  - `rental_inspection_settings.form_paper_size` (select; AT-463, 15 Oct 2026 - the paper the printed site form is laid out for; has()-guarded), `rental_inspection_settings.require_notes_blocks_progression` (toggle) and `omr_mark_threshold`
    (number 0.05-0.95) — saved by `RentalInspectionSettingsController::update` (new `has()`/`filled()`
    guarded fields), and also added to the Rental Inspection Settings page so the two never drift.
  - The repeater lists — refusal reasons, inspection condition states (+ "All Good" baseline),
    photo-note classifications, inventory condition states (plus, in the same partial: how someone
    attended, own room types, the standard room list per kind of property (14 Oct 2026, `rental-inspections.md` §55, saver `inspectionRoomTemplates`, marker `room_templates_submitted`), and — 7 Oct 2026, `rental-inspections.md` §45.14 — the agency's words for
    the three move-out classifications, saver `inspectionMoveOutClassificationLabels`) — rendered by the
    `agency-setup.steps.rentals-inspection-lists` partial (the same repeater markup as the settings
    screens; the step's `partial` key now accepts a list). Each list posts its own `*_submitted`
    marker and saves through `RentalListsWizardSaver`, which is a no-op when the marker is absent and
    otherwise delegates to the canonical saver, so a step post that never rendered a list can neither
    wipe nor fail it (§6.1). Inventory uses its own field name (`inventory_condition_states`) because
    the wizard is one combined form. Photo-note classifications also gained a settings-page form (they
    previously had a route but no screen).

- **Application approvers (RO / CO) - IN the wizard (19 Oct 2026, rental audit fix A4/A27).** The Rentals (`leases`)
  step now has two `user_multiselect` controls, `rental_application_ro_user_ids` (Reviewers) and
  `rental_application_co_user_ids` (Override approvers), source `rental_application` (resolved by
  `RentalApplicationQualifyingSetting::rentalApplicationRoUserIdsFor` / `...CoUserIdsFor`), saved by the ONE narrow
  saver `RentalApplicationSettingsController::updateAuthorisersFromWizard` - each list written only when it was
  posted (`has()`-guarded; the control posts `key[]` plus an empty companion so "everyone un-ticked" still arrives),
  the empty companion dropped, every id resolved inside the agency (a foreign id is a 403), audited in
  `rental_setting_audit`. The step's `stepData` supplies `agencyUsers`. Before this a new agency finished setup with
  nobody able to approve an application. With nobody ticked, approval falls back to one step and CoreX warns (settings
  page + review screen) - see `rental-applications.md` "Audit fixes 2026-10-19".

- **Rental application "Property Link Lock"** (`rental_application_qualifying_settings.
  lock_property_after_submission`, added 2026-09-10). A fine-tuning toggle on an already-
  running rental-applications feature (whether the linked property can still be changed once
  submitted for authorisation) — configured after an agency has real applications moving
  through the flow, not a decision made at signup. Consistent with every other rental-
  application setting (qualifying formula, reopen-link expiry, RO/CO tiers, approval-email
  matched-property cap) — none of which are in the wizard either, and none of those omissions
  are separately recorded here, only noticed while adding this one. **Pending Johan's
  confirmation** — §10a makes the keep-it-out call his, not the lane's; flagging that the
  whole rental-applications settings category shares this gap, not just this one setting.
  Configured on Settings → Rental Applications → "Property Link Lock".

- **Rental application "Tag Contact as Tenant on Approval"** (`rental_application_qualifying_settings.
  tag_contact_as_tenant_on_approval`, added 2026-09-11). Same reasoning and same category as the
  Property Link Lock entry immediately above: a fine-tuning toggle on an already-running feature
  (whether approving a rental application adds "Tenant" to the contact's types), not a signup-time
  decision. Configured on Settings → Rental Applications → "Tag Contact as Tenant on Approval".

- **The owner's work terms per rental property** (`properties.rental_no_approval_spend_threshold` and
  `properties.rental_variation_tolerance_percent`, maintenance-flow Build 2, 6 Oct 2026 —
  `.ai/specs/rental-work-orders.md` §17.6.2 / §17.14). Per-owner agreements, edited on the property's
  Rental tab ("Work terms agreed with the owner", own permission, append-only history) — not an
  agency-wide onboarding choice (same ruling as the other per-property overrides). The **agency
  defaults** they inherit ARE in the wizard (Rentals step: the fee on an outside contractor's quote — type and
  value; saver `RentalWorkOrderSettingsController::updateApprovals`, every field `has()`-guarded).
  **13 Oct 2026 (AT-452, Johan): the variation tolerance % and the owner email on an auto-approved extra were
  REMOVED from the wizard and from Settings** - the authorised amount is a hard lock
  (`rentals-faults-work-orders.md` §22.6), nothing reads either value any more. Stored data is kept.

**From the feature switchboard (spec `.ai/specs/agency-onboarding-feature-switchboard.md` §3.5):**

- **P24 / Private Property portal *credentials* and the `p24_enabled` / `pp_enabled` /
  `pp_lead_pull_enabled` / `pp_stats_pull_enabled` columns.** Written only by the
  `Admin\AgencyController@update` booby trap (with an auto-enable side effect when
  username+password are present), so a switchboard "OFF" would be silently overridden.
  Stay on agency-edit. (The `syndication_*` feature keys — "do we push at all" — ARE in
  the switchboard; they are a different thing to the credential columns.)
- **Commission sub-toggles `mentor_program_enabled` / `revenue_share_enabled`.** These are
  commission *configuration*, already rendered (with §6.1 guards) in the Commission step —
  one home per switch (§3.2).
- **Presentations / CMA master toggle.** No `presentations_enabled` column exists; a real
  master must also gate the feature (nav + compute), which is a feature build in its own
  right — deferred, not a switch.
- **WhatsApp voice transcription (`wa_transcription_enabled`).** No HTTP saver today;
  including it needs a new narrow, `$request->has()`-guarded saver — deferred fast-follow.

`test_removed_sections_are_gone` guards the removal. `test_no_step_deep_links_out_of_the_wizard`
(§3.3a) independently forbids re-adding "open the full editor" links.

- **Per-user switches on the user record** (lane note, AT-422, 2026-09-19 — *pending Johan's confirmation*):
  the "Daily Digest Email" switch (`users.daily_digest_enabled`) is a per-person admin action, not
  something an agency configures about how CoreX behaves for them, so it is not a wizard control — same
  as the existing per-user `show_on_website` / `exclude_from_p24` switches, which are not in the wizard
  either. Recorded here so the omission is on the record rather than unnoticed (CLAUDE.md 10a).

- **Road to Sold - the nine call-list sources (order, on/off), their limits and look-back windows, the colour lines and the on-track bar's steps** (2026-10-13, AT-454; **Johan's call on 13 Oct: "main settings in the wizard, selection order and colour limits on the settings page only"**). Expert knobs an agency tunes once it has seen the list working; they sit on Settings -> Road to Sold with standard defaults that work. Also not wizard controls: each agent's own suburbs and own weekday numbers - those are per-person records with their own screens, not an agency setting. Spec `road-to-sold.md` §4/§8.
- **Road to Sold - public-holiday country, the match rules and list sizes** (2026-10-13, AT-454 audit; **recorded for Johan - pending his confirmation**). `public_holiday_country` has exactly one real value today (only South African holidays are loaded), so a wizard control would offer a one-item list; it is on Settings -> Road to Sold, default ZA, and becomes a wizard control the day a second country's holidays are loaded. The list-builder values (which buyer matches and buyer states count, which statuses mean "on the market", the add-to-day ceiling, the scan window size, the three hot-pocket weights) are expert knobs like the limits above, with standard defaults that work. Spec `road-to-sold.md` §9.11.

### 5.2 Commission step — the mentor programme is optional (2026-07-11)

`commission_settings.mentor_program_enabled` (boolean, **default TRUE**) is an explicit
on/off switch rendered on **both** the settings page and the wizard's commission step,
mirroring `revenue_share_enabled`: the toggle gates its detail fields with `x-show`, so they
disappear when it is off.

Default TRUE is deliberate. The engine previously applied the mentor fee whenever an
`AgentMentor` row existed, so every agency was effectively running the programme; defaulting
to false would have silently changed the payout for any agency with mentored agents.

**Off means off, not merely hidden.** Both engine paths — `CommissionCalculationService`
and `CommissionLedger` — short-circuit the `AgentMentor` lookup when the flag is false, so a
mentee with a live mentor row is charged **no** mentor fee. Hiding the fields without gating
the engine would have been a lie. Proven: R20 000 fee on → R0.00 off, agent's take
R50 300 → R70 300 on a R100 000 (excl. VAT) commission.

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
- Progress indicator: *Step X of N · NN% complete*, where **N is the count of ACTIVE
  steps** for this agency (gated-off steps are neither shown nor counted — feature
  switchboard spec §8); progress persisted on the record (`current_step`,
  `completed_steps`).

**Explanation copy** lives in ONE authoritative, hand-written, reviewed place —
`config/agency-onboarding-copy.php` (a per-step / per-setting content map). **Not**
AI-generated at view time (must be accurate + stable). Views read from the config map.

**Copy rules (binding — STANDARDS F.8).** Every step MUST carry a `what` explainer card
(`{title, body}`) rendered above the controls, which **defines the feature in plain English
before anyone is asked to configure it** — e.g. "What Core Matches is" explains the buyer-wishlist
engine before the four Matches toggles. Every control carries:
- `explain` — a full sentence saying what the setting is, and when you'd want it either way;
- `affects` — rendered as **"What this changes:"**, a *concrete, observable* consequence the
  admin can picture. Tautologies are forbidden: "whether matches are computed at all" says
  nothing; "whether your agents get told who to call when a new listing lands" says something.
Guarded by `test_every_step_explains_itself_before_asking_for_config` (asserts every step in
`STEPS` has a `what` block and renders it).

---

### 5.3 What the agency does — sales, rentals or both (2026-10-14, Johan; cc3's new-agency walk)

**The defect.** A brand-new agency was handed syndication, payroll and every sales tool switched on, whatever it does — and the
syndication/marketing switches were not even its own: with no row of its own the agency read the platform-wide row, which holds
Home Finders Coastal's choices.

**The setting.** `agencies.agency_type` (`sales` | `rentals` | `both`, **default `both`**; existing agencies become `both` and keep
every switch exactly as it is — the migration writes no feature rows). Lists live in `config/agency-types.php`
(`sales_features` = off for rentals-only, `rentals_features` = off for sales-only, `opt_in_features` = off for every new agency).
Cascading children (Road to Sold, Commission, Leave, every `rental-*` module) switch off with their parent.

**Where it is asked.** (1) The owner's **Create Agency** form ("What does this agency do?"). (2) The wizard's **identity** step
(control `agency_type`, saver `AgencyTypeController@update` after `updateAgency`) — so the capabilities step that follows already
shows the right switches. (3) The **Features** page (own small form, same saver). One saver, three homes; §6.1 holds: it writes
only when the form posted `agency_type`, and saving the same type changes nothing.

**What it does.** `AgencyTypeFeatureSeeder::seedNewAgency()` (creation only): writes the type's switches, switches the opt-in
list off, and pins every switchboard setting that lives in `performance_settings` (marketing, syndication, core matches) to a row of
the agency's OWN at the neutral registry default — it can never fall back to another agency's platform-wide row.
`applyType()` (an admin changing the type later, on purpose) re-applies ONLY the type's own lists; every other switch stays as the
admin set it, and each real change emits `AgencyFeatureToggled`. The admin can still switch any feature on or off.

**Wizard savers are called through the container** (`app()->call`), so a saver may type-hint a service. Before 14 Oct the loop
called every saver with the request alone and `ProformaSettingsController@update` / `StaleRulesController@updateListingWindow`
crashed with `ArgumentCountError` on first save for every agency. Test: `AgencySetupEveryStepSavesTest`.

Tests: `tests/Feature/Features/AgencyTypeFeatureSeedTest.php`, `tests/Feature/Onboarding/AgencySetupEveryStepSavesTest.php`.

### 5.4 Short code - the letters on the agency's document numbers (2026-10-14, Johan)

`agencies.short_code` (2-10 letters/numbers, upper-case, unique across agencies). Payslips were numbered with a literal `HFC-`; they now
start with the agency's own code (`CTR-202610-001`). **Where it is set:** the Setup Wizard identity step (control `short_code`, saved by
`SettingsController@updateAgency` - written only when the step posted it, §6.1), Company Settings, `/settings` (agency details) and Admin >
Agencies (create/edit). **Default:** worked out from the name when the agency is created (`App\Support\AgencyShortCode::fromName`: first
letter of each real word, up to three - "Home Finders Coastal" -> HFC, "Cape Town Rentals" -> CTR, one word -> first three letters; a clash
with another agency gets a number added). A blank box keeps the code the agency has - it is never emptied, and renaming the agency does
not change it. Changing the code only affects numbers issued from then on; numbers already issued never change. The migration gave every
existing agency a code from its name, oldest first, so Home Finders Coastal is `HFC` and its numbering is unchanged.

Other document numbers were checked in the same sweep and are NOT agency-lettered: `LV-` (leave application), `DL-` (deal reference) and
`WO-` are document-type codes with no agency in them; the proforma prefix (`AgencyProformaSettings.number_prefix`) and the title-deed prefix
(`TitleDeedSetting`) are already per-agency settings. Test: `tests/Feature/Agencies/AgencyShortCodeTest.php`.

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

**Feature switchboard (step 2) reuses these savers.** The master on/off for Marketing,
the two portal-syndication feeds, Core Matches and Multi-branch — plus the public
website — are invoked from the `capabilities` step through the SAME canonical savers
(`@updateMarketingEnabled`, `@updateSyndicationPortals`, `@updateMatchesEnabled`,
`@updateSplitBranches`, and `Admin\AgencyApiKeyController@toggleWebsite`). Those masters
were **moved out** of their old detail steps (properties / matches / branches) so each
switch has one home (feature switchboard spec §3.2). Detail steps keep only their
how-it-works config. (The step numbers in the table above predate the `roles` and
`capabilities` inserts — treat the *step key*, not the number, as canonical.)

**Extraction plan:** where a step needs a method that currently only exists as a
controller action bound to a settings route, the wizard controller **calls those same
routes/methods** (dispatching a sub-request or invoking a shared service). Preferred:
lift the validation+`updateOrCreate` bodies of the touched methods into
`App\Services\Settings\*` service methods and have BOTH the settings controller and the
wizard controller call them — so they can never diverge. Each lifted method keeps its
existing validation ranges verbatim (BUILD_STANDARD §2 input-space contract preserved).

### 6.1 The saver-precondition rule (MANDATORY — added 2026-07-11)

Reusing the canonical saver is right, but it carries a cost nobody accounted for: **a wizard
step's form carries a SUBSET of its saver's fields.** Several savers were written on the
assumption that *"my form always carries this field"*, and therefore coerce an absent
checkbox to `false`. The wizard became a second caller and broke that precondition —
silently disabling `weekend_visible`, `open_hours_enabled` and `ss_show_complex_section` on
any agency that re-opened the guide from Settings. (`updatePresentations` stated the false
assumption in a comment, verbatim.)

**The rule, for every saver the wizard reuses:**

1. A boolean write MUST be guarded by `$request->has($field)` — never a bare
   `$request->boolean($field)`. Forms that own a toggle post a hidden `"0"` companion, so
   *rendered-but-unchecked* still arrives and still saves `false`; **absent means "leave it
   alone"**, not "off".
2. Before wiring a step to a saver, **read that saver for force-defaults on absence** —
   `?? false`, `?? '#0ea5e9'`, `required` rules the step won't post. If it has any, either
   guard them or write a narrow saver instead.
3. `CompanySettingsController@update` is the reference implementation — `_present` hidden
   markers + `$request->has()` guards, explicitly so sibling forms cannot wipe each other.

**`Admin\AgencyController@update` is a booby trap — do NOT reuse it.** It requires `name`
and force-defaults `is_active`, all four brand colours and six feature flags. A partial post
would **deactivate the agency and reset its branding**. If a future step ever needs a column
that only lives on that controller, write a narrow saver for those columns rather than
calling it.

Confirmed instances of the class, all now guarded:

| Saver | Field(s) | Fix |
|---|---|---|
| `@updateAgencyDashboardSettings` | `weekend_visible`, `open_hours_enabled` | `$request->has()` guard |
| `@updatePresentations` | `ss_show_complex_section` | `$request->has()` guard |
| `Commission\CommissionSettingsController@update` | `mentor_program_enabled`, `revenue_share_enabled` | `$request->has()` guard |
| `@updateMarketingEnabled` | `marketing_enabled` | `$request->has()` guard (feature switchboard §3.4) |
| `@updateSyndicationPortals` | `syndication_p24_enabled`, `syndication_pp_enabled` | `$request->has()` guard, per key |
| `@updateMatchesEnabled` | `matches_enabled` | `$request->has()` guard |
| `@updateSplitBranches` | `split_branches_enabled` | `$request->has()` guard |

The last four were hardened when the feature switchboard (spec
`.ai/specs/agency-onboarding-feature-switchboard.md`) began fanning to them as a
second caller; `toggleWebsite` (`Admin\AgencyApiKeyController`) is already safe
(`required|boolean` + the switchboard always posts the field via a hidden companion).

Regression coverage: `tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php`.

### 6.2 The current-value resolution rule (MANDATORY — added 2026-09-20, extended 2026-09-28)

§6.1 is about SAVING; this is the identical-shaped bug on the READING side — `AgencySetupWizardController::currentValues()`, which resolves what a step's controls show when the wizard is reopened.

**Bug found 2026-09-20:** the `'rental_inspections'` source had no match arm at all, so its two
controls fell through to `default => $agency->{$key} ?? ($control['default'] ?? null)` — neither key
is an `Agency` column, so that always returned null and the wizard always rendered the hardcoded
control default, never the agency's real saved value. Display-only (the save path is independent),
but not harmless: an owner reopening the wizard sees what looks like an unset field and re-saves
over their real value.

**Same bug class, found again 2026-09-28 auditing every match arm after the first fix:** three more
`'rental_inspections'` controls (`public_link_expiry_days`, `auto_pair_photos_enabled`,
`auto_send_report_enabled` — all added the same day as the shared signed-document distribution
feature) were declared in `config/agency-onboarding-copy.php` without the match arm ever being
extended to name them. A second, distinct instance of the SAME shape was also found and fixed:
`'leases'` was hardcoded to always call `LeaseSetting::expiryNoticeWindowDaysFor()`, ignoring
`$key` entirely — so its second control, `default_deposit_months`, silently displayed the
expiry-window value instead of its own. `'rental_work_orders'` had the identical single-hardcoded-
call shape with no live bug yet (only one control exists under it today) — hardened to an explicit
per-key match anyway, since `.ai/specs/rental-work-orders.md` itself names two more Stage-4 settings
landing under that same source later.

**The rule:** every `source` a control declares under is one of two safe shapes —
1. **Generic** — resolves any key via a real column/method-name lookup (`perf`, `deal_sync`,
   `proforma`, `mailbox`, `rental_application`, and the bare `agency` default). A new control here
   just works, provided its key matches a real column/resolver name — no match-arm edit needed.
2. **Explicit per-key** (`leases`, `rental_work_orders`, `rental_inspections`, `rental_inventories`, `rental_portal`, `other_agency_stock`) —
   an inner `match ($key) { ... }` naming every control under that source individually. **Adding a
   new control under one of these sources MUST add its key to that same inner match** — the exact
   step this bug skipped, twice. Never write a source's arm as a single hardcoded call regardless of
   `$key` (the shape that caused both the `'leases'` bug and, before this pass, the `'rental_work_orders'`
   latent one) — write the inner `match ($key)` even when only one control exists today.

`'proforma'`'s `start_number` control deliberately resolves to its own hardcoded default always —
confirmed intentional, not this bug: it is a one-shot "advance the counter" action
(`ProformaSettingsController::update()`), not a persisted preference with a current value to show,
the same write-only shape as `'mailbox'`'s `password`.

Regression coverage: `tests/Feature/Onboarding/AgencySetupWizardCurrentValuesTest.php` — one test
per fixed bug (real HTTP save → reopen → assert the SAVED value renders, not the hardcoded default,
for the 2026-09-20 fix; direct `currentValues()` unit coverage via Reflection for the 2026-09-28
fixes, since asserting an unchecked toggle via raw HTML is fragile) plus a structural guard that
walks every control the config actually declares and asserts, for every explicit-per-key source,
that its own key is named inside that source's own match arm — not just "does an arm exist," the
exact gap both 2026-09-28 bugs were.

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
   timestamps, open count, a **copy-link** button, and (added 2026-08-12, §R1b) a
   **"Resend invite"** button that re-sends `AgencyOnboardingSetupMail` on demand —
   independent of the first-login trigger, for when the emailed link is lost or the Admin
   needs it before ever logging in. Gated to owner role. Nav entry added in the
   Platform-Admin sidebar block same day.

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

---

### Addendum 20 Oct 2026 (AT-475 P3) - Commission step also carries one agency setting

Step 5 (`commission`) now has a `controls` entry besides its inline partial: `portal_show_agency_commission_totals` (`agencies` column, toggle, default OFF) - "Show agents their branch and agency commission totals" (My Portal). It is saved by the same `CommissionSettingsController@update` saver, which writes it only when the field was posted (section 6.1), so a form that never rendered it cannot switch it off. The same toggle is on both commission settings screens (`corex/settings/_portal-commission-totals`).
