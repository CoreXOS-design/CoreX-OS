# MIC — Claim Timer: Working-Day Countdown, One Extension, an Earlier Warning

**Spec ID:** `2026-08-19-claim-timer`
**Date:** 2026-08-19
**Author:** Claude (spec) — for Johan's review and approval before any build.
**Status:** DRAFT — not approved, not built.
**Business owner:** Johan Reichel
**Related:** `.ai/specs/mic-claim-working-hours-window.md` (commit `057a93485`) — see §0 below,
this document builds on it and corrects one thing it missed. `.ai/specs/2026-08-19-stale-stock-and-mic-resolution.md`.

---

## 0. First — checking the two claims in the request before building on them

Johan's brief said a spec was already written and a holiday service already exists, and asked
both to be verified rather than accepted. cc2 searched `.ai/specs/` for a "claim-timer" spec
earlier and did not find one.

**A spec exists. cc2's search missed it on the name, not because it isn't there.**
`.ai/specs/mic-claim-working-hours-window.md`, committed in `057a93485` ("docs(mic): spec —
split Claim from Pitch, working-hours release window"), same day, status DRAFT/unapproved. It
is a real, substantial answer to most of tonight's brief — including the exact "working days
vs. office hours" question Johan asked me to re-check, which it already answers with reasoning
worked against his own Friday-16:00 example (§4 below quotes it). Its Part 1 (splitting Claim
and Pitch into two buttons, and making a bare Claim actually leave the pool) is **not
hypothetical any more — it shipped tonight**, as commit `300a247ba`, cherry-picked into the MIC
body and folded into staging. Parts 2–4 (working-day countdown, one extension, an earlier
warning) were written but never built. That is the gap this document exists to close: a fuller,
independently re-verified spec for exactly those three parts, plus one thing the earlier
document did not catch (§3).

I have not edited or superseded the earlier file — that's a judgement call for whoever owns
`.ai/specs/` hygiene, not something to do unilaterally while writing a different document. Both
files should be read together; where they agree I've said so and moved on, where I found more
(the two-clock bug) or verified something more precisely (live data, not just code reading) I've
gone further.

**The holiday service exists, and is more capable than "a fixed list."** Confirmed in §2.

---

## 1. How claim expiry works TODAY — verified in code and against live qa1 data

**The rule:** a claim expires at exactly 48 wall-clock hours after `claimed_at`, with two
exemptions — a claim that has been **pitched** (`pitched_at` set) never expires, and, less
obviously, a claim that has received **any feedback** (`feedback_at` set, e.g. logged as
"Contacted") also never expires under this check. Both facts are load-bearing for the migration
question in §5.

```php
// app/Models/ProspectingClaim.php
public function isExpired(): bool
{
    if ($this->pitched_at !== null) return false;
    return $this->is_active
        && !$this->feedback_at
        && $this->claimed_at < now()->subHours(48);
}
```

**What actually runs it:** two independent mechanisms, and they use different timestamps —
this is the discrepancy Johan asked me to trace, and it's real, not a formatting quirk.

1. **The real enforcement clock** — `prospecting:maintain-claims`, scheduled **hourly**
   (`routes/console.php`), bulk-releases every matching row straight off `claimed_at`:
   ```php
   ProspectingClaim::active()->whereNull('pitched_at')->whereNull('feedback_at')
       ->where('claimed_at', '<', now()->subHours(48))
       ->update(['is_active' => false, 'released_at' => now()]);
   ```
   `claimed_at` is written exactly once, at claim creation, and is **never updated again by any
   code path** (confirmed — grepped every write to `claimed_at` in the claim service and
   controller; both hits are inside the original `ProspectingClaim::create()` calls).

2. **The display clock** — `ProspectingListingStateEnricher.php:228`, which drives the
   "CLAIM EXPIRES SOON" badge, the R2 suggested-action tile, and the "you · 19h" row chip —
   computes off **`last_updated_at`**, not `claimed_at`:
   ```php
   $expiresAt = $lastUpdatedTs + 48 * 3600;
   $hoursLeft = max(0, ($expiresAt - $now) / 3600);
   ```

**Those two timestamps start equal at claim time and drift apart the moment anything touches
the claim without pitching or logging feedback on it.** The concrete case: `POST
.../claims/{claim}/notes` — an existing, live endpoint that lets an agent add a free-text
progress note to an active, unpitched claim (`MarketIntelligenceController.php:2782`, via
`ProspectingClaimService::recordActionOnClaim()`) — bumps `last_updated_at` to `now()` and
clears `warned_at`, **without touching `claimed_at`, `pitched_at`, or `feedback_at`**. The
display recalculates as if the 48-hour window restarted from that note; the real hourly job
keeps counting from the original claim time regardless. An agent who leaves a note ("left a
voicemail") sees the countdown jump back up to a fresh ~48 hours, while the actual auto-release
still fires at the original deadline — which can now be **before** the display's next warning
threshold ever triggers.

The row chip's own tooltip text makes an explicit promise the code doesn't keep:
> *"Your claim — expires in {hoursLeft} hours unless you log feedback. **The 48-hour window
> resets every time you update the claim.**"* — `_listing-row.blade.php:207`

That sentence is true of the number shown, and false of the actual clock. **This is the
concrete mechanism behind Johan's "warning comes after it expires, not before."** It is not
true in the simple, untouched case — claim it and never touch it again, and the two clocks
stay identical, so the badge (default threshold 6h, agency-configurable via
`SuggestedActionThresholds::expiry_warning_hours`) genuinely does fire 6 hours ahead of the real
release. It is specifically the claim an agent has *partially worked* — added a note, no
pitch or feedback yet — where the warning becomes unreliable, and that's the case that matters
most, because that's the agent actually engaged with the property.

One more instance of the same shape: `ProspectingClaimService::reassignClaim()` (a manager
moving a stale claim to a different agent) bumps `last_updated_at` with the comment *"fresh
start for the new agent"* — but doesn't touch `claimed_at` either. The new agent's display
resets; the real 48-hour clock, running from whenever the original agent claimed it, does not.

**Any working-day rebuild needs to fix this as part of the same change, not as a follow-up.**
Building a correct working-day countdown on top of `last_updated_at` while enforcement stays on
`claimed_at` would keep the exact same bug in a new, harder-to-notice form.

---

## 2. The holiday service — confirmed, and it's more than "a list"

`App\Services\Leave\PublicHolidayService` exists (`app/Services/Leave/PublicHolidayService.php`).
Verified by reading it, not by trusting its doc comments:

- `generateHolidaysForYear(int $year)` **computes** the full SA statutory set algorithmically
  for any year — 10 fixed-date holidays, the Public Holidays Act's Sunday-rolls-to-Monday rule
  applied correctly, plus Good Friday and Family Day derived from `easter_days()`. It is not a
  hand-maintained list with an end date — ask it for 2031 and it computes 2031.
- `countWorkingDays()` and `isWorkingDay()` **already exist and already take a configurable
  working-day mask** (which days of the week count) plus the holiday check — these are close
  to the exact primitives a working-day claim countdown needs. This is currently used for
  leave/payroll accrual and `OutreachWindowService`'s send-time gating — genuinely the same
  "is this a working day" question this feature is asking.
- `ensureYearSeeded()` + the console command `corex:seed-public-holidays {year?}` persist a
  year's holidays to the `public_holidays` table (checked: `SeedPublicHolidaysCommand.php`,
  signature `corex:seed-public-holidays {year?} {--country=ZA}`).

**The gap, confirmed against live qa1 data, not assumed:**
```
PublicHoliday::count()  →  0
```
**Zero rows are seeded on qa1 right now.** `isPublicHoliday()` does a DB existence check — with
nothing seeded, it silently returns `false` for every date, meaning holidays would not be
skipped at all until someone runs the seed command. And the command is **not scheduled
anywhere** (`grep`'d `routes/console.php` — nothing calls it). It is a manual, one-off action
today. If this feature ships depending on it, either the deploy runbook must seed it every year
(and the current year, on this environment, before go-live), or a yearly schedule entry needs
adding as part of this build — otherwise the feature works flawlessly through December and
silently stops skipping holidays the following January until someone remembers.

---

## 3. Is there an existing extension concept? No — confirmed absent

Grepped `ProspectingClaim`, `ProspectingClaimService`, and the MIC controller for
`extend`/`extension`: the only hits are PHP's `class X extends Y` syntax and one unrelated
comment about the short-lived pitch-composer *lock* ("Same agent clicking Pitch again → extend
the lock" — a different, much shorter TTL mechanism, not the 48-hour claim). **No extension
field, no manager-visible reason field, no precedent to reuse beyond the general pattern already
in place on `release_reason`/`notes`** (a structured-reason-plus-free-text shape the claims
table already uses for releases — reusable as the shape for the extension reason, not as
existing functionality). This is entirely new, exactly as Johan assumed.

---

## 4. Design — the working-day countdown (the hard part)

**Whole working days, not office hours — Johan's own instinct, and `057a93485`'s spec already
worked the reasoning through and I re-checked it, it holds:**

> claim Friday 16:00 → raw 48h lands Sunday 16:00 → Saturday and Sunday each pushed out as a
> full non-working day → Sunday 16:00 + 48h → **Tuesday 16:00**.

That's a whole-day skip, not an hours-of-office-day model. The alternative (say, only counting
08:00–17:00) would need to decide what happens to evening/weekend work agents already do by
choice, adds timezone and per-agency-hours complexity nobody has asked for, and stretches "48
hours" into something that no longer reads as 48 hours to a normal person. **Confirming, not
re-deciding: Johan has already effectively made this call** — I found the same reasoning
independently and it matches. Overnight hours ARE counted as before (a claim doesn't pause
9pm–8am); only whole non-working days pause it.

**The consequence, stated plainly because it's a real business change, not a footnote:**

> **A claim made Friday afternoon dies over the weekend today. Under this change, the same
> claim survives to Tuesday.** Any claim touching a Friday, Saturday, or Sunday gets roughly
> two extra calendar days of life. A claim spanning a public holiday gets one more on top of
> that. Stock that would have silently returned to the pool by Monday morning now stays locked
> to one agent until Tuesday afternoon (or later, around a holiday).

This means **less canvassable stock available at the start of every week**, which is the exact
opposite of what the 48-hour window exists to prevent ("stock stuck there could turn into lost
sales" — Johan's own words, quoted in `057a93485`). It's very likely still the right call — a
weekend is genuinely dead time for an agent to act, and expiring a claim while nobody could have
worked it anyway isn't protecting anything, it's just noise — but Johan should make that
trade-off with the number in front of him, not discover it from agents complaining that Monday's
pool looks thinner. **Recommend watching Monday-morning canvass-pool counts for the first two
or three weeks after this ships**, so if the effect is bigger than expected in practice, it's
caught early rather than argued about from memory later.

**Implementation shape** (not committing to exact code, but the pieces already exist):
`PublicHolidayService::isWorkingDay()`/`countWorkingDays()` with a working-day mask of
Mon–Fri (confirm with Johan whether Saturday is ever a working day for any agency — real estate
commonly works Saturdays; if so the mask needs to be configurable per agency, not hardcoded
Mon–Fri, which is a real decision point, not a detail). A working-day-aware "add N working days
to a timestamp" helper is the one new primitive needed on top of what exists — walk forward
day by day (or use `countWorkingDays` in reverse) until N working days have been consumed,
skipping non-working-mask days and holidays identically for the deadline calculation and the
display countdown, **the same function for both**, so §1's two-clock bug cannot recur in a new
form. This is explicitly why `057a93485` called the display rewrite "the single most
failure-prone part of this feature" — agreed, and doubly true now that §1 has shown the
existing display is *already* wrong in a way nobody had traced before tonight.

---

## 5. Design — the one deliberate extension

**One per claim, ever.** An "Extend" action on an active, unpitched claim (mirrors §1's
`recordActionOnClaim` note-adding pattern — same authorisation shape, claim owner or a
prospecting manager) requires a reason before it applies. Reason: a short list of common causes
(*"seller not reachable yet," "gathering deeds info," "waiting on a callback"*) plus free text,
matching the existing `release_reason` (structured) + `notes` (free text) shape already on the
table — reuse it, don't invent a second reason mechanism.

**What it should add:** one further working day, same unit as the base window, so there's one
consistent concept instead of two clocks to explain to an agent.

**What stops a second extension:** a new boolean/timestamp column (e.g.
`extended_at`/`extension_reason`) — the button/action simply doesn't render, and the endpoint
itself must also reject a second attempt server-side (never trust a hidden button alone — the
same "note" endpoint pattern already shows why: nothing today stops an agent hitting an endpoint
directly).

**Manager visibility:** the extension and its reason need to appear on the claim wherever a
manager already reviews claims — the existing R1 "FLAG TO BM" stale-listing review surface and
`_slideover-activity-entry` timeline are the natural places, since they already show a
timestamped action trail for a claim; extension should ride the same trail, not a separate view.

**If an agent needs longer than the one extension allows:** the brief doesn't say, and this is
a real product decision, not a technical one. Two honest options: (a) it simply expires — the
one extension is the entire mechanism, full stop, matching "one deliberate extension" read
literally; or (b) a second request routes to a manager for a manual decision (the manager
`reassignClaim()`/`keepClaim()` actions already exist and already reset the *display* clock —
though note §1's caveat: they don't currently reset `claimed_at` either, so "manager keeps it"
today has the exact same silent-expiry risk as an agent's note does, and that needs fixing
alongside this, not after). **Needs Johan's decision — flagged, not guessed.**

---

## 6. Design — the warning, before it expires

**When:** the existing 6-working-hour default (`SuggestedActionThresholds::expiry_warning_hours`,
agency-configurable) is a reasonable number to carry over unchanged — but "hours" now needs to
mean **working hours within the working-day countdown**, not wall-clock hours, or the same
divergence from §1 reappears in a new shape (a Friday-afternoon claim showing "6 hours left" when
it's actually "6 working hours across into Tuesday" reads very differently to an agent glancing
at a badge). **Recommend restating the threshold as a proportion of the window instead of a
fixed hour count** — e.g. "the last quarter working day" — so it scales sensibly however long the
window ends up being after an extension, rather than needing a second constant for "6 hours of
the extended window." This is Johan's call on the exact number/proportion, not mine — flagging
the unit question so he answers it in those terms.

**How the agent is told:** nothing new to build for delivery — reuse
`NotificationDispatcher`, the same pipe the existing stale-claim nudge already uses (confirmed
by `057a93485`, and consistent with every other MIC reminder in the codebase). The trigger
condition and wording are new; the delivery mechanism is not.

**Where it's also shown:** the same row chip / R2 "CLAIM EXPIRES SOON" suggested-action tile
that exists today, once its number is fixed to read off the same working-day-aware
deadline/countdown function as the real enforcement clock (§1, §4) — not a second, independently
wrong number.

---

## 7. Migration reality — claims already running on the old clock

**This needs an answer, not a shrug, and here is the honest one.**

Checked qa1 directly: **0 active claims are currently subject to the plain 48-hour wall-clock
timer** (unpitched, no feedback) — all 65 currently-active claims on qa1 already carry either
`pitched_at` or `feedback_at`, which exempts them from expiry entirely, on both the old rule and
the new one. **This number will very likely be nonzero on production and needs re-checking
there before this ships** — qa1 is not a reliable stand-in for live claim volume, it's just what
was available to check read-only tonight.

For whatever population *is* in flight at cutover, three honest options, same shape as
`057a93485`'s already-settled call on a related question (leave every claim exempt under the
rule it started under, don't retroactively re-evaluate):

- **(a) Grandfather in-flight claims onto the old wall-clock rule.** A claim already running
  when this ships finishes on the deadline it was claimed under; only claims made *from ship
  day onward* get the working-day countdown. Simplest, no surprises for an agent mid-claim,
  matches the precedent `057a93485` already set for its own "protection triggers on pitch"
  change. **Recommended**, for the same reason that precedent gave: nobody should have a rule
  change underneath them mid-claim.
- **(b) Recompute every in-flight claim's deadline under the new rule immediately.** Simpler
  code (one rule, no dual-path), but retroactively changes when a claim someone is actively
  working expires — could shorten *or* lengthen a deadline an agent is already planning around,
  without their knowledge, the exact kind of surprise (a) avoids.
- **(c) Recompute only claims not yet past their old deadline; anything already overdue under
  the old rule releases immediately at cutover regardless of the new rule.** A middle ground —
  avoids extending anything, but still recomputes ("Tuesday, not Sunday") for anyone genuinely
  mid-window, which is (b)'s surprise in a smaller dose.

**Recommend (a).** It's the same precedent Johan's own earlier spec already set for a closely
related question, and it means zero agents get a mid-claim rule change to notice or complain
about. Needs his confirmation before build — this determines whether the deploy needs a one-time
migration script at all (under (a), it does not: existing rows keep meaning what they meant,
only new claims read the new rule) or whether it needs one that recomputes deadlines for
whatever's in flight (under (b)/(c)).

---

## 8. What exists vs. what has to be built

**Already exists, reusable as-is:**
- `PublicHolidayService` — full SA statutory generation, working-day mask support,
  `countWorkingDays()`/`isWorkingDay()` primitives. (§2)
- The Claim/Pitch split and "claim actually leaves the pool" — shipped tonight (`300a247ba`).
  (§0)
- `NotificationDispatcher` — delivery pipe for the warning. (§6)
- The `release_reason` + `notes` structured-plus-free-text shape — reusable for the extension
  reason. (§5)
- `recordActionOnClaim()` — the authorisation/audit-trail pattern an Extend action can copy.
  (§5)

**Genuinely new:**
- A working-day-aware "add N working days" function, used identically for the real deadline
  and the displayed countdown — the one new primitive. (§4)
- Fixing the `claimed_at` vs `last_updated_at` two-clock divergence (§1) — not optional, and
  not something a working-day rebuild can leave in place, or the bug just gets harder to see.
- Extension: one column pair (`extended_at`, `extension_reason` or similar), the endpoint +
  UI, the one-per-claim guard (client AND server side), and the manager-visible surface. (§5)
- Warning: new trigger condition read off the corrected countdown, new wording, and a decision
  on the unit (§6).
- Yearly holiday seeding needs to become a scheduled job, or an explicit deploy-runbook step,
  or the feature silently regresses to wall-clock behaviour every January. (§2)
- A per-agency working-day mask, if Saturday working is a real case (§4) — otherwise Mon–Fri
  hardcoded is fine and smaller.
- The migration path chosen in §7.

**Rough size:** the display/deadline unification (§1, §4) is the largest and most
failure-prone piece — it touches the enricher, the maintenance job, the badge, the row chip, and
needs a single source of truth shared by all of them, the same "fix the class, not the
instance" standard the rest of tonight's claim work has been held to. The extension (§5) is a
contained, mid-sized addition — one table change, one endpoint, one UI control, reusing existing
patterns throughout. The warning (§6) is small once the countdown itself is correct — it is
mostly a threshold and a wording change against infrastructure that already exists.

---

## 9. Every decision Johan has to make, in one place

1. **Migration path (§7):** grandfather in-flight claims onto the old rule (recommended), or
   recompute some/all of them at cutover?
2. **Per-agency working-day mask (§4):** is Saturday ever a working day for any agency, or is
   Mon–Fri hardcoded fine for everyone?
3. **Warning unit (§6):** keep a fixed working-hour count (and if so, how many, now that hours
   mean something different inside a working-day window), or express it as a proportion of the
   window instead?
4. **The "what if an agent needs longer than one extension" path (§5):** hard stop, or routes to
   a manager for a manual decision?
5. **Holiday-seeding operational ownership (§2):** add it to the deploy runbook as a yearly
   manual step, or make it a scheduled job as part of this build?
6. Not new to this document, but still open from `057a93485` and directly touches this build:
   the exemption-trigger question (pitch vs. promotion) — tonight's shipped code (`300a247ba`)
   kept the existing `pitched_at` trigger unchanged, so this spec assumes that stays as-is
   unless Johan says otherwise.

Nothing in this document has been built. No migration, no controller change, no view change has
been made. It requires Johan's decisions above before any build prompt should be written
against it.
