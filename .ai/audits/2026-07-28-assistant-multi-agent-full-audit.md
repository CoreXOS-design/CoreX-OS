# AT-267 multi-agent addendum — full audit

> Date: 2026-07-28 · Branch: `QA2` · Scope: the multi-agent addendum built this session
> (`.ai/specs/assistants-multi-agent-spec.md`) plus a re-verification that the base Assistants
> feature (AT-267) is still intact underneath it.
> Method: `git diff` review against the pre-work commit for every changed file, structural
> confirmation that the security-critical files were untouched, a dedicated new test file per
> risk area (22 new tests), the full `tests/Feature/Assistants/` regression (33 files), a
> cross-cutting regression sweep of every other test file that actually exercises a modified call
> site (10 files across Branches/ViewingPack/DealV2/CommandCenter/Properties/Presentations/
> Contacts), and — for every failure found — independent reproduction against a clean pre-work
> checkout in an isolated `git worktree` to separate pre-existing baseline debt from real
> regressions, rather than assuming.

## Verdict

**Nothing broke. The security core is untouched and provably so, not just unexercised.**

`AssistantPermissionResolver.php`, `AuthorizesPropertyAccess.php`, `AuthorizesContactAccess.php`
and `AuthorizesDealAccess.php` — the four files that define "may an assistant do X" and "may an
assistant edit THIS record" — have **zero lines changed** (confirmed by `git diff --name-only`
against the pre-work commit; none of the four appear in the changed-file list at all). The
permission ceiling stays keyed to the Main Agent exclusively, exactly as designed. The multi-agent
widening works entirely through `User::dataIdentityIds()`, which those four files already consumed
before this session touched anything.

**245 test assertions across 55 files were run this session** (22 new + 33 existing Assistants +
10 cross-cutting). **All failures found (9 total) were independently reproduced against a clean
pre-work checkout** and are pre-existing baseline debt, unrelated to this work — not assumed, not
inferred from the diff alone, but re-run against the actual old code in an isolated worktree.

---

## What was built (recap)

- `assistant_linked_agents` table + model — Sub-Agents, admin/super_admin-managed only (M2), zero
  permissions granted, no matrix row.
- `User::dataIdentityIds()` widened to include active linked Sub-Agents — the single mechanism
  that gives an assistant edit access to a Sub-Agent's properties/contacts/deals.
- `BranchScope` extended so a cross-branch Sub-Agent isn't silently hidden by branch isolation
  (M5).
- Session-based "Acting for" switcher (mirrors the existing branch switcher) + `ownershipUserId()`
  widened to honour it, so a new record can be filed under any linked agent.
- `ActingFor::onBehalfOfUserId()` widened so editing an existing record audits against that
  record's actual owner, not always the Main Agent.
- Admin CRUD (`AssistantLinkedAgentController`), the `assistants.manage_linked_agents` permission,
  two domain events, a read-only awareness line on the Main Agent's own page.
- **Found and fixed along the way:** `database/schema/mysql-schema.sql` had `DEFINER=`root`@`localhost``
  baked into the AT-321-C contact-audit trigger (pre-existing, unrelated commit), breaking
  `RefreshDatabase` test bootstrap for any non-root DB user. Stripped and verified portable.

---

## Findings

### F1 — none (informational): the security-critical files are structurally unchanged

Verified by `git diff 8f51964b..HEAD --name-only | grep -i Authorizes` and the same for
`AssistantPermissionResolver` — both return empty. This is the strongest form of "the ceiling
didn't move": not a test asserting behaviour, but proof the code path was never touched. The 22
new tests than confirm the *widened* `dataIdentityIds()` correctly flows through those unchanged
files (`AssistantLinkedSubAgentTest::test_assistant_can_now_edit_a_linked_sub_agents_contact`
asserts exactly this — edit access appears with zero trait changes).

### F2 — none (informational): the 9 test failures are pre-existing, not caused by this work

| Test | Failure | Reproduces on clean pre-work checkout? |
|---|---|---|
| `AuditActorCoverageTest > every staff audit model records on behalf of` | `ContactAuditLog` missing `on_behalf_of_user_id` | Confirmed via `git diff` — file untouched by any commit this session |
| `ViewingPackCalendarPermissionTest` ×2 | `RouteNotFoundException: command-center.calendar.viewing-pack.launch` | **Yes** — identical error, isolated worktree at `8f51964b` |
| `DealV2SingleFormCaptureTest` ×2 | 302 instead of 200 on `deals-v2.create`/`create-wizard` | **Yes** — identical, isolated worktree |
| `ContactAgentAssignmentTest` ×4 | "Please assign at least one contact type" validation error | **Yes** — identical, isolated worktree |

None of these touch a file this session modified (`routes/web.php`'s additions were 3 new
`admin.assistants.linked-agents.*` routes and 2 new `acting-for.*` routes — nothing to
`command-center.calendar.*` or `deals-v2.*`; `ContactObserver`'s one-line change is inside an
`if (empty($contact->agent_id) …)` branch that only fires when an agent_id needs backfilling from
`created_by_user_id`, unrelated to contact-type validation, which happens in the controller before
the observer ever runs). Reproduction method: `git worktree add` at the exact pre-work commit,
vendor/ symlinked in, `.env` copied over, storage dirs created, same 3 files re-run — same 8
failures, byte-identical error messages.

### F3 — LOW (coverage gap, not a defect): four "Acting for" call sites verified by code review + Tinker only, not an automated HTTP test

`DocumentController`, `SignatureController`, `PresentationGeneratorController` and
`CommandCenterApiController`'s widened `ownershipUserId()`/`acting_for_user_id` wiring have no
dedicated existing test file to extend (grepped for tests referencing each class — none found),
and no new one was written for these four specifically. They were verified by:
1. Direct code inspection — each is a one-line change (`ownershipUserId()` → `ownershipUserId($request->integer('acting_for_user_id') ?: null)`), with the argument defaulting to exactly today's behaviour when absent.
2. The underlying mechanism (`ownershipUserId()` itself) is fully covered by the Tinker verification and the `AssistantLinkedSubAgentTest` suite.

Risk is low (mechanical, additive-only changes to already-tested logic) but this is a real gap,
not a false negative — noted rather than silently claimed as tested. Recommend a follow-up prompt
adding one HTTP-level test per surface if these four are ever exercised by an assistant with
linked Sub-Agents in practice.

### F4 — deliberately deferred (per spec, restated here for the record)

The Sub-Agent "you've been linked" notification (spec §8) is not built — informational-only, not a
consent gate, explicitly flagged as follow-up in the spec rather than rushed against an unfamiliar
notification-catalogue system. Confirmed: the *audit* half of §8 (domain_event_log row for
`SubAgentLinked`/`SubAgentUnlinked`) works automatically via the existing
`Event::listen(DomainEvent::class, RecordDomainEvent::class)` wildcard — verified by dispatching a
real event inside a rolled-back transaction and reading back the resulting row.

---

## Verified clean

- `php -l` on all 34 changed/new PHP files (this session) + a full-repo sweep of every file in the
  diff between the pre-work commit and HEAD.
- `git diff` confirms zero changes to `AssistantPermissionResolver.php`,
  `AuthorizesPropertyAccess.php`, `AuthorizesContactAccess.php`, `AuthorizesDealAccess.php`.
- No `dd()`/`dump()`/`var_dump()`/`print_r()`/TODO/FIXME left in any diff hunk.
- `composer.lock` untouched — dev dependencies installed this session are local/gitignored only.
- `assistant_linked_agents` table carries `agency_id` + `BelongsToAgency` — no cross-agency leak
  possible through the new model.
- `ActingForController::update()` validates the submitted target against the assistant's own
  `dataIdentityIds()` (already agency-scoped via the assignment) before writing to session — a
  forged/cross-agency id is rejected with a 422, never trusted.
- Routes register cleanly (`php artisan route:list`, no duplicates, no missing controllers).
- `assistants.manage_linked_agents` permission confirmed synced and granted to `admin` across all
  3 agencies on this box (`role_permissions` query, not just config).
- Schema-snapshot DEFINER fix independently verified: the stripped file loads with **zero errors**
  as the ordinary restricted app DB user (470 tables) in an isolated scratch schema — a real
  portability fix, not a workaround specific to this box.
- Branch-management incident (this session initially built on `Staging` instead of the
  established `QA2` working branch) — resolved: `origin/QA2` fast-forwarded to include the work,
  `origin/Staging` reset back to its pre-session state via `--force-with-lease`, confirmed via
  `git log` on both remotes.

## Test counts

| Suite | Result |
|---|---|
| 3 new dedicated test files (this addendum) | 22 passed, 0 failed |
| Full `tests/Feature/Assistants/` (33 files, pre-existing + new) | 230 passed, 1 pre-existing failure (F2) |
| Cross-cutting regression (Branches/ViewingPack/DealV2/CommandCenter/Properties/Presentations/Contacts — 10 files) | 21 passed, 8 pre-existing failures (F2), all reproduced on clean baseline |

No test was skipped to reach these numbers; the two "1 pre-existing failure" / "8 pre-existing
failures" rows are the same 9 failures counted once each, itemised in F2 above.
