# AT-267 Assistants — feature audit

> Date: 2026-07-19 · Branch: QA2 (post-merge of origin/AT-267-assistants) · Method: read the
> security core (resolver, snapshot, PermissionService integration, models, controllers,
> middleware, config) + the spec (`.ai/specs/assistants-feature-spec.md`) + the shipped-prompt
> git history, cross-checked against the build prompt and Johan's two stated requirements.
> No code changed. No phpunit in this lane — findings are by source reading, not test runs.

## Verdict

**The security CORE is excellent — genuinely well-built.** The permission intersection, the
four-layer property-upload lock, the fail-closed resolver, and the write-time eligibility
guards are all correct and defensively written. **But the feature is only partially shipped
(Prompts A–F + K of A–N), and two of the gaps are material** — including one that directly
contradicts Johan's stated requirement, and one the spec itself calls a non-negotiable.

Requirement check (Johan's two asks):
- ✅ **"An assistant only gets the same permissions as the agent they're created for."** Fully
  and correctly implemented — a *live* intersection, not a copy (see §A).
- ❌ **"For an assistant of an admin, admin-access features default OFF."** NOT implemented —
  they default **ON** (see Finding 1).

---

## A. What is correct (verified)

- **Live intersection, never escalation** (`AssistantPermissionResolver::allows()`,
  `app/Services/Assistants/AssistantPermissionResolver.php:50`):
  `assistant_can(k) = matrix[k] AND agent_live_has(k) AND k ∉ LOCKED_SET`. The matrix can only
  ever subtract; the moment the agent loses a permission the assistant loses it, no re-snapshot.
  Fail-closed on every unclear state (no assignment, suspended, deactivated agent, missing row,
  agency toggle off) → `false`, never "fall back to the role."
- **Zero-grant routing.** `PermissionService::userHasPermission()` routes any `is_assistant`
  user to the resolver *before* role resolution (`:349`) — so the `assistant` role's (empty)
  grants and a mis-set `users.role` (defaults to `agent`!) can never leak. Sits after the owner
  bypass; owner agents are blocked at assignment so the two can't collide.
- **Property-upload hard lock — 4 layers** (`config/assistants.php:50`): resolver denies before
  the matrix; `DenyAssistantPropertyWrite` middleware covers the keyless routes (wired on the
  web `properties` group + mobile/prospecting/pull API); matrix UI renders locked rows disabled;
  `AssistantAssignmentPermission::saving()` forces `granted=false` on locked keys. Dead keys
  pre-locked. Comprehensive.
- **Write-time eligibility guards** (`AssistantController::assignableAgents()/validateAgent()`,
  `:333/:349`): owners, existing assistants, and inactive users excluded; re-validated on
  reassign. Blocks E5 (assistant-of-assistant) and E6-owner and the resolver-recursion risk.
- **One agent per assistant** via a STORED generated column + unique index (not just a
  controller check). **Branch/agency isolation**: `AssistantAssignment` uses `BelongsToAgency`
  + `BelongsToBranch`. **Soft-delete cascade**: deleting an assignment soft-deletes its matrix
  and a restore brings it back — the soft-deleted row IS the reassignment archive.
- **Read side works**: an assistant inherits the agent's data identity
  (`User::dataIdentityIds() = [agent, self]`, `:818`) so `own`-scope reads show the agent's book.

---

## Findings

### 🔴 Finding 1 — Admin-access features default ON for an assistant of an admin (contradicts Johan's requirement)

Two compounding causes:

1. **Admins are not blocked as agents.** The spec *summary* (line 132) says
   *"Owner/admin as the Assigned Agent | Blocked"*, but the spec's own E6 detail row (line 733)
   and the implementation block only **owner** roles (`is_owner = true`).
   `assignableAgents()` filters `Role::where('is_owner', true)` only
   (`AssistantController.php:335`). **`admin` is `is_owner = no`** (verified) → an admin can be
   selected as an assistant's agent.
2. **No admin-default-off set exists.** `AssistantMatrixSnapshotService::snapshot()` seeds
   **every** permission the agent holds as `granted = true` (`:61`, `seed(grantedByDefault:true)`);
   the *only* keys forced off are `config('assistants.property_upload_locked_set')`. So an
   assistant of an admin starts with that admin's admin-access permissions ON —
   `access_soft_deletes`, `manage_payroll`, `roles.view`, user management, `access_settings`,
   billing, etc. — until the admin manually unticks each.

**Impact:** exactly the over-privilege Johan asked to prevent. A newly-created assistant of an
admin can, by default, reach admin surfaces.

**Fix (recommend both):**
- Align the block to the spec summary: exclude `admin`/`office_admin` (or any role holding
  `access_settings`/`roles.view`) from `assignableAgents()` — OR, if admins *should* be able to
  hold assistants, then:
- Add an **`admin_default_off` set** to `config/assistants.php` and have `snapshot()` seed those
  keys `granted = false` (soft default-off — unlike the hard lock, the admin *can* still turn
  them on). The permission catalogue already carries `'section' => 'admin'` metadata (12+ keys),
  so the set can be derived, not hand-listed. This matches Johan's phrasing ("default off").

### 🔴 Finding 2 — The audit trail (`on_behalf_of_user_id`) was never built (spec's own non-negotiable)

Spec §11 designs `on_behalf_of_user_id` across ~17 bespoke audit tables (domain_event_log,
property_audit_log, signature_audit_log, calendar_event_audit_log, comms_access_audit_log, …)
and calls it *"mandatory … for FICA/POPIA/PPRA defensibility,"* mandating an
`AuditActorCoverageTest` to stop it rotting. **None of it shipped:**
- `on_behalf_of_user_id` appears in **no migration and has zero write sites** (only a comment in
  `User.php`). The column does not exist anywhere.
- **`AuditActorCoverageTest` is absent.**
- Git history: only Prompts A, B, C, D, E, F, K landed. **Prompt J (audit) never committed.**

**Impact:** the feature's entire reason for existing (spec line 23: end shared-login work that
"the audit trail says the *agent* did") is unmet at the audit layer. An assistant's actions are
not provably attributed to the assistant anywhere.

### 🟠 Finding 3 — Assistant-created records are NOT routed to the agent (write side)

`User::ownershipUserId()` (`:837`) exists to stamp assistant-created records to the **agent**
("a deal captured by an assistant is the AGENT's deal"), but it is **never called on any write
path** — only read-scope resolution in `ResolvesMobileDataScope`. Create paths stamp
`created_by_user_id = auth()->id()` = the **assistant** (e.g. `ContactMatchController.php:168,302`).

**Impact:** the read/write directions are asymmetric — an assistant *sees* the agent's book, but
records they *create* are owned by the assistant, so (a) the agent does **not** see work their
assistant did (a normal agent's `dataIdentityIds` = `[self]`, never includes their assistants),
and (b) commission / targets / pipeline on an assistant-created deal land on the assistant (who
has no commission profile), not the agent. Contradicts the spec's "Record ownership → stamped to
the Agent" row (line 133) and `ownershipUserId`'s own docblock.

### 🟡 Finding 4 — Incomplete build sequence (Prompts I / J / L / M / N; supporting pieces)

Only A–F + K shipped. Beyond Findings 2–3, verify/complete:
- **§6 assistant profile stripping** — no assistant-aware profile view was found; an assistant
  may still see commission / PPRA FFC / bank / leave / payroll sections (partly mitigated by
  permission-gating, but §6 is explicit UI stripping). Likely unbuilt (Prompt L?).
- **`assistants:sync-matrix` nightly drift command** (E4) — the `syncDrift()` service exists,
  but confirm a scheduled command actually calls it, or drift rows never appear.
- **Canary + rollback plans** (spec deliverable) — process items, confirm before any deploy.

---

## Recommendation / priority

1. **Finding 1** — decide admin-as-agent policy and implement default-off (Johan's explicit ask).
   Smallest correct fix: derive an `admin_default_off` set from `section => 'admin'` and seed it
   `granted = false` in `snapshot()`; add a resolver/UI note. Add a test.
2. **Finding 2** — build Prompt J (the `on_behalf_of_user_id` column + writers at the §11
   surfaces + `AuditActorCoverageTest`) before this feature is used in anger. It is the
   compliance spine.
3. **Finding 3** — wire `ownershipUserId()` into the create/stamp paths (an owner-stamping trait
   or the existing observers), so assistant work lands on the agent's book and money.
4. **Finding 4** — finish §6 profile stripping + the drift command; confirm canary/rollback.

**Before any deploy:** run `tests/Feature/Assistants/*` + the full suite on a test-capable lane
(none here). The shipped core is strong; the gaps above are what stand between "well-built
skeleton" and the spec's own definition of done.

---

## Remediation — 2026-07-19 ("fix everything" pass)

**✅ Finding 1 — admin features default OFF (DONE, verified).** New
`config/assistants.php → admin_default_off_sections` (driven by the catalogue's `section`, not a
hand-list). `AssistantMatrixSnapshotService::seed()` seeds those keys `granted = false` on a
fresh snapshot (soft default — still turn-on-able), leaving ordinary keys ON and the property
lock untouched. Tinker-verified: an admin sponsor's assistant gets `access_soft_deletes` /
`view_backups` / `manage_payroll` = off, `contacts.view` = on, `properties.create` = locked-off.

**✅ Finding 2 — the `on_behalf_of_user_id` audit trail (DONE, verified).** New
`App\Support\ActingFor::onBehalfOfUserId()` (mirrors AT-118 `Impersonation`) + a
`StampsOnBehalfOf` trait (creating-hook + `onBehalfOf()` relation) on the 9 audit models;
migration adds the nullable column to all 10 §11 tables; the two raw writers
(`RecordDomainEvent`, `PropertyAuditService` marketing-share) stamp it directly; the missing
`ContactAccessLog::impersonator()` relation is closed. New `AuditActorCoverageTest` ratchet
(NO_STAFF_ACTOR + PENDING_COVERAGE buckets). Tinker-verified: ratchet green, `domain_event_log`
has the column, an assistant's audit write stamps `on_behalf_of = agent`, a normal write stays
null.

**✅ Finding 4b — drift command (DONE).** `assistants:sync-matrix` (calls `syncDrift()` over
active assignments) scheduled nightly at 04:15. Registered + schedule verified.

**◑ Finding 4a — profile stripping (data-exposure hides DONE + compile-verified; residual needs a render lane).**
`AgentPortalController` passes a single `$isAssistant` flag. Done (all `@unless($isAssistant)`,
inert for normal agents; Blade compiles, directives balanced 6/6):
- Tabs: Tools, Payslips, Leave hidden; Compliance only when `fica_required`.
- Overview: My Earnings + My Presentations hidden; Recent Activity collapsed to empty (no
  commission R-amounts — nulled in the controller to avoid touching the nested Training tile).
- Profile: FFC Number/Expiry, Public Website Profile, PPRA Status hidden.
- Documents: practitioner cards (FFC / PI / Tax) filtered out — ID Copy + Profile Photo only.
- Password: Delete Account danger zone hidden.
STILL TO DO on a render/test lane (each carries real risk untested): the NEW Proof-of-Residence
upload card (needs its endpoint wired), `computeComplianceStatus()` item reduction (can't drop
array keys without also gating the fixed-key blade rows), the "Assistant to: <Agent>" label, the
Tools-tab DOM (tab hidden but content still x-show-hidden in source), and full render verification.

**⏳ Finding 3 — record-ownership routing (NOT done — money-sensitive; scoped for pickup).** Wiring
`ownershipUserId()` into create paths changes where commission/pipeline land; per-surface and hard
to reverse, so not shipped untested. **Ready-to-execute ticket written:**
`.ai/audits/assistants-finding3-ownership-remediation.md` — exact surfaces (DealV2 the money one:
`listing_agent_id` defaults to `auth()->id()` at `DealV2Controller.php:325`), owner-vs-actor
columns, the guardrail (assistant can't assign ownership elsewhere), and the test plan. Note:
routing owner→agent also fixes the read asymmetry for free (no `dataIdentityIds()` change needed).
