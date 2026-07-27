# E-sign RECIPIENT signing surface + agent-initial gap — handoff spec

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

## Do NOT re-fix (already done, committed, live)

Per-document other-conditions: persist (closure `$stepData` scope), Step-5
per-document selector (`target_doc_index`), live preview marker render, bridge
scoped routing to `other_conditions__<docKey>`. QA1 `7e4f1885`,
`esign-input-followup` `4a2ebf5c`. See `esign-otherconditions-closure-stepdata-scope`
memory. Pack proof doc 485; MDF 472; Addendum B 481; seeded 2-doc pack 477.
