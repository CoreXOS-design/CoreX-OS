# AT-241 — buyer-pipeline calendar appointment: WRITE-path fix (READY TO LAND)

**Date:** 2026-07-14 · **Lane:** m1 · **Branch:** `AT-241` (base `2a563ae5`, the live-candidate)
**Status:** BUILT + PROVEN on corex-dev (real vendor). Deploy held for conductor GO (dual-deploy).

---

## Spec conformance
Governing specs:
- **`.ai/specs/multi-tenancy.md` §1** (BelongsToAgency auto-fills `agency_id` from
  `effectiveAgencyId()`) and **§2** (AgencyScope: a NULL `agency_id` is an **orphan** —
  invisible to tenants, surfaced only via the owner-role bypass). The fix makes the manual
  calendar-event write derive its agency from the same `effectiveAgencyId()` source, and lets a
  genuinely agency-less super-user event persist NULL (a legitimate orphan) instead of being
  mis-stamped into a tenant.
- **`.ai/STANDARDS.md` Rule 17** (agency/branch-context safe pattern) — WRITE clause: *"Derive
  [agency] from the domain object … OR persist NULL for a legitimately global row (only if the
  column is nullable) … NEVER stamp a hardcoded `1` or a sentinel `0` into a NOT-NULL/FK agency
  column."* This fix is the literal application of that clause.

No calendar-module spec section governs agency stamping specifically; the two above are the
governing authorities.

---

## Root cause (the WRITE path)
`CalendarEventCreator::create()` (the single code path behind **both** appointment-creation
entry points — web `command-center.calendar.store` **and** mobile `v1.command-center.calendar.store`)
stamped the event agency as:

```php
'agency_id' => $user->agency_id ?: 1,   // BUG
```

This reads the **raw** `agency_id` column and hardcodes agency **1** when it is NULL. It ignores
the **acting context**. For a super user whose raw column is NULL but who is acting inside an
agency — the reproduced shape being an **is_owner-role user seated at a branch** (`effectiveAgencyId()`
derives the branch's agency, e.g. 2) — the event is stamped **1**, not 2.

Why it becomes **invisible** (not a 500): `BelongsToAgency`'s creating hook only force-scopes
*ordinary* users. For an **unscoped owner** (is_owner + no switcher override) it *honours* the
explicit value — so the buggy `?: 1` survives. The read side then rejects it:
`CalendarVisibilityResolver::canSee()` first runs an **agency-isolation guard**
(`effectiveAgencyId()=2` vs `event.agency_id=1` → mismatch → `return false`) **before** the
"creator always sees their own" check — so even the creator cannot see their own appointment.

Environment sensitivity (why yesterday's `super_admin` test passed yet staging failed): the bug
only bites when `isOwnerRole()` is true (owner/super_admin roles are `is_owner`, seeded by a
migration → present on staging). `super_admin` additionally short-circuits `canSee()` to `true`,
so it never *shows* the invisibility — the true reproduction is a **non-super_admin is_owner**
(owner) role.

Second failure shape from the same line: on any box where **agency id 1 does not exist** (e.g.
auto-increment has moved past 1), `?: 1` is a hard **FK-1452 500** — demonstrated in the red-proof
below.

`CalendarEventService::createManualWithLinks()` carried the identical `?: 1` landmine (currently
**uncalled** — superseded by CalendarEventCreator) and was neutralised in the same pass so the
class is dead across the file.

---

## The fix (root-cause, guard-first, no shortcut)
1. **Event write** → `'agency_id' => $user->effectiveAgencyId()`. The acting-context agency;
   **NULL** when genuinely agency-less. `calendar_events.agency_id` is nullable, so NULL is a
   legal, correct value (a global/personal super-user event = orphan, per multi-tenancy §2).
   Ordinary agents are unaffected — BelongsToAgency still force-scopes them.
2. **Child rows mirror the parent exactly** (`calendar_event_links`, `calendar_event_invitations`):
   `$agencyId = $event->agency_id !== null ? (int) $event->agency_id : null`. No sentinel — a
   link/invitation cannot belong to an agency its event does not.
3. **Migration `2026_07_14_090000`** makes the two child `agency_id` columns **nullable**
   (`ON DELETE SET NULL`), so they can faithfully mirror the nullable parent. Previously NOT NULL
   — which is what forced the sentinel that caused the leak (`?: 1`) and would cause a fresh
   FK-1452 under a mechanical `?: 0`. Existing non-NULL rows untouched; down() re-backfills then
   restores NOT NULL.
4. Same fix applied to the dead `CalendarEventService` path (landmine kill).

**Entry points covered:** both `command-center.calendar.store` (web) and
`v1/legacy command-center.calendar.store` (mobile) funnel through `CalendarEventCreator::create()`
— one fix reaches every ingress.

---

## Proof (input paths exercised)
Test file: `tests/Feature/Calendar/BuyerPipelineAppointmentTest.php` (rewritten — the old
`super_admin` "repro" never captured the invisibility, since `canSee()` short-circuits super_admin).

**GREEN — with fix (`OK (4 tests, 16 assertions)`):**
- `agency_agent_creates_and_links` — CONTROL (ordinary agent): event + link stamped the agent's agency.
- `branch_seated_owner_event_takes_effective_agency_and_is_visible` — THE REPRO: event stamped
  agency **2** (not 1), link mirrors, **`canSee()==true`** (visible to creator).
- `null_context_super_user_event_is_agencyless_not_agency_one` — LEAK GUARD: agency-less super
  user's event stamped **NULL** (not filed into agency 1); an agency-1 agent `canSee()==false`.
- `null_context_super_user_with_agent_attendee_does_not_500` — MIGRATION PROOF: null-agency event
  **with an agent attendee** creates link + invitation with NULL agency — no FK-1452.

**RED — buggy `?: 1` reinstated (proves the reproduction):**
- `branch_seated_owner…` → `Failed asserting that 1 is identical to 2` (wrong-agency stamp → invisible).
- `null_context_super_user…agencyless` → `SQLSTATE[23000] … 1452 … calendar_events_agency_id_foreign`
  (hard FK-1452 500 on a box where agency 1 isn't present).

**Live Tinker (rolled back, no dev-DB writes):** branch-seated owner, raw agency_id NULL,
`effectiveAgencyId()=27` → `event.agency_id=27`, `link.agency_id=27`, `canSee=true` → **PASS ✓**.

---

## Verification checklist
- [x] `php -l` clean — CalendarEventCreator, CalendarEventService, migration, test.
- [x] `view:clear` / `route:clear` / `cache:clear`.
- [x] Single relevant test file green (4/4, 16 assertions). Red-proof confirms the reproduction.
- [x] Schema snapshot regenerated (`database/schema/mysql-schema.sql`) — both child columns now
      `DEFAULT NULL … ON DELETE SET NULL`; also picked up 5 base tables the committed AT-241
      snapshot was stale on (proforma + deal-sync-settings from `2a563ae5`).
- [x] Full `dev-check` / broad suite **NOT** run — Non-Negotiable #13 (held for Johan / final merge).
- [x] Live super-user end-to-end proof (Tinker + HTTP feature tests).

## Deploy (on conductor GO — dual-deploy)
Standard sequence per host (Staging host + QA1): `git pull` → `php artisan migrate --force`
(runs `2026_07_14_090000`) → `deploy:sync-reference-data` → view/route/config clear → reload the
correct php-fpm pool → restart the queue worker. The migration is a nullability widen (fast,
reversible). No reference-data rows added.

## Class note (batch ticket — NOT this fix)
`CalendarEventService::searchAttendees()` (`:639`) still reads `$user->agency_id ?: 1` — a READ
that returns agency-1 attendees for a null-agency user. It is section-1c of
`.ai/audits/2026-07-13-agency-context-class-audit.md` (batch ticket), not the write bug; noted for
completeness.
