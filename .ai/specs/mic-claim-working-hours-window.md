# MIC — Claim vs Pitch, and a Working-Hours Release Window

**Spec ID:** `mic-claim-working-hours-window`
**Date:** 2026-08-19
**Author:** Claude (spec) — for Johan's review and approval before any build.
**Status:** DRAFT — not approved, not built. Written for sign-off first, per Johan's instruction.
**Business owner:** Johan Reichel
**Related:** `.ai/specs/mic-promoted-property-exclusion-proposal.md`; this session's guard commits `497b6447a`, `9a692b188`, `b1e294dcb`.

---

## 1. The business problem, in Johan's words

> "having agents taking stock and reserving it takes it away from other agents. and having stock stuck there could turn into lost sales. That's why the 48 hour window was designed."

Claiming a property removes it from every other agent's canvassing list. That's the whole point — it stops two agents pitching the same seller. But it only works if a claim that goes nowhere lets go again. Today, opening the "Pitch Now" screen — even without typing a single word — locks a property to one agent **forever**, with nothing that ever gives it back. An agent can claim ten properties Monday morning, open the screen on each once, and never touch them again, and all ten sit locked out of the canvassing pool indefinitely. That is stock taken away from every other agent for nothing.

## 2. What ships — four parts, in plain language

### Part 1 — Claim and Pitch become two different buttons

**How an agent experiences it:** on a Market Intelligence property, two buttons instead of one.

- **Pitch Now** — unchanged. Opens the contact/compose screen immediately, same as today.
- **Claim** — new, sits below Pitch Now. One click. Nothing opens. The property is reserved to that agent, appears in their My Claims, and the countdown (Part 2) starts.

The difference that matters: **only Pitch Now protects the reservation from expiring.** Claim alone starts a clock. If the agent never comes back to actually pitch it, it releases on its own — exactly the "claimed and sat on it" case Johan does not want. Once the agent genuinely pitches it (opens the contact screen and works it — see the honesty note in §6), the clock stops for good, because real work has happened.

**What's already built, so the estimate is honest:** a "Claim" action already exists in the code today (the bookmark-style claim on a property) and a "Pitch" action already exists (opens the compose screen) — they already appear side by side on the property detail panel when nothing is claimed yet. The genuinely new work is not inventing two buttons from nothing; it's (a) making sure they're both visible in the right place on the list itself, not just once you've opened a property, and (b) moving *when* the protection switches on — today it switches on the instant Pitch Now's screen loads, which is the loophole; it needs to switch on only once the agent has actually pitched.

### Part 2 — The 48-hour clock skips weekends and public holidays

**How an agent experiences it:** they claim a property Friday at 16:00. The countdown shown on My Claims does not count Saturday or Sunday at all — it picks back up Monday morning exactly where it left off. It expires Tuesday afternoon, not Sunday. A South African public holiday is skipped the same way a weekend day is.

This is the one part of the design Johan asked to be checked for a working assumption:

- **Are SA public holidays already known to CoreX?** Yes, honestly — `App\Services\Leave\PublicHolidayService` already exists, already generates the correct SA statutory list (fixed dates plus the Easter-linked ones, with the Public Holidays Act's Sunday-rolls-to-Monday rule already handled), and already regenerates itself for future years on demand rather than needing a list maintained by hand. It's currently used for leave/payroll and for gating outreach send times (`OutreachWindowService`) — the exact same "is this a working day" question this feature needs. This is not something to build; it's something to call. One thing to do first: 2026's holidays are not yet seeded on QA1 (`php artisan corex:seed-public-holidays 2026` — the command already exists, checked directly).

- **Working days or office hours?** Johan's instinct — whole calendar working days, not an hours-of-the-day cutoff — is the right call, and here's why, worked against his own example: claim Friday 16:00 → raw 48 hours lands Sunday 16:00 → Saturday and Sunday are each pushed out as a full non-working day → Sunday 16:00 + 48h = **Tuesday 16:00**. That matches "expires Tuesday afternoon" exactly. An hours-of-the-day model (say, only counting 08:00–17:00) would also need to decide what happens to evening work — real estate agents routinely work evenings and weekends by choice, and nobody has asked for the system to stop protecting a claim overnight. The complaint on the table is specifically about days nobody is expected to work at all, not hours within a working day. Adding an hours-of-day cutoff solves a problem nobody raised, adds real complexity (timezones, per-agency configurable hours), and would stretch "48 hours" into something that no longer reads as 48 hours to an agent. Whole working days is simpler, matches the actual complaint, and is what "48 hours" still means to a normal person.

- **The countdown display must show the real number.** If the display just does `deadline − now` in raw hours, it will show a wrong, panic-inducing number on a Friday evening (e.g. "4 hours left" when it's really Tuesday). The countdown has to be computed with the same weekend/holiday-skip logic as the deadline itself, not a naive subtraction. Getting this right is the single most failure-prone part of this feature, because a wrong number is worse than no number — it either scares an agent into rushing a bad pitch, or worse, quietly reads "plenty of time left" when it isn't.

### Part 3 — One deliberate extension, once, with a reason

**How an agent experiences it:** on My Claims, next to a claim that's running down, an "Extend" button. Click it, and it asks why — a short reason, typed or picked from a short list (e.g. "seller not reachable yet," "gathering deeds info," "waiting on a callback"). One extra working day is added. The button is then gone for that claim — it cannot be used twice. Both the extension and the reason stay visible on the claim, to the agent and to their manager.

**Length recommended: one further working day**, matching Johan's instinct — the same unit the base window already uses (a working day, skip-weekend-and-holiday-aware), so it's one consistent concept rather than two different clocks to explain.

**Why require a typed reason instead of a free grace period:** Johan's own reasoning is airtight here — an automatic grace period is invisible to a manager and tells them nothing; a reason attached to a name is something a branch manager can actually look at and manage. Keeping it.

### Part 4 — A warning before it expires, not after

**How an agent experiences it:** with 6 working hours left on the countdown, a notification — "6 hours left on [address] — pitch it or lose it" — through the same in-app notification system CoreX already uses for every other reminder in this system (`NotificationDispatcher`, the same mechanism the existing "your claim is going stale" nudge already uses). Nothing new is being built for delivery — the trigger condition and the wording are new, the pipe is not. The same message also shows directly on the claim's row in My Claims, so it's visible without waiting for a notification to be checked.

## 3. What happens to claims already in flight, and to the 99 damaged rows

**Nobody loses stock because the rule changed underneath them.** Every claim that is already active and already protected under today's rule (pitched, and therefore currently exempt) stays exempt under today's rule — it is not retroactively re-evaluated against the new "protection triggers on completed pitch" test. The new rule applies to claims made **from the day this ships onward**. An agent working a claim today does not get surprised by a rule that didn't exist when they started it.

The 99 already-damaged rows from the earlier audit (claims already released while the property was already promoted) are a **separate, already-flagged decision**, unaffected by this feature either way — whether or not those get repaired is Johan's call on its own timeline, not a prerequisite for or a consequence of shipping this.

## 4. Things worth flagging before this is built — not settled yet

1. **Where exactly do the two buttons live?** The existing Pitch/Claim pair already sits side by side on the property's detail panel once you open it. Johan describes Claim sitting "below Pitch Now" — need to confirm whether that's the same panel (just re-ordered vertically instead of side by side) or whether both buttons also need to appear directly on the list row itself, which today only shows a bookmark-style claim icon, no Pitch button at all. This changes the size of the front-end piece of the work.
2. **Precise trigger for "genuinely pitched."** The cleanest, already-real signal in the data today for "the agent did something real" is the property actually being created (today's "Create & Continue" / promotion moment) — not merely opening the compose screen (today's bug) and not simply having typed something into a field (nothing is saved that early today). Recommend the exemption trigger be **the same moment a claim already becomes permanently linked to a real property** — i.e. promotion — rather than inventing an earlier "in-progress" checkpoint. Worth Johan's explicit confirmation, since it means an agent gets no protection at all while mid-way through gathering deed/seller information, right up until they finish — the countdown genuinely keeps running while they work, which is the intended incentive, but is worth saying out loud so it isn't a surprise.
3. **The countdown-display rewrite is real, non-trivial work**, not a formatting tweak — today's "hours left" badge is a naive `now − timestamp` calculation with no concept of weekends or holidays at all. This needs its own careful build-and-check pass, separate from the deadline calculation itself, precisely because a wrong display is worse than no display.

## 5. What is NOT changing

- The 48-hour figure itself is not changing to 72 or any other number — only how the clock is measured.
- P24/PP/address/in-stock filter behaviour (this session's other fix) is unrelated and untouched.
- Nothing here touches the already-flagged, separately-owned listing-level write-back gap or the draft-status proposal — both stay exactly where they were left.
