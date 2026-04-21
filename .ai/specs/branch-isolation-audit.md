# Branch Isolation — Codebase Audit

> Status: **Investigation only.** Not a build spec. Johan writes the build spec from this.
> Authored: 2026-04-21
> Scope: Identify everything a future `branch_id` partition (Split Branches toggle) would have to touch.
> Reference pattern: `.ai/specs/multi-tenancy.md` (the agency isolation spec this will mirror).

---

## Executive summary

- Branch tables, FKs, pivot tables, and a settings store all **exist already**. Branches are a first-class concept; they are just not currently used as an isolation boundary.
- `User::effectiveBranchId()` is already implemented at [app/Models/User.php:162-170](app/Models/User.php#L162-L170), and the `view_as_branch_id` session override is wired up. So the "current user's branch" is a solved problem.
- No `BranchScope` exists. No `BelongsToBranch` trait exists. No `AgencyScope`-equivalent mechanism for branch filtering.
- Of the 17 models that use `BelongsToAgency`, only **3** currently carry `branch_id` (Property, CommandTask, Presentation) and **1 more** carries it under a different name (User). Several pillar tables (`contacts`, `deals`, `documents`, `fica_submissions`, `training_courses`, `web_packs`, `prospecting_listings`, `docuperfect_field_groups`) have `agency_id` but **no `branch_id` column at all**.
- `agencies` has **no generic settings store** (no JSON column, no `agency_settings` key/value table). Settings are flat columns (`is_active`, `dashboard_settings_mode`, `p24_agency_id`, etc.). The `branches` table uses a separate `branch_settings` key/value table. A `split_branches_enabled` column on `agencies` follows the existing `dashboard_settings_mode` precedent and is the lowest-friction home for the toggle.
- "Branch Manager" role already exists as a first-class role with scope `branch`. It is an active role with ~60 seeded permissions. It is not yet used as an isolation boundary, only as a permission scope.
- The hardest surface is **not** the migrations — it is the ~280 occurrences of explicit `agency_id` filtering spread across ~40 controllers. A `BranchScope` that mirrors `AgencyScope` eliminates most of them by construction; what remains is raw SQL and queue/import code.

---

## 1. Database Schema Audit

### 1.1 `branches` table

**Exists.** Created by [database/migrations/2026_01_15_073058_create_branches_table.php](database/migrations/2026_01_15_073058_create_branches_table.php). Columns accumulated across multiple migrations:

| Column | Type | Nullable | Default | Source migration |
|--------|------|----------|---------|------------------|
| `id` | bigint PK | — | auto | 2026_01_15_073058 |
| `agency_id` | FK → `agencies.id` (nullOnDelete) | yes | null | 2026_02_25_100002 |
| `name` | string | no | — | 2026_01_15_073058 |
| `code` | string | yes | null | 2026_01_15_073058 |
| `trading_name` | string | yes | null | 2026_03_09_133314 |
| `tagline` | string | yes | null | 2026_03_09_133314 |
| `address` | string | yes | null | 2026_03_09_133314 |
| `phone` | string | yes | null | 2026_03_09_133314 |
| `phone_label` | string | yes | null | 2026_03_09_145301 |
| `phone_secondary` | string | yes | null | 2026_03_09_141826 |
| `phone_secondary_label` | string | yes | null | 2026_03_09_145301 |
| `fax` | string | yes | null | 2026_03_09_133314 |
| `email` | string | yes | null | 2026_03_09_133314 |
| `reg_no` | string | yes | null | 2026_03_09_133314 |
| `vat_no` | string | yes | null | 2026_03_09_133314 |
| `ffc_no` | string | yes | null | 2026_03_09_133314 |
| `fic_no` | string | yes | null | 2026_03_09_133314 |
| `logo_path` | string | yes | null | 2026_03_09_133314 |
| `p24_agency_id` | string | yes | null | 2026_04_18_120000 |
| `created_at` / `updated_at` | timestamps | yes | null | 2026_01_15_073058 |
| `deleted_at` | softDeletes (implied via `Branch` model's `SoftDeletes` trait) | — | — | not yet in a migration — see note below |

**Index/FK notes:**
- `agency_id` is `foreignId()->constrained('agencies')->nullOnDelete()` — auto-indexed by Laravel.
- No explicit index on `name` or `code`.
- ⚠ `Branch` model uses `SoftDeletes` trait ([app/Models/Branch.php:13](app/Models/Branch.php#L13)) but **no migration ever added `deleted_at`** to the `branches` table. This is a latent bug that will surface if you soft-delete a branch today (Eloquent will write `UPDATE deleted_at` on a column that doesn't exist). Worth flagging but orthogonal to this audit.

### 1.2 `users.branch_id`

**Exists.** Added by [database/migrations/2026_01_15_073159_add_role_and_branch_to_users_table.php](database/migrations/2026_01_15_073159_add_role_and_branch_to_users_table.php#L13):

```php
$table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete()->after('role');
```

- Type: `bigint`, nullable.
- FK: `branches.id`, nullOnDelete.
- Index: automatic (via `foreignId`/`constrained`).

Ancillary: [database/migrations/2026_02_14_132146_add_counts_for_branch_split_to_users.php](database/migrations/2026_02_14_132146_add_counts_for_branch_split_to_users.php) adds `counts_for_branch_split` (boolean, default true) — used by the performance/worksheet engine, unrelated to isolation.

There is also [database/migrations/2026_01_15_083757_create_branch_assignments_table.php](database/migrations/2026_01_15_083757_create_branch_assignments_table.php) — a `branch_assignments` pivot (`user_id`, `branch_id`, unique on `user_id`) that predates the `users.branch_id` column being authoritative. **Assumed legacy; needs confirmation it is still read anywhere.**

### 1.3 Per-table `branch_id` / `agency_id` status

Legend: `Y` = column exists, `—` = not present, `N/F` = table not found in migrations.

| Table | `agency_id` | `branch_id` | Notes |
|-------|:-----------:|:-----------:|-------|
| `contacts` | Y (2026_04_14_100000) | — | agency_id added in the multi-tenancy backfill |
| `properties` | Y (creation) | Y (creation) | both nullable, both FK |
| `listings` | N/F | N/F | No such table — "listings" is just `properties` + syndication columns |
| `deals` | Y (2026_04_14_100000) | Y (2026_01_15_113405) | both nullable |
| `deals_v2` | — | Y (creation) | branch_id **NOT NULL**, no agency_id |
| `mandates` | N/F | N/F | No such table — mandate state lives on `properties`/`deals` |
| `tasks` | N/F | N/F | Replaced by `command_tasks` |
| `command_tasks` | Y (creation) | Y (creation) | both nullable |
| `notes` | N/F | N/F | Split into `contact_notes` / `property_notes` |
| `documents` | Y (2026_04_14_100000) | — | unified documents table, no branch_id |
| `document_templates` | N/F | N/F | Replaced by `docuperfect_templates` |
| `docuperfect_templates` | — | — (but pivot `docuperfect_template_branches` exists) | branch visibility via pivot |
| `docuperfect_documents` | — | Y (creation) | nullable FK |
| `docuperfect_field_groups` | Y (creation, NOT NULL) | — | |
| `docuperfect_template_branches` | — | Y (pivot) | many-to-many template↔branch |
| `docuperfect_clause_branches` | — | Y (pivot) | many-to-many clause↔branch |
| `docuperfect_pack_branches` | — | Y (pivot) | many-to-many pack↔branch |
| `signatures` | — | — | |
| `signature_templates` | — | — | |
| `signature_requests` | — | — | |
| `esign_envelopes` | N/F | N/F | Flow is `signature_templates` + `signature_requests` |
| `esign_signers` | N/F | N/F | See `signature_requests` |
| `pipelines` | N/F | N/F | See `deal_pipeline_templates` |
| `deal_pipeline_templates` | — | Y (creation, nullable) | |
| `pipeline_stages` | N/F | N/F | See `deal_pipeline_steps` |
| `deal_pipeline_steps` | — | — | inherits scope from its template |
| `activities` | N/F | N/F | See `daily_activities` / `calendar_events` |
| `appointments` | N/F | N/F | `fica_officer_appointments` exists but not user-facing |
| `calendar_events` | Y (creation, nullable) | Y (creation, nullable) | |
| `viewings` | N/F | N/F | Presumed handled via `calendar_events.event_type` |
| `offers` | N/F | N/F | No standalone offers table |
| `commissions` | N/F | N/F | Split across `commission_ledger`, `revenue_share_ledger` |
| `deal_money_lines` | — | Y (creation, indexed) | no FK constraint, just an index |
| `revenue_shares` | N/F | N/F | See `revenue_share_ledger` |
| `training_courses` | Y (creation, NOT NULL) | — | |
| `training_enrolments` | N/F | N/F | Split into `training_progress` / `training_completions` |
| `compliance_records` | N/F | N/F | Compliance = FICA tables + RMCP tables |
| `fica_records` | N/F | N/F | See `fica_submissions` |
| `fica_submissions` | Y (creation, NOT NULL) | — | |
| `fica_officer_appointments` | Y (creation, NOT NULL) | Y (creation, nullable) | |
| `agent_applications` | Y (referenced by OnboardingController) | — | migration file not confirmed in this audit — flag for verification |
| `fault_reports` | — | — | unscoped; global |
| `kb_documents` | N/F | N/F | See `knowledge_documents` |
| `knowledge_documents` | — | — | currently global |
| `ellie_conversations` | N/F | N/F | see `ai_conversations` |
| `ai_conversations` | — | — | scoped per-user via `scopeVisibleTo` |
| `audit_logs` | N/F | N/F | No generic audit log table |
| `portal_capture_listings` | N/F | N/F | See `portal_captures` / `portal_listings` |
| `portal_listings` | — | — | |
| `portal_captures` | — | — | |
| `presentations` | Y (2026_04_14_100000) | Y (creation, NOT NULL) | branch is mandatory on create |
| `presentation_versions` | — | — | inherits from presentation |
| `prospecting_listings` | Y (creation, NOT NULL) | — | |
| `web_packs` | Y (creation, NOT NULL) | — | |

### 1.4 Agency settings mechanism

**No generic settings store exists on the agency side.** The `agencies` table has flat columns only. Full column list from [app/Models/Agency.php:16-44](app/Models/Agency.php#L16-L44):

```
name, slug, trading_name, tagline, address, phone, phone_label,
phone_secondary, phone_secondary_label, fax, email, reg_no, vat_no,
ffc_no, fic_no, sidebar_color, icon_color, default_color, button_color,
logo_path, email_disclaimer, popi_url, is_active, dashboard_settings_mode,
p24_agency_id, p24_agency_label
```

Precedent for an agency-level toggle: `dashboard_settings_mode` — a string column added by [database/migrations/2026_03_31_400001_create_user_dashboard_settings_table.php:50-54](database/migrations/2026_03_31_400001_create_user_dashboard_settings_table.php#L50-L54) holding `'user' | 'agency'`. That is the established pattern for a new agency-wide switch.

Separate `agency_dashboard_settings` table exists for the dashboard feature (per-agency settings when mode = 'agency'). This table is for dashboard settings specifically, not a generic agency settings store. There is no `agency_settings` key/value table equivalent to `branch_settings`.

**Implication for Split Branches:** the toggle fits naturally as a new column on `agencies` (e.g. `split_branches_enabled boolean default false`). No generic settings infrastructure needs to be designed. If Johan wants a broader settings-as-JSON store in future, that is a separate decision.

### 1.5 `branch_settings` table

Exists: [database/migrations/2026_02_09_091604_create_branch_settings_table.php](database/migrations/2026_02_09_091604_create_branch_settings_table.php).

```
id (PK)
branch_id → branches.id (cascadeOnDelete)
key (string)
value (string, nullable)
unique(branch_id, key)
timestamps
```

String-valued key/value store. Used by [app/Http/Controllers/Admin/BranchAssignmentController.php](app/Http/Controllers/Admin/BranchAssignmentController.php) for per-branch contact detail overrides. **Not** a good fit for the Split Branches toggle (wrong side of the relationship — the toggle is per-agency, not per-branch).

### 1.6 All branch-related migrations

18 migration files in [database/migrations/](database/migrations/) contain "branch":

```
2026_01_15_073058_create_branches_table.php                     — base branches table
2026_01_15_073159_add_role_and_branch_to_users_table.php        — users.branch_id + users.role
2026_01_15_083757_create_branch_assignments_table.php           — pivot users↔branches (legacy?)
2026_01_20_123535_create_branch_activity_columns_table.php      — activity metric config per branch
2026_01_23_115447_add_points_weight_to_branch_activity_columns_table.php
2026_01_24_150419_add_branch_budget_to_monthly_target_goals_table.php
2026_02_09_091604_create_branch_settings_table.php              — branch KV store
2026_02_14_132146_add_counts_for_branch_split_to_users.php      — worksheet split flag
2026_02_24_400002_create_docuperfect_template_branches_table.php — template↔branch pivot
2026_02_24_400005_create_docuperfect_clause_branches_table.php  — clause↔branch pivot
2026_02_24_400010_create_docuperfect_pack_branches_table.php    — pack↔branch pivot
2026_02_25_100002_add_agency_id_to_branches.php                 — branches → agency FK
2026_02_25_800000_make_tv_access_codes_branch_id_nullable.php
2026_03_09_133314_add_contact_details_to_branches_table.php
2026_03_09_141826_add_phone_secondary_to_agencies_and_branches_table.php
2026_03_09_145301_add_phone_labels_to_agencies_and_branches_table.php
2026_04_18_120000_add_p24_agency_id_to_agencies_and_branches.php
```

---

## 2. Model & Global Scope Audit

### 2.1 `Branch` model

File: [app/Models/Branch.php](app/Models/Branch.php).

- Uses `SoftDeletes` and `BelongsToAgency` (lines 11-13).
- Fillable: `name, code, agency_id, trading_name, tagline, address, phone, phone_label, phone_secondary, phone_secondary_label, fax, email, reg_no, vat_no, ffc_no, fic_no, logo_path, p24_agency_id` (lines 15-34).
- No casts.
- Relationships:
  - `agency(): BelongsTo` → `Agency` (line 58)
  - `users(): HasMany` → `User` (line 63)
- Helper methods:
  - `contactDetail(string $field): ?string` — branch value with agency fallback (line 40)
  - `resolveP24AgencyId(): ?string` — branch P24 ID or agency P24 ID fallback (line 49)
- No existing scopes (no `scopeVisibleTo`, no `scopeAgencyMembers`-equivalent).
- No `settings()` relationship to the `branch_settings` table — settings are accessed via the pivot table directly from `BranchAssignmentController`.

### 2.2 User ↔ Branch relationship

**Present.** [app/Models/User.php:112-115](app/Models/User.php#L112-L115):

```php
public function branch(): BelongsTo
{
    return $this->belongsTo(Branch::class);
}
```

Plus, `effectiveBranchId()` at [app/Models/User.php:162-170](app/Models/User.php#L162-L170) returns the session `view_as_branch_id` override if set, else `branch_id`. This is the exact parallel of `effectiveAgencyId()` and is the method a `BranchScope` would call.

### 2.3 `app/Models/Scopes/`

Only one file:

- [app/Models/Scopes/AgencyScope.php](app/Models/Scopes/AgencyScope.php) — the reference pattern a `BranchScope` will mirror.

**No other scope classes exist.**

### 2.4 `AgencyScope::apply()` verbatim

From [app/Models/Scopes/AgencyScope.php:36-103](app/Models/Scopes/AgencyScope.php#L36-L103):

```php
public function apply(Builder $builder, Model $model): void
{
    $class = get_class($model);
    if (!empty(self::$applying[$class])) {
        return;
    }

    self::$applying[$class] = true;
    try {
        $this->applyInner($builder, $model);
    } finally {
        unset(self::$applying[$class]);
    }
}

private function applyInner(Builder $builder, Model $model): void
{
    $user = Auth::user();
    if (!$user) {
        return;
    }

    // Super-admin / owner roles see every agency by default. They opt
    // INTO a specific agency via the agency switcher — until they do,
    // we do not scope their queries at all (even if a stale override
    // is sitting in the session from a previous login, the login event
    // listener wipes it).
    if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
        $hasOverride = session('active_agency_id') !== null
            && session('active_agency_id') !== '';
        if (!$hasOverride) {
            return;
        }
    }

    $agencyId = method_exists($user, 'effectiveAgencyId')
        ? $user->effectiveAgencyId()
        : ($user->agency_id ?? null);

    if (!$agencyId) {
        return;
    }

    $table = $model->getTable();
    $column = $table . '.agency_id';
    $keyName = $table . '.' . $model->getKeyName();
    $authId = $user->getKey();
    $isUserModel = $model instanceof \App\Models\User;

    $builder->where(function (Builder $q) use ($column, $agencyId, $keyName, $authId, $isUserModel) {
        // Strict tenancy: rows must carry the current agency_id.
        // Previously we also allowed `agency_id IS NULL` as "shared",
        // but NULL on a tenant table is always an orphan (e.g. a
        // pre-migration row) and treating it as shared made those
        // orphans leak into every agency.
        $q->where($column, $agencyId);

        // The authenticated user must always be able to see their own
        // record. Without this, a stale session agency causes the user
        // provider to lose the logged-in row and immediately log them
        // out on the next request. System Owners legitimately have
        // NULL agency_id — the bypass above already covers them before
        // we reach this clause.
        if ($isUserModel && $authId) {
            $q->orWhere($keyName, $authId);
        }
    });
}
```

Key design decisions a `BranchScope` would inherit:
1. Re-entry guard per model class (the User model scoping itself would recurse).
2. No auth user → no scope (console, jobs, migrations).
3. Owner-role bypass when no session override is set.
4. `agency_id IS NULL` is **NOT** treated as shared — NULL rows are orphans. A branch scope needs a **different** decision here (see open question 3 in §9).
5. Self-preservation clause for `User` model so stale session doesn't log the user out. Branch equivalent needs the same thing.

### 2.5 Every model that uses `BelongsToAgency`

17 models, from the controller-audit grep (grouped for clarity):

**Core pillars:**
- [app/Models/User.php](app/Models/User.php)
- [app/Models/Branch.php](app/Models/Branch.php)
- [app/Models/Property.php](app/Models/Property.php)
- [app/Models/Contact.php](app/Models/Contact.php)
- [app/Models/Deal.php](app/Models/Deal.php)

**Documents / e-sign / presentations:**
- [app/Models/Document.php](app/Models/Document.php)
- [app/Models/UserDocument.php](app/Models/UserDocument.php)
- [app/Models/Presentation.php](app/Models/Presentation.php)

**Compliance:**
- [app/Models/FicaSubmission.php](app/Models/FicaSubmission.php)
- [app/Models/Compliance/RmcpVersion.php](app/Models/Compliance/RmcpVersion.php)
- [app/Models/Compliance/RmcpComplianceOfficer.php](app/Models/Compliance/RmcpComplianceOfficer.php)
- [app/Models/Compliance/RmcpAcknowledgement.php](app/Models/Compliance/RmcpAcknowledgement.php)
- [app/Models/Compliance/EmployeeScreening.php](app/Models/Compliance/EmployeeScreening.php)
- [app/Models/Compliance/FicaOfficerAppointment.php](app/Models/Compliance/FicaOfficerAppointment.php)
- [app/Models/Compliance/AgencyComplianceProvision.php](app/Models/Compliance/AgencyComplianceProvision.php)
- [app/Models/Compliance/UserComplianceOverride.php](app/Models/Compliance/UserComplianceOverride.php)

(The exact count is 16 from that grep; at least one additional model uses the trait per the scope-initialised log — TrainingCourse is an example that should be in this list but needs confirmation. Treat "17" as the order-of-magnitude.)

**No model calls `addGlobalScope(new AgencyScope)` directly** — isolation is always via the trait. A parallel `BelongsToBranch` trait is the natural pattern.

### 2.6 Sanctioned AgencyScope escape hatch usage

Only one occurrence of `withoutGlobalScope(AgencyScope::class)` in the app tree, and it is inside the trait itself ([app/Models/Concerns/BelongsToAgency.php:61](app/Models/Concerns/BelongsToAgency.php#L61)). **Nothing in request code bypasses the scope today.** The multi-tenancy spec rule ("never bypass in request code") is being honoured.

---

## 3. Controller & Query Audit

### 3.1 Aggregate shape

Summing across all patterns (`->where('agency_id',` / `auth()->user()->agency_id` / `Auth::user()->agency_id` / `$user->agency_id` / `$this->agency_id` / `effectiveAgencyId`):

| Directory | Hits |
|-----------|-----:|
| `app/Http/Controllers/Compliance/` | 38 |
| `app/Http/Controllers/Admin/` | 22 |
| `app/Models/` | 21 |
| `app/Http/Controllers/Commission/` | 16 |
| `app/Http/Controllers/Docuperfect/` | 14 |
| `app/Http/Controllers/CoreX/` | 9 |
| `app/Http/Controllers/Api/` | 8 |
| `app/Services/` | 8 |
| `app/Http/Controllers/Training/` | 5 |
| `app/Http/Controllers/Onboarding/` | 5 |
| `app/Http/Controllers/Public/` | 2 |
| `app/Observers/` | 2 |
| `app/Jobs/` | 1 |
| `app/Listeners/` | 0 |
| `app/Policies/` | 0 |
| `app/Livewire/` | 0 (directory may not exist) |
| `app/Filament/` | 0 (directory may not exist) |

**Rough total: ~280 matches across ~40 files.**

### 3.2 Top 25 files by total agency-filter frequency

| # | File | Hits |
|---|------|-----:|
| 1 | [app/Http/Controllers/Admin/ImporterController.php](app/Http/Controllers/Admin/ImporterController.php) | 14 |
| 2 | [app/Http/Controllers/Commission/CommissionController.php](app/Http/Controllers/Commission/CommissionController.php) | 13 |
| 3 | [app/Http/Controllers/ProspectingController.php](app/Http/Controllers/ProspectingController.php) | 11 |
| 4 | [app/Http/Controllers/Compliance/RmcpController.php](app/Http/Controllers/Compliance/RmcpController.php) | 10 |
| 5 | [app/Http/Controllers/Admin/UserManagementController.php](app/Http/Controllers/Admin/UserManagementController.php) | 10 |
| 6 | [app/Models/User.php](app/Models/User.php) | 9 |
| 7 | [app/Http/Controllers/Compliance/RmcpComplianceOfficerController.php](app/Http/Controllers/Compliance/RmcpComplianceOfficerController.php) | 9 |
| 8 | [app/Http/Controllers/Compliance/RmcpAcknowledgementController.php](app/Http/Controllers/Compliance/RmcpAcknowledgementController.php) | 8 |
| 9 | [app/Services/CandidatePractitionerService.php](app/Services/CandidatePractitionerService.php) | 7 |
| 10 | [app/Http/Controllers/Docuperfect/DocumentImporterController.php](app/Http/Controllers/Docuperfect/DocumentImporterController.php) | 7 |
| 11 | [app/Http/Controllers/Compliance/EmployeeScreeningController.php](app/Http/Controllers/Compliance/EmployeeScreeningController.php) | 7 |
| 12 | [app/Http/Controllers/Api/ProspectingApiController.php](app/Http/Controllers/Api/ProspectingApiController.php) | 7 |
| 13 | [app/Http/Controllers/Public/OnboardingPortalController.php](app/Http/Controllers/Public/OnboardingPortalController.php) | 6 |
| 14 | [app/Http/Controllers/Compliance/RmcpDashboardController.php](app/Http/Controllers/Compliance/RmcpDashboardController.php) | 6 |
| 15 | [app/Models/Concerns/BelongsToAgency.php](app/Models/Concerns/BelongsToAgency.php) | 5 |
| 16 | [app/Http/Controllers/Training/TrainingController.php](app/Http/Controllers/Training/TrainingController.php) | 5 |
| 17 | [app/Http/Controllers/Onboarding/OnboardingController.php](app/Http/Controllers/Onboarding/OnboardingController.php) | 5 |
| 18 | [app/Models/Compliance/FicaOfficerAppointment.php](app/Models/Compliance/FicaOfficerAppointment.php) | 4 |
| 19 | [app/Http/Controllers/Docuperfect/FieldGroupController.php](app/Http/Controllers/Docuperfect/FieldGroupController.php) | 4 |
| 20 | [app/Http/Controllers/CoreX/SettingsController.php](app/Http/Controllers/CoreX/SettingsController.php) | 4 |
| 21 | [app/Http/Controllers/CoreX/RoleManagerController.php](app/Http/Controllers/CoreX/RoleManagerController.php) | 4 |
| 22 | [app/Http/Controllers/Compliance/FicaController.php](app/Http/Controllers/Compliance/FicaController.php) | 4 |
| 23 | [app/Http/Controllers/Agent/AgentPortalController.php](app/Http/Controllers/Agent/AgentPortalController.php) | 4 |
| 24 | [app/Services/CommandCenter/AutoEventService.php](app/Services/CommandCenter/AutoEventService.php) | 3 |
| 25 | [app/Models/Scopes/AgencyScope.php](app/Models/Scopes/AgencyScope.php) | 3 |

**Reading:** these are the files most likely to need a parallel `branch_id` filter **when the scope isn't applicable** (dashboards for principals, cross-branch admin views, etc.). Files whose model already carries the `BelongsToAgency` trait will get the branch filter for free if we follow the same pattern.

### 3.3 Raw SQL (`DB::select` / `DB::raw` / `DB::table`) filtering by agency

Only **one** file filters by `agency_id` outside Eloquent:

- [app/Http/Controllers/Admin/AgencyController.php:227](app/Http/Controllers/Admin/AgencyController.php#L227) — agency delete cascade:
  ```php
  $query = DB::table($table)->where('agency_id', $agencyId);
  ```
  Also line 244 in the same block. This is the **only** raw-SQL location that will need a manual `branch_id` filter when deleting an agency if Split Branches is ON (so deleting the agency purges across branches correctly).

Other raw SQL in `AgencyController` (`SHOW TABLES`, etc.) has no agency context and is safe.

### 3.4 API controllers (`app/Http/Controllers/Api/`)

5 files:

- [app/Http/Controllers/Api/CommandCenterApiController.php](app/Http/Controllers/Api/CommandCenterApiController.php) — dashboard + tasks + calendar JSON endpoints for mobile
- [app/Http/Controllers/Api/MobilePropertyController.php](app/Http/Controllers/Api/MobilePropertyController.php) — mobile property CRUD, image upload
- [app/Http/Controllers/Api/NotificationController.php](app/Http/Controllers/Api/NotificationController.php) — in-app notifications (unread / mark-read)
- [app/Http/Controllers/Api/PropertyPullController.php](app/Http/Controllers/Api/PropertyPullController.php) — portal-pull workflow for on-site capture
- [app/Http/Controllers/Api/ProspectingApiController.php](app/Http/Controllers/Api/ProspectingApiController.php) — portal capture & prospecting endpoints (most agency_id usage of the batch)

All five would be affected by branch scoping because they serve agent-facing endpoints. Most already rely on the global scope; the explicit `agency_id` filters in `ProspectingApiController` and `MobilePropertyController` would also need a branch counterpart.

### 3.5 Owner-role "see-all" queries

`User::scopeAgencyMembers()` at [app/Models/User.php](app/Models/User.php) filters out owner-role users from any "users in this agency" list. A similar `scopeBranchMembers()` would probably **not** be needed at the branch level — branch membership is binary (`users.branch_id`) and owners are already excluded by `scopeAgencyMembers()` upstream. Flagging for Johan as a light design question.

---

## 4. UI & Navigation Audit

### 4.1 System Users admin area

**This is where the branch assignment UI attaches.** Confirmed wired end-to-end already.

- Routes ([routes/web.php:115-140](routes/web.php#L115-L140)):
  - `admin.users` (GET `/admin/users`)
  - `admin.users.create` / `admin.users.store`
  - `admin.users.edit` / `admin.users.update`
- Controller: [app/Http/Controllers/Admin/UserManagementController.php](app/Http/Controllers/Admin/UserManagementController.php)
  - `index()` line 22
  - `create()` line 92
  - `edit(User $user)` line 228
  - `update()` line 245
- View: [resources/views/admin/users/create-edit.blade.php](resources/views/admin/users/create-edit.blade.php)

**Branch selector already exists** at [resources/views/admin/users/create-edit.blade.php:155-164](resources/views/admin/users/create-edit.blade.php#L155-L164):

```blade
<label class="block text-xs font-medium mb-1.5">Branch</label>
<select name="branch_id" class="w-full rounded-md px-3 py-2.5 text-sm outline-none">
    <option value="">(no branch)</option>
    @foreach($branchList as $b)
    <option value="{{ $b->id }}" {{ ...selected... }}>{{ $b->name }}</option>
    @endforeach
</select>
```

The field is optional (`(no branch)` is a valid option). This matters for the NULL-branch edge case in §7.

`counts_for_branch_split` checkbox exists separately (line 202-208). **Different concept** — it controls worksheet/split-points calculations, not data visibility.

### 4.2 Agency Settings page

**Exists in two places** — overlap should be resolved before the toggle lands.

1. **CoreX Settings (agency-aware)** — [resources/views/corex/settings.blade.php](resources/views/corex/settings.blade.php)
   - Route: `corex.settings` → GET `/corex/settings` ([routes/web.php:928](routes/web.php#L928))
   - Middleware: `permission:access_settings`, `agency.required`
   - Controller: [app/Http/Controllers/CoreX/SettingsController.php](app/Http/Controllers/CoreX/SettingsController.php) `index()` line 27
   - Tabs ([resources/views/corex/settings.blade.php:39-54](resources/views/corex/settings.blade.php#L39-L54)): `agency`, `user`, `feature`, `system`
   - The "Agency Settings" tab already lists Branch Assignments and Company Settings links (lines 60-96)

2. **Company Settings (owner-only, per-agency identity)** — [resources/views/admin/company-settings/index.blade.php](resources/views/admin/company-settings/index.blade.php)
   - Route: `admin.company-settings` → GET `/admin/company-settings` ([routes/web.php:988-995](routes/web.php#L988-L995))
   - Controller: [app/Http/Controllers/Admin/CompanySettingsController.php](app/Http/Controllers/Admin/CompanySettingsController.php)
   - Editable agency selector (lines 23-34), company identity fields (lines 43-81), contact details (lines 83-100+)

**Recommendation (for Johan's spec, not this audit):** Split Branches toggle belongs on the Agency Settings tab (option 1) because it is a data-visibility toggle, not an identity/branding toggle. Existing `dashboard_settings_mode` is the precedent. Put it next to that.

### 4.3 Sidebar — where a "Branches" management link would fit

[resources/views/layouts/corex-sidebar.blade.php](resources/views/layouts/corex-sidebar.blade.php) has two relevant sections for owners/admins:

**Platform Admin (owner-only)** — lines 789-828:
- Agency Management (line 799)
- Company Settings (line 808)
- P24 Importer (line 816)
- Property Review (line 822)

**Admin (agency-level admins)** — lines 830-951:
- Knowledge Base
- Role Manager
- Training Management (owner/super_admin)
- Onboarding (owner/super_admin)
- Finance Engine
- Fault Reports (owner/super_admin)
- Deal Register V2
- New Deal
- Pipeline Setup
- Settings

**There is no dedicated "Branches" sidebar link today.** Branch management is reached via: Admin → Settings → Agency tab → Branch Assignments. A future "Branches" link would fit naturally in the Admin section — likely above "Role Manager" — but this is a spec decision, not an audit conclusion.

### 4.4 Branch CRUD

Routes exist, mounted under Admin:
- `admin.branch-assignments` GET `/admin/branch-assignments`
- `admin.branch-assignments.update` POST
- `admin.branches.store` POST `/admin/branches`
- `admin.branches.delete` POST `/admin/branches/{branch}/delete`
- `admin.branch-settings.update` POST `/admin/branch-settings/{branch}`

Controller: [app/Http/Controllers/Admin/BranchAssignmentController.php](app/Http/Controllers/Admin/BranchAssignmentController.php) — `index()` 15, `createBranch()` 60, `deleteBranch()` 74, `updateBranchSettings()` 94.

View: [resources/views/admin/branch-assignments/index.blade.php](resources/views/admin/branch-assignments/index.blade.php) (branch contact details form lines 56-150+).

**Implication for Split Branches spec:** there is no dedicated Branches admin page — the create/delete UI piggybacks on Branch Assignments. A Split-Branches build spec should specify whether this stays (it can) or becomes a full CRUD page (probably shouldn't, per KISS).

### 4.5 Agency switcher

- Controller: [app/Http/Controllers/Admin/AgencySwitcherController.php](app/Http/Controllers/Admin/AgencySwitcherController.php) — `switch()` line 21, `clear()` line 35, `selectPage()` line 46, `selectAndRedirect()` line 59.
- Rendered in: [resources/views/layouts/corex-sidebar.blade.php:98-126](resources/views/layouts/corex-sidebar.blade.php#L98-L126) (owner-only dropdown).
- Interstitial: [resources/views/agency/select.blade.php](resources/views/agency/select.blade.php).

**No equivalent branch switcher** exists (unsurprising — agents have one branch). `view_as_branch_id` session override exists but there is no UI to set it. If the spec calls for cross-branch admin views for principals, a branch switcher would be a new build.

---

## 5. Role & Permission Audit

### 5.1 Role mechanism

**Custom system** (not Spatie — Spatie is not in `composer.json`).

- `roles` table ([database/migrations/2026_03_06_000001_create_roles_table.php](database/migrations/2026_03_06_000001_create_roles_table.php)) columns: `id, name (unique), label, description, color, is_owner (bool), can_be_deleted (bool), sort_order, agency_id (nullable FK), timestamps, softDeletes`.
- `role_permissions` table with `permission_key` + optional `scope` column (added [2026_03_05_115116_add_scope_to_role_permissions.php](database/migrations/2026_03_05_115116_add_scope_to_role_permissions.php)).
- `nexus_permissions` — the permission catalogue (the model class was renamed to `CoreXPermission` but the table stayed).
- `users.role` — string column referencing `roles.name` (added 2026_01_15_073159).

[app/Models/Role.php](app/Models/Role.php) key methods: `ownerRole()`, `allRoles()` (cached), `roleNames()`, `isOwnerRole()`.

### 5.2 Seeded roles

From [database/seeders/.../2026_03_06_000002_seed_existing_roles.php](database/migrations/2026_03_06_000002_seed_existing_roles.php):

| name | label | is_owner | can_delete | sort |
|------|-------|:--------:|:----------:|-----:|
| `super_admin` | System Owner | **true** | false | 1 |
| `admin` | Administrator | false | true | 2 |
| `branch_manager` | Branch Manager | false | true | 3 |
| `agent` | Agent | false | true | 4 |
| `viewer` | Viewer | false | true | 5 |

Also referenced: `office_admin` (via [2026_03_06_200001_seed_office_admin_permissions_from_admin.php](database/migrations/2026_03_06_200001_seed_office_admin_permissions_from_admin.php)) — exists as a permission-copy target but is not in the role seed list. **Flag for verification by Johan.**

### 5.3 "Principal" identification

**Not a first-class concept in the codebase.** No `principal_user_id` column, no "principal" role, no `is_principal` flag. The conceptual equivalent today is:
- Platform owner = `roles.is_owner = true` (i.e. `super_admin`)
- Agency-level "principal" = user with role `admin` within that agency

The Split Branches spec needs to decide whether "Principal" is:
(a) a name for the existing `admin` role within an agency, or
(b) a new column on `users` (e.g. `is_principal boolean`), or
(c) a new role called `principal`.

(a) is the lightest lift and matches how the current permission scope `'all'` already works for `admin`.

### 5.4 "Agency admin" identification

Same mechanism: role = `admin`, with permission scope `'all'` per [config/corex-permissions.php:535-541](config/corex-permissions.php#L535-L541).

### 5.5 Branch manager

**Fully implemented and active.** Not a TBD for the *role*, only for the *bypass-branch-scope* question.

- Role exists with `is_owner = false`, scope default `'branch'` ([config/corex-permissions.php:538](config/corex-permissions.php#L538)).
- 60+ permissions granted in config defaults ([config/corex-permissions.php:380-445](config/corex-permissions.php#L380-L445)).
- Dedicated middlewares: [app/Http/Middleware/BranchManagerMiddleware.php](app/Http/Middleware/BranchManagerMiddleware.php), [app/Http/Middleware/AdminOrBranchManager.php](app/Http/Middleware/AdminOrBranchManager.php).
- Dedicated BM controllers: [app/Http/Controllers/BM/WorksheetMarketController.php](app/Http/Controllers/BM/WorksheetMarketController.php), [app/Http/Controllers/BM/PerformanceController.php](app/Http/Controllers/BM/PerformanceController.php).

**39 files reference `branch_manager`.** The name, permission matrix, sidebar gating, and seeded scope `'branch'` all exist today.

The open question is **not** whether the role exists, but:
- Does branch_manager bypass `BranchScope` entirely (see everything in their branch AND everything else in their agency)?
- Or only see their own branch?
- Can a branch_manager be assigned to multiple branches (the `branch_assignments` pivot table suggests this was once contemplated but `users.branch_id` is the single-valued source of truth today)?

### 5.6 Existing permission scope system

[app/Services/PermissionService.php](app/Services/PermissionService.php) `getDataScope(User, module)` returns `'own' | 'branch' | 'all'`. The `'branch'` value already exists and is consumed by `scopeVisibleTo()` on several models. **This predates structural branch isolation** — it is an application-level filter, not a global scope.

Implication: when Split Branches = ON, the new `BranchScope` will **replace** the ad-hoc `'branch'` scope-value logic in models. Expect a consolidation pass.

`shared_scope_modules` list ([config/corex-permissions.php:544](config/corex-permissions.php#L544)): `['p24', 'knowledge']` — modules that are always 'all' regardless of role. A similar "never branch-scope" list will likely be needed (Open Question 4 in §9).

---

## 6. External Integrations & Modules

Format per module: current scoping / branch-aware today / what would need to change.

### A) Private Property syndication (SOAP)

- **Files:** [app/Http/Controllers/PrivateProperty/SyndicationController.php](app/Http/Controllers/PrivateProperty/SyndicationController.php), [app/Services/PrivateProperty/PrivatePropertySyndicationService.php](app/Services/PrivateProperty/PrivatePropertySyndicationService.php), [app/Services/PrivateProperty/PrivatePropertyListingMapper.php](app/Services/PrivateProperty/PrivatePropertyListingMapper.php).
- **Current scoping:** per-property via `Property`. Controller `authorizeProperty()` checks `$property->branch_id` and `$property->agent_id` (line 325-336) — **already branch-aware for authorisation**, but not for feed scoping.
- **Branch-aware today?** Partial — authorisation check only.
- **Writes back:** `properties.pp_*` columns.
- **Split-Branches-ON impact:** Property queries picked up via the global `BranchScope` automatically (Property uses `BelongsToAgency`, would use `BelongsToBranch`). Agent-to-PP registration (currently a single agency-level agent pool) may need to split per branch if the spec says branches should publish under different agent registrations — but PP's SOAP `Agency` identifier is one-per-PP-account, so this is a business question, not a code question. Flag as open.

### B) Property24 syndication

- **Files:** [app/Http/Controllers/Property24/P24SyndicationController.php](app/Http/Controllers/Property24/P24SyndicationController.php), [app/Services/Syndication/Property24/Property24SyndicationService.php](app/Services/Syndication/Property24/Property24SyndicationService.php).
- **Current scoping:** per-property + per-branch for the P24 agency ID resolution.
- **Branch-aware today? Yes.** `$property->resolveP24AgencyId()` ([app/Services/Syndication/Property24/Property24SyndicationService.php:29](app/Services/Syndication/Property24/Property24SyndicationService.php#L29)) reads branch's `p24_agency_id` first, falls back to agency's (`Branch::resolveP24AgencyId()` at [app/Models/Branch.php:49-56](app/Models/Branch.php#L49-L56)). Agent registration (`resolveAgencyIdForUser`, line 513-528) also respects branch.
- **Split-Branches-ON impact:** minimal. The plumbing is branch-aware today. What changes is that the scope makes cross-branch reads impossible, which the current code doesn't rely on anyway.

### C) Portal Capture / Prospecting

- **Files:** [app/Http/Controllers/ProspectingController.php](app/Http/Controllers/ProspectingController.php), [app/Http/Controllers/Api/ProspectingApiController.php](app/Http/Controllers/Api/ProspectingApiController.php), [app/Models/ProspectingListing.php](app/Models/ProspectingListing.php).
- **Current scoping:** strictly agency — `prospecting_listings.agency_id NOT NULL`, no `branch_id` column.
- **Branch-aware today?** No.
- **Writes back:** `prospecting_listings`, `prospecting_claims`, `portal_captures`, `portal_listings`.
- **Split-Branches-ON impact:** needs `branch_id` added to `prospecting_listings` + `prospecting_claims`. Tricky because portal capture is often **cross-branch by nature** — an agent capturing from a public portal may not know which branch the deal belongs to yet. Likely should default to the capturing agent's branch and be re-assignable. Flag for Johan (Open Question 7).

### D) Exclusive mandate breach detection

**Not found in the codebase.** Grep for "exclusive", "mandate_breach", "SoleMandate" only returns:
- PP field name `SoleMandateExclusiveDays` in [app/Services/PrivateProperty/PrivatePropertyListingMapper.php](app/Services/PrivateProperty/PrivatePropertyListingMapper.php) — metadata field sent to PP, no logic.
- `properties.pp_exclusive_days` column — configuration, not detection.

**Status:** there is no exclusive-mandate breach detector in the repo today. Not applicable to this audit.

### E) Ellie AI

- **Files:** [app/Http/Controllers/EllieController.php](app/Http/Controllers/EllieController.php), [app/Models/AiConversation.php](app/Models/AiConversation.php).
- **Current scoping:** per-user. `scopeVisibleTo` filters to `user_id`. Knowledge base (`knowledge_documents`) has **no agency_id, no branch_id** — global today.
- **Branch-aware today?** No.
- **Writes back:** `ai_conversations`, `ai_messages`.
- **Split-Branches-ON impact:** conversations stay per-user (no change). KB articles remain global unless the spec introduces branch-specific KB — if it does, that is a new column + new UI, not a scope tweak. Flag for Johan.

### F) Document engine & e-signature

- **Files:** [app/Http/Controllers/Docuperfect/ESignWizardController.php](app/Http/Controllers/Docuperfect/ESignWizardController.php), [app/Services/Docuperfect/SignatureService.php](app/Services/Docuperfect/SignatureService.php), [app/Models/Docuperfect/Template.php](app/Models/Docuperfect/Template.php).
- **Current scoping:** template visibility per-branch via pivot (`docuperfect_template_branches`), `is_global` flag; documents have nullable `branch_id`.
- **Branch-aware today?** Yes, partially. `Template::scopeVisibleTo()` at [app/Models/Docuperfect/Template.php:94-109](app/Models/Docuperfect/Template.php#L94-L109) already filters `is_global OR pivot match on user's branch`.
- **Writes back:** `docuperfect_templates`, `docuperfect_documents`, `signature_templates`, `signature_requests`.
- **Split-Branches-ON impact:** `docuperfect_documents.branch_id` would need to become **NOT NULL when the toggle is ON**. Template/clause/pack pivot mechanism is already the right design; no schema change. Documents / signatures currently have **no agency_id at all** — this is a pre-existing multi-tenancy gap flagged in `.ai/specs/multi-tenancy.md` under "Known limitations" and should be resolved as part of (or before) branch isolation.

### G) Commission & revenue share engine

- **Files:** [app/Services/Finance/CommissionCalculator.php](app/Services/Finance/CommissionCalculator.php), [app/Services/Finance/RollupService.php](app/Services/Finance/RollupService.php), [app/Http/Controllers/Commission/CommissionController.php](app/Http/Controllers/Commission/CommissionController.php).
- **Current scoping:** per-agent + per-deal; rollup tables exist at agent / branch / company periods ([app/Services/Finance/RollupService.php:20-22](app/Services/Finance/RollupService.php#L20-L22)).
- **Branch-aware today?** Partial. `deal_money_lines.branch_id` exists and is indexed. Rollup types include `branch_period`. Ledger queries don't currently *filter* by branch but the data is there.
- **Writes back:** `deal_money_lines`, `commission_ledger`, `revenue_share_ledger`, `finance_computed_values`.
- **Split-Branches-ON impact:** this is where cross-branch aggregation matters most. Principals will need **unscoped** commission views (for paying PAYE correctly across the whole agency); branch managers + agents get branch-scoped. A `queryWithoutBranchScope()` escape hatch will be used here, exactly as `AgencyScope` is today for owner-level reporting.

### H) Training LMS

- **Files:** [app/Http/Controllers/Training/TrainingController.php](app/Http/Controllers/Training/TrainingController.php), [app/Models/TrainingCourse.php](app/Models/TrainingCourse.php).
- **Current scoping:** per-agency (`training_courses.agency_id NOT NULL`).
- **Branch-aware today?** No.
- **Writes back:** `training_courses`, `training_progress`, `training_completions`.
- **Split-Branches-ON impact:** debatable. Training content is usually agency-wide. Probably belongs on a "never branch-scope" shared list. Flag for Johan (Open Question 4).

### I) FICA / Compliance dashboard

- **Files:** [app/Http/Controllers/Compliance/FicaController.php](app/Http/Controllers/Compliance/FicaController.php) and neighbours, [app/Models/FicaSubmission.php](app/Models/FicaSubmission.php).
- **Current scoping:** per-agency via `BelongsToAgency` on `FicaSubmission`. `fica_officer_appointments` has `agency_id` + nullable `branch_id` — so MLRO is already representable per-branch.
- **Branch-aware today?** Partial (officer appointments only).
- **Writes back:** `fica_submissions`, `fica_officer_appointments`, RMCP tables.
- **Split-Branches-ON impact:** FICA submissions need `branch_id`. Primary CO is agency-wide; MLRO is per-branch (already). Regulatory reporting usually needs agency-wide view regardless — likely the primary CO bypasses branch scope. Flag for Johan.

### J) Agent onboarding & fault reports

- **Files:** [app/Http/Controllers/Onboarding/OnboardingController.php](app/Http/Controllers/Onboarding/OnboardingController.php), [app/Http/Controllers/Public/OnboardingPortalController.php](app/Http/Controllers/Public/OnboardingPortalController.php), `fault_reports` table.
- **Current scoping:** onboarding per-agency; fault reports **unscoped / global**.
- **Branch-aware today?** No.
- **Writes back:** `agent_applications`, `fault_reports`.
- **Split-Branches-ON impact:** onboarding could optionally record which branch the candidate will join. Fault reports is a platform-level feature — probably stays global.

### K) Marketing intelligence centre / tile-based statistics builder

**Not found in the codebase.** No controller/service matching "MarketingIntelligence", "TileStat", "IntelligenceCentre". Either not built yet or named differently. Flag for Johan to clarify — potentially future scope.

### L) Presentations

- **Files:** [app/Http/Controllers/Presentation/PresentationController.php](app/Http/Controllers/Presentation/PresentationController.php), [app/Models/Presentation.php](app/Models/Presentation.php).
- **Current scoping:** `agency_id` (nullable, backfilled) + `branch_id` (NOT NULL from creation).
- **Branch-aware today? Yes, fully.** `authorizePresentation()` at [app/Http/Controllers/Presentation/PresentationController.php:50-67](app/Http/Controllers/Presentation/PresentationController.php#L50-L67) already compares `$presentation->branch_id === $user->effectiveBranchId()`.
- **Writes back:** `presentations` and 10 related tables.
- **Split-Branches-ON impact:** presentations are already the reference example. The `BranchScope` just formalises the existing manual check.

### M) Dashboard / cockpit

- **Files:** [app/Http/Controllers/CommandCenter/DashboardController.php](app/Http/Controllers/CommandCenter/DashboardController.php), [app/Services/CommandCenter/](app/Services/CommandCenter/).
- **Current scoping:** per-agency via `BelongsToAgency` on underlying models; `calendar_events.branch_id` and `command_tasks.branch_id` already exist (both nullable).
- **Branch-aware today?** Weakly — columns exist, queries don't filter.
- **Split-Branches-ON impact:** this is where principals need a **branch filter control** in the UI. Agents get branch-scoped automatically; principals need an explicit "All branches / [branch name]" selector. The `dashboard_settings_mode` pattern on `agencies` is a precedent for user-vs-agency selection but does not cover branch filtering per se.

---

## 7. Edge Cases

Enumerated for Johan to rule on. Every one of these needs a spec answer before build:

1. **User with NULL `branch_id` while Split Branches = ON.**
   Options: (a) lock them out of everything, (b) they see zero tenant data until assigned, (c) they are treated as "agency-wide" and see everything like a principal, (d) force them into a branch on next login. The User form today allows NULL branches (`(no branch)` option in create-edit.blade.php). A flip of the toggle could strand active users.

2. **Legacy rows with NULL `branch_id` after the principal flips OFF → ON.**
   `AgencyScope` treats NULL as orphan (strict tenancy — see [app/Models/Scopes/AgencyScope.php:87-90](app/Models/Scopes/AgencyScope.php#L87-L90)). Should `BranchScope` do the same, or treat NULL as "all branches" (shared) during a grace period? Strict tenancy is safer long-term but requires a backfill. Shared-NULL is lenient but replicates the exact orphan-leak bug the agency scope just fixed.

3. **User transferred Branch A → Branch B mid-deal.**
   Does historical data follow the user (visible to them even after transfer), or stay with Branch A? The obvious rule is "records are owned by the branch at time of creation and do not move." But that means an agent who moves branches loses access to their own active deals. Needs a spec rule — most likely: `deals` / `properties` / `presentations` stay with their branch, but the agent's `users.branch_id` changes only for *future* assignments. Ownership of in-flight deals may need a separate transfer flow.

4. **Cross-branch deals (Branch A buyer on Branch B mandate).**
   V2 Deal model has a single `branch_id` — no second "other side" column. Options: (a) single branch owns the deal; commission splits flow via existing `deal_v2_agents` (which has `user_id`, and each user has their own `branch_id`); (b) introduce a `counterparty_branch_id`; (c) require deals to go through the principal when cross-branch. (a) is the lowest code change but leaves the visible "owning branch" ambiguous to the non-owning side's agents.

5. **Agency-wide resources.**
   Candidates that probably should never branch-scope: company templates (`docuperfect_templates.is_global`), knowledge base (`knowledge_documents`), training (`training_courses`), fault reports (`fault_reports`), RMCP version (single per agency), agency dashboard settings, user roles/permissions, agency branding. These should go into a `shared_scope_models` allowlist mirroring `shared_scope_modules` in the permission config.

6. **Admin impersonation via `ImpersonateController` / `ViewAsController`.**
   When an owner impersonates an agent, does the agent's branch scope apply? Today `view_as_branch_id` session key flows through `effectiveBranchId()`, so yes — the override is already respected. Need to confirm `ImpersonateController::start()` sets this appropriately.

7. **Reports & dashboards aggregation.**
   - Principal dashboard: MUST be unscoped (see all branches' numbers).
   - Branch manager dashboard: scoped to their branch only.
   - Agent dashboard: scoped to their branch (implicit).
   - Cross-branch aggregates (total agency revenue, agency target vs actual): Principal only. Requires `queryWithoutBranchScope()` with owner-equivalent gating — likely gated on a new `branch.bypass_scope` permission rather than the agency-level `is_owner` flag.

8. **OFF → ON flip migration.**
   When the principal toggles Split Branches ON for the first time, what happens to rows where `branch_id IS NULL`? Options:
   (a) automatic bulk assignment to a default branch (which branch? first one alphabetically? smallest? most-recent-creator?),
   (b) a forced mapping wizard before the toggle can flip,
   (c) "grandfather" mode where existing NULL rows are treated as shared for 30 days,
   (d) refuse to flip until principal has manually set `branch_id` on every tenant row.
   (b) is the safest. (a) is the most user-friendly but risks assigning data wrong.

9. **ON → OFF flip.**
   Should this be freely reversible, or one-way? Freely reversible is low-code (data still has branch_id, scope just stops applying). But agents may have used Split-mode to ring-fence sensitive data; flipping OFF suddenly exposes it to the whole agency. Probably reversible, but logged as an auditable event.

10. **Soft-deleted (archived) records.**
    `SoftDeletes` already coexists with `AgencyScope` (the scope only adds `WHERE agency_id`; soft-delete adds `WHERE deleted_at IS NULL`). `BranchScope` would compose the same way. No edge case here except that **archived records keep their `branch_id`**, so an admin recovering them after branch reorganisation may see confusing state. Worth calling out in spec.

11. **Ellie AI answering questions.**
    Conversations are user-private today. When Split Branches = ON, should Ellie's corpus change per asker's branch? KB articles are currently global; property/contact context is user-scoped. Most likely no change — but if the spec introduces branch KB, Ellie's retrieval must respect the branch scope of the user asking.

12. **Cross-agency operations (owner POV).**
    Owners with no `active_agency_id` bypass `AgencyScope` entirely. Does the equivalent apply for `BranchScope`? i.e. does an owner without `active_branch_id` bypass branch scope too? Probably yes — but this introduces a new session key (`active_branch_id`) that needs setting somewhere. Or: owners are always branch-bypassed because they never hold branch assignments.

13. **Branch deletion / merge.**
    Deleting a branch with active records: does it soft-delete records, reassign them, or block deletion? The `branches` table supports `SoftDeletes` in the model but has no `deleted_at` column in any migration — latent bug. Separately, `users.branch_id` uses `nullOnDelete`, so deleting a branch nulls every user's `branch_id` — which while Split Branches = ON immediately strands those users (see edge case 1).

14. **Multi-branch users (e.g. branch manager over two branches).**
    `users.branch_id` is single-valued. The legacy `branch_assignments` table (with `unique(user_id)`) was also single-valued. There is **no** many-to-many mechanism for user↔branch today. If a branch_manager can manage multiple branches, we need either a new pivot or a scope-on-read mechanism.

15. **Records created by SystemOwner (agency_id = NULL, branch_id = NULL).**
    System owners have NULL agency_id. Any seed data, audit-tool output, or system-generated records they create have NULL both columns. These should be excluded from tenant queries (which they already are, under strict-tenancy NULL handling).

16. **Properties with multiple agents from different branches.**
    `properties.agent_id` is single-valued but a deal can have co-listing. Does the *property* always belong to the lister's branch? Current code uses `properties.branch_id` independently of `agent_id`, so yes — branch is a first-class property attribute. Just worth confirming in spec that the lister's branch wins even if the buyer's agent is in another branch.

17. **Pipeline templates created at the branch_manager level under Split=ON.**
    `deal_pipeline_templates.branch_id` is nullable — templates today can be agency-wide (NULL) or branch-specific. Under Split=ON with strict-NULL semantics, NULL templates would become invisible. Either keep the old nullable-as-shared semantics for pipeline templates specifically, or migrate all NULL templates to a per-branch copy.

---

## 8. Proposed Implementation Footprint

**Based on findings above — not a build plan, just scale estimation.**

### Migrations (rough count)

- **New column on `agencies`:** `split_branches_enabled` (bool, default false). 1 migration.
- **New column on `branches`:** `deleted_at` to back the SoftDeletes declaration on the model. 1 migration (latent bug — fix as part of this work).
- **Add `branch_id` to tenant tables that don't have it:**
  - `contacts`, `documents`, `docuperfect_templates` (? — or keep pivot-only), `fica_submissions`, `training_courses` (? — or shared), `prospecting_listings` + `prospecting_claims`, `web_packs` (? — or shared), `docuperfect_field_groups` (? — or shared), `knowledge_documents` (? — or shared), `agent_applications`, `signature_templates`, `signature_requests`, `signatures`, `deal_pipeline_steps` (inherits from template, possibly skip), `deal_v2_agents`/`deal_v2_contacts` (inherits from deal, skip), `revenue_share_ledger`, `commission_ledger`.
  - Unambiguously needed: ~8–10 tables.
  - Johan decides shared-scope list: ~5–7 tables.
- **Backfill migration:** one-off migration populating `branch_id` on existing rows from the creating-user's branch or the linked property's branch.

**Estimate:** 12–18 migrations.

### Models needing `BelongsToBranch`

Every model that currently uses `BelongsToAgency` **and** represents branch-scoped data needs the new trait added. At minimum:
- `Property`, `Contact`, `Deal`, `Document`, `UserDocument`, `Presentation`, `FicaSubmission`, `EmployeeScreening`, `UserComplianceOverride`.
- Plus models that don't use `BelongsToAgency` today but should be branch-scoped under ON: `DealV2`, `CalendarEvent`, `CommandTask`, `ProspectingListing`.
- Plus Docuperfect models (`Template`, `Flow`, `SignatureTemplate`, `SignatureRequest`, `DocuperfectDocument`).

**Estimate:** 12–16 models.

### Controllers needing updates

Most controllers get branch scoping for free via the trait. The ones that need explicit work:
- **Top 25 from §3.2** — audit each for "I'm doing this query outside the scope" (raw SQL, `withoutGlobalScope`, manual aggregates). Expected: ~8 of the 25 need surgical edits.
- **Principal-dashboard controllers** — need an explicit `queryWithoutBranchScope()` or equivalent bypass for aggregate views.
- **Admin/AgencySwitcherController equivalent** for branch switching (if we decide principals get a branch switcher).

**Estimate:** 15–25 controller touches.

### Views needing branch filters / selectors

- User create/edit: branch selector already exists. Make it mandatory when Split=ON.
- Agency Settings tab: new Split Branches toggle. Adjacent to `dashboard_settings_mode`.
- Principal dashboards (command center, commission, performance, daily activity): add "All branches / [branch]" selector.
- Branch assignment form: already exists.
- Property / Deal / Contact / Presentation index: filter buttons for branch (principal only).
- Document library: branch filter (principal only).

**Estimate:** 10–15 view touches.

### Jobs / listeners / observers

- `PropertyObserver` already guards agent_id against owner-role users; a parallel guard may be needed for branch mismatches (e.g. agent in Branch A cannot be assigned as agent on a property in Branch B). Light touch.
- Command-center scheduled commands (`ProcessReminders`, `CalculatePropertyHealth`, etc.) run in console context — they bypass `AgencyScope` naturally. They need to be checked to ensure they don't leak cross-branch when Split=ON. Review only.
- Reminder notifications: need to be scoped by recipient's branch context.

**Estimate:** 5–8 touches.

### Policies

`app/Policies/` has 0 existing agency_id references per §3.1. If policies are introduced as part of this work, they would enforce `user.branch_id === model.branch_id` when Split=ON. But we've been living without them; no need to add them here.

**Estimate:** 0–2 policies.

### Total

**~60–90 files touched. 12–18 migrations. ~2 weeks of build + test for a single developer.** An incremental rollout (pillar tables first, then feature tables, then shared-list decisions) is possible and reduces risk.

---

## 9. Open Questions for Johan

1. **Branch manager scope under Split=ON.** Does a branch_manager see only their branch, or see everything in their agency? If only their branch — does a branch_manager assigned to multiple branches need a new pivot table (`user_branches`), or does the single `users.branch_id` stay the source of truth (with multi-branch being a pro-rata permission scope)?

2. **Cross-branch deals.** When Branch A has the mandate and Branch B brings the buyer: (a) Branch A owns the deal, Branch B's agent is a `deal_v2_agents` row and sees the deal through that link; (b) both branches see the deal via a new `counterparty_branch_id`; (c) cross-branch deals must be routed through the principal?

3. **NULL `branch_id` semantics when Split=ON.** Strict-tenancy (NULL = orphan, invisible) matches `AgencyScope`. Shared (NULL = visible to all branches) matches the existing `deal_pipeline_templates` behaviour. Which?

4. **Shared-scope list.** Which modules should NEVER branch-scope, regardless of Split=ON? Candidates: training content, knowledge base, fault reports, RMCP version, company templates (`is_global=true`), agency dashboard settings, roles/permissions/user-management itself. Confirm the exact list before build.

5. **OFF → ON flip UX.** When principal flips ON for the first time: (a) auto-assign every NULL-branch row to a default branch, (b) force principal through a mapping wizard before the toggle can flip, (c) let it flip and leave NULLs invisible until principal fixes them manually?

6. **Reversibility.** Should Split=ON → Split=OFF be freely reversible? Auditable? Require confirmation + reason?

7. **Portal capture / prospecting.** Portal capture is cross-branch by nature (agent scrapes a portal they don't own). Should captured records default to the capturing agent's branch and be re-assignable by a principal? Or stay agency-wide and only get branched when converted to a mandate?

8. **Private Property SOAP account granularity.** PP has one SOAP Agency per credential. Does each branch publish under its own PP Agency (different credentials per branch), or share the agency-wide one? If shared, PP is effectively branch-blind regardless of our scope.

9. **Principal identification.** Is "principal" just the `admin` role within an agency? Or should we add a dedicated `is_principal` flag / `principal` role? Relevant because "Principal bypasses branch scope" needs a canonical check, and `isOwnerRole()` today returns false for `admin`.

10. **Branch switcher UI.** Does the principal get a branch switcher (session `active_branch_id`) analogous to the agency switcher, so they can "act as" a single branch without leaving their principal role? Or do principal views just always aggregate with a branch filter dropdown?

11. **Docuperfect documents + signatures.** These have no `agency_id` and no `branch_id` (other than `docuperfect_documents.branch_id`). Is a single migration adding both (or introducing isolation via the parent template's scope) acceptable scope creep for this project, or is it a separate ticket?

12. **Multi-agency platform owners + branches.** If an owner switches into Agency A via `active_agency_id` and Agency A has Split=ON, does the owner see all of Agency A's branches, or do they need to pick one? Most likely "all" — but confirm.

---

## Pre-completion checks

1. `git status` — new audit file only (report from Bash follows).
2. `php artisan view:clear` — runs clean (report from Bash follows).
3. `scripts/dev-check.ps1` — full test suite (report from Bash follows).
