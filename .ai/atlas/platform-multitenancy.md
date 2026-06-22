# Atlas — Platform Foundations (Multi-tenancy · Branch isolation · Domain events · Soft-delete · Audit)

> **Status: DONE** · Last verified: 2026-06-22
> The cross-cutting spine **every** feature depends on. Companion specs: `.ai/specs/multi-tenancy.md`,
> `.ai/specs/branch-isolation-spec.md`, `.ai/specs/corex-domain-events-spec.md`. Non-negotiables: CLAUDE.md
> #1 (no hard deletes), #7 (multi-tenancy), #9 (domain events).

---

## 1. WHAT IT DOES

Four foundations enforce CoreX's correctness: **AgencyScope** (tenant isolation), **BranchScope** (branch
isolation under `split_branches_enabled`), the **domain-event spine** (`domain_event_log` + the wildcard
audit listener), and **soft-delete-everywhere** + the **audit observer**. Every pillar's reads and writes
pass through these.

---

## 2. AGENCY MULTI-TENANCY

- **Global scope `app/Models/Scopes/AgencyScope.php`:** re-entry guard `:27,39`; bails if no auth user
  `:56-59`; owner bypass when no switcher override `:66-80`; resolves `effectiveAgencyId()` `:82`; **strict
  filter `WHERE agency_id = :effective`** `:96-113`; **NULL `agency_id` = ORPHAN, filtered out (NOT
  "shared")** `:97-101`; self-row carve-out for `User` `:110-112`.
- **Trait `app/Models/Concerns/BelongsToAgency.php`:** registers scope `:28-30`; **auto-stamps on `creating`**
  — force-sets `agency_id` to `effectiveAgencyId()` for scoped users (can't spoof tenant) `:34-48`; single-
  agency dev fallback `:60-75`; escape hatch `newQueryWithoutAgencyScope()` `:117-125`. **208 models use it.**
- **`effectiveAgencyId()` — `User.php:332-351`:** session `active_agency_id` override → `effectiveBranchId()`
  → `Branch::agency_id` → `users.agency_id`. `effectiveBranchId()` honours `view_as_branch_id` `:322-330`.
- Login/Logout wipe stale `active_agency_id` (`AppServiceProvider.php:507-512`).

---

## 3. BRANCH ISOLATION

- **`BranchScope.php`** mirrors AgencyScope one level down: gates on auth `:64`, owner bypass `:73`, resolve
  agency `:81`, **`split_branches_enabled` gate `:89-91`**, **`branches.view_all` bypass `:96-98`**, then
  `WHERE branch_id = effective` with NULL-branch-under-Split-ON → `whereRaw('1=0')` (sees nothing) `:104-111`.
  `splitBranchesEnabled()` reads the agency column unscoped + caches `:128-141`.
- **`BelongsToBranch.php`:** `addGlobalScope(BranchScope)` `:33`; auto-fills `branch_id` on creating only when
  blank `:35-52`. **20 models use it.**
- **`split_branches_enabled`** — agency toggle controlling isolation; read in scopes, `RequiresBranchAssignment`
  middleware, and `PermissionService::getDataScope`.
- **Data-scope (all/branch/own)** — `PermissionService::getDataScope:59-105`: owner→`all`; for properties/
  contacts an "ON" toggle resolves to `branch` when split else `all` `:90-102`. `ContactScope` consumes it.
- **Branch switcher** `BranchSwitcherController` (req `branches.switch`; branch must be in caller's agency;
  without `branches.view_all` only own branch) writes `view_as_branch_id` to session `:48`. Routes `web.php:848-849`.
- **Deal multi-branch** `DealBranchScope` filters via the `deal_branches` pivot, not a direct column.

---

## 4. THE DOMAIN-EVENT SPINE

- **Table `domain_event_log`** (`2026_05_13_140001`): `event_id` (unique), `trace_id`, `event_name`,
  `agency_id`, `actor_user_id`, polymorphic `subject`, `payload_snapshot`, `context`, `occurred_at`. No FKs,
  not soft-deleted (audit table).
- **Base class `AbstractDomainEvent.php`:** readonly eventId/occurredAt/traceId `:45-54`; `agencyId()`/
  `actorUserId()`/`subject()` hooks `:85-112`; `payloadSnapshot()` reflects public props `:124-152`.
- **Wildcard audit listener `Listeners/Audit/RecordDomainEvent.php`:** kill-switch
  `config('corex.domain_events.audit_enabled')` `:33`; `insertOrIgnore` into `domain_event_log` `:49-61`;
  swallows Throwables so audit never breaks a request `:62-70`.
- **Wiring (no EventServiceProvider — all in `AppServiceProvider`):** `Event::listen(DomainEvent::class,
  RecordDomainEvent::class)` `:172` (every `AbstractDomainEvent` subclass is audited transitively); pillar
  log-listener map `:367-411`; observers `:120-133`. Events fired e.g. `PropertyCaptured`
  (`PropertyObserver.php:148`), `ContactLinkedToProperty` (`PropertyController.php:658,689,716`). Catalogue
  under `app/Events/` (Property, Contact, Mandate, Deal, Prospecting, SellerOutreach, Fica, Document, …).

---

## 5. SOFT-DELETE-EVERYWHERE + THE AUDIT OBSERVER

- **Rule (CLAUDE.md #1):** **238 models use `SoftDeletes`.** Registry
  `Admin/SoftDeleteRegistryService.php` reflection-discovers every soft-deletable model, flags
  `agency_scoped` `:189-234`, is **restore-only — no force-delete path** (`restore()` writes a
  `SoftDeleteRestoration` audit row `:119-142`). Admin UI `Admin/SoftDeleteController` (`admin.soft-deletes.index`
  `web.php:244`, perm `access_soft_deletes`). **Only hard-delete path:** console-only confirmation-gated
  `PurgeSoftDeleted` (`db:purge-soft-deleted`).
- **Audit observer:** `PropertyAuditService.log:11-36` writes a `PropertyAuditLog` row (old/new/metadata);
  invoked from `PropertyObserver` (`:135,230,266,447` incl. soft-delete `deleted()`). Tables:
  `property_audit_log`, `calendar_event_audit_log`, `signature_audit_log`, `whistleblow_audit_log`,
  `legal_block_audit_log`, finance audit, + the generic `domain_event_log`.

---

## 6. WHAT EVERY FEATURE DEPENDS ON

- **`effectiveAgencyId()`** (`User.php:332-351`) — the lynchpin for both the AgencyScope read filter and the
  BelongsToAgency write stamp.
- **Permission system** `PermissionService`: `getDataScope:59`, `userHasPermission:116` (owner bypass `:119`,
  unseeded-allow-all `:131-137`), per-request caches. Catalogue `config/corex-permissions.php` (338 entries).
- **User/role model** `User.php`: `effectiveRole()` honours `view_as_role` `:254-258`; `isOwnerRole:495`,
  `isEffectiveOwner:505`. **Branch model** `Branch.php`; `effectiveBranchId:322-330`.

---

## 7. KNOWN FRAGILITIES (cross-cutting — these affect every feature)

1. **The `agency_id`-null class of bug.** `BelongsToAgency` only force-stamps under `Auth::user()`
   (`:34`). In **cron/observer/queue contexts there is no Auth user**, so it falls to the single-agency
   fallback (`:60-75`) which returns 0 in multi-agency prod → `agency_id` NULL → orphan, invisible to all
   tenants (`AgencyScope.php:97-101`). This is the **AT-72 class** (fixed in `BuyerStateService` by explicit
   `agency_id ?? 1`, see `buyer-pipeline.md` §9.1). Any feature writing in a non-request context is suspect.
2. **`withoutGlobalScope` volume.** CLAUDE.md #7 forbids `withoutGlobalScope(AgencyScope::class)` in request
   code, yet there are **~223 `withoutGlobalScope` occurrences across `app/Http/Controllers/`** (30+ files).
   Many are defensible (auth, public onboarding, owner cross-agency tooling, reading the split toggle,
   impersonation stop), but each is a potential leak surface needing individual review (`multi-tenancy.md:136-145`).
3. **`DB::table()` writes bypass observers + scopes + audit.** Any `DB::table(...)->update()/insert()` skips
   Eloquent events — so `PropertyObserver`/`AgencyScope`/`BranchScope`/`PropertyAuditService` never run.
   Present even inside the observer (`PropertyObserver.php:28,115`) and the audit service
   (`PropertyAuditService.php:74`). `updateQuietly()` (e.g. `BuyerStateService:171`) intentionally suppresses
   observers. These writes are unaudited and untenanted by construction — the
   `PropertyCmaPropagationService` write flagged in `properties.md`/`presentations.md` is exactly this class.
4. **View-As vs Switch-User visibility gotcha** (STANDARDS.md Known Limitations). **View-As**
   (`ViewAsController`) sets `view_as_role`+`view_as_branch_id` without swapping the auth user — and
   `PermissionService` keeps the owner's REAL-role full breadth (`getDataScope` returns `all` for an owner
   even under View-As, `:62`). So an owner "viewing as agent" still has owner data breadth; the role narrows
   the UI but not the underlying scope, and `effectiveBranchId()` *does* honour `view_as_branch_id` — an easy
   mismatch to misread. **Switch-User** (`ImpersonateController`) does a full `Auth::login($user)` so all
   scopes resolve correctly. **Rule: test visibility-scoped features with Switch-User, never View-As.**
5. **Events that escape the audit spine.** The wildcard auditor only catches `AbstractDomainEvent` subclasses
   (`AppServiceProvider.php:172`). **`app/Events/PresentationGenerated.php` does NOT extend
   `AbstractDomainEvent`** (only `Dispatchable, SerializesModels`), so it writes **no `domain_event_log`
   row** despite being framed as a pillar domain event (dispatched `PresentationGeneratorService.php:348`).
   Any legacy event off the base class silently bypasses the audit trail — a gap to track when relying on
   `domain_event_log` for completeness.
6. **`PropertyAuditService` agency default-to-1.** `log()` falls back to `agency_id => ... ?? 1` (`:26`);
   on a multi-agency system a property with a null `agency_id` (the §7.1 orphan case) mis-attributes its
   audit rows to agency 1 — combining fragilities 1 and 5.

---

## Key file:line index
- `app/Models/Scopes/AgencyScope.php:27-113`, `BranchScope.php:37-150`, `DealBranchScope.php`.
- `app/Models/Concerns/BelongsToAgency.php:28-125`, `BelongsToBranch.php:33-74`.
- `app/Models/User.php:322-351` (effective agency/branch), `:495-554` (owner roles).
- `app/Services/PermissionService.php:59-137`; `config/corex-permissions.php`.
- `app/Events/AbstractDomainEvent.php`; `app/Listeners/Audit/RecordDomainEvent.php`; `app/Providers/AppServiceProvider.php:172,367-411,507-512`.
- `app/Services/Admin/SoftDeleteRegistryService.php:47-234`; `app/Services/Audit/PropertyAuditService.php:11-97`; `app/Observers/PropertyObserver.php`.
- Specs `.ai/specs/multi-tenancy.md`, `.ai/specs/branch-isolation-spec.md`, `.ai/specs/corex-domain-events-spec.md`.
