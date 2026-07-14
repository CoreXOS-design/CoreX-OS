# AT-235 R1–R3 + R7 — landing report

> **Branch:** `AT-235-notify-r1r2r3` — `30362db3` (R1+R2+R3) · `c180706c` (R7).
> **Status:** built, tested, pushed. **Staging ceiling. NOT deployed.** Dual-deploy on Johan's GO.
> **Tests:** 20 passed, 4 files, every one verified against the pre-fix code.
> **Findings report:** `.ai/audits/2026-07-13-at235-notifications-vs-event-classes.md`
> **Author:** m6, 2026-07-13.

---

## 1. THE LESSON — three green tests that lied, in one day

**Johan asked for this verbatim, for the morning brief and for STANDARDS. It is the most
important thing in this report — more important than the code.**

Three times today a test suite went green while proving nothing. Each was caught only because a
*second* assertion disagreed with the first, or because I went looking for the failure on purpose.
None would have been caught by review.

**1 — The dedup tests passed against the un-fixed code.**
I wrote six tests for the 1.9M-notification storm. All six passed. They also passed when I reverted
the fix entirely. The 6-hour cooldown (`min_minutes_between_same`, default 360) masks the
moving-dedup-key bug completely — so the tests were measuring the backstop, not the control they
claimed to test. *The storm itself ran with no cooldown at all* (that check only landed 29 May,
mid-storm). **Fix:** isolate the control under test — set the cooldown to 0 — and test the backstop
separately, as the backstop it actually is.

**2 — The catalogue guard passed against an empty catalogue.**
A test asserting "every notification toggle has a producer" iterated the catalogue and found no
orphans. The catalogue table in the test database has **zero rows** — the rows were inserted by a
one-off migration that the schema snapshot marks as already-run, so it never re-runs in tests. The
test was iterating an empty collection and concluding all was well. **Fix:** assert the fixture is
*non-empty before drawing any conclusion from it*, and give the catalogue a real seeder.

**3 — The verification of the build guard was itself a no-op.**
To prove R7's guard catches a new bypass, I injected a rogue `->notify()` into a file — and the
guard stayed green. Because I'd targeted a file that **does not exist on that branch**. Nothing was
injected. The "proof" proved nothing. **Fix:** assert the file exists before trusting the negative
result.

### The rule these three share

> **A test that has never failed has not been tested.**
>
> Green means nothing until you have watched it go red for the right reason. Specifically:
>
> 1. **Run every new test against the un-fixed code.** If it passes, it is not testing your fix.
>    (This is already how we work — but #1 above shows it is not enough on its own, because a
>    *second, unrelated safety net* can hold the test up.)
> 2. **Isolate the control under test.** Disable the other guards. If a cooldown, a cache, a
>    retry, or a unique index could produce the same green, you are testing that, not your change.
> 3. **Assert your fixtures are real.** An empty table, a missing file, a null subject — every one
>    turns a suite into theatre that no reviewer will catch, because the diff looks perfect.
>
> The storm is the same failure at runtime: `notification_dispatch_log` was an idempotency ledger
> that never deduped anything, and nobody noticed for 24 days — because **a control nobody has
> watched fail is indistinguishable from a control that works.**

---

## 2. What landed

### R3 — the storm's root cause (`NotificationDispatcher.php`)

`fire()` defaulted its dedup key to **`now()`**. The idempotency check asks *"is there already a
log row for this fact?"* — which only means something if the key is the **same on every scan tick
for the same fact**. `now()` is fresh every tick, so it never matched. `contact.fica_missing`
re-told the same user the same fact about the same contact every 30 minutes for 24 days:
**1,903,039 dispatches**, 99.5% of the entire log.

**The key is now REQUIRED.** The dispatcher cannot invent a safe default, because only the caller
knows what "the same fact" means — a persistent condition must key off something *stable* (notify
once); a discrete event is a new fact each time (`now()` is correct there). Any default is a guess
at which, and the guess it made turned every persistent condition into a discrete one.

> **I first fixed this with a time bucket (`now()->startOfHour()`) and it was wrong.** It made the
> tests green while only turning a half-hourly storm into an **hourly** one — 24/day/pair ×
> 15,178 pairs is still ~364,000 notifications a day. **A time bucket is not a fact.** The test
> that kills that idea (48 ticks across 24 hours) is in the suite permanently.

Omission now throws — and **cannot reach production**: all 8 call sites pass a key (7 already did;
`CalendarController` now passes `now()` explicitly, declaring itself a discrete event), and a
**static guard** asserts every `->fire(` site still does, so a 9th caller that forgets fails the
*build*.

### R2 — the agency's channel config was inert (`CalendarNotificationDispatcher.php`)

It resolved `$viaChannels` from `calendar_event_class_settings`, checked it was non-empty, then
called `notify()` **without it**. A class configured "in-app only" still emailed; "email only"
still wrote an in-app row. The admin set the channel; the code ignored it.

**The part that mattered more than the fix:** forcing the channel list bypasses `via()` — which is
*also* where the user's `notify_email` master switch was being checked. Without re-applying that
veto deliberately, this fix would have handed agencies **the power to override user consent**. The
class config now decides which channels are *eligible*; the user's master switch still **vetoes**.

### R1 — the settings page is lying (migration + seeder + guard)

Retires `contact.fica_expiring` and `contact.no_followup` (soft-delete — no hard deletes) and makes
`contact.birthday`'s cross-system dependency explicit on the screen where it is configured.

**The guard surfaced that the problem is four times bigger than reported: 8 of 26 toggles have no
producer.** They split into two very different groups:

| Group | Keys | Disposition |
|---|---|---|
| **Orphaned** — producer deleted 1 Jul | `contact.fica_expiring`, `contact.no_followup` | **Retired here.** |
| **Never fired once** — seeded ahead of a watcher that was never built (verified: only 3 keys have *ever* fired on live) | `property.no_activity`, `property.compliance_doc_missing`, `deal.documents_missing`, `deal.commission_unpaid`, `deal.milestone_due`, `leave.cancelled` | ⚠️ **ESCALATED — Johan's call.** These are *unbuilt features*, not dead code. Deleting a planned feature's switch is not mine to do. |

**Also: the catalogue had no seeder at all.** Its rows came from a one-off April migration — so a
**fresh environment gets an empty settings page**, and the test DB had zero rows (finding #2 above).
That is the AT-162 class exactly. Added `NotificationEventTypeSeeder` (idempotent; never
resurrects a retired row) and registered it in `deploy:sync-reference-data`.

### R7 — the build guard

All 22 files carrying a notification bypass are frozen into an annotated allow-list. **Add a 34th
and the build fails**, naming the file. Plus a staleness check (a fixed bypass must be *removed*
from the list, or the entry becomes a hole that re-permits a regression in that same file).

**Scope stated honestly in the test:** it guards the Laravel notification layer, which is what the
preference system governs. It does **not** try to catch raw `Mail::to(...)`, because a static test
cannot reliably tell a mail to a *User* from a mail to a *client contact*. The two user-facing mail
bypasses that therefore escape it — `SendCalendarDigests` and `OversightDigestJob` — are **named in
the test** so the gap is on the record rather than pretended away.

---

## 3. Deploy notes

1. **`schema:dump` runs from the fully-migrated STAGING db, not from a worktree.** This branch adds
   a migration; I did **not** refresh `database/schema/mysql-schema.sql`, because this worktree's
   dev DB is not fully migrated and dumping from it would corrupt the snapshot. Tests are
   unaffected — new migrations run on top of the snapshot by design (CLAUDE.md 12a).
2. **Reference data:** `NotificationEventTypeSeeder` is registered in `deploy:sync-reference-data`
   — which must run on each target, because seeders do not run on a `git pull` deploy (AT-162).
3. **Migration is idempotent and reversible** (`down()` restores the retired toggles).
4. **Nothing to live** until Johan's explicit word.

---

## 4. Open decisions for Johan

1. 🔴 **The six unbuilt toggles** (table above) — build the watchers, or retire the rows? Today a
   user can switch them on and will never hear a thing.
2. **R4 (the three-engine calendar mess)** and **R5 (route the 31 bypasses through the gateway)**
   are the remaining architecture work. R7 is now in place, so R5 can proceed in slices without the
   debt growing behind it.
