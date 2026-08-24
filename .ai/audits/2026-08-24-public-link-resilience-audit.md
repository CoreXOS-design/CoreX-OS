# Public link resilience audit — 2026-08-24

**Read-only.** No code changed, no data touched. Every route below was read directly from
`routes/web.php` and its controller; every scale number is a live, read-only `COUNT()`
query. Johan's ask: every publicly reachable, unauthenticated route a client/seller/buyer/
member of the public could be handed, what happens when the thing it points at is gone,
and a single consistent policy so nobody ever lands on a bare dead end.

**Trigger**: cc3 found `/shared/match/{token}` hard-404s the instant the last wishlist is
archived — a client hits a wall, nobody inside the agency ever sees it. That fix is already
in flight and excluded from the fix list below, but included in the inventory since the
policy has to cover it.

---

## PART 1 — The inventory

20 distinct public route groups found. Table below; full detail (file:line citations) is
in the per-cluster notes further down.

| Route | Identifies | Renders | Sender | Fails when | Today | Distinguishable? |
|---|---|---|---|---|---|---|
| `/property/live/{token}` | unguessable token | seller live report | agent → seller | link revoked | **410, courteous view** | ❌ same view, revoked = never-existed |
| same | same | same | same | property soft-deleted, link not revoked | **500 crash** (`TypeError`) | N/A — crash |
| same | same | same | same | property sold, link not revoked | **renders normally, still says "live"** | N/A — silently stale |
| `/{agencySlug}/properties/{property}` | slug + raw sequential ID | listing detail | agent, marketing | wrong agency / not marketable / bad slug | **plain 404, all three** | ❌ identical |
| `/{agencySlug}/properties` | slug | listing grid | agent, marketing | bad slug | plain 404 | N/A (list) |
| `/corex/properties/{id}/preview` | raw sequential ID | property preview | agent / Core Match | deleted / not marketable | **plain 404, both** | ❌ identical |
| `/buyer/portal/{token}` (GET) | token | buyer's matches + agent card | agent → buyer | revoked / contact purged | **generic 410, both identical** | ❌ identical |
| `/buyer/portal/{token}/respond` (POST) | token | JSON | client | revoked / missing | **bare Laravel 403** — inconsistent with the GET's friendly page | N/A, bare error |
| `/p/{token}` | token | live CMA/presentation | agent → seller | not found / soft-deleted | **404, generic, no agent shown** | ✅ |
| same | same | same | same | **revoked** | **404, specific copy + agent contact block** | ✅ — the reference implementation |
| same | same | same | same | superseded by refresh | **auto-redirects to newer link, no dead end at all** | — |
| same | same | same | same | expired | **410, dedicated "request a refresh" page** | ✅ |
| `/shared/match/{token}` | slug/legacy token | wishlist matches | agent → buyer | archived, live sibling exists | **silent redirect to sibling** | — |
| same | same | same | same | archived, no sibling | **404, privacy-audited "expired" view** | ✅ |
| same | same | same | same | genuinely unknown token | **plain 404** | ✅ |
| same — `recordView`/`feedback` AJAX | same | JSON | client, in-page click | wishlist archived after page load | **raw `ModelNotFoundException` 404** — page-load fix doesn't cover in-page actions | ❌ |
| `/corex/agents/{nameSlug}/{tag}` (business card) | 10-char slug | agent profile, contact, listings, articles | printed/QR/WhatsApp | agent deactivated, no reroute set | **hard 404** — reproduces Johan's exact complaint | not surfaced differently than never-existed |
| same | same | same | same | agent deactivated, reroute set | **redirects to successor, works** | — |
| same | same | same | same | agent hard-deleted | **reroute mandatory at delete — works** | — |
| `/r/a/{slug}` (legacy QR) | same slug | 301 to business card | printed cards in the wild | same as above | same as above | same |
| `/outreach/agent-card/{user}.jpg` | raw numeric ID | og:image | link-preview crawler | deactivated | **still 200s** (no is_active check) | — |
| same | same | same | same | deleted | clean 404 (defensive) | — |
| `sign/{token}` | token | signing ceremony | agent → signer | unknown token | **404** | ✅ correct |
| same | same | same | same | completed | sensible summary view | ✅ deliberate, done right |
| same | same | same | same | expired / declined | sensible view | ✅ deliberate, done right |
| same | same | same | same | **template cancelled/rejected while request still `pending`** | **falls through to the normal signing flow, no notice at all** | ❌ real gap |
| `/documents/download/{token}` | token | post-completion download | system, post-signing | unknown token | 404 | ✅ |
| same | same | same | same | not yet complete | sensible message | ✅ |
| `/sales-documents/return/{token}` | token | upload/return page | agent → client | unknown / expired / returned | 404 / sensible 410 views | ✅ |
| same | same | same | same | **parent send deleted** (latent, 0 live) | **likely 500** | N/A, untriggered |
| `deals-v2/secure-doc/{token}` (+pack) | token, OTP-gated | secure doc download | agent → recipient | unknown OR revoked | **410 friendly page, both identical** | ❌ deliberately conflated — a defensible call, but diverges from the stated policy shape |
| `/tools/cma/evaluation/public/{certificate}` | Laravel signed URL | evaluation cert | agent → client | tampered/expired signature | **bare Laravel 403, unstyled** | — |
| same | same | same | same | certificate deleted | **bare Laravel 404, unstyled** — inconsistent with the 403 case | — |
| `/fica/{token}` | token | FICA form | agent → client | unknown | 404 | ✅ (vs expiry) |
| same | same | same | same | expired | **410, sensible message** | ✅ |
| same | same | same | same | **soft-deleted submission** | indistinguishable from bogus token (excluded by default scope) | ❌ |
| `/info/{token}` | token | seller info page | agent → seller | unknown / expired | 404 / 410 sensible | ✅ |
| `/m/{shortcode}` | 6-char shortcode | outreach landing, 3 modes | system, outreach campaign | malformed/unknown/deleted send | 404 | only partial |
| same | same | same | same | **agent gone** | **already handled — fallback contact card** | — |
| same | same | same | same | **property gone** | **already handled — generic mode, frozen snapshot data** | — |
| `/outreach/opt-out/{token}` `/opt-in/{token}` | 48-char token | confirmation page | system, every send | unknown | 404 | ✅ |
| same | same | same | same | **contact archived after send** | **also plain 404, same as unknown** | ❌ real gap |
| `/unsubscribe/{agency}` | raw numeric agency ID | unsubscribe form | email footer | bad agency id | 404 | N/A |
| `/legal/privacy/{token}` | token | privacy policy text | embedded in compliance flows | bad token / unpublished | plain 404, both | this is the CORRECT choice per Part 3 — no PII at stake, nothing to distinguish |

---

## PART 2 — The policy

**Johan's three branches, confirmed and extended with his same-day addition:**

1. **VALID TOKEN, RESOURCE DEAD** → a specific, courteous page naming what happened, plus
   a route back (the agent's contact, the agency, a re-engagement action). Never a dead end.
2. **UNKNOWN OR INVALID TOKEN** → still a **404 status code**, but a branded, friendly
   page — "what you're looking for isn't valid any more, visit our website / contact us."
   Warm, but reveals nothing about whether anything ever existed at that URL.
3. **DELIBERATE EXPIRY** (a signing link completed or timed out) → its own category, may
   correctly refuse. Not a dead end to be softened.

**The two texts must stay genuinely different in what they assert** — "isn't valid" reveals
nothing; "this buyer's wishlist has closed" reveals a wishlist existed. `PublicPresentationController`
(`renderUnavailable()` + `unavailable.blade.php`) already implements exactly this
split correctly — copy that pattern, don't invent a new one.

### Rate limiting — what exists today, and the proposal

Currently throttled: opt-out/opt-in (30/1 GET, 10/1 POST), agent business-card image
(60/1), presentation routes (60/1, capture-lead 5/1, request-revision 6/1), privacy-policy
(60/1), legal pages (60/1), unsubscribe (30/1 GET, 10/1 POST), `/m/{shortcode}/callback`
(10/60), shared-match reengage (named limiter). No global default — the `web` middleware
group carries no baseline throttle; every limit above is opt-in per route.

**Not throttled at all today**, including some of the most sensitive: seller live link,
buyer portal, agency property listings, property preview, agent business-card page itself
(only the *image* is throttled), the entire `sign/{token}` group **including `/verify`**
(the ID-number check — currently brute-forceable), `documents/download/{token}` (including
its own `/verify`), sales-documents return, **`deals-v2/secure-doc`'s OTP request/verify**
(currently brute-forceable), seller-info, FICA, and the `/m/{shortcode}` landing itself
(only its callback POST is throttled).

**Proposal**: `throttle:30,1` per IP on every public GET route keyed by a token/slug (matches
the existing convention already used elsewhere in this codebase — reuse it, don't invent a
new number). For the two identity-verification endpoints found with zero protection
(`sign/{token}/verify`, `deals-v2/secure-doc/{token}/verify`) — these aren't just
enumeration risk, they're brute-forceable ID-number/OTP checks — propose a tighter
`throttle:5,1`, matching the existing `capture-lead`/`request-revision` precedent for
sensitive actions.

### The reassignment/takeover mechanism — four found, not one, and none targets "branch manager" specifically

Johan described this as an existing, reusable concept. It's real, but it's **four separate,
non-unified implementations**, and none of them actually resolves to "the branch manager" —
worth being precise about this rather than confirming his framing wholesale:

1. **`AgentDeletionService::reassignAndCleanup()`** — pillar records (properties, contacts,
   deals, tasks). **Triggered only on hard delete**, never on deactivation. Target is
   manually picked from a flat, alphabetical list of every active agency user — no
   branch-manager filter, no suggested default.
2. **`User::resolveByQrSlug()` + `qr_reroute_user_id`** — the agent business-card link.
   **Also delete-only** (mandatory at delete time per spec `agent-qr-onboarding.md`), a
   stored chained pointer. Never populated on plain deactivation — which is why the
   business-card 404 Johan hit is reproducible from code: deactivate an agent today, and
   this pointer is simply never set.
3. **`SharedLinkReengagementService::agencyFallbackContact()`** — the wishlist/shared-match
   link (cc3's fix). **Deactivation-aware** (checks `is_active` and `deleted_at` live, not
   a stored pointer) and falls through to an agency-level fallback contact — not a specific
   successor agent, not branch-manager-scoped.
4. **`SellerOutreachLandingService::resolveBranchManagerFallback()`** — named for branch
   manager, but actually resolves to the earliest-created `super_admin`/`admin` user in the
   agency, with a generic "The team" placeholder as the ultimate fallback. Also
   deactivation-aware.

`role = 'branch_manager'` **is** a real, cheaply resolvable concept elsewhere in the
codebase (confirmed live in `CandidatePractitionerService.php`) — but none of these four
fallback paths actually use it.

**Policy recommendation**: don't invent a fifth mechanism. Compose one shared resolver —
e.g. an `AgentFallbackResolver` — that: (a) live-checks the owning agent's `is_active`/
`deleted_at` (pattern 3/4's approach, since it's the only one that reacts to deactivation,
which is the actually-common departure event, not deletion); (b) if gone, tries an explicit
stored reroute if one exists (pattern 2's `qr_reroute_user_id` concept, generalized); (c)
otherwise resolves the branch manager for that agent's branch (the resolvable-but-unused
`role='branch_manager'` query); (d) otherwise falls to the earliest active admin/super_admin
(pattern 4's actual current behavior); (e) otherwise a generic agency contact card (pattern
3's floor). This reuses every existing piece and finally makes deactivation — not just
deletion — trigger a takeover everywhere.

### Audience split — public vs authenticated 404

**Already exists as a pattern, just needs the guest branch built out.**
`resources/views/errors/404.blade.php` already branches on `@auth` / `@else`: a logged-in
user gets an in-app, dashboard-styled 404; a guest gets a bare, unbranded dark page. This is
the right architecture already — the fix is enriching the `@else` branch into the friendly,
branded "Shucks" page for Case 2 above, not building new plumbing. Keep the in-app branch as
is; staff should never see "contact us."

### Whose branding — can a public URL resolve which agency it belongs to?

CoreX is a single domain, no subdomain-per-agency (`APP_URL` is one host, no
`Route::domain()` found anywhere in the routes). Agency context comes from one of two
places:

- **The URL path itself**, for the one route family that carries it directly:
  `/{agencySlug}/properties` — the slug is resolvable even when the specific property
  isn't, so this route family CAN be agency-branded on every failure, including the
  never-existed case (resolve the agency from the slug first, brand the 404 with it, THEN
  check the property).
- **The underlying (soft-deleted-but-still-present) database record**, for every
  token-based route in the "valid token, resource dead" branch — a soft-deleted property,
  archived wishlist, or revoked presentation link still carries `agency_id`, so THAT page
  can and should be agency-branded (matches what `/p/{token}`'s revoked case already does —
  it shows agent contact info, meaning agency context is already being resolved there today).

**For a genuinely unknown token, there is nothing to look up — no agency is resolvable, and
none should be guessed.** That page must be neutral, platform-level (CoreX) branding, never
a specific agency's, and never hardcoded to HFC — confirmed precedent already exists for
this exact "don't default to a specific brand" discipline: `/m/{shortcode}`'s og:image
omits itself entirely when an agency has no logo, rather than falling back to CoreX's own
mark.

---

## PART 3 — Distinguishability, summarized

Already marked per-row in the Part 1 table (✅/❌). Pattern: routes that already do
courteous fallback (presentations, shared-match, FICA expiry, sales-documents, seller-info)
distinguish cleanly. Routes that use a bare `abort_unless(..., 404)`/`firstOrFail()` with no
custom handling (seller live link's crash/sold cases, agency properties, property preview,
business card's deactivation case, buyer portal, opt-out/opt-in's archived-contact case,
`deals-v2/secure-doc`'s deliberate 410-for-both) do not. `deals-v2/secure-doc` conflating
unknown-and-revoked into one page is a defensible security-first choice, not obviously a
bug — flagged as a deliberate-call item for Johan, not put on the fix list as broken.

## PART 4 — Privacy, summarized

**No route found leaking materially more than the intended recipient needed.** Specific
notes: FICA's form itself renders zero pre-filled PII (blank inputs only) but has **no
identity check before submit** — anyone holding the link can submit compliance data
attributed to the named recipient (a fraud-surface, not a data leak, flagging since it's
adjacent). The property-preview route's `?agent={id}` deliberately resolves past the
tenant scope by design, with a same-agency guard, to let a sharing agent's identity travel
with a link even when they're not the session user — a real, if narrow, existing pattern
worth knowing about. Sequential/guessable property IDs on the agency-listing and preview
routes mean an outsider can enumerate what's currently marketable across an agency without
any token at all — not a privacy leak in the PII sense, but a business-data exposure worth
a decision (out of scope for this audit to resolve).

## PART 5 — Scale, live counts, right now

| Category | Count |
|---|---|
| Seller live links pointing at a soft-deleted property (→ 500 today) | **19** |
| Seller live links pointing at a sold/delisted property (→ silently stale, no error) | **152** |
| Total non-revoked seller live links | 619 |
| Non-marketable properties agency-wide (every direct link to one 404s today, no fallback) | 167 |
| Deactivated/deleted agents with a business-card slug that dead-ends today | **3** (Nthabiseng Moeng, Katlego Moeng, Cherise Wybenga) |
| Buyer portal links currently dead | 0 of 4 active |
| Presentation links currently dead | 0 of 1 active |
| Signing requests stuck `pending` against a cancelled/rejected template | 0 |
| Sales-document recipients currently orphaned (parent send deleted) | 0 of 30 (latent risk, not active) |
| `deals-v2/secure-doc` distributions in existence at all | 0 (feature unused in practice) |
| FICA submissions expired (handled correctly) | 17 of 208 |
| FICA submissions soft-deleted (indistinguishable from bogus token) | not separately counted — flagged as a gap, not a live count |
| Seller-info links currently dead | 0 of 2 |
| Outreach sends where the agent is now gone (already handled by fallback) | 28 of 1,246 |
| Outreach sends where the property is now gone (already handled) | 14 of 1,246 |
| Outreach opt-out/opt-in tokens whose contact was archived after send (real gap) | 2 |

---

## Ranked fix list — worst client-facing impact first

`/shared/match/{token}` excluded — already in flight with cc3.

1. **Seller live link 500 on a soft-deleted property (19 live today).** A hard crash, the
   single worst thing on this list — a real client hits a broken page with a stack trace
   risk, not even a 404. `SellerLinkController::show`, null-check the property before
   calling `getFeedbackRollup()`.
2. **Seller live link silently stale on a sold property (152 live today).** Not an error —
   worse in a different way: actively misleads a seller into thinking their sold listing is
   still being marketed. Needs a status check and a "this property has sold" branch.
3. **Agent business-card 404 on deactivation (3 live today, but this is Johan's own named
   example and the one most likely to recur at real scale).** The reroute mechanism exists
   and works at delete-time; wire it (or the composed resolver from Part 2) into the plain
   deactivate toggle too.
4. **Agency property listings / property preview — plain 404, no agency context, no
   fallback (167 non-marketable properties agency-wide, every direct link 404s).** Given
   the URL already carries the agency slug for one of these two routes, this is the
   cheapest agency-branded fix on the list.
5. **`sign/{token}` — cancelled/rejected template falls through to the normal signing flow
   with no notice (0 live today, but a real gap in reachable code, not hypothetical).**
   Small, contained fix: check `template->status` in `show()`, not just the request's own
   status/TTL.
6. **Buyer portal — revoked/never-existed indistinguishable, and the POST `/respond`
   returns a bare Laravel 403 while the GET `/show` returns a friendly page (inconsistent
   between the two, 0 live-dead today but the inconsistency is real and cheap to fix).**
7. **Outreach opt-out/opt-in — archived contact indistinguishable from unknown token (2
   live today).** Real distinguishability gap on a low-volume but already-occurring case.
8. **Rate limiting gaps on `sign/{token}/verify` and `deals-v2/secure-doc/{token}/verify`**
   — not a dead-end issue, a brute-force exposure found as a side effect of this audit.
   Flagging here since it's cheap (one middleware line) and adjacent to everything else
   being touched.
9. **Evaluation-certificate route's inconsistent bare 403/404 (Laravel default, unstyled).**
   Lowest client volume of anything on this list (agent-generated, not a mass-send channel)
   — lowest priority, listed for completeness.

**Deliberately not on this list, flagged as "needs Johan's call, not a bug":**
`deals-v2/secure-doc` conflating unknown-and-revoked into one identical 410 page — arguably
the more security-conscious choice, diverges from the stated policy shape on purpose.
