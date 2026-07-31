# Comms Phase 2 — design decisions for Johan's ruling

> **Date:** 2026-07-31 · **Author:** cc2 lane · **Status:** shipped to QA1 (`37e134ae`), awaiting Johan's verify.
> **Scope:** the 5 judgement calls made while building the confidence ladder + compounding-win in the
> existing `/corex/comms-suspense` engine. Each is a **conservative default**; every one has a documented
> alternative so Johan can tune in one read. None changes the shipped flow unless he asks.
> **Standing check:** `php artisan comms:ladder-check` re-asserts every ladder tier + gate + compounding
> win + shared-state on disposable, rolled-back data (17/17 PASS today).

---

## D1 — "provider + one side" tiering (attorney treated like supplier)  ⭐ Johan please rule

**Johan's spec (tier 3):** "Supplier + one side matches (seller OR buyer) → MEDIUM; promote to HIGH if
that matched party is unique to a single deal."

**Decision (conservative generalisation):** I treat a **transferring attorney + one side** at the SAME
tier as **supplier + one side** — i.e. any *provider* (supplier OR attorney) plus exactly one transaction
side → MEDIUM, promoted to HIGH when that side-party is unique to one deal. Any other 2-role combination
also → MEDIUM.

- **Why:** an email with the attorney + the seller is at least as deal-identifying as supplier + seller;
  leaving attorney+one-side at LOW would under-rank a strong signal. It never *auto-files* (that's the
  learned-ref only), so the risk of the generalisation is purely "suggested a bit more confidently."
- **Alternative if you want it literal:** restrict the MEDIUM/HIGH "provider + one side" rule to
  **supplier** only; attorney+one-side then falls to LOW (single strong party). One-line change in
  `scoreByParties()`.

**Where:** `CorrespondenceMatchService::scoreByParties()`.

---

## D2 — the "supplier" bucket includes the bond originator

**Decision:** the ladder's `supplier` role-bucket = the deal's **work-order suppliers** (COC / electrician
/ entomologist …) **plus the bond originator** (both are providers who are parties on the deal).

- **Why:** the bond originator is a provider party like a supplier; folding it in means a bond-originator
  email counts toward the ladder instead of being invisible. Johan's tier signals named
  seller/buyer/transfer-attorney/supplier; the bond originator wasn't listed, so I placed it in the
  nearest bucket rather than inventing a 5th role.
- **Alternative:** give the bond originator its own role (a distinct 5th signal), or exclude it entirely.
  Small change in `partyEmailsByRole()`.

**Where:** `CorrespondenceMatchService::partyEmailsByRole()` (supplier bucket).

---

## D3 — `normalizeSubject` does NOT strip Re:/Fwd: (conservative)

**Johan's spec:** "prefer [CX-D###] plus a normalized subject as backup. If the subject mutates
(Re:/Fwd:/edited) it must fall back to suspense — never mis-file. Conservative default."

**Decision:** the `subject_exact` key is **lowercase + whitespace-collapse only** — Re:/Fwd:/edits are
**deliberately kept**, so a mutated subject normalises to a different value → the learned auto-file
**misses → falls back to suspense as a suggestion** (never mis-files). The immutable `[CX-D]` token stays
the reliable auto anchor for genuine reply threads.

- **Why:** exactly your "EXACT SAME ref auto-files; a mutation falls back" rule. A reply "Re: X" is treated
  as a *new* correspondence for the subject-backup (but still auto-files if it carries the token).
- **Alternative (looser):** strip Re:/Fwd: prefixes so a plain reply "Re: X" also auto-files on the
  subject alone. Riskier (an unrelated "Re: Update" could collide) — hence not the default. One-line change
  in `normalizeSubject()`.

**Where:** `CorrespondenceMatchService::normalizeSubject()`.

---

## D4 — the G1=A gate lives in `EmailArchiveIngestor` (the one change outside the 3 services)  ⭐ Johan please note

**Context:** the build was scoped to "the three correspondence services + the suspense blade." Your G1=A
ruling ("an inbound email parks iff a recognised party is on it — any address on from/to/cc") is a **park-
gate** ruling, and the gate lives in the **live** `EmailArchiveIngestor`. So one branch there had to change.

**Decision:** the park branch now parks when the sender is a known provider **OR**
`CorrespondenceFilingService::hasKnownParty(from ∪ to ∪ cc)` is true (a party/supplier anywhere). Mail with
no party still drops (POPIA scope unchanged). `park()` was made null-provider-tolerant for the
"party-in-cc, unknown-sender" case.

- **Why safe:** the change only affects mail whose **sender is not a known Contact** (buyer/seller *senders*
  still take the unchanged Contact-archive path, so the queue won't flood). The new match logic itself all
  lives in the three services; the ingestor change is a single guarded condition + a null-provider fallback.
- **Alternative if you'd rather not touch the live ingestor:** keep the gate at "sender is a provider" only
  (revert the `hasKnownParty` branch) — then a party present only in cc with an unknown sender would drop
  instead of parking. The ladder/suggestion code is unaffected either way.

**Where:** `EmailArchiveIngestor.php` (park branch), `CorrespondenceFilingService::hasKnownParty()` +
`park()` null-provider tolerance.

---

## D5 — tier-4 subject-line name matcher (single unambiguous hit only)

**Johan's spec (tier 4):** "fall back to SUBJECT-LINE name matching — scan subject for names that match any
names on the agent's deals."

**Decision:** when no party address matched, scan the subject for a **seller/buyer name** (length ≥ 4) of
the agency's non-declined deals; suggest a deal only on a **single unambiguous hit** (if 2+ deals' names
match, suggest nothing — safer to let the agent pick). Always LOW tier.

- **Why:** name-substring matching is soft; a common surname could hit many deals. Requiring a unique hit
  avoids a confident-looking wrong suggestion. Agency-scoped at park time (no authenticated agent); the
  reselect dropdown is the agent-scoped layer.
- **Alternative:** show the top-N name candidates instead of suppressing on ambiguity, or add fuzzy
  (token/Levenshtein) matching. More surface; deferred as not asked-for.

**Where:** `CorrespondenceMatchService::matchBySubjectNames()`.

---

## Not decisions — already-settled facts (for completeness)

- **Shared-state** (one email = one row/link for all recipients) is enforced by two DB unique keys
  (`communications` agency+external_id; `communication_filing_suspense` agency+communication_id), plus a
  first-approval-wins guard in `verify()`. Not a judgement call — structural.
- **Confidence never bypasses suspense** (only a *verified learned-ref* auto-files) — this was already the
  engine's behaviour; Phase 2 preserved it.

*(All five are live on QA1 behind the shipped flow; reverting any one is a localised change in the file
named. `php artisan comms:ladder-check` guards the behaviour whichever way you rule D1/D3.)*
