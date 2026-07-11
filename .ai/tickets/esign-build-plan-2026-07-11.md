# E-Sign Build Plan — tonight's shift (Phase 0 + the mandate-ceremony lane)

> **Status:** AWAITING JOHAN'S WORD. No code starts until he approves this plan.
> **Crew:** m6 + m5. **Target:** another agency live-ready **1 August 2026**.
> **Derived from:** `.ai/audits/2026-07-11-esign-v3-gap-analysis.md` (the rebased gap table).
> **Doctrine:** `.ai/specs/esign-ceremony-v3.md` (canonical, `a346eba1` + `2cc8ca85` — decisions
> are **§15**; recipient sourcing is **§2.1**).
> **Gated on:** `.ai/qa/esign-contract-walk-test/` — the walk-test fires on qa1 tonight after the
> DR2 gate walk. **Phase 1 sizes are provisional until it passes.**

---

## How to read this

Each ticket is scoped to **one concern** (CLAUDE.md "one concern per prompt"), carries its **size**,
its **launch/post-launch** marking, and the **gap-table line** it closes. Phase 0 is
**independent of the walk-test** — it can start the moment Johan's word lands. Phase 1 is
**gated on the walk-test passing**.

**Sequencing rule:** Phase 0 tickets are all small, all independent, and several are live
exposures. **They go first, in parallel, and they do not wait for the walk-test.**

---

## PHASE 0 — legal holes and live bugs · **LAUNCH · start immediately**

These are defects in shipped code. Two are legal exposures. None depend on the ceremony work.

### P0-1 · Close the pack e-sign legal hole 🔴 **LAUNCH · S · start first**
**Gap #3 / contradictions C2 + C3.**

**The problem in business terms:** an **Offer To Purchase inside a web pack can currently be
e-signed.** South African law (ECTA §13(1) / Alienation of Land Act) says it cannot. The block
exists — it is just not applied to packs.

- `ESignWizardController::store()` scopes the block to single templates only:
  `if ($templateId && !$isPackFlow && !$pdfPackId)`. A pack flow sails past it.
- Web packs have **no e-sign eligibility computation at all** (PDF packs do — they grey out with
  *"Contains a wet ink document — not eligible for e-signature"*).

**Build:** apply `Template::isEsignBlocked()` across **every** template in a pack at flow creation
**and** at dispatch (`prepareSigning()`); compute `esign_eligible` for **web** packs the way
`PackController` already does for PDF packs; grey the pack in the wizard with the plain reason.
**Do not** silently drop the offending template (the PDF path's current habit) — refuse the pack
and say why.

**Done when:** a web pack containing an OTP/sale-agreement template cannot reach the e-sign wizard,
shows the plain-language reason, and the refusal is logged to `LegalBlockAuditLog`.
**Test:** feature test — web pack + blocked template → refused at both entry points.

---

### P0-2 · Sales-side reminders never fire 🔴 **LAUNCH · S**
**Gap #31.**

**The problem in business terms:** **every sales signing request goes silently un-nudged.** The
escalation ladder (gentle → firm → team-alert → final) works — but `SendSignatureReminders` filters
templates to `signing`, `awaiting_tenant`, `awaiting_landlord` only. **`awaiting_buyer` and
`awaiting_seller` are not in the list**, so a seller sitting on a mandate is never reminded and the
team is never alerted. This is live today and it is invisible.

**Build:** add the missing statuses (and audit the enum for any other omission — do not just patch
the two). While in there: `config/signatures.php` declares `max_email_reminders` (3) and the command
**never reads it** — the counts are hardcoded `< 1/2/3`. Wire the config value.

**Done when:** a seller/buyer request past the gentle threshold receives a reminder and increments
`reminder_count`; `max_email_reminders` is honoured from config.
**Test:** feature test per status, asserting the mail goes out and the counter moves.

---

### P0-3 · Per-page initial consent — make the gate real 🔴 **LAUNCH · S–M**
**Gap #14a / contradiction C8.**

**The problem in business terms:** the rule that **a client must initial each page individually**
(informed consent — each initial is an explicit affirmation) is the thing that makes an initial
legally meaningful. Today it is enforced **only by hiding the button.** `isAgent` withholds the
"Apply to All Pages" affordance in Alpine — but `capture()`, `saveFields()`, `saveWebFields()` and
`completeWeb()` contain **no server-side rejection**. A crafted client with a recipient token can
still POST N initials in a loop. The existing tests assert the affordance is *absent from the HTML*,
not that a bulk write is *refused*.

**Build:** server-side enforcement in the write path — a recipient (non-agent `party_role`) may not
place more than one initial per request per page; reject with a clear error and an audit row.
Keep the agent's apply-all (professional profile, ceremony §2 — that is doctrine, not a bug).

**Done when:** a scripted bulk-initial POST on a recipient token is **rejected server-side**, and a
test asserts the rejection (not merely the missing button).

---

### P0-4 · Make the audit log actually immutable **LAUNCH · S**
**Gap #32 / contradiction C7.**

**The problem in business terms:** the tracker is meant to be **evidence** we can put in front of a
principal or the Ombud (ceremony §6). `SignatureAuditLog` uses `SoftDeletes` and overrides nothing —
it is append-only *by convention*, not enforced. (`ESignConsentLog` gets this right: it throws on
`update()` and `delete()`.)

**Build:** mirror the `ESignConsentLog` pattern — drop `SoftDeletes`, throw on `update()`/`delete()`.
**Done when:** an attempt to mutate or delete an audit row throws; a test asserts it.

---

### P0-5 · Harden pack filing against silent degradation **LAUNCH · S**
**Gap #1.**

**The problem in business terms:** when `splitMergedHtml()` finds a fragment-count mismatch, it
**files the entire pack as ONE merged PDF** and moves on with a log warning. The agent sees a
completed signing; the Mandate, the FICA and the Disclosure are not separately filed. This has bitten
before (an attribute-order desync produced 0 fragments).

**Build:** turn the mismatch into a hard failure — do not file, alert the agent, log loudly.
Silent wrong-filing is worse than a visible stop.
**Done when:** a mismatch raises rather than degrades; a test asserts it.

---

### P0-6 · Remove the void-and-re-sign amendment landmine **LAUNCH · S**
**Hazard H1.**

**The problem in business terms:** Johan's amendment doctrine (retain prior marks, re-circulate,
all parties initial **only the new content**) is **already the shipped behaviour** —
`requeueAllPartiesForInitialing()` does exactly that. But the **legacy `handleAmendment()` still
exists** and sets previous signers back to `STATUS_PENDING` with fresh tokens — **a full re-sign
cascade**. `AmendmentController` doesn't call it, so the doctrine holds *today* — but it is one wrong
call from voiding every signature on a document, and **§11-A's revival is going to be built on this
very engine.** Clear the mine before standing on it.

**Build:** delete it, or fence it behind an explicit guard that throws. Trace all callers first
(CLAUDE.md: no removal without a full dependency trace).
**Done when:** no code path can re-open completed signers for a full re-sign on amendment.

---

## PHASE 1 — the mandate-ceremony lane · **LAUNCH · gated on the walk-test**

> **The rebase in one line:** the surface/role-block **engine is built and merged** (`4d5eb28c`,
> `1fe10836`) — what is missing is **content, not capability**. The mandate documents are not on the
> web/CDS path in **any** database, and the `data-role-block` backfill has **never been run**
> (0 templates carry it, anywhere). So Phase 1 is mostly **import and verification**, not
> engineering — which is why it parallelises well and why the estimate dropped from 6–8 to **4–6
> weeks**.

### P1-0 · **THE WALK-TEST** 🔴 **LAUNCH · S · conductor, tonight, on qa1**
`.ai/qa/esign-contract-walk-test/README.md`. **Not the finish line — the starting line.**
Everything below is provisional until it passes. Knife-edge signal: **zero**
`rendering unnormalised template via legacy clustering` log lines.

---

### P1-1 · Import EATS / Disclosure / FICA onto the web/CDS path **LAUNCH · M** *(was L)*
**Gap #7.**

**The problem in business terms:** web/CDS is **the** e-sign path (settled). But the documents agents
actually use — **EATS #27, OTP #25, FICA #33, Disclosure #30 — are all `render_type=pdf` overlays in
every database.** The only web templates on live and qa1 are **four copies of "Letting Mandate (V5)"**
(rentals). There is **no web/CDS EATS anywhere.** You cannot run a mandate e-sign ceremony on a
document that isn't on the e-sign path.

**Build:** import EATS, Disclosure and FICA through the CDS importer (which now stamps the contract
automatically). Verify per-role blocks, signature surfaces, and field mappings against
`.ai/specs/esign-field-intelligence.md` — the surface inventory is the acceptance list.

**Note:** the settled spec's *Sales Mandate Pack composition* names **templates 116 / 117 / 119**.
**Those do not exist in any database** (repo artifacts only). The pack must be composed from the
newly-imported documents — do not go looking for them.

**Done when:** each document exists as `render_type=web` with the contract stamped, and a
single-party ceremony completes and files correctly on each.

---

### P1-2 · Run the `data-role-block` backfill (and survive the deploy trap) **LAUNCH · S**
**Gap #7b.**

**The problem in business terms:** the contract is on **zero** templates — staging, qa1 *and* live.
Everything renders on the legacy clustering fallback. `02c8f5fb` only *documented* the backfill; it
was never run.

**⚠️ The trap (AT-162 class):** `docuperfect:normalize-templates` writes **two** places —
`editor_state.tagged_html` (**per-environment DB — does NOT travel on a `git pull`**) **and the blade
view file on disk** (**which IS git-tracked**). So: run **locally**, **commit the rewritten blades**,
and **re-run per environment** for the DB half. It also **silently skips templates with no
`tagged_html`**.

**Done when:** contract coverage is non-zero and verified on qa1 → Staging → (live on Johan's word);
the skip-list is recorded and dispositioned.

---

### P1-3 · Recipients from the PROPERTY-LINK ROLE 🔴 **LAUNCH · S · doctrine-critical**
**Gap #10 / ceremony §2.1.**

**The problem in business terms:** *"A seller in contacts can be linked to a property as a buyer"*
(Johan). Recipients must resolve from **the role the contact holds on *this* property**, not their
global contact type. Get this wrong and the ceremony **offers the wrong party** — and in a signing
ceremony the wrong party is not a cosmetic bug, it is **the wrong person signing the wrong side of a
legal instrument.**

**⚠️ Supersedes V2 §13 and re-points V2 §17 build-item 3.** The old spec says "wire
`contact_types.esign_role` into wizard Step 3" — **do not build that.** It is the exact model the
doctrine rejects.

**The mechanism is already loaded and thrown away:** `Property::contacts()` carries
`->withPivot('role')` (`Property.php:506`) and the wizard **already eager-loads it**
(`ESignWizardController:494`) — then ignores it and derives the role from the template's
`signing_parties`. **Work = read the pivot role that is already in memory.**

**Build:** primary source = property-link role; `esign_role` demotes to the **no-property-link
fallback**; manual add stays unfiltered. Match the DR2 precedent (`cc5a4cfd`, "parties by
property-link role") so a party means the same thing in Deals and in Documents.

**Done when:** a contact typed "seller" globally but linked to this property as a **buyer** is
offered as a **buyer**. Test asserts exactly that inversion.

---

### P1-4 · Web-pack slot resolution + compose the Sales Mandate Pack **LAUNCH · M**
**Gaps #2, #5.**

**Business:** a pack is an ordered set of **slots**; the agent resolves each slot to a **variant** at
send (Mandate → Open/Exclusive/Dual; FICA → natural/company/trust; Disclosure → sectional vs full
title). Today `web_pack_items` **already has** `slot_type`, `slot_group`, `slot_label` — **written by
the admin UI and never read.** Composition is a flat ordered list.

**Build:** server-side slot resolution in `ESignWizardController::store()` (copy the working pattern
from `PackController::resolveSelectableTemplates()`), plus the send-time variant picker. Compose the
Sales Mandate Pack from the P1-1 imports. **`web_packs` is canonical** — legacy PDF packs migrate.

---

### P1-5 · Editable fields on the mandate templates; templates ship empty **LAUNCH · M**
**Gap #8 / ceremony §11.1 (decision 3, §15).**

**Business:** the fee — and every "such value" — is an **editable field**, prefilled from the
deal/mandate and agent-overridable. **Templates ship empty-for-completion.** The "7.5% + VAT" seen
printed on a PDF was an agent's fill on that example, **not a template constant.**
Infrastructure exists (`field_mappings.editable_by` → inputs at signing) but **no template populates
it** — everything falls back to a static map. **Populate it for the mandate pack.**

---

### P1-6 · Amount-in-words: fix the lossy engine **LAUNCH · S**
**Gap #9 / ceremony §9.**

**Business:** every money field auto-generates its amount in words; the agent types the figure once.
It exists — `WebTemplateDataService::numberToWords()` — but takes an **`int`**: **cents are silently
dropped**, there is no currency word ("Rand"), no "and XX cents". On a legal instrument a
price whose words don't match its figure is a defect.

**Build:** cents, ZAR wording, one canonical key. (Note the CDS parser maps "Amount in Words" to a
binding `deal.amount_words` that is **not** the key the renderer produces — two vocabularies, one
concept. Reconcile.)

---

## Deferred within LAUNCH — decide at the cut line

| Ticket | Gap | Size | Note |
|---|---|---|---|
| **P2-1 · Party groups + checkpoint between groups** | #17 | M | Today: strictly one-at-a-time, checkpoint after **every** party — *more* friction than doctrine. Needed for the OTP two-act order. |
| **P2-2 · Signing Tracker screen + nav** | #29, #38 | M | No tracker exists (only "My Documents"). All substrate present. **Johan named tracker in the launch cut.** |
| **P2-3 · Evidence report** | #30 | M | Attributed timeline; depends on P0-4. |
| **P3-1 · OTP two-act + wet-ink pack** | #36 | M | Wet-ink portal/upload/agent-review already **built**. New: two-act order + countdown. |
| **P3-2 · Strike rendering into the document** | #11a | M | Both branches shown, excluded one struck — for the **printed** OTP. |
| **P4 · Lapse / extension / revival** | #24–28 | M–L | **The likeliest casualty.** Largest genuinely-new block in the launch cut. Reuse the amendment re-queue engine (after P0-6). If it slips, mandate expiry stays a human discipline — which is what it is today. |

> ### ✂ Cut line
> **Phase 0 + Phase 1 + P2-2 (minimal tracker) + P3-1** is the honest minimum for a second agency to
> go live: take mandates with e-signed EATS/FICA/Disclosure, run offers on paper through the existing
> wet-ink flow, and see where every document sits. **Revised estimate: 4–6 weeks for one lane** vs
> ~3 weeks of runway. Phase 4 goes first if something must go.

---

## POST-LAUNCH (unchanged from the gap table)

Disclosure two-stage re-serve (**L** — not buildable on the current lifecycle; needs its own spec) ·
juristic parties + signatories-as-contacts (**L** — no entity model exists) · per-surface consent
ledger (**L**) · witness routing (**M** — doctrine default is OFF, so today's behaviour is
accidentally correct) · strike-initialing gates (**M**) · individual-consent clauses (**M**) ·
FICA as a first-class pack slot (**M**) · gateway assurance tiers **or delete `security_tier`**
(**M/S** — it is a dead column today) · per-recipient delivery mode (**M**) · full agency threshold
panel (**M**).

---

## Proposed split for tonight

| Lane | Tickets | Why |
|------|---------|-----|
| **m6** | **P0-1** (legal hole) → **P0-6** (amendment landmine) → **P0-5** | The two legal/structural ones first; I hold the amendment-engine context and P4 will build on it. |
| **m5** | **P0-2** (reminders) → **P0-4** (audit immutability) → **P0-3** (consent gate) | Independent, small, well-bounded; m5 holds the conformance-audit context. |
| **conductor (m4)** | **P1-0 walk-test** on qa1 after the DR2 gate walk | Its result decides whether Phase 1 holds its size. |

**Nothing starts until Johan's word lands on this plan.**
