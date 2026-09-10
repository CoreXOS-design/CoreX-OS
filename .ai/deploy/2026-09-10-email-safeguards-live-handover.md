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

**Do not run `php artisan migrate --force` blind for this delta.** One of these eight migrations
must be deliberately excluded from live. Run the other seven explicitly, in order, by path.

```bash
php artisan migrate --force \
  --path=database/migrations/2026_09_08_170000_add_incremental_poll_watermarks_to_communication_mailboxes.php \
  --path=database/migrations/2026_09_08_210000_add_messages_behind_estimate_to_communication_mailboxes.php \
  --path=database/migrations/2026_09_09_020000_add_poll_backoff_to_communication_mailboxes.php \
  --path=database/migrations/2026_09_09_030000_create_communication_host_circuit_breakers_table.php \
  --path=database/migrations/2026_09_09_040000_add_auth_lock_to_communication_host_circuit_breakers.php \
  --path=database/migrations/2026_09_09_040000_create_outbound_mail_guard_captures_table.php \
  --path=database/migrations/2026_09_09_050000_add_error_detail_to_communication_mailboxes.php \
  --path=database/migrations/2026_09_09_080000_add_communication_poll_chunk_size_to_agencies.php
```

**Deliberately NOT in that list:** `2026_09_09_060000_reconcile_hfcoastal_host_auth_failure_count.php`.
Do not run it on live. See below for why.

| Migration | What it does | Runs on live? |
|---|---|---|
| `add_incremental_poll_watermarks_to_communication_mailboxes` | adds `last_uid_seen`, `inbox_uid_validity`, `sent_last_uid`, `sent_uid_validity` | Yes |
| `add_messages_behind_estimate_to_communication_mailboxes` | health-badge support | Yes |
| `add_poll_backoff_to_communication_mailboxes` | back-off/disable columns | Yes |
| `create_communication_host_circuit_breakers_table` | new table, one row per host | Yes |
| `add_auth_lock_to_communication_host_circuit_breakers` | adds `auth_failure_count`, `auth_locked_at` | Yes |
| `create_outbound_mail_guard_captures_table` | interception-capture logging | Yes |
| `add_error_detail_to_communication_mailboxes` | raw-server-response columns for the diagnostics disclosure | Yes |
| `reconcile_hfcoastal_host_auth_failure_count` | writes Staging's own incident history onto `mail.hfcoastal.co.za`'s breaker row | **NO — excluded, see below** |
| `add_communication_poll_chunk_size_to_agencies` | agency-configurable chunk size | Yes |

### DO NOT RUN `reconcile_hfcoastal_host_auth_failure_count` ON LIVE — deliberately excluded

**Final decision, Johan's word, 2026-09-10.** This was investigated properly before deciding, not
assumed either way — the full reasoning is worth reading if you're ever unsure whether to
reconsider it:

- The two recorded failures were **real, but they were Staging's** — a genuine 535 rejection
  against a password that was stale *at that moment* (last changed in July, corrected by Johan
  later the same day). Proven fixed: a real, successful login went through on Staging afterward.
- **Live's credentials are confirmed good.** Johan, 2026-09-10, verbatim: *"live worked until we
  did the outgoing email part this week, and it worked until we hit the multi test. so theres no
  issue with passwords on live."* Live was never the environment that hit the stale password.
- The migration is **not a soft precaution** — it's a standing stop. Checked directly in the code:
  nothing ever resets `auth_failure_count` on a successful login (only `resetAuthLock()` does,
  and that is only ever called by a human — see `HostCircuitBreaker.php`), and the lock is checked
  and enforced *before* every single real connection attempt, in all four call sites (Test
  Connection ×3, the poll job). Once tripped, it does not recover on its own — ever. Running this
  on live would pause Test Connection and automatic polling for **every HFC mailbox on
  `mail.hfcoastal.co.za`**, indefinitely, until a person manually clears it — for a problem that
  was proven to be Staging's, not live's.
- **Live does not need this pre-loaded to be protected.** The actual safeguards — the rate
  limiter, the same threshold-of-2 auth-failure lock, chunked polling — ship as **code**, not
  data, in this same delta. They protect live automatically starting from live's very first real
  attempt after promotion. If live ever does hit two real failures, the exact same lock trips
  there, on its own, with no history needing to be pre-loaded for it to work.

If this delta is ever re-cut with a different mail setup (a different account, a different
provider) this assumption needs re-checking, not carried forward blind — but as of 2026-09-10, for
`mail.hfcoastal.co.za` specifically, it's resolved: **do not run it.**

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

The `mail.hfcoastal.co.za` row printed by that query is **expected to be empty/null** at this
point — the table exists (from `create_communication_host_circuit_breakers_table`), but no row for
that host is seeded, because the one migration that would write it was deliberately skipped. That
is correct. A row appears the first time any real connection attempt targets that host, created
fresh by the running code, not pre-loaded.

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
- **Do not run `reconcile_hfcoastal_host_auth_failure_count` on live — final decision, §3.**
  Johan confirmed `mail.hfcoastal.co.za` is one shared host across live and Staging/QA1, not a
  separate relationship — but he also confirmed live's own credentials are current and working
  ("no issue with passwords on live"), and tonight's two failures were proven to be Staging's, not
  live's. Running this migration would impose a standing, human-clear-only stop on every HFC
  mailbox on live for a problem that was never live's. Resolved, not a thing to second-guess on
  the day — but if the mail setup changes before this ships (a different account, a different
  provider, live's own credentials turn out to be stale after all) that assumption needs
  re-checking, not assumed to still hold.

---

*Everything in this document was verified against Johan's real mailbox on Staging over the course
of tonight's incident response, not assumed. If anything here doesn't match what you see on live,
stop before proceeding and say so — an honest "this doesn't match" is worth more than pushing
through on the assumption the document is right and reality is wrong.*
