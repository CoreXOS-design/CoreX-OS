# Calendar Person-Picker + Invited-By Marker — Design & Investigation

**Status:** DESIGN ONLY. Not built. Written by cc6 (reconcile lane) per Johan's request, 2026-08-19.
**Pillars touched:** Agent (User, Role, role_permissions), Property/Deal (indirectly, via calendar events linked to them).
**Companion finding:** a real code bug in `CalendarController::applyFilters()` blocks both features and is scoped below — read that section first, it is the gate.

---

## 0. applyFilters() — independent diagnosis, ordering answer, fix scope

### 0.1 cc1's diagnosis: CONFIRMED, with one correction

Read `app/Http/Controllers/CommandCenter/CalendarController.php::applyFilters()` (currently lines 2436–2532, the single choke point for month/week/day/agenda views AND the `events()` JSON — 10 call sites, all of them). Confirmed independently:

- Line 2447: `->where('invitee_user_id', $user->id)` — bare id, not `dataIdentityIds()`.
- Line 2459: `(int) $e->user_id === (int) $user->id` — bare id.
- Lines 2461–2462: `$user->branch_id` (both the `when()` guard and the comparison) — bare column, not `effectiveBranchId()`.
- **A fourth site cc1's note didn't mention**, same root cause, same method: line 2475, the batched invitation-status lookup that feeds `$event->user_invitation_status` (the field the invited-by marker in Part 2 depends on) — `->where('invitee_user_id', $user->id)`, also bare.

So: yes, an assistant's AT-267-widened events (their agent's own-owned events, not just invitations) and a multi-branch admin's "view as branch X" override both get silently discarded by this method today, regardless of the role_permissions scope value. Confirmed by reading the code, not by running it (this method has **zero** existing test coverage — `grep -rl applyFilters tests/` finds one docblock mention, no actual test).

**The correction:** cc1's note frames the fix as needing "the invited-event-id set... computed BEFORE the filter rather than after, because right now the data needed to protect those events does not exist at the point of filtering." That ordering problem **already does not exist** — I fixed it earlier today, in the `ad9399923` cherry-pick reconciliation (commit `583ce9096` on `wip/calendar`, folded into staging). Before that fix, `applyFilters()` had no invited-event carve-out at all; I added `$invitedEventIds`, computed before the two scope-filter closures, exactly the shape cc1 is describing. That part is done.

What's left is narrower than cc1's estimate implies: it is not a reordering, it is **swapping the identity source** at four existing sites that are already correctly positioned. No structural change to the method.

### 0.2 Should this be fixed FIRST, before either feature? Yes — I agree with your instinct, for a reason sharper than "it's upstream"

It's not just that a person-picker is "worthless" on top of this bug — it's that **the picker's own selected-person filter has to live in this exact method**, next to the exact code that's wrong. If I build the picker's `people[]` filter now, I'm either:
- adding a fifth bare-`$user->id`-shaped site to the same method (compounding the bug), or
- doing the identity fix as an undocumented side-effect buried inside a feature PR, which is precisely the "two people editing the same method on the same night" collision you and cc1 are trying to avoid.

Fix applyFilters() as its own change, its own commit, its own review, before either feature touches the method again.

### 0.3 Concrete fix scope

**What changes** (4 sites, all inside `applyFilters()`):

```php
private function applyFilters(Collection $events, $user, array $typeFilter, array $categoryFilter, string $scope): Collection
{
    $identityIds = $user->dataIdentityIds();          // NEW — top of method
    $effectiveBranchId = $user->effectiveBranchId();   // NEW — top of method

    $invitedEventIds = in_array($scope, ['own', 'branch'], true)
        ? DB::table('calendar_event_invitations')
            ->whereIn('invitee_user_id', $identityIds)      // was: where(..., $user->id)
            ->whereIn('status', ['pending', 'accepted', 'tentative'])
            ->pluck('event_id')->all()
        : [];

    $filtered = $events
        ->when(...)  // type/category filters, unchanged
        ->when($scope === 'own', fn ($c) => $c->filter(
            fn ($e) => in_array((int) $e->user_id, $identityIds, true) || in_array($e->id, $invitedEventIds, true)
        )->values())
        ->when($scope === 'branch' && $effectiveBranchId, fn ($c) => $c->filter(
            fn ($e) => (int) $e->branch_id === $effectiveBranchId || in_array($e->id, $invitedEventIds, true)
        )->values());

    // ... unchanged ...

    if (!empty($eventIds)) {
        $invitationStatuses = DB::table('calendar_event_invitations')
            ->whereIn('invitee_user_id', $identityIds)      // was: where(..., $user->id)
            ->whereIn('event_id', $eventIds)
            ->pluck('status', 'event_id');
        // ...
    }
```

**Line count:** ~10–15 lines touched (2 new lines at the top, 4 one-word-ish swaps, one `when()` guard condition). Smaller than cc1's 20–40 line estimate because no reordering is needed — that part's already done.

**What does NOT change:** the `visibilityResolver->filterVisible()` call, colour resolution, layer_key computation, privacy redaction (`applyPrivacyFor`), the unacknowledged-decline count (that one's keyed by event, not by viewer identity — correctly untouched), and all 10 call sites (the method's signature doesn't change).

**What could break:**
- **Nothing regresses.** Every change is a pure widening: `in_array($x, $identityIds)` where `$identityIds = [$id]` for any non-assistant is identical to `$x === $id`. `effectiveBranchId()` returns `$this->branch_id` when there's no session override, identical to today for anyone who's never used the branch switcher. There is no code path where this fix makes a user see *less* than they see today.
- **Behaviour visibly changes for two populations, both intentionally:** (1) any assistant currently on 'own' or 'branch' calendar scope — they'll start seeing their agent's own-owned events and invitations addressed to the agent, which is the AT-267 promise, currently broken; (2) a multi-branch admin using the branch switcher — their calendar will finally follow the switch instead of silently reverting to their home branch. Worth a one-line mention in the deploy notes so it isn't mistaken for a new bug.
- **Performance:** `dataIdentityIds()` is memoized per-user (confirmed in `User::activeAssistantAssignment()` — it caches on `$this->assistantAssignmentMemo`), and `applyFilters()` is called at most twice per request (verified across all 10 call sites). No new queries beyond what the AT-267 scope resolution already pays for elsewhere on the same request.

**How to prove it — same DB workaround as the last two tasks:**
`applyFilters()` is `private`, so it can't be called directly the way `dismiss()`/`complete()` were — either promote it to `protected` (defensible: it's already effectively an internal collaborator called from 10 places in the same class, and testability is a legitimate reason) or invoke it via `ReflectionMethod::setAccessible()`. Either way, the test hand-builds the same minimal schema as `CalendarDismissReasonAuditTest` (`calendar_events`, `calendar_event_invitations`, `properties`, `agencies` — no new tables needed) and proves, against the real method:
1. An assistant with `scope='own'` sees an event owned by their assigned agent (the AT-267 regression this fix closes).
2. An assistant with `scope='own'` sees an event their *agent* was invited to, not just ones addressed to the assistant directly.
3. A user with a `view_as_branch_id` session override and `scope='branch'` sees events in the overridden branch, not their home branch.
4. A plain, non-assistant, non-switched user's result set is byte-for-byte identical before and after (the no-regression guarantee, made concrete).

I have not written or run this test — Johan's instruction was investigation only, and I haven't touched `applyFilters()`. This is the test I'd write when given the go-ahead.

---

## 1. PERSON-PICKER

### 1.1 What already exists

- **The breadth ceiling is already computed and enforced.** `PermissionService::calendarScope($user)` returns `own`/`branch`/`all`, sourced from `role_permissions` (`command_center.calendar` module, keyed by role name + agency). `PermissionService::clampScope($requested, $ceiling)` already exists and already pulls any client-requested scope back to what the role permits — this is the exact mechanism the My/Branch/All toggle on the calendar page uses today. **This is the correct scope source for the picker**, not `roles.oversight_scope` (see 1.2).
- **The calendar query already accepts multi-value array filters over querystring**, applied via `->whereIn()` inside `applyFilters()`: `types[]=...` and `categories[]=...` are live today (`$typeFilter = $request->input('types', [])`, same for categories). A `people[]=` filter is a direct, precedented extension of a pattern already proven at every one of `applyFilters()`'s 10 call sites — not a new mechanism.
- **Per-user persisted preferences already exist and are exactly this shape.** `CalendarUserPreference` (table `calendar_user_preferences`) already has `calendar_layers` (JSON array, "per-user calendar layout memory... layer toggles", AT-164) and `calendar_deck_layout`. A new `calendar_selected_people` JSON column follows the identical, already-established convention — additive migration, one `$fillable`/`$casts` entry, same read/write pattern the layer toggle already uses (server-injected on page load per `index.blade.php`'s "SAVED DEFAULT" comment at line ~2821).
- **"Everyone in the agency" / "everyone in this branch" is already a solved, working query** — `Admin\UserManagementController::index()` does exactly this today (`User::where('agency_id', $agencyId)`, `->where('branch_id', ...)`), and `Oversight\OversightService::agentsInScope()` is an even closer precedent: `if ($scope === 'branch') { $query->where('branch_id', $manager->branch_id); }` else agency-wide (via `User`'s own `AgencyScope` global scope). Both confirm the underlying data model answers these two questions cleanly today — `User.agency_id` and `User.branch_id` are enough, no new columns needed.

### 1.2 Decision Johan has to make #1 — which scope field drives the picker

Two candidate scope fields exist in the codebase, and they disagree with each other on live data right now:

| Field | Purpose | Values | Current state on staging (= live) |
|---|---|---|---|
| `role_permissions.scope` (via `PermissionService::calendarScope()`) | Drives which calendar EVENTS a role sees (`scopeVisibleTo()`, `applyFilters()`) | own / branch / all | Populated per role/agency; this is the field cc1 found mis-set for admin/branch_manager |
| `roles.oversight_scope` (via `OversightService::agentsInScope()`) | Drives which AGENTS a manager sees oversight-feed data for (unrelated feature, 7-category signal aggregator) | branch / agency | **NULL for every role, every agency, checked directly on staging** (`SELECT ... FROM roles` — 16 rows checked, admin/branch_manager/agent/owner/super_admin across 4 agencies, `oversight_scope` is NULL on all of them). Defaults to `'branch'` when null.

`roles.oversight_scope` reads as the more literal match to Johan's phrasing ("branch" / "agency") and `OversightService::agentsInScope()` is almost exactly the method the picker needs — but it is **currently unpopulated everywhere**, and its null-default of `'branch'` would silently cap an admin's picker to their own branch, the opposite of what's wanted, until every agency's admin/branch_manager roles get a value backfilled.

**Recommendation: use `PermissionService::calendarScope($user)`, not `oversight_scope`.** It's the same value already gating which events exist in the result set the picker is filtering, so picker-breadth and result-breadth can never disagree with each other — and once cc1's role_permissions fix lands, it's correctly populated for exactly the roles that need it, with no separate backfill. This also makes "agents excluded" fall out for free (see 1.3) rather than needing a role-name check.

This is Johan's call, not mine to bake in silently — the two fields exist for genuinely different features and reusing one for a new purpose is a real design decision, not a mechanical detail.

### 1.3 Decision Johan has to make #2 — what "agents excluded" means

Johan's exact phrasing: *"Admin picks anyone in the agency, branch manager anyone in their branch, multi-select, agents excluded."* Read as one flat list of three clauses, this is ambiguous between two readings:

- **Reading A — the picker itself isn't offered to agents.** If the picker's availability is gated on `calendarScope($user) !== 'own'` (per 1.2's recommendation), this is automatic: an agent's calendar scope ceiling defaults to `own`, `clampScope()` already pulls any wider request back down to it, and a picker built on top of that ceiling has nothing to show an 'own'-scoped user anyway — the picker control simply doesn't render for them. No role-name check needed anywhere.
- **Reading B — agents are excluded from the *pickable list***, i.e. an admin's agency-wide multi-select shows branch managers/admins/owners but not rank-and-file agents. This needs an explicit filter on the candidate list — and it should NOT be `->where('role', '!=', 'agent')`: `RoleManagerController` shows agencies can create custom roles that clone an 'agent' role's permission defaults under a different name (e.g. "Junior Agent"), so a literal string exclusion is fragile against custom roles. If Johan means Reading B, the robust version is an **allowlist** of management-tier roles (admin/branch_manager/owner/super_admin, or whatever `oversight_scope IS NOT NULL` ends up meaning once populated), not a denylist of 'agent'.

I lean toward Reading A reading naturally from the sentence structure (three clauses about who gets what breadth of picker, not a clause about the picker's contents) — but this changes what gets built, so it needs Johan's confirmation before implementation, not an assumption baked into the first draft.

### 1.4 Multi-select — where selections live, and the resulting query

- **Persistence:** `calendar_user_preferences.calendar_selected_people` (new nullable JSON column, array of user ids), read/written exactly like the existing `calendar_layers` column — server-injected as the page's default selection on load, updated via the same preference-save endpoint pattern already in `CalendarController` (three existing call sites read/write `CalendarUserPreference::firstOrNew(['user_id' => $user->id])`).
- **Validation on save/use:** every id in the submitted `people[]` array must be a member of the set `calendarScope($user)` currently permits (agency-wide list if `all`, branch list if `branch`) — reject or silently drop anything outside that set server-side. This is not optional hardening; without it, a crafted request could probe which user ids exist in another branch/agency.
- **The query shape**, once `applyFilters()` is fixed (§0): a `people[]` filter is a fifth `->when()` clause in the exact same place `$typeFilter`/`$categoryFilter` already sit — `->when(!empty($personFilter), fn ($c) => $c->filter(fn ($e) => in_array((int) $e->user_id, $personFilter, true)))`. It composes with, not replaces, the existing own/branch/all scope filter — selecting people narrows further *within* whatever the role's ceiling already allows; it can never widen past it. Whether "viewing person X's calendar" should also surface events X was invited to (not just events X owns) is a real product question — I'd default to yes for consistency with how the marker in Part 2 treats invitations, but it's a small, callable-out decision, not a technical blocker.

### 1.5 Is this mostly a UI job, or does the scope logic need work first?

**Both, in this order:** (1) the `applyFilters()` fix in §0 is a hard prerequisite — without it, `scope='branch'`/`'own'` results are already dropping events the picker would otherwise correctly surface, so testing the picker against broken data would be worthless; (2) `role_permissions` needs the data fix cc1 already identified (admin/branch_manager scope values), independent of any code change; (3) after both of those, the picker itself — candidate-list endpoint, multi-select UI component, the one new `->when()` clause, the one new preference column — is genuinely a UI-and-plumbing job on top of scope logic that already works. No new visibility/permission primitives need inventing.

**Rough size** (once §0 and the role_permissions data fix are done): a candidate-list JSON endpoint (agency or branch member list, ~20 lines), a multi-select UI component (new, no existing calendar-page precedent to copy — the AI-badge/layer-toggle patterns are the closest visual precedent but not a multi-select control), the preference column + migration, and the one `applyFilters()` clause. Small-to-medium; the ceiling-scope and persistence groundwork being already in place is what keeps it small.

---

## 2. INVITED-BY MARKER

### 2.1 What already exists

- **Invitation status is already computed at render time, batched, for the tile-bearing views.** `applyFilters()` (lines 2471–2481, current) already runs one batched query per page load — `DB::table('calendar_event_invitations')->where('invitee_user_id', $user->id)->whereIn('event_id', $eventIds)->pluck('status', 'event_id')` — and attaches `$event->user_invitation_status` to every event object before the view ever renders. **No per-tile query needed; the plumbing for "is this invitation status available at render time" already says yes**, for `status` specifically. (This query has the same bare-`$user->id` bug as §0 — same fix, same commit.)
- **The full invitation record, including who sent it, is already fetched and shown — but only in the detail panel, not on the tile.** `CalendarController::show()` (lines 774–776, 971–976) queries the full `CalendarEventInvitation` row and returns `inviter_name` (resolved via a `User::withoutGlobalScopes()->find()` lookup), `status`, `response_at`, and a respond URL, in the JSON the event panel already consumes when a user clicks a tile open. **"Who invited you" is a solved problem at the panel level today.**
- **Partial marker styling exists in month/week views, not day view, and only for pending/tentative — never for accepted.** `_month-block.blade.php` and `_week-row.blade.php` both read `$evt->user_invitation_status` and apply a dashed border + "PENDING" text label for `pending`/`tentative` status. Grepped `_day-column.blade.php` and the inline day-view block in `index.blade.php` (the hour-grid tile renderer, ~line 703–724, the one showing time + title + category) — **zero invitation-status handling of any kind.** Once a user accepts an invitation, `user_invitation_status` moves out of the `pending`/`tentative` branches into nothing — the tile renders with no marker at all, anywhere, in any view. This is precisely Johan's complaint: an accepted invitation looks identical to your own event, everywhere, and a pending one only looks different in month/week, never in day.
- **A reusable small-badge component pattern already exists**: `<x-ai-badge size="xs" />` (`resources/views/components/ai-badge.blade.php`) — a `title`-tooltipped, size-variant inline `<span>` badge, already used inline in the day-view all-day tile (`index.blade.php` ~line 647: `@if($evt->created_by_ai)<x-ai-badge size="xs" />@endif`). An `<x-invited-badge>` following the identical shape (props, size variants, tooltip-on-hover for the inviter's name) is a direct copy-adapt, not a new pattern.

### 2.2 What has to be built

1. **Extend the existing batched query** in `applyFilters()` (same fix commit as §0, or immediately after — same method, same call site) to also select `inviter_user_id`, and add one more small batched lookup (`User::whereIn('id', $inviterIds)->pluck('name', 'id')`) to resolve names — one extra query per page load, not per tile, consistent with the "batch, not N+1" discipline the method's own comments already insist on (see the "Fix 4 — single query, not N+1" comment at line 2471). Attach `$event->invited_by_name` (or similar) alongside the existing `$event->user_invitation_status`.
2. **A new `<x-invited-badge>` Blade component**, modeled on `<x-ai-badge>` — accepts the inviter's name and response status, renders a compact icon/label, `title` tooltip for the full "Invited by X — pending/accepted/tentative" text.
3. **Wire it into all three tile renderers** that currently lack an "accepted" marker: `_month-block.blade.php`, `_week-row.blade.php` (both currently only handle pending/tentative — extend to render the badge for `accepted`/`tentative` too, not just pending), and the day-view hour-grid tile in `index.blade.php` (currently has none at all — this is the bigger of the three edits, since there's no existing invitation-status plumbing there to extend).
4. Decide what the badge shows (2.3).

### 2.3 Decision Johan has to make #3 — what the marker shows

You know from `ad9399923` that `CalendarEventInvitation` carries `status` (pending/accepted/tentative/declined), `inviter_user_id`, and `response_at`. The panel already shows all of it. For the *tile* (compact, space-constrained, one of potentially several tiles in a day cell), the options are:

- **Icon-only, generic "invited" marker** (cheapest, matches the existing pending/tentative dashed-border convention, name available on hover via `title` and in full on click via the already-working panel) — smallest visual footprint, least information density on the grid itself.
- **Icon + inviter's first name** ("Invited by Sarah") directly on the tile — matches Johan's literal phrasing ("'Invited by' marker") most closely, costs more horizontal space on an already truncated tile (`Str::limit($evt->title, ...)` is already fighting for room in the month/week chips).
- **Icon + response state, name deferred to hover/panel** (e.g. a dot colour or badge variant for pending vs. accepted vs. tentative, name only on hover) — keeps today's pending/tentative visual distinction and adds the missing accepted-state marker, without growing tile text.

Given Johan's own wording leads with "Invited by," I'd default to the second option with the first as a compact fallback on the narrowest tiles (month view chips are already the tightest space in the UI) — but this is a genuine design call, not a technical one, and belongs to Johan.

### 2.4 Rough size

Small. The expensive part (a batched, no-N+1 query surfacing invitation data at render time) is already built and already proven correct (it's what the pending/tentative styling reads today) — the fix in §0 extends it by one field and one lookup, not a new mechanism. The new work is one Blade component (copy-adapt from `x-ai-badge`) and wiring it into three tile partials that don't currently call it (two of which need only a conditional added to an already-open `@php` block; the day view needs the block added). No migration, no new table, no new query pattern.

---

## 3. Summary for Johan

1. **applyFilters() fix first, on its own, before either feature.** ~10–15 lines across 4 sites in one method, pure widening (nothing that works today stops working), no test exists today so one ships with the fix. I'd write it next if you give the go-ahead — not done yet, per your instruction.
2. **Person-picker:** the hard part (scope ceiling, agency/branch member queries, per-user persistence pattern) already exists and works; the picker is a UI-and-plumbing job on top of it, gated on the applyFilters() fix landing first. Needs your call on: which scope field (§1.2, I recommend `calendarScope()` over the currently-unpopulated `oversight_scope`), and what "agents excluded" means (§1.3, I lean toward "no picker for agents" over "agents never pickable").
3. **Invited-by marker:** the data is already computed at render time for `status`; extending it to also carry the inviter's name is one extra batched query, not a new mechanism. The real gap is that three tile renderers (month, week, day) don't show anything for an *accepted* invitation today, and day view shows nothing at all. Needs your call on what the badge displays (§2.3).

No code was written or changed for either feature or for applyFilters(). This document and the diagnosis above are the full deliverable for tonight.
