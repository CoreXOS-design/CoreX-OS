# ESIGN-WETINK — BUILD HANDOFF (fresh-head resume)

> **Read this, then `.ai/specs/ESIGN-WETINK.md` (the full spec + gap audit).**
> You are resuming a legally-critical e-sign re-plumb mid-flight. The prior head
> hit context limit. Everything you need to continue is on disk + in git.
> **Target set by Johan: land 1a + 1b + 1c on the bench by morning.** 1d/1e run
> in a parallel lane tomorrow on the landed spine.

---

## 0. The doctrine (why this exists)

E-sign must mimic **wet ink exactly**. The template births **ONE document** at
e-sign setup. That exact rendered document **IS** the screen every party opens.
It flows **sequentially** party → party; **each hop adds only its ink**; the next
party receives the exact accumulated artifact the previous party sent, rendered
**identically**. The document **never re-renders differently per screen**.

The current system re-runs the whole expansion pipeline **per surface, per
viewer, at display time** — that is the defect class ("combined I/We clause on one
screen, per-seller on another; ink that can't accumulate"). This is legally
fatal. We rip out per-screen rendering and serve the one stored artifact.

**This is re-plumbing, not invention.** Every business rule (editable_by scoping,
letterhead, role-block looping, field gates) already exists and stays. The only
genuinely NEW piece is **identity-scoping** so every party's ink stays distinct
(`data-recipient-identity`).

### ⚠️ N-PARTY — no 1/2 assumptions ANYWHERE (Johan scope correction)

A document carries **as many sellers AND buyers as needed**, plus witnesses and
the practitioner — **unbounded collections**, not seller_1/seller_2. Every layer
of the identity model must be fully **N-party**:
- `data-recipient-identity` keying: `{role}_{index}` where index runs 1..N per
  role, for **every** role (seller, buyer, witness, practitioner), no ceiling.
- Expansion (`expandWithLooping`) already loops per recipient — feed it the full
  recipient collection; never assume 2.
- Ink composition (`CanonicalInkComposer`, §2 Phase 1c) resolves the signer's
  nodes by their identity key out of an N-length set — no `seller_1`/`seller_2`
  branches, no hard-coded pair.
- Version chain (1e) snapshots after **each** hop across the full ordered party
  list, however long.
- Editability overlay (§2 Phase 1b): each of the N same-role recipients edits
  ONLY their own identity-keyed instance.

**Grep-test before you commit:** no literal `seller_1`/`seller_2`/`buyer_1` in new
logic except as *runtime-built* keys or *test data*. Any hard-coded pair is a bug.

**OTP is the stress case:** the Offer to Purchase carries multiple sellers +
multiple buyers + witnesses + practitioner on ONE document. If the N-party model
renders and flows the OTP correctly, it holds for everything.

### OTP context note (do NOT build OTP-specific e-sign routing)

In live practice the **OTP itself is WET-INK-ONLY**: the agent renders it, parties
sign on **paper**, and the signed OTP is later **scanned + attached** to the deal.
That scan-attach flow is a **SEPARATE, later build (post-e-sign)** — not in scope
here. Tomorrow's **OTP import is the e-sign ACCEPTANCE-TEST document only**: its
N-party structure is exactly what the canonical artifact must render and flow
correctly. Use it to prove N-party rendering/ink/version — but **do NOT bake any
OTP-specific routing, party-shape, or ceremony assumptions** into the e-sign
engine. The engine stays document-agnostic; the OTP is just the hardest fixture.

---

## 1. Current state (git)

- **Branch:** `AT-300-onsite-refix` (off `origin/QA1` `bcbb2849`)
- **Tip:** `ba2792a9` — **Phase 1a + 1b + 1c LANDED + pushed (READY-FOR-QA1)**.
  - `b955e2c4` — 1a (compose+store canonical artifact).
  - `ba2792a9` — 1b (every surface serves canonical_html + editability overlay)
    **and** 1c (ink baked by data-recipient-identity; markers now identity-stamped
    in the expansion; `CanonicalInkComposer::bakeInk`; `completeWeb` bakes into
    canonical + version bump; PDF/print/hash read canonical). Landed as ONE slice
    because 1b-serve without 1c-bake would leave a transitional gap where party 2
    can't see party 1's ink — Johan's acceptance test is the N-party accumulation
    journey, so both had to ship together.
  - **NOTE (git sync):** branch is 8 commits behind `origin/QA1` (AT-229 etc.).
    NOT rebased — the conductor pinned the base at `2c617b16` and a mid-flight
    rebase of 8 QA1 commits into the exact e-sign files under rewrite risked
    conflicts on a legally-critical build. Flagged for the conductor to sequence
    the QA1 catch-up rebase deliberately.
- **Proven (local):** CanonicalInkIdentityScopingTest 5/5 (multi-seller no-bleed,
  2-hop accumulation, sole-of-role fallback, blank-when-ambiguous, scoped
  editability); CollectiveRoleRenderTest 3/3 (no regression); real-data
  doc-140/tmpl-31 compose→bake end-to-end. **NEXT GATE = deployed-qa1 ≥3-party
  OTP journey** (multi-seller + multi-buyer) per §5 — the DONE bar is Johan's screen.
- Deploy target: **qa1 host only** (`/corex-qa1`, qatesting1.corexos.co.za).
  NEVER Staging/live — m2 is the deploy hand, Johan is the gate.
- Tag commits `READY-FOR-QA1` in the subject; cc1 auto-deploys on a 15-min watch.
- Shell rule: write-script-to-file-then-`bash file.sh` (no inline `$()`/heredocs/loops).

### What 1a shipped (`b955e2c4`)
`app/Services/Docuperfect/CanonicalDocumentRenderer.php` — **the ONE renderer**.
- `compose(SignatureTemplate): string` — merged_html → `SignatureSurfaceNormalizer::normalize`
  → `LetterheadRefresher::refresh` → `InsertableBlockRenderer::renderInDocument(…, CONTEXT_AGENT_PREPARATION, null, null)`
  → `RoleBlockExpansionService::expandWithLooping($docTemplate, $html, $recipients, **null**, $fieldMappings)`.
  The **`null` viewer is deliberate** → fully-expanded + identity-stamped but
  **viewer-AGNOSTIC** (no `data-viewer-editable` baked). That agnosticism is what
  makes the artifact identical for everyone.
- `composeAndStore(SignatureTemplate): void` — stores `web_template_data['canonical_html']`
  + `['canonical_version']=0`. try/catch **non-fatal**.
- Wired into `SignatureService::sendForSigning` right after `stampLegalDeadline`
  (~line 961). **Store-only: nothing serves it yet → zero live behaviour change.**

**Verified** on Johan's real doc-424 artifact (see §5 recipe): I/We collective
clause once + both seller names; per-seller detail loops address×2/phone×2;
identity-stamped seller_1×4 / seller_2×4. GOOD — do not "fix" compose.

> ⚠️ A compose "defect" was flagged mid-build then RETRACTED — it was a bug in
> the *verify script* (`\"` inside single-quoted PHP = literal backslash → false
> zero counts), not the pipeline. Ignore any note claiming compose drops detail
> blocks. Re-prove with §5 if unsure.

---

## 2. BUILD ORDER — 1b then 1c

### Phase 1b — every surface SERVES `canonical_html` verbatim

**Goal:** replace all display-time re-expansion with "read `canonical_html`, apply
per-viewer overlays, serve." One artifact, one render.

**COMPLETE serve-point surface map (every surface that renders the document — all
must serve the ONE canonical via `CanonicalDocumentRenderer::forDisplay()`):**

| Surface | Route | Method | Status |
|---|---|---|---|
| Recipient ceremony | `signatures/external/{token}` | `SigningController::show()` | ✅ canonical (resolveOrCompose) |
| **Marker setup (Step 2)** | `documents/{id}/signatures/setup` | `SignatureController::setup()` | ✅ **forDisplay (was self-composing, un-expanded — Johan seam #1)** |
| **Agent sign** | `documents/{id}/sign` | `SignatureController::sign()` | ✅ **forDisplay (was gated on STORED canonical → pre-send fell back to un-expanded merged_html — Johan seam #2)** |
| **Agent review** | `documents/{id}/signatures/review` | `SignatureController::review()` | ✅ **forDisplay** |
| **Amendment review (AT-302)** | (flag review) | `AmendmentController::buildFlaggedDocumentHtml()` | ✅ **forDisplay** |
| Wizard Step-5 preview | (wizard) | `ESignWizardController::templatePages()` | ➖ already runs expandWithLooping (transient recipients); merged_html = agent-edit source |
| Final signed PDF | (pdf gen) | `SignaturePdfService::generate()` | ✅ canonical vN precedence |
| Print / download | `signatures/external/print` | `SigningController::printView()` | ✅ canonicalOrMerged |
| Consent hash | (verify) | `SigningController` hash | ✅ canonicalOrMerged |
| Agent completion (WRITE) | `documents/{id}/web-sign-complete` | `SignatureController::webSignComplete()` | ➖ embeds into merged_html BEFORE send → flows into canonical v0 at send-compose (no separate bake needed; a bake here would be overwritten by send's composeAndStore) |
| Recipient completion (WRITE) | `completeWeb` | `SigningController::completeWeb()` | ✅ bakeInk into canonical (1c) |

> **ROOT CAUSE of the setup/sign seams:** agent-side PREP surfaces (setup, sign)
> ran BEFORE a canonical is stored, and either self-composed (setup) or gated the
> canonical serve on the STORED artifact existing (sign) — so pre-send they fell
> back to raw un-expanded `merged_html` and rendered N-seller domicilium in its
> COLLECTIVE form. `forDisplay()` = stored canonical if sent, else composed FRESH
> via the identical pipeline (expandWithLooping included, no store). Every display
> surface now calls the one method → **byte-identical by construction.**
>
> **Verify recipe (deployed qa1, doc 431 — 2 sellers Anine + Andre):** curl the
> setup, sign and ceremony URLs; extract the seller-domicilium/address block from
> each; diff — must be byte-identical. (Local DB has no 2-seller web-body doc, so
> the N-seller byte-diff is a DEPLOYED-qa1 proof; local proved forDisplay
> stored-vs-compose + expansion + no-bleed via CanonicalInkIdentityScopingTest.)

**Original serve points (Phase 1b first pass, all in `app/Http/Controllers/Docuperfect/`):**

| Surface | File:line (current) | Today it does | Change to |
|---|---|---|---|
| Main signing view | `SigningController.php:281-282` then `:345-353` | reads `merged_html` → `expandWithLooping(viewer=$signingRequest)` at display | read `canonical_html` (fallback to compose-on-the-fly if absent for pre-1a docs) → **apply editability overlay** (below), NO re-expand |
| Letterhead re-resolve | `SigningController.php:320` | re-resolves letterhead on the stored snapshot | drop — canonical_html already has current letterhead from compose |
| Compiled dual-path | `SigningController.php:266` `CompiledSigningRenderer` | alt render path | **retire** (fold into canonical serve — see §3) |
| PDF/paginated reads | `SigningController.php:684, 1972, 2137, 2181` | read `merged_html` | read `canonical_html` |
| completeWeb ink write | `SigningController.php:1513-1533` (writes `merged_html` + `signed_paginated_html:1539`) | bakes this signer's ink back into `merged_html` | **1c** rewrites this to bake into `canonical_html` by identity (see §2.1c) |
| Wizard preview/compose sources | `ESignWizardController.php:1964, 4364, 4536` (write merged_html); `:4710, 4729, 4763` (read) | build/preview merged_html | leave merged_html as the AGENT-EDIT source of truth; canonical_html is derived at send. Preview MAY compose canonical for fidelity but not required for 1b. |

**THE CRUX OF 1b — editability as a DISPLAY OVERLAY (do this right or nobody can sign):**
Today `expandWithLooping(viewer=$req)` stamps `data-viewer-editable="1"` (see
`SigningController.php:345` comment) on the fields THIS viewer may edit. If you
serve viewer-agnostic `canonical_html` as-is, **no field is editable by anyone.**
So after loading `canonical_html`, stamp editability as an overlay:
- The scoping logic ALREADY EXISTS: `getEditableFieldsFromMappings($fieldMappings, $party_role)`
  (`SigningController.php:305`) and the server-side gate `persistFieldValue`
  (`:1112-1325`, esp. name→editable_by map `:1241-1256` and role-allows check
  `:1279-1325`). Reuse it — do NOT reinvent.
- Overlay = walk the served DOM, for each `[data-field]` whose logical name is in
  this viewer's editable set (respecting `data-recipient-identity` so seller_1
  only edits seller_1's instance), add `data-viewer-editable="1"`. Everything else
  read-only. Keep the `editableFields` array passed to the view (`:484`).
- **The server-side persist gate (`:1112-1325`) STAYS UNTOUCHED** — it is the
  security ceiling (client can strip `data-viewer-editable`, server still rejects).

**1b verification (deployed-site rule — MANDATORY):** deploy to qa1, open the real
qa1 ceremony as seller_1 → confirm (a) doc renders identically to pre-change
(collective clause once, both detail loops), (b) ONLY seller_1's fields are
editable, (c) seller_2 opening the same link sees the SAME document, edits ONLY
seller_2's fields. Fetch the EXACT page (curl the token URL) and diff the served
markup, not a synthetic fixture. A fix is DONE only when correct on the deployed
qa1 site.

### Phase 1c — ink baking by `data-recipient-identity` (`CanonicalInkComposer`)

**Goal:** each hop writes its ink INTO `canonical_html`, scoped to the signer's
identity, so it accumulates and every later party sees all prior ink.

- **New class** `app/Services/Docuperfect/CanonicalInkComposer.php`:
  `bakeInk(canonical_html, SignatureRequest $signer, array $signatures,
  $initials, $ceremonyValues): string`. Locate the signer's fields by
  **`data-recipient-identity="{role}_{index}"`** — index runs 1..N per role
  (every seller, every buyer, every witness, the practitioner) so **all N parties'
  ink stays distinct** (THE new piece) — and write the ink into those nodes only.
  Resolve the signer's identity key from the SignatureRequest at runtime; no
  hard-coded pair, no assumption of two.
- **Rewire** `completeWeb` (`SigningController.php:1513-1533`): today it normalizes
  `merged_html` and embeds this signer's ink then writes back to `merged_html`.
  Change it to call `CanonicalInkComposer::bakeInk` on `canonical_html`, bump
  `canonical_version`, and write back to `canonical_html`. Keep the parallel
  `signed_paginated_html` write (`:1539`) for PDF — but source it from the baked
  canonical, not a re-render.
- **Ink identity:** the marker/`signature_data` per-viewer overlay approach
  (is_mine) is superseded — ink is now IN the artifact, visible to all. See §4.

**1c verification:** party 1 signs → party 2 opens → sees party 1's ink already
present in the identical document; party 2 signs → party 3 sees BOTH; and so on
down the full ordered party list. Prove on deployed qa1 with a **≥3-party**
ceremony (ideally the multi-seller + multi-buyer OTP fixture), not just a pair —
the N-party accumulation is the thing under test.

---

## 3. CompiledSigningRenderer retirement

`app/Services/Docuperfect/Compiler/Serving/CompiledSigningRenderer.php` +
`CompiledServingResolver.php` are the AT-177 "compiled" dual serve path
(`SigningController.php:266`). Under wet-ink there is ONE artifact and ONE serve
path (canonical_html). Fold the compiled path OUT: the canonical serve replaces
it. Do this only AFTER 1b's canonical serve is proven, and keep the classes on
disk (soft-retire — remove the call site, leave the files) until Johan confirms
no CDS-compiled template regresses. Note: `Template.php`, `CompiledSigningRenderer`
touch the **pipeline gate** (dev-check.ps1 §E-sign moat) — any change to the
gated files needs a test diff in `tests/Feature/Docuperfect/SigningView/`.

---

## 4. AT-300 reconciliation (DO NOT SKIP)

The prior head, chasing "green empty boxes," set two `<template x-if="!marker.is_mine">`
to **`x-if="false"`** in `resources/views/docuperfect/signatures/external/sign.blade.php`
(~lines 545, 788). That HID other-party marker overlays. Under the OLD model that
was the ONLY way seller_2 saw seller_1's ink → the ESIGN-WETINK gap audit finding
(b) warns this WORSENS accumulation until ink bakes into the artifact.

**Once 1c bakes ink into `canonical_html`, that hiding is CORRECT and complete** —
ink lives in the document, not in per-viewer markers, so there is nothing to
overlay and no empty box. **Reconcile:** after 1c lands, confirm on qa1 that with
markers hidden AND ink baked, every party sees all prior ink and no empty green
box. If a gap appears, the fix is in `CanonicalInkComposer` (bake the missing
ink), NOT re-enabling the marker overlay. The green box = party/status-tab chip
shell, separate concern — leave the AT-300b container removal as-is.

---

## 5. Anine doc-424 verification recipe (regenerate after /clear)

The scratchpad `merged-424.html` is session-temp and will NOT survive `/clear`.
Regenerate it, then run the compose check:

```php
// scratchpad/dump-424.php  (run: php artisan tinker scratchpad/dump-424.php)
$doc = \App\Models\Docuperfect\SignatureDocument::find(424); // or any multi-party ceremony doc
file_put_contents('scratchpad/merged-424.html', $doc->web_template_data['merged_html'] ?? '');
echo strlen($doc->web_template_data['merged_html'] ?? ''), " bytes\n";
```
> If doc 424 is gone on a fresh qa1 DB, find a multi-party ceremony — ideally one
> with multiple sellers AND buyers (the OTP fixture is the stress case):
> `SignatureTemplate::withCount('requests')->having('requests_count','>=',3)->get()`
> or filter for ≥2 seller + ≥2 buyer requests; use its document.

Then compose-verify (CORRECT escaping — use `"` not `\"` in single quotes, or
double-quote the needle). Expect for an N-party doc: each collective clause ONCE
with all party names; per-party detail loops = N (one per same-role recipient);
identity-stamped for EVERY `{role}_{index}` present (seller_1..N, buyer_1..N).
Script pattern is in the prior scratchpad `verify-compose2.php` if it survives;
else rebuild from §1 "Verified" line — but generalise the needles to loop over
the full recipient set, not a hard-coded seller_1/seller_2 pair.

**For 1b/1c the real proof is the DEPLOYED qa1 page**, not tinker — curl the
signing token URL, diff served markup, open as each party in a browser.

---

## 6. Guardrails (non-negotiable)

- Build on **QA1 stack only**; deploy **qa1 host only**; never Staging/live.
- **No silent slippage** — if effort/scope shifts, report with numbers.
- Verify each slice **ON the deployed qa1 site** (browser-visible surface), not
  just green tests. Fetch the exact page, match markup, prove.
- Pipeline gate: changes to `SigningController.php` / `Template.php` /
  `RoleBlockExpansionService.php` / `SignatureSurfaceNormalizer.php` /
  `LetterheadRefresher.php` / `InsertableBlockRenderer.php` etc. REQUIRE a test
  diff in `tests/Feature/Docuperfect/SigningView/`.
- No hard deletes; permissions gated; one artifact, one render — the doctrine.

---

## 7. One-line resume

> Branch `AT-300-onsite-refix` @ `b955e2c4`. 1a (compose+store canonical_html)
> DONE + verified. Next: **1b** — rewire the serve points in §2 to read
> `canonical_html` + apply editability as a display overlay (reuse
> `getEditableFieldsFromMappings`; keep the `:1112` persist gate); then **1c** —
> `CanonicalInkComposer::bakeInk` keyed on `data-recipient-identity`, rewire
> `completeWeb`. Verify each on the deployed qa1 ceremony with an N-party set
> (≥3, multi-seller + multi-buyer OTP fixture) — no seller_1/2 assumptions.
> Then §4 AT-300 reconciliation. Target: 1a+1b+1c on the bench by morning.
