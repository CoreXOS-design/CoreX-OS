# AT-68 — Private Property status parity: investigation

> **Status:** investigation. **No production code written.** One authorised live read/write probe
> was run against PP prod (property #1972) and **rolled back to its exact baseline** — see §7.
> **Question:** m3's Wave 2 flows under-offer / sold to P24 immediately. What can Private
> Property's SOAP API actually do about status, what would our mapper need, and what does the
> fix cost?
> **Short answer:** **PP supports everything we need — the API is not the constraint.** The gap
> is ours, it is bigger than the ticket says, and **PP has no status delivery path at all**, so
> the moment Wave 2 goes live the two portals will disagree in public.
> **DECISION (Johan, adopted):** ship the **under-offer** half with Wave 2; **`sold` stays
> `Inactive`**; the `Sold` question settles in sandbox when PP restores it (§6).
> **Also proven, the hard way:** **PP answers `"Successful"` while doing nothing** — every status
> push must be verified by reading it back (§7.1).
> **Author:** m6, 2026-07-11. Sources: `storage/pp-agentimport.wsdl` (the live contract),
> the PP and P24 mappers, `PropertyObserver`, `DesyndicatePropertyFromPortalsJob`, and the §7 probe.

---

## 1. What PP actually supports (from the live WSDL, not from memory)

`storage/pp-agentimport.wsdl` — Agency Feed Service. The contract is unambiguous:

**`PropertyStatus` enum — six values:**

```
ForSale · ToLet · PendingOffer · Sold · Inactive · Archived
```

**`ListingType` enum:** `Unknown · Sale · Rental · Both`

**Operations that matter:**

| Operation | Contract | What it gives us |
|---|---|---|
| **`ListingStatusUpdate`** | `(BranchId, PropertyId, ListingType, PropertyStatus, Token)` | A **dedicated status-only push.** No need to re-submit the whole listing to change status. |
| `GetListingStatus` / **`GetListingStatusVerbose`** | per listing | **Read-back**, so we can verify what PP actually believes — the AT-221 lesson (a portal can accept a call and still not do what you asked). |
| `UpdateListing` | full payload | `PropertyStatus` is a **required** field on every listing submit. |

**So: under offer (`PendingOffer`), sold (`Sold`), and withdrawn (`Inactive` / `Archived`) are
all first-class in PP's contract. There is no API limitation to work around, and the transport
already exists in our code** — `PrivatePropertySoapClient` already calls `ListingStatusUpdate`.

---

## 2. The ticket's premise is half stale — and the real cause is a single wrong belief

AT-68 says PP's payload is "hardcoded ForSale/ToLet and ignores status". **That was fixed on
2026-06-20** (PP-1, `.ai/audits/syndication-bug-sweep-2026-06-20.md`).
`PrivatePropertyListingMapper::mapPropertyStatus()` today does:

- off-market words (`sold`, `rented`, `withdrawn`, `expired`, `cancelled`, `archived`,
  `unavailable`) → **`Inactive`**
- everything else → **`ToLet`** (rental) or **`ForSale`** (sale)

But read that fix's own docblock:

> *"Off-market statuses now map to `'Inactive'` — **the only off-market PropertyStatus PP's
> submission contract documents (ForSale, ToLet, Inactive)**."*

**That is factually wrong against the WSDL.** PP documents **six** statuses, not three. The June
fix was built on a three-value belief, so it collapsed every off-market state into `Inactive` and
never considered `PendingOffer` or `Sold` — because it did not know they existed.

**That single wrong belief is the root of AT-68.** It is worth saying plainly, because the fix is
not "add a feature PP lacks" — it is "use the contract PP has always had".

---

## 3. The parity gaps, concretely

What each portal will show once Wave 2 is live:

| CoreX state | P24 (Wave 2) | **PP today** | PP *should* send | Verdict |
|---|---|---|---|---|
| **Under offer** | `Pending` — flagged, immediately | **`ForSale`** — still advertised as plainly **for sale** | `PendingOffer` | ❌ **The headline gap.** A property under offer keeps advertising as available on PP. |
| **Sold** | `Sold` — **stays on the portal** as sold stock | **`Inactive`** — **removed from the portal** | ⏸️ **Unresolved — DEFERRED** | ⚠️ Asymmetric (P24 shows the sale, PP erases the listing), but the live probe **could not settle** whether PP keeps a `Sold` listing on the portal (§7.2). **Decision: `sold` stays `Inactive` — no change, no stranding risk (§6).** |
| **Withdrawn / expired / cancelled** | `Withdrawn` / `Expired` / `Cancelled` (removed) | `Inactive` (removed) | `Inactive` | ✅ Already equivalent. |
| **Rented / let out** | `Rented` — stays on portal | `Inactive` — removed | *(PP has **no** `Rented` value)* | ⚠️ **Not a bug.** PP's contract has no let-out display state; `Inactive` is the honest mapping. Say so, don't invent one. |

---

## 4. Two further defects the ticket does not mention

**(a) `status_label` is invisible to PP — so the headline gap survives even a naive fix.**

CoreX models listing status in **two tiers** (mirroring P24/Propcon): a **base status**, plus an
optional **sub-label** on an on-market base — "For Sale" + *"Under Offer"* / *"Pending"* /
*"Reduced Price"*. P24's `getP24Status()` resolves the **sub-label first**, explicitly because
*"the sub-label IS the authoritative P24 lifecycle signal when present"*.

**The PP mapper reads only `$property->status`. It never reads `status_label`** (zero references
in the whole `app/Services/PrivateProperty/` tree). So under-offer — which normally lives in the
sub-label — is structurally invisible to PP. Adding a `PendingOffer` branch keyed on the base
status alone would **still miss the common case.** The PP mapper must consume the same two-tier
resolution P24 does; ideally the *same* helper, so the two portals cannot drift again.

**(b) `reactivateListing()` hardcodes `PropertyStatus => 'ForSale'`.**

`PrivatePropertySoapClient::reactivateListing()` sends `'ForSale'` regardless of listing type. For
a **rental**, that pushes a rental listing back onto PP **as a property for sale.** One line, real
bug, independent of everything else.

---

## 5. The finding that actually decides the timeline: **PP has no status delivery path**

`PropertyObserver` contains **zero** references to Private Property. The auto-sync-on-status-change
block is **P24-only**. PP is reached today by exactly two routes:

1. `DesyndicatePropertyFromPortalsJob` — the **off-market delist** path (this *does* call PP's
   `deactivateListing()`), and
2. **manual agent action** — "Refresh to portal" / the syndication controller.

**Consequence when Wave 2 goes live:** a deal flips a property to under-offer, and —

- **P24 updates within seconds.**
- **PP receives nothing at all.** No job is dispatched. The listing keeps advertising "For Sale"
  until an agent happens to refresh it manually — and even then, the mapper pushes `ForSale`.

So AT-68 is **not merely a mapper gap. There is no wire.** Fixing the mapping alone changes
nothing in production, because nothing calls it on a status change.

---

## 6. DECISION (Johan, 2026-07-11) — ship the under-offer half with Wave 2

**Adopted, after the live probe in §7:**

- **Ship the under-offer half (`PendingOffer`) with Wave 2.** It is the headline gap, it is the
  half Wave 2 actually exposes, and it carries **none** of the terminal-vs-removed ambiguity — a
  `PendingOffer` listing stays on the portal by definition, so there is no stranding trap.
- **`sold` stays `Inactive`.** No behaviour change on sold. No stranding risk.
- **The `Sold` question settles in sandbox** when PP restores it (§7.3).

---

## 7. The live probe — what it actually proved (and what it did not)

Property **#1972** (Ramsgate, sold, already `Inactive` on PP). Push `Sold` → read back → roll
back. Baseline restored and verified.

```
BASELINE   GetListingStatus: Inactive    in GetActiveListings: no
PUSH Sold  ->  response: "Successful"
READ BACK  GetListingStatus: Inactive    in GetActiveListings: no
ROLLBACK   ->  Inactive  (baseline restored, verified)
```

### 7.1 THE HARD FINDING — PP reports `"Successful"` and does nothing

**PP accepted the `Sold` push, returned `ListingStatusUpdateResult: "Successful"`, and the
listing's status did not change.**

This is **the AT-221 bug class, now confirmed on Private Property**. On P24 we learned that an
`HTTP 200` with `isOnPortal: false` meant *rejected*. Here a SOAP result of `"Successful"` means
**"I received your call"** — not *"I did what you asked."*

**Consequence: WS3 (read-back verification) is MANDATORY, not optional.** Any PP status work that
trusts the response string will silently do nothing and we will never know. `GetListingStatus`
returns the raw enum directly (verified: a live listing read back as `"ToLet"`), so the read-back
is cheap and there is no excuse for skipping it.

**This finding alone justified the probe** — it is worth more than the answer we went looking for.

### 7.2 The Sold question is NOT settled — and the confound was self-inflicted

The probe used a listing that was **already `Inactive`**. So two readings survive, and this test
cannot separate them:

1. **PP collapses `Sold` into `Inactive`** — Sold removes the listing, and today's mapping is
   already behaviourally correct; or
2. **PP refuses `Sold` from an `Inactive` source state** — you cannot sell a listing that has
   already been delisted, so the transition was invalid and nothing happened.

Choosing an already-off-portal listing as the "lowest-risk candidate" is exactly what made the
test inconclusive. **Reading this as "Sold removes the listing" would be motivated reasoning.**
It is recorded as unresolved.

### 7.3 What it DID establish — an ordering rule that constrains any future fix

**A `Sold` push cannot act on an already-deactivated listing.**

So if CoreX ever wants Sold-on-portal semantics, it must send `Sold` **instead of** `Inactive` at
the moment of sale — **never after** deactivating. The current code deactivates first, so that
path is closed to it by construction. Any future WS on Sold must change the *order*, not just the
*value*.

**To settle it properly**, the transition must start from an **active** (`ForSale`) listing:

- **Sandbox — the right venue. Zero risk.** Blocked: `services.sandbox.pp.co.za` resolves
  (4.221.124.121) but **times out on port 443**, from a host where the PP **prod** WSDL answers
  `HTTP 200` in 1.2s. So it is PP's side, not our egress. **Chase PP for sandbox access — we now
  need it for WS3 regardless.**
- **A live active listing** — definitive, but if PP *does* remove it, a genuinely on-market
  listing briefly disappears from the portal. Bigger ask; needs its own authorisation.
- **Reviving #1972 first** (`ForSale` → `Sold` → `Inactive`) — **advised against**: it would
  briefly advertise a *sold* house as *available*, which is a worse public misrepresentation than
  the listing simply being absent.

---

## 8. What the fix needs — work-streams (post-decision)

| WS | Work | In scope for Wave 2? | Size |
|---|---|---|---|
| **WS1 — Under-offer truth in the mapper** | Send **`PendingOffer`** when the property is under offer. Consume the **two-tier** status (base + `status_label`) via the **same** resolution P24 uses, so the portals cannot drift (§4a — under-offer normally lives in the sub-label and is currently invisible to PP). Fix the `reactivateListing()` `ForSale` hardcode (§4b — it pushes rentals back as *for sale*). Correct the wrong docblock (§2). **`sold` stays `Inactive` — unchanged.** | ✅ **YES** | **S** (~1 day) |
| **WS2 — A status delivery path to PP** | Dispatch a PP status push from the **same** status-change trigger that already feeds P24. The transport exists (`ListingStatusUpdate`). **This is the real work** — without it, WS1 changes nothing in production, because nothing calls the mapper on a status change (§5). | ✅ **YES** | **M** (~2–3 days) |
| **WS3 — Honest read-back** | Verify with `GetListingStatus` that PP **actually applied** the status; record an honest syndication status; never trust the `"Successful"` string. **Now proven mandatory by §7.1.** | ✅ **YES** | **S–M** (~1 day) |
| **WS4 — Sold on portal** | Decide `Sold` vs `Inactive`, and the ordering (§7.3). **Deferred** until PP sandbox is back. Carries the terminal-vs-removed stranding risk (§9). | ❌ **Deferred** | TBD |

**Total for Wave 2: M — roughly one week for one lane.** It ships **with** Wave 2, not after: the
gap only becomes visible the moment the two portals start publicly disagreeing about the same
property.

---

## 9. The trap WS4 must not walk into (deferred, but recorded)

Switching `Sold` from `Inactive` → `Sold` re-opens a bug class we have already been bitten by.
`Sold` (if PP behaves like P24) is **terminal but still ON the portal**. Our own history — commit
`16945462`, `.ai/audits/p24-sold-not-delisted-2026-07-10.md`, property #2142 — is exactly this:

> a Sold push wrote `p24_syndication_status='deactivated'`, and every delist path downstream then
> **skipped the property as "already off the portal"** — while P24's own is-on-portal check still
> answered **true**. The listing was stranded live.

If PP ever starts sending `Sold`, **PP's syndication status must not be marked `deactivated`**, or
we reproduce the identical stranding bug on the second portal. *Terminal is not the same as
removed.* That separation already exists on the P24 side
(`Property::P24_ON_PORTAL_TERMINAL_STATUSES`, `Property24ListingMapper::isTerminalStatus()`) and
the PP side would need it too.

**Reassuring, for now:** a read-only sweep found **zero** sold-but-still-active-on-PP properties,
so the stranding class is not biting PP today — and with `sold → Inactive` unchanged, it cannot
start.

---

## 10. Answer to the question Johan asked

*"Do we have status truth on both portals?"*

**Not yet — and PP's API was never the reason.** PP has supported under-offer and sold all along;
we have never sent them, and today **there is no wire at all** — a status change fans out to P24
only, so when Wave 2 goes live PP will keep advertising an under-offer property as plainly for
sale until an agent manually refreshes it.

**The under-offer half ships with Wave 2** (about a week: mapper + delivery path + read-back).
**`sold` stays `Inactive`** — no behaviour change, no stranding risk. **`Sold` settles in sandbox**
once PP restores it.

And one thing we did not know this morning: **PP will tell you `"Successful"` while doing nothing.**
Every status push must be verified by reading it back.
