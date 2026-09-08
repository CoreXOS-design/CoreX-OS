# AT-33 mailbox poller — incremental rebuild (2026-09-08)

Approved and built same-day, following a live incident investigation (mailbox 12 on
live-testing hit a 50s read budget on a large backlog every run, then every mailbox on that
box flipped to `connect_failed` and stayed there for hours). Full investigation trail:
`.ai/handover/AT-395-staging-handover.md` and this session's own conversation record cover the
diagnosis; this spec covers the rebuild.

> **SUPERSEDED, SAME DAY — read §2A before §2.** §2 below (rolling overlap window on a
> timestamp watermark) was built, verified with real data, and then **rejected outright by
> Johan** once he saw it named an unproven mid-poll-arrival gap instead of closing it ("we have
> a gap, and we are naming it? why are we not fixing it? ... we dont ship problems or bugs. we
> tackle them head on and fix them."). §2A documents the replacement he specified: IMAP
> UID-based tracking, the same mechanism Outlook/Apple Mail/Thunderbird use. **§2, §3's overlap
> framing, and the lookback-window parts of §7/§8 are HISTORICAL RECORD ONLY — describing a
> design that shipped inside this same session and was then replaced before ever reaching
> Staging.** Nothing in §2 is live in the current code. §9 (files changed) is current.

## 2A. UID-based tracking — REPLACES §2 entirely (Johan's explicit rebuild order, same day)

**Why the overlap design was rejected.** It could not prove a message arriving mid-poll was
collected exactly once without waiting on real clock timing — the whole design rested on "the
overlap window is wide enough," which is a probabilistic argument, not a proof. Johan's read:
naming that as an accepted gap and shipping anyway is exactly the kind of compromise CoreX does
not ship. His fix is deterministic by construction instead of probabilistic by margin.

**The mechanism.** Every IMAP message has a permanent, monotonically increasing UID, scoped to
one folder's UIDVALIDITY. `communication_mailboxes.last_uid_seen` / `.sent_last_uid` (columns
already existed from the original AT-33 build) now hold the highest UID this folder has fully
processed; `.inbox_uid_validity` / `.sent_uid_validity` (new, this migration) hold that folder's
UIDVALIDITY at the time the cursor was recorded. Each poll:

1. Reads `$folder->status()` for the folder's CURRENT `uidvalidity`/`uidnext` — cheap, no
   SELECT/EXAMINE needed (webklex issues a plain IMAP `STATUS`).
2. **Checks UIDVALIDITY every single poll**, not just at "first poll" — a folder can be rebuilt
   or migrated between any two polls. If the stored value disagrees with the current one, every
   UID this mailbox holds for that folder is meaningless. The mismatch is logged at ERROR
   (`UIDVALIDITY changed for mailbox {id} folder {name} (stored=X, current=Y)`), the stored
   cursor is discarded, and the poll falls through to the SAME bounded first-poll backfill window
   already used for a brand-new folder — never an unbounded rescan to UID 1, and never a silent
   continue against numbers that may now point at completely different messages.
3. With a trustworthy stored UID, searches `UID {stored+1}:*` — server-side, exact, no clock, no
   timezone, no lookback window, no re-read of anything already processed. Without one (first
   poll for this folder, or a just-detected UIDVALIDITY mismatch), searches `SINCE (now -
   firstPollBackfillDays)`, unchanged from before.
4. The cursor advances to the highest UID actually returned by the search — tracked as each
   message is seen, regardless of whether it's kept/dropped/duplicate/erroring — but the
   **write only happens after that folder's entire message loop runs to completion with
   nothing thrown**. The exact same non-negotiable invariant as the old watermark design,
   now proven deterministically (§ "Verification" below) instead of only observed in practice:
   an interrupted poll leaves the cursor exactly where it was: unchanged, never advanced,
   never lost.
5. `whereUid()` (webklex's own builder method) is NOT used — reading webklex's
   `Query::generate_query()` showed it passes a UID range like `"1001:*"` through an
   `is_numeric()` check to decide whether to quote the value; a range string isn't numeric, so
   it gets wrapped in quotes, which is invalid IMAP syntax and would silently break the search.
   The library's own `"CUSTOM "` prefix (`WhereQuery::validate_criteria()`) is the documented
   escape hatch for an unquoted raw criterion — used as `where('CUSTOM UID 1001:*')`.

**What this explicitly REMOVES — two mechanisms for one job is how the next bug hides:**

- The rolling overlap window and its lookback entirely. `agencies.communication_poll_lookback_hours`
  is DROPPED (migration `2026_09_08_180000_drop_poll_lookback_hours_in_favour_of_uid_tracking.php`).
  `config('communications.poll_lookback_hours')` is removed from `config/communications.php`
  (replaced with a one-line comment pointing here, so nobody re-adds it not knowing why it's gone).
- `inbox_watermark_at` / `sent_watermark_at` (timestamp columns from the same-day earlier
  migration) are no longer read or written anywhere in `ImapMailboxPoller`. **Left in the schema
  rather than dropped in today's session** — genuinely dead, harmless, and a candidate for a
  future cleanup migration, but dropping schema on the same day as replacing the code it served
  was judged lower priority than shipping the fix itself; flagged here so it isn't mistaken for
  load-bearing.
- `advanceWatermark()` is gone, replaced by `advanceUidCursor()`.
- Dedup by Message-ID (§3) is UNCHANGED and stays as the second line of defense, exactly as
  Johan specified ("UID becomes primary, dedup stays as belt-and-braces") — it is what protects
  against a message being processed twice if a UID range is ever re-asked for any reason (e.g.
  after a UIDVALIDITY-triggered resync re-touches a UID already seen under the old numbering by
  coincidence).

**webklex UID/UIDVALIDITY support — confirmed real, not assumed** (Johan's requirement #6):
`Folder::status()` → `Protocol::folderStatus()` returns `uidvalidity`/`uidnext` from a native
IMAP `STATUS` command (RFC 3501) — read directly from webklex's own source, not inferred from
docs. `WhereQuery`'s `"CUSTOM "` escape hatch makes a raw `UID n:*` search possible despite the
`whereUid()` quoting bug (above). Both confirmed working against a real deterministic test
harness (below), not just believed to work from reading the library's code.

**Verification — deterministic this time, as Johan required.** New test file
`tests/Feature/Communications/ImapUidIncrementalPollTest.php`, four scenarios, all passing
against a fully faked IMAP wire (a `ProtocolInterface` implementation driving a real,
never-network-connected `Webklex\PHPIMAP\Client`/`Message`, not a loose duck-typed stand-in) —
UID numbers make every one of these exactly reproducible, no live timing dependency:

1. `test_a_message_arriving_between_polls_is_collected_on_the_next_poll_exactly_once` — a
   second message becomes visible on the server only between two polls; proves it's collected on
   poll 2, exactly once, with poll 1's cursor unaffected.
2. `test_rerunning_a_poll_immediately_collects_nothing_new_and_creates_no_duplicates` — proves
   the exact next search issued after a completed poll is `UID {cursor+1}:*`, and re-running
   against unchanged server state creates zero new rows.
3. `test_uidvalidity_mismatch_forces_a_bounded_resync_not_a_silent_continue` — the mailbox holds
   a stored UID+validity from before a simulated folder rebuild; the fake reports a DIFFERENT
   validity; proves the ERROR log fires with the exact stored/current values, proves the poll
   falls back to the SINCE path rather than trusting the stale UID range, and proves the message
   under the NEW numbering is still collected (not silently skipped).
4. `test_an_interrupted_poll_does_not_advance_the_stored_uid` — a simulated hung read trips the
   pcntl budget watchdog mid-search; proves the stored UID is byte-identical to its pre-poll
   value afterward, proves zero rows were created, and proves the very next poll re-asks for the
   exact same UID range (nothing skipped, nothing lost).

All 15 pre-existing regression tests in `MailboxHealthTest.php`, `ImapPollReadTimeoutTest.php`,
and `SentFolderResolutionTest.php` still pass unchanged (two of their fake folder doubles needed
a trivial `status()` stub returning `uidvalidity=0`, since `poll()` now calls it unconditionally
on every folder — `uidvalidity=0` routes them down the same `SINCE`-backfill path they already
exercised, so their actual assertions are untouched).

**What's UNCHANGED and stays, per Johan's explicit confirmation:** nightly backfill (§ "one-time
backfill marker" below), headers-first filtering (§4), the unknown-sender hold (§5), fairness
routing to the `mail-slow` queue (§6), and honest error-reason classification. "That work is
good and stays. This changes how we decide WHAT to fetch, not what we do with it."

**Honest time cost.** The UID mechanism itself (search, cursor, UIDVALIDITY-mismatch resync) —
roughly half a day's work once the design was clear, most of it in the webklex `whereUid()`
quoting bug investigation (avoided a shipped-but-broken search) and the `Config`/`Client`
bootstrap needed to build a genuinely faithful fake IMAP wire for the deterministic tests (four
iterations of "construct the real `Client`, discover the next missing config key" before the
harness ran at all — `masks`, `events`, `options`, `decoding`, and finally an `openFolder()`
override, each one only discoverable by hitting the error, not foreseeable from documentation).
That harness is now reusable for any future poller test that needs this level of fidelity
without a live mail server.

## 1. The problem this replaces

Before today, every poll re-scanned an entire coarse date window (`SINCE last_polled_at - 1
day`) from scratch, downloaded every message's full body to decide whether to keep it, and
discarded most of what it downloaded. A mailbox whose backlog didn't fit in the 50-second read
budget could never make progress — the same messages hit the same wall every single run,
forever, because nothing tracked where the previous run had gotten to.

## 2. Design — incremental polling with a rolling overlap watermark

**Per-folder watermark, not per-mailbox.** `communication_mailboxes.inbox_watermark_at` and
`.sent_watermark_at` (migration `2026_09_08_170000_...`) each track the last time THAT folder's
read genuinely completed — independent of each other, since Inbox and Sent can succeed/fail on
different runs.

**The watermark advances ONLY on genuine completion of that folder's message loop.** In
`ImapMailboxPoller::poll()`, `advanceWatermark()` is called exactly once per folder, immediately
after that folder's `foreach ($messages as $liteMessage)` loop finishes with nothing thrown. If
the pcntl budget watchdog fires anywhere inside that loop — mid-search, mid-header-peek,
mid-full-peek, mid-ingest — `ImapPollTimeoutException` propagates straight past the
`advanceWatermark()` call for that folder without ever reaching it. **There is no code path that
advances a watermark on partial or failed work.** Verified with a real, repeatedly-failing
mailbox (see §7) across multiple consecutive real runs: the watermark stayed byte-identical to
its pre-poll value on every failed attempt, and advanced only on the run that genuinely
completed.

**Rolling overlap, not a hard cutoff (Johan, mid-build addition).** Each run searches from
`(watermark - lookback_hours)`, not the watermark exactly. `poll_lookback_hours`: agency
override `agencies.communication_poll_lookback_hours` (migration `2026_09_08_170100_...`) ??
config default 12 (`communications.poll_lookback_hours`), clamped `[1, 168]`. This exists
because IMAP's `SINCE` search criterion is DATE granularity only — there is no way to ask a
server "since 22:14" — so server-side narrowing is inherently coarse, and a hard cutoff at the
watermark would silently lose mail on: clock skew between our server and the provider; a message
arriving DURING the read itself; or a message whose own `Date:` header doesn't match when it
actually landed (out-of-order delivery). The overlap deliberately re-reads messages already
processed every run — **safe only because dedup is by Message-ID** (see §3). No UID-cursor
"never re-read" mechanism was built for the query boundary itself (Johan's explicit design is
time+overlap+dedup, not a high-water-mark that skips re-reading) — `last_uid_seen` /
`sent_last_uid` / `*_uid_validity` columns exist in the schema from the original AT-33 build and
this session's migration respectively, reserved for a possible future optimisation layer, but are
**not read or written by today's build** — noted here so nobody assumes they're load-bearing.

**Backfill only on the very first poll for a folder** (`firstPollBackfillDays`, unchanged,
agency-configurable, default 7 days) — used only when that folder's watermark is still null.
Every poll after the first genuine completion uses the watermark-minus-lookback window instead.

**Dial-down escape hatch, proven live.** Default 12h proved too heavy on a genuinely busy real
mailbox during today's own verification (§7) — set the agency override to 2 and re-ran; the next
poll completed. This is exactly the tunability Johan asked for, confirmed working under real
load, not just described.

## 3. The dedup guarantee the overlap depends on entirely (checked BEFORE building)

`EmailArchiveIngestor::alreadySeen()` — checked, not assumed — dedupes on `external_id`, which is
`Message::getMessageId()` (the RFC 5322 `Message-ID` header) with a `sha256:`-of-raw-bytes
fallback for the rare message with no Message-ID at all. Checked against BOTH the permanent
archive (`Communication`) and the new hold table (`CommunicationPending`), scoped by
`agency_id`, with **no folder or direction qualifier at all** — a message that somehow appears
in both Inbox and Sent dedupes correctly by construction, not by a special case. This was
confirmed correct before any of today's overlap work began — the overlap could not safely have
been built otherwise.

A new public wrapper, `EmailArchiveIngestor::isAlreadySeen()`, exposes this exact same check to
the poller's header-stage pre-filter (§4) — never a second, potentially-drifting reimplementation
of "have we seen this."

## 4. Headers first, filter before the expensive fetch

`PeekingMessageFetcher::peekHeader()` (new) — the same non-destructive `BODY.PEEK[...]` pattern
AT-257 already established for `peek()`, but requesting `BODY.PEEK[HEADER]` + `FLAGS` instead of
`BODY.PEEK[HEADER]` + `BODY.PEEK[TEXT]`. (Still a two-item fetch, never one — webklex's response
parser throws on a single-item `BODY.PEEK[...]` request, per the existing class docblock,
verified against the live server originally; `FLAGS` is the cheapest possible second item and
happens to also avoid `peek()`'s separate flags() round trip.)

Per message, in order, all on the header alone:
1. Message-ID dedup check (§3) — a duplicate never proceeds any further, no contact lookup, no
   filter check, no full fetch.
2. Known-contact match (`ContactIdentifierResolver::resolve()`, the exact same matcher
   `EmailArchiveIngestor` itself uses) — a match always gets the full fetch (never let a real
   client's mail be filtered by a no-reply heuristic just because the header check ran early).
3. Only for a NON-match: `CommunicationIngestFilter::dropReasonForUnknown()` (existing, unchanged
   logic) — a no-reply/service-domain match is dropped right here, before any full fetch at all.

Everything that survives (a known contact, or a genuinely unknown, non-service sender) gets the
full `peek()` fetch and proceeds to `EmailArchiveIngestor::ingest()`, which remains the single
authoritative decision-maker for archived vs held vs parked vs dropped — the header-stage check
is a pure optimisation that never changes an outcome, only avoids paying for a fetch on mail
that would be dropped regardless.

## 5. Known senders vs unknown senders — the hold, revived

`CommunicationPending` / the AT-36 triage screen / `communications:prune-pending` were fully
built (index/addContact/notRealEstate on `CommunicationTriageController`, the nightly
retroactive-attach-or-prune command, `PendingAttachmentService`) but **dormant** — AT-122 made
the ingestor match-first and discard-on-no-match unconditionally, with no live code path left
that ever created a `CommunicationPending` row. Confirmed via `grep` before touching anything:
zero `CommunicationPending::create()` calls anywhere in the app.

Today's change, in `EmailArchiveIngestor::ingest()`'s no-contact-match branch: the known-attorney
correspondence-park path (AT-231) is unchanged; the no-reply/service-domain drop is unchanged
(still applies FIRST); a genuinely unknown, non-service sender is now routed to a new
`holdUnknownSender()` method instead of being discarded, creating a `CommunicationPending` row
with `expires_at = now()->addDays(CommunicationPending::graceDays($mailbox->agency))`. Field
mapping mirrors `PendingAttachmentService::attach()`'s reverse direction exactly (archive-shaped
fields, pending's own subset) so a row promotes cleanly with no drift between the two paths.

**Grace window: 4 -> 7 days default, max 5 -> 30** (`CommunicationPending::DEFAULT_GRACE_DAYS` /
`MAX_GRACE_DAYS`, and `config('communications.pending_grace_days')`). Per-agency override:
`agencies.communication_pending_grace_days` — the model already read this column; it never
existed until today's migration, so the override silently never applied before now.

**The purge-to-hard-delete switch, for Johan's still-open decision with Elize:** exactly one
line, `PruneCommunicationPending::handle()`'s `$pending->delete();` (the soft delete). Changing
that single call to `$pending->forceDelete()` is the entire change needed to make an expired,
unclaimed stranger's mail a genuine hard purge instead of a recoverable soft-delete. Nothing else
in the pipeline needs to change either way.

## 6. Fairness — a dedicated slow queue, not a shared one

`PollMailboxJob::SLOW_QUEUE_NAME = 'mail-slow'` (new constant). `PollMailboxes::handle()` checks
each mailbox's own `last_poll_duration_seconds` (new column, stamped by every poll regardless of
outcome) against an operator threshold (`PerformanceSetting::get('mailbox_poll_slow_threshold_seconds',
20)`, matching the existing `mailbox_poll_stagger_seconds` convention in the same file) and routes
that mailbox's dispatch to the slow queue instead of `mail`. Self-healing: the check re-evaluates
every dispatch cycle from the mailbox's own most recent measured duration, so a mailbox that goes
back to polling quickly returns to the normal queue on its very next scheduled poll, with no
separate flag or manual reset.

**QA1 provisioning done today:** a dedicated `corex-qa1-queue-mail-slow.service` systemd unit
(mirrors the existing `corex-qa1-queue.service`, `--queue=mail-slow` only) — genuinely isolated
worker, not just an added queue name on the same shared process (which would not have solved
anything: a single process working through two queues in its list still processes one job at a
time either way). **Live/Staging need the equivalent** — a second worker process dedicated to
`mail-slow` — before this fairness mechanism does anything there; simply adding `mail-slow` to an
existing worker's `--queue=` list does not provide isolation.

## 7. Real verification (QA1, real mailbox, real backlog — before QA1's DB got sanitised for the
   coordinated live-testing-data restore)

Target: `johan@hfcoastal.co.za` (id 12), real Afrihost mailbox, unpolled since 2026-08-21 (~18
days of real backlog at the time of testing).

- **Watermark-on-failure-only, proven across repeated real runs.** First 4 consecutive real runs
  each hit the 50s budget (`status=error, reason=read_timeout`) on the initial wide backfill
  window; `inbox_watermark_at` stayed `null` (its pre-poll value) after every one of them — never
  advanced on a failed run. Stats across those runs: real messages were genuinely archived (1),
  held pending (10, then 8, then 2 — new unique mail each run) and dropped pre-fetch (1, then 1,
  then 3), while `duplicate` correctly climbed to reflect exactly the running total of
  already-handled mail (11, then 19, then 20) — proving the re-read-without-reprocessing
  guarantee directly, with real counts, not inferred.
- **A run that genuinely completed advanced the watermark to the time its own read started** —
  confirmed (`inbox_watermark_at` and `sent_watermark_at` both stamped, `backfill_completed_at`
  stamped for the first time in the same run once BOTH enabled folders had a real watermark).
- **Dial-down proven under real load.** At the 12h default this mailbox's real traffic still hit
  the budget (`~54s` for ~20 messages in-window). Setting `agencies.communication_poll_lookback_hours
  = 2` for the agency and re-running completed successfully.
- **Honest cost, not hidden:** isolated per-command timing against this real server —
  `connect()` ~1.0s, folder resolve ~0.4s, a `SINCE` search alone ~17.5s for 11 UIDs, individual
  `peekHeader()` calls 0.75s–1.9s each, a full `peek()` (with body) on the same UID ~0.94s. The
  dominant cost on this specific real server is **per-command round-trip latency, not bytes
  transferred** — headers-first still saves real work (a dropped no-reply message never pays for
  a body+attachment fetch at all), but the wall-clock saving per KEPT message is smaller than
  bandwidth alone would suggest, because this server's round-trip time is the larger factor. This
  is a genuine, observed property of `mail.hfcoastal.co.za` under its current real load — not a
  defect in the client-side logic, and consistent with the whole day's "the server is slow"
  finding.
- **Cross-folder dedup** — verified by code inspection (§3: `alreadySeen()` has no folder/direction
  qualifier) rather than a live contrived test; a live trigger test was not completed before QA1's
  database was sanitised for the coordinated restore (see §8).

## 8. Not completed today — reported plainly, not glossed over

- **A deliberately-triggered "message arrives mid-poll" test** — RESOLVED by §2A. The overlap
  design's version of this test (live IMAP APPEND, dependent on real timing) never ran before
  QA1's credentials were sanitised. Johan rejected shipping on that unproven basis. The UID
  rebuild's equivalent — `test_a_message_arriving_between_polls_is_collected_on_the_next_poll_exactly_once`
  — is deterministic by construction (no live timing dependency at all) and passes. A live
  confirmation against a real mailbox is still worth doing once QA1 credentials are restored, but
  is no longer the thing standing between this design and being provably correct.
- **Wizard entry (CLAUDE.md non-negotiable #10a) — Johan's call, not made unilaterally.**
  `communication_pending_grace_days` remains a real, functioning, agency-configurable setting.
  `communication_poll_lookback_hours` no longer exists (§2A — removed with the overlap
  mechanism), so the recommendation below now applies to grace-days alone. Recommendation: **do
  NOT add it to the Agency Onboarding Setup Wizard** — it's an expert/rarely-touched operational
  tuning knob a brand-new agency has no basis to answer on day one. Flagged for Johan to confirm
  or override, not treated as decided.
- **"Never attempted" as a distinct recorded reason** (item 5's fourth category, alongside
  `connect_failed`/`auth_failed`/`connect_timeout`/`read_timeout`, all already distinct as of the
  prior session's work) was not built today — the concrete case (a mailbox's dispatch silently
  dropped by `ShouldBeUnique`'s lock because its previous job is still in flight) has no current
  visibility at all (Laravel's `dispatch()` doesn't report whether a uniqueness lock silently
  no-op'd it), and building that visibility is a separate, smaller piece of work than today's
  scope stretched to. Reported, not built.
- **SalesDocumentController's identical false-Sent bug class** (found and reported in an earlier
  session round, `.ai/specs/at395-outgoing-mail-per-mailbox-smtp.md` §16.3) remains unfixed — a
  separate pipeline, unrelated to today's incoming-mail rebuild.

## 10. Part A (2026-09-08, second round) — incremental checkpointing closes the real live incident

**The actual root cause**, found by reading `storage/logs/laravel.log` on live, not inferred: mailboxes
8, 9, 11, 12, 13, 15, 19 hit `mailbox N read exceeded 50s budget` repeatedly from 07:21 onward. §2A's
UID rebuild closes the STEADY-STATE gap (a mailbox that already caught up once resumes precisely). It
did **not** close this one: `advanceUidCursor()` was only ever called once, after a folder's ENTIRE
message loop ran to completion — a mailbox whose very first pass never finishes within budget banked
NOTHING, so `inbox_uid_validity` stayed null forever and every run re-took the `since()` branch,
re-walking (and re-fetching-a-header-for, to dedupe) the same growing prefix from scratch. Johan's
words: *"it runs for a long time and then looks like it fails"* — flatlining, not merely slow.

**The fix**, `app/Services/Communications/ImapMailboxPoller.php`: the per-message loop now updates
`$maxUidThisRun` **only after that UID's outcome is proven** (ingested / already-duplicate / dropped
before fetch / a real fetch failure) — never before, and never for a message whose fate was still
unknown when the budget fired. When `ImapPollTimeoutException` propagates out of the message loop, an
inner `catch` **checkpoints** whatever was genuinely proven (`advanceUidCursor()` with that exact
value) before re-throwing to abort the whole poll exactly as before. The next poll resumes with a
precise `UID {checkpoint+1}:*` search — never a re-walk of the SINCE window, even for a folder whose
first pass never fully finishes. A checkpoint also records the folder's real UIDVALIDITY (read from
`status()` before any message fetch, so it's valid regardless of how much of the backlog got through) —
meaning a mailbox with a backlog too large for one budget window now gets a real UID cursor from its
very first partial run, not after some hypothetical future full completion.

**Non-negotiable invariant, unchanged in spirit from §2A, now proven at finer grain:** a message is
never counted as handled while its outcome is still unknown. Every `continue`/success path in the
per-message loop updates the cursor candidate at that exact point, not before; the one path that
doesn't know (a timeout) rethrows before ever reaching an update. Deterministic proof, not an
assurance: `tests/Feature/Communications/ImapUidIncrementalPollTest.php` —
`test_a_budget_expiry_mid_run_checkpoints_exactly_what_was_proven_ingested` (three of five messages
proven ingested, budget fires fetching the fourth — checkpoint lands on exactly the third, never the
fourth, never fewer) and `test_an_interrupted_run_followed_by_resumption_is_indistinguishable_from_one_clean_run`
(the two-poll end state — every message archived exactly once, cursor at the true end of backlog — is
identical to what one uninterrupted poll would have produced).

**Side-effect fix required for honesty:** `backfill_completed_at` (§ "one-time backfill marker") is
now gated on `$status === 'success'`. Without this, a checkpoint on an interrupted first pass could
set `inbox_uid_validity` non-null while the backlog is nowhere near caught up, silently drifting that
marker's meaning from "genuinely done" to merely "started" — the same class of lie B fixes elsewhere.

**NOT a setting.** The checkpoint mechanism is correct behaviour, not a tunable — per Johan's standing
rule, safety fixes are never settings. There is no flag to disable it.

## 11. Part B (2026-09-08) — "Behind" is not "Failing"

**The defect, confirmed by reading the actual rendered state, not assumed:** `CommunicationMailbox::
pollHealth()` collapsed every non-null `last_error` — `connect_failed`, `auth_failed`,
`incomplete_credentials`, and `read_timeout` alike — into the single `HEALTH_FAILING` badge. The
mailbox list view (`resources/views/compliance/communication-archive/mailboxes/index.blade.php`)
showed a red "Failing" badge for all of them identically; the only place the real reason differed was
a `title` tooltip attribute — hover-only, easy to never see. A mailbox that connected and
authenticated fine, and was demonstrably still working (visible in Outlook), was shown exactly as
broken as one that could not connect at all. Johan: *"The system told him a lie about its own state."*

**The fix.** A new state, `CommunicationMailbox::HEALTH_BEHIND`, checked in `pollHealth()` BEFORE
`last_error` — connected and reading fine, just not finished with a backlog, is a genuinely different
condition from cannot-connect and must never share a badge with it. Driven by a new column,
`messages_behind_estimate` (migration `2026_09_08_210000_...`), set only when a poll is cut off by our
own time budget — computed as `(folder's UIDNEXT − 1) − (highest UID proven handled this run)`, an
honest approximation read from data the poll already has, deliberately NOT a live IMAP status check
from the list page (the list page does zero I/O by design — see the earlier failure investigation).

`MailboxHealthRecorder` gets a new method, `recordBehind()`, mirroring `recordSuccess()`'s
failure-state clearing (a mailbox that goes from failing to merely-behind has, in fact, recovered its
connection) but setting the backlog estimate instead of leaving it null — and, the whole point, **it
never calls `maybeNotify()`.** A `read_timeout` no longer feeds `consecutive_failures` or
`failure_notified_at` at all; it cannot reach the admin "mailbox stopped receiving mail" alert by
construction, not by a downstream filter. `recordFailure()` (genuine connect/auth/poll failures,
unchanged) now also clears `messages_behind_estimate`, so a truly broken mailbox never shows a stale
backlog count alongside "Failing" — a second, quieter version of the same lie.

The badge (`index.blade.php`) gains a fifth, visibly distinct state — amber "Behind", never grouped
with red "Failing" — and the reason text is now rendered INLINE below the badge, not hover-only, for
both `behind` and `failing`, in Johan's language via `CommunicationMailbox::behindLabel()`: *"Connected
— working through a backlog, about N message(s) behind."* Never "cannot connect."

**Deterministic proof**, `tests/Feature/Communications/MailboxHealthTest.php`:
`test_read_timeout_advances_last_polled_at_and_records_behind_not_failing` (replaces the old test that
asserted the DEFECT's behaviour as correct — `last_error` stays null, `pollHealth()` is `'behind'`,
never `'failing'`) and `test_a_budget_cutoff_never_raises_the_broken_mailbox_alert` (three consecutive
budget cutoffs — exactly the shape that fires the genuine-failure alert at the default threshold —
proves zero notifications sent and no alert episode ever opened).

**NOT a setting.** The existence of a distinct "Behind" state, and the rule that a budget cutoff must
never raise the broken-mailbox alert, are correct behaviour, not tunables — per Johan's standing rule,
safety/honesty fixes are never settings. There is no flag to make read_timeout alert again.

## 12. Real-mailbox status — said plainly, not implied

**The poller has still never completed a real poll against a real mailbox with today's code.** A
read-only, instrumented observation was run against `johan@hfcoastal.co.za` (mailbox id 12,
`mail.hfcoastal.co.za:993`) on QA1 on 2026-09-08 specifically to gather real numbers for this work.
One real connection attempt was made (`ImapMailboxPoller::connect()` directly): it failed in 3.06
seconds with `NO [AUTHENTICATIONFAILED] Authentication failed.` — despite credentials having been
re-saved to that row roughly six minutes earlier. No further attempts were made. **This is a QA1
artefact, not something addressed by this work, and not something this session is closing** — QA1's
mailbox credentials for the one real mailbox available do not currently authenticate; fixing that is
Johan's or Andre's, not a poller-code problem, and the poller was never touched to work around it.

The real per-message timing figures used to reason about A above (~0.75–1.9s per header round-trip,
~17.5s for an 11-UID `SINCE` search) come from an **earlier session's** successful measurement against
this same mailbox, before a coordinated database restore blanked its credentials — not from today.
They remain the best real evidence available and are used as such, labelled as prior, not re-confirmed
today. If they are stale once this mailbox authenticates again, checkpointing (§10) is far less
sensitive to being wrong by 2x than the all-or-nothing design it replaces, since it now banks
whatever a run genuinely completes rather than needing full completion to bank anything.

**A separate, real defect found during this observation, NOT fixed here (out of scope for A/B/C/D):**
`CommunicationMailboxController::testConnection()`'s Sent-folder-write leg
(`ImapSentFolderAppender::append()`) makes a genuine `appendMessage()` call against a mailbox's real
Sent folder — not read-only, not guarded by the QA outbound-mail guard (which only covers the SMTP
leg, confirmed by reading `OutboundMailGuardServiceProvider`'s own docblock). On a mailbox that
authenticates correctly, clicking "Test Connection" writes a real synthetic message into the real
Sent folder every time. Reported to Johan directly; not touched.

## 9. Files changed (current, post-§2A UID rebuild, and §10/§11 checkpointing + health-state work)

- `database/migrations/2026_09_08_170000_add_incremental_poll_watermarks_to_communication_mailboxes.php`
  — added `inbox_uid_validity`/`sent_uid_validity`/`sent_last_uid`/`last_poll_duration_seconds`/
  `backfill_completed_at` (still load-bearing) alongside the now-dead `inbox_watermark_at`/
  `sent_watermark_at` (left in schema, see §2A).
- `database/migrations/2026_09_08_170100_add_poll_lookback_hours_to_agencies.php` — added
  `communication_poll_lookback_hours`, then...
- `database/migrations/2026_09_08_180000_drop_poll_lookback_hours_in_favour_of_uid_tracking.php`
  — ...DROPS it again, same day, per §2A.
- `app/Models/Communications/CommunicationMailbox.php` (fillable/cast additions:
  `inbox_uid_validity`, `sent_uid_validity`, `sent_last_uid`, etc.; §11 — new
  `HEALTH_BEHIND` constant, `messages_behind_estimate` fillable/cast, `pollHealth()` checks it
  before `last_error`, new `behindLabel()`)
- `app/Models/Communications/CommunicationPending.php` (grace-day constants — unchanged by §2A)
- `app/Services/Communications/ImapMailboxPoller.php` (§2A — UID cursor + UIDVALIDITY-mismatch
  resync REPLACES the watermark+overlap code entirely; §10 — per-message checkpointing on budget
  expiry, `$behindEstimate` computed at both timeout sites and threaded to health recording,
  `backfill_completed_at` gated on `$status === 'success'`; headers-first, filter-before-fetch,
  and fairness duration tracking are unchanged)
- `app/Services/Communications/MailboxHealthRecorder.php` (§11 — new `recordBehind()`;
  `recordSuccess()`/`recordFailure()` now also clear `messages_behind_estimate`)
- `app/Services/Communications/EmailArchiveIngestor.php` (pending-hold revival, `isAlreadySeen()`
  public wrapper — unchanged by §2A/§10/§11)
- `app/Services/Communications/PeekingMessageFetcher.php` (`peekHeader()` — unchanged)
- `app/Jobs/Communications/PollMailboxJob.php` (`SLOW_QUEUE_NAME` — unchanged)
- `app/Console/Commands/Communications/PollMailboxes.php` (slow-queue routing — unchanged)
- `config/communications.php` (`pending_grace_days` default 4->7; `poll_lookback_hours` added
  then removed same day per §2A, replaced with an explanatory comment)
- `resources/views/compliance/communication-archive/mailboxes/index.blade.php` (§11 — fifth
  "Behind" badge state, amber, visibly distinct from "Failing"; reason text now rendered inline,
  not hover-only, for both `behind` and `failing`)
- `database/migrations/2026_09_08_210000_add_messages_behind_estimate_to_communication_mailboxes.php`
  (new, §11)
- `tests/Feature/Communications/ImapUidIncrementalPollTest.php` (§2A's four deterministic
  UID-rebuild proofs, plus §10's two checkpointing proofs — six total)
- `tests/Feature/Communications/MailboxHealthTest.php` (§2A `status()` stub; §11 — the
  read-timeout test rewritten to assert `behind` not `failing`, plus a new test proving the
  alert episode never fires for a budget cutoff)
- `tests/Feature/Communications/ImapPollReadTimeoutTest.php` (§2A `status()` stub only —
  unaffected by §10/§11, this test never reaches the health-recording call)
- QA1 infra: `/etc/systemd/system/corex-qa1-queue-mail-slow.service` (new, not in git — noted
  here so it's not lost; live/Staging need the equivalent provisioned separately)
