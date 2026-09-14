# Dead-link sweep, and a proposal for one shared "entice business" screen

**Date:** 2026-09-14. **Requested by:** Johan, via the conductor — "all links that go
dead should have a let's-do-business enticing screen." **Status:** investigation and
proposal only, per explicit instruction. No code written. Wording is drafted, not
final — Johan writes the copy.

## Part 1 — what already exists (more than expected)

CoreX already converged on a shared pattern for this once before, 2026-08-24/25.
`App\Services\PublicLinks\PublicLinkUnavailableResponder` renders
`resources/views/public/shared/_dead-end.blade.php` — agency branding, an agent card
(phone/email/WhatsApp), the agent's newest listings, the agency's newest listings,
an optional CTA. This is genuinely the "enticing" page Johan is remembering, and it
already has real thought behind not leaking (documented in its own class docblock —
never passes the dead record's own identity, only agency/agent).

**Confirmed callers already using it** (grepped, not assumed):
`SellerLinkController`, `PublicAgencyPropertiesController`, `AgentPreviewController`,
`Compliance\FicaPublicController`, `Compliance\SellerInfoPublicController`,
`Docuperfect\SalesDocumentController`, `BuyerPortalController`,
`SellerOutreach\PublicOptInController`, `SellerOutreach\PublicOptOutController` — nine
call sites, not the six named in the responder's own (now stale) docblock.

**Alongside it, a second, separate shared page**: `errors/404-guest.blade.php`
(`bootstrap/app.php`'s `HttpException` render callback, documented policy at
`.ai/audits/2026-08-24-public-link-resilience-audit.md`) — the deliberately
agency-**less** fallback for a token that never resolved to any record at all (so
there's nothing to brand with, and guessing would risk showing a stranger the wrong
agency). Already thoughtful, already warm rather than bare — but its current CTA is
**"Contact CoreX support"**, which is exactly backwards for a buyer or seller clicking
a dead property link. This is the piece Johan named directly: *"failing that CoreX's
own generic 'find your next property' version"* — today it doesn't do that at all.

So the real gap isn't "nothing was ever built" — it's **(a) several places that never
adopted either shared page and still show something bare or bespoke, and (b) the
generic no-context fallback pointing at the wrong business** (CoreX-the-vendor
instead of property).

## Part 2 — the sweep, every place found

| # | Where | Trigger | What it shows TODAY | Status |
|---|---|---|---|---|
| 1 | Seller live link (`property/live/{token}`) | revoked / property gone / sold | `_dead-end.blade.php`, agency+agent branded | **Already good** |
| 2 | Public agency properties browsing | bad agency slug/token | `_dead-end.blade.php` | **Already good** |
| 3 | Agent public profile preview | bad token | `_dead-end.blade.php` | **Already good** |
| 4 | FICA public submission link | expired token | `_dead-end.blade.php` | **Already good** |
| 5 | Seller info share link (`SellerInfoShareLink`) | `expires_at` passed | `_dead-end.blade.php` | **Already good** — but see Part 3, the *trigger* itself has a gap |
| 6 | Sales document (e-sign adjacent) link | expired/revoked | `_dead-end.blade.php` | **Already good** |
| 7 | Buyer portal (`buyer_portal_links`) | `revoked_at` set | `_dead-end.blade.php` | **Already good** |
| 8 | Seller-outreach opt-in link | expired/consumed | `_dead-end.blade.php` | **Already good** |
| 9 | Seller-outreach opt-out link | expired/consumed | `_dead-end.blade.php` | **Already good** |
| 10 | **Rental application public link** (`rental-applications/public/unavailable.blade.php`) | token expired | Bare card, no branding, no CTA: *"This link has expired. Please contact your agent for a new link."* | **Needs the new screen** — this is the exact wording Johan quoted |
| 11 | **E-sign recipient signing link** (`docuperfect/signatures/external/unavailable.blade.php`) | cancelled / declined / expired / authority changed | Agency-branded (logo, no-leak reasoning already done well) but **no marketing** — no listings, no "browse stock" CTA | **Upgrade, don't rebuild** — closest of the bespoke ones to the target |
| 12 | **Presentation share link** (`presentations/public/unavailable.blade.php`) | not_found / revoked / expired | Bare card, agent contact only if resolvable, **no agency branding, no CTA, no listings** | **Needs the new screen** |
| 13 | Presentation share link, "stale but refreshable" case (`expired-with-refresh.blade.php`) | link works but content is stale | Has its own self-service "request a refresh" flow — different purpose from a truly dead link | **Out of scope** — not a dead link, leave as-is |
| 14 | **DR2 secure document link** (`SecureDocumentController`) | resolve() fails (unknown OR revoked, deliberately indistinguishable) | Bare `abort(410)` → falls through to `errors.404-guest` (generic CoreX page, no property marketing) | **Needs the generic screen's CTA fixed** (see #16) — the view file `deals-v2/secure-doc/unavailable.blade.php` is **orphaned**, not actually rendered any more |
| 15 | **Seller-outreach landing** (`/m/{shortcode}`, `PublicLandingController`) | bad shortcode / not found | Bare `abort(404)` → generic `errors.404-guest` | Same as #16 — inherits whatever the generic page becomes |
| 16 | **The generic no-context fallback itself** (`errors/404-guest.blade.php`) | ANY genuinely-unresolvable token, app-wide (the shared final fallback for #14, #15, and any future one) | CoreX-branded (not agency-branded, correctly, since no record resolved), but CTA is **"Contact CoreX support"** | **This is Johan's third case** — needs a property-marketing CTA instead of a software-support one |
| 17 | Two **orphaned, unused view files** found while tracing: `seller-link/revoked.blade.php` and `deals-v2/secure-doc/unavailable.blade.php` — zero references in the codebase, superseded by the shared responder/generic-404 but never deleted | — | — | **Housekeeping**, not part of this build — flagging so nobody edits a dead file by mistake |

**Not property-consumer-facing, deliberately excluded from this sweep:**
`onboarding/portal/expired.blade.php`, `agency-setup/expired.blade.php` — these are
B2B/staff onboarding links (a new agency/branch setting up CoreX itself), not a
buyer/seller/tenant's link to a property. Named so the exclusion is a decision on
record, not an oversight.

## Part 3 — SellerInfoShareLink, traced as asked

**Confirmed: yes, same class of gap — but a different shape of fix than
`PropertySellerLink`'s.**

`SellerInfoPublicController::show()` checks exactly one thing: `$link->isExpired()`
— purely `expires_at`, set at creation to `now()->addDays(90)`
(`SellerInfoController.php:136`). There is **no `revoked_at` column on this table at
all** and no check anywhere against whether the seller is still linked to the
property. A seller removed from a property retains a working info-pack link for up
to 90 days afterward — same failure mode as `PropertySellerLink`, longer window.

The fix shape differs because the mechanism differs: `PropertySellerLink` already had
a `revoked_at` column nobody was setting; `SellerInfoShareLink` has no revocation
concept whatsoever. Two honest options, not choosing between them here:
(a) add `revoked_at`/`revoked_by_user_id` to this table too and set it from the same
`ContactPropertyLinker::unlink()` hook, mirroring the seller-link fix exactly; or
(b) have `show()` additionally check current seller-liveness (a live
`contact_property` row for that `(property_id, contact_id)` pair) alongside
`isExpired()`, no schema change needed. (a) is more consistent with tonight's "keep
the record, mark it dead" principle and with how `PropertySellerLink` is being fixed;
(b) is smaller. Not deciding here — flagging for the same conversation as the main fix.

## Part 4 — the proposal: one shared component, not a patch per screen

**Extend `PublicLinkUnavailableResponder`/`_dead-end.blade.php` rather than build a
new one.** It already does almost everything Johan described — branding, agent card,
listings, CTA, no-leak discipline — and nine places already call it correctly. Building
a second "shared" screen would recreate exactly the "five copies, four never updated"
problem he's trying to avoid. The actual work is:

1. **Migrate #10, #11, #12 onto the existing responder** (rental applications, e-sign,
   presentations) instead of their bespoke bare/branded-but-plain views.
2. **Fix the generic fallback's CTA** (#16, #14, #15) — swap "Contact CoreX support"
   for something property-marketing-shaped. This one has no agency to brand with by
   design (that's deliberate and correct — don't guess a stranger's agency), so it
   needs its own distinct, CoreX-neutral version of "find your next property," not
   agency branding bolted on where none can be safely resolved.
3. **Flatten the wording**, per Johan's brief. Several existing screens differentiate
   the visible copy by reason today — e-sign says "declined" vs "cancelled" vs
   "expired" vs "authority changed"; presentations say "revoked" vs "expired." Johan's
   instruction is one flat line regardless of cause: *"This link is no longer
   active."* Worth naming plainly: the reason-specific wording on some of these
   screens is itself a small leak today (a stranger learns "this was **declined**,"
   which is more than "gone") — flattening it is a genuine tightening, not just a
   style choice.
4. **Delete or leave the two orphaned files** (#17) — trivial, mention only so nobody
   is confused finding them mid-build.

### The three information states — how the existing responder already covers them

- **Agency + agent known** (the token resolved a real, now-dead record): full
  treatment — agency logo/branding, agent card with phone/email/WhatsApp, agent's own
  newest listings, agency's newest listings, CTA. *(Already built — this is exactly
  `_dead-end.blade.php`'s main path today.)*
- **Only the property/agency known, no specific agent** (or the agent's no longer
  active): falls back to the agency's own contact details, agency listings only.
  *(Already built — the responder's own `$showAgent` fallback logic.)*
- **Nothing resolvable at all** (genuinely unknown token): the separate
  `errors/404-guest.blade.php` page — CoreX-branded, not agency-branded (can't guess),
  needs its CTA changed from software support to property discovery. *(Exists,
  needs the one copy change from #16.)*

### No-leak checklist for whoever builds this

Confirmed already respected by the existing responder's docblock, restating because
Johan flagged it as the place to be careful: never render the dead record's own
identity (which property, which document, which applicant, which contact), never a
reason specific enough to tell a stranger *what happened* rather than just *that it's
gone*. The four-way `$showAgent` check (`is_active && !deleted_at`) already guards
against showing a departed agent's name — keep that exactly as-is when migrating the
three screens above onto it.

## Draft wording — Johan's to finalise, not to ship

Offered as a starting point only, deliberately plain and generic per his brief:

- **Heading:** "This link is no longer active"
- **Body:** "But we'd love to help you find what you're after." *(placeholder —
  he'll want his own voice here)*
- **When agency+agent known:** agent card, agent's/agency's newest listings, "View
  current listings" CTA, "Visit our site" CTA.
- **When only agency known:** same, agency-only listings, agency contact details
  instead of a named agent.
- **When nothing known (generic CoreX page):** no listings (nothing to show), a
  "Find your next property" CTA pointing at... *(needs Johan's call — a CoreX-wide
  search surface, if one exists for the public, or nothing more specific than
  encouraging them to contact whoever sent the link)*. This is the one branch where
  I don't have a concrete destination to propose — flagging rather than guessing.

## What's still needed before build

- Johan's final copy for the three states above.
- His call on the generic (no-agency) branch's CTA destination.
- His call on whether e-sign's `authority_changed` reason (a revoked representative)
  still needs its own distinct wording — that one arguably isn't a "dead link," it's
  telling a specific person their own authority changed, which is closer to an
  account-status message than a marketing moment. Flagging as a possible exception
  to "one flat message," not deciding it.
