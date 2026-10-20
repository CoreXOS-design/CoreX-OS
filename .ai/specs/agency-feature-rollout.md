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
