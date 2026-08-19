# Comms Phase 2 — design decisions (RULED by Johan 2026-07-31, applied)

> **Date:** 2026-07-31 · **Author:** cc2 lane · **Status:** RULED + applied on QA1.
> **Overarching principle (Johan):** the **EMAIL ADDRESS is the primary, reliable matching signal**.
> Subject and personal names are secondary/fallback and must NEVER auto-file a deal on their own without
> email-address corroboration.
> **Rulings:** D1 confirmed (+ clarified: all non-principals are suppliers). D2 confirmed (folds into D1).
> **D3 CHANGED** (strip prefixes + address-corroborated auto-file). D4 kept. D5 kept.
> **Standing check:** `php artisan comms:ladder-check` re-asserts every ladder tier + all-suppliers-same-tier
> + gate + compounding-win (threaded-reply auto-files, subject-only does NOT) + shared-state on disposable,
> rolled-back data (**20/20 PASS**).

---

## D1 — CONFIRMED (+ clarified): two principals, everyone else is a supplier

**Johan's ruling:** buyer and seller are the two **principals**; **every** other party — transferring
attorney, bond originator, COC company, work-order suppliers — is a **supplier**, all treated identically
under the same tier logic. Keep the generalization.

**Applied:** `partyEmailsByRole()` now returns exactly **three** roles — `seller`, `buyer`, `supplier` —
where the **supplier bucket spans EVERY provider type** (attorney provider+contact, bond originator
provider+contact, and all work-order suppliers' firms+contacts). `scoreByParties()` tiers on those three:
all-three → HIGH · buyer+seller → HIGH · supplier + one side → MEDIUM, promoted to HIGH if that side-party
is unique to one deal · single party → LOW. (Proven: attorney+one-side and bond+one-side both → HIGH, same
as a work-order supplier + one-side.)

**Where:** `CorrespondenceMatchService::partyEmailsByRole()`, `scoreByParties()`.

---

## D2 — CONFIRMED (folds into D1)

The bond originator is a supplier (a non-principal provider party). Now handled by the single supplier
bucket in D1 alongside the attorney and work-order suppliers. No separate role.

---

## D3 — CHANGED: strip prefixes + normalize, and require ADDRESS corroboration to auto-file

**Johan's ruling:** strip system-added prefixes (Re:/Fwd:/…) and normalize the subject so a threaded reply
("Re: barnard / du toit") matches the core subject ("barnard / du toit") **and can auto-file** — BUT the
subject is a **secondary/thread signal**: an auto-file must be **corroborated by a party email address on
the message**. Subject alone (no address match) **never** auto-files.

**Applied (two changes):**
1. `normalizeSubject()` now **strips** the Re:/Fwd:/Fw:/Aw:/Wg: prefix stack, then lowercases + collapses
   whitespace — so a threaded reply matches the learned core subject.
2. `matchLearned()` gates the **subject-based** learned signals (`subject_exact`, `subject_pattern`) on
   `dealHasPartyOnMessage($dealId, $addrs)` — at least one party email of the learned deal must be on the
   message, or it does **not** auto-file (it falls through to the ladder → parks as a suggestion). The
   `[CX-D]` token, `thread_key`, and `sender_email` are reliable machine/address anchors and need no
   corroboration.

**Proven:** a "Re: …" reply **with** a party address auto-files; the same subject with **no** party address
does NOT auto-file (at most a LOW name-fallback suggestion). Net: subject makes the match looser on purpose;
the **email address is what actually pins the deal** — exactly the overarching principle.

**Where:** `CorrespondenceMatchService::normalizeSubject()`, `matchLearned()`, `dealHasPartyOnMessage()`.

---

## D4 — KEPT (Johan confirmed): the G1=A park-gate in `EmailArchiveIngestor`

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

## D5 — KEPT (conservative): tier-4 subject-line NAME matcher is a weak LOW fallback only

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

*(All rulings are live on QA1. `php artisan comms:ladder-check` (20/20 PASS) guards the behaviour — ladder tiers, all-suppliers-same-tier (D1), threaded-reply-auto-files + subject-only-does-not (D3), the park gate (D4), and shared-state — on disposable rolled-back data.)*
