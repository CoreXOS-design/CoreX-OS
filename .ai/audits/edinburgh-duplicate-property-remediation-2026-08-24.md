# Edinburgh duplicate-property incident — remediation proposal
**Date:** 2026-08-24 · **Status:** Proposal only, no code written. Johan approves before any lane builds.
**Investigation this proposal is based on:** read-only live investigation, same date, chat record with Johan (not yet a separate audit file — the findings are restated below as context, in full, so a lane can build from this document alone without re-investigating).

---

## 1. What happened (context for the fixes below)

Property 3218 (12/364 Edinburgh Street, agent Falan Du Bois, **active**) had corrupted data: the erf number (364) was typed into the `street_number` column instead of `erf_number`, and its `suburb` string ("Uvongo Beach") didn't match the correct suburb ("MANABA BEACH"). When a CMA deeds-capture scrape came in for the same physical property, `TrackedPropertyMatchOrCreateService::previewPropertyMatch()` — the one function that drives both the deeds-capture review screen and the create-time hard block — ran its three structural strategies (erf+suburb, address+suburb, GPS ≤25m) against agency stock and found nothing, because none of 3218's fields lined up. A second agent then created a duplicate property (15809), linked the same seller contact Falan had linked two months earlier, and sent that contact a pitch — while the contact was still assigned to Falan.

Three independent signals existed at the moment of creation. Exactly one was checked (the CMA scrape correctly matched an already-known *tracked_property*, itself a different, softer check than the agency-stock one) — and even that produced only an informational line, never a warning or block. The other two signals — the active advertised property, and the already-existing seller-contact link — were never queried by any code at all.

That's the shape of all three gaps below: not a warning that got clicked past, but checks that were never built for this combination.

---

## 2. Framing that governs all three proposals (Johan's own rule)

> Certain things get hard blocks. Probable things never interrupt the agent at all. Anything that can't be certain belongs in a back-office review queue, not on an agent's screen as another amber banner.

Every design choice below is judged against this. CoreX already has a soft-warning fatigue problem — Johan's words, this session: "the product already has too many soft warnings and users have learned to click past them." None of these three proposals add a new banner an agent can dismiss. Two are hard blocks. One is a silent, structural check that either escalates into an existing hard-block path or writes to a queue nobody but back-office ever sees.

---

## 3. Gap 1 — Hard block on active advertised stock

### The rule
"Active stock cannot be bypassed or created again" (Johan). Today this rule exists in code (`PropertyDuplicateAgeResolver::BAND_ACTIVE_BLOCKED`, wired into `DeedsCaptureController::promote()`) and it **works** — it fired correctly for a different property the same day this incident happened. The gap is not the block logic; it's that the *matcher feeding it* missed this specific pair because of bad data on the existing record.

### What to build
Two independent improvements to `TrackedPropertyMatchOrCreateService::resolvePropertyMatch()` (the function `previewPropertyMatch()` delegates to — same function backs both the review screen and the create-time block, so a fix here fixes both surfaces at once):

**a. A fourth structural strategy: title-deed / erf-history cross-check.** The CMA/deeds capture almost always carries a `title_deed_number` (Edinburgh's capture had `T31735/2019`). Add a strategy that checks whether any agency-stock property's `erf_number` *or* a `tracked_property_addresses` history entry for that property references the same title deed or erf number, independent of the candidate property's own (possibly wrong) `street_number`/`suburb` fields. This directly defeats the exact failure mode here: 3218's erf was typed into the wrong column, but its erf value ("364") still exists somewhere on the record — a title-deed/erf lookup that doesn't depend on which column the erf landed in would have caught it.

**b. Feed the new contact-link signal from Gap 3 into this same matcher as a corroborating strategy** (see §5 below — this is the connecting piece: the two proposals are not independent, Gap 3's check is what makes Gap 1's block reachable in the cases where address data is bad but the *seller* is the same person).

**c. Do not loosen the GPS or address-fuzzy thresholds.** Loosening tolerance to catch more cases raises false-positive risk (two genuinely different properties, one match) far more than it catches true positives — the existing 25m/exact-erf design already made this tradeoff deliberately (see the code comments re: 1166 Lynne Avenue, six distinct portions sharing one bare stand number). The fix is *more independent signals*, not *looser matching on the signals already tried*.

### What the user sees when it fires
Already built and correct — do not change the block message shape. `promote()` currently returns:
> "This matches [address] — [reason]. It cannot be updated from Deeds Capture."

This is already a hard redirect with an error banner, not a modal the agent can click through — no code path exists today that lets an agent proceed past `BAND_ACTIVE_BLOCKED`. The only action available is `acknowledgeBlockedMatch()`, which logs the acknowledgement and **removes the capture from the queue** — it never creates a duplicate. This is exactly the "rare, certain, unbypassable" shape Johan wants. Nothing needs to change here except making the matcher find the match in the first place.

### The escape hatch — and this is where the code already has the right shape

There is an existing, unused stub built for exactly this decision:

```php
// app/Services/Prospecting/PropertyDuplicateBlockGuard.php
class PropertyDuplicateBlockGuard
{
    public function authorizeOverride(User $user, string $band): bool
    {
        return false; // Johan has NOT confirmed override behaviour — do not change until he does.
    }
}
```

It is currently **not called from anywhere** — the block today is unconditional, with no override path at all (confirmed by grep: the only reference to the class is the comment claiming it's used; the promote() block never actually instantiates it). This is exactly the seam to wire the override through, rather than inventing a new mechanism:

- **Recommendation matches Johan's instinct:** `authorizeOverride()` returns true only for `branch_manager` and `admin` roles, and only for the `BAND_ACTIVE_BLOCKED` band specifically (leave `BAND_NO_GO` — recently-off-market — unoverridable; that band exists for a shorter, different reason and Johan hasn't asked for an escape hatch there).
- Every call to `authorizeOverride()` — granted or refused — writes a `property_match_decisions` row via the existing `PropertyMatchDecisionService`, with a new outcome value `'blocked_overridden'` alongside the existing `'blocked'` and `'blocked_acknowledged'`. This reuses the same audit table already proven decisive in this investigation (its unconditional-write behaviour is what let us prove no warning fired at all — the exact same property makes an override permanent, provable evidence).
- The override UI is a **second confirmation step behind the existing block screen** — not a checkbox on the same screen, not a "click here to force" link next to the error banner. A BM/admin who hits the block sees the same hard-stop error a plain agent would; a distinct, separately-permissioned action (`deeds_capture.override_active_block` — a new permission, not folded into `deeds_capture.access`) is what surfaces the override control at all. An agent without that permission never sees a live override option to be tempted by — this is what keeps the block "rare, certain, unbypassable" for the 95% of users, while giving BM/admin a real, logged way through for the genuine re-list / mandate-move / coincidental-address cases Johan is right to worry about.

### Blast radius
- `TrackedPropertyMatchOrCreateService` is used by both deeds-capture and (per the codebase map) prospecting/MIC matching generally — the new title-deed strategy must be scoped to only add a match, never remove one already found by strategies 0–3, so no regression to existing correct matches.
- `PropertyDuplicateBlockGuard` wiring touches `DeedsCaptureController::promote()` only — a single call site.
- New permission (`deeds_capture.override_active_block`) needs Setup Wizard exposure per non-negotiable #10a if it's agency-configurable; if it's a fixed role grant (BM/admin always, no agency toggle), it does not need a wizard entry — Johan's call, flag it in the build prompt.

### What could go wrong
- A title-deed match that's *wrong* (a title deed genuinely transferred, e.g. after a sale, and the new capture legitimately describes a different current owner) — mitigate by scoping the strategy to unsold/undated title-deed matches only, or by having it feed `resolveOrLogAmbiguous()` like the other strategies (never silently auto-picks when more than one candidate).
- An admin/BM override becomes routine rather than rare, defeating the "rare and certain" goal — mitigate by making the override log entry visible on a dashboard/report (not just a DB row nobody reads), so a pattern of overrides is visible to Johan, not buried.

### How to prove it works
1. Replay property 3218 / tracked_property 8476 against the new matcher (read-only, using the real historical data — the exact reproduction case this incident already gives you) and confirm `previewPropertyMatch()` now returns property 3218.
2. Confirm the block still fires and still cannot be bypassed by a plain-agent role.
3. Confirm a BM/admin override writes a `property_match_decisions` row with `outcome='blocked_overridden'` and the acting user's id.
4. Regression: re-run against the *other* same-day capture that correctly blocked (decision id=34, tracked_property 365 → property 15807) and confirm it still blocks identically — this fix must not touch that path's behaviour.

---

## 4. Gap 2 — The deeds→property approval gate

### What's actually there today
One flat permission, `deeds_capture.access`, gates the entire route group — index *and* promote. On live it is granted to **admin, branch_manager, office_admin, and plain agent** — all four roles, identically. There is no `deeds_capture.promote` permission, and `promote()` has no role check beyond confirming the tracked property belongs to the user's own agency. The only quasi-approval mechanism that exists, `PropertyTakeRequest` / `BAND_NEEDS_APPROVAL`, is conditional on `previewPropertyMatch()` finding a match in the first place — it never triggered here because nothing matched. Johan's belief that admin/BM sign-off is required is not a gate that got bypassed; it is a gate that was never built.

### The choice, stated plainly
There are two structurally different things Johan's sentence could mean, and they have very different costs:

- **(a) Every promotion requires BM/admin approval**, regardless of whether anything looks ambiguous. This is a blanket process control. It stops nothing that Gaps 1 and 3 don't already stop, and it adds friction to the large majority of promotions that have no duplicate, no shared contact, and no ambiguity at all — every agent's routine, correct deeds→property promotion now waits on a manager.
- **(b) Only ambiguous or flagged promotions require approval** — i.e. extend the *existing* `PropertyTakeRequest` mechanism (built for `BAND_NEEDS_APPROVAL`) to also fire when Gap 3's new "contact already linked elsewhere" signal is present, or when the new title-deed strategy from Gap 1 finds a *possible-but-not-certain* match (ambiguous candidates, multiple hits, GPS-only). This is Johan's own certain/probable/queue framing applied directly: certain match → hard block (Gap 1), probable-but-not-certain → this queue, nothing at all → straight through, same as today.

**Recommendation: (b).** Extending `PropertyTakeRequest` is the right move — it already has the notification plumbing (`PropertyTakeRequestNotifier`), an approval/reject model, and a UI. Building a second, parallel approval concept (a blanket role gate) would mean CoreX has two different ways a promotion can require sign-off, which is exactly the "two chips that say opposite things" pattern Johan has flagged elsewhere in this same screen's history. One business consequence to state to Johan plainly: **choosing (b) means the vast majority of promotions stay exactly as fast as they are today — approval only appears when the system has a specific, named reason to be unsure, not as a blanket tax on every agent's routine work.**

### What to build (if Johan confirms (b))
- No new permission. `deeds_capture.access` stays as-is for promoting the *clear* cases.
- `promote()`, when the new Gap-3 "contact already seller-linked to a different active property" signal fires, or when Gap 1's title-deed strategy finds an ambiguous (>1 candidate) result, does **not** create the Property directly. It creates a `PropertyTakeRequest` (same model, same notifier, same reviewer UI already built for `BAND_NEEDS_APPROVAL`) and tells the agent: "This needs a second look before it can go on your books — [BM name] has been notified." No amber banner, no click-through — the agent simply cannot create the record themselves in this specific, named situation.
- `PropertyTakeRequest`'s reviewer set already needs confirming — check who it currently notifies (branch_manager scoped to the agent's branch, or a fixed admin list) and confirm that matches "admin or BM" as Johan expects. This is a five-minute check for whichever lane builds this, not a re-investigation — the model and notifier already exist, just verify the recipient logic before assuming it's already BM/admin.

### If Johan actually wants (a) instead
State it as the tradeoff it is, don't build it silently: a new `deeds_capture.promote` permission, granted only to admin/branch_manager by default; a plain agent's `promote()` call is refused (not queued — Johan specifically distinguished "queued for approval" vs. "refused" as two different things to choose between) with a message pointing them at their BM, and every deeds-capture promotion in the agency waits on a manager whether or not anything is actually wrong with it. This is a real, defensible choice if Johan's priority is organizational control over promotion generally (not just this failure mode) — but it is a materially bigger, slower change to how every agent works day to day, for a problem Gaps 1 and 3 already close at the data layer.

### Blast radius
Touches `DeedsCaptureController::promote()` (new branch before property creation), `PropertyTakeRequest` (possibly a new `reason_code` distinguishing "band-triggered" from "contact-conflict-triggered" approval requests, for the reviewer's clarity), and the deeds-capture review screen (needs to show "pending approval" state alongside its existing live/stale/not_promoted states — `$stockStatusByTp` already has room for a state enum, this is one more value).

### What could go wrong
- If reviewer routing is wrong (e.g. no BM assigned to a branch), a queued request could sit unnoticed — same failure class `PropertyTakeRequestNotifier` presumably already has to handle for its existing trigger; confirm it has a fallback/escalation, don't assume.
- Approval-queue volume: if Gap 3's signal fires too often (e.g. a legitimate co-owned property scenario), this could flood BMs with routine approvals, breeding the same "click past it" fatigue Johan is trying to eliminate — the review criteria need to be tight and specific (this contact, this OTHER property, that other property currently *active*), not "any shared contact ever."

### How to prove it works
1. Simulate Edinburgh's exact conditions post-Gap-3-fix (contact 14001 already linked to active property 3218) and confirm `promote()` creates a `PropertyTakeRequest` instead of a `Property`.
2. Confirm the named reviewer (BM/admin) receives the existing notification.
3. Confirm a promotion with no conflicting signal at all (the ordinary case) is completely unaffected — no new approval step, no added latency.

---

## 5. Gap 3 — The contact link with no lookup

### What's there today
```php
public function linkSellerToProperty(int $contactId, int $propertyId, string $source = 'manual'): void
{
    DB::table('contact_property')->updateOrInsert(
        ['contact_id' => $contactId, 'property_id' => $propertyId],
        ['role' => 'seller', 'source' => $source, ...],
    );
}
```
`ComposeSellerService::linkSellerToProperty()` is a bare upsert. It never asks whether this contact is already linked to a *different* property, and it never asks whose agent that other link belongs to. This is the cheapest of the three gaps to close, and it is the one signal that would have caught Edinburgh outright — Quentin Breda was already the seller of record on an active property under a different agent, one query away.

### What to build
1. **A query, not a warning, at the point of link.** Before the upsert, look up `contact_property` for this `contact_id` with `role='seller'`, excluding the target `property_id` and excluding soft-deleted properties. If any hit exists where the linked property is currently active/advertised (reuse `Property::isStaleStock()` — already built, already used by Gap 1's matcher — inverted), that's a **certain** fact, not a probable one: this specific contact is already the on-record seller of a specific, named, currently-active property.
2. **Route that certain fact into the exact same decision machinery as Gap 1**, don't build a third, separate warning type:
   - If the *property being linked to* is itself a fresh, unmatched deeds-capture promotion happening in the same transaction (i.e. this is happening inside `DeedsCaptureController::promote()`'s owner-linking step, not a manual after-the-fact link on an already-existing property) — feed "this contact is already seller on property X" into `previewPropertyMatch()` as a new corroborating strategy (per §3's proposal (b)). A shared, currently-active seller contact is exactly the kind of independent signal that turns an otherwise-missed address match into a confident one.
   - If the link is happening **manually**, after the fact, on a property that already exists and was *not* just created via this capture (the actual Edinburgh sequence — Quentin Breda was linked manually, one minute after the automatic deed-owner link, to a property that had already been created) — this is a different moment with a different available action: the property already exists, so there's nothing left to block. This is where Gap 2's queue belongs: write a `PropertyTakeRequest`-style flag (or a lighter "duplicate-contact-link" review row if a full take-request is too heavy for this moment) so a BM/admin sees, within the day, "contact X was just linked as seller on two active properties under two different agents" — a back-office queue item, never an agent-facing banner, per Johan's framing.
3. **Do not block the manual link itself.** A contact legitimately can be the seller on two different properties (a second home, an inherited property, co-ownership across separate stands). The certain fact here is "linked twice, both active, different agents" — that's worth a same-day human look, not an agent-facing hard stop that would misfire on the legitimate multi-property seller.

### What to build, concretely, for a lane
- Add `sellerAlreadyLinkedElsewhere(int $contactId, int $excludePropertyId): ?Property` to `ComposeSellerService` (or a shared duplicate-signal service if Gap 1 wants to call the same logic — better to build it once and have both callers use it).
- Call it from `linkSellerToProperty()` before the upsert; also call it from `DeedsCaptureController::promote()`'s automatic owner-linking step (the one that created contact 17792's link at 14:31:36) so both the automatic and manual paths are covered — Edinburgh had one of each.
- When it returns a match: log it (new table or reuse `property_match_decisions` with a new `subject_type='contact_cross_link'`), and — per §4 — either strengthen an in-flight Gap-1 match decision, or write the back-office review flag if the target property already exists outside this transaction.

### Blast radius
- `ComposeSellerService::linkSellerToProperty()` — one new query before every seller link, agency-wide (seller-outreach compose screen, deeds-capture promotion, any other caller — grep confirms this is the only production call site of that specific method, so the blast radius is contained).
- No UI change required for the agent doing a normal, non-conflicting link — the check is silent unless it fires.

### What could go wrong
- Query cost: this runs on every seller link. `contact_property` should already be indexed on `contact_id`; confirm before shipping, don't assume.
- False signal on legitimately multi-property sellers — mitigated by routing to a human review queue rather than a block, exactly as scoped above.
- If this feeds Gap 1's matcher as a "strategy," it must go through the same `resolveOrLogAmbiguous()` discipline (never auto-picks on ambiguity) as every other strategy there.

### How to prove it works
1. Reproduce Edinburgh's exact contact-link sequence (contact 14001, already seller on active property 3218, manually linked to a second property) and confirm `sellerAlreadyLinkedElsewhere()` returns property 3218.
2. Confirm the link itself still succeeds (this is not a block — the contact does get linked) and that a review signal is written.
3. Confirm a genuine multi-property seller (two real, distinct active listings, same contact, same or different agent) does NOT get blocked and does NOT generate noise beyond the same quiet review-queue entry — check with Johan whether even that queue entry is wanted for the same-agent case, since same-agent is far less concerning than a different-agent case wasn't scoped separately in this proposal and is worth a quick confirmation before build.

---

## 6. Priority ranking before 1 September

**1. Gap 3 (contact link with no lookup) — build first.** Cheapest to build (one query, one call site, no UI, no permission changes), and it is the single change that would have caught Edinburgh even with 3218's data corruption left completely unfixed — it doesn't depend on address data being clean at all, since it matches on the contact, not the property. It also directly feeds Gap 1, so building it first makes Gap 1 stronger and easier.

**2. Gap 1 (hard block on active stock) — build second.** This is Johan's core, named rule ("active stock cannot be bypassed or created again") and the deepest structural fix, but it's larger: a new matching strategy, wiring an existing-but-unused override guard, a new permission, and careful regression proof against the matcher's existing correct behaviour (the Lynne Avenue / Villa Del Sol ambiguity cases it's already tuned for). Sequencing it after Gap 3 means it ships with the strongest signal set already available.

**3. Gap 2 (approval gate) — build third, and only after Johan confirms (a) vs (b).** This is a policy decision, not a discovered bug — nothing was "bypassed" here that a plain flat permission was ever supposed to stop, because that stop was never built. It's real, worth doing, and cheap to build as (b) since it reuses `PropertyTakeRequest` end to end — but it's the one of the three where doing it right requires Johan's answer before any code, and it delivers real value only in combination with 1 and 3 already existing to generate the "ambiguous" signal it routes on.

---

## 7. One sentence per gap, for the record

- **Gap 1:** The hard block Johan wants already exists in code and already works — it just never saw this case, because the matcher had no way to see past one property's own corrupted erf/address data.
- **Gap 2:** The admin/BM approval Johan remembers was never built — one flat permission covers every role, with no sign-off step of any kind between a deeds capture and a live Property record.
- **Gap 3:** The contact link that would have caught this outright uses a bare insert with no lookup at all — the one signal that was free, sitting in the database, and never asked.
