# E-sign RECIPIENT signing surface + agent-initial gap — handoff spec

## AUTHORITATIVE — Johan clarification 2026-07-27

This section OVERRIDES anything below it that conflicts. Johan is the authority.

- The Y/N/N-A radio buttons on the disclosure (MDF) and the COC statements
  (Addendum B) are for the RECIPIENT to click and sign, on BOTH documents. They
  must be recipient-clickable. Do NOT gate them to owner/agent-only. Any prior
  "gate MDF's disclosure to owner-only" direction is WRONG and must be reversed if
  it was applied.
- THE BLOCKER (fix this first, everything else is downstream): during AGENT
  signing, the agent is never prompted to initial the other-conditions / clauses.
  Because the agent cannot initial the clauses, the document must NOT and cannot
  legitimately advance to the recipients. Requirement: the agent-signing flow MUST
  require the agent to initial EACH clause/condition before the document moves to
  recipient signing. Johan tested at recipient level only because agent-level was
  already broken here.
- Only after the agent-initial-clauses step works: recipient side must let the
  recipient tick each radio, initial each agent-added condition, and +Add +
  initial their own — on both MDF and Addendum B.
- Proof bar for the fresh session: drive AGENT signing in a real browser first
  (agent is forced to initial every clause, then submit), THEN recipient signing
  (radios tickable, conditions initialable, +Add works) — on both MDF (472) and
  Addendum B (481). Screenshot each.

---

Branch `esign-input-followup`. QA1 only. Written as a checkpoint before the fix
begins — the prior fix (per-document other-conditions persist/route/render, QA1
`7e4f1885`, `esign-input-followup` `4a2ebf5c`) is committed and live; this is the
NEXT piece: the RECIPIENT signing surface is broken and the AGENT is never asked
to initial the conditions section.

Context provenance: the per-document fix proved the AGENT fill→render path (pack
doc 485). Johan then pushed **Addendum B (doc 481)** to recipient 1 and tested the
RECIPIENT signing flow — it is broken end-to-end. Prove by CLICKING the real
rendered page (headless Chromium on serving QA1), not unit tests. Johan clicks
the live UI.

## The four failures (Johan's exact findings)

1. **Recipient `+Add condition` is dead.** On the RECIPIENT signing view the
   "+ Add condition" block does not respond. It must: recipient clicks → adds a
   condition frame → recipient initials it → the addition passes through the
   agent-review re-engagement flow already built for recipient-added conditions
   (KICKER / amendment cascade → agent-review gate → all completed parties
   re-engaged to initial). Broken on **BOTH** MDF (472) and Addendum B (481) —
   this is the GENERAL other-conditions recipient-interaction defect; fix once.

2. **Recipient cannot click agent-added conditions' initial slots.** The
   agent-added conditions DO render on the recipient view, but the recipient's
   per-party initial slot on each condition is not clickable/initialable. Also
   broken on **BOTH** MDF (472) and Addendum B (481) — same general defect as (1).

3. **Agent-signing conditions-initial step is MISSING.** During AGENT signing,
   the agent was never prompted to initial the conditions they added. Every
   condition the agent added → the agent must be required to initial it. Wire
   this into the agent-signing completion gate — it is currently skipped.

4. **Radios (Y/N/N-A) not selectable by recipient — ADDENDUM-B-SPECIFIC.**
   The Y/N/N-A radio inputs on Addendum B (doc 481) and its disclosure statement
   rows are not clickable/selectable by the recipient (and must persist).
   NARROWING (Johan's latest test): on the **MDF (doc 472) the recipient CAN
   click the radios**; on **Addendum B (481) they CANNOT**. So the radio defect
   is Addendum-B-specific — compare the MDF vs Addendum B radio markup / handler
   / editability-stamp to find the divergence. (This is separate from failures
   1–3, which are the general other-conditions defect on both docs.)

## Reproduce Johan's exact path (serving QA1, real browser)

Take **Addendum B doc 481 / ST 112** (status `signing`), the one Johan pushed to
recipient 1. Open the RECIPIENT signing link and, AS THE RECIPIENT, verify you can
(a) select the Y/N/N-A radios, (b) click to initial each agent-added condition,
(c) click `+Add condition` and add + initial a new one. Separately drive the
AGENT signing and confirm the agent is prompted to initial the conditions.
Screenshot recipient view AND agent view. Compare doc 472 (MDF, radios WORK) vs
481 (radios BROKEN) for failure 4.

### Signing links (doc 481 / ST 112)
- Agent req 240 (status viewed): `https://qatesting1.corexos.co.za/sign/w7VnfpL08BzB8WVzdFEYH4EOwCuPvdG4aJZ4m0MSbXLVdO902hlQEekcJ6jkdrOp`
- **Seller / recipient-1 req 241** (status waiting): `https://qatesting1.corexos.co.za/sign/rpdTvjDfqPVhLDAwadw5xi5Fu9sqBKNaDz90omoLAKDMi0dqs9vXOvsilO99AbTd`
- Buyer req 242 (status waiting): `https://qatesting1.corexos.co.za/sign/sneXARpjv3WlBqpTlr8ifWrTtmXjZYnjuF2AQ1JQTyZlVpDrgSgUblu8IXBoJWit`

Note: recipient reqs are `waiting` (ceremony gated on agent order 1). To exercise
the recipient surface you may need to advance the ceremony (agent signs first) or
open the recipient link in the state Johan tested. doc 481 has 1 agent-added
condition on the bare `other_conditions` block (single-doc → bare is correct).
Compare doc **472 (MDF)** for the radio-works baseline.

## Code areas identified (start here)

- `app/Http/Controllers/Docuperfect/SigningController.php` — `show()`
  recipient-context render (CONTEXT_RECIPIENT_SIGNING). Primary path is CANONICAL
  serve → `applyViewerEditabilityOverlay` → `reRenderBlocksForViewer`
  (makes the block interactive for THIS viewer) → `stampConditionSigningToken`
  (stamps the current signing token so the recipient can POST an add/initial).
  Failures 1 & 2 almost certainly live in how these overlays wire the RECIPIENT
  (vs agent) role — the +Add + per-condition initial slots must be actionable
  for `party_role = seller/buyer`, not just the agent.
- `app/Services/Docuperfect/InsertableBlockRenderer.php` —
  `reRenderBlocksForViewer()`, `renderInitialSlotsForCondition`,
  `resolveAdoptedInitial`, `renderBlockPartial`, `stampConditionSigningToken`,
  `injectAddConditionGuidance`. The per-party initial slot's "actionable for
  current party" logic and the +Add control's token/handler are here.
- The signing-view Blade (recipient signing template) — radio (`tick`) input
  handling + persistence, and the JS that POSTs radios/initials/conditions.
  For failure 4, diff the Addendum B template's radio markup / `data-viewer-editable`
  / `data-field` stamping vs the MDF's (MDF radios work, Addendum B don't). Likely
  the Addendum B blade emits radios that the recipient-editability pass or the
  client handler does not recognise as viewer-editable.
- `RoleBlockExpansionService::applyViewerEditabilityOverlay` — stamps
  `data-viewer-editable="1"` on fields THIS recipient may edit; if Addendum B's
  radios aren't stamped, they'll be inert for the recipient (candidate root cause
  for failure 4).
- Agent-signing completion gate (failure 3) — where the agent's sign is finalised.
  Requiring the agent to initial each condition they added ties into the
  `pending_agent_approval` / agent-review gate machinery (AT-322,
  `at322-final-agent-review-gate`) and the amendment/KICKER re-engagement cascade.

## Proof harness (scratchpad — session-isolated, may need re-mint)

- Puppeteer at `/corex-qa1/node_modules/puppeteer`, `executablePath:'/usr/bin/chromium'`.
- Prior session's shot scripts + the minted Johan (user 46) session cookie were in
  the scratchpad: `shot.mjs`, `wiz.mjs`, `selector.mjs`, `signshot.mjs`,
  `elshot.mjs`, `addtest.mjs`, and `cookie.txt` (COOKIE_NAME/COOKIE_VALUE/
  XSRF_VALUE/PLAIN_TOKEN). Scratchpad is session-isolated — RE-MINT the session in
  the new session: minting script pattern loads `app('session')->driver()`, puts
  `_token` + `auth()->guard('web')->getName() => 46` + `password_hash_web`, saves,
  then `Crypt` via `Illuminate\Cookie\CookieValuePrefix::create($cookieName,$key)`
  → cookie value. SESSION_DRIVER=database, cookie name `corex-os-qa1-session`.
  Verify with `curl -b cookie /api/v1/logged-user` → `{"authenticated":true,...id:46}`.
- SignatureRequest token column is `token` (NOT `signing_token`).
- To drive a fresh create end-to-end: clone a Flow row (must set `type`), POST
  `/docuperfect/esign/{flow}/prepare-signing` with `X-CSRF-TOKEN: <PLAIN_TOKEN>`.

## Deploy standard (unchanged)

Serving tree `/corex-qa1` (branch QA1): edit → `php artisan optimize:clear` +
`view:clear`, `npm run build` ONLY if a Vite asset changed (Blade views are NOT
Vite assets), **`sudo systemctl reload php8.2-fpm`** (opcache). Commit changed
files to `origin/QA1` with EXPLICIT pathspec (shared tree — never `git add -A`;
untracked `tmp_*.cjs` + a template blade exist there, do not sweep them). Mirror
the same commit to `esign-input-followup`. Keep docs persistent, nothing rolled
back. Hand Johan BOTH the recipient signing link AND the agent link for Addendum B
(doc 481) plus the 3-doc pack (doc 485).

## RESOLUTION (2026-07-27 — QA1 `4339980e`, esign-input-followup `2fb9df88`)

Root causes + fixes (2 files: `resources/views/docuperfect/signatures/external/sign.blade.php`
+ `resources/views/docuperfect/signatures/_partials/add-condition-modal.blade.php`).
Full root-cause map: `.ai/audits/recipient-signing-investigation.md`.

**Failures 1 & 2 (recipient +Add dead; recipient can't initial agent-added
conditions) — FIXED.** Root cause: the `.btn-add-condition` / `.btn-add-initial`
click handlers were a one-shot `querySelectorAll` IIFE bound at
`DOMContentLoaded`/`alpine:initialized`, but the document body arrives via Alpine
`x-html` and is relocated by `paginateDocument()` in a deferred
`$nextTick`+`setTimeout(150)` — AFTER those events — so the handlers bound to zero
buttons and never re-ran. Replaced with DELEGATED click handlers on `document`
that survive x-html inject + pagination + in-place row append. Proven on serving
QA1: recipient (Addendum B, MDF) can +Add a condition (modal opens) and initial
each agent-added condition (slot fills "SS", N-remaining drops).

**Failure 3 (agent never asked to initial the conditions section) — FIXED.** Root
cause: the submit gate (`_computeIncompleteItems()` + `_computeWebCounts()`) only
counted page-break initials (`[data-marker-type="initial"]`), never the
per-condition `.btn-add-initial[data-condition-id]` slots — so the agent was never
required to initial conditions they added and recipients could skip them. Both
methods now count the current signer's condition-initial slots; filling one (or
adding a condition) dispatches `corex-refresh-signing-count` to refresh the gate.
Proven on serving QA1 pack doc 485: the agent's 3 added conditions are now in
`totalRequired` (was 50, now 51); initialing one drops incomplete 26→25.

**Failure 4 (Addendum B radios not selectable by recipient) — INVESTIGATED, NOT a
code defect for the disclosing party; FLAGGED to Johan (policy call).** Empirically
proven on serving QA1: the SELLER (owner_party) CAN tick Addendum B radios — they
toggle ○→✓ and persist. The structural asymmetry: MDF's disclosure
(`_processDisclosureTable`, bare `.corex-table`) injects radios with NO party gate,
so ANY signer (agent, buyer) can tick; Addendum B's disclosure
(`processWebDisclosureChecklists`, `.corex-disclosure-checklist`) is gated to the
disclosing owner/seller party via `_disclosureEditable()` (PPA-s70, "the seller is
the sole discloser" — a Johan-approved legal rule, `disclosure-logic.blade.php:15-38`).
So a non-owner recipient (buyer/agent) correctly sees Addendum B read-only while
MDF is (arguably wrongly) editable by anyone. "MDF works, Addendum B doesn't" is
exactly that asymmetry when testing as a non-seller. NOT changed — the gate is
legally grounded and I could not reproduce a failure for the seller. Johan to
confirm which party he tested as; the real inconsistency to resolve (his call) is
that MDF's bare-table path is ungated while the checklist path honours PPA-s70 —
they should match (gate MDF too, or intentionally ungate Addendum B). SECONDARY
gap (subagent): Addendum B seeds `field_mappings => []`, so its `coc_*_when`
"date issued" fields are never made editable even for the seller — separate ticket.

**Handoff docs (serving QA1, recipient-active + agent):**
- Agent-initial gate (pack): agent link doc 485 → required to initial all 3 conditions.
- Addendum B RECIPIENT (seller, ready now): doc 490 `/sign/xSNEjtvborExul3bmZpuCJdX2spVtpzZJvBg9Q247sKG9uRv3YrJk2a3kdoUdNtX` (ID 8001015009087).
- MDF RECIPIENT (seller, ready now): doc 491 `/sign/PXdxnGHV3TPneWTsUaU1mrmJpZpDDb2CkLiEs8tm4UwdPmwU6ZF28JuohGzA3nEn`.
- Pristine sequential test (agent→recipient): Addendum B 481 + pack 485 agent links, then the seller link activates.

## Do NOT re-fix (already done, committed, live)

Per-document other-conditions: persist (closure `$stepData` scope), Step-5
per-document selector (`target_doc_index`), live preview marker render, bridge
scoped routing to `other_conditions__<docKey>`. QA1 `7e4f1885`,
`esign-input-followup` `4a2ebf5c`. See `esign-otherconditions-closure-stepdata-scope`
memory. Pack proof doc 485; MDF 472; Addendum B 481; seeded 2-doc pack 477.

---

## OTHER-CONDITIONS INITIAL RULE + render/enforcement diagnosis (Johan 2026-07-28)

> **AUTHORITATIVE. Overrides anything above it that conflicts. Read before building the e-sign other-conditions fix.**
> Status at capture: the agent-initial-per-condition requirement is **NOT enforced** in practice (see diagnosis). This section is the durable brief for the later build — no app code / no DB writes were made when it was written.

### 1) AUTHORITATIVE RULE (Johan) — universal, not document-specific

Whenever an **"other condition" clause is added to ANY document** (any e-sign doc — MDF, Addendum B, mandate pack, rental, anything — not just the doc where the defect was seen), **ALL parties must AGREE to it and INITIAL it**: the **agent AND every recipient/signer**. The document **MUST be blocked from advancing to any recipient** until **every party has initialled every added condition**. This is a hard gate, not a UI nicety.

- The rule is UNIVERSAL. Do not scope the fix to Addendum B or to the doc in the repro. Any document that gains an other-condition clause is subject to it.
- Every added condition needs a per-party initial slot for **each** party; none may be skipped.
- Advancing to recipients is forbidden until the agent (and, in turn, each recipient) has initialled **every** added condition.

**Acceptance flow to prove (must pass before the fix is "done"):**
1. Pick **Addendum B**.
2. Fill-and-sign as the agent.
3. Add a clause under **OTHER CONDITIONS** (`+ Add condition`).
4. An **agent-initial control appears next to that clause**.
5. The **agent must initial it** — the document cannot be submitted/advanced while it is un-initialled.
6. **Only then** may it advance to recipients.
7. Each **recipient must likewise initial** that clause on their signing view before they can complete.

### 2) DIAGNOSIS (from the 2026-07-28 read-only forensic)

- The requirement is currently enforced **only by a bypassable CLIENT-side DOM count** of `.btn-add-initial[data-condition-id]` slots in `resources/views/docuperfect/signatures/external/sign.blade.php` (`_computeIncompleteItems()` / `_computeWebCounts()`, commit `4339980e`).
- The **SERVER floor does NOT check condition-initials.** `SigningController::completeWeb()` (`app/Http/Controllers/Docuperfect/SigningController.php:1439-1453`, AT-293) enforces only: consent + at least one signature/initial + (if editable fields) fill one. Condition-initialing is explicitly outside that floor. A crafted or JS-failed POST advances the doc regardless.
- On the repro doc (**spec's "doc 481"** = `signature_templates.document_id=481` / template **112**; the *current* "Test condition on the addendum B" is on **template id=123 / document_id=492**) the **DATA is correct**:
  - `parties_json` **contains a `role='agent'` party** (Johan Reichel) alongside `seller` / `seller_2`.
  - A **structured `document_conditions` row exists**: `id=34`, `signature_template_id=123`, `block_purpose='other_conditions'`, live (not soft-deleted), `content="Test condition on the addendum B"`. It is present as a structured row **and** in `other_conditions_text` — so it is NOT the legacy-text-only fallback path.
- Therefore this is a **RENDER-PATH bug, not a data bug.** Both preconditions the fix needs are satisfied, yet the agent's signing HTML does not contain the condition-initial slots, so the client count reads zero and nothing is required.
  - Ruled out: the **compiled-serving** path — `compiled_templates` table is **empty**, so `show()` never takes the `renderForSigning` branch.
  - The slots emit **only on a fresh `InsertableBlockRenderer` render under `CONTEXT_RECIPIENT_SIGNING`** (`InsertableBlockRenderer::renderInitialSlotsForCondition`, the `$isMine` gate at ~L434-437 requires `CONTEXT_RECIPIENT_SIGNING`).
  - **Leading suspect:** the **stored `merged_html` snapshot branch at `SigningController.php:338-339` short-circuits ahead of the fix's fresh render at ~L389.** A snapshot baked at agent-prep time (`CONTEXT_AGENT_PREPARATION`) carries condition rows with **no active agent slot** (because `$isMine` demands the recipient-signing context), so the served HTML lacks `.btn-add-initial[data-condition-id]` for the agent. (Not fully confirmed from data alone — `merged_html` is not a column on `signature_templates`; confirm the exact source in code during the build.)
- Fix `4339980e` **is deployed** on serving QA1 (HEAD `845e914b`; markers present in the served blade). It works on the pack/MDF (doc 485, where it was proven) but not on the snapshot-served Addendum B agent path — the fix's proof never exercised Addendum B's agent-signing flow, and there is **no automated test** covering the gate.

### 3) FIX DIRECTION (for the later build — DO NOT build now)

- **(a) Render:** ensure condition-initial slots render for **every party incl. the agent** on **ANY** document that has an added other-condition, **regardless of the serve/snapshot path** (fresh render, stored `merged_html`, or any future path). The slot's presence must not depend on which serving branch `show()` happens to take.
- **(b) Server enforcement:** add a **server-side gate** that **blocks advancing to recipients until every party has initialled every added condition** — do not rely on the client DOM count. This closes the AT-293 floor gap for conditions specifically.
- **(c) Test:** include an **automated test** asserting **"add condition → agent forced to initial before advance"** (and, ideally, the recipient leg too). Lands in `tests/Feature/Docuperfect/SigningView/` per the pipeline gate. There is currently **zero** test coverage of this gate.
