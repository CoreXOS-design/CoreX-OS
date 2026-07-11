# AT-68 — Private Property status parity: investigation

> **Status:** investigation only. No code written.
> **Question:** m3's Wave 2 flows under-offer / sold to P24 immediately. What can Private
> Property's SOAP API actually do about status, what would our mapper need, and what does the
> fix cost?
> **Short answer:** **PP supports everything we need — the API is not the constraint.** The gap
> is ours, it is bigger than the ticket says, and **PP has no status delivery path at all**, so
> the moment Wave 2 goes live the two portals will disagree in public.
> **Author:** m6, 2026-07-11. Sources: `storage/pp-agentimport.wsdl` (the live contract),
> the PP and P24 mappers, `PropertyObserver`, `DesyndicatePropertyFromPortalsJob`.

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
| **Sold** | `Sold` — **stays on the portal** as sold stock | **`Inactive`** — **removed from the portal** | `Sold` | ❌ Asymmetric: P24 shows the sale, PP erases the listing. |
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

## 6. What the fix needs — three work-streams

| WS | Work | Size |
|---|---|---|
| **WS1 — Status truth in the mapper** | Add `PendingOffer` and `Sold` to `mapPropertyStatus()`; consume the **two-tier** status (base + `status_label`) via the **same** resolution P24 uses, so the portals cannot drift; fix the `reactivateListing()` `ForSale` hardcode; correct the wrong docblock. Pure mapping + tests. | **S** (~1 day) |
| **WS2 — A status delivery path to PP** | Dispatch a PP status push from the **same** status-change trigger that already feeds P24. The transport exists (`ListingStatusUpdate`) — this is an idempotent job plus the syndication-status bookkeeping. **This is the real work.** | **M** (~2–3 days) |
| **WS3 — Honest read-back** | Verify with `GetListingStatusVerbose` that PP *actually* applied the status, and record an honest syndication status. Exactly the AT-221 pattern (a portal can return success and still reject the content). | **S–M** (~1 day) |

**Total: M — roughly one week for one lane.**

**It should ship *with* Wave 2, not after it.** The gap only becomes visible — and only becomes
embarrassing — the moment Wave 2 starts flowing status to P24, because that is when the two
portals begin publicly disagreeing about the same property.

---

## 7. The one thing that must be designed, not patched

**Changing `Sold` from `Inactive` → `Sold` re-opens a bug class we have already been bitten by.**

`Sold` (if PP behaves like P24) is **terminal but still ON the portal** — the listing stays up,
shown as sold stock. Our own P24 history (commit `16945462`,
`.ai/audits/p24-sold-not-delisted-2026-07-10.md`, property #2142) is exactly this:

> a Sold push wrote `p24_syndication_status='deactivated'`, and every delist path downstream then
> **skipped the property as "already off the portal"** — while P24's own is-on-portal check still
> answered **true**. The listing was stranded live.

If PP starts sending `Sold`, then **PP's syndication status must not be marked `deactivated`**, or
we reproduce the identical stranding bug on the second portal. *Terminal ≠ removed* — that
distinction already exists in `Property::P24_ON_PORTAL_TERMINAL_STATUSES` and
`Property24ListingMapper::isTerminalStatus()`, and the PP side needs the same separation.

**Open question — do not guess:** we know PP *accepts* `Sold`; we do **not** know whether PP
*keeps a Sold listing on the portal* (like P24) or removes it. That is empirically answerable in
sandbox via `GetListingStatusVerbose` in about an hour, and the answer decides whether Sold →
`Sold` or Sold → `Inactive` is the correct mapping. **Settle it before building WS1, not after.**

---

## 8. Answer to the question Johan will ask

*"Do we have status truth on both portals?"*

**Not yet — and not by accident of PP's API. PP has supported under-offer and sold all along; we
have never sent them, and today we have no way to send them on a status change at all. It is
about a week of work, it should land with Wave 2, and one design decision (does a Sold listing
stay on PP's portal?) needs a one-hour sandbox check before we build.**
