# Buyer Portal — "Your Property Matches" (public, token-gated)

> AT-204 · lane cc2 · redesign of the public buyer-matches page.
> **Status: CLOSED — SUPERSEDED / RESOLVED-BY-ANDRE (Johan's ruling 2026-07-07).**
> Pillars: **Contact** (buyer) · **Property** (matches) · **Agent** (who to call).

---

## 0. CLOSE-OUT (2026-07-07) — resolution & what happened

**Johan's ruling:** *"Andre sorted out the seller and buyer links. So that can be
marked as done."* Andre's resolution is THE resolution.

**What actually happened (factual, no judgment):** Andre took this cc2 branch
(`AT-204-buyer-portal-redesign`), **merged it into QA2** (`13f8440d`), then applied
a restyle/integration **`fix` (`8ec6a591`)** that reworked the buyer-portal cards +
`show` + both shared partials (`public/shared/_agent-card`, `_company-footer`) AND
the seller pages (`seller-link/live.blade.php`, `shared/match.blade.php`,
`corex/properties/live-preview.blade.php`) into his design language, then merged
QA2 → Staging → **main** (`8b3a523e`). Johan added a test/regression cleanup
(`3c9b50d5`). So this redesign **shipped to live, restyled by Andre** — `fe496ca0`
(the cc2 feat commit) is an ancestor of `origin/main`. The cc2 QA1 version does
**not** proceed to Staging/live as-is; Andre's restyled version is canonical.

**respond() truth:** `origin/main`'s `BuyerPortalController::respond()` is the cc2
version (Andre's `8ec6a591` did **not** touch the controller) — `agency_id` stamped
once from `link.agency_id`, `updateOrInsert` once, activity-log once; **no
double-application**. Byte-identical to cc3 AT-203's live fix (`0793284d`), which
already deployed the stamp to live and **live-verified respond OK**. The live
respond path works with Andre's code.

**Doctrine check (neutral):** Andre's shipped `_agent-card.blade.php` KEPT the
elements Johan asked for — agent photo, tap-to-call, WhatsApp (`wa.me`), email,
**PPRA/FFC** line — plus the company footer. No doctrine conflict to flag.

**Branch disposition:** `AT-204-buyer-portal-redesign` is **PARKED** — pushed,
documented here, **not merged by cc2, never deleted**. Still valuable if a redesign
round returns: (a) the shared `public/shared/*` component contract (§4); (b) the
**match-% honesty** design + `ContactMatch::presentBrief/matchBasisText/matchBasisLabels`
(these methods are on `main`, in use); (c) the **11-test** suite
`tests/Feature/BuyerPortal/BuyerPortalRedesignTest.php`; (d) the mobile-first
public-page pattern + headless 390px/desktop proof harness.

**QA1:** re-pointed to the **live lineage** (Andre's shipped versions of the 4
restyled buyer-portal view files, `035bd8d3`) so the bench matches live and nobody
QAs the superseded cc2 version. Controller / `ContactMatch` / `revoked.blade` were
already identical. **AT-203 data fixes on QA1 are untouched.** Note: the SELLER
pages on QA1 (`seller-link/live`, `shared/match`) were also restyled by Andre on
main and were NOT re-aligned here (out of cc2's lane — cc1/Andre's call).

---

## 1. What & why

A buyer receives a WhatsApp link to `/buyer/portal/{token}` — their **personal
property feed**. The pre-redesign page was desktop-only, dark, text-only cards
(no photos), showed only "Budget" from a full wishlist, and rendered twenty
context-free **100%** badges (budget-only buyer → mathematically right, reads
fake). No agent, no company, no branding.

The redesign makes it the buyer's honest, branded, mobile-first feed:

1. **Branded header** — agency logo (agency-driven, `Agency::publicBrandingFor`).
2. **Greeting + honest preferences brief** — every criterion the buyer actually
   gave (`ContactMatch::presentBrief()`), not just budget.
3. **Match cards WITH PHOTOS** — primary thumbnail via `Property::thumbFor()`
   (the AT-190 engine), title, suburb, price, beds/baths/garages, and the three
   actions preserved exactly: **Interested / Not Interested / Request Viewing**.
4. **Match-% HONESTY (display-layer only — engine untouched)** — see §3.
5. **Grouping** — Your best matches (perfect) → Strong → collapsed "more to
   consider" (approximate), strongest-first; **actioned states shown**.
6. **Agent card + company footer** — the buyer always knows who to call.

Public-page rules honoured: token-gated, revoked → 410, only this buyer's data,
never 500s on missing data (zero-matches state designed; missing property
skipped; purged contact → 410), thumbnails only, cheap queries.

---

## 2. Data & flow (as-built)

- **Route** (`routes/web.php`): `GET /buyer/portal/{token}` → `BuyerPortalController@show`
  (name `buyer-portal.show`); `POST /buyer/portal/{token}/respond` → `@respond`.
- **Token**: raw `buyer_portal_links` row (`token`, `revoked_at`, `contact_id`,
  `agency_id`, `generated_by_user_id`). No `expires_at` — revocation is the gate.
- **Matches**: `PropertyMatchScoringService::getMatchesForBuyer()` → cached
  `property_buyer_matches` rows (`score`, `tier`), score-desc. Properties loaded
  keyed by id (`withoutGlobalScopes`, whereIn).
- **Preferences**: the contact's primary `ContactMatch` (wishlist).
- **Agent**: `link.generated_by_user_id` → `User`, fallback `contact.agent_id`.
- **Responses**: `buyer_property_responses` (enum interested / not_interested /
  viewing_requested).

### 2a. LIVE BUG FIXED (critical)

`buyer_property_responses.agency_id` is **NOT NULL, no default** (multi-tenancy
migration `2026_05_23_030600`). `respond()` did a raw `DB::table()->insert()`
that **omitted agency_id** → BelongsToAgency does not auto-stamp raw inserts →
**every buyer response 500'd on live** (`1364 Field 'agency_id' doesn't have a
default value`). The three actions are the buyer-loop's heartbeat. The AT-202
hotfix stamped the *link-generation* insert but missed this *response* insert.

Fix: stamp `agency_id` from `link.agency_id` (contact fallback); switch to
`updateOrInsert` keyed on `(contact_id, property_id)` so a change-of-mind updates
the row instead of stacking duplicates (idempotent). Locked by
`tests/Feature/BuyerPortal/BuyerPortalRedesignTest.php`. (Bug-class register:
[[at203-agency-id-notnull-landmine-register]] — this closes the
`buyer_property_responses` writer.)

---

## 3. Match-% honesty (Buyer Pillar doctrine — display only)

**Root**: `MatchingService::score()` scores ONLY the criteria a buyer specified
(denominator = specified criteria). Budget-only wishlist + in-budget property =
100 — correct, but a naked "100%" reads fake. **The matching engine is NOT
touched.** Two display helpers on `ContactMatch` carry the honesty:

- `presentBrief()` → full ordered brief (Budget, Areas, Bedrooms, …) for the
  preferences summary — the buyer sees everything they told us.
- `matchBasisLabels()` / `matchBasisText()` → the plain-English basis the % is
  computed from, e.g. `"your budget"`, `"your budget & bedrooms"`,
  `"your budget, area & 2 more"`.

**Presentation choice (for Johan):**
- The card chip never shows a bare number: **plain-English quality word**
  (`Excellent match` ≥90 / `Strong match` ≥80 / `Possible match`) + the % as
  muted secondary + a per-card line **"Matched on {basisText}"**.
- When the buyer has given **≤2 criteria**, a one-line nudge under the brief:
  *"These matches are scored on your budget — the preference you've shared so
  far. Tell {agent} more (area, bedrooms, must-haves) and your matches get
  sharper."* — honest AND drives the buyer↔agent loop (CoreX principle) instead
  of implying a perfect fit. **Open question for Johan:** keep the numeric % on
  the chip at all for single-criterion buyers, or drop it entirely in favour of
  the word + basis? Current build keeps it (muted) — easy to drop if you prefer.

---

## 4. Shared public-page component contract (cc1 ⇄ cc2)

The seller live page (cc1) and this buyer page both need the same three
agency-driven pieces. Proposed contract, built here, **cc1 to converge** (build
nothing duplicate — integrate at the merge gate):

| Component | Partial | Expects |
|-----------|---------|---------|
| Agent card | `resources/views/public/shared/_agent-card.blade.php` | `$agent` (User\|null), `$agency` (Agency\|null), `$heading?` |
| Company footer | `resources/views/public/shared/_company-footer.blade.php` | `$agency` (Agency\|null) |
| Branded header | *(inline in each page for now — logo via `Agency::publicBrandingFor()['logoUrl']`)* | `$brand` array |

- Both partials rely on the host page's `:root` design tokens
  (`--brand-default/-button/-icon`, `--surface`, `--border`, `--text-*`) — each
  page seeds those from `Agency::publicBrandingFor()` colours, so the shared
  components inherit the agency's brand. All fields null-safe; the agent card
  self-hides when `$agent` is null; wa.me digits normalised `0..`→`27..`.
- If cc1 has already declared a different contract on its ticket, converge to
  cc1's (cc1 owns the shared foundation); the swap is an include-path change.

---

## 5. Files

- `app/Http/Controllers/BuyerPortalController.php` — show() enriched (agent,
  brand, null-safe contact); respond() agency_id stamp + idempotent.
- `app/Models/ContactMatch.php` — `presentBrief()`, `matchBasisLabels()`,
  `matchBasisText()` + private range/feature helpers (display-layer honesty).
- `resources/views/buyer-portal/show.blade.php` — full redesign (mobile-first).
- `resources/views/buyer-portal/_property-card.blade.php` — photo + honest chip
  + 3 actions + actioned states.
- `resources/views/buyer-portal/revoked.blade.php` — branded 410.
- `resources/views/public/shared/_agent-card.blade.php` — NEW shared.
- `resources/views/public/shared/_company-footer.blade.php` — NEW shared.
- `tests/Feature/BuyerPortal/BuyerPortalRedesignTest.php` — NEW (11 tests).

## 6. Acceptance criteria

- [x] Renders 200 with photos, agent card, footer, branding (rich buyer).
- [x] Honest basis shown ("Matched on your budget"); no context-free 100%.
- [x] Zero-matches state designed (not broken); agent card still shown.
- [x] Revoked → 410; missing property skipped; purged contact → 410.
- [x] All three actions work AND stamp agency_id (live 500 fixed); idempotent.
- [x] Only this buyer's matches shown.
- [ ] Mobile 390px + desktop headless proof (see §7 in ticket close).
- [ ] Johan phone QA on QA1.
