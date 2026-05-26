# Multi-Tenancy (Agency Isolation) — Spec

> Status: Active (implemented 2026-04-14 after cross-agency leak found on staging).
> Owner: Andre / Johan
> Severity: Security-critical. Treat regressions as P0.

---

## Why this exists

CoreX OS is a **multi-agency** platform. Every operational row (properties,
contacts, deals, presentations, documents, agents, branches) belongs to
exactly one agency, and no user of Agency A may ever read or write data
owned by Agency B.

On 2026-04-14 staging was shown to be leaking properties and agents
across agencies because:

1. Several pillar tables (`contacts`, `deals`, `presentations`,
   `documents`) had no `agency_id` column at all.
2. Controllers relied on ad-hoc `where('agency_id', …)` checks, which
   were missing in `PropertyController@index` and anywhere newly added.
3. `AgencySwitcherController` stored `active_agency_id` in session with
   no authorisation check, so any user with the permission key could
   impersonate any agency.

The fix is structural, not a patch: isolation is enforced at the model
layer via a global scope, not at the controller layer.

---

## Pillar tables that MUST carry `agency_id`

| Table | Backfill source |
|-------|-----------------|
| users | (already present) |
| branches | (already present) |
| properties | (already present) |
| contacts | `users.agency_id` via `created_by_user_id` |
| deals | `branches.agency_id` via `branch_id` |
| presentations | `branches.agency_id` via `branch_id` |
| documents | `users.agency_id` via `uploaded_by` |

Any new table that stores tenant-owned data MUST include `agency_id`
from migration day one. No exceptions.

---

## How isolation is enforced

### 1. `App\Models\Concerns\BelongsToAgency` trait

Every agency-owned Eloquent model uses this trait. It:

- registers `AgencyScope` as a global scope,
- auto-fills `agency_id` on `creating` from `Auth::user()->effectiveAgencyId()` when blank,
- exposes an `agency()` BelongsTo,
- exposes `queryWithoutAgencyScope()` as the **only** sanctioned escape hatch
  (use for console commands, queues and system imports; never in request code).

**Single-agency dev/test fallback (Wave 3b).** When `creating` fires with no
`agency_id` and no authenticated user (seeders, factories, console commands
on a fresh dev/test DB), the trait inspects the `agencies` table. If — and
only if — exactly one agency row exists, that id is stamped onto the new
model. This matches Wave 3b backfill semantics and prevents seeders from
crashing on `NOT NULL agency_id`. It NEVER fires in multi-agency
production, because the count check returns 0 once a second agency exists.
The lookup is cached per request.

### 2. `App\Models\Scopes\AgencyScope`

Applies `WHERE agency_id = :effectiveAgency` (strict match) to every query
on models that use the trait. Notes:

- **NULL `agency_id` is treated as an orphan, NOT as shared/global.** An
  earlier draft of the scope allowed `agency_id IS NULL` through as
  "shared", but in practice any NULL on a tenant table is a pre-migration
  orphan or a write that escaped the auto-fill, and treating those as
  shared caused them to leak into every agency. The scope now requires a
  strict match — orphans are invisible to tenants and only surface via
  `queryWithoutAgencyScope()` for audit/cleanup. See the inline comment
  at `AgencyScope::applyInner()` (around lines 86–91) for the canonical
  statement of this rule.
- Skipped when there is no authenticated user (console, migrations, login queries).
- Skipped for owner-role accounts that have NOT activated the agency switcher
  (so platform owners see everything until they deliberately scope in).
- Respects the session override `active_agency_id` via `User::effectiveAgencyId()`.
- Recursion-guarded per model class so the `User` scope is safe during auth
  resolution.
- **Self-row carve-out for `User`.** The authenticated user's own row is
  always visible to them (`orWhere(id = authId)`), so a stale session
  agency doesn't kick them out at the auth-provider stage. System Owners
  with `agency_id = NULL` are handled by the owner-role bypass above,
  not by this clause.

### 2a. Models that legitimately need "shared" rows

Some configuration-style models genuinely store global defaults alongside
per-agency overrides (e.g. system-provided calendar event classes,
default feedback options). Because the scope no longer interprets NULL
as shared, such models must do one of the following:

- **Do not use `BelongsToAgency`.** Apply tenant filtering manually in
  the controller/service. Suitable for catalog tables that are read
  freely across agencies (e.g. `roles` with `agency_id IS NULL` for
  system roles).
- **Use the trait and add an explicit `scopeShared()` (or equivalent)
  carve-out** that calls `withoutGlobalScope(AgencyScope::class)` and
  `whereNull('agency_id')` to fetch shared rows on demand. Never assume
  the global scope will let NULL rows through — it will not.

If a model uses `BelongsToAgency` AND its calling code does
`Model::whereNull('agency_id')` directly without bypassing the scope, the
query will resolve to an empty set under any authenticated tenant
context. That is a bug — flag it.

### 3. `AgencySwitcherController`

Validates the target agency before writing `active_agency_id` into session:

- owner-role users may switch into any agency,
- non-owner users may only switch into agencies they personally belong to
  (matched via `users.agency_id` or `users.branch_id → branches.agency_id`),
- otherwise responds with 403.

---

## Rules for every new feature

1. **New model holds tenant data?** Add `agency_id` to the migration, use
   `BelongsToAgency` on the model, index the column. Do it in the first
   commit, not later.
2. **Never call `->where('agency_id', …)` manually in new code.** The global
   scope does it. Manual filters are redundant and drift from the real
   source of truth.
3. **Never use `Model::withoutGlobalScope(AgencyScope::class)` in request
   code.** If you think you need it, you are probably designing a leak.
   Console/queue only.
4. **Route-model binding** (e.g. `Property $property`) is already safe —
   the binder runs the query through the scope, so mismatched tenants 404.
   Rely on that; do not re-check.
5. **Owner-role "see all" is intentional.** If a feature needs to aggregate
   across agencies (platform-wide KPIs, superadmin tooling), gate the UI
   on `$user->isOwnerRole()` and use `queryWithoutAgencyScope()` explicitly
   on the query you need unscoped.
6. **Agency switcher** must remain the single entry point for owners who
   want to act inside one agency. Don't invent a second mechanism.

---

## Verification checklist (before declaring any feature done)

Run through this list on every module that reads or writes a pillar table:

- [ ] Log in as a user of Agency A. Can you see any Agency B records
      anywhere in the UI (lists, search, autocomplete, API endpoints,
      exports)?
- [ ] Can you hit an Agency B record by URL (`/properties/{id}` where
      `{id}` belongs to B)? Expected: 404.
- [ ] Does creating a record from the UI assign the correct `agency_id`
      automatically?
- [ ] Does the agency switcher 403 when an Agency A user posts a switch
      request for Agency B?
- [ ] Does `php artisan tinker` with a non-owner user show only that
      user's agency rows on every pillar model?

If any item fails, the feature is not done.

---

## System Owners — platform identities, not agency members

Added 2026-04-14.

A "System Owner" is a platform-level operator (e.g. Andre, Johan). They
authenticate through the same `users` table as everyone else — login,
sessions, permissions, all reuse the existing infrastructure — but they
are **not members of any agency**. This matters because:

- they must be able to move between agencies without cluttering each
  agency's own data,
- they must never appear in an agency's user list, property agent picker,
  commission table, branch assignment, etc.,
- they must never be assignable as a property agent, deal participant, or
  contact creator-for-agency — those are agency-member responsibilities.

### How it's enforced

1. **Role flag.** System Owner users have a role with `is_owner = true`
   on the `roles` table. `User::isOwnerRole()` and `User::ownerRoleNames()`
   are the canonical checks.
2. **Null tenancy columns.** Owners carry `agency_id = NULL` and
   `branch_id = NULL`. The one-off migration
   `2026_04_14_110000_detach_system_owners_from_agencies` nulls these on
   every existing owner account and is intentionally non-reversible.
3. **Read-side filter.** Every query that builds an "agency users list"
   must call `User::scopeAgencyMembers()`. Applied in: PropertyController
   agent picker, ContactController agent filter, DealV2Controller, Role
   Manager, User Management, Branch Assignments, Commission principal
   dashboard, Agent Compliance, email signature picker.
4. **Write-side guard.** `PropertyObserver::saving()` rejects any attempt
   to set `agent_id` to an owner. Extend the same pattern to any future
   pillar model that introduces an agent/user FK.
5. **AgencyScope compatibility.** Owners without an active switcher
   override bypass the global scope entirely, so they see every agency's
   data for platform-level operations. Once they switch into a specific
   agency, they are scoped like any member.
6. **Sidebar separation.** The sidebar renders a dedicated "Platform
   Admin" section (Agency Management, Company Settings) visible only to
   owners, above the regular Admin block.
7. **Impersonation guard.** `ImpersonateController::start()` rejects any
   attempt by a non-owner caller to impersonate an owner-role target —
   otherwise an `admin` with `impersonate_users` could `Auth::login()` as
   Andre/Johan and inherit platform-wide access (privilege escalation).
   The sidebar impersonation picker (`corex-sidebar.blade.php`) also
   filters out owner-role users via `ownerRoleNames()` so they don't
   appear as targets in the first place. The "View As" controller
   (`ViewAsController`) already validates `Rule::in(Role::where('is_owner', false))`
   on its `role` input — same pattern applied to actual login swaps.

### Rules when adding new features

- Any new "list users of this agency" query must call
  `->agencyMembers()`. If you find yourself reaching for `User::where`
  directly for a UI picker, stop and ask why.
- Any new FK that points at `users.id` and represents ownership
  (property agent, deal participant, mandate signatory, commission
  recipient) needs a saving-time guard that rejects owner-role users.
- If you legitimately need to target owners (audit tooling, impersonation
  picker, super-admin activity logs), query `User::query()` without
  the scope — don't invent a second scope.

---

## Known limitations / follow-up

- A handful of secondary tables (`prospecting_listings`, `fica_submissions`,
  `commission_ledgers`, `training_courses`, `agent_applications`,
  `web_packs`, `docuperfect_field_groups`, etc.) already had `agency_id`
  and manual filtering before this fix. They are candidates to also adopt
  `BelongsToAgency` for defense in depth. Track in `ROADMAP.md`.
- Cross-agency reporting for owners (platform KPIs) currently requires
  `queryWithoutAgencyScope()`. Any new reporting query that needs this
  must be code-reviewed specifically for leak safety.

---

## Permissions sync after deploy

When new permission keys are added to `config/corex-permissions.php`,
`SyncPermissions` (`php artisan corex:sync-permissions`) upserts them
into `nexus_permissions` (the catalog). It does **not** automatically
grant them to existing roles — `role_permissions` rows are the source
of truth once the system is past first install.

Operational rules:

- **First install only:** `--seed-defaults` wipes `role_permissions` and
  re-seeds from the config. Never run on a live database — it destroys
  Role Manager customisations.
- **After every deploy that adds permissions:** run
  `--merge-defaults`. This inserts any config-defined defaults that the
  role does not yet have, without touching existing rows or scope
  customisations. Idempotent and safe to re-run. Owner-flagged roles
  are skipped (they bypass permission checks). Custom roles created via
  Role Manager (no entry in `role_defaults`) are also skipped — leave
  them under the operator's control.
- **Without either flag:** the command prints any new keys not yet
  granted to any role. Use this as a sanity check before merging.

Without `--merge-defaults` in the deploy pipeline, every new permission
key silently locks the corresponding feature for every non-owner role
until someone hand-ticks each box in Role Manager. This is what caused
the April 2026 "admin can't open My Portal / Command Center" incident.
