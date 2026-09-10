# Email safeguards — handover for live promotion (Andre)

> Written 2026-09-10, for Andre, who was not part of any of this. You promote this to live on
> Johan's word. This document is meant to stand on its own — if something here is unclear, stop
> and ask rather than guess; nothing in this feature is worth guessing about, because a wrong
> guess against the real mail provider (Afrihost) risks a second IP ban of Johan's real business
> email, which is exactly what caused this whole incident.

---

## 1. What changed, and why

Johan's real Afrihost mail account (`johan@hfcoastal.co.za`, host `mail.hfcoastal.co.za`) got
IP-banned tonight after repeated real login failures — a Test Connection click on the Email Setup
screen was making **two** real logins (SMTP + IMAP) per click, and nothing was stopping repeated
clicks from burning through Afrihost's hard 3-failed-login cap. Once whitelisted again, a second,
unrelated problem surfaced: a mailbox's **first poll never completed** — it silently died on an
unhandled crash roughly half the time, and even once that crash was fixed, an honest attempt still
timed out on every single run against a real mailbox with real history, because it tried to fetch
an agency's entire backlog in one unbounded IMAP call.

Two things were built and proven tonight, both live-testable against Johan's real mailbox on
Staging:

1. **IP-block safeguards** — a rate limiter, an auth-failure lock, a circuit breaker, and
   back-off, so a burst of Test Connection clicks (or a wedged poll cycle) can never again spend
   more than a small, bounded number of real logins against a host before something stops it.
2. **Chunked mailbox polling** — the first-catch-up (and any later resync) now fetches a bounded
   number of messages at a time, checkpointing the watermark after every chunk, so a mailbox with
   any amount of history makes real progress every cycle and genuinely finishes, instead of
   attempting everything at once and timing out forever.

Everything below was proven against Johan's actual mailbox, not a test double.

---

## 2. The exact commit, and where it actually is

**Staging HEAD:** `d881e8612` (`fix(communications): stop chunking from triggering webklex's
empty-UID-array warning`)

Confirm it before doing anything else — do not trust this document's hash, re-derive it:

```bash
git -C /corex-staging fetch origin
git -C /corex-staging rev-parse HEAD
git -C /corex-staging rev-parse origin/Staging
# both must print d881e86129bcadfb1b063b128354df0cee689ac1 -- if they don't match, STOP
```

**QA1:** the same fixes exist on QA1's local checkout but are genuinely **not yet on
`origin/QA1`** — that repo has diverged from Staging by ~700 commits over the last three weeks,
none of it this feature's fault. cc1 is running the proper reconciliation (Johan's model:
Staging wins, comes down into QA1, daily, without losing in-progress QA1 work). This feature will
arrive on `origin/QA1` through that process, not around it. Do not try to shortcut this yourself —
it was investigated tonight and a clean isolated cherry-pick is not possible; the fix commits
depend on earlier today's circuit-breaker work, which is itself interleaved with ~60 unrelated
commits from other lanes.

---

## 3. Migrations — what must run, and how to actually verify (not migrate output)

Everything below already ran cleanly on Staging. Run the same sequence on live, then verify with
the real queries — `php artisan migrate` printing "DONE" only proves the migration didn't throw,
not that the data is what you expect.

```bash
php artisan migrate --force
```

New/relevant migrations in this delta (`2026_09_08_170000` through `2026_09_09_080000`):

| Migration | What it does |
|---|---|
| `add_incremental_poll_watermarks_to_communication_mailboxes` | adds `last_uid_seen`, `inbox_uid_validity`, `sent_last_uid`, `sent_uid_validity` |
| `add_messages_behind_estimate_to_communication_mailboxes` | health-badge support |
| `add_poll_backoff_to_communication_mailboxes` | back-off/disable columns |
| `create_communication_host_circuit_breakers_table` | new table, one row per host |
| `add_auth_lock_to_communication_host_circuit_breakers` | adds `auth_failure_count`, `auth_locked_at` |
| `add_error_detail_to_communication_mailboxes` | raw-server-response columns for the diagnostics disclosure |
| `reconcile_hfcoastal_host_auth_failure_count` | **RUN IT.** Johan confirmed — see below. |
| `add_communication_poll_chunk_size_to_agencies` | agency-configurable chunk size |

### RESOLVED — run this one, Johan's own words

`reconcile_hfcoastal_host_auth_failure_count` **hardcodes** `mail.hfcoastal.co.za`'s breaker row to
`auth_failure_count = 2, auth_locked_at = now()` — i.e. it **locks that host on whatever
environment it runs on**.

This used to be a STOP pending an answer from Johan. He answered it directly, 2026-09-10: *"if you
are on johan@hfcoastal.co.za then yes. thats my mailbox and all the other mailboxes are hfc's
mailboxes."* **`mail.hfcoastal.co.za` is one real, shared Afrihost mail host — the same physical
mailboxes live and Staging/QA1 both connect to. Live is not a separate mail relationship.**

**Run it on live, deliberately, as part of this delta.** The reasoning, plainly:

- Tonight's two recorded failures were **real** — a genuine 535 rejection from the real Afrihost
  server, using a password that was genuinely stale at that moment (last changed in July; Johan
  re-saved it later the same day). Not a Staging bug, not a misconfiguration on Staging's side —
  the identical two failures would have happened from live at that same moment, with that same
  old password. The current password is confirmed correct: a real, successful login went through
  once Afrihost's IP block lifted.
- Because the host is shared, those two failures count against the **real, external Afrihost
  cap** regardless of which environment caused them. `communication_host_circuit_breakers` is a
  **separate table per environment** (Staging, QA1, and live each have their own database) — our
  own software cannot see across environments, so live's copy of this safety tracker currently
  reads "0 of 3, all clear" whether that's true or not.
- **Not running it leaves live's own tracker telling a false story** — genuinely 2 of 3 real
  strikes already spent, with live's software unaware and unable to warn anyone before a third
  real attempt (from any environment) risks a second real ban.
- **Running it means every mailbox on `mail.hfcoastal.co.za` on live — not just Johan's, all of
  HFC's staff on that host — has Test Connection and automatic polling paused** until a person
  reviews and deliberately clears the lock (same as it's already behaving on Staging tonight).
  That pause is the correct, intended behaviour, not a side effect to work around — see §8, do not
  clear it without understanding why it's there.

### Verification queries — run these for real, don't trust migrate's output

```bash
php artisan tinker --execute="
echo Illuminate\Support\Facades\Schema::hasTable('communication_host_circuit_breakers') ? 'table exists' : 'MISSING';
echo PHP_EOL;
print_r(Illuminate\Support\Facades\DB::table('communication_host_circuit_breakers')->where('host','mail.hfcoastal.co.za')->first());
echo implode(', ', array_filter(Illuminate\Support\Facades\Schema::getColumnListing('agencies'), fn(\$c) => str_contains(\$c, 'poll') || str_contains(\$c, 'chunk')));
echo PHP_EOL;
echo implode(', ', array_filter(Illuminate\Support\Facades\Schema::getColumnListing('communication_mailboxes'), fn(\$c) => str_contains(\$c, 'uid') || str_contains(\$c, 'error_detail')));
"
```

Expected agency columns: `communication_first_poll_days, communication_poll_chunk_size,
communication_poll_backoff_base_seconds, communication_poll_backoff_max_seconds,
communication_poll_disable_threshold`. Expected mailbox columns include `last_uid_seen,
inbox_uid_validity, sent_last_uid, sent_uid_validity, last_send_error_detail,
last_sent_folder_append_error_detail, last_error_detail`. If any of these are missing, a migration
silently didn't run or didn't apply — stop, don't proceed to caches/reload.

Standard post-migrate steps, unchanged from any other CoreX deploy:

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
# reload the live PHP-FPM pool, restart the live queue workers, per the usual live runbook
```

---

## 4. Agency-configurable settings — both real, both defaulted sensibly

| Setting | Column | Default | What it controls |
|---|---|---|---|
| First-poll backfill window | `agencies.communication_first_poll_days` | **7 days** | how far back the very first poll of a new mailbox looks |
| Poll chunk size | `agencies.communication_poll_chunk_size` | **25 messages** | how many messages are fetched per IMAP round trip during catch-up |

Both are nullable — `NULL` falls back to the config default (`config('communications.
first_poll_backfill_days')` / `config('communications.imap_poll_chunk_size')`), same pattern as
every other per-agency override in this module. Neither needs to be set for a new agency; the
defaults were proven, not guessed, against a real 95-message backlog tonight.

**Not agency-configurable, and that's deliberate:** `imap_poll_budget_seconds` (default 50s, the
watchdog that bounds one poll cycle's total work) is a single global setting, not per-agency. It
doesn't need to scale with mailbox size the way the two settings above do — the chunking fix means
the budget only ever bounds *how many chunks fit in one cycle*, not whether catch-up eventually
finishes.

---

## 5. What to watch after promotion

- **Mailbox health badges** (Settings → Email Setup, Compliance → Archive Mailboxes). A mailbox
  mid-catch-up will show as "Behind" or similar, not "Failing" — that's correct, expected
  behaviour for a large mailbox's first few poll cycles, not a fault. Only genuine connect/auth
  failures should show as "Failing".
- **The login-failure budget** per host (visible on both mailbox screens as "N of 3 login
  failures used"). This is the real, hard external limit — it should sit at 0 for a healthy host.
  Anything above 0 means a real login genuinely failed against that host recently.
- **A first catch-up on a big mailbox takes several poll cycles by design.** Tonight, catching up
  a real 95-message, ~10-year-old-mailbox backlog took **10 poll cycles** before completing
  cleanly. That is not a fault, not a hang, not something to intervene on — it is the fix working
  as intended. Only worry if a mailbox's watermark (`last_uid_seen` / `inbox_uid_validity` on
  `communication_mailboxes`) stops advancing between cycles entirely — that would mean something
  is genuinely stuck, not just working through a backlog.

---

## 6. The one thing that will look alarming and isn't

**Catching up a backlog costs one real login per poll cycle, not one login total.** Tonight,
catching up 95 messages at the default chunk size of 25 took 10 real logins across 10 cycles. If
you watch a mailbox mid-catch-up and see ten logins in the logs where you expected one, **that is
correct, not a bug.**

The important distinction: **all ten were successful logins.** Afrihost's 3-failure cap only
counts *failed* logins — a successful login never touches that budget, no matter how many of them
happen. In real production usage these are also spread across the mailbox's normal poll interval
(every few minutes), not fired back-to-back the way tonight's proof runs deliberately did to
converge quickly. If you ever see a mailbox generating *failed* logins repeatedly, that's the
auth-failure lock's job to catch (see §7 — do not touch that yourself).

---

## 7. Known non-blocking item — already fixed, not outstanding

Earlier tonight, chunking triggered a real (harmless) PHP warning —
`Array to string conversion` in webklex's own `ImapProtocol.php` — on the terminal page of every
catch-up. Root cause: the chunking loop used to discover "no more pages" by fetching an empty one,
and webklex's own `fetch()` doesn't handle a genuinely empty UID list cleanly. **This is fixed** —
the loop now counts the total matches up front and never requests a page it already knows is
empty — and proven gone across a full 10-cycle catch-up plus a final clean run, zero occurrences.
Nothing outstanding here. Mentioned so you know it was found and closed, not because it needs
attention.

---

## 8. What you must NOT do

- **Do not clear a login-failure lock (`auth_locked_at` on `communication_host_circuit_breakers`)
  without first understanding *why* it tripped.** The lock exists specifically to stop a second
  real ban. Clearing it without knowing the cause just re-exposes the same risk that caused
  tonight's incident. If a lock needs clearing, that's Johan's call, made with the actual reason
  in hand — not a routine unblock.
- **Do not raise `HostCircuitBreaker::AUTH_FAILURE_LOCK_THRESHOLD` (currently 2, against
  Afrihost's real stated cap of 3).** That number is deliberately *not* agency-configurable and
  deliberately set below the real external limit, on purpose, so there is always margin before the
  real ban. Raising it trades away the one thing standing between us and a second incident like
  tonight's.
- **`reconcile_hfcoastal_host_auth_failure_count` — run it, per §3.** Johan confirmed
  `mail.hfcoastal.co.za` is one shared host across live and Staging/QA1, not a separate
  relationship. This is the one migration in this delta that encodes real incident history rather
  than a schema/feature change — it's resolved, not a thing to second-guess on the day, but if
  the mail setup changes before this ships (a different account, a different provider) that
  assumption needs re-checking, not assumed to still hold.

---

*Everything in this document was verified against Johan's real mailbox on Staging over the course
of tonight's incident response, not assumed. If anything here doesn't match what you see on live,
stop before proceeding and say so — an honest "this doesn't match" is worth more than pushing
through on the assumption the document is right and reality is wrong.*
