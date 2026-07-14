# Assistants — Build Spec

> Spec file: `.ai/specs/assistants-feature-spec.md`
> Status: **DRAFT — awaiting Johan sign-off. No code until §20 is signed.**
> Ticket: **AT-267** (`assistant feature`) — branch `AT-267-assistants`
> Author: Johan (product architect) + Claude (solution design)
> Supersedes: N/A
> Related: [branch-isolation-spec.md](branch-isolation-spec.md), [multi-tenancy.md](multi-tenancy.md), [corex-domain-events-spec.md](corex-domain-events-spec.md), [agency-onboarding-setup.md](agency-onboarding-setup.md)

---

## 1. Purpose

An **Assistant** is a User who works *for* a specific Agent. The Agent decides, from their own
permission set and no wider, what the Assistant may do. Every action the Assistant takes is
recorded as *the Assistant acting on behalf of the Agent*, and every record they create belongs
to the Agent.

The Assistant is not a role and not a new user type. It is a **relationship** — an
`assistant_assignments` row — that changes how permissions and data visibility resolve for that
one user.

The feature exists because agency admin staff already do agent work today under the agent's own
login (shared passwords). That is a FICA/POPIA/PPRA defensibility hole: the audit trail says the
agent did it, and it is not true. Assistants close the hole by giving the person their own login
while keeping the *work* attributable to the sponsoring agent.

---

## 2. Investigation findings that changed the brief

The build brief made four assumptions that the codebase contradicts. Each is resolved below and
each carries a decision for Johan (§20).

### 2.1 "Sponsor" is already a word in this schema — and it means something else

| Existing column | Meaning today | Defined |
|---|---|---|
| `users.sponsored_by_user_id` | **Commission mentor / revenue-share sponsor.** Pairs with `agent_tier` (standard/mentor/team_lead/icon) and `is_mentor_eligible`. | `database/migrations/2026_03_27_300001_add_commission_columns_to_users.php:13` |
| `users.supervised_by` | **PPRA candidate-practitioner supervisor.** Pairs with `signature_templates.is_candidate_flow` + `supervisor_user_id`. | `database/migrations/2026_03_22_184212_add_supervised_by_to_users_table.php:22` |

Neither is the assistant relationship. **No sponsor/supervisor column may be reused, and no new
sponsor column goes on `users`** — the relationship lives on its own table. The word "Sponsor" in
the UI is Johan's call (§20, D1); the schema is unambiguous either way.

### 2.2 The RMCP seeder is not a permission registry

The brief names `database/seeders/HfcRmcpMasterSeeder.php` as the "RMCP source of truth" for new
permission slugs. It is not. **RMCP = Risk Management and Compliance Programme** — the agency's
FIC Act s42 compliance *document* (`RmcpVersion` → `RmcpSection` → `RmcpVariable`; sections are
"Definitions", "Compliance with Section 20A", "Establishment and Verification of Identity"…). It
defines zero permission slugs.

Permissions live in **`config/corex-permissions.php`**, synced by `php artisan
corex:sync-permissions --merge-defaults`, which is already registered in
`deploy:sync-reference-data` (`app/Console/Commands/Deploy/SyncReferenceData.php:42`). §8 of this
spec puts the new permissions there.

There *is* a real RMCP question hiding inside the brief's error, and it is not a naming quibble:
an Assistant handling client identity documents is a person the agency's FIC Act programme should
name. That is a compliance-content change, not a code change — flagged as **D6**.

### 2.3 A permission-slug lock cannot hard-lock property upload

The brief assumes property upload can be locked by denying permission slugs. It cannot. The audit
of every property-creation path found:

- The **live** create gate is `properties.create`, and it is checked in exactly one place — the
  wizard (`app/Http/Controllers/CoreX/PropertyWizardController.php:34` and `:163`).
- `create_properties`, `publish_properties`, `delete_properties`, `properties.archive`,
  `listings.create|edit|archive` are **defined in config and never checked anywhere**. They are
  dead keys that read as if they gate something.
- Several property-creation paths have **no permission key at all**:

| Ungated creation path | Route | Gate today |
|---|---|---|
| Classic property store | `routes/web.php:2803` (`POST /corex/properties`) | group `permission:access_properties` only |
| Wizard photo upload / finalize | `routes/web.php:2821-2826` | data-scope only |
| Mobile API property create + image upload | `routes/api.php:365`, `:372` | **none** |
| Portal pull (P24/PP → property) | `routes/api.php:345` | **none** |
| Prospecting import (tracked-property create) | `routes/api.php:341` | **none** |
| P24 bulk importer | `routes/web.php:612` | `owner_only` |
| Sold-properties CSV import | `routes/web.php:2806` | `super_admin` |

So the lock is enforced at **four** layers (§9), not one. This is the brief's own "fix the
bug-class, not the instance" rule applied honestly.

### 2.4 Permissions are not visibility — and the brief only solves permissions

`PermissionService::userHasPermission()` answers *may I do X*. It does **not** answer *whose
records do I see*. That is `PermissionService::getDataScope()` → `'own' | 'branch' | 'all'`, which
23 models consume in `scopeVisibleTo()`, and `'own'` universally resolves to
`where('<actor column>', $user->id)`.

An Assistant granted `contacts.view` with scope `own` would therefore see **zero of the Agent's
contacts** — only contacts they created themselves. The feature would ship inert.

The Assistant must inherit the Agent's *data identity*, not just their permission ceiling. §7
specifies this. It is the single largest piece of work in the build and it is not optional.

---

## 3. Guiding principles

- **The matrix can only ever subtract.** An Assistant's effective permission is the intersection
  of (assignment matrix) ∩ (Agent's live effective permissions) − (locked set). There is no path
  by which an Assistant does something the Agent cannot.
- **Fail closed.** An Assistant with no active assignment, a suspended assignment, a deactivated
  Agent, or a missing matrix row has **no permissions at all** — not "agent defaults". This
  mirrors AT-265's posture (`PermissionService` fails closed on an empty grants table).
- **Ships OFF.** `agencies.assistants_enabled` defaults `false`, exactly as
  `split_branches_enabled` did. Dormant code, no behaviour change for any existing agency.
- **The work belongs to the Agent.** Records an Assistant creates are *owned* by the Agent
  (commission, pipeline, "my listings") and *audited* as created by the Assistant. Both facts are
  stored; neither is inferred.
- **Mirror, don't invent.** `AssistantScope` mirrors `BranchScope`. The matrix editor mirrors the
  Role Manager. The invite mirrors `UserInviteMail`. The on-behalf-of column mirrors
  `contact_access_log.impersonator_id`. No new abstractions where a pattern exists.
- **No hard deletes.** Revoke = soft delete of the assignment. The matrix rows travel with it.

---

## 4. Scope summary

| Area | Decision |
|------|----------|
| Feature toggle | `agencies.assistants_enabled` (default **false**) |
| Assistant is a role? | **No permission bundle** — but a zero-grant `assistant` role row is seeded (§6.2). Justified below; this is a deliberate deviation from the brief. |
| Identity flag | `users.is_assistant` (bool, default false) — the resolver hook + fast query |
| Relationship | `assistant_assignments` row (Assistant → Agent), soft-deleted |
| Matrix storage | `assistant_assignment_permissions` (one row per permission key) |
| Assistants per Agent | **Many** |
| Agents per Assistant | **One** (v1). Enforced by a partial unique index. |
| Agent who is also an Assistant | **Blocked** at creation + assignment (§14, E5) |
| Owner/admin as the Agent | **Blocked** — the Agent must hold a non-owner role, else the owner bypass leaks everything through the matrix (§14, E6) |
| Sponsor drift (Agent gains a permission later) | **Auto-add as `granted = false`** (brief's option (a)) |
| Sponsor loses a permission | Assistant loses it **immediately** — live intersection, no re-snapshot needed |
| Property upload | Hard-locked at 4 layers (§9). Not editable in the matrix, not grantable, not reachable by URL. |
| Data visibility | Assistant inherits the Agent's data identity (§7) |
| Record ownership | Stamped to the **Agent**; actor recorded as the Assistant |
| Audit | `on_behalf_of_user_id` on the audit surfaces in §11, mirroring `contact_access_log.impersonator_id` |
| FICA | `users.fica_required` (bool). What "the FICA section" *is* for staff is an open decision — **D4** |
| Multi-tenancy | `agency_id` + `branch_id` on both new tables; `BelongsToAgency` + `BelongsToBranch` |
| Revoke | Soft delete, restorable |
| Invite email | **Reuse `App\Mail\UserInviteMail`** — no new mailable |

---

## 5. Terminology (locked)

| Term | Meaning | Schema |
|---|---|---|
| **Agent (Sponsor)** | The User whose live effective permissions are the ceiling. | `assistant_assignments.sponsor_user_id` |
| **Assistant** | The User assigned under an Agent. | `users.is_assistant = true`, `assistant_assignments.assistant_user_id` |
| **Assignment** | The relationship row carrying status + the matrix. | `assistant_assignments` |
| **Matrix** | The per-assignment permission grid the Agent controls. | `assistant_assignment_permissions` |

The word "Sponsor" is retained per the brief, **but only on `assistant_assignments`** — never on
`users`, where it would sit beside the unrelated `sponsored_by_user_id`. Both columns get a
docblock pointing at the other. If Johan wants zero ambiguity, the alternative is "Lead Agent"
(**D1**).

---

## 6. Data model

### 6.1 `users` — two new columns

```php
// database/migrations/2026_07_14_xxxxxx_add_assistant_fields_to_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('is_assistant')->default(false)->after('role');
    $table->boolean('fica_required')->default(true)->after('is_assistant');
    $table->index('is_assistant');
});
```

- `is_assistant` — the resolver hook. **Not** a role. Set true only by the Assistant creation
  flow; cleared only when the last assignment is revoked *and* an admin explicitly converts the
  user back to a normal user (§14, E9).
- `fica_required` — default `true` per the agency default (§13). Note: `signature_requests.fica_required`
  already exists and means something unrelated (the per-recipient e-sign gate). Different table,
  no conflict, but the model docblock says so.

### 6.2 `roles` — one seeded row, zero grants

**This deviates from the brief and the deviation is deliberate.** `users.role` is
`varchar NOT NULL DEFAULT 'agent'` (`database/schema/mysql-schema.sql`, users table col 8). A user
created without an explicit role **is a full agent**. If an Assistant is created with `role = 'agent'`,
then any code path that reaches `getPermissionsForRole('agent', …)` without going through the
assistant resolver hands them the entire agent permission set.

So: seed a role `assistant` with `is_owner = false`, `can_be_deleted = false`, and **no entries in
`role_permissions` at all**. It is an identity label, not a permission bundle — the brief's
requirement ("Assistant is NOT a new Role" = no inherited bundle) is honoured in substance. And
because `PermissionService` fails closed on a role with zero grants (AT-265), the zero-grant role
is the *last* line of defence: if the resolver hook in §7 were ever bypassed, the Assistant gets
**nothing**, not everything. Fail-closed by construction.

Seeded via `config/corex-permissions.php` `role_defaults` → `'assistant' => ['include' => []]`.

### 6.3 New table — `assistant_assignments`

```php
Schema::create('assistant_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
    $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    $table->foreignId('assistant_user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('sponsor_user_id')->constrained('users')->cascadeOnDelete();

    $table->enum('status', ['active', 'suspended', 'revoked'])->default('active');
    $table->string('suspend_reason', 190)->nullable();   // why the freeze (sponsor deactivated, etc.)
    $table->timestamp('snapshot_taken_at')->nullable();  // when the matrix was last (re)seeded from the sponsor

    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('revoked_at')->nullable();
    $table->string('revoke_reason', 190)->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['agency_id', 'status']);
    $table->index(['sponsor_user_id', 'status']);
    $table->index(['assistant_user_id', 'status']);
});
```

**One active Agent per Assistant** is enforced by a partial unique index. MySQL has no partial
index, so use the standard CoreX trick — a generated column:

```php
// same migration, raw statement (MySQL 8):
DB::statement("
    ALTER TABLE assistant_assignments
    ADD COLUMN active_assistant_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(status = 'active' AND deleted_at IS NULL, assistant_user_id, NULL)) STORED,
    ADD UNIQUE KEY assistant_one_active_sponsor (active_assistant_user_id)
");
```

This makes "two live sponsors for one assistant" a **database error**, not a race condition a
controller check can lose. Mirror pattern: `role_permissions`' `role_perms_role_key_agency_unique`.

Mirror for the table shape overall: `prospecting_claims`
(`database/migrations/2026_03_18_140000_create_prospecting_claims_table.php`) — agency-scoped,
user-assigned, soft-deleted assignment table.

### 6.4 New table — `assistant_assignment_permissions`

```php
Schema::create('assistant_assignment_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
    $table->foreignId('assistant_assignment_id')
        ->constrained('assistant_assignments', 'id', 'aap_assignment_fk')
        ->cascadeOnDelete();

    $table->string('permission_key', 190);
    $table->boolean('granted')->default(false);
    $table->string('scope', 10)->nullable();     // own|branch|all — only for keys ending .view
    $table->boolean('is_locked')->default(false); // property-upload set: never editable, never granted

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['assistant_assignment_id', 'permission_key'], 'aap_assignment_key_unique');
    $table->index('permission_key');
});
```

Short FK/index names are mandatory — MySQL's 64-char identifier limit bites on these table names
(same reason `contact_access_log` used `cal_impersonator_fk`).

**Reassignment archive:** no separate archive table. Reassignment soft-deletes the old assignment
(its permission rows travel with it, `deleted_at` set by a model `deleting` hook) and creates a
new one. The soft-deleted assignment *is* the archive — restorable, queryable via `withTrashed()`.

### 6.5 `agencies` — the kill switch + the FICA default

```php
Schema::table('agencies', function (Blueprint $table) {
    $table->boolean('assistants_enabled')->default(false)->after('split_branches_enabled');
    $table->boolean('assistant_fica_required_default')->default(true)->after('assistants_enabled');
});
```

Both are agency **settings** → both are surfaced in the Setup Wizard (§13, Non-negotiable #10a).

### 6.6 Eloquent relationships

```php
// App\Models\AssistantAssignment  (BelongsToAgency, BelongsToBranch, SoftDeletes)
assistant(): BelongsTo   → User::class, 'assistant_user_id'
sponsor():   BelongsTo   → User::class, 'sponsor_user_id'
permissions(): HasMany   → AssistantAssignmentPermission::class
createdBy(): BelongsTo   → User::class, 'created_by_user_id'
scopeActive($q)          → where('status', 'active')

// App\Models\User  — additions
assistantAssignment(): HasOne        // the assistant's own active assignment
    → hasOne(AssistantAssignment::class, 'assistant_user_id')->active()
assistantAssignments(): HasMany      // the agent's assistants
    → hasMany(AssistantAssignment::class, 'sponsor_user_id')->active()
sponsor(): ?User                     // convenience: $this->assistantAssignment?->sponsor
isAssistant(): bool                  // $this->is_assistant (guards against a stale flag: also requires an active assignment)
sponsorsAssistants(): bool           // $this->assistantAssignments()->exists() — drives the conditional nav entry
```

`AssistantAssignmentPermission` does **not** use `BelongsToBranch` — it is a child of the
assignment and inherits the assignment's branch implicitly. It carries `agency_id` + `BelongsToAgency`
so `AgencyScope` applies to it directly (mirror: `viewing_pack_properties`).

---

## 7. The resolver — intersection, and the data-identity problem

There are **two** resolution questions and the brief only answers the first.

### 7.1 May I do X? — `PermissionService::userHasPermission()`

The single choke point is `app/Services/PermissionService.php:303`. Insert the assistant hook
**after** the owner bypass (an assistant can never hold an owner role — §14 E6 blocks it) and
**before** the role lookup, so an assistant's `users.role` value is never consulted for grants:

```
userHasPermission(user, key):
    if user.isOwnerRole():            return true            # existing, unchanged
    if user.is_assistant:             return AssistantPermissionResolver::allows(user, key)
    ... existing role resolution unchanged ...

AssistantPermissionResolver::allows(assistant, key):
    if not assistant.agency.assistants_enabled:   return false    # kill switch → fail closed
    assignment = active assignment for assistant  # request-cached
    if assignment is null:                        return false    # fail closed
    if assignment.status != 'active':             return false    # suspended → frozen, no access
    if key in PROPERTY_UPLOAD_LOCKED_SET:         return false    # HARD LOCK — before the matrix
    sponsor = assignment.sponsor
    if sponsor is null or not sponsor.is_active:  return false    # sponsor gone → frozen
    if not assignment.grants(key):                return false    # the matrix
    return PermissionService::userHasPermission(sponsor, key)     # the live ceiling
```

The recursion terminates because a sponsor can never be an assistant (§14, E5) and can never be an
owner (§14, E6).

### 7.2 Whose records do I see? — the data-identity problem

`getDataScope()` (`PermissionService.php:201`) returns `own|branch|all`, and every one of the 23
`scopeVisibleTo()` implementations resolves `'own'` as `where('<actor column>', $user->id)`.

An Assistant's `'own'` must mean **the Agent's own**. Two additions to `User`:

```php
/**
 * The user ids whose records this user may see under an 'own' data scope.
 * Normal user: [self]. Assistant: [sponsor, self] — they see the Agent's book,
 * plus anything stamped to them directly (there should be none; see ownershipUserId()).
 */
public function dataIdentityIds(): array

/**
 * The user id a record this user creates is OWNED by.
 * Normal user: self. Assistant: the sponsor — so commission, "My Listings",
 * pipeline and targets all land on the Agent, which is the entire point.
 */
public function ownershipUserId(): int
```

Then every `scopeVisibleTo()` `'own'` branch changes from

```php
return $query->where('agent_id', $user->id);
```
to
```php
return $query->whereIn('agent_id', $user->dataIdentityIds());
```

**23 models** carry `scopeVisibleTo`: `Property`, `Contact`(via `ContactScope`), `Deal`,
`DealV2\DealV2`, `Presentation`, `Rental`, `ListingStock`, `DailyActivity`, `DocumentFiling`,
`PortalLead`, `CommercialEvaluation`, `SharedDrive`, `AiConversation`, `SalesDocumentSend`,
`Outreach\OutreachQueue`, `Communications\Communication`, `CommandCenter\CalendarEvent`,
`CommandCenter\CommandTask`, and 5 under `Docuperfect\` (`Template`, `Document`, `Pack`,
`Clause`, `LeaseRecord`, `SignatureTemplate`).

They do **not** all use the same actor column (`agent_id`, `user_id`, `created_by_user_id`), so
this is a per-model edit, not a find-and-replace.

**And it must not rot.** Mirror `tests/Feature/Branches/BranchSplitIsolationTest.php` exactly: a
coverage test that reflects over every model defining `scopeVisibleTo()` and fails the suite if its
`'own'` branch calls `->where(…, $user->id)` instead of `->whereIn(…, $user->dataIdentityIds())`,
unless the model is listed in an explicit `PRIVATE_TO_SELF` allowlist with a reason.

`AiConversation` is the first entry on that allowlist: an Assistant must **not** read the Agent's
private Ellie conversations. Its `scopeVisibleTo` stays `where('user_id', $user->id)`. Any other
genuinely-private surface goes on the list as a design decision, not as a way to silence the test.

Data scope for an assistant clamps to the sponsor's:

```
getDataScope(assistant, module):
    matrixScope  = assignment.scopeFor(module + '.view')     # what the Agent granted
    sponsorScope = getDataScope(sponsor, module)             # the live ceiling
    if sponsorScope is null: return null
    return clampScope(matrixScope, sponsorScope)             # PermissionService.php:284 — already exists
```

`clampScope()` already implements exactly this ceiling semantic. Reuse it; do not write a second one.

---

## 8. Permissions — `config/corex-permissions.php`

New section `assistants`. Entry shape is the config's own (`key`/`label`/`section`/`type`/`module`/`sort_order`
— note: **no `description` field exists** in this config, contrary to the brief):

```php
// ── Assistants ──
['key' => 'assistants.view',        'label' => 'View Assistants',           'section' => 'assistants', 'type' => 'access', 'module' => 'assistants', 'sort_order' => 1],
['key' => 'assistants.create',      'label' => 'Create & Assign Assistant', 'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 2],
['key' => 'assistants.reassign',    'label' => 'Reassign to Another Agent', 'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 3],
['key' => 'assistants.revoke',      'label' => 'Revoke Assistant Access',   'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 4],
['key' => 'assistants.view_all',    'label' => 'View All Assistants (agency-wide)', 'section' => 'assistants', 'type' => 'access', 'module' => 'assistants', 'sort_order' => 5],
```

**`assistants.manage-own` is deliberately NOT a permission.** The brief asks whether the Agent's
right to edit their own assistant's matrix should be a grantable key. It should not: it is an
*ownership* right, not a *role* right. It derives from `AssistantAssignment.sponsor_user_id ===
auth()->id()` — the same way "edit my own profile" derives from being that user. Making it
grantable creates a nonsense state (an agent who sponsors an assistant but cannot configure them,
so the assistant sits with an all-false matrix and nobody can fix it without an admin). Enforced in
the controller as `abort_unless($assignment->sponsor_user_id === $user->id, 403)`.

`role_defaults` additions:

```php
'assistant'      => ['include' => []],                  // zero grants — §6.2
'agent'          => [... 'include' => [ /* nothing new */ ]],   // agents get NO assistants.* keys
'branch_manager' => [... 'include' => ['assistants.view', 'assistants.view_all']],
'admin'          => // all-minus-exclude → inherits every assistants.* key automatically
'super_admin'    => '*',                                // automatic
```

`admin` gets the create/reassign/revoke keys for free via its all-minus-exclude default — no edit
needed. **Compliance/Principal**: whichever role name Johan uses for the compliance officer needs
`assistants.view` + `assistants.view_all` added explicitly (**D5** — the brief says "Admin /
Principal / Compliance" but CoreX has no `principal` or `compliance` role; the roles are
`super_admin`, `admin`, `branch_manager`, `agent`, `viewer`, `office_admin`).

Carried to every environment by the existing `deploy:sync-reference-data` →
`corex:sync-permissions --merge-defaults`. **No new seeder, no new deploy registration.**

---

## 9. `PROPERTY_UPLOAD_LOCKED_SET` — the four-layer lock

Defined once, in `config/assistants.php`, so there is a single list to audit:

```php
// config/assistants.php
'property_upload_locked_set' => [
    // Live gates
    'properties.create',        // PropertyWizardController:34, :163 — the real create gate
    'import_listings',          // routes/web.php:945 — CSV bulk listing import
    'access_import_listings',   // sidebar entry for the above
    'manage_p24',               // routes/web.php:661 — P24 listing import
    'mic.edit_address',         // tracked-property address create/edit
    'mic.merge_duplicates',     // tracked-property merge

    // Dead keys — locked anyway, so they can never be revived into a hole
    'create_properties',
    'publish_properties',
    'listings.create',
    'listings.edit',
],
```

`mic.upload_reports` (CMA/market-report upload, which creates tracked properties via
match-or-create) is **borderline** — it is intelligence capture, not listing upload, and is
plausibly exactly the drudge work an assistant should do. Flagged as **D3**.

The four layers — a slug list alone is provably insufficient (§2.3):

1. **Resolver** — `AssistantPermissionResolver::allows()` returns `false` for any key in the set,
   checked *before* the matrix (§7.1). An assistant can never hold these, whatever the matrix says.
2. **Route middleware** — `App\Http\Middleware\DenyAssistantPropertyWrite`, aliased
   `deny_assistant_property_write` in `bootstrap/app.php`, applied to the **keyless** paths that
   the resolver cannot see: `POST /corex/properties`, the wizard mutation routes
   (`routes/web.php:2821-2826`), `POST /api/v1/mobile/properties` + `/{property}/images`,
   `POST /api/v1/properties/pull-from-portal`, `POST /api/v1/prospecting/import`. Returns 403 with
   a plain-language message. (The P24 importer and sold-CSV import are already `owner_only` /
   `super_admin` — an assistant can never reach them; no change needed, but the regression test
   asserts it.)
3. **Matrix UI** — locked rows render disabled, pre-unchecked, with a `title=` tooltip: *"Property
   upload is switched off for all assistants by CoreX. Only the agent can create a listing."*
   (Per STANDARDS.md "No Silent Locks" — the lock states why. It has no unlock path, and saying so
   plainly is the correct UX, not a dead button.)
4. **Server-side matrix validation** — `AssistantMatrixController::save()` filters any locked key
   out of the payload before writing, and `AssistantAssignmentPermission::saving()` forces
   `granted = false` when `is_locked = true`. A hand-crafted POST cannot grant a locked key.

---

## 10. Assistant profile page — section-by-section

The agent profile is **`/my-portal`** (`agent.portal`) →
`app/Http/Controllers/Agent/AgentPortalController.php` + `resources/views/agent/portal.blade.php`
(1415 lines, hash-driven Alpine tabs). `/profile` is a 301 redirect to `/my-portal#profile`.

**There is no staff-FICA section in CoreX today.** FICA in CoreX means *client* FICA
(`Compliance\FicaController`). The staff-side equivalents that exist are: compliance documents
(FFC cert, ID copy, PI insurance, tax clearance), **Employee Screening**, and the **RMCP
acknowledgement** wizard. So "show the FICA section if `fica_required`" has no existing section to
show — see **D4**.

| Tab / Section | portal.blade.php | Assistant sees? | Why |
|---|---|---|---|
| **Overview** tab | :126-304 | **HIDE** | Every card on it is agent-economics |
| — My Earnings (commission ledger) | :132-155 | HIDE | Assistants have no commission |
| — Compliance Overview | :158-185 | **SHOW** (reduced) | Only their own doc items |
| — My Presentations | :187-220 | HIDE | |
| — Recent Activity (commission ledger) | :222-263 | HIDE | |
| — Training Progress | :265-284 | **SHOW** | Training applies to staff |
| — Admin Access Log (impersonation) | :286-303 | **SHOW** | POPIA — their own access log |
| **Profile** tab | :306-641 | **SHOW** (reduced) | |
| — Public agent page preview | :312-320 | HIDE | Assistants have no public page |
| — **Profile Photo upload** | :322-338 | **SHOW** | Brief requirement |
| — Profile Information (name/email/phone/cell/ID number) | :340-424 | **SHOW** | |
| — FFC Number + FFC Expiry inputs | :407-423 | **HIDE** | Assistants are not PPRA practitioners |
| — Public Website Profile (about_me, socials) | :426-454 | HIDE | |
| — Admin Managed block (designation, role, branch) | :456-493 | **SHOW** (read-only) | Add: **"Assistant to: <Agent>"** |
| — PPRA Status | :479-491 | **HIDE** | Not a practitioner |
| — Branches I Manage | :502-542 | HIDE | |
| — Articles CRUD | :544-640 | HIDE | |
| **Tools** tab (theme, QR, ad accounts, WhatsApp) | :643-946 | **SHOW** theme only; HIDE agent QR + ad accounts + WA capture | QR is an agent-marketing artefact |
| **Documents** tab | :948-1108 | **SHOW** (reduced) | |
| — FFC Certificate card | :954-955 | **HIDE** | |
| — **ID Copy card** | :956-957 | **SHOW** | Brief requirement |
| — PI Insurance card | :958-959 | HIDE | Practitioner cover |
| — Tax Clearance card | :960-961 | HIDE | |
| — **Profile Photo card** | :962-963 | **SHOW** | |
| — **Proof of Residence card** | — | **SHOW — MUST BE BUILT** | `UserDocument::DOCUMENT_TYPE_PROOF_OF_ADDRESS` exists (`app/Models/UserDocument.php:24`) and is rendered admin-side, but **has no portal card**. New card in `$docTypeConfig`. |
| **Compliance** tab | :1110-1209 | **SHOW** iff `fica_required` (see D4) | Items reduce to: ID Copy, Proof of Residence, RMCP Acknowledgement, Employee Screening |
| **Training** tab | :1211-1293 | **SHOW** | |
| **Password** tab | :1295-1361 | **SHOW** | |
| — Delete Account danger zone | :1340 | **HIDE** | Admin-only action for assistants |
| **Payslips** tab | :1363-1395 | HIDE | Already gated `view_own_payslips` — assistant matrix can't grant it (agent can't either) |
| **Leave** tab | :1397-1410 | HIDE for v1 | Already gated `apply_for_leave`. **D7** — if the agency employs the assistant, leave arguably *should* apply. |
| Commission / bank details / qualifications | not on portal today | N/A | Bank details = Payroll only; qualifications = constant with no card |

Implementation: a single `$isAssistant` flag passed from `AgentPortalController::index()`, plus a
`@unless($isAssistant)` wrapper per hidden section. **Not** a forked Blade view — a fork would
drift within a month.

`computeComplianceStatus()` (`AgentPortalController.php:486-630`) skips FFC / PI / Tax / PPRA
items entirely for an assistant, and skips **all** compliance items when `fica_required = false`.

---

## 11. Audit — `on_behalf_of_user_id`

There is **no `spatie/laravel-activitylog`** and no generic activity table. There are ~17 bespoke
audit tables. The precedent for exactly this problem already exists (AT-118):
`contact_access_log.impersonator_id`, written from `App\Support\Impersonation::actingAdminId()`.

**Mirror it.** New helper, same shape:

```php
// app/Support/ActingFor.php
class ActingFor
{
    /** The sponsor id when the authed user is an assistant; null otherwise. Session/console-safe. */
    public static function onBehalfOfUserId(): ?int;
}
```

Column, everywhere, mirroring the `contact_access_log` migration exactly (short FK name — 64-char
limit):

```php
$table->foreignId('on_behalf_of_user_id')->nullable()->after('<actor column>')
    ->constrained('users', 'id', '<short>_obo_fk')->nullOnDelete();
```

| # | Table | Actor column it sits beside | Write site |
|---|---|---|---|
| 1 | **`domain_event_log`** | `actor_user_id` | `AbstractDomainEvent` / the audit listener — **highest leverage: this is the one central, cross-pillar log** |
| 2 | `property_audit_log` | `user_id` | `app/Services/Audit/PropertyAuditService.php:36` (single writer — one edit) |
| 3 | `deal_logs` (DR1) | `actor_user_id` | private `logDealEvent()` — duplicated in 6 controllers/listeners. **Extract to one writer first**, then add the column. |
| 4 | `deal_activity_log` (DR2) | `user_id` | inline `create()` in 5 controllers — same: extract, then add |
| 5 | `signature_audit_log` (e-sign) | `actor_id` + `actor_type` | `SignatureAuditLog::log()` — single static writer, ~25 call sites, one signature edit |
| 6 | `calendar_event_audit_log` | `performed_by_user_id` | `CalendarEventAuditEntry::create()` |
| 7 | `legal_block_audit_log` | `user_id` | `Docuperfect/Template.php:369` |
| 8 | `comms_access_audit_log` | `actor_user_id` | `CommsAccessAuditLog::record()` — already injects `acting_as_admin_id` into its `detail` JSON; add the real column |
| 9 | `marketing_share_log` | `user_id` | raw `DB::table()->insert()` in `PropertyAuditService.php:98` |
| 10 | `contact_access_log` | `user_id` (+ existing `impersonator_id`) | `LogsContactAccess.php:66` — **distinct concept**, keep both columns |

`esign_consent_log` and `deal_document_access_log` are **excluded** — their actor is the external
signer/recipient, never a staff user.

`ContactAccessLog` also has a latent gap worth closing in the same pass: `impersonator_id` is
fillable but has **no `impersonator()` BelongsTo relation**, so nothing can render it. The new
`onBehalfOf()` relation lands on every model above, and the missing `impersonator()` gets added.

**The rule that stops this rotting:** any new audit table carrying an actor column ships with
`on_behalf_of_user_id`. Enforced by a coverage test mirroring `BranchSplitIsolationTest` —
reflect over models whose table has an actor column, fail if `on_behalf_of_user_id` is absent and
the model is not on an explicit `NO_STAFF_ACTOR` allowlist.

---

## 12. Routes, controllers, views, navigation

### Admin surface (permission-gated)

| Method | URI | Name | Gate |
|---|---|---|---|
| GET | `/admin/assistants` | `admin.assistants.index` | `permission:assistants.view` |
| GET | `/admin/assistants/create` | `admin.assistants.create` | `permission:assistants.create` |
| POST | `/admin/assistants` | `admin.assistants.store` | `permission:assistants.create` |
| GET | `/admin/assistants/{assignment}` | `admin.assistants.show` | `permission:assistants.view` |
| POST | `/admin/assistants/{assignment}/reassign` | `admin.assistants.reassign` | `permission:assistants.reassign` |
| POST | `/admin/assistants/{assignment}/revoke` | `admin.assistants.revoke` | `permission:assistants.revoke` |
| POST | `/admin/assistants/{assignment}/restore` | `admin.assistants.restore` | `permission:assistants.revoke` |
| POST | `/admin/assistants/{assignment}/resend-invite` | `admin.assistants.resend-invite` | `permission:assistants.create` |

Controller `app/Http/Controllers/Admin/AssistantController.php`. **Full CRUD is the floor**
(BUILD_STANDARD §1): list, view, create, reassign, revoke, restore.

`store()` reuses `UserManagementController::store()`'s shape verbatim:
`User::create([... 'password' => 'INVITE_PENDING', 'is_active' => true, 'email_verified_at' => null,
'role' => 'assistant', 'is_assistant' => true, 'fica_required' => $agency->assistant_fica_required_default])`
then **`Mail::to($user->email)->send(new UserInviteMail($user))`** — the existing signed-URL,
7-day, `account.setup` flow (`app/Mail/UserInviteMail.php:25`). **No new mailable, no new template,
no new token mechanism.** The Assistant lands on the same `auth.account-setup` page every other
new user does.

### Agent surface (ownership-gated, not permission-gated)

| Method | URI | Name | Gate |
|---|---|---|---|
| GET | `/my-portal/assistants` | `agent.assistants.index` | auth + `sponsorsAssistants()` |
| GET | `/my-portal/assistants/{assignment}/matrix` | `agent.assistants.matrix` | `$assignment->sponsor_user_id === auth()->id()` |
| POST | `/my-portal/assistants/{assignment}/matrix` | `agent.assistants.matrix.save` | same |

Controller `app/Http/Controllers/Agent/AssistantMatrixController.php`.

### Navigation (Non-negotiable #2 — same day)

**Agent's sidebar**, in the `Agents` section, directly under **My Portal**
(`resources/views/layouts/corex-sidebar.blade.php:522-542`). Conditional on a *data* condition, so
it mirrors the established precedent at `:641` (permission + data check) and the cached-count
pattern at `:573-590`:

```blade
@php
    $_sponsorsAssistants = cache()->remember(
        'assistants.sponsors.'.$user->id, 60,
        fn () => \App\Models\AssistantAssignment::where('sponsor_user_id', $user->id)->active()->exists()
    );
@endphp
@if($_sponsorsAssistants && ($_userAgency?->assistants_enabled))
    <a href="{{ route('agent.assistants.index') }}"
       class="corex-nav-item {{ request()->routeIs('agent.assistants.*') ? 'active' : '' }}">
        <svg …/><span>My Assistants</span>
    </a>
@endif
```

The cache key is busted on assignment create/revoke/restore.

**Admin's sidebar**: `Assistants` under the existing **Company** group (`:1482-1515`), beside Role
Manager, gated `@permission('assistants.view')`.

### Views

- `resources/views/admin/assistants/index.blade.php` — list (Assistant · Agent · Branch · Status · Invite state · Actions)
- `resources/views/admin/assistants/create.blade.php` — mirrors `admin/users/create-edit.blade.php`'s Profile tab + an **Agent** selector (searchable, filtered to non-owner, non-assistant, active users in the agency) + a **FICA required** toggle
- `resources/views/admin/assistants/show.blade.php` — assignment detail, matrix read-only, audit history
- `resources/views/agent/assistants/index.blade.php` — the Agent's assistant list
- `resources/views/agent/assistants/matrix.blade.php` — **the matrix editor**

### The matrix editor — mirror the Role Manager exactly

Source pattern: `resources/views/corex/role-manager.blade.php` (1117 lines) + the `roleManager()`
Alpine component at `:822-1116` + `RoleManagerController::index()`'s `$matrixSections` grouping
(`:162-183`).

Copy: the two-column layout (left = searchable feature/module list grouped by section; right =
the detail panel for `selectedFeature` only), the `matrix[key]` / `scopeMatrix[key]` Alpine maps,
hidden inputs rendered for the selected feature only, the 800 ms debounced autosave
(`scheduleSave()`, `:972`), the `beforeunload` dirty guard, and the transactional
delete-then-chunked-insert save.

Differences:
- Rows are filtered to **only the permissions the Agent themselves currently holds** — computed
  server-side as `array_filter($allKeys, fn($k) => $sponsor->hasPermission($k))`. The Agent
  literally cannot see, let alone grant, a permission they don't have.
- Scope radios clamp to the Agent's own scope for that module: if the Agent's `contacts` scope is
  `own`, the Assistant's options are `None | Own` — `Branch` and `All` are not rendered.
- Property-upload rows render **disabled + unchecked + tooltipped** (§9 layer 3).
- No role switcher, no copy-from-role, no bulk copy. One assignment at a time.

---

## 13. Agency settings + the Setup Wizard (Non-negotiable #10a)

Two new settings → two new wizard controls, **in this build, not later**:

| Setting | Column | Wizard step |
|---|---|---|
| Enable Assistants | `agencies.assistants_enabled` (default false) | Step: **Your branches / Your team** |
| Assistants require FICA verification by default | `agencies.assistant_fica_required_default` (default true) | same step |

`config/agency-onboarding-copy.php`, entry shape `['key','source'=>'agency','type'=>'toggle','default','explain','affects']`:

```php
['key' => 'assistants_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
 'label' => 'Allow agents to have assistants',
 'explain' => 'An assistant is a person who works for one of your agents — they get their own login, and the agent chooses exactly which of their own permissions to hand over. The assistant can never do more than the agent can, and can never create a listing.',
 'affects' => 'What this changes: an "Assistants" page appears under Company for your admins, and any agent who has an assistant gets a "My Assistants" entry in their sidebar to manage what that assistant may do.'],

['key' => 'assistant_fica_required_default', 'source' => 'agency', 'type' => 'toggle', 'default' => 1,
 'label' => 'New assistants must complete FICA verification',
 'explain' => 'Whether a newly-created assistant is asked for their identity documents (ID copy and proof of residence) as part of their onboarding.',
 'affects' => 'What this changes: when on, a new assistant sees a Compliance tab on their profile asking for an ID copy and proof of residence, and appears on your compliance dashboards. When off, that tab is hidden and they are skipped by FICA reminders.'],
```

**Read `.ai/specs/agency-onboarding-setup.md` §6.1 before wiring the saver** — the wizard step
posts a *subset* of the saver's fields, and a saver that coerces an absent checkbox to `false`
silently wipes settings the step never rendered. Both writes must be guarded with
`$request->has()`.

---

## 14. Edge cases — every one resolved

| # | Case | Decision | Justification |
|---|---|---|---|
| **E1** | Agent deactivated (`is_active = false`) or soft-deleted | **Freeze, not cascade.** Assignment → `status = 'suspended'`, `suspend_reason = 'sponsor_deactivated'`. Assistant keeps their login, has **zero permissions**, and sees a banner: *"Your agent's account is inactive. Ask an administrator to reassign you."* Reactivating the Agent auto-restores `status = 'active'` with the matrix intact. | Cascade-revoking would destroy the matrix on a temporary suspension. Freezing is reversible; revoking is not. The resolver already fails closed on a non-active sponsor (§7.1), so the freeze is defence-in-depth, not the only guard. |
| **E2** | Agent transferred to another branch | Assignment's `branch_id` **follows the Agent**, updated in the same transaction as the user's branch change (`BranchAssignmentController`). | Branch-isolation doctrine: "all historical data follows the user" (branch-isolation-spec §3). An assistant stranded in the old branch would see nothing. |
| **E3** | Agent demoted / loses a permission | Assistant loses it **on the next request**. No schema change, no re-snapshot. | The intersection is evaluated live against `PermissionService::userHasPermission($sponsor, $key)` (§7.1). This is the whole point of intersecting rather than copying. |
| **E4** | Agent *gains* a permission after the snapshot | Auto-added to the matrix as **`granted = false`**. A nightly `assistants:sync-matrix` command inserts missing rows; the Agent's matrix page shows a *"3 new permissions available"* chip. | Brief's option (a). Safe default: the Agent must consciously hand over anything new. Silent auto-grant would widen an assistant's access without anyone deciding to. |
| **E5** | A user is both an Agent-with-an-assistant **and** an Assistant to someone else | **BLOCKED.** Validation on create + assign: the chosen Agent must have `is_assistant = false`; a user with active `assistantAssignments()` cannot be converted into an assistant. | Chained delegation makes the audit story unprovable ("who authorised this, really?") and makes the resolver recursive. Recommended by the brief; confirmed. |
| **E6** | The chosen Agent holds an **owner** role | **BLOCKED.** The Agent selector filters to non-owner roles; `store()` re-validates. | `userHasPermission()` returns `true` unconditionally for owners (`PermissionService.php:306`). An owner sponsor would make the matrix the *only* limit — the intersection would be with "everything", and a mis-ticked box would grant an assistant super-admin powers. Not in the brief; found during investigation. |
| **E7** | One Assistant, multiple Agents | **BLOCKED for v1.** Enforced by the generated-column unique index (§6.3), not just a controller check. | Simpler audit story: one `on_behalf_of_user_id` per action, no ambiguity about which agent's book a record lands in. Recommended by the brief; confirmed. Multi-agent assistants are a v2 conversation. |
| **E8** | One Agent, multiple Assistants | **ALLOWED.** | No ambiguity — each assistant has one sponsor. |
| **E9** | Reassignment to a different Agent | Old assignment soft-deleted (matrix rows travel with it, restorable). New assignment created with a **fresh snapshot from the new Agent**, all `granted = false` except… nothing. The new Agent starts from a blank matrix and opts in. | The old matrix is meaningless against a different permission ceiling. Starting blank forces a conscious decision; carrying it over would silently grant whatever the two agents happen to share. |
| **E10** | Assistant acts outside the Agent's branch | Blocked by `BranchScope` (the assistant's `branch_id` = the Agent's) and audited as a denial. | Existing mechanism; no new code. |
| **E11** | `fica_required` flipped **off** while documents are already uploaded | Documents **retained** (soft, never deleted). Compliance tab hidden from the assistant. Compliance officers still see the documents via `admin.user.documents.*`. | No hard deletes, ever. The docs were lawfully collected; hiding the tab is a UI decision, not a retention decision. |
| **E12** | `fica_required` flipped **on** later | Compliance tab appears; the user enters the normal onboarding state; existing documents (if any) show with their existing status. | Idempotent — the tab is derived, never a stored state machine. |
| **E13** | Assistant's own record ownership | Records they create are stamped `agent_id`/`user_id` = **the Agent** (`ownershipUserId()`); the audit row records the Assistant as actor + the Agent as `on_behalf_of_user_id`. | The brief's "all work traceable back to the sponsoring agent". Also the only correct answer for commission: a deal captured by an assistant is the *Agent's* deal and must hit the Agent's ledger, targets and pipeline. |
| **E14** | Assistant reads the Agent's private Ellie conversations | **BLOCKED.** `AiConversation` is on the `PRIVATE_TO_SELF` allowlist (§7.2). | Not everything the Agent can see is something the Agent meant to delegate. |
| **E15** | `assistants_enabled = false` but assignments exist (toggle flipped off) | Resolver fails closed — assistants get **zero** permissions; the nav entry disappears; the assignments are untouched. Flipping back on restores everything. | Same reversible-toggle doctrine as `split_branches_enabled`. |

---

## 15. Domain events (Non-negotiable #9)

Cross-pillar reactivity → named events, not ad-hoc calls. Per
`.ai/specs/corex-domain-events-spec.md`, past-tense facts, uniform payload:

| Event | Emitted when | Payload | Listeners |
|---|---|---|---|
| `Assistant\AssistantAssigned` | Assignment created | `assignment`, `assistantUserId`, `sponsorUserId`, `agencyId` | audit; bust the sidebar cache; notify the Agent |
| `Assistant\AssistantMatrixChanged` | Agent saves the matrix | `assignment`, `changedKeys`, `actorUserId` | audit (this is a permission change — it must be logged) |
| `Assistant\AssistantReassigned` | Sponsor changed | `assignment`, `oldSponsorId`, `newSponsorId` | audit; notify both Agents |
| `Assistant\AssistantRevoked` | Assignment revoked | `assignment`, `revokedByUserId`, `reason` | audit; bust cache; notify |
| `Assistant\AssistantSuspended` | Sponsor deactivated (E1) | `assignment`, `reason` | audit; notify the assistant |

**Registration:** listeners MUST be explicitly registered in `AppServiceProvider::boot()` —
event discovery is **OFF** in this codebase. And listeners on domain events must stay
**synchronous**: a queued listener on an `AbstractDomainEvent` fatals (the parent's readonly
`$eventId` cannot be restored from the child scope). If work must be async, the sync listener
dispatches a Job carrying scalars.

---

## 16. Build sequencing

Each prompt follows **investigate → report → approve → fix**. Each ends with `php -l` on every
changed file, `php artisan view:clear`, the **single most relevant test file** (never a broad
suite — CLAUDE.md #13), and Tinker functional verification. No prompt is done until it is pushed.

| # | Prompt | Description |
|---|--------|-------------|
| **A** | Schema + models | The two migrations (§6.1, §6.3, §6.4, §6.5), the generated-column unique index, `AssistantAssignment` + `AssistantAssignmentPermission` models with `BelongsToAgency`/`BelongsToBranch`/`SoftDeletes`, the `User` relationships (§6.6). Then `DB_DATABASE=hfc_dash_test php artisan schema:dump` (**never** a plain `schema:dump` — it reads the stale dev DB and silently drops tables) and commit the snapshot with the migration. No behaviour yet. |
| **B** | Permissions + the zero-grant role | `config/corex-permissions.php` — the `assistants` section (§8), `role_defaults` for `assistant` (empty) and `branch_manager`. Verify `corex:sync-permissions --merge-defaults` is idempotent and that a user with `role = 'assistant'` and no matrix resolves to **zero** permissions. |
| **C** | The resolver | `AssistantPermissionResolver` + the two hooks in `PermissionService` (`userHasPermission` §7.1, `getDataScope` §7.2). `config/assistants.php` with `PROPERTY_UPLOAD_LOCKED_SET`. Request-level caching of the active assignment. **Tests first**: no assignment → 0 perms; suspended → 0; sponsor inactive → 0; locked key → false even when the matrix says true; matrix true + sponsor false → false; sponsor loses a perm → assistant loses it. |
| **D** | Data identity | `User::dataIdentityIds()` + `ownershipUserId()`. Sweep all 23 `scopeVisibleTo()` models. Add the `AssistantVisibilityCoverageTest` (mirror `BranchSplitIsolationTest`) with the `PRIVATE_TO_SELF` allowlist. **This is the biggest prompt — it may split into D1 (helpers + coverage test, all models allowlisted) and D2..Dn (drain the allowlist module by module).** |
| **E** | Property-upload hard lock | `DenyAssistantPropertyWrite` middleware on the keyless routes (§9 layer 2), the `saving()` guard on the matrix model (layer 4). Test every path in the §2.3 table returns 403 for an assistant — including the mobile API and the portal-pull endpoint. |
| **F** | Admin CRUD | `AssistantController` + the four Blade views. Create → `User::create` with `INVITE_PENDING` → `UserInviteMail`. Reassign, revoke, restore, resend-invite. Agent selector filtered per E5/E6. Nav entry under Company. |
| **G** | Snapshot + drift | `AssistantMatrixSnapshotService::snapshot($assignment)` — seeds one row per permission the sponsor holds, `granted = false`, locked-set rows `is_locked = true`. `assistants:sync-matrix` scheduled command for drift (E4) + the "new permissions available" chip. |
| **H** | The matrix editor | `AssistantMatrixController` + `agent/assistants/matrix.blade.php`, mirroring the Role Manager (§12). Filtered to the sponsor's own permissions; scopes clamped; locked rows disabled + tooltipped. The conditional "My Assistants" sidebar entry. |
| **I** | Assistant profile | `$isAssistant` through `AgentPortalController::index()`; the `@unless` wrappers per §10; the **new Proof of Residence card**; `computeComplianceStatus()` reduced for assistants and skipped entirely when `fica_required = false`. |
| **J** | Audit — `on_behalf_of_user_id` | `App\Support\ActingFor`. The column + relation on the 10 tables in §11. **Extract `logDealEvent()` and the DR2 inline `create()`s to single writers first** — do not add the column to 11 duplicated call sites. The `AuditActorCoverageTest`. |
| **K** | Setup Wizard + agency settings | §13 — both controls in `config/agency-onboarding-copy.php` with `explain` + `affects`, savers guarded with `$request->has()` per `agency-onboarding-setup.md` §6.1. Company Settings toggles. |
| **L** | Domain events | The five events + listeners (§15), registered explicitly in `AppServiceProvider::boot()`, sync-only. |
| **M** | Canary | §17. |
| **N** | Regression matrix | §18. |

**Parallelism:** D and E can run alongside F/G/H once C has landed. J is independent of everything
after A and can run in parallel throughout. K is independent.

---

## 17. Canary plan

QA1 first (`qatesting1.corexos.co.za`, branch `QA1`) — a real-data snapshot, `APP_ENV=qa`,
outbound neutralised, so an accidental invite email cannot reach a real person. **The invite flow
must be verified on Staging, not QA1** — QA is web-only (no queue worker), and while
`UserInviteMail` is sent synchronously today, `MAIL_MAILER` is neutralised there.

Then a single real pair on Staging, then live:

1. **Johan nominates the pilot pair** — one HFC agent who already has an admin person doing their
   paperwork, and that person. (**D8** — I have not picked; naming a real person's account without
   Johan is not my call.)
2. Create the assistant against the nominated agent with `assistants_enabled = true` for HFC only.
   Every other agency stays `false`.
3. Matrix starts **entirely false**. The agent switches on capabilities one at a time over a week,
   starting with the safest (`contacts.view` at scope `own`, `command_center.tasks.*`).
4. Verify daily for one week: (a) every record the assistant creates lands on the **agent's**
   dashboard, ledger and pipeline; (b) every audit row shows assistant-as-actor + agent-as-on-behalf-of;
   (c) the assistant cannot reach any property-create path (walk all 7 rows of the §2.3 table by URL);
   (d) the assistant sees the agent's contacts and **not** the agent's Ellie conversations.
5. Only then offer the toggle to other agencies.

---

## 18. Acceptance criteria

The feature is done when:

1. An admin can create an assistant, assign an agent, and the assistant receives the **existing**
   CoreX invite email and sets their own password via `account.setup`.
2. A newly-assigned assistant with an all-false matrix can log in and do **nothing** — every
   sidebar entry except their own portal is absent, and every URL 403s.
3. The agent's "My Assistants" entry appears **only** while they sponsor an active assistant.
4. The agent's matrix shows **only permissions the agent holds**, with scopes clamped to the
   agent's own scope.
5. Granting `contacts.view` to the assistant makes the **agent's** contacts visible to them — not
   an empty list. (This is the §2.4 test. If it fails, the feature is inert.)
6. A contact/task/document created by the assistant is **owned by the agent** and appears on the
   agent's dashboard.
7. Every audit row for that action carries `on_behalf_of_user_id = <agent>`.
8. The assistant cannot create a property via **any** of the 7 paths in §2.3 — matrix, direct URL,
   mobile API, portal pull, prospecting import, importer, CSV.
9. Locked matrix rows are visibly disabled and explain why.
10. Revoking the sponsor's permission removes it from the assistant on the next request, with no
    re-snapshot.
11. Deactivating the agent freezes the assistant (zero permissions, banner shown); reactivating
    restores the matrix intact.
12. Revoke → restore round-trips with the matrix intact. No hard deletes anywhere.
13. `assistants_enabled = false` returns the system to exactly current behaviour, with assignments
    preserved.
14. The assistant's profile shows exactly the sections in §10 and no others.
15. Both new settings appear in the Setup Wizard with `explain` + `affects`.
16. `AssistantVisibilityCoverageTest` and `AuditActorCoverageTest` pass, with every allowlist entry
    carrying a written reason.
17. No new failures in the targeted test files (per CLAUDE.md #13 — no broad suite without Johan's
    go-ahead).

---

## 19. Rollback plan

The feature is designed to be inert until switched on, so rollback is a toggle, not a revert.

| Failure point | Rollback |
|---|---|
| **Any prompt, pre-deploy** | Standard: the branch is not merged. |
| **A (schema) fails on deploy** | Both migrations have working `down()`. The columns are additive and defaulted — `assistants_enabled = false` means no code path reads them. Rolling forward with a fix is safer than dropping tables; drop only if the migration itself is malformed. |
| **C (resolver) misbehaves in production** | `UPDATE agencies SET assistants_enabled = 0` — the resolver's first check. Every assistant instantly has zero permissions (fail-closed), which is the safe direction. Assistants cannot do *less* harm than a normal user, so the failure mode of the kill switch is "assistant can't work", never "assistant can do more". |
| **D (data identity) leaks an agent's data to the wrong user** | This is the only genuinely dangerous prompt. Its rollback is a code revert of the `dataIdentityIds()` sweep, because a leak is not fixed by the kill switch (`dataIdentityIds()` returns `[self]` for non-assistants, so a bug there affects *everyone*). **Mitigation: D ships with the coverage test and is deployed to QA1 alone for 48h before Staging.** |
| **E (lock) has a hole** | Add the missing path to the middleware and redeploy. The hole is a missed route, not a design failure — the resolver still denies the slug. |
| **J (audit) breaks a write path** | The column is nullable with `nullOnDelete`. Revert the writer change; the column stays harmlessly empty. |
| **Post-launch, assistant misuse** | `admin.assistants.revoke` — soft delete, instant, restorable. |

**Data safety:** nothing in this build deletes or rewrites existing rows. The only writes to
existing tables are additive nullable/defaulted columns. There is no backfill.

---

## 20. Decisions requiring Johan's sign-off

**No code is written until these are answered.**

| # | Decision | Recommendation |
|---|---|---|
| **D1** | **Terminology.** "Sponsor" already means *commission mentor* (`users.sponsored_by_user_id`) and "supervisor" means *PPRA candidate supervisor* (`users.supervised_by`). Keep "Sponsor" for the assistant's agent, or rename? | Keep **"Sponsor"** in the UI (it is your word and the mental model is right), confine it to `assistant_assignments.sponsor_user_id`, and cross-reference both columns in docblocks. Rename to "Lead Agent" only if you want zero grep ambiguity. |
| **D2** | **The `assistant` role.** The brief says "not a new role", but `users.role` defaults to `'agent'` — an assistant created without an explicit role **is a full agent**. | Seed a **zero-grant `assistant` role**. It carries no permissions (the matrix does that), so the brief's intent holds, and it makes the system fail *closed* if the resolver is ever bypassed. |
| **D3** | **Is `mic.upload_reports` a property-upload permission?** Uploading a CMA/market report creates tracked properties via match-or-create. | **Do not lock it.** It is intelligence capture, not listing creation — and it is precisely the drudge work an assistant should absorb. Lock only the paths that put a property on the agency's books. Say the word and I'll add it to the set. |
| **D4** | **What is "the FICA section" for a staff member?** CoreX has *no* staff-FICA section. FICA here means *client* FICA. The staff-side surfaces are compliance documents, Employee Screening, and the RMCP acknowledgement. | Read `fica_required` as **"collect and verify this person's identity documents"** → ID copy + **Proof of Residence** (a card I must build; the document type exists but has no portal card) + Employee Screening + RMCP acknowledgement. If you mean something more — a real staff-FICA record with risk rating — that is a separate spec and a bigger build. |
| **D5** | **Which roles get `assistants.*`?** The brief says "Admin / Principal / Compliance". CoreX has no `principal` or `compliance` role — the roles are `super_admin`, `admin`, `branch_manager`, `agent`, `viewer`, `office_admin`. | `admin` + `super_admin` get everything (automatic). `branch_manager` gets view + view_all. Name the role you mean by "Compliance" and I'll grant it view + view_all. |
| **D6** | **Does the agency's RMCP need an Assistants clause?** An assistant handling client identity documents under an agent is a person the FIC Act programme should arguably name. This is compliance *content*, not code. | Your call as compliance officer. If yes, it is a new `RmcpSection` in a new RMCP version — a separate, small prompt, not part of this build. |
| **D7** | **Leave and payslips for assistants.** Hidden in v1. But if the agency *employs* the assistant, leave arguably applies. | Hide for v1 (they are already permission-gated and the agent cannot grant what they don't have). Revisit once payroll knows what an assistant is. |
| **D8** | **The canary pair.** Which HFC agent, and which assistant? | You nominate. I will not put a real person's account into a pilot without you naming them. |

---

## 21. Deliberately NOT in the Setup Wizard

Nothing. Both new settings (§13) are in the wizard. This section exists so that any *future*
assistant setting that is deliberately left out is recorded here as a decision, per
Non-negotiable #10a.

---

End of spec.
