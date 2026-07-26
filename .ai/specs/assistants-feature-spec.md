# Assistants — Build Spec

> Spec file: `.ai/specs/assistants-feature-spec.md`
> Status: **Approved — ready for build** (Johan sign-off 2026-07-14, §20)
> Ticket: **AT-267** (`assistant feature`) — branch `AT-267-assistants`
> Author: Johan (product architect) + Claude (solution design)
> Supersedes: N/A
> Related: [branch-isolation-spec.md](branch-isolation-spec.md), [multi-tenancy.md](multi-tenancy.md), [corex-domain-events-spec.md](corex-domain-events-spec.md), [agency-onboarding-setup.md](agency-onboarding-setup.md)

---

## 1. Purpose

An **Assistant** is a User who works for one specific **Assigned Agent**. On assignment the
Assistant receives a **copy** of that Agent's permissions; the Agent then switches individual
capabilities off from their own Assistant page. The Assistant can never do more than the Agent can,
and can never create a listing.

Every action the Assistant takes is recorded as *the Assistant acting on behalf of the Assigned
Agent*, and every record they create is **owned by the Agent** — so commission, pipeline, targets
and "My Listings" all land where they belong.

The feature exists because agency admin staff already do agent work today under the agent's own
login (shared passwords). That is a FICA/POPIA/PPRA defensibility hole: the audit trail says the
agent did it, and it is not true. Assistants close the hole by giving the person their own login
while keeping the *work* attributable to the Assigned Agent.

---

## 2. Investigation findings that changed the brief

The build brief made four assumptions the codebase contradicts. Each is resolved below.

### 2.1 "Sponsor" is already a word in this schema — and it means something else

| Existing column | Meaning today | Defined |
|---|---|---|
| `users.sponsored_by_user_id` | **Commission mentor / revenue-share sponsor.** Pairs with `agent_tier` (standard/mentor/team_lead/icon) and `is_mentor_eligible`. | `database/migrations/2026_03_27_300001_add_commission_columns_to_users.php:13` |
| `users.supervised_by` | **PPRA candidate-practitioner supervisor.** Pairs with `signature_templates.is_candidate_flow` + `supervisor_user_id`. | `database/migrations/2026_03_22_184212_add_supervised_by_to_users_table.php:22` |

Neither is the assistant relationship. **Johan's ruling (D1): the term is "Assigned Agent", not
"Sponsor".** The word "sponsor" appears nowhere in this feature — not in the UI, not in a column,
not in a variable name. The relationship lives on its own table (`assistant_assignments.agent_user_id`)
and no sponsor/supervisor column on `users` is touched.

### 2.2 The RMCP seeder is not a permission registry

The brief names `database/seeders/HfcRmcpMasterSeeder.php` as the "RMCP source of truth" for new
permission slugs. It is not. **RMCP = Risk Management and Compliance Programme** — the agency's
FIC Act s42 compliance *document* (`RmcpVersion` → `RmcpSection` → `RmcpVariable`; sections are
"Definitions", "Compliance with Section 20A", "Establishment and Verification of Identity"…). It
defines zero permission slugs.

Permissions live in **`config/corex-permissions.php`**, synced by `php artisan
corex:sync-permissions --merge-defaults`, already registered in `deploy:sync-reference-data`
(`app/Console/Commands/Deploy/SyncReferenceData.php:42`). §8 puts the new permissions there.
**No new seeder. No new deploy registration.**

### 2.3 A permission-slug lock cannot hard-lock property upload

The brief assumes property upload can be locked by denying permission slugs. It cannot:

- The **live** create gate is `properties.create`, checked in exactly one place — the wizard
  (`app/Http/Controllers/CoreX/PropertyWizardController.php:34` and `:163`).
- `create_properties`, `publish_properties`, `delete_properties`, `properties.archive`,
  `listings.create|edit|archive` are **defined in config and never checked anywhere**. They are
  dead keys that read as if they gate something.
- Five property-creation paths have **no permission key at all**:

| Ungated creation path | Route | Gate today |
|---|---|---|
| Classic property store | `routes/web.php:2803` (`POST /corex/properties`) | group `permission:access_properties` only |
| Wizard photo upload / finalize | `routes/web.php:2821-2826` | data-scope only |
| Mobile API property create + image upload | `routes/api.php:365`, `:372` | **none** |
| Portal pull (P24/PP → property) | `routes/api.php:345` | **none** |
| Prospecting import (tracked-property create) | `routes/api.php:341` | **none** |
| P24 bulk importer | `routes/web.php:612` | `owner_only` |
| Sold-properties CSV import | `routes/web.php:2806` | `super_admin` |

So the lock is enforced at **four** layers (§9), not one.

### 2.4 Permissions are not visibility — and the brief only solves permissions

`PermissionService::userHasPermission()` answers *may I do X*. It does **not** answer *whose records
do I see*. That is `PermissionService::getDataScope()` → `own | branch | all`, which 23 models
consume in `scopeVisibleTo()`, and `own` universally resolves to `where('<actor column>', $user->id)`.

An Assistant granted `contacts.view` at scope `own` would therefore see **zero of the Agent's
contacts** — only contacts they created themselves. The feature would ship inert.

The Assistant must inherit the Agent's *data identity*, not just their permission ceiling. §7
specifies this. It is the largest piece of the build and it is not optional.

---

## 3. Guiding principles

- **Copy, then subtract.** On assignment the Assistant's matrix is a **copy** of the Agent's
  permissions, all ON (except the property-upload locked set). The Agent switches things OFF. The
  matrix can only ever *subtract* from the Agent's live ceiling — there is no path by which an
  Assistant does something the Agent cannot.
- **Fail closed.** An Assistant with no active assignment, a suspended assignment, a deactivated
  Agent, or a missing matrix row has **no permissions at all** — never "agent defaults". Mirrors
  AT-265's posture (`PermissionService` fails closed on an empty grants table).
- **Ships OFF.** `agencies.assistants_enabled` defaults `false`, exactly as `split_branches_enabled`
  did. Dormant code, no behaviour change for any existing agency.
- **The work belongs to the Agent.** Records an Assistant creates are *owned* by the Agent and
  *audited* as created by the Assistant. Both facts stored; neither inferred.
- **Mirror, don't invent.** The matrix editor mirrors the Role Manager. The invite mirrors
  `UserInviteMail`. The on-behalf-of column mirrors `contact_access_log.impersonator_id`. No new
  abstractions where a pattern exists.
- **No hard deletes.** Revoke = soft delete of the assignment. The matrix rows travel with it.

---

## 4. Scope summary

| Area | Decision |
|------|----------|
| Feature toggle | `agencies.assistants_enabled` (default **false**) |
| Terminology | **Assigned Agent** (never "Sponsor" — §2.1) |
| The `assistant` role | A **standalone role with zero role-grants** (§6.2). Its permissions come entirely from the assignment matrix. |
| Identity flag | `users.is_assistant` (bool, default false) — the resolver hook |
| Relationship | `assistant_assignments` row (Assistant → Assigned Agent), soft-deleted |
| Matrix storage | `assistant_assignment_permissions` (one row per permission key) |
| **Matrix at assignment** | **A copy of the Agent's permissions, all `granted = true`** — except the property-upload locked set. The Agent trims from there. |
| **Matrix on Agent drift (Agent gains a permission later)** | Row auto-added **`granted = false`** + a *"N new permissions available"* chip on the Agent's Assistant page. The Agent turns it on. |
| **Matrix on Agent loss** | Assistant loses it **immediately** — live intersection, no re-snapshot |
| Assistants per Agent | **Many** |
| Agents per Assistant | **One** (v1). Enforced by a generated-column unique index. |
| Agent who is also an Assistant | **Blocked** (§14, E5) |
| Owner/admin as the Assigned Agent | **Blocked** (§14, E6) |
| Property upload | Hard-locked at 4 layers (§9). CMA / market-report upload is **NOT** locked (D3). |
| Data visibility | Assistant inherits the Agent's data identity (§7.2) |
| Record ownership | Stamped to the **Agent**; actor recorded as the Assistant |
| Audit | `on_behalf_of_user_id` on the surfaces in §11 |
| Who can create/assign | **admin + super_admin** by default. The `assistants.*` keys appear in **Role Manager**, so an admin can grant them to `branch_manager` (or any role) without a code change. (D5) |
| FICA | `users.fica_required` (bool). Gates the **existing Compliance tab on My Portal** — no new staff-FICA module (D4). |
| Multi-tenancy | `agency_id` + `branch_id`; `BelongsToAgency` + `BelongsToBranch` |
| Revoke | Soft delete, restorable |
| Invite email | **Reuse `App\Mail\UserInviteMail`** — no new mailable |

---

## 5. Terminology (locked)

| Term | Meaning | Schema |
|---|---|---|
| **Assigned Agent** | The Agent the Assistant works for. Their live effective permissions are the ceiling. | `assistant_assignments.agent_user_id` |
| **Assistant** | The User assigned under an Agent. | `users.is_assistant = true`, `assistant_assignments.assistant_user_id` |
| **Assignment** | The relationship row carrying status + the matrix. | `assistant_assignments` |
| **Matrix** | The per-assignment permission grid the Assigned Agent controls. | `assistant_assignment_permissions` |

The word **"Sponsor" is banned from this feature** — it already means *commission mentor* in
`users.sponsored_by_user_id`. Any variable, column, method, label or comment using it is a bug.

---

## 6. Data model

### 6.1 `users` — new columns

```php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('is_assistant')->default(false)->after('role');
    $table->boolean('fica_required')->default(true)->after('is_assistant');
    $table->index('is_assistant');
});

// Later add (Johan, 2026-07-22):
Schema::table('users', function (Blueprint $table) {
    $table->string('assistant_title', 60)->nullable()->after('fica_required');
});
```

- `is_assistant` — the resolver hook. Set true only by the Assistant creation flow; cleared only
  when an admin explicitly converts the user back to a normal user (§14, E9).
- `fica_required` — see §10. Note: `signature_requests.fica_required` already exists and means
  something unrelated (the per-recipient e-sign gate). Different table, no conflict — but the model
  docblock must say so.
- `assistant_title` — a per-assistant **display label** ("PA", "Receptionist", "Secretary", …),
  entered in the optional **Title** box on the create form. It is a label ONLY: `role` stays pinned
  to `assistant` (H6), so the title never affects permissions. Null falls back to "Assistant" via
  `User::assistantTitle()`. It lives on `users` (not `assistant_assignments`) so it survives
  reassignment. Rendered on the admin Assistants list, the assignment detail header ("PA to \<Agent\>"),
  and anywhere the person's role word is shown. Distinct from `users.designation`, which is the PPRA
  practitioner designation (Candidate / Property Practitioner / Principal) and must not be reused.

### 6.2 `roles` — one seeded row, zero grants

`users.role` is `varchar NOT NULL DEFAULT 'agent'`. A user created without an explicit role **is a
full agent**. So an Assistant *must* carry an explicit role, and that role must grant nothing.

Seed a role `assistant` with `is_owner = false`, `can_be_deleted = false`, and **zero entries in
`role_permissions`**. Every permission an Assistant has comes from the assignment matrix (§7.1) —
the role is an identity label, not a bundle. Because `PermissionService` fails closed on a role with
zero grants (AT-265), the zero-grant role is the last line of defence: if the resolver hook were
ever bypassed, the Assistant gets **nothing**, not everything.

Declared in `config/corex-permissions.php` → `'role_defaults' => ['assistant' => ['include' => []]]`.

### 6.3 New table — `assistant_assignments`

```php
Schema::create('assistant_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
    $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    $table->foreignId('assistant_user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();  // the Assigned Agent

    $table->enum('status', ['active', 'suspended', 'revoked'])->default('active');
    $table->string('suspend_reason', 190)->nullable();
    $table->timestamp('snapshot_taken_at')->nullable();

    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('revoked_at')->nullable();
    $table->string('revoke_reason', 190)->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['agency_id', 'status']);
    $table->index(['agent_user_id', 'status']);
    $table->index(['assistant_user_id', 'status']);
});
```

**One active Agent per Assistant** is enforced in the database, not in a controller check that can
lose a race. MySQL has no partial index, so use a generated column:

```php
DB::statement("
    ALTER TABLE assistant_assignments
    ADD COLUMN active_assistant_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(status = 'active' AND deleted_at IS NULL, assistant_user_id, NULL)) STORED,
    ADD UNIQUE KEY assistant_one_active_agent (active_assistant_user_id)
");
```

Table-shape mirror: `prospecting_claims`
(`database/migrations/2026_03_18_140000_create_prospecting_claims_table.php`).

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
    $table->string('scope', 10)->nullable();      // own|branch|all — only for keys ending .view
    $table->boolean('is_locked')->default(false); // property-upload set: never editable, never granted

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['assistant_assignment_id', 'permission_key'], 'aap_assignment_key_unique');
    $table->index('permission_key');
});
```

Short FK/index names are mandatory — MySQL's 64-char identifier limit bites on these table names
(same reason `contact_access_log` needed `cal_impersonator_fk`).

**Reassignment archive:** no separate archive table. Reassignment soft-deletes the old assignment
(its permission rows travel with it via a model `deleting` hook) and creates a new one. The
soft-deleted assignment *is* the archive — restorable, queryable via `withTrashed()`.

### 6.5 `agencies` — the kill switch + the compliance default

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
assistant():     BelongsTo → User::class, 'assistant_user_id'
assignedAgent(): BelongsTo → User::class, 'agent_user_id'
permissions():   HasMany   → AssistantAssignmentPermission::class
createdBy():     BelongsTo → User::class, 'created_by_user_id'
scopeActive($q)            → where('status', 'active')
grants(string $key): bool  → matrix lookup, false when absent (fail closed)
scopeFor(string $key): ?string

// App\Models\User — additions
assistantAssignment(): HasOne   // the assistant's own active assignment
    → hasOne(AssistantAssignment::class, 'assistant_user_id')->active()
assistantAssignments(): HasMany // the agent's assistants
    → hasMany(AssistantAssignment::class, 'agent_user_id')->active()
assignedAgent(): ?User          // $this->assistantAssignment?->assignedAgent
isAssistant(): bool             // is_assistant AND an active assignment exists (guards a stale flag)
hasAssistants(): bool           // drives the conditional nav entry
dataIdentityIds(): array        // §7.2
ownershipUserId(): int          // §7.2
```

`AssistantAssignmentPermission` carries `agency_id` + `BelongsToAgency` (so `AgencyScope` applies to
it directly — mirror: `viewing_pack_properties`) but **not** `BelongsToBranch`: it is a child of the
assignment and inherits the assignment's branch implicitly.

---

## 7. The resolver

There are **two** resolution questions and the brief only answers the first.

### 7.1 May I do X? — `PermissionService::userHasPermission()`

Single choke point: `app/Services/PermissionService.php:303`. Insert the assistant hook **after**
the owner bypass (an Assistant can never hold an owner role) and **before** the role lookup — so an
Assistant's `users.role` value is never consulted for grants:

```
userHasPermission(user, key):
    if user.isOwnerRole():            return true            # existing, unchanged
    if user.is_assistant:             return AssistantPermissionResolver::allows(user, key)
    ... existing role resolution unchanged ...

AssistantPermissionResolver::allows(assistant, key):
    if not assistant.agency.assistants_enabled:   return false    # kill switch → fail closed
    assignment = active assignment for assistant                  # request-cached
    if assignment is null:                        return false    # fail closed
    if assignment.status != 'active':             return false    # suspended → frozen
    if key in PROPERTY_UPLOAD_LOCKED_SET:         return false    # HARD LOCK — before the matrix
    agent = assignment.assignedAgent
    if agent is null or not agent.is_active:      return false    # agent gone → frozen
    if not assignment.grants(key):                return false    # the matrix
    return PermissionService::userHasPermission(agent, key)       # the live ceiling
```

The recursion terminates because an Assigned Agent can never be an assistant (§14, E5) and never an
owner (§14, E6).

### 7.2 Whose records do I see? — the data-identity problem

Every one of the 23 `scopeVisibleTo()` implementations resolves an `own` scope as
`where('<actor column>', $user->id)`. An Assistant's `own` must mean **the Agent's own**.

```php
/**
 * User ids whose records this user may see under an 'own' data scope.
 * Normal user: [self]. Assistant: [assigned agent, self].
 */
public function dataIdentityIds(): array

/**
 * The user id a record this user creates is OWNED by.
 * Normal user: self. Assistant: the Assigned Agent — so commission, "My Listings",
 * pipeline and targets all land on the Agent, which is the entire point.
 */
public function ownershipUserId(): int
```

Every `scopeVisibleTo()` `own` branch changes from

```php
return $query->where('agent_id', $user->id);
```
to
```php
return $query->whereIn('agent_id', $user->dataIdentityIds());
```

**23 models** carry `scopeVisibleTo`: `Property`, `Contact` (via `ContactScope`), `Deal`,
`DealV2\DealV2`, `Presentation`, `Rental`, `ListingStock`, `DailyActivity`, `DocumentFiling`,
`PortalLead`, `CommercialEvaluation`, `SharedDrive`, `AiConversation`, `SalesDocumentSend`,
`Outreach\OutreachQueue`, `Communications\Communication`, `CommandCenter\CalendarEvent`,
`CommandCenter\CommandTask`, and 6 under `Docuperfect\` (`Template`, `Document`, `Pack`, `Clause`,
`LeaseRecord`, `SignatureTemplate`).

They do **not** all use the same actor column (`agent_id`, `user_id`, `created_by_user_id`), so this
is a per-model edit, not a find-and-replace.

**And it must not rot.** Mirror `tests/Feature/Branches/BranchSplitIsolationTest.php`: a coverage
test that reflects over every model defining `scopeVisibleTo()` and fails the suite if its `own`
branch calls `->where(…, $user->id)` instead of `->whereIn(…, $user->dataIdentityIds())`, unless the
model is on an explicit `PRIVATE_TO_SELF` allowlist **with a written reason**.

`AiConversation` is the first allowlist entry: an Assistant must **not** read the Agent's private
Ellie conversations. Not everything the Agent can see is something the Agent meant to delegate.

VIEW breadth clamps to the Agent's; MUTATION is separately pinned to the Agent's **own** records:

```
# VIEW / LIST breadth — what the Assistant may SEE (getDataScope, scopeVisibleTo, model binding)
getDataScope(assistant, module):
    matrixScope = assignment.scopeFor(module + '.view')   # what the Agent granted
    agentScope  = getDataScope(assignedAgent, module)     # the live ceiling
    if agentScope is null: return null
    if matrixScope is null: return null                   # the Agent did not hand this module over
    return clampScope(matrixScope, agentScope)            # the Assistant SEES what the Agent sees

# MUTATION breadth — what the Assistant may EDIT/DELETE (per-record write guards only)
mutationScope(assistant, module):
    scope = getDataScope(assistant, module)
    if scope is not null: return clampScope(scope, 'own')  # pinned to the Agent's OWN book
    return null
```

`clampScope()` already implements the ceiling semantic. Reuse it; do not write a second one.

**View wide, edit narrow (Johan's ruling, 2026-07-20).** An Assistant SEES exactly what their
Assigned Agent sees — if the Agent is a Branch Manager or Admin whose module scope is `branch` or
`all`, the Assistant sees the branch / agency, so they can find and open the records their Agent
works with. But an Assistant may **EDIT only the Agent's own records**, never another agent's item
even one they can see. An Assistant is a proxy for **one person**, not for that person's authority
over other people's records.

- **VIEW** stays at the Agent's breadth: `AssistantPermissionResolver::dataScope()` = `clampScope(matrix, agentScope)`. No `own` cap. Drives `scopeVisibleTo()`, the global `ContactScope`, and route-model binding.
- **EDIT** is pinned to the Agent's own book: `PermissionService::mutationScope()` caps the Assistant to `own`, which — resolved through `dataIdentityIds()` = `[agent_id, self_id]` — is exactly the Agent's own records. Used only by the per-record write guards: `AuthorizesPropertyAccess`, `AuthorizesDealAccess` (write paths; the read-only deal log uses `getDataScope`), `AuthorizesContactAccess` (new — Contacts had no per-record write guard because they rode the view-scoped binding, which is only safe while view and edit breadth match), and the mobile `ResolvesMobileDataScope::authorizePropertyAccess`.

For a normal Agent (own scope), view and edit are both `own` and nothing changes. The split only
bites when the Assigned Agent is a Branch Manager or Admin — then the Assistant sees the team's
records but can only edit the Agent's own.

---

## 8. Permissions — `config/corex-permissions.php`

New section `assistants`. Entry shape is the config's own (`key`/`label`/`section`/`type`/`module`/
`sort_order` — note there is **no `description` field**, contrary to the brief):

```php
// ── Assistants ──
['key' => 'assistants.view',     'label' => 'View Assistants',            'section' => 'assistants', 'type' => 'access', 'module' => 'assistants', 'sort_order' => 1],
['key' => 'assistants.create',   'label' => 'Create & Assign Assistant',  'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 2],
['key' => 'assistants.reassign', 'label' => 'Reassign to Another Agent',  'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 3],
['key' => 'assistants.revoke',   'label' => 'Revoke Assistant Access',    'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 4],
['key' => 'assistants.view_all', 'label' => 'View All Assistants (agency-wide)', 'section' => 'assistants', 'type' => 'access', 'module' => 'assistants', 'sort_order' => 5],
```

**Role defaults (D5):**

```php
'assistant'      => ['include' => []],   // zero grants — §6.2
'super_admin'    => '*',                 // automatic
'admin'          => // all-minus-exclude → inherits every assistants.* key automatically. No edit needed.
'branch_manager' => // NOTHING by default
```

Because the keys are declared in `config/corex-permissions.php`, they appear **automatically as a
new "Assistants" section in the Role Manager** for every non-owner role. An admin can therefore
grant `assistants.*` to `branch_manager` (or any other role) from the UI, with **no code change** —
which is exactly what Johan asked for. Nothing is granted to `branch_manager` out of the box.

**`assistants.manage-own` is deliberately NOT a permission.** The Agent's right to edit their own
Assistant's matrix is an *ownership* right, not a *role* right — it derives from
`AssistantAssignment.agent_user_id === auth()->id()`, the same way "edit my own profile" derives
from being that user. Making it grantable creates a nonsense state (an agent who has an assistant
but cannot configure them, so the assistant sits with a matrix nobody can fix without an admin).
Enforced in the controller as `abort_unless($assignment->agent_user_id === $user->id, 403)`.

Carried to every environment by the existing `deploy:sync-reference-data` →
`corex:sync-permissions --merge-defaults`.

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
    'mic.merge_duplicates',     // merging tracked properties

    // Dead keys — locked anyway, so they can never be revived into a hole
    'create_properties',
    'publish_properties',
    'listings.create',
    'listings.edit',
],
```

**Deliberately NOT locked (D3):** `mic.upload_reports` (CMA / market-report upload) and
`mic.edit_address`. These are intelligence capture, not putting a listing on the agency's books —
and they are precisely the drudge work an Assistant exists to absorb. An Assistant may upload CMAs
and market reports. Only paths that create an *agency-stock listing* are locked.

The four layers — a slug list alone is provably insufficient (§2.3):

1. **Resolver** — `AssistantPermissionResolver::allows()` returns `false` for any key in the set,
   checked *before* the matrix (§7.1). An Assistant can never hold these, whatever the matrix says.
2. **Route middleware** — `App\Http\Middleware\DenyAssistantPropertyWrite`, aliased
   `deny_assistant_property_write` in `bootstrap/app.php`, applied to the **keyless** paths the
   resolver cannot see: `POST /corex/properties`, the wizard mutation routes
   (`routes/web.php:2821-2826`), `POST /api/v1/mobile/properties` + `/{property}/images`,
   `POST /api/v1/properties/pull-from-portal`, `POST /api/v1/prospecting/import`. Returns 403 with a
   plain-language message. (The P24 importer and sold-CSV import are already `owner_only` /
   `super_admin` — unreachable by an Assistant; no change needed, but the regression test asserts it.)
3. **Matrix UI** — locked rows render disabled, unchecked, with a `title=` tooltip: *"Property upload
   is switched off for all assistants by CoreX. Only the agent can create a listing."* (STANDARDS.md
   "No Silent Locks" — the lock states why. It has no unlock path, and saying so plainly is the
   correct UX, not a dead button.)
4. **Server-side matrix validation** — `AssistantMatrixController::save()` strips any locked key from
   the payload before writing, and `AssistantAssignmentPermission::saving()` forces `granted = false`
   when `is_locked = true`. A hand-crafted POST cannot grant a locked key.

---

## 10. Assistant profile page — section-by-section

The agent profile is **`/my-portal`** (`agent.portal`) →
`app/Http/Controllers/Agent/AgentPortalController.php` + `resources/views/agent/portal.blade.php`
(1415 lines, hash-driven Alpine tabs). `/profile` is a 301 redirect to `/my-portal#profile`.

**D4 (Johan):** there is no separate staff-FICA module to build. The compliance surface Johan means
is the **existing Compliance tab on My Portal** (`portal.blade.php:1110-1209`). `users.fica_required`
gates that tab for Assistants. Nothing new is built except the missing Proof of Residence card.

| Tab / Section | portal.blade.php | Assistant sees? | Why |
|---|---|---|---|
| **Overview** tab | :126-304 | reduced | |
| — My Earnings (commission ledger) | :132-155 | **HIDE** | Assistants have no commission |
| — Compliance Overview | :158-185 | **SHOW** iff `fica_required` | |
| — My Presentations | :187-220 | HIDE | |
| — Recent Activity (commission ledger) | :222-263 | HIDE | |
| — Training Progress | :265-284 | **SHOW** | Training applies to staff |
| — Admin Access Log (impersonation) | :286-303 | **SHOW** | POPIA — their own access log |
| **Profile** tab | :306-641 | reduced | |
| — Public agent page preview | :312-320 | HIDE | Assistants have no public page |
| — **Profile Photo upload** | :322-338 | **SHOW** | Brief requirement |
| — Profile Information (name/email/phone/cell/ID number) | :340-424 | **SHOW** | |
| — FFC Number + FFC Expiry inputs | :407-423 | **HIDE** | Assistants are not PPRA practitioners (brief: "no PPRA fidelity fund cert") |
| — Public Website Profile (about_me, socials) | :426-454 | HIDE | |
| — Admin Managed block (designation, role, branch) | :456-493 | **SHOW** read-only + **"Assistant to: \<Agent\>"** | |
| — PPRA Status | :479-491 | **HIDE** | Not a practitioner |
| — Branches I Manage | :502-542 | HIDE | |
| — Articles CRUD | :544-640 | HIDE | |
| **Tools** tab | :643-946 | theme only | HIDE agent QR, ad accounts, WhatsApp capture — agent-marketing artefacts |
| **Documents** tab | :948-1108 | reduced | |
| — FFC Certificate card | :954-955 | **HIDE** | |
| — **ID Copy card** | :956-957 | **SHOW** | Brief requirement |
| — PI Insurance card | :958-959 | HIDE | Practitioner cover |
| — Tax Clearance card | :960-961 | HIDE | |
| — **Profile Photo card** | :962-963 | **SHOW** | |
| — **Proof of Residence card** | — | **SHOW — MUST BE BUILT** | `UserDocument::DOCUMENT_TYPE_PROOF_OF_ADDRESS` exists (`app/Models/UserDocument.php:24`) and renders admin-side, but **has no portal card**. New entry in `$docTypeConfig`. |
| **Compliance** tab | :1110-1209 | **SHOW** iff `fica_required = true` | Items reduce to: ID Copy, Proof of Residence, RMCP Acknowledgement, Employee Screening. FFC / PI / Tax / PPRA rows suppressed. |
| **Training** tab | :1211-1293 | **SHOW** | |
| **Password** tab | :1295-1361 | **SHOW** | |
| — Delete Account danger zone | :1340 | **HIDE** | Admin-only action for assistants |
| **Payslips** tab | :1363-1395 | HIDE (v1) | Already gated `view_own_payslips`; the Agent cannot grant what they don't have. Open: §21. |
| **Leave** tab | :1397-1410 | HIDE (v1) | Already gated `apply_for_leave`. Open: §21. |
| Commission / bank details / qualifications | not on portal today | N/A | Bank details = Payroll only; qualifications = a constant with no card |

Implementation: a single `$isAssistant` flag from `AgentPortalController::index()` plus an
`@unless($isAssistant)` wrapper per hidden section. **Not** a forked Blade view — a fork would drift
within a month.

`computeComplianceStatus()` (`AgentPortalController.php:486-630`) skips FFC / PI / Tax / PPRA items
for an Assistant, and returns empty when `fica_required = false` (tab hidden, compliance dashboards
skip the user, FICA reminders suppressed).

---

## 11. Audit — `on_behalf_of_user_id`

There is **no `spatie/laravel-activitylog`** and no generic activity table — ~17 bespoke audit
tables. The precedent for exactly this problem already exists (AT-118):
`contact_access_log.impersonator_id`, written from `App\Support\Impersonation::actingAdminId()`.

**Mirror it.** New helper, same shape:

```php
// app/Support/ActingFor.php
class ActingFor
{
    /** The Assigned Agent's id when the authed user is an assistant; null otherwise. Session/console-safe. */
    public static function onBehalfOfUserId(): ?int;
}
```

Column, everywhere, mirroring the `contact_access_log` migration (short FK name — 64-char limit):

```php
$table->foreignId('on_behalf_of_user_id')->nullable()->after('<actor column>')
    ->constrained('users', 'id', '<short>_obo_fk')->nullOnDelete();
```

| # | Table | Actor column it sits beside | Write site |
|---|---|---|---|
| 1 | **`domain_event_log`** | `actor_user_id` | The audit listener — **highest leverage: the one central, cross-pillar log** |
| 2 | `property_audit_log` | `user_id` | `app/Services/Audit/PropertyAuditService.php:36` (single writer — one edit) |
| 3 | `deal_logs` (DR1) | `actor_user_id` | private `logDealEvent()` — duplicated across 6 controllers/listeners. **Extract to one writer first**, then add the column. |
| 4 | `deal_activity_log` (DR2) | `user_id` | inline `create()` in 5 controllers — same: extract, then add |
| 5 | `signature_audit_log` (e-sign) | `actor_id` + `actor_type` | `SignatureAuditLog::log()` — single static writer, ~25 call sites, one signature edit |
| 6 | `calendar_event_audit_log` | `performed_by_user_id` | `CalendarEventAuditEntry::create()` |
| 7 | `legal_block_audit_log` | `user_id` | `Docuperfect/Template.php:369` |
| 8 | `comms_access_audit_log` | `actor_user_id` | `CommsAccessAuditLog::record()` — already injects `acting_as_admin_id` into its `detail` JSON; add the real column |
| 9 | `marketing_share_log` | `user_id` | raw `DB::table()->insert()` in `PropertyAuditService.php:98` |
| 10 | `contact_access_log` | `user_id` (+ existing `impersonator_id`) | `LogsContactAccess.php:66` — **distinct concept**, keep both columns |

`esign_consent_log` and `deal_document_access_log` are **excluded** — their actor is the external
signer/recipient, never a staff user.

Close a latent gap in the same pass: `ContactAccessLog.impersonator_id` is fillable but has **no
`impersonator()` BelongsTo relation**, so nothing can render it. Add it alongside the new
`onBehalfOf()` relation on every model above.

**The rule that stops this rotting:** any new audit table carrying an actor column ships with
`on_behalf_of_user_id`. Enforced by an `AuditActorCoverageTest` mirroring `BranchSplitIsolationTest`
— reflect over models whose table has an actor column, fail if `on_behalf_of_user_id` is absent and
the model is not on an explicit `NO_STAFF_ACTOR` allowlist.

---

## 12. Routes, controllers, views, navigation

### Admin surface

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

```php
User::create([
    ...,
    'password'          => 'INVITE_PENDING',
    'is_active'         => true,
    'email_verified_at' => null,
    'role'              => 'assistant',
    'is_assistant'      => true,
    'fica_required'     => $agency->assistant_fica_required_default,
]);
Mail::to($user->email)->send(new UserInviteMail($user));
```

That is the **existing** signed-URL, 7-day, `account.setup` invite (`app/Mail/UserInviteMail.php:25`).
**No new mailable, no new template, no new token mechanism.** The Assistant lands on the same
`auth.account-setup` page every other new user does.

### Agent surface (ownership-gated, not permission-gated)

| Method | URI | Name | Gate |
|---|---|---|---|
| GET | `/my-portal/assistants` | `agent.assistants.index` | auth + `hasAssistants()` |
| GET | `/my-portal/assistants/{assignment}/matrix` | `agent.assistants.matrix` | `$assignment->agent_user_id === auth()->id()` |
| POST | `/my-portal/assistants/{assignment}/matrix` | `agent.assistants.matrix.save` | same |

Controller `app/Http/Controllers/Agent/AssistantMatrixController.php`.

### Navigation (Non-negotiable #2 — same day)

**Agent's sidebar**, in the `Agents` section directly under **My Portal**
(`resources/views/layouts/corex-sidebar.blade.php:522-542`). Conditional on a *data* condition,
mirroring the established precedent at `:641` (permission + data check) and the cached-count pattern
at `:573-590`:

```blade
@php
    $_hasAssistants = cache()->remember(
        'assistants.agent.'.$user->id, 60,
        fn () => \App\Models\AssistantAssignment::where('agent_user_id', $user->id)->active()->exists()
    );
@endphp
@if($_hasAssistants && ($_userAgency?->assistants_enabled))
    <a href="{{ route('agent.assistants.index') }}"
       class="corex-nav-item {{ request()->routeIs('agent.assistants.*') ? 'active' : '' }}">
        <svg …/><span>My Assistants</span>
    </a>
@endif
```

Cache key busted on assignment create / revoke / restore / reassign.

**Admin's sidebar**: `Assistants` under the existing **Company** group (`:1482-1515`), beside Role
Manager, gated `@permission('assistants.view')`.

### Views

- `resources/views/admin/assistants/index.blade.php` — list (Assistant · Assigned Agent · Branch · Status · Invite state · Actions)
- `resources/views/admin/assistants/create.blade.php` — mirrors `admin/users/create-edit.blade.php`'s Profile tab + an **Assigned Agent** selector (searchable; filtered to active, non-owner, non-assistant users in the agency) + a **FICA required** toggle (defaulted from the agency setting)
- `resources/views/admin/assistants/show.blade.php` — assignment detail, matrix read-only, audit history
- `resources/views/agent/assistants/index.blade.php` — the Agent's assistant list
- `resources/views/agent/assistants/matrix.blade.php` — **the matrix editor**

### The matrix editor — mirror the Role Manager

Source pattern: `resources/views/corex/role-manager.blade.php` (1117 lines) + the `roleManager()`
Alpine component at `:822-1116` + `RoleManagerController::index()`'s `$matrixSections` grouping
(`:162-183`).

Copy: the two-column layout (left = searchable feature/module list grouped by section; right = the
detail panel for `selectedFeature` only), the `matrix[key]` / `scopeMatrix[key]` Alpine maps, hidden
inputs rendered for the selected feature only, the 800 ms debounced autosave (`scheduleSave()`,
`:972`), the `beforeunload` dirty guard, and the transactional delete-then-chunked-insert save.

Differences:
- Rows are filtered to **only the permissions the Assigned Agent currently holds** — computed
  server-side as `array_filter($allKeys, fn ($k) => $agent->hasPermission($k))`. The Agent cannot
  see, let alone grant, a permission they don't have.
- Scope radios clamp to the Agent's own scope for that module: if the Agent's `contacts` scope is
  `own`, the Assistant's options are `None | Own` — `Branch` and `All` are not rendered.
- Property-upload rows render **disabled + unchecked + tooltipped** (§9 layer 3).
- A **"N new permissions available"** chip when drift has added `granted = false` rows (§14, E4),
  linking to a filtered view of just those rows.
- No role switcher, no copy-from-role, no bulk copy. One assignment at a time.

---

## 13. Agency settings + the Setup Wizard (Non-negotiable #10a)

| Setting | Column | Wizard step |
|---|---|---|
| Allow agents to have assistants | `agencies.assistants_enabled` (default false) | **Your branches / Your team** |
| New assistants must complete FICA verification | `agencies.assistant_fica_required_default` (default true) | same step |

`config/agency-onboarding-copy.php`, entry shape `['key','source'=>'agency','type'=>'toggle','default','explain','affects']`:

```php
['key' => 'assistants_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
 'label' => 'Allow agents to have assistants',
 'explain' => 'An assistant is a person who works for one of your agents. They get their own login and start with a copy of that agent\'s permissions, which the agent can then switch off item by item. An assistant can never do more than the agent they work for, and can never create a listing.',
 'affects' => 'What this changes: an "Assistants" page appears under Company for your admins, and any agent who has an assistant gets a "My Assistants" entry in their sidebar to control what that assistant may do.'],

['key' => 'assistant_fica_required_default', 'source' => 'agency', 'type' => 'toggle', 'default' => 1,
 'label' => 'New assistants must complete FICA verification',
 'explain' => 'Whether a newly-created assistant is asked for their identity documents — an ID copy and proof of residence — as part of their onboarding.',
 'affects' => 'What this changes: when on, a new assistant sees a Compliance tab on their profile asking for an ID copy and proof of residence, and appears on your compliance dashboards. When off, that tab is hidden and they are skipped by compliance reminders.'],
```

**Read `.ai/specs/agency-onboarding-setup.md` §6.1 before wiring the saver** — a wizard step posts a
*subset* of the saver's fields, and a saver that coerces an absent checkbox to `false` silently wipes
settings the step never rendered. Both writes must be guarded with `$request->has()`.

---

## 14. Edge cases — every one resolved

| # | Case | Decision | Justification |
|---|---|---|---|
| **E1** | Assigned Agent deactivated (`is_active = false`) or soft-deleted | **Freeze, not cascade.** Assignment → `status = 'suspended'`, `suspend_reason = 'agent_deactivated'`. The Assistant keeps their login, has **zero permissions**, and sees a banner: *"Your agent's account is inactive. Ask an administrator to reassign you."* Reactivating the Agent auto-restores `status = 'active'` with the matrix intact. | Cascade-revoking would destroy the matrix on a temporary suspension. Freezing is reversible. The resolver already fails closed on an inactive agent (§7.1), so the freeze is defence-in-depth, not the only guard. |
| **E2** | Agent transferred to another branch | The assignment's `branch_id` **follows the Agent**, updated in the same transaction as the user's branch change (`BranchAssignmentController`). | Branch-isolation doctrine: "all historical data follows the user". An assistant stranded in the old branch would see nothing. |
| **E3** | Agent demoted / loses a permission | The Assistant loses it **on the next request**. No schema change, no re-snapshot. | The intersection is evaluated live against `PermissionService::userHasPermission($agent, $key)` (§7.1). This is why we intersect rather than copy-and-forget. |
| **E4** | Agent **gains** a permission after assignment (Johan's Ad Manager example) | Row auto-added to the matrix as **`granted = false`**. A nightly `assistants:sync-matrix` command inserts missing rows; the Agent's Assistant page shows a *"N new permissions available"* chip. **The Agent turns it on.** | Johan's ruling. The Assistant follows the Agent's *ceiling* automatically, but a brand-new capability is handed over consciously, never silently. |
| **E5** | A user is both an Assigned Agent **and** an Assistant to someone else | **BLOCKED.** Validation on create + assign: the chosen Agent must have `is_assistant = false`; a user with active `assistantAssignments()` cannot be converted into an assistant. | Chained delegation makes the audit story unprovable ("who authorised this, really?") and makes the resolver recursive. |
| **E6** | The chosen Assigned Agent holds an **owner** role | **BLOCKED.** The Agent selector filters to non-owner roles; `store()` re-validates. | `userHasPermission()` returns `true` unconditionally for owners (`PermissionService.php:306`). An owner agent would make the matrix the *only* limit — the intersection would be with "everything", and one mis-ticked box would hand an assistant super-admin powers. |
| **E7** | One Assistant, multiple Agents | **BLOCKED for v1.** Enforced by the generated-column unique index (§6.3), not just a controller check. | One `on_behalf_of_user_id` per action, no ambiguity about which agent's book a record lands in. |
| **E8** | One Agent, multiple Assistants | **ALLOWED.** | No ambiguity — each assistant has one agent. |
| **E9** | Reassignment to a different Agent | Old assignment soft-deleted (matrix rows travel with it, restorable). New assignment created with a **fresh copy of the new Agent's permissions** (all ON except the locked set), per §4. | The old matrix is meaningless against a different permission ceiling. A fresh copy is the same rule as a first assignment — consistent, and the new Agent trims it themselves. |
| **E10** | Assistant acts outside the Agent's branch | Blocked by `BranchScope` (the Assistant's `branch_id` = the Agent's) and audited as a denial. | Existing mechanism; no new code. |
| **E11** | `fica_required` flipped **off** while documents are already uploaded | Documents **retained** (soft, never deleted). Compliance tab hidden from the Assistant. Compliance officers still see the documents via `admin.user.documents.*`. | No hard deletes, ever. The docs were lawfully collected; hiding the tab is a UI decision, not a retention decision. |
| **E12** | `fica_required` flipped **on** later | Compliance tab appears; the user enters the normal onboarding state; existing documents show with their existing status. | Idempotent — the tab is derived, never a stored state machine. |
| **E13** | Record ownership | Records the Assistant creates are stamped `agent_id`/`user_id` = **the Assigned Agent** (`ownershipUserId()`); the audit row records the Assistant as actor + the Agent as `on_behalf_of_user_id`. | "All work traceable back to the agent." Also the only correct answer for commission: a deal captured by an assistant is the *Agent's* deal and must hit the Agent's ledger, targets and pipeline. |
| **E14** | Assistant reads the Agent's private Ellie conversations | **BLOCKED.** `AiConversation` is on the `PRIVATE_TO_SELF` allowlist (§7.2). | Not everything the Agent can see is something the Agent meant to delegate. |
| **E15** | `assistants_enabled` flipped off while assignments exist | Resolver fails closed — assistants get **zero** permissions; the nav entry disappears; the assignments are untouched. Flipping back on restores everything. | Same reversible-toggle doctrine as `split_branches_enabled`. |

---

## 15. Domain events (Non-negotiable #9)

Per `.ai/specs/corex-domain-events-spec.md` — past-tense facts, uniform payload:

| Event | Emitted when | Payload | Listeners |
|---|---|---|---|
| `Assistant\AssistantAssigned` | Assignment created | `assignment`, `assistantUserId`, `agentUserId`, `agencyId` | audit; bust the sidebar cache; notify the Agent |
| `Assistant\AssistantMatrixChanged` | Agent saves the matrix | `assignment`, `changedKeys`, `actorUserId` | audit — this is a permission change and must be logged |
| `Assistant\AssistantReassigned` | Assigned Agent changed | `assignment`, `oldAgentId`, `newAgentId` | audit; notify both Agents |
| `Assistant\AssistantRevoked` | Assignment revoked | `assignment`, `revokedByUserId`, `reason` | audit; bust cache; notify |
| `Assistant\AssistantSuspended` | Agent deactivated (E1) | `assignment`, `reason` | audit; notify the Assistant |

**Registration:** listeners MUST be explicitly registered in `AppServiceProvider::boot()` — event
discovery is **OFF** in this codebase (AT-261). Listeners on domain events must stay **synchronous**:
a queued listener on an `AbstractDomainEvent` fatals (the parent's readonly `$eventId` cannot be
restored from the child scope). If work must be async, the sync listener dispatches a Job carrying
scalars.

---

## 16. Build sequencing

Each prompt follows **investigate → report → approve → fix**. Each ends with `php -l` on every
changed file, `php artisan view:clear`, the **single most relevant test file** (never a broad suite —
CLAUDE.md #13), and Tinker functional verification. No prompt is done until it is pushed.

| # | Prompt | Description |
|---|--------|-------------|
| **A** | Schema + models | The migrations (§6.1, §6.3, §6.4, §6.5), the generated-column unique index, `AssistantAssignment` + `AssistantAssignmentPermission` models with `BelongsToAgency`/`BelongsToBranch`/`SoftDeletes`, the `User` relationships (§6.6). Then `DB_DATABASE=hfc_dash_test php artisan schema:dump` (**never** a plain `schema:dump` — it reads the stale dev DB and silently drops tables) and commit the snapshot with the migration. No behaviour yet. |
| **B** | Permissions + the zero-grant role | `config/corex-permissions.php` — the `assistants` section (§8), `role_defaults` for `assistant` (empty). Verify `corex:sync-permissions --merge-defaults` is idempotent, that the new section renders in Role Manager, that `branch_manager` can be granted `assistants.*` from the UI, and that a user with `role = 'assistant'` and no matrix resolves to **zero** permissions. |
| **C** | The resolver | `AssistantPermissionResolver` + the two hooks in `PermissionService` (§7.1, §7.2). `config/assistants.php` with the locked set. Request-level caching of the active assignment. **Tests first**: no assignment → 0 perms; suspended → 0; agent inactive → 0; locked key → false even when the matrix says true; matrix true + agent false → false; agent loses a perm → assistant loses it. |
| **D** | Data identity | `User::dataIdentityIds()` + `ownershipUserId()`. Sweep all 23 `scopeVisibleTo()` models. Add `AssistantVisibilityCoverageTest` (mirror `BranchSplitIsolationTest`) with the `PRIVATE_TO_SELF` allowlist. **Biggest prompt — may split into D1 (helpers + coverage test, all models allowlisted) and D2..Dn (drain the allowlist module by module).** |
| **E** | Property-upload hard lock | `DenyAssistantPropertyWrite` middleware on the keyless routes (§9 layer 2) + the `saving()` guard on the matrix model (layer 4). Test every path in the §2.3 table returns 403 for an assistant — including the mobile API and the portal-pull endpoint. |
| **F** | Admin CRUD | `AssistantController` + the four Blade views. Create → `User::create` with `INVITE_PENDING` → `UserInviteMail`. Reassign, revoke, restore, resend-invite. Agent selector filtered per E5/E6. Nav entry under Company. |
| **G** | Snapshot + drift | `AssistantMatrixSnapshotService::snapshot($assignment)` — one row per permission the Agent holds, **`granted = true`**, locked-set rows `granted = false, is_locked = true`. `assistants:sync-matrix` scheduled command for drift (E4, `granted = false`) + the "N new permissions available" chip. |
| **H** | The matrix editor | `AssistantMatrixController` + `agent/assistants/matrix.blade.php`, mirroring the Role Manager (§12). Filtered to the Agent's own permissions; scopes clamped; locked rows disabled + tooltipped. The conditional "My Assistants" sidebar entry. |
| **I** | Assistant profile | `$isAssistant` through `AgentPortalController::index()`; the `@unless` wrappers per §10; the **new Proof of Residence card**; `computeComplianceStatus()` reduced for assistants and empty when `fica_required = false`. |
| **J** | Audit — `on_behalf_of_user_id` | `App\Support\ActingFor`. The column + relation on the 10 tables in §11. **Extract `logDealEvent()` and the DR2 inline `create()`s to single writers first** — do not add the column to 11 duplicated call sites. `AuditActorCoverageTest`. |
| **K** | Setup Wizard + agency settings | §13 — both controls with `explain` + `affects`, savers guarded with `$request->has()` per `agency-onboarding-setup.md` §6.1. Company Settings toggles. |
| **L** | Domain events | The five events + listeners (§15), registered explicitly in `AppServiceProvider::boot()`, sync-only. |
| **M** | Canary | §17. |
| **N** | Regression matrix | §18. |

**Parallelism:** D and E can run alongside F/G/H once C has landed. J is independent of everything
after A. K is independent throughout.

---

## 17. Canary plan

QA1 first (`qatesting1.corexos.co.za`, branch `QA1`) — a real-data snapshot, `APP_ENV=qa`, outbound
neutralised, so an accidental invite email cannot reach a real person. **The invite flow must be
verified on Staging, not QA1** — QA is web-only and `MAIL_MAILER` is neutralised there.

**Johan creates the pilot pair himself (D6).** The build's job is to make that safe:

1. `assistants_enabled = true` for **HFC only**. Every other agency stays `false`.
2. Johan creates the assistant and assigns the agent. The matrix arrives as a **full copy** of that
   agent's permissions, minus the property-upload locked set.
3. The agent trims what they don't want handed over, from their own **My Assistants** page.
4. Verify daily for one week: (a) every record the assistant creates lands on the **agent's**
   dashboard, ledger and pipeline; (b) every audit row shows assistant-as-actor +
   agent-as-`on_behalf_of_user_id`; (c) the assistant cannot reach any property-create path — walk
   all 7 rows of the §2.3 table by URL; (d) the assistant sees the agent's contacts and **not** the
   agent's Ellie conversations.
5. Only then offer the toggle to other agencies.

---

## 18. Acceptance criteria

1. An admin can create an assistant, pick an Assigned Agent, and the assistant receives the
   **existing** CoreX invite email and sets their own password via `account.setup`.
2. On assignment the assistant's matrix is a **copy** of the agent's permissions, all ON except the
   property-upload locked set, which is OFF and disabled.
3. The agent can switch any of those off from **My Assistants**, and the assistant loses them on the
   next request.
4. The "My Assistants" entry appears **only** while the agent has an active assistant.
5. The matrix shows **only permissions the agent holds**, with scopes clamped to the agent's own scope.
6. The assistant sees the **agent's** contacts, properties and tasks — not an empty list. (This is
   the §2.4 test. If it fails, the feature is inert.)
7. A contact/task/document created by the assistant is **owned by the agent** and appears on the
   agent's dashboard.
8. Every audit row for that action carries `on_behalf_of_user_id = <agent>`.
9. The assistant cannot create a property via **any** of the 7 paths in §2.3 — matrix, direct URL,
   mobile API, portal pull, prospecting import, importer, CSV.
10. The assistant **can** upload a CMA / market report (D3).
11. When the agent is granted a new permission, it appears in the assistant's matrix **switched off**,
    with the "N new permissions available" chip. Turning it on grants it.
12. Deactivating the agent freezes the assistant (zero permissions, banner); reactivating restores the
    matrix intact.
13. Revoke → restore round-trips with the matrix intact. No hard deletes anywhere.
14. `assistants_enabled = false` returns the system to exactly current behaviour, assignments preserved.
15. The assistant's profile shows exactly the sections in §10 and no others; the Compliance tab appears
    only when `fica_required = true`.
16. Both new settings appear in the Setup Wizard with `explain` + `affects`.
17. `AssistantVisibilityCoverageTest` and `AuditActorCoverageTest` pass, every allowlist entry carrying
    a written reason.
18. No new failures in the targeted test files (CLAUDE.md #13 — no broad suite without Johan's go-ahead).

---

## 19. Rollback plan

The feature is inert until switched on, so rollback is a toggle, not a revert.

| Failure point | Rollback |
|---|---|
| **Any prompt, pre-deploy** | The branch is not merged. |
| **A (schema) fails on deploy** | Both migrations have working `down()`. The columns are additive and defaulted — `assistants_enabled = false` means no code path reads them. Roll forward with a fix; drop tables only if the migration itself is malformed. |
| **C (resolver) misbehaves in production** | `UPDATE agencies SET assistants_enabled = 0` — the resolver's first check. Every assistant instantly has zero permissions (fail-closed), which is the safe direction. The kill switch's failure mode is "assistant can't work", never "assistant can do more". |
| **D (data identity) leaks an agent's data to the wrong user** | The only genuinely dangerous prompt. Its rollback is a **code revert** of the `dataIdentityIds()` sweep — the kill switch does not help, because `dataIdentityIds()` returns `[self]` for non-assistants and a bug there affects *everyone*. **Mitigation: D ships with the coverage test and sits on QA1 alone for 48h before Staging.** |
| **E (lock) has a hole** | Add the missing route to the middleware and redeploy. The hole is a missed route, not a design failure — the resolver still denies the slug. |
| **J (audit) breaks a write path** | The column is nullable with `nullOnDelete`. Revert the writer change; the column stays harmlessly empty. |
| **Post-launch, assistant misuse** | `admin.assistants.revoke` — soft delete, instant, restorable. |

**Data safety:** nothing in this build deletes or rewrites existing rows. The only writes to existing
tables are additive nullable/defaulted columns. There is no backfill.

---

## 20. Decisions — signed off (Johan, 2026-07-14)

| # | Decision | Ruling |
|---|---|---|
| **D1** | Terminology — "Sponsor" already means *commission mentor* (`users.sponsored_by_user_id`) | **"Assigned Agent."** The word "sponsor" is banned from this feature. |
| **D2** | The `assistant` role — `users.role` defaults to `'agent'`, so an assistant without an explicit role *is a full agent* | **Yes — a standalone `assistant` role with zero role-grants.** All its permissions come from the assignment matrix. |
| **D3** | Is `mic.upload_reports` (CMA / market-report upload) "property upload"? | **No — leave it UNLOCKED.** Assistants may upload CMAs and market reports. Only agency-stock listing creation is locked. |
| **D4** | What is "the FICA section" for a staff member? | **The existing Compliance tab on My Portal.** No new staff-FICA module. `users.fica_required` gates that tab. |
| **D5** | Who can create and assign an assistant? | **Admin (+ super_admin).** The `assistants.*` keys appear as a new section in **Role Manager**, so access can be granted to `branch_manager` — or any role — from the UI, with no code change. Nothing granted to `branch_manager` by default. |
| **D6** | Agent drift — the agent gains a new permission after assignment | **The row is added to the assistant's matrix switched OFF.** The agent turns it on from their Assistant page. The assistant follows the agent's *ceiling* automatically, but a brand-new capability is handed over consciously. |
| **D7** | Canary pair | **Johan creates it himself.** The build's job is to make that safe (§17). |

---

## 21. Open — not blocking the build

- **Does the agency's RMCP need an Assistants clause?** An assistant handling client identity
  documents under an agent is arguably a person the FIC Act programme should name. This is compliance
  *content*, not code — a new `RmcpSection` in a new RMCP version, a separate small prompt. Johan's
  call as compliance officer.
- **Leave and payslips for assistants.** Hidden in v1 (both are already permission-gated, and an agent
  cannot grant what they don't hold). If the agency *employs* the assistant, leave arguably applies.
  Revisit once payroll knows what an assistant is.

## 22. Deliberately NOT in the Setup Wizard

Nothing. Both new settings (§13) are in the wizard. This section exists so that any *future* assistant
setting deliberately left out is recorded here as a decision, per Non-negotiable #10a.

---

## 23. Activity tracking — the "Activity" tab (added 2026-07-22, Johan)

The matrix page (`agent.assistants.matrix`) gains a second tab. **Permissions** (the existing
switchboard) is what the assistant CAN do; **Activity** is what they HAVE done — so the agent can
check, plainly, that nothing is happening on their book that shouldn't be.

- **Storage — `assistant_activity_log`** (append-only, no soft delete, like `property_audit_log`):
  `agency_id`, `assistant_assignment_id`, `assistant_user_id`, `agent_user_id` (on-behalf-of),
  `action` (opened|edited|created|deleted), `subject_type` (property|contact|deal), `subject_id`,
  `subject_label` (denormalised human label), `route_name`, `url`, `method`, `created_at`. Indexed by
  `(assistant_assignment_id, created_at)`.
- **Writer — `App\Http\Middleware\LogAssistantActivity`** appended to the `web` group. A single
  `is_assistant` bool check makes it a no-op for everyone else, so volume is bounded to assistants.
  For an assistant, a successful (<400) request bound to a `{property}`/`{contact}`/`{deal}` route
  writes one row: `GET → opened`, `PUT/PATCH → edited`, `DELETE → deleted`, `POST → edited` only when
  the route name ends `.update` (so the many small sub-action POSTs stay out). List/index pages
  (no bound record) write nothing. The write is wrapped so a logging failure never breaks the page.
  **This is the ONLY property "open/view" logging in CoreX** — property views were not logged before
  (contrast `contact_access_log`, which already logs contact views).
- **Read** — `AssistantMatrixController::edit()` loads the 200 most recent rows for the assignment,
  newest first; the Activity tab renders them as a dot + "Opened/Edited/Deleted &lt;Type&gt; &lt;label&gt;" +
  relative time, linking to the record. Empty state when there's nothing yet.
- **Not a permission.** The Activity tab is an *ownership* view (the agent seeing their own
  assistant's actions), gated exactly like the matrix itself (`assignment.agent_user_id === auth id`).
- **Switch User.** Assistants already satisfy the impersonation picker's filters (active, non-owner,
  in-agency) and appear in it — the picker now also shows their Title/label so switching into one is
  meaningful. No security guard changed (an assistant was always a valid impersonation target).

Governing spec: this file. No separate spec — extends the Assistants feature.

---

## 24. Ads — all listings, always the listing agent's info (added 2026-07-22, Johan)

An assistant helps market the whole office, so they may build an ad for **any** of the agency's
listings — not just their assigned agent's own book. And because an ad's contact details must credit
the **listing agent** (the property's `agent_id`), never the assistant, the two requirements combine
safely: widening the pool cannot misattribute a listing.

- **Access widened (assistant-only):** the ad generator (`PropertyController@ad`), the printable
  brochure (`@brochure`) and the batch **Ad Manager** (`AdManagerController::adScope()` → `'all'` for
  assistants) skip the per-listing data-scope gate for an assistant. The property is still
  agency-scoped by route-model binding / `AgencyScope`, so an assistant can only ever reach their own
  agency's listings. Everyone else stays scope-gated. (Assistants own no listings, so the old `'own'`
  scope would have left their Ad Manager empty.)
- **Agent info is already the listing agent** everywhere in the ad path (`Property::adData()`,
  `agentAdCard()`, `ad.blade.php` all read `$property->agent`) — `auth()->user()` is never used for ad
  contact. The two spots where a *different* agent could be surfaced are now locked for assistants:
  the brochure `?ad_agent=` footer override is ignored, and `livePreview`'s `agent=me` falls back to
  the listing agent. So an ad an assistant produces can only ever carry the listing agent's details.
- No permission change: reaching the ad still requires `access_properties` (Ad Manager:
  `access_ad_manager`), which the agent grants via the matrix like any other capability.

---

## 25. "N new permissions available" — show once, then clear (fix, 2026-07-22)

The E4 drift banner (§14) counted **every** off, non-locked matrix row as "new", so an assistant
seeded with admin-default-off or agent-trimmed permissions showed a large, permanent count (e.g. "81
new") that never went away. Fixed:

- New `assistant_assignment_permissions.is_new` boolean (default false). Set `true` **only** by a
  drift top-up (`syncDrift` — the agent gained a permission after setup); the initial snapshot and
  locked rows are never `is_new`.
- `pendingDriftCount()` and the per-row NEW badge now read `is_new`, not "any off row".
- `AssistantMatrixController::edit()` builds the view (so the banner + badges render **once**), then
  calls `AssistantMatrixSnapshotService::acknowledgeDrift()` to clear `is_new` — so the next visit is
  clean. The rows stay **off** until the agent turns them on; only the *notice* clears.
- Existing rows backfill to `false`, so any stale banner clears on deploy.

---

End of spec.
