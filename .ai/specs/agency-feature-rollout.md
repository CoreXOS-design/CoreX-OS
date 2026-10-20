# Gradual Feature Rollout for new agencies (AT-478) — Spec

> Status: **Approved 2026-10-20** (all answers + §12 additions + §5.5 owner per-agency page)
> Lane: QA2 (stays on QA2 until Andre's go → Staging; live only on Johan's explicit order)
> Ticket: AT-478
> Builds on (read first): `.ai/specs/corex-feature-registry.md` (the per-agency feature switches),
> `.ai/specs/features-page.md` (permission ownership, "off really means off", agency type §114),
> `.ai/specs/agency-onboarding-setup.md` (wizard; §6.1 saver precondition binds),
> `.ai/specs/corex-domain-events-spec.md` (cross-module reactivity).
> Pillars: Agent (User — roles/access, announcements), Property + Contact (the day-one core),
> Deal (becomes a switchable, held-back feature). Ingests no property data (NN #10 N/A).

---

## 1. What this does and why

A brand-new agency opening CoreX for the first time is shown ~70 modules at once. That is
overwhelming and most of it goes unexplored. **Gradual rollout** is an opt-in mode chosen during
agency setup: the agency starts with only **Properties and Contacts** (plus the screens that can never
be switched off — dashboard, settings, users), and every few days CoreX switches on the next
**wave** of features, introduces them with a popup, and points each person at the guided tour.

It is NOT a new switch system. Every on/off it performs goes through the existing per-agency feature
switches (`AgencyFeatureService` / `agency_features`) and every access change goes through the
existing Role Manager write path. The rollout is only a **schedule** on top of those.

## 2. Business behaviour (plain language)

1. **Opt-in.** During agency setup, the admin chooses *"Introduce CoreX gradually"* or *"Turn
   everything on now"*. Only that agency is affected. Existing agencies are untouched.
2. **Day one.** Only Properties, Contacts and the always-on screens are visible. **Deals is hidden.**
3. **Waves.** Features arrive in themed groups (e.g. "Deals & documents", "Marketing &
   presentations", "Compliance"), one wave every *N* **working days** (default 2). The default order is
   set by the CoreX team in **Dev Settings → Feature rollout**.
3a. **Weekdays only.** Waves are released Monday to Friday only. Weekends never count towards the
   interval and nothing is released on them, so a wave due on a Saturday or Sunday is released on Monday.
   The "day before" admin popup follows the same calendar: for a Monday wave it is shown on Friday.
   "Weekdays only" is a setting: platform default in Dev Settings (on), and per agency in agency setup.
4. **Rentals and both.** *Rentals only* agencies get the rentals track first. Sales tools stay off for
   them (unchanged agency-type behaviour) and the rollout never releases them. *Sales and rentals*
   agencies follow the normal order by default, with a setup option **"Introduce sales and rentals
   side by side"**. With that option on, each release day releases the next wave of the sales track AND
   the next wave of the rentals track together.
5. **The day before a wave goes live** the agency admin gets a popup when they sign in: *"Next working
   day these features switch on."* For each feature it shows the agency's roles with a tick-box each,
   like the Role Manager does. The admin can leave the access as it is, untick roles, give access only
   to the roles they pick, or say "nobody yet". Saving updates the real Role Manager settings.
5a. **Admin didn't sign in the day before.** The wave still goes live on schedule with the current
   access. The next time the admin signs in, the **same role-access prompt is shown first**, before any
   other popup (including the release announcement), for every released wave they haven't reviewed. It
   reads "These went live on <date>, check who has access" and saves through the same path.
6. **Release day.** Every user who has access to at least one of the new features gets a popup
   once: *"New in CoreX: …"* with a **Take the tour** button per feature (or a short explanation
   where no tour exists yet). Users without access to any of them see nothing.
7. **Admin control** (agency setup → Feature rollout): see the progress (released / next up / date),
   **pause / resume**, **release a wave now**, **skip a feature**, change the interval, or
   **turn everything on** and end the rollout.
8. **Admin choices always win.** If the admin switches a feature on early on the Features page,
   the rollout counts it as released. If they switch a held-back feature off on purpose, the rollout
   drops it and never switches it back on.
9. **Never automatic for things that go outside the agency.** Portal syndication and payroll already
   start off for every new agency and are not part of the rollout. The public website, outreach and
   automatic client messages are released as **"ready when you are"**: the popup shows them, and the
   admin has to click to turn them on.
10. **Deals is hidden on day one and arrives in its wave** like any other feature. If the admin
    unticks every role in the day-before prompt, nobody sees it. That is the admin's call.

## 3. Architecture decisions

### 3.1 One write path
- Feature on/off → `AgencyFeatureService` setter (the Features page's canonical saver).
- Role access → the Role Manager's canonical role-permission saver (per-agency roles,
  `role_permissions`). The day-before popup posts a SUBSET of permissions; the saver must only touch
  the permissions the popup rendered (the §6.1 precondition, applied to roles).
- Which permissions "belong" to a feature comes from the registry's existing
  `permission_modules` / `permission_keys` ownership. A feature with no owned permissions shows
  "Everyone with access to <parent area>" instead of a role grid.

### 3.2 What the rollout "holds"
At opt-in, after the agency-type seeding has run, the rollout **holds** every feature that is
currently ON and not core. It switches these OFF and records them on the agency's plan. Features that are
off for any other reason (agency type, opt-in list) are not held and are never released by the rollout.

### 3.3 Deals becomes switchable
`deals` moves from `core => true` to a normal feature with `default => true` (so non-rollout agencies
are unchanged). Its dependants (`agency-tracker`, `commission-management`, `proforma-invoices`, …)
gain `depends_on: ['deals']`. Every link to deal screens from other pages must be wrapped in
`@feature('deals')`. `FeatureNavGuardCoverageTest` enforces this. **This is the largest part of the
build** and touches every screen that links into Deals.

### 3.4 Snapshot on opt-in
When an agency opts in, the platform default order is **copied** into that agency's own plan (and
re-ordered rentals-first for Rentals-only agencies). Later Dev Settings edits affect new agencies
only. A running rollout is never reshuffled underneath an agency.

### 3.5 Scheduler
Daily scheduled command `rollout:release` (idempotent, runs on weekdays when `weekdays_only` is set).
For each active plan whose next wave is due, it switches on the wave's held features (except "ready
when you are" ones) and stamps `released_at`, then emits `FeatureWaveReleased`. With side-by-side on,
it releases one due wave per track. Missed days (server down) catch up one wave per track per run,
not all at once. All dates are calculated in the agency's timezone. `scheduled_for` is recomputed by
one date helper that skips Saturday and Sunday when weekdays-only is on (pause/resume and interval
changes use the same helper).

### 3.5a Admin access-review gate
On sign-in for a `manage_feature_rollout` user, the layout checks for (a) a wave due on the next
release day that hasn't been reviewed, or (b) a released wave that hasn't been reviewed. Case (b) is
rendered first and blocks the user release popup until it is saved or kept. "Reviewed" is
`agency_rollout_waves.access_reviewed_at`, so one admin reviewing clears it for every admin.

### 3.6 Domain events (NN #9)
- Emits `FeatureWaveReleased(agency, wave)`, which drives the user announcement.
- Listens to the existing feature-toggle event from the Features page, which marks held features
  released-early or dropped (§2.8). If no such event exists today, it is added to the catalogue in
  this build.

## 4. Data model (new migrations)

| Table | Purpose | Key columns |
|---|---|---|
| `feature_rollout_waves` | Platform default order (Dev Settings) | `position`, `title`, `intro_text`, `feature_keys` json, `track` (`default`/`rentals`), timestamps, `deleted_at` |
| `agency_feature_rollouts` | One plan per agency | `agency_id` (unique), `status` (active/paused/completed/cancelled), `interval_days`, `weekdays_only` bool, `side_by_side` bool, `started_at`, `next_release_on`, `paused_at`, `completed_at`, `deleted_at` |
| `agency_rollout_waves` | The agency's snapshot of waves | `agency_id`, `rollout_id`, `track` (`sales`/`rentals`/`default`), `position`, `title`, `intro_text`, `feature_keys` json, `scheduled_for`, `access_reviewed_at`, `access_reviewed_by`, `released_at`, `deleted_at` |

Platform defaults (interval, weekdays-only) are `DevSetting` keys `feature_rollout.interval_days` and
`feature_rollout.weekdays_only`.
| `agency_rollout_items` | One held feature | `agency_id`, `wave_id`, `feature_key`, `state` (held/released/released_early/dropped/awaiting_admin), `released_at` |
| `rollout_announcement_views` | Who has seen which popup | `agency_id`, `wave_id`, `user_id`, `kind` (admin_preview/release), `seen_at` |

All agency tables use `BelongsToAgency` / `AgencyScope`. `feature_rollout_waves` is platform-level
(owner-only). All models use SoftDeletes. Nothing is hard-deleted. `schema:dump` + DEFINER strip after the
migrations (NN #12a).

## 5. Screens

### 5.1 Dev Settings → Feature rollout (owner-only, new section `feature_rollout`)
List of default waves for each track (Default / Rentals first). Supports create, edit, drag to reorder,
archive and restore. Each wave has a title, intro text and features (multi-select from the registry,
excluding core and opt-in features). Tracks: Default, Sales, Rentals (Rentals-only agencies run
Rentals first. Side-by-side runs Sales + Rentals together).
- **How often:** a picker of *Every day / Every 2 days / Every 3 days / Every week / Custom (1–10
  days)*, default every 2 days. Counted in working days when Weekdays only is on.
- **Weekdays only** default (on), **Skip public holidays** default (on), **Release time** default 07:00.
- **Features per wave:** pick exactly which features each wave switches on. Each feature shows a
  "no tour yet" badge where it has none.
- **Tour gap report** (§12.11): every feature in a wave with no guided tour, ordered by how soon it is
  released across all agencies (earliest wave first = write that tour first), with counts.
- **Owner overview** (§12.7): links to the per-agency page (§5.5).
- Search: wave title, feature name. Sort: position (default), title, updated. Filter: track, status
  (active/archived). Date range: last updated. Pagination: 25. Empty state: "No waves yet: every
  feature switches on at once for agencies that choose gradual rollout."
- A check flags features not in any wave ("these will never be released") and features in two waves.

### 5.2 Agency setup wizard (NN #10a)
- New choice on the first capability step: **Introduce gradually / Everything now**. If gradual:
  interval (working days), **Weekdays only** (default on), and, for *Sales and rentals* agencies only,
  **Introduce sales and rentals side by side**. Copy follows the wizard `explain` / `affects` contract.
  Every boolean saver write is guarded with `$request->has()` (parent §6.1).
- **Settings → Feature rollout** panel: progress tracker (wave list with dates, track and state),
  pause/resume, release now, skip feature, change interval, weekdays-only, side-by-side, turn
  everything on. The same controls appear in the wizard and on the panel (NN #10a, no drift).
  Scoped to the agency. Requires the new permission.

### 5.3 Admin day-before popup
Shown at sign-in to users holding `manage_feature_rollout` when a wave is due within 24h and not yet
reviewed. For each feature: name, one-line explain, and a role × access tick grid (pre-ticked with
current access). Buttons: **Keep as is**, **Save access**, **Hold this wave** (pauses the plan). Once
saved or kept, it is not shown again for that wave. If the admin never signs in, the wave still goes live
on schedule with the current access, and the prompt is shown first at their next sign-in (§2.5a, §3.5a).

### 5.4 User release popup
Reuses the existing `system-update-modal` pattern. Shown once per user per wave, only listing features
the user can access. Each has a **Take the tour** button (TourRegistry) or its explain text if no tour exists.

### 5.5 Admin → Agency Setup Progress → per-agency rollout page (owner-only)
The existing **Agency Setup Progress** list (`admin.agency-setup-progress`) gains a **Rollout**
column ("Wave 3 of 8 · next Tue 22 Oct" / "Paused" / "Not on gradual rollout") and each agency row
becomes clickable, opening a new page `admin.agency-setup-progress.show` for that agency:
- **Progress:** setup wizard steps done, rollout status, each wave with its date, state, features,
  who reviewed access and when, tour completion per feature, and usage of released features (§12.6, §12.8).
- **Change this agency's plan:** add or remove features from a wave, move a feature to another wave,
  reorder waves, add a wave, change how often, weekdays only, holidays, release time, side-by-side.
  Plus pause / resume / release next wave now / turn everything on, and **Reset to default list**
  (re-copies the current Dev Settings order for waves not yet released). Released waves are
  read-only history. Changes affect only that agency and are recorded in the audit trail (§12.10)
  with "changed by CoreX team".
- **Differences from default:** a badge on each wave the owner has changed, and a "show differences"
  view against the Dev Settings default.
- Cross-agency by design (owner tool): the controller reads with the explicit owner bypass the
  existing progress controller already uses (multi-tenancy rule #5). It is never reachable by agency
  users (`owner_only` route middleware + controller check). Agency admins edit their own plan only
  through their Settings panel (§5.2), which is agency-scoped.
- The list keeps its current behaviour. The new **Rollout** column is sortable and a **Rollout status**
  filter is added (on rollout / paused / completed / not on rollout).

## 6. Permissions
New keys in `config/corex-permissions.php`: `manage_feature_rollout` (agency admin: tracker panel +
day-before popup). Dev Settings section is behind the existing `owner_only` middleware. Routes are
named, and JSON endpoints live under `/api/v1/feature-rollout/*` (NN #7).

## 7. Multi-agency (NN #9)
No agency IDs or HFC wording. The default order is neutral. The interval and every wave are per agency
after the snapshot. A second agency (e.g. rentals-only) gets the rentals-first track automatically.

## 8. Acceptance criteria
1. Opt-in agency: only Properties, Contacts and core screens are visible. Deals routes return 404 and Deals links are hidden.
2. Non-rollout agencies (including HFC): no visible change. Deals works as today.
3. Rentals-only agency: first waves are rentals. Sales features are never released. Sales-and-rentals
   with side-by-side: one sales wave + one rentals wave per release day. Without it: default order.
4. Wave due next working day: admin sees the popup once. The saved role grid changes exactly the rendered
   permissions and nothing else.
4a. Admin absent the day before: the wave goes live on schedule. At next sign-in the access prompt
   renders before the release popup.
4b. Weekdays only: a wave falling on Saturday/Sunday is scheduled for Monday, its preview shows on
   Friday, and the scheduler releases nothing on weekends. With the setting off, weekend dates are used.
5. Release run: held features (including Deals) switch on, outward-facing ones go to "awaiting
   admin", and re-running the same day changes nothing.
6. User with access sees the release popup once with tour buttons. User without access sees nothing.
7. Admin switches a held feature on early → released_early. Switches one off → dropped, never re-enabled.
8. Pause stops releases; resume reschedules from today. "Everything on" releases all held features
   and completes the plan.
9. Editing Dev Settings waves does not change a running agency's plan.
10. Another agency's plan/waves are unreachable by ID (scoping test).
11. Dev Settings "How often" accepts every day … every week / custom 1–10 and new agencies' schedules follow it.
12. Owner per-agency page: changing a feature's wave for agency A changes only A's plan, leaves the
    Dev Settings default and agency B untouched, and is audit-logged. Released waves can't be edited.
    A non-owner gets 403 on the page and every action.
13. Public holiday on a release day moves the wave to the next working day. Releases happen at the
    agency's release time in its timezone.
14. Readiness: a feature whose setup isn't done is held as "set up first" (not released), and the admin
    popup links to the setup step.
15. Tour gap report lists exactly the waved features with no TourRegistry tour.

## 9. Files (expected)
New: migrations (§4), models, `App\Services\Features\FeatureRolloutService`, `rollout:release`
command (+ schedule), `FeatureWaveReleased` event + listener, Dev Settings section view + saver,
wizard step copy in `config/agency-onboarding-copy.php`, tracker panel view, two popup partials,
`/api/v1/feature-rollout/*` routes + controller, tests under `tests/Feature/Features/FeatureRollout*`.
Owner page: `AgencySetupProgressController@show` + plan-edit actions, `admin/agency-setup-progress/show`
view, column/filter on the existing index view, routes `admin.agency-setup-progress.show` and
`admin.agency-setup-progress.rollout.*` (owner_only).
Modified: `config/corex-features.php` (`deals` not core + depends_on), views linking to Deals
(`@feature('deals')` wraps), `DevSettingsController` (new section), `config/corex-permissions.php`,
layout (popup includes), `AgencyTypeFeatureSeeder` (run before the hold step).

## 10. Deliberately NOT in this build
- Per-user (rather than per-role) access in the popup. The Role Manager works per role.
- Changing existing agencies. Rollout is opt-in at setup only.

## 11. Decisions on record (2026-10-20)
1. Deals arrives in its wave. If the admin gives no role access, nobody sees it.
2. Admin absent the day before: go live on schedule, and the access prompt comes first at next sign-in.
3. Sales-and-rentals agencies: normal order, with a side-by-side option.
4. Weekdays only, no weekends. A setting (Dev Settings default + per agency).

## 12. Additions — APPROVED 2026-10-20 (all eleven in scope; #11 tour gap report explicitly confirmed)
1. **Readiness check before release.** Don't release a feature the agency hasn't set up the basics
   for (e.g. documents need a letterhead, compliance needs FFC details). The admin popup says "set
   this up first" and links straight to that setup step.
2. **Public holidays.** Skip public holidays as well as weekends, using a holiday calendar the agency
   can see. The default calendar follows the agency's country, not hard-coded SA dates (NN #9).
3. **Release time.** Release at the start of the working day in the agency's timezone (e.g. 07:00),
   not at midnight.
4. **Admin email/notification the day before**, as well as the popup, so a busy admin still knows.
5. **"Coming soon" page for every user.** Shows what's coming next and when, with a "Request this
   early" button that notifies the admin.
6. **Tour follow-up.** Show the admin who took each tour, nudge users who haven't after a few days,
   and let Ellie answer "what's new for me?".
7. **Owner overview in Dev Settings.** Every agency on gradual rollout, their current wave, and agencies
   paused for a long time or not using what was released, so support can call them.
8. **Pace from real use.** If almost nobody has opened the last wave, suggest the admin pause
   before more arrives (suggest only, never automatic).
9. **"What's new" history.** A dismissed release popup can be found again later.
10. **Audit trail.** Every release, skip, pause and access change, with who and when.
11. **Tour gap report.** Features in a wave with no guided tour (~60 of ~77 today), so the team knows
    which tours to write first.

## 13. Build notes — Part 1 (engine, Dev Settings, Deals switchable) — 2026-10-20

Decisions made while building Part 1. Where these refine an earlier section, this section wins.

1. **Tracks** are stored as `general` / `sales` / `rentals` (shown as General / Sales / Rentals). Lanes:
   rentals-only = Rentals waves then General; sales-only = General + Sales; both = one list in order;
   both + side by side = lane 1 General + Sales, lane 2 Rentals. One ordered list in Dev Settings, each wave badged with its track.
2. **Features in no wave are NOT held.** New agencies get them from day one. Dev Settings flags them
   ("Not in any wave") with a check, plus two more checks: a feature in two waves, and a feature placed before a feature it depends on.
3. **One plan per agency, ever.** `start()` on an agency that already has a plan returns that plan. A finished
   plan is the record of how the agency was introduced and is never deleted.
4. **Outward-facing list** (`config/feature-rollout.php` `admin_switch_on`): `public-website`, `outreach`.
   Portal syndication and payroll are already off for every new agency (`agency-types.opt_in_features`), so they are never held.
5. **"Every week"** = 5 release days (a working week when weekdays-only is on). Custom = 1–10.
6. **Public holidays** read `public_holidays`. For ZA the year is generated on demand (the generator only
   knows SA rules). Any other country uses the rows already there.
7. **Catch-up:** a missed day releases one wave per lane per run, never several at once. The scheduler runs every 15 minutes.
8. **Deals** (`config/corex-features.php`): switchable, on by default. Owns the deal register screens (legacy, DR2, deals-v2)
   and their permissions. Deliberately NOT owned, because other areas use them: suppliers & contractors and their
   service types (Rentals), and the plain property / contact / attorney / supplier lookups (seller info, deeds capture).
   Commission Management and Proforma Invoices now depend on Deals. Every cross-screen link to a deal page
   is wrapped (`FeatureNavGuardCoverageTest`).
9. **One write path:** `AgencyFeatureWriter` (extracted from `AgencyTypeFeatureSeeder`) is used by the type seeder and the rollout.
10. **Schema snapshot** not yet regenerated for `2026_11_18_100000_create_feature_rollout_tables` — the migration
    runs on top of the snapshot. Run `scripts/schema-dump.sh` with the next batch.
