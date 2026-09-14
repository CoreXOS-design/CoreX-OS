# Dead-link screens — swept by SOURCE and REASON, per Johan's correction

**Supersedes the "one screen" framing in yesterday's version of this doc** (kept at
`2026-09-14-dead-link-screen-sweep-and-proposal.md` for the record of what was
originally proposed and why Johan corrected it). Johan's own words, verbatim, for
why: *"the screen we did was essentially custom to where you arrive from... its not
a one link fits all scenario, but similar working but based on where the link is
from and why did it die."*

**Investigation and proposal only — no code, per instruction.**

## Part 1 — the sweep, by SOURCE and REASON

| SOURCE | REASON(S) reachable | Where | What renders TODAY | Screen precedent that already exists for this shape |
|---|---|---|---|---|
| **Buyer** (match/wishlist share link) | wishlist closed/archived; genuinely unknown token | `SharedMatchController::show()` / `showExpired()` | **Already rich, already bespoke**: `shared/match-expired.blade.php` — agent card, agent's + agency's 2 newest listings, and a **self-service "Start a new list" re-engagement form** (`shared.match.reengage`) | This is the most-built-out variant in the app already — see Part 2, it's the model for what Johan's asking for, with one open tension |
| **Seller** (live marketing link) | revoked; property soft-deleted; property concluded (sold/let) | `SellerLinkController::show()` → `showUnavailable()` | `_dead-end.blade.php` via `PublicLinkUnavailableResponder` — names the seller's **current** agent (`$link->contact->agent`), agent+agency listings, CTA | Generic shared shell |
| **Seller** (info-pack share link) | expired only (90-day TTL; no revoke concept — see the earlier trace) | `SellerInfoPublicController::show()` | Same `_dead-end.blade.php` | Generic shared shell |
| **Tenant/applicant** (rental application) | token TTL expired; application still in `draft` (never sent) | `RentalApplicationSigningController::show()` | Bare, unbranded card — Johan's quoted wording exactly | **Narrower than it first looked**: a *declined/withdrawn* application does **not** land here at all — it falls through to the normal read-only "view your submission" screen with its own honest closed-message text. The only genuine dead-link case for this source is an expired token or a draft that was never sent. |
| **E-sign recipient** | cancelled by agency; declined by a party; expired/lapsed; a representative's authority changed | `Docuperfect\SigningController::renderUnavailable()` | Bespoke, agency-branded, no-leak-already-done, **no marketing** | Closest bespoke screen to "done right but missing the enticing half" |
| **Buyer** (secure document distribution, DR2) | resolve() deliberately conflates unknown + revoked (security choice, not a gap) | `DealV2\SecureDocumentController` | Bare `abort(410)` → generic CoreX 404 page | — |
| **Compliance (FICA)** | token expired | `Compliance\FicaPublicController` | `_dead-end.blade.php` | Generic shared shell |
| **Presentation recipient** (buyer or seller, either can receive a presentation) | not_found; revoked by agent; expired | `Presentation\PublicPresentationController::renderUnavailable()` | Bare card, agent contact only if resolvable, no branding, no listings | — |
| **Presentation recipient**, stale-not-dead case | content is stale but link still resolves | `presentations/public/expired-with-refresh.blade.php` | Has its own self-service refresh flow | **Out of scope** — not a dead link |
| **Buyer** (buyer portal / matches list) | revoked | `BuyerPortalController::portalUnavailable()` | `_dead-end.blade.php`, eyebrow "Your matches" | Generic shell, but no listings shown (see note below) |
| **Seller** (outreach opt-in link) | expired/consumed | `SellerOutreach\PublicOptInController` | `_dead-end.blade.php` | Generic shared shell |
| **Seller/lead** (outreach opt-out link) | expired/consumed | `SellerOutreach\PublicOptOutController` | `_dead-end.blade.php` | Generic shared shell |
| **Seller/lead** (outreach shortcode landing) | bad/unknown shortcode | `SellerOutreach\PublicLandingController` | Bare `abort(404)` → generic CoreX page | — |
| **Nobody resolvable at all** (genuinely unknown token, any source) | — | `bootstrap/app.php` HttpException callback | `errors/404-guest.blade.php` — CoreX-branded, not agency-branded (correct — no agency resolvable), CTAs to **"Contact CoreX support"** | This is the true no-information floor every source falls back to |

**Two orphaned files, unchanged from yesterday's note**: `seller-link/revoked.blade.php`,
`deals-v2/secure-doc/unavailable.blade.php` — zero references, dead.

## Part 2 — the buyer case: what do we actually know, honestly

You asked me to be honest rather than optimistic here, so: **we know a lot — but
Johan himself already ruled, in writing, that most of it must not be shown on this
exact screen, for a reason that's worth putting in front of him again given today's
new instruction.**

`SharedMatchController::showExpired()` — the buyer's dead-link page — has this
exact comment, dated 2026-08-24, marked non-negotiable:

> *"PRIVACY (non-negotiable): both queries below are plain public-stock lookups,
> agnostic of the closed wishlist — nothing here ever reads `$contact`'s criteria,
> price band, or matched properties."*

So today, the buyer's dead-link page shows the agent's/agency's **newest stock**
(limit 2 each), completely blind to what that specific buyer was actually looking
for — not because the data doesn't exist (it does: `ContactMatch`, the wishlist's
own criteria, the matched properties are all sitting right there and readable), but
because Johan himself forbade reading them on this page four weeks ago.

**This is now in direct tension with today's instruction** ("recent properties that
matches what they were looking at... that's the valuable bit"). I don't know his
reasoning from 2026-08-24 well enough to guess whether it still applies — but the
shape of the risk is exactly the disclosure principle you named tonight: this is a
**public, unauthenticated, forwardable link**. If it leaked or got forwarded, showing
"here's what YOU were searching for — 3-bed, budget R2m, Ramsgate" to whoever now
holds the link would disclose the *original* buyer's private search to a stranger,
not just a dead-property identity. That's a plausible read of why it was written the
way it was. It might also just be caution that's outlived its reason. **I'm not
resolving this — flagging it exactly as you asked, because the two instructions
genuinely pull against each other and only you/Johan can say which one wins.**

If the ruling changes, the mechanism to do it safely already half-exists: match
criteria are on the wishlist (`ContactMatch`), and a "properties like this" query
scoped to price band + suburb + type would be a normal property search, not a new
capability — the only decision is whether it's safe to key it off THIS specific
buyer's closed wishlist on a link that isn't provably still in that buyer's own hands.

**The buyer portal case (`BuyerPortalController`) is narrower still**: today it shows
*no* listings at all on its dead-link page (just the agent card + "reach out"
copy) — not even the generic newest-stock treatment `match-expired` gives. Worth
deciding whether that one should be brought up to the same standard.

## Part 3 — architecture: the existing convention, and what to build on it

The codebase has already arrived at almost exactly the shape Johan described,
independently, twice:

1. **`_dead-end.blade.php` + `PublicLinkUnavailableResponder`** — one shared *shell*
   (branding, agent card, generic listings, CTA slots), fed different *content* by
   each of its 9 callers. This is "shared plumbing, many faces" already, just not
   yet source-aware in its content choices — every caller currently gets the same
   flavour of "here's an agent, here's some listings."
2. **`shared/match-expired.blade.php`** — a genuinely different, richer bespoke
   screen for one specific source (buyer/wishlist), because that source's dead-link
   moment calls for something the generic shell doesn't do (a self-service
   re-engagement form, not just a static CTA).

That's the actual existing convention: **one shared shell for the common case, and a
small number of named, source-specific screens where the source's own dead-link
moment genuinely needs different content or actions** — not a single monolithic
component with a `switch` on eleven reasons inside it, and not five independently
copy-pasted Blade files either.

**Proposed direction, following that same convention forward:**

- Keep `_dead-end.blade.php` as the shared shell (branding header, contact block,
  listings grid, CTA row) — but make its *content* assembly source-aware, via a
  small set of named "content resolver" methods/classes (one per SOURCE:
  buyer, seller-live-link, seller-info-pack, tenant/applicant, e-sign, generic),
  each producing: headline, one line of body copy chosen from a **small, fixed,
  pre-approved set keyed by REASON** (never a raw internal status string rendered
  directly — that's how a reason leaks), and which contact/listings panel to show.
- Where a source's needs go beyond what the shared shell's slots can express
  (buyer's re-engagement form is the existing example), it gets its own named
  screen like `match-expired.blade.php` already does — reusing the shell's styling/
  branding partials, not duplicating them.
- REASON maps to *safe* wording variants only. Several reasons that exist internally
  today (declined / cancelled / authority_changed) may need to collapse into the
  same visitor-facing copy as "expired," per the disclosure rule — internal status
  stays internal, the visitor only ever learns "not active any more."

## Part 4 — draft copy per (source, reason) — Johan's to rewrite, not to ship

Deliberately plain, deliberately not final:

- **Buyer, any reason:** *"This link isn't active any more."* + agent card (if the
  buyer's current agent is resolvable) + [newest stock now, or matching stock —
  pending Part 2's ruling] + "Start a new search" CTA (existing re-engage flow) OR
  "View current listings."
- **Seller (live link or info-pack), any reason:** *"This link isn't active any
  more."* + **named current agent** ("You worked with {agent name}") + phone/email/
  WhatsApp + "Get in touch" CTA. No listings — a seller doesn't want to browse
  stock, they want their agent.
- **Tenant/applicant, expired token:** *"This link isn't active any more."* +
  rental-flavoured framing ("Looking for a place to rent?") + agent/agency contact
  + a CTA toward current rental listings, if one exists for this agency's public
  site.
- **Tenant/applicant, never sent (draft):** stays its own, different message —
  *this isn't dead, it's not ready yet* — genuinely different situation, not a
  "please contact us" moment. Keep as-is, not part of the marketing-screen work.
- **E-sign recipient, any reason (cancelled/declined/expired/authority_changed
  collapsed to one line per the disclosure rule):** *"This link isn't active any
  more."* + agency contact (already resolved correctly today) — **no listings, no
  "browse stock" CTA** — someone expecting to sign a specific document isn't a
  browsing prospect in the same moment; forcing a listings panel here risks reading
  as tone-deaf ("sorry your lease fell through, here's 2 more houses!"). Flagging
  this as a case where I'd argue *against* the marketing panel even once wording is
  approved — Johan's call.
- **Generic / nothing resolvable:** *"This link isn't active any more."* + no
  agency branding (can't guess) + CoreX-neutral "Find your next property" CTA,
  destination still needs Johan's call (named as open in the previous sweep too).

## Part 5 — the disclosure rule, restated against this harder version

Unchanged principle, now checked against each source:

- **Seller naming their own agent is fine** — they know who they worked with; this
  is the one case where "identify a specific person" is correct, not a leak.
- **Every other source must stay generic about *why*.** A revoked link and an
  expired link must look identical to a tenant, a buyer, or a stranger holding a
  forwarded e-sign link — only the seller screen is allowed to be specific, and
  only about the agent's identity, never about the property or the reason the link
  died.
- **The REASON collapse (Part 3) is where this gets enforced structurally** — if
  the content resolver only ever accepts a small pre-approved wording set per
  source, there's no code path left that could accidentally render an internal
  status string (`'declined'`, `'authority_changed'`, `'revoked'`) as visible copy.

## What's still needed before build (unchanged shape, now source-specific)

- Johan's copy for each of the five source variants above.
- His ruling on the buyer criteria-privacy tension (Part 2) — this is the one
  finding in this document that genuinely blocks writing final buyer copy.
- His call on whether e-sign should carry a listings/marketing panel at all (Part 4).
- His call on the generic branch's CTA destination (unchanged from yesterday).
- Confirmation on the buyer portal being brought up to `match-expired`'s standard,
  or left at its current, plainer treatment.
