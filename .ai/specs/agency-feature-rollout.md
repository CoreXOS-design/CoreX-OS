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
- **The wave planner board (§20) replaces the wave table and the per-wave tick-box form.** Active waves are shown as
  one ordered, dated board; the list/search/sort/filter/pagination screen applies to **archived** waves
  (`?status=archived`): search wave title or feature name, filter track, date range last updated, sort position / title /
  updated, 25 a page, Restore. Empty state of the board: "No waves yet: every feature switches on at once for agencies
  that choose gradual rollout."
- Checks: the board flags, live and on the wave itself, features not in any wave ("not placed yet: on from day one") and
  features placed before a feature they need. A feature can no longer be in two waves. The amber "Things to check" box
  stays for the two checks that need server knowledge (a feature in "Admin decides" with no access setting of its own;
  one that shares its way in with a feature in another group).

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
10. **Schema snapshot** regenerated 2026-10-20 with `scripts/schema-dump.sh` after Part 5 — covers all five AT-478
    migrations (`2026_11_18_100000` … `100400`); a lane test schema rebuilt from it (`lane-test.sh --fresh`) passes.

## 14. Build notes — Part 2 (wizard choice + agency panel) — 2026-10-20

1. **Who can choose gradual introduction:** an agency with no plan yet whose setup guide is not finished
   (`FeatureRolloutController::canStart`). An up-and-running agency is never offered it, so re-opening the
   setup guide can never hide a live team's tools. The CoreX owner page (Part 4) can still start one for any agency.
2. **The default is "Everything now".** Nothing changes unless the admin picks "Introduce gradually" AND ticks
   a confirmation that names how many features will be switched off for everyone now (validated server-side).
3. **Wizard:** the choice is the first block of the capabilities step (`agency-setup.steps.feature-rollout-choice`)
   and its saver `FeatureRolloutController@saveChoice` runs LAST, after the feature switches in the same step
   are saved, so the plan holds exactly what the admin left on. The block (and its `feature_rollout_present`
   marker) renders only for `manage_feature_rollout` holders, and the saver returns before its permission
   check when the marker is absent, so the step never fails for a user who could not see the block (§6.1).
4. **One block, two places:** `corex.feature-rollout._choice` is the wizard block AND the panel's settings form (NN #10a).
5. **Panel:** Settings → Feature rollout (`corex.feature-rollout.index`, sidebar Settings sub menu + Settings rail).
   Status, next group and date, Pause / Resume, Switch the next group on now, Switch everything on, pace
   (how often, weekdays only, side by side for sales-and-rentals agencies), the groups with each feature's
   state, Skip, and Switch on for outward-facing features. Search (group or feature name), filter (all /
   still to come / switched on, date range), sort (date — default — or name), pagination 25.
   Own agency only: no agency or plan id in any URL; item ids resolve inside the agency's plan.
6. **Side by side mid-plan** moves unreleased Rentals groups to their own lane (on) or back into the one
   list in the default order (off). Waves are created in default order so the merge is exact.
7. **Permission** `manage_feature_rollout` (settings module): admin / super_admin by the all-minus-exclude
   rule; nobody else by default. Deploys run `corex:sync-permissions --merge-defaults`.

## 15. Build notes — Part 3 (admin access review + release pop-up) — 2026-10-20

1. **One partial, both layouts:** `layouts/partials/feature-rollout-popups` (included just before the System
   Updates pop-up, rendered above it with z-[75]). At most ONE rollout pop-up per page, chosen by
   `FeatureRolloutAccess::popupFor()`: released-but-unreviewed review → upcoming review → release pop-up.
   An agency with no plan costs one indexed lookup and renders nothing.
2. **When the admin is asked:** users holding `manage_feature_rollout` (not the System Owner). Upcoming = an
   ACTIVE plan's unreleased wave once today is on or after the release day before its date
   (`RolloutCalendar::previousReleaseDay`, so a Monday wave is asked on Friday). Released = any released
   wave with no `access_reviewed_at`. All waiting waves of the same kind are shown together in one pop-up.
   A wave with nothing to decide (only features that own no permissions, or only skipped features) is not asked.
3. **Buttons:** Save access (writes the ticks, marks reviewed), Keep as is (marks reviewed), Hold this group
   (upcoming only — pauses the plan, stays unreviewed so it asks again after Resume), Ask me next time
   (session only — asked again at next sign-in). Reviewed is per wave, so one admin clears it for all.
4. **The role grid** lists the Role Manager's own editable roles (agency roles, no owner, no assistant).
   A role "has" a feature when it holds any of the feature's own nav permissions (else any permission the
   feature owns). A feature owning no permissions shows "everyone who can open this part of CoreX".
5. **Write path (§3.1):** `FeatureRolloutAccess::setRoleAccess()` touches only that feature's own permissions
   for that role. The Role Manager saver is NOT reused because it rebuilds a role's whole list and freezes
   the permissions of switched-off features — which an upcoming wave's features are. Same rules as the Role
   Manager: revoke = soft delete (the tombstone `--merge-defaults` respects); grant = restore the role's
   tombstoned rows for that feature, else its `role_defaults` keys for that feature, else just the nav
   permission. Only features of the reviewed waves and only posted roles are touched.
6. **Release pop-up:** once per user per released wave (`rollout_announcement_views`, kind `release`), listing
   only released / released-early features the user can open (feature on + nav permission). "Take the tour"
   uses `TourRegistry::canOpen()` + `launchTarget()`; no tour = the feature's explain text only. Not shown for
   waves released before the user's account existed or more than 30 days ago. Closing or opening a tour
   records it as seen (keepalive, same as System Updates).
7. **Endpoints (NN #7):** `POST /api/v1/feature-rollout/access-review` (`api.v1.feature-rollout.access-review`,
   `permission:manage_feature_rollout`) and `POST /api/v1/feature-rollout/announcements/seen`
   (`api.v1.feature-rollout.announcements.seen`). The plan always comes from the user's own agency; wave ids
   outside it are ignored.
8. **New table:** `rollout_announcement_views` (migration `2026_11_18_100100`). Schema snapshot regen still
   pending (with §13.10).

## 16. Build notes — Part 4 (owner per-agency page) — 2026-10-20

1. **List:** Agency Setup Progress gains a "Gradual introduction" column ("2 of 8 groups on · next Fri 6 Nov" /
   "Paused · …" / "Finished" / "Not on gradual introduction", `FeatureRolloutService::summaries()`, two queries),
   a filter (running / paused / finished / not on it) and a sort (next release first). The agency name links to
   the new page. Nothing else on the list changed.
2. **Page:** `admin.agency-setup-progress.show` (`/admin/agency-setup-progress/agencies/{agency}`,
   `AgencyRolloutAdminController`): setup guide steps done, introduction status, controls, pace, and the
   agency's groups (search, show all / to come / switched on / archived, date range, sort by date or name, 25 a page).
   Who checked access and when is shown per group.
3. **Starting from here:** allowed for an agency already up and running (unlike the agency's own wizard choice,
   §14.1) because it is a deliberate CoreX-team action; it names every feature that switches off and must be
   confirmed (validated server-side).
4. **Changes** (groups still to come only — a switched-on group is history and every change to it is refused
   with a plain message): add a feature (only one that is ON for the agency and not already in its plan — it is
   switched off now; a feature the agency has off is never added, so the plan can never switch on something
   they turned off), move a held feature to another group, switch one on now (released early), skip, edit a
   group's name/message, move a group earlier/later, add a group (to either lane when side by side), archive an
   EMPTY group (soft) and restore it at the end, pace (how often, release time, weekdays only, public holidays,
   side by side — hidden "0" companions, §6.1). Every change re-dates the groups still to come from today.
5. **Reset to the default list** rebuilds the groups still to come from TODAY's Dev Settings order, for the
   features those groups hold now; anything the default no longer places goes into a final "Other features"
   group, so a reset never switches anything on. The old groups are archived (soft), never deleted.
6. **Differences:** `agency_rollout_waves.source_wave_id` (the default group it was copied from) and
   `customised_at` (migration `2026_11_18_100200`). Badges "Changed for this agency" / "Added for this agency";
   "Show differences from the default" lists features only in this agency's group and features in today's
   default group that are not here.
7. **Scoping:** owner_only route + `isOwnerRole()` in every action. The agency comes from the URL (unscoped,
   multi-tenancy rule #5); group and feature ids resolve only inside that agency's plan — another agency's id
   is a 404. Changes never touch another agency or the Dev Settings defaults (tested).
8. **Moved to Part 5 (with the rest of §12):** tour completion per feature (§12.6), usage of released features
   (§12.8) and the "changed by CoreX team" audit trail (§12.10).
9. Part 2's panel now uses the shared `FeatureRolloutService::listWaves()` for its group list.

## 17. Build notes — Part 5 (the §12 additions) — 2026-10-20

Already built earlier: #2 public holidays and #3 release time (Part 1), #11 tour gap report (Part 1).

### 17a. Readiness (#1) and history (#10)
1. **Readiness** is config, not code: `config/feature-rollout.php` `readiness` lists, per feature, the `agencies`
   columns that must be filled in, their plain words and the setup-guide step that fills them. Shipped:
   DocuPerfect needs the logo (Branding) and physical address (Agency identity); Compliance needs the agency's
   FFC number and PPRA number (Agency identity). Same rules for every agency. `FeatureReadiness::missing()`.
2. When a wave is released, a feature whose basics are missing becomes **awaiting_setup** ("Set up first") —
   not switched on. The scheduler (`releaseReadyItems`, every run, on release days after the release time,
   not while paused) switches it on once they are filled in. The admin can also "Switch on anyway" (panel and
   owner page); "Switch everything on" includes it; skip works on it; switching it on from the Features page
   counts as early. The day-before prompt and the panel name what is missing with a link to that step.
3. A feature that switches on later than its wave is not re-announced in the release pop-up (the pop-up is per
   wave); it shows in the history and on the "New and coming" page (17c).
4. **History:** every change emits `FeatureRolloutChanged` (agency, action, details, signed-in actor) beside the
   existing `FeatureWaveReleased`; the wildcard audit listener stores both in `domain_event_log`, and
   `FeatureRolloutHistory` reads them back for one agency as plain sentences: who (the person, "CoreX team" for a
   System Owner, "Automatic" for the scheduler) and when. Recorded: started, paused, resumed, finished, pace
   changed, skipped, switched on (anyway / early / from Features / once set up), feature added / moved, group
   added / edited / moved / archived / restored, reset to default, access reviewed (keep or each role change).
   Section "History" on Settings → Feature rollout and on the owner's agency page; filter (switched on /
   changes / who gets access), date range, newest/oldest, 25 a page; own query names (h_*, hpage).

### 17b. Usage, tour follow-up (#6), the CoreX team's overview (#7), low use (#8)
1. **Usage:** `rollout_feature_users` (agency, user, feature: `first_opened_at`, `tour_nudged_at`,
   `early_requested_at`; migration `2026_11_18_100300`). Global web middleware `RecordRolloutFeatureUse`
   (after `EnforceFeatureRoutes`) records the first time a person opens a feature their agency's rollout
   switched on — matched by the registry's `route_names` globs or `route_prefixes` (whole segments, with or
   without `corex/`). GET page loads only, never for a System Owner; one cached lookup for an agency with no
   rollout (cleared whenever the rollout switches something); at most one write per person per feature,
   memoised in the session; never breaks a page.
2. **Who opened it / who took its tour:** under each switched-on feature on Settings → Feature rollout and
   the owner's agency page — "Opened by X of Y · Tour taken by A of Y", expandable to the names who have not.
   "Took the tour" = `user_tour_progress.completed_at` for any of the feature's tours.
3. **Tour reminders:** `rollout:release` also runs `FeatureRolloutUsage::nudgeTours()`: 3 days after a feature
   switched on (and not more than 30), each person with access who has not completed one of its tours gets ONE
   in-app notification ("Take the tour: …", linking to the tour). Not sent where no tour can be opened.
4. **Low use (suggestion only):** when under a quarter of the people with access (at least 3 people) have
   opened anything from the most recent group, 2+ days after it switched on, the panel, the owner page and the
   admin's day-before prompt say so and point at Pause / Hold this group. Nothing is ever paused automatically.
5. **Overview:** Dev Settings → Feature rollout gains "Agencies on gradual rollout": status, groups on, next
   date, and what needs attention (paused 7+ days, features waiting on setup, low use), needing-attention first;
   filter needs attention / all, search by agency name, 25 a page; each agency links to its page (§5.5).
6. Thresholds are platform-wide product tuning in `config/feature-rollout.php` `follow_up` (same for every agency).

### 17c. Day-before notice (#4), New and coming (#5 + #9), Ellie (#6)
1. **Day-before notice:** `rollout:release` runs `FeatureRolloutUpdates::notifyDayBefore()`. From the release
   time on the release day before a group switches on, the agency's rollout admins (active users holding
   `manage_feature_rollout`, never the System Owner) get ONE `RolloutDayBeforeNotification` per group — in-app
   always, email too when the person has email notifications on (same rule as `EventDueReminderNotification`).
   Not sent for a group already reviewed or with nothing held. Stamped `agency_rollout_waves.admin_notified_at`
   (migration `2026_11_18_100400`).
2. **New and coming** (`corex.new-and-coming.index`, `/corex/new-and-coming`, `NewAndComingController`): every
   signed-in person in an agency on gradual rollout; 404 for any other agency. "Coming next" = held features of
   groups still to come with their date ("When your admin resumes" while paused) and **Ask for this early**;
   "Already switched on" = every released group, newest first, ALL history (this is the place a dismissed release
   pop-up is found again), showing only features the person can open (plus "Waiting for your admin" for
   awaiting-admin / awaiting-setup ones), with Take the tour. Search, show (coming / switched on / both), date
   range, 10 groups a page, empty states. Menu link next to What's New, shown only when the agency is on a
   rollout (`FeatureRolloutService::isOnRollout`, cached); coverage exemption like What's New. The release pop-up
   links to it ("See everything new and coming").
3. **Ask for this early:** once per person per feature (`rollout_feature_users.early_requested_at`), only for a
   held feature of the person's own agency's plan. The rollout admins get an in-app
   `RolloutEarlyRequestNotification`; the agency panel shows "Asked for early by …" under the feature; the
   history records it. Nothing is switched on — the admin decides.
4. **Ellie:** read-only tool `whats_new_for_me` (`EllieToolkit`, spec ellie-v2.md §3.2): status, what switched on
   for this user in the last 30 days (only what they can open, with tour links), the next 5 groups coming, and the
   page link. The system prompt routes "what's new / what changed / what is coming" questions to it.

### 17d. Tour gap report fix — 2026-10-20

`FeatureTourCoverage` matched a tour's page against a feature's `route_prefixes` only as written, but prefixes
are written without the app's own `corex/` segment (Training: `training` for `corex/training`). So the Training
tours (and the core Contacts / Properties / Command Centre / FICA tours) were not counted, and Training showed
"no tour yet". It now matches both forms, the same way `FeatureRolloutUsage::recordVisit` already did. Test:
`FeatureRolloutDefaultsPageTest::test_a_tour_on_a_corex_page_is_matched_through_the_feature_route_prefix`.
After the fix, 26 of the 66 features a group can hold have a tour; the other 40 genuinely have none in
`TourRegistry` (all 16 Rentals features, Road to Sold, Marketing, Public Website, Title Deeds and others).
Writing those tours is separate work, not part of this ticket.

## 18. "Admin decides" group — 2026-10-20 (Andre)

**Ask (verbatim):** "Now I need somehting for the end where I can put all the last features that like agent wont see
that the admin will see in the end and then deicde if anyone should see them but they should not fo out to like the
flow or watever". **Decisions (Andre, answered in the lane):** the admin gets them **at the end**; when the admin
later gives a role access, those people get the **"New in CoreX" pop-up with the guided tour**; the agents'
"New and coming" page does **not** mention them.

**Behaviour.**
- Dev Settings → Feature rollout: a wave's Track can be **"Admin decides (at the end)"** (`feature_rollout_waves.track = 'admin'`).
  Every agency type gets those waves, in their own lane (`agency_rollout_waves.lane = 3`, `AgencyRolloutWave::LANE_ADMIN`).
- Scheduling: an "Admin decides" group is dated after the LAST of the other groups (any lane) and is only ever the
  next group once every other group has switched on — "Switch the next group on now" cannot reach it early.
  Side by side never moves it (relane skips lane 3).
- Switching on (on schedule, Switch everything on, Switch on now, Set up first → ready, outward-facing switch-on):
  `FeatureRolloutService::switchOn()` first limits the feature to the agency's **admin roles**
  (`FeatureRolloutAccess::adminRoles()` = editable roles holding `manage_feature_rollout`, else the role named
  `admin`): every other role's access is soft-revoked the Role Manager's way (a later grant restores exactly what
  the role had), the admin roles are granted. Then the feature switches on.
- No day-before review and no day-before notice for it (nobody else is affected). No low-use suggestion for it.
- Once on, the admin's pop-up asks **"Ready for you — who else should see these?"** (kind `review_admin`) with the
  role grid, Save access / **Keep for admins only** / Ask me next time. A normal group waiting for review is asked first.
- Settings → Feature rollout shows the group badged "Admin decides — admins only" and, once on, a
  **"Who else sees the "Admin decides" features"** grid (POST `corex.feature-rollout.access`, `manage_feature_rollout`,
  own agency, only those features and the agency's own editable roles; admin roles shown ticked and fixed;
  hidden "0" companion per box, §6.1). Saves are recorded in the history ("Who gets access").
- Announcing: an "Admin decides" feature is announced per person per feature, not per group — whoever can open
  it and has not been told (`rollout_feature_users.announced_at`) gets it in "New in CoreX" with its tour, however
  the access was given (this pop-up, the Feature rollout page or the Role Manager), and at any time later.
- Hidden from everyone but the rollout admins until given out: "New and coming" (coming list and its waiting
  items), Ellie's `whats_new_for_me` (same source), and asking for it early (refused).
- Agency Setup Progress (CoreX team): "Add a group" offers "Admin decides — at the end, admins only"; features can
  be moved into it like any group.

**Data.** Migration `2026_11_25_100000_add_announced_at_to_rollout_feature_users_table`. No new permission key, no
new agency setting (Dev Settings default only — not a wizard setting, #10a not triggered).

**Test.** `tests/Feature/Features/FeatureRolloutAdminDecidesTest.php` (6).

## 19. Walkthrough fixes, bigger pop-ups, feature wording & videos — 2026-10-25 (Andre)

**Ask (verbatim):** "make the fixes, also with the pop ups use more of the screen and each fetuare should have a full
explination on what the feature does, in the settings side for each feature there should be a section where a dev can
edit the wording and also add link etc for when we do vidoes for the features one day, do this and run it again".
"The fixes" = the findings of the 2026-10-25 Chrome walkthrough (local, agency "Isolation Test Realty").

### 19.1 "Admin decides" leaked features to everyone (bug)
Found: an agent got "New in CoreX → For the office → Commission Management" with its tour and could open it; the admin
was never asked "who else should see these?"; the agency panel said "on for the admins only" over a table reading
"Everyone". Root cause: limiting to admins works through the feature's OWN permissions, and many features own none in
the registry, so there was nothing to take away.
1. **A feature's access now includes its own way in.** For the rollout's access work (`FeatureRolloutAccess::ownedKeys`)
   a feature owns its registry permissions PLUS its `nav_permission` keys. Billing (`billing.view`), Finance Engine
   (`access_finance_engine`), Leases (`leases.view`), Rental Applications and the other features that have a way-in
   permission but no `permission_modules` can now be given / taken per role — in the day-before review, the
   "released while away" review, "Admin decides", and the panel grid. Only the rollout's view changes; the Features
   page's "off really means off" ownership (`AgencyFeatureService::permissionKeysFor`) is untouched.
2. **A feature with NO access control cannot be "Admin decides".** Features with no permission at all (today:
   Agent Onboarding, Buyer Pipeline, Commission Management (= My Earnings, every agent's own page), Contact Governance,
   Guided Tours, Marketing, Multi-branch, Public website) reach everyone who can open their area, so they cannot be
   kept to admins. Refused with a plain message: Dev Settings save of an "Admin decides" wave; the owner page adding or
   moving one into an "Admin decides" group. Dev Settings "Things to check" lists any already there. If an existing
   default still has one there, a NEW plan does not hold it in that group (it is left on from day one, like a feature
   in no wave) — the check names it so the CoreX team moves it.
3. **Shared way in** — a feature whose way-in permission is also another waved feature's (e.g. Rental Crews and the
   Parts & Labour Catalogue share `rental_catalogue.view`): Dev Settings "Things to check" warns when they sit in
   different groups and one of them is "Admin decides" (giving or taking one changes the other).
4. Panel / owner page wording: a row that genuinely cannot be limited reads "Can't be limited — everyone who can open
   this area has it", never a bare "Everyone" under "admins only".

### 19.2 Smaller fixes
1. **Notification toasts behind the pop-up:** while a rollout pop-up is open the alert toast stack is hidden (the bell
   keeps its count); it comes back when the pop-up closes.
2. **"Finished" wording:** when the plan is completed but features still wait (ready when you are / set up first),
   the panel says how many are still waiting for the admin instead of "everything it was holding back is switched on".
3. **"New and coming" only promises what the person will get:** a held feature is listed under "Coming next" only if
   the person's role currently has its way in (or it has no access control). Rollout admins still see everything.
   Same source for Ellie's `whats_new_for_me`.

### 19.3 Bigger pop-ups with the full explanation
Both pop-ups (admin access review and "New in CoreX") use most of the screen: up to 1200px wide and 88% of the height,
two panes on a laptop/desktop — the features on the left (grouped by group; in the review each shows "n of m roles"),
the selected feature's full page on the right: name, one-line summary, the full explanation, **Watch the video** (when
a video link is set), extra links, Take the tour, and (review only) the role ticks. Stacked on a phone. Every
feature's role ticks stay in the posted grid whichever feature is on screen (nothing is lost by switching).

### 19.4 Feature wording & videos (Dev Settings, owner-only)
- **Where:** Dev Settings → Feature rollout → **Feature wording & videos** (button on the Feature rollout page; route
  `admin.feature-rollout-defaults.copy.index`, `/corex/admin/dev-settings/feature-rollout/wording`).
- **What a dev edits per feature:** the one-line summary (default: the registry `explain`), the full explanation
  (default: `config/feature-rollout-copy.php`; blank lines = paragraphs), a video link (https), and up to 6 extra links
  (label + https URL, or a CoreX page path starting with `/`). Shown in both pop-ups. The feature NAME is not editable
  here (it is the registry label used everywhere).
- **Platform-wide, the same for every agency** — product copy, neutral wording, no agency names (NN #9). Not an agency
  setting, so not a wizard setting (#10a not triggered).
- **Data:** `feature_rollout_feature_copy` (`feature_key` unique, `summary`, `description`, `video_url`, `links` json,
  `updated_by`, timestamps, `deleted_at`). A row exists only for an edited feature; no row = the defaults.
- **List:** every feature a group can hold. Search (name, key, wording), filter (all / edited / default wording / has a
  video / no video / reverted edits), updated date range, sort (name — default — category, last updated), 25 a page,
  empty state.
- **Edit page** (`admin.feature-rollout-defaults.copy.edit`): the fields above with the default shown beside each; Save;
  **Go back to the default wording** (archives the edit — soft delete); a reverted edit can be **Restored** from the
  "Reverted edits" filter. Never hard-deleted. owner_only route group + `isOwnerRole()` in every action.

**Tests:** `tests/Feature/Features/FeatureRolloutWalkthroughFixesTest.php`, `tests/Feature/Features/FeatureRolloutCopyTest.php`.

## 20. Wave planner board (Dev Settings → Feature rollout, owner-only)

Johan, 2026-10-25: "yes I like that build it" (after reviewing the interactive mock-up of ideas 1 + 2: drag board + real dates).

**Why:** setting up waves meant ticking features in a 66-item list per wave, moving waves with ▲▼ one reload at a time,
and never seeing when a wave actually reaches a new agency. The board shows the whole plan, dated, on one screen.

### 20.1 What the owner sees
- **Pace bar** (unchanged form, same Save pace) plus a preview-only **"If an agency starts on"** date (defaults to today;
  never saved). Changing pace, start date, release time, weekdays-only or skip-holidays re-dates every wave at once,
  before saving.
- **Summary strip:** waves with features, features in a wave, features on from day one, order problems, date of the last wave.
- **Board:** a fixed "Day one" row (start date; Properties, Contacts and the always-on screens), then each active
  wave as a card: date and release time on the left, name, track, the feature chips, and the wave message on the right.
  "Admin decides" waves sit last. A wave whose date skipped a public holiday says which.
- **Dates** use the same rule as a running plan (`FeatureRolloutService::schedule`, `RolloutCalendar`): wave *k* of the
  main list is *k × interval* release days after the start; "Admin decides" waves follow *k × interval* after the last
  main wave. The preview is for one list in order (an agency doing sales and rentals, not side by side); it does not
  model the rentals-first or side-by-side orders.
- **Not placed yet tray:** every feature in no wave, grouped by category, with a search box and a count. These switch on
  from day one for a new agency. Dropping a chip on the tray takes it out of its wave.
- **Moving:** drag a chip to a wave or the tray; drag a wave by its handle (or ▲ ▼ / arrow keys on the handle) to reorder;
  on touch/keyboard pick a chip up (tap / Enter) and press "Move here". A feature can only be in one wave.
- **Inline warnings:** a feature placed in a wave earlier than a feature it needs (or whose parent is in no wave) is marked
  on its wave with a one-click "Bring <parent> into this wave".
- **Archive** a wave: its features go back to the tray; Undo until the next change. Archived waves are restored from the
  archived list. Nothing is hard-deleted (NN #1).
- **Wave message** ("What to tell people when it switches on") is edited on the wave card, as before.
- **Unsaved changes:** the board is edited in the browser and saved with one **Save plan** (Discard resets). Leaving with
  unsaved changes asks first.

### 20.2 Saving
- One owner-only route, `PUT admin/dev-settings/feature-rollout/layout` (`admin.feature-rollout-defaults.layout`), receives
  the whole ordered list `[{id|null, title, track, intro_text, feature_keys[]}]`. In one transaction: new waves are created,
  existing ones updated (title, track, message, features, position = order × 10), active waves missing from the list are
  archived (soft delete). Same rules as a single wave: ≥ 1 feature, only waveable features, "Admin decides" only holds
  features that can be kept to some roles. A feature repeated across waves keeps its earliest place. Running plans are never
  changed (they hold their own copy).
- **Safety (audit 2026-10-25):** an empty plan is refused unless the owner confirms it ("this archives every wave"); the page also
  posts the ids of the waves it showed (`known`), and only those can be archived by leaving them out, so a wave another owner added
  after the page loaded is never archived by a stale page; the existing wave ids are read under a lock inside the transaction.
  A feature found in two saved waves (e.g. after a Restore) is kept in the earliest on the board, with a notice saying so.
- The older per-wave endpoints (`store`, `update`, `move`, `destroy`, `restore`) stay; `restore` serves the archived list.
  `store`, `update`, `move` and `destroy` no longer have any screen using them (candidates for removal).
- No new setting is added, so nothing is added to the setup wizard (NN #10a not triggered). No new API endpoint (NN #7 not
  triggered): this is a web form post on an existing owner-only admin page. Multi-agency (NN #9): the page is platform-wide
  Dev Settings, no agency names; the holiday calendar is the saved country code.

### 20.3 Scoping / permissions
Owner-only (route group + `isOwnerRole()` in every action), unchanged. No agency-scoped data on this page.

### 20.4 Acceptance
1. Board shows every active wave in order with the date the wave reaches a new agency, matching `RolloutCalendar`.
2. Changing pace/start date re-dates the board without a reload.
3. Dragging/picking a feature between waves and the tray, and reordering waves, then **Save plan**, persists order, features,
   titles, tracks and messages; unsaved changes are never written.
4. Archiving a wave via Save puts it in the archived list; Restore brings it back.
5. Invalid saves (empty wave, core/opt-in feature, non-limitable feature in "Admin decides", unknown wave) are refused with a
   plain message and write nothing.
6. Non-owners are refused.

**Files:** `FeatureRolloutDefaultsController` (index, `saveLayout`), `RolloutCalendar` (`holidayMap`), `routes/web.php`,
`resources/views/admin/dev-settings/feature-rollout.blade.php`, removed `_feature-rollout-wave-form.blade.php`.
**Tests:** `tests/Feature/Features/FeatureRolloutDefaultsPageTest.php`, `tests/Feature/Features/FeatureRolloutBoardTest.php`.

## 21. The access review looks and works like the Role Manager

Johan, 2026-10-25, verbatim: "it needs to look like the role manager with all settings related to the feature just like in role manager".

**Why:** the review pop-up (the day-before pop-up, the "went live without a review" pop-up and the "Admin decides" pop-up) gave
one tick per role per feature. A feature owns many settings in the Role Manager (menu access, how much data a role sees,
create / edit / archive, extra actions). The admin must be able to decide all of them at the moment a feature is about to
switch on, exactly as in the Role Manager.

### 21.1 What the admin sees (review pop-ups only; the "New in CoreX" release pop-up is unchanged)
For the selected feature, under "Who gets it":
- **Role pills** (the agency's own roles, as in the Role Manager): each shows ✓ when that role has the feature's way in. Clicking
  one chooses the role being edited. The feature list on the left still says "n of m roles".
- A panel headed "Editing permissions for: <role>", then, for every permission module the feature owns (a module heading only when
  there is more than one), the Role Manager's own rows:
  - **Access permissions**: Enabled / Disabled, "Menu / section visibility", and the amber "Default breadth…" line where the Role
    Manager shows it (AT-401).
  - **Data Scope**: None / Own / Branch / All; for Properties and Contacts the single On / Off toggle; "Shared — all users" for the
    shared modules; the amber "Default (nothing stored for this role)…" line while nothing is stored.
  - **Create / Edit / Archive** (the configured label is the title), then **other actions**; Enabled / Disabled.
- The same ticking rules as the Role Manager: Data Scope **None** unticks create / edit / archive; ticking create / edit / archive
  on a role whose scope is None sets Own; ticking one on a role that only has the default view makes that view grant real.
- A feature that owns no permission at all keeps "Everyone who can open this part of CoreX gets it — there is no separate access to set."
- Wording and look are the Role Manager's (module labels come from one shared list, `App\Support\Permissions\RoleManagerLabels`).

### 21.2 What Save access writes
- `POST /api/v1/feature-rollout/access-review` accepts `settings` = `{role: {permission_key: true|false}}` and
  `scopes` = `{role: {view_key: none|own|branch|all}}`, only for what the admin changed. The older `access` (feature × role tick) still works.
- Only permission keys the reviewed groups' features own, and only the agency's own editable roles, are honoured; everything
  else is ignored (§3.1 / §6.1). Nothing is rebuilt: the Role Manager's whole-role saver is not used.
- A revoke is a soft delete (the tombstone the permission sync respects); a grant restores the tombstoned row and keeps its old
  scope unless a new one was chosen. Scope is stored on `.view` keys only. Server-side rules mirror the interlocks above, plus
  `branches.edit_all` implies `branches.view_all`.
- A module can own keys that end in the same word (`auctions.view` and `auctions.reserve.view`): every key is listed. The module's own
  view key is the one with the fewest dots; create / edit / archive are the ones beside it; any other key appears as an extra action.
- History records, per feature and role, "gave", "took away" (the feature's way in) or "changed the settings of".

### 21.2a One Done per feature (AT-478, 2026-10-25)
An ordinary admin reads "Done" as "I'm finished with this feature, show me the next one". So the pop-up never closes
on one feature's say-so:
1. The footer has one primary button, **"Done with <feature> — next: <feature> →"**. It marks that feature done and
   opens the next one that is not done. The last one reads **"Done with <feature> — finish"**.
2. The left list shows **✓ Done** under each finished feature and "… · not done yet" under the rest; the footer counts
   "n of N features done". A feature with no separate access to set still needs its Done.
3. Only when every feature has had its Done does the pop-up send **one** save: the ticks and Data Scopes changed
   across all features (action `save`), or `keep` when nothing was changed. Then it closes and the page reloads.
4. A feature can be reopened and changed after its Done; the final send carries everything. If the send fails the pop-up
   stays open with the error and the same button sends again.
5. The old whole-group buttons (**Keep as is**, **Keep for admins only**, **Save access**) are gone — Done with no
   changes is "keep as is". **Hold this group** (upcoming only) and **Ask me next time** stay, and still write nothing.

### 21.3 Out of scope
Settings → Feature rollout (the "who else sees these" panel) is not changed. The Role Manager's behaviour is unchanged (its module
labels moved to the shared `RoleManagerLabels` class).

### 21.4 Acceptance
1. The review pop-up shows, for a feature with several settings, the same rows as the Role Manager for the chosen role.
2. Changing one create/edit/archive or extra action, or a Data Scope, then Save access, changes exactly that and nothing else.
3. Data Scope None removes view/create/edit/archive for that role and module; ticking an action when scope is None grants view as Own.
4. Other roles, other features' permissions, and permissions the feature does not own are never touched.
5. Hold / Ask me next time write nothing; Done on every feature with no changes writes nothing (it just marks the review done).
6. Finishing one feature never closes the pop-up while other features are not done (§21.2a).

**Files:** `FeatureRolloutAccess` (`settingsFor`, `applySettings`), `FeatureRolloutPopupController::review`, `feature-rollout-popups.blade.php`,
`FeatureRolloutHistory`, `RoleManagerController` and new `RoleManagerLabels` (labels only). **Tests:** `tests/Feature/Features/FeatureRolloutAccessSettingsTest.php`.
