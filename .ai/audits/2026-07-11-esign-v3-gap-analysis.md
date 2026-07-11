# E-Sign Ceremony V3 — Gap Analysis & Launch Build Plan

> **Status:** investigation deliverable (no code written). For Johan's approval.
> **Question answered:** what does the V3 ceremony doctrine require, what does CoreX
> already have, and what must be built to get **another agency live-ready by 1 August 2026**?
> **Canonical sources (now committed to `main`):** `.ai/specs/esign-ceremony-v3.md` +
> `.ai/specs/esign-field-intelligence.md` at **`a346eba1`**; **§2.1 "Who the parties are"** +
> m5's conformance audit (`.ai/audits/esign-ceremony-v3-conformance-2026-07-11.md`) at
> **`2cc8ca85`**. Decisions are **§15** (renumbered from §16). This analysis cites those copies
> only — the recovered working copies are superseded.
>
> **Sources:** `.ai/specs/esign-ceremony-v3.md` (Johan's locked doctrine),
> `.ai/specs/esign-field-intelligence.md` (surface inventory),
> `.ai/specs/claude_esignature_v2_spec.md` (March-2026 code audit),
> `.ai/specs/esign-v3-complete-spec.md` (settled doctrine), and a fresh five-way map of
> the live codebase (2026-07-11, m6).
> **Author:** m6 (E-Sign crew).

---

## 0-A. REBASE (2026-07-11, after Johan's correction)

Johan settled the two open questions and named a fact the first pass missed. **He is right, and
the mandate lane shrinks — but not for the reason stated, and there is a hard prerequisite
nobody has done.** Verified on current `main` (HEAD `16945462`) and against the **staging, qa1
and live databases**:

**Settled (no longer open):**
- **Template family:** **web/CDS is THE e-sign path.** The PDF-image overlays are the
  wet-ink/stopgap family only. My original gap #7 ("L — nowhere to put a mark") sized the
  *PDF-overlay* world and was wrong about the e-sign path.
- **Canonical packs:** **`web_packs`.** Legacy PDF packs migrate. (My C5 recommendation was
  right; it is now a decision, not a question.)

**The contract stack is real and merged.** All three commits are on `main` **and** `Staging`:
`4d5eb28c` (CDS importer stamps the `data-role-block` contract on every import),
`1fe10836` (contract-driven renderer — `expandWithLooping()` now queries
`//*[@data-role-block]` first), `02c8f5fb` (the backfill). So **signature and role surfaces on
the web/CDS path ARE existing infrastructure**: `signature-line` / `signature-block` components
render one line per recipient from `recipients_by_role`, `SignatureSurfaceNormalizer` stamps the
marker attributes, and `RoleBlockExpansionService` clones each role block per recipient. **I was
sizing a build that is already done. That part of the lane collapses.**

**But three things the correction assumes are not true on the ground:**

1. **The contract is on ZERO templates — in every environment.** `02c8f5fb` is a **docs-only
   commit** (105 lines, `docs/esign/role-block-contract-backfill.md`); it *documents* a command
   Johan must run. **The backfill has never been run.** Queried directly:
   `data-role-block` appears in **0 of 67 templates on staging, 0 of 64 on qa1, 0 of 65 on live**,
   and in **0 blade views in the repo** (the command rewrites the blade file too, so a run would
   have shown up in git). **Every template today renders through the legacy clustering fallback**
   — which is still in the code, logging *"run `php artisan docuperfect:normalize-templates`
   to migrate this template"* on every render.
2. **The mandate documents are not on the web/CDS path in any database.** The only `render_type=web`
   templates on live and qa1 are **four copies of "Letting Mandate (V5)"** — rentals. There is
   **no web/CDS EATS, no web Disclosure, no web FICA anywhere.** The documents agents actually
   use — **#27 Shelly EATS, #25 OTP, #33 FICA, #30 Disclosure — are all `render_type=pdf` on live.**
   Seeded CDS versions exist in the repo (`exclusive-authority-to-sell.json` etc.) but were
   **never seeded to any environment**.
3. **Template 111 does not exist.** Not on live, not on staging, not on qa1 — not even
   soft-deleted. Same for **116, 117 and 119** (the ids the settled spec names as the *Sales
   Mandate Pack composition*). They survive only as **blade files in the repo and seeder JSON** —
   dev-machine artifacts that never reached a database. **The walk-test as written cannot run.**

**So the long pole changes shape rather than disappearing.** It is no longer *"build the surface
infrastructure"* (**L** → that is built). It is **"bring the mandate documents onto the path that
was built"** (**M**): import EATS / Disclosure / FICA through the CDS importer — which now stamps
the contract automatically — then verify. That is importer-and-verification work, not engine work,
and it is materially cheaper. **But it is a prerequisite with nothing behind it today, and it is
the real starting line — which is exactly why the walk-test matters.**

*(One correction to my own first pass: `compiled_serving` is **ON for 3 templates on staging** —
the "CoreX Reference 116/117/119 (compiled cutover)" reference-pack rows. It is **off on qa1 and
live**. My earlier "dark, only true in a test" was right for live, wrong for staging.)*

---

## 0. Headline

**The ceremony doctrine is closer to buildable than it looks, but it is leaning on four
things that do not exist, and it is hiding one genuinely large build.**

The good news is substantial. CoreX already has: one-ceremony-many-filings
(`splitMergedHtml` → per-document filing against property/contact/deal); a complete wet-ink
portal with agent review; an agent approval checkpoint; **amendments that already retain
prior signatures and re-circulate every party to initial only the new content** — which is
Johan's refinement B and the blast-radius answer, already shipped; an immutable consent log;
a rich audit trail; and an escalating reminder ladder.

The bad news is concentrated in four places:

1. **Every signature surface on the four documents agents use today is printed-only.** They
   are PDF page-image overlays with effectively zero signature fields, so a mandate e-sign
   ceremony has nowhere to put a mark. This is the single biggest launch cost — **but there is
   a lever**: seeded web/CDS versions of EATS, Disclosure and Marketing Permission already
   exist, and on that path the surfaces are HTML anchors the engine already stamps. Which
   family the mandate pack is built on is the most valuable decision Johan can make this week
   (see §5.1b).
2. **A legal hole:** the e-sign block that stops an Offer To Purchase being e-signed
   **is not applied to pack flows**. An OTP inside a web pack goes straight into the e-sign
   wizard. This is a live ECTA/Alienation-of-Land-Act exposure, and it is cheap to close.
3. **The lapse/extension/revival doctrine (§11-A) has nothing under it.** There is no legal
   deadline on a signing flow at all — only a 14-day link TTL.
4. **The Disclosure two-stage flow (§11.4) is not buildable on the current lifecycle.** Once
   a document completes it locks and files; there is no concept of re-opening it for a later
   wet-ink countersignature. This is the "exotic edge" and belongs post-launch.

**On the 1 August target:** the launch cut as Johan scoped it (mandate e-sign + wet-ink OTP
pack + tracker/evidence + lapse-extension-revival) is roughly **6–8 weeks of one lane**, not
three. §4 below gives a priority order and marks the line to cut at if the date is fixed.

---

## 1. Where the doctrine contradicts the code — flag these before building

These are places where the ceremony spec cites something as *already settled or built* and
the codebase disagrees. Each one changes a build estimate, so Johan should see them first.

| # | The spec says | The code says | Consequence |
|---|---------------|---------------|-------------|
| **C1** | §0.1: the signing gateway is governed by **`security_tier`** (standard / enhanced / high), and V3's consent profiles "build on" it. | **`security_tier` is a dead column.** All 7 references are storage plumbing (model fillable, template save, CDS-builder hidden inputs). **Zero business-logic reads.** The gateway enforces exactly one thing: an ID-number string match. The V2 audit's verdict ("stored but NEVER read — DEAD") is correct. | Gateway assurance tiers are **build-new**, not settled. Either build them (`OtpService` exists and is unwired — see C6) or **delete the field**. Do not plan around it. |
| **C2** | §0.1: an **`is_e_sign_blocked`** template "throws a `DomainException` if bypassed". | No such column or property exists. The real API is `Template::isEsignBlocked()`, and it returns a **422 / redirect** — no exception anywhere in the dispatch path. **Worse: the hard block in `ESignWizardController::store()` is explicitly scoped to single templates (`!$isPackFlow && !$pdfPackId`).** | **A sale agreement or OTP inside a web pack is not blocked.** It passes into the e-sign wizard. This is a legal exposure, not a nicety. Close it first. |
| **C3** | §0.1: "**Pack e-sign eligibility is COMPUTED from its templates** — any `is_esign=false` makes the whole pack wet-ink, greyed with the reason." | **True for PDF packs only** (`PackController` + wizard show a greyed pack with *"Contains a wet ink document — not eligible for e-signature"*). **Web packs — the path the e-sign wizard and `splitMergedHtml` actually use — have no eligibility computation at all.** Every web pack is always clickable. | The computed-mode promise is half-built, and it is missing on the half that matters. Pairs with C2. |
| **C4** | §0.1: "**mixed-mode per-recipient** — the agent may pick mode per recipient (seller wet-ink, agent e-sign)." | The column exists (`signature_requests.signing_method`) but is **system-forced, never agent-chosen**. The wizard has a **single flow-level radio** for delivery mode; recipient rows carry no mode field. | Per-recipient mode is an **extend**, not a given. It is also the mechanism §11.4 depends on. |
| **C5** | §0/§1: slot-based packs already exist as **`docuperfect_pack_slots`**. | Two pack systems exist. `docuperfect_pack_slots` is real — but it belongs to the **legacy PDF-pack system** (no `agency_id`). The **live web-pack path** has its own `slot_type` / `slot_group` / `slot_label` columns on `web_pack_items` — **written by the admin UI but never read at composition time**. Web packs are, at runtime, a flat ordered list. | **Johan decision needed: which pack system is canonical for V3?** The e-sign/CDS path is web packs. Recommendation: build slots on web packs; the data columns are already there. |
| **C6** | §10 / §0.1: the OTP ceremony runs the full doctrine (strike-initialing, in-ceremony amendment gates, visible countdown) "regardless of delivery mode" — while also being **wet-ink only**. | Wet-ink means the document is downloaded, printed and signed on paper. **Strike-initialing gates and in-ceremony amendment consent cannot run on paper.** | Partly aspirational. On a wet-ink OTP the doctrine reduces to what the *system* can do: agent-side preparation, **visible strikes rendered into the printed document**, the countdown, the tracker and the evidence trail. Say so explicitly, or the build will chase an impossible surface. |
| **C7** | §6: the tracker is an audit-ready **evidence** record; §13.2 (settled): the audit trail is immutable. | `SignatureAuditLog` **uses `SoftDeletes`** and overrides nothing. It is append-only *by convention*, not enforced. (By contrast `ESignConsentLog` genuinely throws on `update()`/`delete()`.) | If the tracker is going to be shown to a principal or the Ombud, the log backing it must be **actually** immutable. Cheap fix. |
| **C8** | §2 / FIX 2 (settled): recipients must initial **each page individually**; apply-to-all is agent-only. | The gate is **UI-deep only**. `isAgent` hides the Alpine affordance; `capture()` / `completeWeb()` contain **no server-side rejection**. A crafted client with a recipient token could still POST N initials in a loop. The tests assert the affordance is absent, not that a bulk write is refused. | The informed-consent guarantee — the thing that makes each initial legally meaningful — is not enforced at the API. Close it. |

**Also worth naming:** "OTP" is overloaded in this codebase — **Offer To Purchase** (the
document) and **One-Time Password** (`App\Services\Otp\OtpService`). They appear in the same
sentences constantly. Pick distinct words in tickets.

### 1.1 Three landmines found in passing (not doctrine gaps — live hazards)

| # | Hazard | Why it matters |
|---|--------|----------------|
| **H1** | **There are TWO amendment paths, and the legacy one voids signatures.** `requeueAllPartiesForInitialing()` is the good one (retains marks, re-initial only). But `SignatureService::handleAmendment()` still exists and sets previous signers back to `STATUS_PENDING` with a fresh token — **a full re-sign cascade**. `AmendmentController` only calls the initialing path, so the doctrine holds *today* — but the void-and-re-sign method is one wrong call away, and it directly contradicts refinement B. **Delete it or fence it before building on the amendment engine (it is also the engine §11-A revival should reuse).** |
| **H2** | **The entire AT-177 compiled pipeline serves zero production traffic.** `compiled_serving` is `true` in exactly one place: a test. No seeder or migration cuts any template over. Linter, golden harness, `CdsRenderer`, `CompiledSigningRenderer` are all built and tested and **dark** — every live document goes down the legacy compensator chain (freshness guard → surface normalizer → letterhead refresher → insertable blocks → role-block expansion). The ceremony spec assumes the compiler "produces the signable structure". It does not, yet. |
| **H3** | **A stale CDS draft outranks the saved template.** `Template::canonicalFieldMappings()` reads `cds_drafts` (status=`draft`) as **tier 1**, above `editor_state.mappings` and the `field_mappings` column. Its own docblock admits field truth "fans out across six divergent sources … with no canonical owner". An abandoned builder autosave can therefore silently govern what a live document does. |

---

## 2. The gap table

Verdicts: **EXISTS-AS-IS** (works today, verify only) · **EXTEND** (real foundation, named
gap) · **BUILD-NEW** (nothing under it). Sizes: **S** ≈ ≤1 day · **M** ≈ 2–4 days ·
**L** ≈ 1–2 weeks.

### 2.1 Packs, filing and delivery mode

| # | Requirement (ceremony §) | Verdict | What exists / what's missing | Size |
|---|--------------------------|---------|------------------------------|------|
| 1 | **One ceremony → many independent filings** (§1) — the pack is a delivery vehicle, each document files on its own name | **EXISTS-AS-IS** | `SignatureService::splitMergedHtml()` splits the *exact signed-and-paginated DOM* per `.corex-document-wrapper`; `filePackDocuments()` creates one `Document` per template with its own `document_type_id`, linked to contacts + property + deal (and auto-completes the matching deal pipeline step). **Caveat:** on a fragment-count mismatch it silently files the whole pack as ONE merged PDF with only a log warning. Harden to a hard failure. | S (harden) |
| 2 | **Slot-based packs — a slot resolves to a variant at send** (§1) | **EXTEND** | Slot columns already exist on `web_pack_items` (`slot_type`, `slot_group`, `slot_label`) and are written by the admin UI — but **nothing reads them at composition**; `ESignWizardController::store()` just maps items to templates in `sort_order`. The legacy PDF-pack system *does* have real slot resolution (`resolveSelectableTemplates()`) — the pattern to copy. Needs: server-side slot resolution + a send-time variant picker. **Blocked on the C5 decision.** | M |
| 3 | **Pack e-sign eligibility computed; blocked modes greyed with the plain reason** (§0.1, §12) | **EXTEND** | Built for PDF packs (greyed + reason string). **Absent for web packs**, and `isEsignBlocked()` is not applied to pack flows at all (C2/C3). | S |
| 4 | **Per-recipient delivery mode (mixed mode)** (§0.1) | **EXTEND** | `signature_requests.signing_method` (electronic/wet_ink) exists but is system-forced; wizard is flow-level. Needs a per-recipient control + plumbing. | M |
| 5 | **Sales Mandate Pack exists** (§10.2 settled) | **BUILD-NEW** | Not seeded. Only "Seller Onboarding" and "Marketing Permission" web packs exist. | S |
| 6 | **FICA is a first-class pack slot** (§0, §11.3) | **EXTEND** | FICA today is a **per-recipient toggle at wizard step 6** (`fica_required`), deliberately *not* a pack item. Doctrine wants it as a slot with natural/company/trust variants. | M |

### 2.2 Signature surfaces and fields — the foundation

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 7 | **Every signing surface is declared and injected** (§2, field-intel #1) — EATS §2.6/§2.7.1/§2.7.4 + final block; OTP purchaser/seller/witness/practitioner; FICA client+office; Disclosure seller+purchaser | **EXISTS-AS-IS (engine)** + **EXTEND (content)** — *rebased* | **The engine is built.** On the web/CDS path signature surfaces are HTML: `signature-line` / `signature-block` components render one line **per recipient** from `recipients_by_role`; `SignatureSurfaceNormalizer` stamps `data-marker-type` / `data-marker-party`; the **contract-driven `RoleBlockExpansionService`** (`1fe10836`) clones each `[data-role-block]` per recipient with no guessing. Nothing to build here. **What is missing is content, not capability:** the mandate documents (EATS, Disclosure, FICA) exist **only as PDF overlays in every database** — there is no web/CDS EATS anywhere. Work = **import them through the CDS importer** (which stamps the contract on import, `4d5eb28c`) and verify. | **M** (was L) |
| 7b | **The `data-role-block` contract is actually applied** (prerequisite for #7 and for any multi-party mandate) | **BUILD-NEW (run it)** — *new* | **0 of 67 templates on staging, 0 of 64 on qa1, 0 of 65 on live carry `data-role-block`.** The backfill (`docuperfect:normalize-templates`) has never been run; `02c8f5fb` only *documents* it. Every template renders on the **legacy clustering fallback** today. **Operational trap:** the command writes **two** places — `editor_state.tagged_html` (per-environment DB, does **not** travel on a `git pull`) **and the blade view file on disk** (which **is** git-tracked). So it must be run locally, the rewritten blades committed, *and* re-run per environment for the DB half — the AT-162 reference-data class all over again. It also **silently skips any template with no `tagged_html`** (which is all 3 staging reference templates). | **S (run) + S (deploy discipline)** |
| 8 | **Fee and all such values are editable fields; templates ship empty** (§11.1, answer 3) | **EXTEND** | Infrastructure is built (`field_mappings.editable_by` → `getEditableFieldsFromMappings()` → inputs at signing) but **no template populates it** — everything falls back to a static map. Needs the mandate-pack templates actually configured. | M |
| 9 | **Money fields auto-generate amount-in-words** (§9) | **EXTEND** | It exists, but it is hand-rolled and lossy: `WebTemplateDataService::numberToWords()` takes an **`int`** — **cents are silently dropped**, and there is no currency word ("Rand") or "and XX cents" suffix. It already feeds `price_in_words`, `rental_amount_words`, `deposit_amount_words`. Separately, the CDS parser maps the label "Amount in Words" to a binding `deal.amount_words` which is **not** the same key the renderer produces — two vocabularies for one concept. Needs: cents, currency wording, one canonical key. | S |
| 9b | **Y / N / N-A grid baked into the Disclosure so a statutory item cannot ship unanswered** (§11.4) | **BUILD-NEW** | **There is no N/A concept anywhere in the system** — no column, no field type, no code path (searched `n/a`, `not_applicable`, `is_na`). The Disclosure's statutory grid is exactly where it is needed, and the harvest found the grid is added per-document with columns missed. | M |
| 10 | **Recipients come from the PROPERTY-LINK ROLE** (ceremony **§2.1**, Johan's DR2 ruling) | **EXTEND** — *rebased; direction reversed* | ⚠️ **My first pass had this backwards.** I wrote "wire `ContactType::scopeForEsignRole()`" — but **§2.1 supersedes V2 §13 and re-points V2 §17 build-item 3**: recipients resolve from the **role the contact holds on *this* property**, not the global contact type. Wiring `esign_role` as primary would build the exact model the doctrine rejects. **The mechanism is already loaded and thrown away:** `Property::contacts()` carries `->withPivot('role')` (`Property.php:506`) and the wizard **already eager-loads it** (`ESignWizardController:494`) — then ignores it and derives the role from the template's `signing_parties` instead. Work = **read the pivot role that is already in memory**; demote `esign_role` to the no-property-link fallback; manual add stays unfiltered. **Doctrine-critical, not cosmetic** — a global-type filter offers the wrong party the moment a contact is a seller on one property and a buyer on another, and in a ceremony the wrong party is the wrong person signing the wrong side of a legal instrument. | S |
| 11 | **Strikes visible and initialed; one typed strike/branch behaviour** (§3) | **EXTEND** | `DocumentClauseStrikethrough` exists as a permanent (non-soft-deletable) legal record and `InsertableBlockRenderer` renders strikes; `ConditionInitial` gives immutable per-condition initials. But the recipient-facing propose flow was retired to clause-flagging (410), and there is **no typed both-branches-render-strike-the-loser model** driven from a CDS source. Split: **rendering** the strike into the document (launch, needed for the printed OTP) vs **the initial-the-strike consent gate** (e-sign side, post-launch). | M (render) + M (gate) |

### 2.3 Consent profiles

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 12 | **Consent capture (ECTA intent-to-sign)** | **EXISTS-AS-IS** | `ESignConsentLog` → `esign_consent_log`: genuinely immutable (throws on update/delete), stores the verbatim consent text, encrypted ID number, IP, user-agent, device info and a SHA-256 document hash. Strong. | — |
| 13 | **Agents sign on the professional adopt-once/apply-all profile** (§2, refinement A) | **EXISTS-AS-IS** | Shipped as FIX 2 — "Apply to All Pages" gated on `isAgent`, computed from token identity alone (a dispatching agent opening a recipient's link does *not* inherit the bypass). | — |
| 14 | **Clients gate per surface — every mark is a deliberate, recorded consent event** (§2) | **EXTEND** | Per-page initials exist as *placements*, and apply-to-all is correctly withheld from clients — but there is **no per-surface consent event record**, and the guarantee is **UI-deep, not API-deep** (C8). Two pieces: (a) server-side enforcement — small and legally load-bearing; (b) a per-surface consent ledger — larger. | S (enforce) + L (ledger) |
| 15 | **Individual-consent-required clauses** (e.g. letting termination, every co-tenant) (§2) | **BUILD-NEW** | No concept. Rentals-facing. | M |

### 2.4 Flow, groups and checkpoints

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 16 | **Agent checkpoint between parties** (§4) | **EXISTS-AS-IS** | `handlePartyCompletion()` → `pending_agent_approval` → `approveAndAdvance()`, with review + return-to-party-with-notes. The gate already sits between **every** external party. | — |
| 17 | **Group-sequential flow — groups, sequential within, checkpoint between groups, order configurable per pack** (§4) | **EXTEND** | Today is **strictly one-signer-at-a-time** by integer `signing_order`; there is **no group concept anywhere** (searched: `signing_group`, `party_group`, `group_order` — zero hits). Note today's behaviour is *more* friction than the doctrine (a checkpoint after every single party, not after each group). Needs a group column + group-aware advance + per-pack config. | M |
| 18 | **Witnesses — optional role, default off, named** (§8) | **EXTEND** | `witness` is accepted in the wizard's role alias map but has **no status mapping** (routes as generic signing); in the older `saveParties()` path a witness gets **no request, no token, no email at all**. Default-off means today's behaviour is *accidentally* the safe default. | M |
| 19 | **Juristic parties — entity + 1..n authorised signatories, signatories become CoreX Contacts** (§7, refinement D) | **BUILD-NEW** | **No entity/company/trust concept on `contacts`, and no contact-to-contact relationship table.** Authority documents in the pack, FICA entity variants, and "which known person signed for the company" all rest on this. Genuinely large. | **L** |

### 2.5 Amendments

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 20 | **Amendment retains prior marks, re-circulates, all parties initial only the new content** (§5, refinement B + blast-radius answer 2) | **EXISTS-AS-IS** | This is the pleasant surprise. `requeueAllPartiesForInitialing()` flips the flow to `STATUS_AMENDMENT_INITIALING`, re-queues **every** previously-completed signer with a fresh token, and the code comment is explicit: *"existing request rows are re-issued — signed_at / original signatures NOT touched"*, with the email telling the party *"your original signature stays in place."* `ConditionInitial` is immutable (throws on re-save). Johan's doctrine is already the shipped behaviour. | — (verify) |
| 21 | **Attention drawn to the change — highlight + navigate, no hunting** (§5) | **EXTEND** | The focused initialing surface exists (`amendment-review`), but there is no highlight-and-scroll-to-the-changed-clause treatment. | S |
| 22 | **Any party may propose an amendment** (§5, §12) | **EXTEND** | Recipients can add conditions and flag clauses; both create `DocumentAmendment` rows. But V2 notes clause flags **do not** yet create amendments in every path, and there is no configurable "which parties may propose". | M |
| 23 | **Full version audit — every mark bound to the version it was placed against** (§5) | **EXTEND** | `signed_document_versions` + `signature_audit_log` + per-advance document hashing exist; binding each *mark* to a *version* does not. | M |

### 2.6 Deadlines, lapse and revival (§11-A)

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 24 | **A signing flow has a legal deadline** (mandate expiry / OTP irrevocable-until) | **BUILD-NEW** | There is **no deadline field on a signing flow**. The only expiry is `signature_requests.token_expires_at` — a **14-day link TTL, hardcoded** (`config/signatures.php`'s `expiry.default_days` is declared and **never read**). A link TTL is not a legal date. | M |
| 25 | **Hard block — signing on a lapsed document is refused** (§11-A.1) | **EXTEND** | The *machinery* is excellent and reusable: `isExpired()` is checked at ~20 entry points; GETs render an expired view, POSTs return **410**. It simply keys off the wrong clock. Re-point it at the legal deadline. | S |
| 26 | **Lapse / extension_proposed / revived / re-lapsed as first-class states** (§11-A.3) | **BUILD-NEW** | The status enum is already rich (20 values incl. `amendment_initialing`, `pending_agent_approval`) — these four are simply absent. | S |
| 27 | **Extension = visible strike-and-fill date change, all parties initial to revive** (§11-A.2) | **BUILD-NEW** | Nothing: no extend, revive, reactivate or grace-period concept (searched). **But the vehicle already exists** — an extension *is* an amendment, and `requeueAllPartiesForInitialing()` already does "all parties re-initial the new content". Build it as a special amendment type rather than a new engine. | M–L |
| 28 | **Visible countdown to the irrevocable deadline, seen by all parties** (§10, §4) | **BUILD-NEW** | Nothing. Depends on #24. | S |

### 2.7 Tracker, evidence and notifications (§6)

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 29 | **Live tracker — current signer, time-in-state, nudge, one row per ceremony** | **BUILD-NEW (screen)** | No tracker screen exists — only "My Documents". **The substrate is all there**: status, `sent_at` / `viewed_at` / `completed_at`, `reminder_count`, plus `resendNotification()` and `sendManualReminder()`. This is a UI build over existing data. | M |
| 30 | **Evidence report — attributed, timestamped timeline; proves who sat past the deadline** | **BUILD-NEW (report)** | `signature_audit_log` is a strong substrate (actions incl. `viewed`, `sent`, `reminder_sent`, `expired`, wet-ink events; actor type/IP/user-agent; per-advance document hash). Two gaps: the report itself, and **the log is soft-deletable** (C7) — fix immutability before calling it evidence. | M + S |
| 31 | **Nudge / reminder cadence** | **EXISTS-AS-IS + BUG** | `SendSignatureReminders` runs a proper escalation ladder (gentle→firm→team-alert→final) off `config/signatures.php`. **But the command only matches templates in `signing` / `awaiting_tenant` / `awaiting_landlord` — so `awaiting_buyer` and `awaiting_seller` flows are NEVER reminded.** The entire sales side is silently un-nudged today. Also `max_email_reminders` is declared and never read (counts are hardcoded). | S (fix) |
| 32 | **Immutable audit trail** | **EXTEND** | See C7 — `SignatureAuditLog` uses `SoftDeletes`. | S |

### 2.8 Per-document choreography (§11)

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 33 | **EATS mandate ceremony** — sellers → agent; declared surfaces; strikes; prefill; editable fee | **EXTEND** | Composite of #7 (surfaces), #8 (editable fields), #11 (strikes), #17 (groups). The flow engine itself is ready. | — (rolls up) |
| 34 | **FICA — client zone then office zone via the existing FICA module** (§11.3, answer 4) | **EXISTS-AS-IS** | Correct as specced: `fica_required` per recipient, gate lifts on **submission**, then `FicaSubmission` (risk rating, verification method) → agent approval → `FicaComplianceOfficer` approval. Referenced, not redesigned — exactly the doctrine. Only the pack-slot framing (#6) is new. | — (verify) |
| 35 | **Disclosure — ONE document, TWO stages: seller e-signs at mandate, purchaser wet-ink countersigns inside the sales pack** (§11.4, answer 1) | **BUILD-NEW** | **Not present, and not buildable on the current lifecycle.** Once `completeDocument()` fires the flow is `completed`, locked and auto-filed; there is no re-open for a later stage. Today this would be two separate documents. Needs a genuine stage concept (and depends on #4 per-recipient mode). **This is the exotic edge — post-launch.** | **L** |
| 36 | **OTP — two-act offer→acceptance, wet-ink, under a visible countdown** (§10, §11.2) | **EXTEND** | The wet-ink half is **strong and already built**: recipient portal, download-with-markers, upload (multi-file, versioned, audit-logged), and a real agent review/approve step with a marker checklist — on approval the scan *replaces the flattened pages* so the next party sees the physical signatures. What's new: the two-act group order (#17), the countdown (#28), and strike-rendering into the printed document (#11). | M |

### 2.9 Configuration and navigation

| # | Requirement | Verdict | What exists / what's missing | Size |
|---|-------------|---------|------------------------------|------|
| 37 | **Agency-configurable thresholds** (§12) | **EXTEND** | `config/signatures.php` is **global, not per-agency**. Doctrine wants ~14 knobs per agency. Launch needs only a couple (reminder cadence, checkpoint mode). | M |
| 38 | **Nav: Documents → Packs / New Signing / Signing Tracker** (§13, non-negotiable #2) | **EXTEND** | Packs admin and the wizard have entries; **the tracker does not exist**, so it needs one the day it ships. | S |
| 39 | **Permissions** (non-negotiable #5) | **EXISTS-AS-IS** | A full DocuPerfect permission section already exists (`access_docuperfect`, `access_docuperfect_packs`, `documents.*`, `templates.*`). New screens need keys added. | S |
| 40 | **Signing gateway assurance tiers** (§0.1) | **BUILD-NEW or DROP** | `security_tier` is dead (C1). If Johan wants OTP-verified signing, `OtpService` is the canonical engine — but it is **email-only** (no SMS transport) and **completely unwired from e-sign**. Otherwise delete the field. | M (or S to drop) |

---

## 3. What is *not* buildable as written

- **§11.4 Disclosure two-stage** (#35). One document carrying an e-signed stage and a later
  wet-ink stage cannot sit on the current lifecycle — completion locks and files. This needs a
  new stage concept, not an adjustment. Post-launch, and it deserves its own spec.
- **§10 OTP running the full ceremony "regardless of delivery mode"** (C6). Strike-initialing
  gates and in-ceremony amendment consent cannot execute on a printed page. The wet-ink OTP
  gets: agent prep, strikes *rendered* into the document, the countdown, the tracker, the
  evidence trail. Say this in the spec so nobody builds toward a surface that can't exist.
- **§0.1's `security_tier` gateway tiers** (C1) — the cited foundation is inert.

---

## 4. Proposed build order — LAUNCH (target 1 August) vs POST-LAUNCH

**Reality check first.** LAUNCH below is ~**6–8 weeks for one lane**; the fixed date is ~3
weeks away. The order is therefore strict priority, with an explicit **cut line**. Everything
above the cut line makes a second agency able to *take mandates and handle offers*. Below it
is doctrine completeness.

### LAUNCH — Phase 0: legal holes and live bugs (do these first; all small)

| # | Item | Why it's first | Size |
|---|------|----------------|------|
| L0.1 | **Apply `isEsignBlocked()` to pack flows + compute web-pack e-sign eligibility** (C2, C3, gap #3) | An Offer To Purchase inside a web pack can currently be e-signed. That is an Alienation-of-Land-Act exposure sitting in production. | S |
| L0.2 | **Fix the reminder blind spot** (gap #31) | `awaiting_buyer` / `awaiting_seller` flows are never reminded — the whole sales side goes un-nudged today. | S |
| L0.3 | **Server-side enforcement of per-page initials / apply-to-all** (C8, gap #14a) | The informed-consent guarantee is UI-deep only. It is the thing that makes each initial legally meaningful. | S–M |
| L0.4 | **Make `SignatureAuditLog` genuinely immutable** (C7, gap #32) | You cannot call it evidence while it is soft-deletable. | S |
| L0.5 | **Harden `splitMergedHtml` fragment-count mismatch to a hard failure** (gap #1) | Today a mismatch silently files a whole pack as one document with a log warning. | S |
| L0.6 | **Delete or fence the legacy `handleAmendment()` void-and-re-sign path** (H1) | It contradicts refinement B outright, and §11-A's revival is going to be built on the amendment engine — clear the landmine before standing on it. | S |

### LAUNCH — Phase 1: the mandate ceremony foundation *(rebased — was the long pole, now mostly content work)*

| # | Item | Size |
|---|------|------|
| **L1.0** | **THE WALK-TEST (§6) — run it first.** It is the starting line, not the finish. It proves the contract engine end-to-end on a real subject and tells us what Phase 1 actually costs. | S |
| L1.1 | **Import EATS / Disclosure / FICA onto the web/CDS path** through the CDS importer (stamps the contract automatically) + verify surfaces and role blocks (gap #7) — *engine is built; this is content* | **M** (was L) |
| L1.1b | **Run the `data-role-block` backfill and commit the rewritten blades** (gap #7b) — plus the per-environment DB half; watch the AT-162-class deploy trap | S |
| L1.2 | **Web-pack slot resolution at send + compose the Sales Mandate Pack** (gaps #2, #5) — *note the spec's stated composition (templates 116/117/119) does not exist in any DB; the pack must be composed from the newly-imported documents* | M |
| L1.3 | **Populate editable fields on the mandate-pack templates; templates ship empty** (gap #8) | M |
| L1.4 | **Amount-in-words derivation** (gap #9) | S |
| L1.5 | **Contact filtering by `esign_role`** (gap #10) | S |

### LAUNCH — Phase 2: groups, tracker, evidence

| # | Item | Size |
|---|------|------|
| L2.1 | **Party groups + checkpoint between groups, configurable per pack** (gap #17) | M |
| L2.2 | **Signing Tracker screen** — current signer, time-in-state, nudge (gap #29) + nav entry (#38) | M |
| L2.3 | **Evidence report** — attributed timeline (gap #30) | M |

### LAUNCH — Phase 3: the wet-ink offer flow

| # | Item | Size |
|---|------|------|
| L3.1 | **OTP two-act group order + wet-ink pack** (gap #36) — the wet-ink portal, upload and agent review already exist | M |
| L3.2 | **Strike rendering into the document** (gap #11a) — both branches shown, the excluded one struck, for the printed OTP | M |

### LAUNCH — Phase 4: lapse, extension, revival (Johan named this in the cut)

| # | Item | Size |
|---|------|------|
| L4.1 | **Legal deadline on a signing flow** (mandate expiry / irrevocable-until) (gap #24) | M |
| L4.2 | **Re-point the existing hard-block at the legal deadline** (gap #25) | S |
| L4.3 | **Lapse / extension_proposed / revived / re-lapsed states** (gap #26) | S |
| L4.4 | **Strike-and-fill extension amendment → all parties initial → revive** (gap #27) — build as a special amendment on the existing re-queue engine | M–L |
| L4.5 | **Visible countdown** (gap #28) | S |

> ### ✂ Cut line
> If the 1 August date is immovable, **Phases 0 + 1 + a minimal L2.2 tracker + L3.1** is the
> honest minimum for a second agency to go live: they can take mandates with e-signed
> EATS/FICA/Disclosure, run offers on paper through the existing wet-ink flow, and see where
> every document sits. **Phase 4 (lapse/revival) is the most likely casualty** — it is the
> largest genuinely-new block in the launch cut. If it slips, mandate expiry stays a human
> discipline for a few more weeks, which is what it is today.

### Re-estimate after the rebase

The mandate lane **does shrink materially** — Johan is right. Phase 1's long pole drops from
**L to M** because the surface/role-block engine is already built and merged; what remains is
importing the documents onto it and running a backfill that was never run.

**Revised: roughly 4–6 weeks for one lane** (was 6–8). That is still more than the ~3 weeks to
1 August, so the cut line stands — but the shape is healthier: **Phase 1 is now mostly content
and verification, not engineering**, which parallelises far better across the crew than a
foundational build would have.

**The estimate is conditional on the walk-test.** Everything above assumes the contract engine
behaves on a real multi-party document. It has never been proven end-to-end — the test that was
supposed to prove it never ran, and its subject (template 111) does not exist. **If the walk-test
surfaces defects in the contract path, Phase 1 re-inflates.** That is the single largest
remaining uncertainty in the plan, and it is answerable in a day.

---

## 6. The WALK-TEST

**Moved — one source of truth.** The walk-test is now a ready-to-run conductor task:
**`.ai/qa/esign-contract-walk-test/README.md`** (plan of record, Johan 2026-07-11; fires on qa1
tonight after the DR2 gate walk).

It proves the contract engine end-to-end on a **freshly imported EATS** (not template 111 — that
does not exist in any database). Six proof points: importer stamps the contract automatically ·
two-seller session renders per-party role blocks · recipient sees their own block highlighted and
is denied cross-party writes · agent checkpoint fires and the document files correctly · a second,
**previously-unseen** document does all of it with **no per-document code changes** · the backfill
dry-run behaves.

**Knife-edge pass/fail signal:** zero `RoleBlockExpansionService: rendering unnormalised template
via legacy clustering` log lines.

**Every Phase-1 estimate in this document is provisional until it passes.** The build plan derived
from this analysis is `.ai/tickets/esign-build-plan-2026-07-11.md`.
