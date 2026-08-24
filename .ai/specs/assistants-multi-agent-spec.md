# Assistants — Multi-Agent (Linked Sub-Agents) Addendum

> Spec file: `.ai/specs/assistants-multi-agent-spec.md`
> Status: **DRAFT — awaiting Johan's explicit sign-off before build.**
> Extends: `.ai/specs/assistants-feature-spec.md` (AT-267) + `.ai/specs/assistant-control-page.md`
> Amends: §6.3, §7.2, §11, §14 (E7), §12, §13/§22 of the base spec.
> Driver: Johan, 2026-07-28 — "an assistant should be assignable to more than one user; there
> should be a main user who decides what roles/permissions the assistant can see, but the
> assistant should get access to the sub users' data as well — e.g. today an assistant can only
> edit the assigned agent's properties, but with sub users they need to edit those properties too."
> Decisions locked in this conversation (2026-07-28): new-record ownership is an explicit
> "Acting for" choice at creation; only admin/super_admin can add or remove a linked sub-agent;
> no sub-agent consent step in v1; the permission ceiling stays keyed to the Main Agent only.

---

## 1. What changes and what doesn't

The base spec locked **E7: "One Assistant, multiple Agents — BLOCKED for v1"**, enforced by a
generated-column unique index on `assistant_assignments`, specifically to keep the audit trail
(`on_behalf_of_user_id`) and the permission ceiling unambiguous — "one action, one agent's book."

That invariant is **not being removed** — it is being narrowed to the thing it was actually
protecting: **there is still exactly one agent whose live permissions define the ceiling.** What
changes is that an assistant can now also see and edit the records of additional agents, without
those agents controlling anything.

| Concept | Base spec (AT-267) | This addendum |
|---|---|---|
| **Main Agent** (renamed from "Assigned Agent" for clarity in this doc — same column, same relationship) | The one agent. Controls the matrix. Defines the permission ceiling. | **Unchanged.** Still exactly one, still `assistant_assignments.agent_user_id`, still the unique-index-enforced 1:1. |
| **Sub-Agent(s)** | Did not exist. | **New.** Zero or more additional agents whose records the assistant may see and edit, via a new table. They grant **no additional permissions** and control nothing. |
| Permission ceiling ("may I do X") | `AssistantPermissionResolver::allows()` keyed to the Main Agent. | **Unchanged.** Sub-Agents are invisible to this resolver entirely. |
| Data visibility / edit breadth ("whose records") | `User::dataIdentityIds()` = `[agent, self]`. | **Extended** to `[mainAgent, ...activeSubAgents, self]`. This is the entire mechanism — see §3. |
| Record ownership on CREATE | Always the Main Agent (`ownershipUserId()`). | **Explicit "Acting for" choice** among Main Agent + linked Sub-Agents, defaulting to the Main Agent when omitted or when there are no linked Sub-Agents (zero behaviour change for every assistant that has none). |
| Who manages the relationship | Admin/super_admin creates the assignment; the Main Agent trims the matrix. | **Unchanged for the Main Agent/matrix.** Adding/removing a Sub-Agent is **admin/super_admin only** (Johan's ruling, 2026-07-28) — not the Main Agent, not the Sub-Agent. |

**Why this is a narrow, safe change and not a rewrite:** `dataIdentityIds()`
(`app/Models/User.php:907`) is already the single choke point every one of the 23
`scopeVisibleTo()` models and all three per-record write guards (`AuthorizesPropertyAccess`,
`AuthorizesDealAccess`, `AuthorizesContactAccess`) resolve `own` through. Widening what that one
method returns is sufficient to give an assistant edit access to a Sub-Agent's properties —
**no changes needed to any of the 23 models or the 3 authorize traits.** This is the direct answer
to "the assistant needs to be able to edit their properties as well."

---

## 2. Data model

### 2.1 New table — `assistant_linked_agents`

```php
Schema::create('assistant_linked_agents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
    $table->foreignId('assistant_assignment_id')
        ->constrained('assistant_assignments', 'id', 'ala_assignment_fk')
        ->cascadeOnDelete();
    $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete(); // the Sub-Agent

    $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('removed_at')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['assistant_assignment_id']);
    $table->index(['agent_user_id']);
});

// Same "restorable one-active-link" mechanism as §6.3 of the base spec — allows
// re-linking an agent that was previously removed without a duplicate-row failure.
DB::statement("
    ALTER TABLE assistant_linked_agents
    ADD COLUMN active_agent_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, agent_user_id, NULL)) STORED,
    ADD UNIQUE KEY ala_assignment_agent_unique (assistant_assignment_id, active_agent_user_id)
");
```

Short FK names again — mirrors `aap_assignment_fk` in the base spec (64-char MySQL identifier
limit).

**No `assistant_assignment_permissions`-style matrix row for Sub-Agents.** A Sub-Agent contributes
**zero permissions** and **zero matrix rows** — it is purely a data-visibility widening. This is
what keeps the permission ceiling answer trivial: it never has to reconcile two agents' matrices.

### 2.2 `AssistantAssignment` — new relation

```php
// app/Models/AssistantAssignment.php
public function linkedAgentLinks(): HasMany
{
    return $this->hasMany(AssistantLinkedAgent::class);
}
```

### 2.3 New model — `AssistantLinkedAgent`

```php
class AssistantLinkedAgent extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'assistant_assignment_id', 'agent_user_id',
        'added_by_user_id', 'removed_by_user_id', 'removed_at',
    ];

    protected $guarded = ['active_agent_user_id']; // MySQL-maintained, never written to

    public function assignment(): BelongsTo { return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id'); }
    public function agent(): BelongsTo      { return $this->belongsTo(User::class, 'agent_user_id'); } // the Sub-Agent
    public function addedBy(): BelongsTo    { return $this->belongsTo(User::class, 'added_by_user_id'); }
}
```

No `BelongsToBranch` — deliberately (§4, branch interaction below): a linked agent's own
`branch_id` is what matters, not the link row's.

### 2.4 `User::dataIdentityIds()` — the extension point

Current (`app/Models/User.php:907-916`):

```php
public function dataIdentityIds(): array
{
    $agent = $this->isAssistant() ? $this->assignedAgent() : null;
    if (!$agent) {
        return [$this->id];
    }
    return [$agent->id, $this->id];
}
```

New:

```php
public function dataIdentityIds(): array
{
    $agent = $this->isAssistant() ? $this->assignedAgent() : null;
    if (!$agent) {
        return [$this->id];
    }

    $ids = [$agent->id, $this->id];

    foreach ($this->activeLinkedSubAgentIds() as $subAgentId) {
        $ids[] = $subAgentId;
    }

    return array_values(array_unique($ids));
}

/**
 * Sub-Agent ids currently in effect for this assistant's assignment — filtered LIVE, every
 * call, to only agents who are still active, still non-owner, and not themselves an assistant
 * (E5/E6-equivalent guard — see §5). No re-snapshot: a Sub-Agent removed by an admin, deactivated,
 * or later promoted to owner drops out of this list on the very next request, exactly like the
 * Main Agent's live-intersection rule (base spec E3).
 */
public function activeLinkedSubAgentIds(): array
{
    $assignment = $this->activeAssistantAssignment();
    if (!$assignment) {
        return [];
    }

    return $assignment->linkedAgentLinks()
        ->with('agent')
        ->get()
        ->pluck('agent')
        ->filter(fn ($a) => $a
            && $a->is_active
            && !$a->is_assistant
            && !(method_exists($a, 'isOwnerRole') && $a->isOwnerRole()))
        ->pluck('id')
        ->all();
}
```

Memoise `activeLinkedSubAgentIds()` per request the same way `activeAssistantAssignment()` already
is (`User.php:794`, `:815-824`) — it is consulted on every `scopeVisibleTo()` call, same hot path.

**Everything downstream is unchanged**: `AuthorizesPropertyAccess::propertyAccessAllowed()`
(`app/Http/Controllers/Concerns/AuthorizesPropertyAccess.php:74`) already does
`in_array((int) $property->agent_id, $user->dataIdentityIds(), true)` for the `own` scope — a
Sub-Agent's property now passes that check with no edit to the trait. Same for
`AuthorizesDealAccess` and `AuthorizesContactAccess`, and every one of the 23
`scopeVisibleTo()` models the base spec's §7.2 lists.

**VIEW breadth is unaffected by this addendum** — `AssistantPermissionResolver::dataScope()`
(`app/Services/Assistants/AssistantPermissionResolver.php:111-143`) still clamps to the Main
Agent's own module scope. A Sub-Agent's records become reachable ONLY where the existing `own`
scope resolution already widens through `dataIdentityIds()` — i.e. exactly the same mechanism
that lets an assistant edit the Main Agent's own records today. If the Main Agent's module scope
is `own`, the assistant's list/search pages must already resolve `own` through
`dataIdentityIds()` (they do, per the base spec's D prompt) — so Sub-Agent records simply appear
in that same list. No new "branch"/"all" breadth is granted.

---

## 3. Permission ceiling — explicitly unchanged (Johan's ruling, 2026-07-28)

`AssistantPermissionResolver::allows()` (`app/Services/Assistants/AssistantPermissionResolver.php:50-93`)
is **not touched**. It resolves against `$assignment->assignedAgent` — the Main Agent — exactly as
today. A Sub-Agent's own permission set, role, or matrix (Sub-Agents have no matrix) is never
consulted. This is deliberate and locked:

- **Why not "broadest of all linked agents":** that would break the invariant the whole feature is
  built on — "the assistant can never do more than a specific agent can" — by letting an assistant
  accumulate the union of several people's permission ceilings. One mis-linked Sub-Agent with a
  wider role would silently widen every assistant capability, not just their data.
- **Practical effect:** if a Sub-Agent can do something the Main Agent cannot (e.g. holds
  `mic.merge_duplicates` which the Main Agent lacks), the assistant still cannot do it — they can
  only act within the Main Agent's ceiling, just against a wider set of records.

---

## 4. Branch isolation interaction (new finding — must be handled, not assumed)

**Investigated, not guessed** (BUILD_STANDARD §"Investigation Before Prompt"): `BranchScope`
(`app/Models/Scopes/BranchScope.php:62-126`) is a **separate, earlier-applied** global scope. When
`agencies.split_branches_enabled` is ON, it filters every query to
`branch_id = $user->effectiveBranchId()` **before** any `own`/`branch`/`all` data-scope logic runs.

The assistant's own `branch_id` follows the Main Agent (base spec E2). If a Sub-Agent belongs to a
**different** branch than the Main Agent, that Sub-Agent's properties/contacts/deals would be
filtered out by `BranchScope` regardless of what `dataIdentityIds()` returns — the widening in §2.4
would silently do nothing for that Sub-Agent whenever branch split is on. This is exactly the kind
of silent-inert failure BUILD_STANDARD §3 (prevent-or-absorb) requires deciding explicitly, not
discovering later.

**Decision (Johan, 2026-07-28, M5): ABSORB, not prevent — extend `BranchScope` to understand
linked Sub-Agents**, rather than blocking cross-branch links. An assistant may legitimately support
agents across branches (e.g. a floating/head-office assistant), and the feature should not
artificially restrict that.

### 4.1 The extension

```php
// app/Models/Scopes/BranchScope.php — applyInner()
$effectiveBranch = ...; // unchanged

if (!$effectiveBranch) {
    $builder->whereRaw('1 = 0');
    return;
}

// AT-267 multi-agent addendum — an assistant supporting Sub-Agents in OTHER branches must
// also see those branches' records. Empty for every non-assistant and for every assistant
// with zero (or same-branch) linked Sub-Agents — i.e. the entire population today — so this
// is a no-op for everyone except the new feature's own users.
$branchIds = [$effectiveBranch];
if (method_exists($user, 'activeLinkedSubAgentBranchIds')) {
    $branchIds = array_values(array_unique(array_merge(
        $branchIds,
        $user->activeLinkedSubAgentBranchIds()
    )));
}

$table   = $model->getTable();
$column  = $table . '.branch_id';
$keyName = $table . '.' . $model->getKeyName();
$authId  = $user->getKey();
$isUserModel = $model instanceof \App\Models\User;

$builder->where(function (Builder $q) use ($column, $branchIds, $keyName, $authId, $isUserModel) {
    $q->whereIn($column, $branchIds);
    if ($isUserModel && $authId) {
        $q->orWhere($keyName, $authId);
    }
});
```

Only the single `where($column, $effectiveBranch)` line becomes `whereIn($column, $branchIds)` —
the smallest possible diff to the highest-blast-radius file this addendum touches.

### 4.2 New `User` helper — the companion to `activeLinkedSubAgentIds()`

```php
/** Distinct branch ids of this assistant's currently-active linked Sub-Agents. Empty for
 *  everyone else, and for any assistant with no linked Sub-Agents — i.e. almost everyone. */
public function activeLinkedSubAgentBranchIds(): array
{
    $assignment = $this->activeAssistantAssignment();
    if (!$assignment) {
        return [];
    }

    return $assignment->linkedAgentLinks()
        ->with('agent')
        ->get()
        ->pluck('agent')
        ->filter(fn ($a) => $a && $a->is_active && !$a->is_assistant
            && !(method_exists($a, 'isOwnerRole') && $a->isOwnerRole()))
        ->pluck('branch_id')
        ->filter()
        ->unique()
        ->values()
        ->all();
}
```

Same live-filter guard as `activeLinkedSubAgentIds()` (§2.4) — a Sub-Agent who is deactivated,
promoted to owner, or converted to an assistant drops out of the branch widening on the very next
request too, not just out of `dataIdentityIds()`.

### 4.3 Why this is safe despite touching a central file

- **Zero behaviour change for every user who is not an assistant** — `activeLinkedSubAgentBranchIds()`
  doesn't exist as a concept for them; `method_exists()` still returns true (it's defined on `User`
  for everyone) but the method itself returns `[]` unless `isAssistant()` is true, so `$branchIds`
  degrades to exactly `[$effectiveBranch]` — today's single-branch filter, unchanged.
- **Zero behaviour change for every assistant with no linked Sub-Agents** — same reason, `[]`.
- **The widening is bounded** to exactly the branches of that assistant's own currently-active
  linked Sub-Agents — never the whole agency, never another assistant's Sub-Agents.
- The candidate-linking guardrails (§5) still apply in full — this section only removes the
  same-branch restriction that would otherwise have been L2; it does not touch L1/L3/L4/L5/L6.

Because of the blast radius of `BranchScope`, this prompt (Prompt C, §11) ships with its own
dedicated test file and sits on QA1 alone before Staging — same posture as `dataIdentityIds()`
(Prompt B) and the base spec's own Prompt D.

---

## 5. Guardrails on linking a Sub-Agent (mirrors E5/E6, extended)

Enforced at attach time (`AssistantLinkedAgentController::store()`), and **re-checked live** every
time `activeLinkedSubAgentIds()` runs (§2.4) — belt and braces, same fail-closed posture as the
rest of the feature:

| # | Rule | Why |
|---|---|---|
| **L1** | Candidate must be in the same agency. | `AgencyScope` boundary — non-negotiable #7/#9 (SYSTEM.md). |
| **L2 (removed)** | Cross-branch candidates are **allowed** — `BranchScope` is extended (§4, M5) rather than restricting the candidate pool. There is no branch filter on the picker. | Johan's ruling, 2026-07-28: an assistant may legitimately support agents across branches. |
| **L3** | Candidate must not be `is_assistant`. | Chained delegation — same reasoning as base spec E5. An assistant's Sub-Agent list must only ever contain people who are themselves fully-permissioned agents, never another delegation hop. |
| **L4** | Candidate must not hold an **owner** role. | Same reasoning as base spec E6 applied to data exposure: an owner's "own" record set is not a normal agent's book, and this feature must never be the path that exposes it. |
| **L5** | Candidate must not already be the assignment's Main Agent. | Redundant — the Main Agent relationship already grants everything a Sub-Agent link would. |
| **L6** | Candidate must not already be an active linked agent on this assignment. | Enforced by the unique index (§2.1); the controller pre-checks for a friendly message instead of a raw DB error. |
| **L7** | The **same** agent MAY be linked as a Sub-Agent on multiple different assistants' assignments. | Not exclusive — e.g. a shared office assistant supporting several agents, and each of those agents may separately have their own dedicated assistant too. No conflict: each is a distinct `assistant_assignment_id` row. |
| **L8** | An agent MAY simultaneously be a Main Agent for assistant A and a linked Sub-Agent for assistant B. | Different relationships, different rows — no cycle, because Sub-Agent status never confers any control (L3/L4 only block chains that could reach a permission ceiling). |

---

## 6. Record ownership on CREATE — the "Acting for" selector

**Decision locked (2026-07-28): the assistant picks explicitly.** Ownership on create is no
longer unconditionally the Main Agent — an assistant supporting multiple agents must be able to
create a property/contact/deal that lands on whichever agent's book it actually belongs to.

### 6.1 `User::ownershipUserId()` — widened signature, backward compatible

Current (`app/Models/User.php:926-929`):

```php
public function ownershipUserId(): int
{
    return $this->assignedAgent()?->id ?? $this->id;
}
```

New:

```php
public function ownershipUserId(?int $actingForUserId = null): int
{
    $agent = $this->assignedAgent();
    if (!$agent) {
        return $this->id; // not an assistant — unchanged
    }

    // Only a valid, currently-in-scope choice is honoured — never trust the raw request value.
    if ($actingForUserId !== null
        && $actingForUserId !== $this->id
        && in_array($actingForUserId, $this->dataIdentityIds(), true)) {
        return $actingForUserId;
    }

    return $agent->id; // default: the Main Agent — EXACTLY today's behaviour when omitted
}
```

**Every existing call site that passes nothing is unaffected** — an assistant with zero linked
Sub-Agents (today's entire population) gets identical behaviour, byte for byte.

### 6.2 UI — where the selector appears

A new `<select name="acting_for_user_id">` ("Acting for") on every record-creation surface an
assistant can reach, **rendered only when `activeLinkedSubAgentIds()` is non-empty** — an assistant
with just a Main Agent never sees it (zero UI change for the common case today). Options: Main
Agent (default-selected) + each active linked Sub-Agent, labelled by name.

Surfaces (same catalogue `assistant-control-page.md`'s Phase 3 "ownership routing" work already
enumerated as create sites needing `ownershipUserId()`): Contact create, DealV2 create,
CommandTask create, notes, Presentation create, Viewing Pack create, calendar event create, daily
activity entry. Property creation is excluded — it is hard-locked for assistants entirely (base
spec §9), Acting-for is moot there.

Each create controller passes the submitted (and re-validated per §6.1) value through to
`ownershipUserId($request->integer('acting_for_user_id') ?: null)`.

### 6.3 Audit — `ActingFor::onBehalfOfUserId()` must also take an explicit target

**This is a correctness fix, not optional.** Current
(`app/Support/ActingFor.php:25-39`) always returns the Main Agent's id — for an **edit** of an
existing Sub-Agent's record, that would misattribute the audit row to the Main Agent even though
the action was authorised through the Sub-Agent's own ownership, which is precisely the
FICA/POPIA/PPRA defensibility gap (base spec §1) this whole feature exists to close.

New signature:

```php
public static function onBehalfOfUserId(?int $recordOwnerUserId = null): ?int
{
    $user = Auth::user();
    if (!$user instanceof User || !$user->isAssistant()) {
        return null;
    }

    // A record's actual current owner (Main Agent or an active linked Sub-Agent) takes
    // precedence — this is what makes an EDIT of a Sub-Agent's property audit correctly.
    if ($recordOwnerUserId !== null && in_array($recordOwnerUserId, $user->dataIdentityIds(), true)) {
        return $recordOwnerUserId;
    }

    return $user->assignedAgent()?->id; // default — unchanged for every existing call site
}
```

- **Edit paths** (property, contact, deal, etc. `update()`): pass the record's own existing owner
  column value (`$property->agent_id`, `$deal->listing_agent_id`, …) — already known, no new input.
- **Create paths**: pass the same `acting_for_user_id` used for `ownershipUserId()` in §6.1/§6.2 —
  one value drives both.
- **Every existing call site that passes nothing** keeps today's behaviour exactly.

All ten audit tables in the base spec's §11 table that call `ActingFor::onBehalfOfUserId()` must be
swept to pass the record-owner argument on edit paths. This is mechanical but not zero-effort — it
touches the same call sites the base spec's Prompt J already enumerated.

---

## 7. Admin surface — managing linked Sub-Agents (admin/super_admin only)

Per the locked decision, **only admin/super_admin** manage this — not the Main Agent, not a
Sub-Agent, no self-service. New permission key (Role Manager section `assistants`, mirrors base
spec §8):

```php
['key' => 'assistants.manage_linked_agents', 'label' => 'Link/Unlink Additional Agents',
 'section' => 'assistants', 'type' => 'action', 'module' => 'assistants', 'sort_order' => 6],
```

Role defaults: `admin`/`super_admin` inherit it automatically (same all-minus-exclude pattern as
every other `assistants.*` key); `branch_manager` gets nothing by default, same as D5.

### Routes

| Method | URI | Name | Gate |
|---|---|---|---|
| POST | `/admin/assistants/{assignment}/linked-agents` | `admin.assistants.linked-agents.store` | `permission:assistants.manage_linked_agents` |
| DELETE | `/admin/assistants/{assignment}/linked-agents/{linkedAgent}` | `admin.assistants.linked-agents.destroy` | `permission:assistants.manage_linked_agents` |
| POST | `/admin/assistants/{assignment}/linked-agents/{linkedAgent}/restore` | `admin.assistants.linked-agents.restore` | `permission:assistants.manage_linked_agents` |

Controller: `App\Http\Controllers\Admin\AssistantLinkedAgentController`, following the same
validate-then-attach / soft-delete-then-restorable shape as the rest of `AssistantController`.

### View

New section on the existing `resources/views/admin/assistants/show.blade.php` (assignment detail
page, base spec §12): **"Also supports these agents"** — a simple add/remove list (name, branch,
status, a Remove button with confirmation per STANDARDS.md "Confirmations Before Destructive
Actions"), sitting below the existing Main Agent + matrix summary. The agent-picker reuses the
Main Agent selector's component, filtered per §5 (L1, L3-L8) — **not** restricted by branch (§4,
M5): the picker shows candidates agency-wide, and each row displays the candidate's branch so the
admin can see at a glance when they're linking across branches.

No new navigation entry needed — it lives on a page the existing Assistants nav entry already
reaches (STANDARDS.md "No Orphaned Pages" is satisfied by the existing `admin.assistants.show`
route).

### The Main Agent's own control page — read-only awareness, not control

`agent/assistants/matrix.blade.php` (the Main Agent's own page, base spec §12) gains a **read-only**
line in the behaviour panel: *"This assistant also supports: {names}"* when linked Sub-Agents
exist — so the Main Agent isn't confused about scope creep they didn't cause, without being able to
edit it (that would contradict "admin only," §7 heading). No-op UI otherwise.

---

## 8. Domain events (Non-negotiable #9)

Two additions to the base spec's §15 catalogue, same shape (past-tense fact, uniform payload,
synchronous listeners registered in `AppServiceProvider::boot()`):

| Event | Emitted when | Payload | Listeners |
|---|---|---|---|
| `Assistant\SubAgentLinked` | Admin links a Sub-Agent | `assignment`, `subAgentUserId`, `addedByUserId`, `agencyId` | audit; notify the Sub-Agent ("An assistant supporting {mainAgent} now also has access to your records") |
| `Assistant\SubAgentUnlinked` | Admin removes a Sub-Agent link | `assignment`, `subAgentUserId`, `removedByUserId`, `reason` | audit; notify |

**The Sub-Agent notification is informational, not a consent gate** (locked decision: no consent
step in v1) — but STANDARDS.md's "No Silent Locks" spirit argues a person whose data is now
reachable by someone else's assistant should at minimum be told. Cheap to add, closes an obvious
"how would I ever find out" question, and costs nothing architecturally since the event plumbing
already exists.

---

## 9. Edge cases — amending base spec §14

| # | Case | Decision |
|---|---|---|
| **E7 (superseding base spec E7)** | One Assistant, multiple Agents | **Main Agent stays singular** (unique index unchanged — audit/ceiling clarity preserved). **Sub-Agents are unlimited**, admin-managed, contribute zero permissions, only widen data breadth. |
| **E7a** | A linked Sub-Agent is deactivated or soft-deleted | Drops out of `dataIdentityIds()` on the **next request** — live filter (§2.4), no re-snapshot, no cascade to the link row itself (it stays, so reactivating the agent restores access with no admin action needed — mirrors base spec E1's Main-Agent freeze-not-cascade doctrine). |
| **E7b** | A linked Sub-Agent later becomes an assistant themselves, or is promoted to an owner role | Drops out of `dataIdentityIds()` on the next request (§2.4's live filter checks `is_assistant`/`isOwnerRole()` every call) — same freeze-not-cascade posture, no manual cleanup required, though the stale link row is worth surfacing to admins for a cleanup pass. |
| **E7c** | Same agent is a Sub-Agent on multiple assistants' assignments, or is a Main Agent on one assignment and a Sub-Agent on another | **Allowed** — no exclusivity, no conflict (§5, L7/L8). |
| **E7d** | Assistant submits a create form without choosing "Acting for" (or has no linked Sub-Agents at all) | Falls back to the Main Agent — never blocked, never a 500 (BUILD_STANDARD §2, optional-and-empty). |
| **E7e** | A tampered/forged `acting_for_user_id` value not in the assistant's `dataIdentityIds()` | Silently ignored server-side, falls back to the Main Agent (§6.1's `in_array` guard) — fail closed, never trust the client value directly. |
| **E7f** | A candidate Sub-Agent is in a different branch than the assignment, while `split_branches_enabled` is on | **Allowed** (Johan, M5, §4) — `BranchScope` is extended to include the Sub-Agent's branch for that assistant's queries, rather than blocking the link. |
| **E7g** | `assistants_enabled` flipped off agency-wide while Sub-Agent links exist | Resolver already fails everything closed (base spec E15); Sub-Agent links are untouched rows, restored automatically when the toggle returns. |

---

## 10. Setup Wizard — deliberately NOT added (Non-negotiable #10a)

Linking a Sub-Agent is a **per-assignment admin action**, not an agency-level setting — there is no
toggle to surface. This mirrors the base spec's own §22 precedent (`assistant-control-page.md`'s
per-assignment behaviour toggles were confirmed NOT a wizard item, same file, "Per-assignment, so
NOT a Setup Wizard item — confirmed"). Recorded here explicitly per Non-negotiable #10a rather than
silently omitted.

---

## 11. Build sequencing

Each prompt: investigate → report → approve → fix. Ends with `php -l`, `view:clear`, the single
most relevant test file (never a broad suite — CLAUDE.md #13), Tinker verification, push.

| # | Prompt | Description |
|---|---|---|
| **A** | Schema + models | `assistant_linked_agents` migration (§2.1) with the restorable-unique generated column, `AssistantLinkedAgent` model (§2.3), the `linkedAgentLinks()` relation on `AssistantAssignment` (§2.2). `DB_DATABASE=hfc_dash_test php artisan schema:dump`, commit snapshot with the migration. No behaviour yet. |
| **B** | `dataIdentityIds()` extension | `activeLinkedSubAgentIds()` + the widened `dataIdentityIds()` (§2.4), memoised. **Tests first**: assistant with 0/1/2 linked sub-agents sees the right id set; a deactivated/promoted/removed sub-agent drops out live; `AssistantVisibilityCoverageTest` still passes unmodified (it asserts the *mechanism*, not the *size*, of `dataIdentityIds()`). Verify a Sub-Agent's property is now editable via `AuthorizesPropertyAccess` with **zero changes to that trait** — this is the proof the mechanism works. |
| **C** | `BranchScope` extension | The §4 widening: `activeLinkedSubAgentBranchIds()` + the `whereIn` change in `BranchScope::applyInner()`. **Tests first, dedicated file**: a non-assistant's queries are byte-identical to today; an assistant with 0 linked sub-agents is byte-identical to today; an assistant with a same-branch sub-agent is unaffected; an assistant with a cross-branch sub-agent sees that sub-agent's branch-scoped records; branch-split OFF agencies are unaffected entirely (scope short-circuits before this code runs). |
| **D** | Admin CRUD for links | `AssistantLinkedAgentController` (§7), the new permission key, the "Also supports these agents" section on `admin/assistants/show.blade.php`, the guardrails L1-L6 (§5) as validation. |
| **E** | Acting-for — ownership | Widened `ownershipUserId()` (§6.1). The selector UI (§6.2) on the create surfaces enumerated. Test: omitted → Main Agent; valid sub-agent id → that agent; forged id → falls back to Main Agent (E7e). |
| **F** | Acting-for — audit | Widened `ActingFor::onBehalfOfUserId()` (§6.3). Sweep the base spec's §11 ten-table list: edit paths pass the record's existing owner; create paths pass the acting-for value. Test: editing a Sub-Agent's property audits `on_behalf_of_user_id` = that Sub-Agent, not the Main Agent. |
| **G** | Read-only awareness | The Main Agent's "This assistant also supports…" line (§7, last item). |
| **H** | Domain events | `SubAgentLinked` / `SubAgentUnlinked` (§8), registered synchronously. |
| **I** | Regression matrix | §12 below. |

**Parallelism:** C can run alongside B once A lands. E and F are sequential (F depends on E's
acting-for value existing). D is independent of B/C/E/F except for sharing the permission key from
Prompt A's config addition.

---

## 12. Acceptance criteria (additive to base spec §18)

1. An admin can link a second, third, … agent to an existing assistant assignment from the
   assignment detail page; the Main Agent and matrix are untouched by this action.
2. The assistant can now see and **edit** a linked Sub-Agent's properties/contacts/deals — with
   **zero code changes** to `AuthorizesPropertyAccess`/`AuthorizesDealAccess`/`AuthorizesContactAccess`
   or any of the 23 `scopeVisibleTo()` models (proves the `dataIdentityIds()` mechanism, §2.4).
3. The assistant's permission ceiling is unaffected by which agents are linked — a capability the
   Main Agent doesn't have stays unavailable no matter which Sub-Agent has it.
4. Creating a new record shows an "Acting for" selector only when ≥1 Sub-Agent is linked; omitting
   it (or having zero Sub-Agents) lands the record on the Main Agent exactly as today.
5. A forged/out-of-scope `acting_for_user_id` is ignored server-side and falls back to the Main
   Agent — never a 403, never a crash, never silently trusted.
6. Editing an existing Sub-Agent's record produces an audit row with `on_behalf_of_user_id` = that
   Sub-Agent, not the Main Agent.
7. Deactivating a linked Sub-Agent (or promoting them to owner, or converting them to an assistant)
   removes their records from the assistant's reach on the next request, with no admin cleanup step
   required to restore it later.
8. Linking a Sub-Agent from a different branch succeeds when `split_branches_enabled` is on for the
   agency, and the assistant can subsequently see and edit that Sub-Agent's branch-scoped records
   (properties, contacts, deals) — proving the `BranchScope` extension (§4) works, not just the
   `dataIdentityIds()` widening (§2.4) alone.
9. `assistants.manage_linked_agents` appears in Role Manager exactly like every other `assistants.*`
   key; only admin/super_admin have it by default.
10. No new Setup Wizard entry (§10) — recorded as a deliberate omission, not an oversight.
11. No new failures in the targeted test files (CLAUDE.md #13).

---

## 13. Rollback plan

| Failure point | Rollback |
|---|---|
| **A (schema)** | Additive-only table; drop it if malformed. No existing table is touched. |
| **B (`dataIdentityIds()`)** | **High-risk prompt, same as the base spec's D** — a bug here affects every assistant, not just multi-agent ones, because `dataIdentityIds()` is called for everyone. Ships behind the fact that `activeLinkedSubAgentIds()` returns `[]` for every assistant with no links (100% of today's population) — so a bug can only manifest once a link is actually created. Sits on QA1 alone before Staging, same posture as the base spec's D. |
| **C (`BranchScope`)** | **Highest-risk prompt in this addendum** — `BranchScope` is consulted on every branch-scoped query for every user in every agency, assistant or not. Rollback is a **code revert** of the `applyInner()` change, not a toggle — there is no kill switch for `BranchScope` itself. Mitigated by: (a) the change degrades to today's exact `where()` for anyone where `activeLinkedSubAgentBranchIds()` is empty — non-assistants and assistant with no cross-branch links, i.e. everyone today; (b) its own dedicated test file per §11; (c) sits on QA1 alone for 48h before Staging, same posture as the base spec's D and this addendum's B. |
| **D-H** | Standard code revert; nothing here touches existing data, only additive rows and new UI. |
| **Post-launch misuse** | `admin.assistants.linked-agents.destroy` — soft delete, instant, restorable. |

---

## 14. Decisions — signed off (Johan, 2026-07-28)

| # | Decision | Ruling |
|---|---|---|
| **M1** | Whose book does a new record land on when the assistant supports multiple agents? | **The assistant picks explicitly** — an "Acting for" selector, defaulting to the Main Agent. |
| **M2** | Who can add/remove a linked Sub-Agent? | **Admin/super_admin only** — not the Main Agent, not the Sub-Agent. |
| **M3** | Does a Sub-Agent need to consent to being linked? | **No, not in v1** — same trust boundary as existing role-based visibility. An informational notification is sent (§8) but is not a gate. |
| **M4** | Does linking a Sub-Agent widen what the assistant may DO (the permission ceiling), or only whose records they may see/edit? | **Only data breadth.** The permission ceiling stays keyed to the Main Agent exclusively. |
| **M5** | Cross-branch Sub-Agents, when `split_branches_enabled` is on: block the link, or extend `BranchScope` to allow it? | **Extend `BranchScope`** (§4). An assistant may legitimately support agents across branches; the candidate picker is agency-wide, not branch-filtered. Accepted as the highest-blast-radius prompt in this addendum in exchange for that flexibility — mitigated per §13. |

## 15. Open — none

Both open items from the draft (branch isolation, §4/M5) are now resolved and locked above. This
addendum is ready for the build sequence in §11 once Johan confirms the whole document (not just
M5) reads as intended.

---

End of addendum.
