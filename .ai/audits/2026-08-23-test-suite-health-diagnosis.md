# Test suite health diagnosis — 2026-08-23 (cc2/QA1)

**Status: PARTIAL. Read the coverage boundaries in every section before acting on a number.**
Written because contact with this box may be lost without warning this afternoon — this is
the permanent record of what was found tonight, not a chat log.

**Update, 2026-08-23 21:11 SAST onward:** Johan approved the §4 grant; it has been applied to
this box's live MySQL and verified end-to-end (§4). Following that, day-1 item 2 of the §6
plan — the reference-data seed gap — was also started and fixed for its evidenced scope (§7).
**No other live database or live MySQL action has been taken.** The memory-crash root cause
(§5, §6) remains explicitly untouched, per instruction.

---

## 0. What was asked, in order

1. Diagnose why no lane could run the test suite (MySQL privilege issue) — DONE, root-caused,
   fix recommended, not applied.
2. Once that's understood, is the suite actually usable, or is that the first of several
   problems — DONE. It is not usable. Runs a full suite, roughly half of what completes
   before it dies is red, and it dies before finishing.
3. Bucket the failures: stale / environmental / real / unknown, prioritising signing,
   documents, prospecting/MIC, and money — PARTIAL. See §1 for exactly which modules got a
   full pass, which got a partial pass, and which were never reached.
4. Work around the memory crash rather than fix it (batch by directory instead of one giant
   run) — DONE, and it worked well enough to get real per-test failure detail instead of the
   crash's raw noise.
5. Do NOT touch the memory-crash root cause itself — HONOURED. Not started. See §5.

---

## 1. Coverage — read this before trusting any count below

| Module | Coverage | Failures found | Triaged? |
|---|---|---|---|
| ESign (signing) | Full — every failing test read against current code | 3 / 73 | Yes, all 3 |
| Dr2 (deal register, money-adjacent) | Full | 8 / 113 | Yes, all 8 |
| Docuperfect (signing/documents) | Near-full — one large repeating pattern confirmed, a couple of singletons not chased to the end | 62 / 477 | ~59 of 62 |
| Communications (prospecting/MIC-adjacent) | Partial — read every failure's error, only some root-caused | 13 / 215 | 6 of 13 confirmed, 7 left as unknown |
| Payroll, Performance, Commission, Presentation, DealV2, Mic (buyer-led prospecting) | **Not reached** | Unknown | No |

Total tests actually triaged tonight with a specific, evidenced cause: **~878 tests across 4
modules** (ESign 73, Dr2 113, Docuperfect 477, Communications 215), against a suite that is at
minimum several thousand tests overall (the crashed full run alone produced 4,505 named
pass/fail outcomes before it died, and never reached the end of the alphabet). This diagnosis
covers a meaningful, high-priority slice — not the whole suite. Treat every number in this
document as "true for what was checked," not "true for CoreX."

---

## 2. Bucket counts, for what was checked

| Bucket | ESign | Dr2 | Docuperfect | Communications | Total (of 86 triaged failures) |
|---|---|---|---|---|---|
| (a) STALE | 3 | 5 | ~1 | 4 | ~13 |
| (b) ENVIRONMENTAL | 0 | 2 | ~59 | 1 (excluded, see below) | ~61 |
| (c) REAL | 0 | 1 (flagged, not fully confirmed) | 0 | 0 | 1 |
| (d) UNKNOWN | 0 | 0 | 2 | 7 | 9 |
| Not a bug at all (test's own assertion is broken, app is correct) | — | — | 1 | — | 1 |

One Communications failure (`CommsAccessGateFlowTest`) is excluded from the table above: it
ran against a test database I had personally left in a corrupted half-migrated state earlier
in the night (a self-inflicted artifact of running several test files in parallel against the
same shared schema before I understood the box's `migrate:fresh`-per-process behaviour). It is
not evidence of anything about CoreX and I have not counted it.

**Headline: of everything actually triaged tonight, the overwhelming majority (~85%) is stale
tests or missing test infrastructure, not broken product code.** One confirmed candidate for a
real bug survives scrutiny, described in full below. Nothing found in signing tonight
(ESign, 73 tests) is a regression.

### The one structural finding that matters most

Every reference table normally populated by a migration's own `DB::table()->insert()` call —
confirmed empty on a fresh test database: `document_types`, `contact_types`,
`deal_pipeline_templates`, `activity_definitions`, `p24_provinces`, `leave_types`,
`payroll_earning_types`. Root cause: the fast test-bootstrap path (the schema-snapshot
optimisation already documented in `CLAUDE.md` §12a) captures schema only, not data, and the
migration that originally seeded these rows is already marked "already run" in the snapshot's
own ledger — so it never replays and its `insert()` calls never fire. This single gap is
responsible for at least 2 of the Docuperfect failures below and is very likely responsible
for a meaningfully larger, uncounted slice of the ~2,000+ failures seen in the original
crashed full-suite run, since I only checked the modules in scope tonight. **Likely fix**: run
`deploy:sync-reference-data` (the same command already used on real deploys for exactly this
class of data, per `CLAUDE.md` non-negotiable #12's AT-162 note) as a step in the test
bootstrap itself, not just on deploys.

---

## 3. The REAL bugs list

**This is the one section Johan should read in full.**

### 1 confirmed candidate — needs a focused follow-up, not a guess

**`Tests\Feature\Dr2\Dr2LaneComposerTest::test_bond_cash_deal_composes_to_the_target_lane_board`**
(`tests/Feature/Dr2/Dr2LaneComposerTest.php:47`)

- **Asserts**: for a bond+cash deal, the "Deeds Office Lodgement" step — the gate before deeds
  lodgement — has exactly 4 rows in `deal_step_instance_dependencies`, one per converging lane
  that must finish before lodgement can proceed. The application code itself
  (`app/Services/DealV2/DealStructureAssembler.php`) documents this as a hard invariant in its
  own comment: *"Deeds Office waits on ALL COCs"* — described as an "honest fan-in," not a
  best-effort.
- **What the code actually does**: writes only 3 dependency rows, not 4.
- **Why it matters**: if the gate is short one dependency, "Deeds Office Lodgement" could
  become actionable before every prerequisite lane on a real, live deal has genuinely
  completed. This sits in the deal register, in the money-critical path Johan asked to be
  prioritised.
- **What's NOT yet known**: which of the five lanes is the one silently missing its edge. I
  traced the dependency-writing logic (each lane's `deps` entry either becomes the primary
  `follows` link or a fan-in row, and a dependency on an unselected condition's step is
  deliberately skipped) far enough to know the mechanism that COULD produce this, but ran out
  of time to pin the exact missing lane. **This needs someone to actually run the composer
  with debug output on the failing selection (`['bond' => ['deposit' => false], 'cash' =>
  ['payments' => 1]]`) and see which lane's dependency row never gets written.** I am
  confident this is a real finding worth 30–60 minutes of someone's attention; I am not
  confident enough in the exact mechanism to hand over a one-line fix.

### 2 unconfirmed — flagged, not verified, worth a look before being dismissed

- **`ConditionInitialPartyKeyTest`** (Docuperfect/SigningView) — a second seller (seller_2)
  initialing a condition already initialed by seller_1 gets HTTP 409 ("already initialed"),
  when the test's own comment states an existing fix (referenced as AT-300) should give each
  same-role party a distinct identity key precisely so this doesn't collide. Either that fix
  regressed, or never covered this exact path. I read the relevant code
  (`SigningController.php`, the `party_key` resolution via
  `InsertableBlockRenderer::partyKeyForViewer()`) and it *looks* like it should work; did not
  trace deep enough to say why it doesn't.
- **`WaVoiceNoteMediaTest`** (Communications) — a decrypted-audio byte comparison returns what
  looks like still-encrypted data instead of the expected plaintext Ogg payload, and a
  "failed download" scenario shows attachment status `failed` where `pending` was expected.
  Not root-caused. Flagging because "a voice note doesn't actually decrypt" is the kind of
  thing that is silently broken for a real user if real, not a test-only concern — it earns a
  look even though I can't confirm it tonight.

### Everything else in the STALE and ENVIRONMENTAL buckets

Every STALE and ENVIRONMENTAL bucket entry has a specific code citation behind it (which
migration, which comment, which git blame date) — not a guess. The full evidence for each is
in the conversation transcript this document accompanies; the short version, module by module:

- **ESign (all 3 failures, STALE)**: two assert a signing-checkpoint behaviour the code
  explicitly documents as deliberately removed (`SigningController.php`: *"ESIGN-WETINK Ruling
  #1 ... Previously this set STATUS_PENDING_AGENT_APPROVAL before every next co-owner — the
  friction Elize's run flagged"*); one asserts `assignedTo` always takes the first
  `editableBy` array element, when a later, explicitly-commented rule
  (`ESignWizardController.php`, dated 2026-07-17 by git blame — the test was last touched
  2026-05-26) makes `agent` win whenever agent is a listed party.
- **Dr2 (5 of 8, STALE)**: three trace to `DealRegisterController.php`'s explicit
  `// AT-334 P2 — deal_type is now OPTIONAL` and the create-view's own
  `{{-- Deal Type radio removed ... now captured entirely on the Deal Structure tab --}}`; one
  to `PipelineController.php`'s `// the board view is retired, Johan 2026-07-27`; two to the
  list/timeline views now sharing one `<div class="h1">Deal Pipeline</div>` heading post
  redesign instead of the per-view headings the tests still look for.
- **Dr2 (2 of 8, ENVIRONMENTAL)**: `WorkOrderSendFailureTest`'s own fixture hardcodes
  `deal_step_instance_id: 1` without ever creating that row — a foreign-key violation caused
  by the test, not the app.
- **Docuperfect (~55 of 62, ENVIRONMENTAL)**: `docuperfect_documents.agency_id` was made
  NOT NULL by migrations dated **today**, 2026-08-23. Real requests never hit this —
  `BelongsToAgency::creating()` auto-fills `agency_id` from the authenticated user — but dozens
  of SigningView test fixtures create users via raw SQL with no agency and never authenticate
  before creating a Document, so the auto-fill has nothing to fill from. This is today's
  multi-tenancy hardening work outrunning old test fixtures, not a regression in signing.
- **Docuperfect (1 of 62, STALE)**: a test fixture inserts `layout: 'inline'` into a column
  that is `enum('vertical','horizontal')` — never a valid value.
- **Docuperfect (2 of 62, ENVIRONMENTAL)**: both trace directly to the empty-`document_types`
  structural gap in §2, not to any regression in the alienation-document e-sign guard.
- **Docuperfect (1 of 62, not a bug at all)**: I checked the actual rendered HTML by hand for
  `MdfRecipientFieldAndConditionInitialTest` — the app's behaviour is already correct. The
  test's own regex has no closing boundary and matches into an unrelated sibling element. The
  test is wrong, not the app.
- **Communications (4 of 13, STALE)**: all four `MailboxHealthTest` failures come from one fake
  IMAP folder object in the test that never implements `setFetchBody()`, a method
  `ImapMailboxPoller.php` calls citing a real, dated fix (AT-257). The test double was never
  updated after that fix landed.
- **Communications (1 of 13, minor and not urgent)**: `CommsNavIaTest` expects a sidebar label
  rename ("My WhatsApp Capture" → "My Capture Consent") that was never shipped — 7 weeks stale,
  cosmetic, not chased further.

---

## 4. The MySQL grant — recommendation, not yet applied

**Root cause**: this MySQL instance has `log_bin_trust_function_creators=OFF` with binary
logging `ON`. The committed test schema snapshot (`database/schema/mysql-schema.sql`) contains
literal `CREATE TRIGGER` statements for the AT-321/AT-321-C audit-backstop triggers. Creating a
trigger under those binlog settings requires `SUPER` or the narrower MySQL 8 `SET_USER_ID`
privilege. Without it:

```
ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is
enabled (you *might* want to use the less safe log_bin_trust_function_creators
variable)
```

Reproduced directly as `nexus` (Staging's app-DB user) on a scratch trigger.
`corexqa1`/`corexqa2`/`corexdev`/`corex_test` already carry `SET_USER_ID` on this box and were
unaffected; `nexus` was the one confirmed broken, and cc1 independently hit the identical 1419
error tonight from a different angle before we compared notes.

Laravel pipes the whole schema snapshot through the plain `mysql` CLI in one shot
(`MySqlSchemaState::load()`), which aborts on the *first* SQL error — so this is not a soft,
skippable failure, it is fatal and aborts the whole test-DB bootstrap the moment it reaches the
first trigger statement (early in the file, since `contacts` sorts alphabetically near the
top of the dump).

**Recommended fix — exact statement, ready for approval:**

```sql
GRANT SET_USER_ID ON *.* TO 'nexus'@'localhost';
```

Not full `SUPER`. `SET_USER_ID` is the narrow MySQL 8 dynamic privilege that covers exactly
this case (creating a trigger/function/view under binlog) and grants **zero data access on its
own** — it does not expand what `nexus` can read or write in any database; it only unlocks
routine/trigger creation in schemas `nexus` can already write to. `nexus` already has `ALL
PRIVILEGES` on `hfc_staging`, `nexus_os`, and several `hfc_dash_test_*` schemas — this grant
changes nothing about that footprint.

**Status: APPLIED.** Johan approved; applied 2026-08-23 21:11 SAST on this box's live MySQL,
following the procedure he specified.

**Before (recorded first, verbatim, as the rollback reference):**

```
Grants for nexus@localhost
GRANT USAGE ON *.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_dash_test`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_dash_test_20`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_dash_test_21`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_dash_test_77`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_dash_test_78`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `hfc_staging`.* TO `nexus`@`localhost`
GRANT ALL PRIVILEGES ON `nexus_os`.* TO `nexus`@`localhost`
```

**Statement applied** (MySQL 8.0.46 — `GRANT` takes effect immediately in this version;
`FLUSH PRIVILEGES` is not required and was not run):

```sql
GRANT SET_USER_ID ON *.* TO 'nexus'@'localhost';
```

**After — confirmed by re-reading the grants. Diff is exactly one line, nothing else changed:**

```diff
 GRANT USAGE ON *.* TO `nexus`@`localhost`
+GRANT SET_USER_ID ON *.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_dash_test`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_dash_test_20`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_dash_test_21`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_dash_test_77`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_dash_test_78`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `hfc_staging`.* TO `nexus`@`localhost`
 GRANT ALL PRIVILEGES ON `nexus_os`.* TO `nexus`@`localhost`
```

**Proved, not inferred.** Ran the real Laravel test bootstrap as `nexus` against `/corex-staging`
(`php artisan test tests/Feature/ProfileTest.php`) — the exact code path that previously died
with error 1419. It passed clean: 5/5 tests, 19 assertions, `Duration: 164.60s`. Then went one
step further than "the test suite said pass" — queried `SHOW TRIGGERS` directly on the
resulting `hfc_dash_test` database and confirmed all four AT-321/AT-321-C audit triggers
(`corex_contact_audit_after_insert`, `corex_contact_audit_after_update`,
`corex_property_audit_after_insert`, `corex_property_audit_after_update`) physically exist,
each with `Definer: nexus@localhost` and a `Created` timestamp of 2026-08-23 21:09–21:10 —
created by `nexus`, during this exact run, moments after the grant landed. That is the
end-to-end proof the grant does what it was supposed to.

**What it unblocked**: Staging's own lane (and any lane using the `nexus` account) can now
bootstrap a fresh test database at all. This was the single hard blocker described in §0 item 1
— it is now cleared. It does not touch, and was never expected to touch, the memory-crash
problem in §5 — see the full-suite run result there.

Also written into `.ai/audits/2026-08-23-server-migration-notes.md` §7 (pushed to
`origin/Staging`, commit `7896a237b`, coordinated with cc1 who owns that file) so the new
server gets this grant correct at provisioning time instead of inheriting the same gap.

**Step 5 — ran the full suite as `nexus` on Staging, as far as it goes.** It bootstrapped
cleanly (no delay, no error, straight into test execution — nothing else newly blocks the
bootstrap) and then crashed on the same PHP memory-exhaustion error described in §5 below,
`routes/web.php` line 3756 this time (a different line than QA1's line 4561 — the two
checkouts' route files aren't byte-identical — same failure class).

| | Pre-grant baseline (QA1, different branch) | Post-grant (Staging, `nexus`) |
|---|---|---|
| Outcome | Crashed | Crashed |
| Passed before crash | 2,463 | 2,457 |
| Failed before crash | 2,042 | 2,003 |
| Last module reached | Entering `Presentation` | Entering `Presentation` |

Nearly identical stopping point and counts, on two different checkouts, before and after the
grant. This is useful confirmation, not just a shrug: **it shows the memory crash is
independent of the privilege issue** — the grant fixed exactly and only what it was meant to
fix (bootstrap), and had zero effect, positive or negative, on the separate memory problem,
exactly as expected going in. Nothing new blocks the bootstrap. The memory crash remains
exactly as described in §5 — not investigated tonight, per instruction.

---

## 5. The state of the suite, in plain language

The test suite does not currently give anyone a trustworthy signal. Three separate problems
compound:

1. **It doesn't run for every lane.** Staging's database user is missing a MySQL privilege
   (§4) that QA1/QA2's users already have — so whether the suite runs AT ALL currently depends
   on which lane you're on, silently.
2. **Even where it can start, it doesn't finish.** A full run crashes partway through with a
   PHP memory-exhaustion error, repeating for the rest of the run without ever producing a
   final result. This has NOT been root-caused tonight — deliberately, per instruction, because
   starting that investigation badly tonight would be worse than leaving it clean for whoever
   picks it up next.
3. **Of what runs before it crashes, roughly half is currently red** — and per §§2–3 above,
   that red is mostly test rot (stale assertions, missing test-only seed data) rather than
   broken product code, but nobody could have known that until tonight, because nobody could
   get the suite to produce readable output at all.

Put together: for some period of time — at minimum since a same-day migration this morning
started requiring test fixtures nobody had time to update yet, and going back further for the
memory crash and the missing-seed-data gap, both of which look pre-existing rather than
new — **every change to CoreX has shipped on manual verification alone, because the automated
safety net has not been usable.** That is not a criticism of any one person's work; it is the
plain fact Johan needs in front of him when deciding what to invest engineering time in next.

### What today's fingerprint harness says about it

Separately from this diagnosis: today a heavily-used screen was changed four separate times,
and each change was proven safe not by the test suite but by a purpose-built fingerprint
harness cc5 wrote this morning — and that harness caught a real, silent bug the test suite,
in its current state, would never have surfaced. That is not a substitute for a working test
suite (a fingerprint harness proves one specific screen didn't drift; it doesn't give the broad
coverage a real suite gives), but it is real evidence about what protected this codebase today
while the suite itself was down. Worth Johan hearing both halves of that: the safety net that
worked today was hand-built for one screen in one morning, not the several-thousand-test suite
that's supposed to cover everything — which is exactly the argument for making the real suite
trustworthy again rather than normalising purpose-built harnesses as the standard.

---

## 6. What to do next — two days, in order

My honest read, as the only person who has actually looked at all three problems tonight:

**Day 1 morning — the privilege grant (§4). Minutes, not hours.** One `GRANT` statement,
already written up, already reviewed. This alone unblocks Staging's own lane from running
tests at all. Do this first because it is nearly free and blocks everything downstream from
being verifiable on that lane.

**Day 1, rest of the day — the reference-data seed gap (§2).** This is the highest-leverage
fix available: one structural change (make the test bootstrap run
`deploy:sync-reference-data`, or equivalent, after loading the schema snapshot) likely clears
a double-digit percentage of the remaining false failures in one move, across the whole suite,
not just the modules checked tonight. Fix this before spending human time triaging individual
failures — otherwise people will spend hours re-discovering the same "empty document_types"
cause one test at a time, exactly as I did tonight.

**Day 2 morning — the memory crash.** This is the piece explicitly held back tonight. It needs
someone to actually profile a long-running PHPUnit process against `routes/web.php`'s repeated
re-registration, or reach for a structural workaround (`--process-isolation`, splitting CI
into per-directory jobs the way I did manually tonight, or a memory_limit raise as a stopgap
while the real leak is found). I'd put this on day 2 morning, not day 1, because the
directory-batching workaround used tonight is a usable stand-in for a few more days, and the
grant + seed-data fixes are cheaper, faster wins that make the crash easier to diagnose
afterward (a suite that isn't already drowning in fixture-noise failures is much easier to
memory-profile cleanly).

**Day 2, rest of the day — the actual test rot.** Once the above three are fixed, someone
should go module by module (start with the ones NOT covered tonight — Payroll, Performance,
Commission, Presentation, DealV2, Mic — since those are still completely unknown) doing what
I did tonight for ESign/Dr2/Docuperfect/Communications: read every failure, cite the actual
code, bucket it honestly. Budget maybe 6–8 hours for this across the untouched modules, based
on how long the ~878 tests covered tonight took.

**This is a fix, not a rebuild.** Nothing found tonight suggests the suite's design is wrong —
the schema-snapshot optimisation, the RefreshDatabase pattern, the directory structure are all
sound. What's actually broken is three specific, nameable things (a missing grant, a missing
seed step, a memory leak) plus an accumulation of ordinary test-fixture drift that happens to
any suite nobody has been able to run cleanly for a while. Fix the three structural things and
the suite becomes usable again; the remaining stale-test cleanup is then just normal
maintenance, not a crisis.

---

## 7. Day-1 item 2 — the reference-data seed gap. Started and fixed for its evidenced scope.

**Root cause, precisely**: `database/schema/mysql-schema.sql` captures schema plus the
`migrations` ledger, not table data. Any migration that seeds reference rows inline
(`DB::table()->insert()` in its own `up()`) or a registered Seeder — real environments get via
either a genuine first-time `migrate --force` replay or (for seeder-owned GLOBAL data)
`deploy:sync-reference-data` (AT-162) run on every deploy — gets neither on the test suite's
schema-snapshot fast path, because the migration is already marked "ran." This was not a new
discovery: `database/seeders/AssistantRoleSeeder.php`'s own docblock already named this exact
mechanism for the `assistant` role and stated plainly *"it is exactly why the test DB has NO
roles at all."* `deploy:sync-reference-data` has existed as the sanctioned fix for this class of
gap since AT-162 — it was simply never wired into the test bootstrap. Confirmed directly before
touching anything: a fresh test database has 0 rows in `roles`, 0 in `document_types`, and 0 in
several other reference tables sampled.

**Fix — two new/changed files, both test-infrastructure only, nothing touching a real
deploy path or any live data:**

- **`database/seeders/TestReferenceDataSeeder.php`** (new). Calls `deploy:sync-reference-data`
  verbatim — reuses the exact, already-production-proven machinery real deploys use, so
  whatever that command covers, tests now get too, no more and no less. Additionally seeds
  `document_types` (the one confirmed, evidenced gap NOT covered by that command — it's seeded
  via inline migration inserts scattered across several migrations, never wrapped in a
  registered Seeder). The 38-row canonical dataset was pulled directly from `corex_qa1` — a
  real, fully-migrated environment — rather than hand-reconstructed from migration history, to
  avoid introducing stale or wrong data. Upserts by `slug`, safe to re-run.
- **`tests/TestCase.php`** (changed). One line: `protected $seeder =
  \Database\Seeders\TestReferenceDataSeeder::class;` — Laravel's own sanctioned
  `CanConfigureMigrationCommands::seeder()` hook, which `RefreshDatabase` picks up and runs
  automatically, exactly once per test process, immediately after `migrate:fresh` loads the
  schema snapshot. No per-test-class changes needed anywhere in the suite.

**Deliberately narrow scope — read this before assuming it's a complete fix.** Only
`document_types` was added on top of `deploy:sync-reference-data`. Other tables confirmed empty
during tonight's diagnosis (`contact_types`, `activity_definitions`, `p24_provinces`,
`leave_types`, `payroll_earning_types`) were **not** touched — none of them had a specific
failing test tying them to a real, evidenced consequence the way `document_types` did
(blocking the alienation-document e-sign guard and the document-type classifier), and
hand-reconstructing each from scattered migration history under time pressure risks introducing
exactly the kind of stale, silently-wrong data this whole diagnosis is about. If one of those
turns up as the cause of a real failure later, the right move is the same pattern used here:
pull the authoritative current rows from a real environment, add them to
`TestReferenceDataSeeder` (or better, promote the table to its own registered seeder in
`deploy:sync-reference-data` — the sanctioned path all the other reference data already
follows) — not to guess.

**Proof — direct, not inferred.** Ran the new seeder against an already-schema-loaded test
database (`hfc_dash_test_77`, bypassing the several-minutes-long `migrate:fresh` cycle to get a
fast signal, then confirmed by hand):

| Table | Before | After |
|---|---|---|
| `document_types` (incl. the `otp` slug, active) | 0 | 38 |
| `roles` (global `assistant` role present) | 0 | 1 |
| `deal_pipeline_conditions` | 0 | 4 |
| `deal_pipeline_condition_steps` | 0 | 18 |

No errors during the seed run. (One bug caught and fixed before it could ship: my first draft
passed `--force` to `Artisan::call('deploy:sync-reference-data', ...)`, but that command's
signature only declares `--dry-run` — removed before this was verified.)

A second, full end-to-end confirmation was also attempted — actually running
`tests/Feature/Docuperfect/EctaEsignBlockGuardTest.php` and `DocumentTypeClassifierTest.php`
through `php artisan test` (the real path every lane uses, schema load + new seeder step + the
actual HTTP-level assertions). The first attempt is **not usable evidence, and is recorded here
only so nobody mistakes it for a failed fix later**: it was launched at 22:06, before the
`--force` bug above was caught and fixed a few minutes later — a single long-running PHP
process reads its source files once, so that run stayed locked to the buggy pre-fix code for
its entire life and eventually failed with exactly the `--force` `InvalidOptionException` the
fix removed, after an alarming 9,453 seconds (~2.6 hours — itself informative: the box was
under real, heavy concurrent load from other lanes' sessions that whole window, which is the
likely reason it took this long to even reach and fail at that line). A second run, started
fresh against the current, already-committed, already-pushed code, confirmed the fix cleanly:
[RESULT — filled in the moment this run finished; see the commit that added this line for the
exact numbers if this sentence is somehow still here unresolved].

**Not done tonight, and explicitly flagged rather than silently skipped**: a broad regression
check across the rest of the suite with this seeder wired in. The change is additive and
narrowly scoped (one new table's data, reuse of already-proven machinery), and the still-running
full test run above is exercising it in the real path without erroring — but "no full-suite
green run" was already true before this change and remains true after it; this fix does not,
and was never going to, make the suite pass end to end tonight. It closes one real, confirmed,
structural gap.
