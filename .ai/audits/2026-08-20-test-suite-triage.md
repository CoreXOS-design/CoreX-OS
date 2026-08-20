# Test suite triage — 2026-08-20, finishing last night's work

**Author:** QA1 lane (qa1-deal-external-guard).
**Scope:** triage only. Nothing in this document was fixed. Read-only against application code; live/QA1 databases were touched only with `SELECT`. The only writes anywhere were to this file.
**Why this exists:** last night's session ran the suite for the first time in a week (260 files, 40 failing, 90 failed / 1,443 passed), was asked to triage the 90 by root cause, and the session ended before that landed anywhere Johan could see it. A full triage (`.ai/audits/2026-08-19-test-suite-triage.md`, commit `419ed91ae`) actually was finished and committed — but nobody told Johan it existed, which is the same as it not existing. This document **re-verifies it against tonight's actual code** rather than just re-publishing it, because a claim that's a day stale and never checked is worse than no claim.

## Corrected count — read this note before the numbers below

I could only positively identify **36 of last night's 40 failing files** by name from the committed doc's own text (4 were referenced only in an aggregate "and 15 files" count with no individual name given, so I could not safely reconstruct them). Those 36 were re-run **fresh, tonight**, in one batched `php artisan test` invocation (paying the schema-bootstrap cost once, not 36 times — see the DDL-contention section below for why that matters).

**First re-run came back 111 failed / 175 passed. That number was wrong** — my own worktree setup (`/corex-qa1-wt-triage`) was missing the `public/build` Vite-manifest symlink that the other worktrees I set up tonight already had. Every full-page-render test in the batch was hitting `ViteManifestNotFoundException` and failing for a reason with **nothing to do with the application**. I caught this by reading the actual failure text (not just the count) before writing anything down, fixed the symlink, and **re-ran the entire batch again**.

**Corrected, authoritative count: 85 failed, 201 passed, across the 36 re-run files (286 individual tests).** This is the number below. I'm flagging the correction explicitly rather than quietly using the second number, because if I got this wrong once tonight, the honest thing is to show the check, not just the answer.

This is close to last night's 90/217 split (36 files here vs. last night's 40 — the 4 unidentified files account for most of the gap), and every one of tonight's per-file signatures that I checked matches last night's diagnosis exactly. **No evidence of new regressions since last night** on this branch — expected, since `qa1-deal-external-guard` hasn't taken a new commit since the deeds-capture fix earlier today, and that fix touched a file with no test overlap in this set.

---

## Read this first — the one finding that matters before anything else

**`BranchSplitIsolationTest::test_split_on_hides_another_branchs_property_from_a_plain_agent` is a confirmed, real, code-level bug — not a stale test.**

```
SPLIT LEAK: a Margate agent can read a Port Shepstone property
Failed asserting that true is false.
```

The test creates two branches in one agency, puts a property in Branch A, and asserts a plain agent logged into Branch B **cannot** query their way to it. Today, they can. This is exactly the tenant-isolation mechanism (`BranchScope`) that the Split Branches feature depends on, and it is leaking.

**Then I checked the one fact that decides whether this is an incident or a backlog item — read-only `SELECT` against both databases:**

| | QA1 (`corex_qa1`) | Live (`nexus_os`) |
|---|---|---|
| Agencies with `split_branches_enabled = 1` | **0** | **0** |

**Zero agencies have Split Branches turned on anywhere, QA1 or live. This is not a production incident — nobody is affected right now, because the feature that would expose this leak is not switched on for anyone.** It is a real gap in code that is running in production today, just not reachable by any current tenant's configuration.

**What this means practically: this must be fixed before Split Branches is ever turned on for a real agency — not before then.** If anyone is about to enable it for a customer, that plan needs to pause on this first. Otherwise it is a real but non-urgent backlog item — see Rank 1 below for what I could and couldn't determine about the cause.

I did **not** attempt to fix this — read-only, per the brief.

---

## Groups, ranked by "would a real user notice this"

### Rank 1 — Branch-split isolation leak (1 test) — **broken code, confirmed, zero current impact (see flag above)**

**File:** `BranchSplitIsolationTest`
**Symptom:** with `split_branches_enabled = true`, a plain agent in one branch can read a `Property` row that belongs to a different branch in the same agency.
**What I checked, read-only:** `app/Models/Scopes/BranchScope.php` — the enforcement logic itself reads correctly: it checks the user is authenticated, resolves their effective branch, and applies a `where branch_id = X` unless the user holds `branches.view_all`. I also checked `config/corex-permissions.php` for every role block and confirmed `branches.view_all` is **not** statically granted to the `agent` role anywhere — so this isn't a stale permission default either. The other four tests in the same file (structural decay-stopper, known-gap-list ceiling, split-OFF-is-inert, child-inherits-parent-branch) all **pass** — so this isn't the scope being globally broken, it's specific to this one behavioural path.
**Broken test or broken code?** **Broken code**, with moderate confidence, not fully root-caused. I read the scope implementation and permission config and found no obvious defect in either — which means the actual cause is more subtle than a one-line bug (candidates I did not get to: `User::factory()`'s default attributes possibly not matching what the test assumes, a caching issue in `BranchScope::$agencyToggleCache` across the two `actingAs()` switches even though the test calls `flushCache()`, or `effectiveBranchId()`/`isOwnerRole()` behaving differently than expected for a factory-built user). **This needs someone to actually step through it with `dd()` or a debugger, not more static reading** — I've spent real effort ruling out the easy explanations and didn't want to guess further and be wrong in a security-relevant document.

### Rank 2 — DealV2 entry routes redirect instead of loading (4 tests across 3 files) — **unresolved, investigated deeper than last night, still not root-caused**

**Files:** `DealV2SingleFormCaptureTest` (2), `DealV2OverviewTest` (1), `DealRemarkTest` (1 of its 3 — the other 2 are unrelated, see Rank 9)
**Symptom:** `GET deals-v2.create`, `deals-v2.create-wizard`, `deals-v2.overview` all return **302** where the test expects **200**, for an actor created with `role: 'super_admin', is_admin: true, is_active: true`, and with **both `agency_id` and `branch_id` explicitly set** on the fixture (not null — ruling out the obvious "owner with no agency context" explanation).
**What I ruled out, read-only, tonight (more than last night got to):**
  - **Not `CheckPermission` middleware** — it calls `abort(403, ...)` on failure, never a redirect. A 403 would render an error page with 403 status; the observed status is 302.
  - **Not `AgencyMaintenanceGate`** — it returns a 503 maintenance view on failure, not a redirect, and the test's freshly-created agency isn't in maintenance mode anyway.
  - **Not the CSRF-419-to-redirect exception handler** in `bootstrap/app.php` — that only fires on `TokenMismatchException`, which only ever arises from `VerifyCsrfToken` on state-changing verbs (POST/PUT/PATCH/DELETE). All four failing calls are plain `GET` requests.
  - **Probably not `RequireAgencyContext`** — I traced `effectiveAgencyId()` → `effectiveBranchId()` → `Branch::find($branchId)->agency_id`, and the test's admin has a real `branch_id` set, so this should resolve without needing the `active_agency_id` session override the middleware would otherwise require. I say "probably" because I traced this statically, not by running a debugger against the actual request.
**Broken test or broken code?** **Still unresolved** — same honest conclusion as last night, but with four specific hypotheses now ruled out instead of zero, which should save whoever picks this up real time. The uniform 302 across every route in the group (not just the ones with a shared permission requirement) points at something in the global `web` middleware stack or Laravel's own `auth` middleware, not a feature-specific gate. **This is the second item, after Rank 1, I'd point someone at directly** — not because it's more likely to be user-facing than Rank 1 (Rank 1 is a confirmed leak; this is an unconfirmed redirect), but because "the whole DealV2 create flow 302s for an admin" would be very visible if it were somehow live, and I can't yet prove it isn't a live-reachable gate.

### Rank 3 — Reference data lost by the schema snapshot: `contact_types` rows don't exist in the test DB (≥19 tests) — **broken test infrastructure, reconfirmed, not broken code, not broken data**

**Files:** `ContactTypeAssignmentTest` (15/15 of its tests fail), `ContactAgentAssignmentTest` (4), `ContactStructuredAddressTest` (1 of its 4)
**Symptom, reconfirmed verbatim tonight:** `Failed asserting that two arrays are identical. -Array &0 ['seller','buyer','lessor','lessee'] +Array &0 []` — every canonical `ContactType` lookup returns empty; every contact-form submission requiring a type fails with *"Please assign at least one contact type."*
**Root cause (unchanged from last night, re-confirmed by matching error signature):** the canonical `contact_types` rows are inserted by **data-only migrations**, but `database/schema/mysql-schema.sql` is a schema-only dump — the ledger says those migrations already ran, so `RefreshDatabase`'s fast path never re-executes them and the data never lands. Real environments (QA1, staging, live) got this data through a normal `php artisan migrate` at deploy time, so **this does not affect production** — it's purely a gap in how the test-schema snapshot tool interacts with any migration that seeds data rather than only altering structure.
**Broken test or broken code?** Broken test infrastructure. Structural, will recur on every future data-seeding migration until fixed at the snapshot-tooling level (either exclude data migrations from the "already applied" ledger snapshot, or have `schema:dump` capture reference-table data too). Fixing this one thing clears the single largest block of failures in the whole set.

### Rank 4 — P24-suburb "confirmed" flag not set on a hand-built test fixture (3 tests) — **broken test**

**File:** `ContactStructuredAddressTest` (the other 3 of its 4 failures)
**Symptom:** *"Selected suburb is not confirmed on Property24. Pick a Property24-recognised suburb."* even though the test inserts its own `p24_suburbs` row directly.
**Root cause:** unchanged from last night — `p24_suburbs.p24_verified_at` gates this validation now; the test's raw insert predates that column's use and never sets it.
**Broken test or broken code?** Broken test, same as last night's assessment, reconfirmed by matching error text.

### Rank 5 — Enum-truncation: DB column narrower than what's inserted (11 tests) — **broken test, high confidence, reconfirmed**

**Files:** `DealPipelineWorkOrderConfigTest` (3, column `deal_type` = `'transfer'`), `CdsImportBindingConvergenceTest` (8, column `layout` = `'inline'`)
**Symptom, reconfirmed verbatim tonight:** `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'layout'` — MySQL strict mode turns the truncation warning into a hard failure. Same exact enum value (`'inline'`) at the same line as last night.
**Broken test or broken code?** Likely broken test (the ENUM value the factory sends isn't in the column's current allowed list) — still not confirmed which side is stale (dropped from the ENUM vs. never valid), same open item as last night. A 5-minute check of both `ALTER TABLE ... MODIFY layout ENUM(...)` history vs. the test/factory would settle it.

### Rank 6 — IMAP test-double drift cascades into 4 failures, not 4 separate bugs (4 tests) — **broken test, one root cause**

**File:** `MailboxHealthTest`
**Symptom:** `Call to undefined method class@anonymous::setFetchBody()` in `app/Services/Communications/ImapMailboxPoller.php:112` (2 tests), plus 2 more failures expecting a `MailboxPollFailureNotification` to have been sent that never fires.
**Reading tonight, not done last night:** the 2 "notification never sent" failures are almost certainly **downstream of** the 2 `setFetchBody()` crashes, not a separate bug — if the poll throws before it finishes, the alert-threshold logic that would fire the notification never runs. **One stale IMAP test-double, one root cause, 4 red tests** — exactly the shape Johan's brief predicted for this whole exercise. Not independently confirmed by tracing the actual call graph, but the shared file and adjacent line numbers make it very likely.
**Broken test or broken code?** Broken test — the mock's surface (`Webklex\PHPIMAP`'s query builder) has drifted from the real library, not re-verified against the library itself tonight.

### Rank 7 — Route names changed, tests still call the old ones (route family) — **broken test, reconfirmed**

**Files:** `EventReminderEndpointTest` (3 — `v1.command-center.reminders.due`/`.read`/`.snooze` not defined), `ContactCommunicationSendStatusTest` (4 — `.revert`/`.resend` route names, not individually re-verified tonight but named identically to last night's finding)
**Symptom, reconfirmed verbatim:** `Route [v1.command-center.reminders.due] not defined.` The real route is registered as `command-center.reminders.due` (no `v1.` prefix) — it works, the test's name is stale.
**Broken test or broken code?** Broken test — the reminder feature itself is reachable at its real name.

### Rank 8 — `NOT NULL`/FK columns added, hand-built fixtures never updated (≥6 tests, same family as this morning's own deeds-capture fixes) — **broken test, reconfirmed**

**Files:** `ClientSellerInsightsTest` (4, `properties.agent_id` — *note: this is the exact same missing-column shape I hit and fixed in my own `DeedsCaptureLinkServiceTest` fixture this morning*), `AdminMultiBranchManagerTest` (1, `user_managed_branches` empty after a reportedly-successful save), `DemoSidebarCurationTest` (1, `users.agency_id` FK — no agency exists yet in a fresh test DB at the point this factory runs)
**Broken test or broken code?** Broken test, high confidence — mechanically identical to a pattern already proven correct twice today (once in this morning's deeds work, again here).

### Rank 9 — Everything else: single or small clusters, no shared pattern found (remainder, ~15 tests across ~9 files) — **not worked through individually**

`WhatsAppSendConfirmationTest` (3, send status `'not_delivered'` vs. `'sent'` — possibly connected to WA-device-optimistic-send logic, worth a look given it's the same pipeline as `WaVoiceNoteMediaTest` below but not cross-checked), `WaVoiceNoteMediaTest` (2, encryption-at-rest — the adjacent test that exercises the real decrypt-on-read HTTP path *passes*, so this is a stale raw-disk assertion, not a broken feature), `CommsNavIaTest` / `ContactCommunicationsTabTest` (1 each, stale UI-copy assertions), `IngestFilterTest` (1, `'pending'` vs `'dropped'` classification), `ProvisionalCommReconciliationTest` (1, prune not soft-deleting as expected), `WaSessionWebhookTest` (1, opt-out body-withholding), `WaThreadChatViewTest` (1, unexpected emoji glyph in rendered markup), `DealV2OverviewTest`/`DealTemplateCorrectionsTest`/`InboundCorrespondenceTest`/`DealPipelineDefaultTemplatesTest`/`BuyerPortalLinkIsolationTest`/`CalendarDeadlineAggregationTest`/`PrivateEventVisibilityTest`/`DocumentTypeClassifierTest`/`EctaEsignBlockGuardTest`/`Phase4WebhooksTest`/`Phase5bWebsiteTabTest`/`ActivityDefinitionScopeTest`/`MicCanonicalScoringTest`/`DemoConnectorTest` (1 each, not re-diagnosed individually tonight — last night's document has per-file notes on most of these and nothing in tonight's re-run contradicted the shapes it described; not re-verified line-by-line here in the interest of prioritising the top of this list, per this session's own brief).

`DealRemarkTest`'s other 2 failures (`agent cannot remark on a deleted...`, `agent cannot delete another agent's...`, `timeline interleaves remarks...`) are **not** the DealV2 redirect issue in Rank 2 — different symptom shape, not investigated further tonight.

---

## Per-lane test-schema isolation — the case for it, not a build

Every test run tonight — this triage, and the deeds-capture fix earlier today — cost **3 to 5+ minutes just to bootstrap**, even for a single targeted file. That is not the normal ~25s `schema:dump` fast path this repo is built around (CLAUDE.md non-negotiable #12a). The cause, observed directly via `SHOW FULL PROCESSLIST` while waiting: multiple lanes' test runs are all resetting and reloading the **same** `hfc_dash_test_77`-family schema on the **same** MySQL server at the same time, and `RefreshDatabase` does a full drop-and-reload of the entire ~489-table schema on every single `php artisan test` invocation in this setup (not incrementally) — so two lanes running tests within the same few minutes are fighting over DDL locks on the same tables. This has been the single biggest cost on this lane for two days running, and — from the number of `hfc_dash_test_N` variants seen in `SHOW PROCESSLIST` tonight (`_77`, `_99` both observed) — it's clearly not unique to this lane.

**The mechanism to fix this already exists and doesn't need building — it needs deciding.** `tests/bootstrap.php` already resolves the test database name from a **dedicated env key**, `TEST_DB_DATABASE`, with a hard whitelist (`hfc_dash_test(_N)?`) enforced twice (bootstrap-time and again in `Tests\TestCase::setUp()`). Every worktree already carries its own `.env`. The only thing standing between "shared, contended schema" and "one schema per lane" is **each worktree's `.env` setting a distinct `TEST_DB_DATABASE` value** (e.g. `hfc_dash_test_qa1deals`, `hfc_dash_test_qa1buyers`, one per active lane) instead of every lane defaulting to the same `hfc_dash_test_77`.

What this would look like, concretely, without building it tonight:
- **Naming convention:** one `hfc_dash_test_<lane-slug>` database per active worktree/branch, assigned when the worktree is created (matches the existing `_N` numeric convention closely enough that the whitelist regex barely needs to change — or the regex could be loosened to `hfc_dash_test_[a-z0-9_]+` to allow slugs instead of only numbers).
- **Cost:** each lane's *first* test run after a fresh worktree still pays the full ~25s (or, under contention, longer) bootstrap once — that's unavoidable and already true today. The win is that lane A's bootstrap never again collides with lane B's, so the ~25s stays ~25s instead of degrading to 3-5+ minutes as more lanes run concurrently.
- **Cleanup:** stale `hfc_dash_test_*` schemas from retired worktrees would accumulate on the shared MySQL instance unless something prunes them — worth deciding whether that's a manual step in the worktree-teardown process or a periodic sweep.
- **Not free:** more schemas means more disk/memory footprint on the shared MySQL instance permanently, not just during a test run — worth sizing against whatever headroom that box actually has before committing to N-per-lane rather than a smaller shared pool.

I have not implemented any of this — it's a proposal for Johan/the conductor to decide on, not a change I made.

---

## What this triage does NOT include

- No application file was edited. No test was modified. The only file written was this document.
- The 220 files that passed cleanly last night were not re-run — no value in spending the time twice, and re-running only the 90's files was explicit in tonight's brief.
- 4 of last night's 40 failing files could not be positively identified by name from the committed doc's text and are not included in tonight's 36-file re-run or its counts. They are very unlikely to change the ranking above (Rank 3's contact_types gap is already the largest single group by a wide margin), but they are a real gap in this document's coverage, not a rounding error, and are noted here rather than silently absorbed into "everything else."
- Root causes marked "unresolved" (Rank 1, Rank 2) are exactly that — an honest stopping point after real, documented investigation, not a soft claim of certainty.

## Suggested order for tomorrow

1. **Rank 1 (branch-split leak)** — before Split Branches is enabled for any real agency. Not urgent by the clock, but it blocks a specific future action, so it shouldn't sit indefinitely either.
2. **Rank 2 (DealV2 redirect)** — needs a live debugger trace, not more reading. Worth 15 real minutes given how central DealV2 is.
3. **Rank 3 (contact_types seed data)** — one structural fix (either exclude data migrations from the snapshot ledger, or teach `schema:dump` to capture reference-table data) clears ≥19 tests at once and prevents every future data-seeding migration from hitting the same silent gap.
4. **Ranks 4–8** — mechanical, high-confidence, fast: P24 fixture flag, enum truncation (2-file check), IMAP mock surface, route renames, missing-column fixtures.
5. **Rank 9** — lowest priority, fixture/assertion drift with no evidence of user-facing impact.
6. **Test-schema isolation** — not urgent for correctness, but it is costing every lane real wall-clock time every single day this stays undecided. Worth a five-minute decision even if the implementation waits.
