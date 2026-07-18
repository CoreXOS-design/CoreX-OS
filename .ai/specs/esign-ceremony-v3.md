# CoreX OS — E-Sign Ceremony V3 (Doctrine Chapter)

> **This is a chapter extending `.ai/specs/claude_esignature_v2_spec.md`** — it does not
> replace it. V2 describes the template/wizard/signing machinery as it stands; this chapter
> defines the **signing ceremony**: how a pack of documents is assembled, presented to the
> parties, signed, approved, amended, filed, and tracked.
>
> **Reads with:** `.ai/specs/esign-field-intelligence.md` (the per-document field / strike /
> signature-surface inventory this chapter choreographs — cross-referenced throughout) and
> `.ai/specs/esign-document-compiler-spec.md` (AT-177, the CDS compile engine that produces the
> signable structure).
>
> **Status:** doctrine locked by Johan, 2026-07-11 (this document captures it verbatim, in
> business language). Draft for Johan's read. No tickets, no code.
> **Author:** cc3.

---

## 0. What this extends (so nothing is reinvented)

Johan's ceremony doctrine formalises primitives CoreX already has. Naming them up front keeps
the build honest — this is an extension, not a greenfield:

| Doctrine concept | Already exists as | This chapter adds |
|------------------|-------------------|-------------------|
| Slot-based packs | `docuperfect_pack_slots` (`sort_order`, `slot_type` = required/selectable/attachment, `allow_multiple`, `is_optional`) | **variant resolution at send** — a slot resolves to a chosen template variant per ceremony |
| One ceremony → many filed docs | `autoFileSignedDocument()` splits the merged output at `.corex-document-wrapper` and creates **separate `Document` records** with each document's own `document_type_id`, linked to contacts + property (V2 §8) | preserved exactly — the ceremony is one flow; filing stays per-document, on each document's own name |
| Agent approval between signers | the **Agent Approval Gate** — after a party signs, the document returns to the agent (`pending_agent_approval`), who reviews then `approveAndAdvance()` (V2 §5) | promoted to a **checkpoint between party-groups**, configurable per pack |
| Signature/initial surfaces | markers/zones/coordinate system (V2 §6); note the field-intelligence finding that today's four templates carry **no signature overlay fields** — surfaces are printed-only | every surface becomes a **declared, gated consent action** |
| FICA gate | `fica_required` gate before signing (V2 §5, §9) | unchanged; slots make FICA a first-class pack member |

**The one-line doctrine:** *a pack is an ordered set of slots; each slot resolves to a variant
at send; the parties sign one ceremony in configurable groups with an agent checkpoint between
each; every client mark is a deliberate per-surface consent (agents sign on a professional
adopt-once profile); strikes are visible; any party can propose an amendment that re-circulates
the document — prior marks retained, attention drawn to the change, changed sections
re-initialed; a document dies at its legal date — signing on a lapsed ceremony is hard-blocked,
and the sole way back is a visible strike-and-fill date extension that every party initials;
the tracker is audit-ready evidence of who held the document and for how long; and
the whole thing files as many independent documents on their own names.*

> **Refinements applied 2026-07-11 (pm), Johan — these override where they touch:** (A) two
> consent profiles — per-surface gating is for **clients**; **agents** sign adopt-once-apply-all
> (professional profile), same for internal candidate→full approvals (§2). (B) amendments **do
> not void** prior marks — retain + re-circulate with attention drawn to the change; **all parties**
> re-initial the changed content (blast radius per the final addendum, answer 2) (§5). (C) the tracker is **evidence** — audit-ready attribution,
> lapse events first-class, a lapsed offer provably shows which party sat past the deadline (§6).
> (D) juristic signatories **become CoreX contacts** linked to the entity (§7).
>
> **Final addendum 2026-07-11 (late), Johan — settled-history reconciliation + §15 answers:**
> this chapter now **cites and extends the settled e-sign doctrine** (V2 §17–§19 + the March–May
> 2026 redesign in `esign-v3-complete-spec.md`) rather than re-inventing it — see **§0.1**. All
> five open questions are **answered** (now §15 Decisions): (1) Disclosure = one document, two
> signing stages, one e-signable + one wet-ink, across the mandate/sales boundary (§11.4); (2)
> amendment blast radius = **all parties, all surfaces, always; no auto-added marks ever** (§5);
> (3) fee and all such values are **editable fields, templates ship empty-for-completion** (§11.1);
> (4) FICA office section **mirrors the existing FICA module** (§11.3); (5) the **Expiry & Revival
> doctrine — the rules of war** (refinement E, new **§11-A**): hard lapse at the legal date,
> signing blocked on a lapsed ceremony, and a visible **strike-and-fill date extension** that all
> parties initial to **revive** it.

---

## 0.1 Reconciliation with settled e-sign doctrine (cite, don't reinvent)

Much of what this chapter needs is **already decided and partly built** in the settled specs.
This chapter **extends** those decisions; it does not restate or replace them. The load-bearing
settled doctrine and where V3 leans on it:

| Settled decision | Where it lives | How the ceremony extends it |
|------------------|----------------|-----------------------------|
| **ECTA §13(1) — sale agreements / OTPs cannot be e-signed; wet-ink only.** The wizard **greys the e-sign option** with the plain reason ("Sale agreements and offers to purchase cannot be e-signed under South African law — please use wet-ink delivery"); an `is_e_sign_blocked` template throws a `DomainException` if bypassed. | `esign-v3-complete-spec.md` §5 (ll. 38-40, 365-375); V2 §1 legal boundaries | The ceremony's **delivery mode is not free** — it is **computed** from the pack's templates (below). The OTP two-act ceremony (§10) therefore runs in **wet-ink mode**, not e-sign. |
| **Pack e-sign eligibility is COMPUTED from its templates.** Any template with `is_esign=false` in a pack makes the **whole pack wet-ink/download only** — shown greyed in the wizard with the plain reason. | `esign-v3-complete-spec.md` (delivery modes, ll. 137, 169, 283-286); web_packs (ll. 816-839) | Slot resolution (§1) feeds eligibility: resolve a wet-ink-only variant into a slot and the **pack's mode downgrades**, visibly, with the reason. The agent never has to know the law — the system computes and explains. |
| **web_packs / web_pack_items + `splitMergedHtml()` = one session, many filings.** A pack signs as one flow and files as separate `Document` records per template (V2 §8, §19). "Document pack system fully built — no further work needed." | `esign-v3-complete-spec.md` (ll. 816-839); V2 §19; `SignatureService::splitMergedHtml` | This is exactly §1's "one ceremony → many independent filings." V3 adds slot→variant resolution on top; it does **not** touch the split/file machinery. |
| **Mixed-mode handoff — per-recipient delivery mode; wet-ink portal = download-print-sign-upload.** An agent may pick mode per recipient (e.g. seller wet-ink, agent e-sign); the wet-ink party downloads, signs physically, and **uploads via the portal**, and the agent reviews the uploaded copy → approves. | `esign-v3-complete-spec.md` §5 (ll. 286, 721-733) | This is the mechanism behind **one document carrying an e-signed stage and a wet-ink stage** (§11.4, answer 1). A ceremony chain can legitimately mix e-sign and wet-ink handoffs. |
| **Signing gateway tiers + consent capture.** `security_tier` (`standard / enhanced / high`) governs the signing gateway; consent is captured explicitly (informed-consent per-page initials; consent-gated flag-removal with a token'd recipient authorise/reject, retained in the audit chain forever). | `esign-v3-complete-spec.md` (`security_tier` ll. 169-190; consent flows ll. 1388-1390) | V3's consent profiles (§2) and evidence tracker (§6) build **on** this — the gateway tier is the ceremony's assurance level; consent-capture is the substrate the gate-per-surface writes to. |
| **Apply-to-all is agent-only; recipients initial each page individually** (informed-consent). Already built (FIX 2): the "Apply to All Pages?" affordance is gated on `isAgent`. | `esign-v3-complete-spec.md` (l. 1386) | This **is** refinement A (§2) — already settled and shipped. V3 names it as doctrine (professional profile = apply-all; client profile = gate-per-surface) and generalises it to internal candidate→full approvals. |
| **Amendment = all parties initial only the new content, not re-sign the whole document** ("matches SA wet-ink practice exactly"). | `esign-v3-complete-spec.md` (l. 612); V2 §10 amendment/flag system | This **is** the settled reconciliation of §5 (answer 2): retain prior marks (refinement B), all parties re-initial (blast radius), but only the new content. V3 adds the strike-and-fill rendering and the void-nothing guarantee. |
| **FICA module** — `FicaSubmission` (risk_rating 1-3, verification_method), status `submitted → agent_approved (Awaiting CO Approval) → approved`, `FicaComplianceOfficer`. | `app/Models/FicaSubmission.php`, `FicaComplianceOfficer`; V2 §9 | The FICA slot's office/verification stage (§11.3, answer 4) **is** this flow — referenced, not redesigned. |
| ⚠️ **Recipient sourcing — the ONE settled decision this chapter OVERRIDES, not extends.** V2 says recipients are filtered by the contact's **global** type (`contact_types.esign_role` = seller/buyer/lessor/lessee), and queues the build. | V2 **§13** "Contact Filtering"; V2 **§17** Build Priority **item 3** ("wire esign_role into wizard Step 3") | **SUPERSEDED by §2.1** on Johan's DR2 ruling (2026-07-11): recipients resolve from the **property-link role**, not the global contact type; `esign_role` demotes to a fallback where no property link exists. **V2 §17 item 3 must be re-pointed before any build wires recipient sourcing.** |

> **Read the table above as an inheritance rule:** every row except the last says *extend the settled
> decision*. The last row is the single **override** — where this chapter deliberately reverses settled
> doctrine. Anything not named here is inherited from the settled specs unchanged.

---

## 1. Packs are slot-based

**Doctrine.** An admin defines a **pack template** as an ordered list of **slots** — e.g.
*Mandate · FICA · Mandatory Disclosure · docN*. A slot is a *role in the pack*, not a fixed
document. At **send time**, the agent **resolves each slot to a concrete variant**:

| Slot | Resolves to (variant) | Source of variants |
|------|-----------------------|--------------------|
| **Mandate** | Open · **Exclusive** (Sole) · Dual | the mandate-type decision on the deal/listing |
| **FICA** | **Natural person** · On-behalf-of (representative) · Company · Trust · Partnership | the party's legal nature |
| **Mandatory Disclosure** | version (V7…) · **SECTION-TITLE vs FULL-TITLE** | property tenure (sectional vs freehold) — see field-intelligence §Disclosure |
| **docN** | any further template or attachment | the pack template |

The pack template fixes the **order and the required/optional/selectable nature** of each slot
(the existing `slot_type` + `is_optional` + `allow_multiple` columns already express this). The
agent's send-time job is variant resolution, not authoring.

**One ceremony out, one process back, many docs filed.** The resolved pack goes out as **one
signing ceremony**. It returns as **one process** (one status, one audit trail, one tracker
row). On completion it **files as many independent documents**, each on its own name, its own
`document_type_id`, filed against the property and the correct contacts — **exactly the current
CoreX filing behaviour** (`autoFileSignedDocument()`, V2 §8). The Mandate files as the Mandate;
the FICA files as that person's FICA; the Disclosure files as the Disclosure. The pack is the
*delivery vehicle*, never a merged mega-document in the filing cabinet.

**Agency-configurable.** Pack templates are admin-defined and agency-scoped (global pack
templates may be provided as a CoreX standard set, overridable per agency). Slot order, which
slots are required vs optional vs selectable, and default variants are all configuration.

**Navigation.** Pack templates are administered under **Documents → Packs** (admin); agents
launch a ceremony from **the deal / listing** (send a pack) and from **Documents → New Signing**.
Every screen carries a nav entry the day it ships (non-negotiable #2).

---

### 1.1 The mechanism is the existing WEB-PACK system — not a seeded pack (Johan, 2026-07-15)

**Ruling.** The slot model above is not a thing to build — it is the **web-pack system already in
Documents**, which predates Phase 1 and was made for exactly this. A pack is composed by a human,
through the builder, as agency data; it is **never seeded from a template import**, and in
particular never from a walk-test document.

| Concern | Where it already lives |
|---|---|
| Build / edit a pack (full CRUD, soft-delete, permission `access_docuperfect_packs`) | `WebPackController`; **Documents → Web Packs** (`docuperfect.web-packs.*`), sidebar entry present |
| Set each slot's nature — **required · selectable(one-of, grouped) · optional** — with a label | `resources/views/docuperfect/web-packs/form.blade.php` (`slot_type` / `slot_group` / `slot_label`) |
| Offer the picks to the agent at send (radios for a selectable group, checkbox for optional) | `esign/wizard.blade.php` step 1 (`packSlots` / `slotSelections`) |
| Resolve the picks server-side (pack-membership, required-always, exactly-one-per-group, re-run eligibility on the RESOLVED set) | `WebPackSlotResolver` (HD-2), proven at the HTTP boundary (HD-3) |

**The canonical Sales Mandate Pack** (Johan's composition, verbatim) — an agency builds this ONCE
in the web-pack builder; every send then offers the choices:

| Slot | `slot_type` | Group | The agent's send-time choice |
|---|---|---|---|
| **Mandate** | `selectable` | A | **Open OR Exclusive** (sole) — one of |
| **Mandatory Disclosure** | `required` | — | always included, not the agent's to drop |
| **FICA** | `selectable` | B | **which FICA is applicable** — one of (Natural person / Company / Trust …) |

The composition is therefore the AGENT'S CHOICE at send time, expressed through the web-pack
picker — not a fixed bundle, and not something a lane hard-codes. A CoreX-standard starter pack
MAY later be provided as global reference data (overridable per agency), but that is a distinct,
Johan-authorised decision; the mechanism does not depend on it.

---

## 2. Two consent profiles — adopt-once for all, gate-per-surface for clients

**Doctrine (refinement A).** **The consent profile is a property of the signer role, not of the
ceremony.** Every signer adopts their signature and initials once at entry; *how those adopted
marks are then placed* depends on the role's profile:

- **Client profile (parties) — gate-per-surface.** For a client party, **every required surface
  is a deliberate gate**: at each page/section/clause that needs them, the party performs a
  **consent action** — *"by clicking you agree to this page / this section"* — and that action
  **places the adopted mark** on that surface. Adopting does **not** stamp the mark everywhere;
  each placement is its own recorded, timestamped consent event. Silent "apply to all" is
  **forbidden** for clients — it would destroy the evidentiary chain that each surface was
  individually agreed.
- **Professional profile (agents) — adopt-once, apply-all.** An agent/practitioner signs on a
  **professional profile**: adopt once, and the ceremony **applies the mark to every surface the
  agent role owns** in one deliberate approval. Agents work packs all day; forcing per-surface
  gating on the professional would be friction with no evidentiary gain (the agent is the
  document's steward, not a party consenting to terms against their own interest). **The same
  once-off professional approval governs internal flows** — e.g. a **candidate-document →
  full-status approval**, where a supervisor's single approval action stands for the whole
  document, not a click per surface.

**Special clauses can still demand every party individually.** Independent of profile, some
surfaces require **each party to consent in their own right** — e.g. a **letting-agreement
termination clause** where every co-tenant must individually initial. The pack/template declares
which surfaces are *individual-consent-required*; the ceremony will not advance until every named
party has personally gated that surface. (This applies to client parties; the professional
profile is never the substitute for a required party consent.)

**Why this matters against the field-intelligence.** The four current templates carry **no
signature overlay fields** — every surface is printed-only (field-intelligence §Cross-doc-1). So
the ceremony **declares** each surface (from the field inventory) and turns it into a consent
gate for clients / an apply-all target for the agent. The per-page **initials** that *do* exist
digitally on all four documents become the simplest client gate; the clause-signatures and final
blocks (EATS §2.6/§2.7.1/§2.7.4; OTP purchaser/seller/witness/practitioner; FICA client + office;
Disclosure seller + purchaser) become declared surfaces injected at ceremony time — gated for the
party who owns them, apply-all for the practitioner's own signatures.

---

### 2.1 Who the parties are — recipients come from the PROPERTY-LINK ROLE

**Doctrine (Johan, 2026-07-11).** This is Johan's DR2 capture ruling, in his own words, extended to
ceremony recipient sourcing:

> *"Parties should not be linked based on their contact assignment, but on their link to the property;
> a seller in contacts can be linked to a property as a buyer."*

**The rule.** Ceremony recipients are **offered and routed from the role the contact holds on *this*
property** — the property↔contact link role — **not from the contact's global contact type.** A contact
typed "seller" agency-wide who is linked to *this* property as a **buyer** is a **buyer in this
ceremony**. The global type never overrides the property link.

- **Primary source = the property-link role.** Every ceremony recipient list (who is offered as an
  owner_party / acquiring_party, who lands in which signing group in §4) resolves from the property's
  linked contacts and their **link role on that property**.
- **`contact_types.esign_role` demotes to a FALLBACK filter.** It applies **only where no property link
  exists** — e.g. a party manually added to a ceremony, or a contact not yet linked to the property. It
  is never the primary source, and it never wins against a property link.
- **Manual add stays unfiltered** (as in the settled spec) — the agent can always add a party by hand.
- **Juristic parties (§7) are unaffected**: the *entity* is the party via its property link; its
  authorised signatories are resolved from the entity, not from a global type.

**This section SUPERSEDES settled doctrine — the one place this chapter does not merely extend it.**
`claude_esignature_v2_spec.md` **§13 "Contact Filtering"** prescribes the opposite model
(*"Solution: esign_role on contact_types … Show contacts where contact_type.esign_role IN ('seller',
'lessor')"*), and **V2 §17 Build Priority item 3** queues it (*"wire esign_role into wizard Step 3"*).
**Both are re-pointed by this ruling:** the wizard wires the **property-link role**, with `esign_role`
retained only as the no-property-link fallback. **No build may wire recipient sourcing until item 3 is
re-pointed accordingly.**

**The mechanism already exists and is already loaded** — this is a re-point, not new infrastructure:

- `app/Models/Property.php:506` — the property↔contact relationship already carries the role on the
  pivot (`->withPivot('role')`).
- `app/Http/Controllers/Docuperfect/ESignWizardController.php:494` — the wizard **already eager-loads
  it**: `Property::with(['contacts' => fn($q) => $q->withPivot('role')])->find($propertyId)`.
- `contact_types.esign_role` exists on `ContactType` but is, per V2 §13, *"NOT YET WIRED INTO WIZARD"* —
  and under this ruling it must not be wired as the primary source.
- **Precedent:** the DR2 capture screen already resolves parties this way (commit `cc5a4cfd`, *"parties
  by property-link role"*). The ceremony follows the same truth, so a party means the same thing in
  Deals and in Documents.

**Why it matters.** A person is a seller on one property and a buyer on another. A single global role on
the contact cannot express that, so a global-type filter offers the **wrong parties** the moment a
contact appears in two deals in different roles — and in a signing ceremony the wrong party is not a
cosmetic bug: it is the wrong person signing the wrong side of a legal instrument.

---

## 3. Strikes are visible

**Doctrine.** A non-applicable clause is **rendered struck-through and initialed — never hidden.**
When the agent (or a source value) resolves a strike/select choice, the losing branch stays on
the page with a strike through it, and the responsible party **initials the strike** as a
deliberate act. Removing the clause from view is forbidden; the struck, initialed clause is the
**evidence** that the parties saw the alternative and consciously excluded it.

**Against the field-intelligence.** This replaces the three inconsistent mechanisms found in the
wild (field-intelligence §Cross-doc-3): EATS' uniform strike-capability, the OTP's *type-"N/A"-
into-the-losing-branch* habit, and the Disclosure's per-document ticks. Under V3 there is **one
typed strike/branch behaviour**: the branch renders struck, the choice is recorded, and the strike
is a gated, initialed consent surface. The OTP's decision points — freehold-vs-sectional,
deposit 1.1.1/1.1.2, guarantees 1.2.1/2/3, second-property 2.2.1/2.2.2, occupational-rent payee,
VAT-vendor, cooling-off, tenant-vs-vacant, commission split (field-intelligence §OTP strike table)
— each render both branches, strike the excluded one, and carry an initial.

---

## 4. Group-sequential flow with agent checkpoints

**Doctrine.** Signing proceeds **party-group by party-group**. **Within a group**, signing is
**sequential** (member after member). **Between groups**, there is an **agent approval
checkpoint** — the ceremony pauses, the agent reviews everything the just-finished group did, and
only the agent's approval releases the next group. **Group order is configurable per pack
template.**

**Locked group orders (this morning's doctrine):**

- **OTP:** `purchasers → agent → sellers → agent`.
  The **irrevocable-until countdown timer is visible to all parties** throughout (the offer is
  irrevocable until a stated deadline — field-intelligence §OTP field 88). Purchasers sign the
  offer first; the agent checkpoints; the offer is then presented to the sellers for acceptance
  under the live countdown; the agent checkpoints the acceptance.
- **Mandate:** `sellers → agent`.
  The seller(s) sign the mandate; the agent checkpoints and countersigns as practitioner.

The checkpoint is the existing **Agent Approval Gate** (V2 §5, `approveAndAdvance()`), promoted
from per-signer to per-group and made configurable. At each checkpoint the agent may **approve
and advance**, or **return to the group with notes**.

**Agency-configurable.** Group composition and order per pack template are configuration.
Whether the checkpoint is mandatory or auto-advances for a given pack is an agency setting (with
a safe default of *mandatory checkpoint*).

---

## 5. In-ceremony amendments — the kicker (refinement B: retain marks, re-circulate)

**Doctrine (refinement B — overrides the earlier void-and-restart).** **Any party may propose a
change** to a condition or clause **mid-flow.** The flow:

1. A party proposes an amendment (e.g. a purchaser alters an occupation date or a special
   condition).
2. The **agent approves** the amendment (agent is always the gatekeeper of the document's terms).
3. On approval, the document **re-circulates** through the flow — **but prior signatures and
   initials are RETAINED, not voided.** The document is re-served to the parties with their
   **attention drawn to the amended section(s)**: the changed clause is **highlighted and
   navigated-to — no hunting.** A party may read the whole document again, but they **consent
   specifically to the change** by placing **amendment initials at the changed content.**
4. **Blast radius (answer 2, settled): ALL parties, ALWAYS — no auto-added marks, ever.** The
   re-circulation passes through **every** party in the flow, with the agent checkpoints between
   groups (§4). **Every** party must deliberately place their amendment-initial against the new
   content — including a party who already finished and sits upstream of the change. The system
   **never auto-carries a party's prior consent onto the amended version and never auto-adds a
   mark on anyone's behalf.** This is the conservative, legally-safe choice Johan calls *"the
   court-kicking-you-into-jail decision"* — and it aligns exactly with the settled rule
   (`esign-v3-complete-spec.md` l. 612): *"after the agent approves a change, all parties must
   initial only the new content — not re-sign the entire document; this matches SA wet-ink
   practice exactly."* So: **all parties (blast radius) · only the new content (scope) · every
   mark deliberate (no auto-add).**
5. A **full version audit trail** is kept: who proposed what, when, what changed, which version
   each original mark and each amendment-initial belongs to. **The audit trail is the proof** —
   which is *why* nothing is auto-added: every mark is provably a deliberate human act.

**Why retain-and-re-initial, not void.** This is the **digital twin of established wet-ink
practice**: when a term changes, the agent doesn't tear up every page and re-sign the whole
agreement — they **send the corrected page around for initialing.** The original signatures stand;
the amendment initials attest to the specific change. CoreX reproduces exactly that, with the
integrity guarantee coming from the **version trail** (every mark is bound to the version it was
placed against) rather than from destroying prior consent. Parties are never made to re-sign a
whole document because one clause moved — they consent to *what changed*.

**Against the field-intelligence.** Amendments most naturally target the free-condition bays and
choice points the inventory flagged as agent/party-authored: OTP §17 Other Undertakings, the
strike/select decisions, occupation and deposit terms (field-intelligence §OTP). The amendment
engine treats any such surface as proposable, and drives the "attention-drawn" highlight/navigate
straight to it on re-circulation.

---

## 6. Live tracker — and the tracker is evidence (refinement C)

**Doctrine.** The agent **always sees where the document sits**: the **current signer**, the
**time-in-state** (how long it has waited on that party), and the **group/stage** in the flow.
The tracker exposes **nudge / remind actions** (re-send the link, remind the current signer)
without leaving the tracker.

- One tracker row per ceremony (the "one process" of §1), drilling into per-document and
  per-surface status.
- Time-in-state drives the nudge affordance (and can drive agency-configurable auto-reminders —
  see §Thresholds).
- **Navigation:** **Documents → Signing Tracker** (agent view), plus a live badge on the deal /
  listing the ceremony belongs to.

**The tracker is audit-ready evidence (refinement C).** The ceremony timeline is not just a
convenience view — it is a **timestamped attribution record**. Every **handoff** (document passes
from one party/group to the next), every **view** (a party opened the document), every **nudge**
(who was reminded, when), and every **deadline breach** is recorded against a clock. The purpose is
**attribution**: when an offer lapses, the record **provably shows which party sat on the document
past the deadline** — so the agent doesn't take the flack for a lapse they didn't cause; **the
record assigns it.**

- **Lapse events are first-class.** When a deadline is hit, the ceremony **lapses** as a recorded
  state transition (not a silent expiry), and an **evidence report** for that ceremony becomes
  available — the full attributed timeline (who held it, for how long, who was nudged, when it
  breached) in a form fit to show a principal, a party, or the Ombud.
- Every timeline entry is immutable and attributed to an identity (a party is a CoreX contact —
  see §7 refinement D — so "which party" resolves to a *known person/entity*, not just a name).
- **Navigation:** the evidence report is reachable from the tracker row (**Documents → Signing
  Tracker → [ceremony] → Evidence report**) and from the owning deal/listing.

---

## 7. Juristic (entity) parties

**Doctrine.** A party may be a **juristic person** (company, trust, close corporation,
partnership) rather than a natural person. A juristic party is modelled as **the entity plus its
authorised natural-person signatory/signatories (1..n)**. The **authority documents** that prove
each signatory's mandate to bind the entity are **carried in the pack** (a slot / attachment).

- The entity is the *party*; the natural persons are the *signatories* who actually place marks.
- **1..n signatories** per entity — a trust with two trustees who must both sign is two signatory
  gates under one entity party.
- FICA resolves accordingly: the **FICA slot** picks the entity variant (Company / Trust /
  Partnership) **and** each signatory's Natural-person FICA where required (field-intelligence
  notes FICA is contact-role-agnostic and the on-behalf-of variant expands the principal /
  representative section).
- Authority docs (resolution, letter of authority, power of attorney) ride in the pack as
  required slots so the bundle is self-proving.

**Signatories become CoreX contacts (refinement D).** The authorised natural persons who sign for
an entity are **added / linked as CoreX `Contact` records tied to the entity** — not captured as
free-text names on a page. The system therefore **knows *who* the parties are**, structurally:

- Each signatory is a Contact (created if new, matched/linked if they already exist), related to
  the entity (which is itself a Contact of a juristic type). The relationship makes "who is
  authorised to sign for this company/trust" a queryable fact, reusable across future deals.
- This is what makes §6's evidence real: "which party sat on the document" resolves to a **known
  contact**, and a signatory who recurs across deals is recognised, not re-typed.
- It also feeds the FICA slot — a signatory who is a Contact can carry their own FICA record and
  be matched on the next transaction, rather than being re-questionnaired from scratch.
- Consistent with the Contact pillar and the Match-or-Create discipline: capture a person once,
  link them everywhere.

---

## 8. Witnesses

**Doctrine.** **Witnesses are an optional role per ceremony, default OFF.** When enabled, witness
surfaces become gated signing surfaces like any other party.

- The OTP's witness surfaces are **named** (field-intelligence §OTP confirms each "As Witness"
  has a dedicated "Name of Witness" line — purchaser ×2, seller ×2). When witnesses are enabled
  for an OTP ceremony, those named witness gates are collected within the relevant party-group.
- Default off means the common case (no witnessing) is frictionless; enabling is a per-ceremony
  toggle, with an agency default.

---

## 9. Automatic amounts-in-words

**Doctrine.** **Every money field auto-generates its amount-in-words.** The agent enters the
figure once; the words are derived (ZAR), never hand-typed.

This directly retires the strongest format pattern in the harvest: **money is always digits +
amount-in-words, paired** (field-intelligence §Cross-doc-6 and the OTP price/deposit/balance/bond
rows, the EATS gross-price row). Under V3 the words are a derived, locked companion to the figure
— consistent, correct, and impossible to mismatch.

---

## 10. OTP is a two-act ceremony

**Doctrine.** The Offer To Purchase is a **two-act offer→acceptance ceremony conducted under the
irrevocable deadline.**

- **Act I — Offer.** The **purchaser(s)** complete and sign the offer (group one). The offer
  becomes **irrevocable until the stated deadline** (field-intelligence §OTP field 88 — "offer
  irrevocable until midnight on <date>"). The **countdown is visible to all parties** (§4).
- **Agent checkpoint** between acts.
- **Act II — Acceptance.** The **seller(s)** accept and sign under the live countdown; the
  **practitioner** signs (accepting the benefits of the commission clause — the *stipulatio
  alteri* signature the inventory flags at OTP §18), with the optional **co-signing practitioner**
  where a second agency is involved (field-intelligence §OTP signature map).
- If the deadline lapses before acceptance, the offer **lapses hard** — signing is blocked and
  revival is only via a strike-and-fill date extension (**§11-A**); the lapse is an attributed
  evidence event (§6).

**Delivery mode: the OTP runs WET-INK, not e-sign.** Under ECTA §13(1) an OTP cannot be e-signed
(§0.1); its pack eligibility computes to wet-ink/download only, greyed in the wizard with the plain
reason. So the "signing" in this two-act flow is the **download-print-sign-upload** wet-ink handoff
(§0.1) — the ceremony doctrine (groups, checkpoints, amendments, visible countdown, evidence
tracker) applies **regardless of delivery mode**; the OTP simply resolves to wet-ink.

This is the group-sequential flow of §4 with the OTP's specific two-act semantics and the
countdown as a first-class, visible ceremony element.

---

## 11. Per-document ceremony choreography (cross-referenced to the field inventory)

How each of the four current documents flows through the ceremony, drawing every fill / strike /
initial / signature surface from **`esign-field-intelligence.md`**. (The field-intelligence
tables are the surface-level truth; this section is how they sequence.)

### 11.1 Exclusive Authority To Sell (EATS) — Mandate slot, Exclusive variant
- **Group order:** `sellers → agent` (§4 Mandate).
- **Adopt at entry:** seller signature + initials.
- **Gated surfaces (from field-intelligence §EATS):** per-page initials (P1, P2); the three
  mid-clause consent signatures at **§2.6, §2.7.1, §2.7.4**; the final block (Seller & Co-Seller,
  Witness if enabled, Practitioner) — all **declared and injected**, since none exist as overlay
  fields today.
- **Strikes (visible, initialed):** owner-vs-representative; Erf / Sectional / Unit; Complex /
  Estate; am/pm; unused Seller 2–4 domicilium rows.
- **Prefill targets:** seller name + **SA-ID split** from Contact; property (erf/scheme, complex,
  township, district) from Property; gross price + **auto words** and mandate expiry from the
  deal/mandate.
- **Fee is an EDITABLE FIELD, not a constant (answer 3).** The "7.5% + VAT" the field-intelligence
  saw printed on the EATS PDF was a **DocuPerfect agent-fill on that example**, not a template
  constant — **templates ship empty-for-completion.** So professional fee (and every "such value")
  is a **fillable field**, prefilled from the deal/mandate commission and overridable by the agent
  like any other. (This supersedes the field-intelligence note that read the printed value as a
  fixed constant — it is agency/deal data, not baked text.)

### 11.2 Offer To Purchase (OTP) — two-act (§10)
- **Group order:** `purchasers → agent → sellers → agent` with the **irrevocable countdown
  visible** (§4, §10).
- **Adopt at entry:** each party's signature + initials; **per-page initials** are the 8 digital
  surfaces already present (field-intelligence §OTP signature map) — every page is a gate.
- **Strikes (the core of this doc):** all nine decision points render both branches, strike the
  excluded, initial the strike — freehold-vs-sectional, deposit 1.1.1/1.1.2, guarantees
  1.2.1/2/3, second-property 2.2.1/2.2.2, occupational-rent payee, VAT-vendor, cooling-off,
  tenant-vs-vacant, commission split (field-intelligence §OTP strike table). Where a CDS source
  exists (property tenure, deal flags) it *proposes* the branch; the agent/party confirms.
- **Signature gates:** purchaser + 2 **named** witnesses; seller + 2 named witnesses;
  practitioner; co-sign (witnesses only if enabled, §8) — declared/injected (print-only today).
- **Money:** purchase price, deposit, balance, bond, occupational rent — figure entered once,
  **words auto-derived** (§9).

### 11.3 FICA Natural Person (Schedule 4) — FICA slot, Natural variant
- **Two zones, one document.** Client zone (Q1–Q20) then the **office/staff verification zone**
  (risk rating 1/2/3, verification method, staff signature, the 7-row checklist) — which the
  field-intelligence found is **not captured at all today** (§FICA).
- **The office zone MIRRORS the existing FICA module — reference, do not redesign (answer 4).**
  CoreX already has the verification flow: `FicaSubmission` carries the **risk_rating (1-3)** and
  **verification_method**, and moves `submitted → agent_approved ("Awaiting CO Approval") →
  approved`, with a **`FicaComplianceOfficer`** role doing the final approval (V2 §9). The FICA
  slot's office/verification stage **is that flow** — the ceremony hands the submitted
  questionnaire into the existing FICA module rather than inventing a second signer surface. So
  the sequence is: **client completes + signs the questionnaire → agent checkpoint → the existing
  FICA module captures risk rating + verification method → compliance-officer approval.** This is
  the genuine two-role reality (client, then office), realised through the module CoreX already
  runs, not a new office-signature block on the template.
- **Adopt at entry:** client signature + initials; per-page initials are the digital anchors.
- **Prefill:** name + **SA-ID (split + checksum)**, address, contact, tax number from Contact;
  the PEP / source-of-funds declarations remain client-entered (manual-only).
- **Juristic:** the on-behalf-of / entity variants (§7) expand the principal/representative
  section and pull each signatory's authority.

### 11.4 Seller Mandatory Disclosure V7 (+ Addendum B) — Disclosure slot
- **One document, TWO signing stages across the mandate/sales boundary (answer 1 — resolves the
  re-serve question).** The disclosure is a **single document** signed at **two stages**:
  - **Stage 1 — seller, at mandate.** Signed when the mandate is taken. This stage **MAY be
    e-signed** (it rides the mandate pack, which can be e-sign-eligible).
  - **Stage 2 — purchaser countersign, at offer.** The §9 acknowledgement is signed **inside the
    OTP / sales pack**, where **only wet ink is allowed** — the **sales-process boundary is
    absolute** (ECTA §13(1); §0.1). So stage 2 is **wet-ink**, via the download-print-sign-upload
    mixed-mode handoff (§0.1).
  - **A single document can therefore legitimately carry an e-signed stage AND a wet-ink stage.**
    The two-stage instance is *not* two separate ceremonies to reconcile — it is one document
    whose second stage is bound to the sales pack's wet-ink mode. This is the answer to the
    earlier "one sleeping ceremony vs two linked" question: **one document, two stage-scoped
    signings, each in the delivery mode its pack computes.**
- **Variant:** SECTION-TITLE vs FULL-TITLE resolved by **property tenure** (§1).
- **Gated surfaces:** the full **Y/N/N-A grid** (13 condition items + Addendum B's 5 certificate
  items) and the seller / purchaser / practitioner / co-signature blocks — **baked into the
  template** so the document cannot ship with an unanswered statutory item (fixing the harvest's
  "grid added per-doc, columns missed" gap).
- **Conditionally-required explanation:** when any item = **Yes**, the §4 additional-information
  explanation becomes **required** (fixing the real compliance gap the harvest found — Yes
  answered, explanation omitted).
- **Addendum B** is page 4 of the same instance (building plans + Electrical CoC / Electric-fence
  / Gas / Entomology certificates, each Y/N/N-A + issue date) and signs with the same blocks.

---

## 11-A. Expiry, lapse & revival — the rules of war (refinement E)

**Doctrine (answer 5).** A signing document has a **legal date** by which it lives or dies — the
**mandate expiry date** (EATS), the **OTP irrevocable-until date**, and any other statutory
deadline. The ceremony treats that date as **absolute law**, not a soft reminder.

### 11-A.1 Hard lapse — signing on a dead document is blocked
- **The document lapses at its legal date.** When the deadline passes with the ceremony
  incomplete, the ceremony transitions to **`lapsed`** — a recorded state, not a silent expiry.
- **A signature placed after that date is null and void.** So the system **hard-blocks signing on
  a lapsed ceremony** — the signing surface will not accept a mark; there is no "just sign it
  anyway." A mark that would be legally worthless is never collected in the first place.
- This is why the OTP countdown (§10) is visible to all parties: the clock is not decoration, it
  is the enforcement boundary. When it hits zero, the pen stops working.

### 11-A.2 Extension is the sole resurrection path — a visible strike-and-fill amendment
A lapsed document can be brought back **only** by extending its legal date. This is a special,
high-ceremony **amendment** (it runs on §5's engine) with strict form:

- **Any party may propose the extension** (new date).
- It is executed as a **visible strike-and-fill**: the **old date is struck through and preserved
  on the page** (never erased), and the **new date is entered alongside it** — *"13 July → 15 July
  must be SEEN as changed."* The change is evidentiary, not silent.
- It requires **ALL parties to initial next to the change** (§5 blast radius — all parties, always,
  no auto-added marks). The agent checkpoints as in any amendment.
- **Only when every party has initialed** does the ceremony **revive** and continue from where it
  paused. Extension is the **sole** resurrection path — there is no other way to un-lapse a
  document.

### 11-A.3 First-class states with a full evidence timeline
Model **lapse → extension-proposal → revival → re-lapse** as **first-class ceremony states**, each
timestamped and attributed on the evidence timeline (§6):

- **`lapsed`** — deadline hit; signing blocked; the evidence report is available (attributing who
  held the document past the deadline).
- **`extension_proposed`** — a party proposed a new date; awaiting agent approval + all-party
  initials.
- **`revived`** — all parties initialed the strike-and-fill; the ceremony is live again under the
  new date, with the old date visibly struck for the record.
- **`re-lapsed`** — a revived document whose new date also passes incomplete lapses again; the same
  rules apply (extend again, or it stays dead). Every cycle is on the timeline.

This closes the loop with §6: a lapse is not a mess the agent has to explain — it is an **attributed,
evidenced event**, and revival is a **deliberate, all-party, visible act**. The record shows exactly
who let it die and exactly who agreed to bring it back.

---

## 11-B. Ceremony completion distribution — the signed copies go back to the signers

**Doctrine (Johan, 2026-07-11).** When a ceremony completes, the **signed documents go back to
the people who signed them.** A seller who signs a mandate expects their signed copy — not a
link, not a portal login, not "ask your agent". The distribution is part of the ceremony, not an
afterthought.

### 11-B.1 The trigger — the FINAL AGENT SIGN-OFF, and never the candidate's

Distribution fires on the **final agent sign-off that completes the ceremony** — the last act,
after every party group and every checkpoint (§4).

**Candidate doctrine applies, and it is absolute.** A **candidate practitioner cannot complete a
ceremony.** Their final sign-off **routes to a full-status agent for approval**, and **only that
approving sign-off triggers distribution** — never the candidate's own. A candidate's signature
is work-in-progress; the full-status practitioner's approval is what makes the document final,
and only a final document may be distributed.

**As-built:** this is already the shipped behaviour and is *not* a build. On a candidate flow
(`signature_templates.is_candidate_flow`) the candidate's sign-off calls `advanceToSupervisor()`
rather than completing; only the `supervisor_final` sign-off calls
`SignatureService::completeDocument()` — which is what fires distribution
(`sendCompletionEmails()`). The authoriser is recorded (`authorised_by` / `authorised_at`) and
audited (`supervisor_final_signoff`). The ceremony inherits this — it does not reinvent it.

### 11-B.2 What distributes — the SIGNED documents only

**Only documents that were signed are distributed.** Supporting and attached documents — pack
**attachment slots** (§1), juristic **authority documents** (§7), knowledge-base attachments,
anything that rode along as an *input* to the ceremony — are **never** distributed.

The test is simple: **did a party place a mark on it?** If yes, it is an output of the ceremony
and it goes back. If no, it was an input, and inputs are not returned. Attaching a party's FICA
supporting evidence or a trust's letter of authority to an email that goes to *every* signer is a
POPIA problem, not a courtesy.

**As-built:** this holds today only *by omission* — `sendCompletionEmails()` attaches the signed
PDF and never touches pack attachments. **It must be made an explicit rule**, because the moment
distribution iterates the pack's documents (§11-B.3), an attachment slot sitting in that same
pack becomes one loop away from being emailed to everyone.

### 11-B.3 How it distributes — as MANY documents, never one merged pack

**A pack of four documents distributes as FOUR attachments — never as one merged file.**

This is the **files-as-many doctrine (§1) extended to distributes-as-many.** The pack is the
*delivery vehicle*, never a merged mega-document — not in the filing cabinet, and not in the
signer's inbox. The seller receives the Mandate as the Mandate, their FICA as their FICA, the
Disclosure as the Disclosure. Each is independently legally defensible, independently filed, and
independently readable. A single fused PDF of four unrelated instruments is not a copy of what
anyone signed.

**As-built — this is the one real gap in this section.** `sendCompletionEmails()` attaches a
**single merged client PDF** (`"Signed - {documentName}.pdf"`). But the per-document signed PDFs
**already exist**: `filePackDocuments()` splits the signed DOM (`splitMergedHtml()`) and writes
each document to
`docuperfect/signed-documents/{signatureTemplateId}/individual/{templateId}_client.pdf`. So the
artefacts are already generated and filed — they are simply **not the ones attached to the
email**. The build attaches the per-document set that filing already produced.

### 11-B.4 To whom — the parties who signed

Distribution goes to **the parties who signed** — each completed signing party, at the address
the ceremony used. **Agents receive in-app notification, not email** (the settled rule: agents
get zero emails; V2 §12).

**As-built:** already correct — `sendCompletionEmails()` iterates the template's requests, sends
only to those with status `completed`, and skips `party_role === 'agent'`. Each party receives
the **client copy** (no internal audit trail); the agent's internal copy stays internal.

### 11-B.5 Distribution is an evidence event (cross-reference §6)

**Every distribution is an audit entry.** The tracker is audit-ready evidence (§6, refinement C),
and "the signed copy was sent to this party, at this address, at this time, and it was this
document" is exactly the kind of fact the evidence report must be able to prove. A dispute about
whether a seller ever received their signed mandate is settled by the record, not by memory.

Each distribution records: **which document** (per document, not per ceremony), **which party**,
**which address**, **when**, and the **delivery outcome**.

**As-built:** the audit action already exists — `SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED` is
written per recipient in `sendCompletionEmails()`. It must become **per document per recipient**
once distribution is per-document (§11-B.3), so the evidence timeline can answer *"was the
Disclosure sent to the purchaser?"* and not merely *"was something sent?"*.

### 11-B.6 What "done" looks like

- A ceremony completing on a **candidate's** sign-off distributes **nothing** until a full-status
  agent approves; that approval is what sends the copies.
- A **four-document pack** arrives in the signer's inbox as **four attachments**, each named as
  its own document.
- **No supporting or attached document** is ever distributed — only what was signed.
- Every party who signed receives the documents **they signed**; agents get an in-app notification.
- Every distribution appears on the **evidence timeline** (§6), per document, per party, with the
  delivery outcome.

---

## 12. Agency-configurable thresholds (everything with a knob)

Per the doctrine, everything that has a threshold is agency-configurable. The knobs this chapter
introduces:

| Setting | What it controls | Safe default |
|---------|------------------|--------------|
| Pack templates | slot order, required/optional/selectable per slot, default variant per slot | CoreX standard SA pack set |
| Group composition & order | per pack template (e.g. OTP purchasers→agent→sellers→agent) | per the locked orders in §4 |
| Agent checkpoint mode | mandatory checkpoint vs auto-advance between groups | **mandatory** |
| Individual-consent surfaces | which clauses require every party personally (§2) | per template (e.g. letting termination = on) |
| Witnesses | on/off per ceremony | **off** |
| Reminder cadence | time-in-state before an auto-nudge fires (§6) | agency-set; off unless configured |
| Irrevocable deadline default | default offer-validity window for OTP (§10) | agency-set |
| Commission / fee default | the default commission % / fee prefilled into the (always-editable) fee field from the deal/mandate (§11.1, answer 3 — never a printed constant) | agency commission default; agent-overridable |
| Amendment rights | which parties may propose amendments (§5) | all signing parties |
| Consent profile per role | which roles sign on the professional adopt-once/apply-all profile vs client gate-per-surface (§2, refinement A) | agent/practitioner = professional; all client parties = gate-per-surface |
| Lapse & evidence | whether an evidence report auto-generates on lapse and who may view it (§6, refinement C) | auto-generate on lapse; agent + principal visible |
| Legal expiry source | which field supplies each document's hard deadline — mandate expiry date, OTP irrevocable date (§11-A) | mandate → mandate expiry; OTP → irrevocable-until date |
| Extension consent | who may propose an extension and the initialing rule to revive (§11-A) | any party may propose; **all-party initial is fixed, not configurable** (legal integrity) |
| Completion distribution | whether signed copies auto-send to signers on completion (§11-B) | **auto-send on completion**; signed documents only, one attachment per document |
| Delivery mode | **computed from the pack's templates, not chosen** — any `is_esign=false` template forces wet-ink/download (§0.1) | computed; wizard greys blocked modes with the reason |

---

## 13. Navigation & placement (nav entry same day — non-negotiable #2)

- **Documents → Packs** — admin: create/edit pack templates (slots, variants, group order).
- **Documents → New Signing** / **from a deal or listing** — agent: launch a ceremony, resolve
  slot variants, choose parties, toggle witnesses.
- **Documents → Signing Tracker** — agent: the live tracker (§6), current signer + time-in-state
  + nudge/remind; a live badge on the owning deal/listing.
- **The ceremony surface itself** — the party's signing view: adopt-at-entry, gated consent
  actions per surface (§2), visible strikes (§3), the OTP countdown (§10), amendment-propose
  action (§5).
- Filed outputs appear where they always have — each independent document on the property / the
  contacts, under **Documents** (§1, unchanged filing).

---

## 14. What "done" looks like (business acceptance)

- An admin can build a pack template of ordered slots; an agent sending it resolves each slot to
  a variant, and the bundle goes out as **one ceremony**.
- **Clients** adopt once, then **consciously consent at every required surface** — no client mark
  is ever placed without a click; the audit shows a consent event per surface. **Agents** sign on
  the **professional profile** (adopt-once, apply-all), and the same once-off approval governs
  internal candidate→full-status approvals.
- Non-applicable clauses appear **struck and initialed**, never removed.
- Signing runs **group by group** with an **agent checkpoint between groups**, in the pack's
  configured order; the OTP runs its **two-act** flow with the **countdown visible to all**.
- Any party can **propose an amendment**; on the agent's approval the document **re-circulates with
  prior marks retained** and **attention drawn to the change** — **all parties re-initial the new
  content** (blast radius = all parties, always; no auto-added marks) — with a **full version
  history** as the proof.
- The agent can always see **who holds the document and for how long**, and can **nudge**; a
  **lapsed offer produces an evidence report** that **attributes the delay to the party who held
  the document past the deadline.**
- **The right people are offered.** Ceremony recipients come from the **property-link role** (§2.1) — a
  contact typed "seller" agency-wide who is linked to *this* property as a **buyer** is offered as a
  **buyer**. The global contact type is only a fallback where no property link exists, and never
  overrides the link.
- **Juristic parties** sign through their authorised natural persons — **who are linked as CoreX
  contacts tied to the entity** — with authority docs in the pack; **witnesses** work when enabled.
- On completion the ceremony **files as many independent documents on their own names** — the
  Mandate as the Mandate, each FICA as that person's FICA, the Disclosure as the Disclosure —
  exactly as CoreX files today.
- **Delivery mode is computed, never guessed** — a pack containing a wet-ink-only template (e.g. an
  OTP) shows e-sign greyed with the plain-language reason; a single document can carry an e-signed
  stage and a wet-ink stage (the Disclosure across the mandate/sales boundary).
- **A lapsed document cannot be signed** — the deadline is a hard block; the only way back is a
  **visible strike-and-fill date extension that every party initials**, after which the ceremony
  **revives**. Lapse, extension, revival and re-lapse are all on the evidence timeline.
- **Fees and such values are editable fields** filled from the deal/mandate (templates ship empty),
  and every **money field** auto-generates its amount-in-words.
- On completion the signed copies **go back to the parties who signed** — a four-document pack
  arrives as **four attachments, never one merged file** (§11-B); **no supporting or attached
  document is ever distributed**; a **candidate's** sign-off distributes nothing until a
  full-status agent approves; and every distribution is an **evidence event** (§6).
- Every threshold above is **agency-configurable** (except the all-party extension-initial rule,
  which is fixed for legal integrity); every new screen has a **navigation entry**.

---

## 15. Doctrine decisions (the five questions — now answered)

The open questions from the prior draft are all resolved by Johan's final addendum. Recorded here
so the resolution is on the page, not just in the section it touched:

1. **Disclosure re-serve → RESOLVED (§11.4).** It is **one document, two signing stages** — stage 1
   (seller, at mandate) may be **e-signed**; stage 2 (purchaser countersign) sits **inside the OTP /
   sales pack and is wet-ink only** (the sales-process boundary is absolute). One document can carry
   an e-signed stage and a wet-ink stage; there is no "sleeping ceremony vs two ceremonies" dilemma —
   each stage signs in the delivery mode its pack computes.
2. **Amendment blast radius → RESOLVED (§5).** **All parties, all surfaces, always — no auto-added
   marks, ever** ("the court-kicking-you-into-jail decision"). Every party re-initials the new
   content on any amendment (aligned with the settled rule: all parties initial only the new content,
   not re-sign the whole document); the audit trail is the proof.
3. **Fee as field vs constant → RESOLVED (§11.1).** Fee and all such values are **editable fields**;
   templates **ship empty-for-completion.** The printed "7.5% + VAT" in the PDF example was a
   DocuPerfect agent-fill, not a template constant.
4. **FICA office-zone signer → RESOLVED (§11.3).** **Mirror the existing FICA module** — the client
   signs the questionnaire; verification (risk rating, method, compliance-officer approval) runs
   through `FicaSubmission` / `FicaComplianceOfficer` as it already does. Referenced, not redesigned.
5. **Deadline authority → RESOLVED (§11-A).** The legal date is a **hard system stop** — signing is
   blocked on a lapsed ceremony (a post-deadline signature is null and void). The **only** revival is
   a **visible strike-and-fill date extension that all parties initial**; lapse / extension / revival
   / re-lapse are first-class evidenced states.

---

*End of chapter. Cites and extends the settled e-sign doctrine (`claude_esignature_v2_spec.md` §17–§19
+ the March–May 2026 redesign in `esign-v3-complete-spec.md`); pairs with `esign-field-intelligence.md`
(surface inventory) and `esign-document-compiler-spec.md` (CDS engine). No code, no tickets — doctrine
capture for Johan's read.*

---

## AT-291 — signing-ceremony send-now fixes (2026-07-18, built)

Six defects Johan hit in the live ceremony, fixed on branch `AT-291-esign-ceremony-send-now`
(off QA1). Investigation: `.ai/audits/2026-07-17-esign-ceremony-wonk-audit.md` (seller-ID class)
+ the AT-291 mail/flag/chip investigations. **Authority:** Johan, "the esign bugs sent now fixed".

**① Sender (From) address + ② Reply-To** — the amendment/initialing re-send sites
(`SignatureService::handleAmendment` and `::requeueAllPartiesForInitialing`) sent
`SigningRequestMail` **without `->fromAgent()`**, collapsing both From and Reply-To to the system
default. Fixed by stamping `->fromAgent($template->creator)` at both, matching every other send
site. The deliverability-safe rule is `BaseSignatureMail::getFromAddress()` and applies per user
type: **company-domain agent** (`@hfcoastal.co.za` outward email) → From = agent's own address;
**personal-email agent** → From = `system@hfcoastal.co.za` with display name "`<Name>` via Home
Finders Coastal" (SPF/DKIM constraint, AT-79) **+ agent Reply-To**; **no resolvable agent** →
system From, no Reply-To. Reply-To always carries the agent's outward email when an agent is present.

**③ Green chips** — the emerald "`<party> (signed)`" attribution chip on OTHER parties' markers in
the recipient signing view derived ownership from a fuzzy same-family role match
(`isMyWebSigBlock`, `seller/owner_party/lessor/landlord/owner` treated as one), lighting a green
chip for the **wrong** same-family party. **Ruling (Johan): hidden from the recipient view, kept on
the agent view.** The recipient still sees their OWN "Signed/Done" indicator and the "not yours"
lock on unsigned other-party fields; only the other-party *signed attribution* chip is hidden
(both web + PDF overlays in `signatures/external/sign.blade.php`). The agent view
(`SignatureController::sign`, separate blade) is unchanged.

**④ Data-loss-on-flag** — the flag-clause modal called `location.reload()` on a successful flag
POST, wiping every captured-but-unsubmitted signature / initial / field (forbidden per STANDARDS
§E-Sign). Fixed: the modal now dispatches `clause-flagged-committed`; the signing component applies
the flag **in place** (`_applyCommittedFlag` — pushes to `webClauseFlaggedItems`, repaints the
clause) so all captured work survives. Mirrors the already-correct flag-**removal** path.

**⑤ Flag freeze workflow** — the freeze was client-only. Now server-enforced:
`completeWeb()` (and the marker `complete()` twin) reject a completion POST with **423** while any
flag amendment is `STATUS_PENDING` (`templateHasPendingFlag`); a crafted / JS-failed POST can no
longer sign a document that is about to change. The freeze **lifts automatically** the moment the
agent resolves the flag — `show()` derives each persisted clause-flag's status from the live
`DocumentAmendment` record (`hydrateClauseFlagStatuses`) instead of the frozen `clause_flags` JSON
(which the resolution cascade never rewrites). Recipient UX: while frozen, the only action is a
**Close** button on the freeze banner, which surfaces an "amendment sent — you may close this
window" overlay; the document returns to the agent to fix + re-send, and the recipient completes
via the email link after resolution. Deliberately **not** gated: raising/self-removing flags stays
allowed while frozen (a recipient may flag more than one clause) — only *completion* is blocked.

**⑥ Fill&sign duplicate seller render** — the Step 5 "Fill & Review" preview runs the recipient
signing engine (`ESignWizardController` pipes it through `RoleBlockExpansionService::expandWithLooping`),
so the double is a block-expansion defect, not a wizard-blade loop. Root cause: the normalizer stamps
mixed vocabulary (`RoleBlockDetectionService` returns `owner_party` for generic-named fields and
`seller` for a `data-contact-type="Seller"` block), and `groupRecipientsByRole` fans each recipient
into both its literal and canonical-twin bucket — so a `seller` role-block nested inside an
`owner_party` role-block (same party) is cloned once WITH its ancestor and again on its own pass →
the seller renders twice. **Fix:** `RoleBlockExpansionService` now excludes a role-block nested inside
another role-block of the **same canonical party** from independent expansion
(`hasSamePartyRoleBlockAncestor` / `canonicalParty`); different-party nesting and non-nested sibling
blocks are untouched, so a single seller renders once and genuine multi-seller still expands N times.

Tests: `tests/Feature/Docuperfect/SigningView/FlagFreezeGateTest.php` (⑤ server gate + freeze
derivation) and `.../NestedRoleBlockDuplicateTest.php` (⑥ nested same-party dedup + regression
guards) — both in the pipeline-gated `SigningView/` dir covering the `SigningController` and
`RoleBlockExpansionService` changes.

## AT-292 — couple's-mandate seller identity-drop (2026-07-18, built; stacked on AT-291)

Johan: on a couple's mandate the second seller renders WITHOUT their ID number. Re-verified on the
QA1 base — the root cause had MOVED from the AT-111-audit's legacy composite-span builder (now dead
for live CDS docs) to the CONTRACT render path.

Root cause: per-recipient prefill (`RoleBlockExpansionService::mutateCloneForInstance` /
`stampInlineFieldForRecipient`) re-sources identity from the linked Contact via
`resolveContactValue`, which `(string)`-cast an empty Contact column to `''`. `''` is not null, so the
caller's `if ($value !== null)` guard fired and `replaceTextContent($f, '')` **wiped the ID the wizard
had baked into merged_html**. A couple's second seller is typically matched to an EXISTING Contact
whose `id_number` is blank (the wizard never wrote the typed ID back), so the ID (and name/email/
address/phone — same class) blanked. Separately, `seller_cell` fields had no `case` in
`resolveContactValue` so they never re-sourced (couples showed seller 1's number).

Fixes (all fill-safe, non-destructive):
- **A (choke point):** `resolveContactValue` returns **null, not `''`**, for any empty Contact column
  (`blankToNull`) → the prefill guard PRESERVES the baked span for ID/name/email/address/phone on both
  prefill paths.
- **A′ (headline fallback):** `mutateCloneForInstance` falls back to `SignatureRequest.signer_id_number`
  for the ID when the Contact is blank — fixes historical couples even if the span lacked the ID.
- **B (durable data fix):** `ESignWizardController` reconciliation loop now backfills the typed
  `id_number` onto pre-linked / matched-existing / auto-duplicate Contacts (`backfillContactIdNumber`,
  **fill-if-blank — never overwrites** a non-empty value) so the drop is closed at the data source
  (render + FICA + deals all resolve it going forward).
- **C (phone key):** added `case 'cell'` to `resolveContactValue` alongside `phone`.

No overlap with AT-291 (that touched `expandViaContract`'s block-collection; this is downstream in
prefill/resolve — no ordering conflict). Test: `SigningView/SellerIdentityPreservationTest.php` —
baked-ID survives, signer_id_number fallback, `seller_cell` resolves, blank→null unit, wizard
fill-if-blank backfill.

## AT-293 — completeWeb server-side mandatory FLOOR (2026-07-18, built; stacked on AT-292)

Canon §4 / AT-291 audit G1: `SigningController::completeWeb()` validated only `consented` server-side
before `STATUS_COMPLETED`; required fields / disclosures / signatures were enforced CLIENT-side only
(`canSubmitWeb` / `webIncompleteCount`), so a crafted or JS-failed POST could complete with blank
statutory items.

Key constraint (verified): a web/CDS template carries **no structured per-field `required` flag** —
required-ness lives only in the rendered HTML (a client DOM computation). The exact per-item count
therefore **cannot be faithfully reproduced server-side** without re-rendering + re-parsing this
party's merged_html. So the gate is a **strict FLOOR beneath the client contract**, not a
reproduction of it:
- (a) consent (already enforced);
- (b) at least one **signature/initial** captured (`signatures{}`/`initials{}` non-empty);
- (c) if this signer has **recipient-editable fields** (`getEditableFieldsFromMappings` for the
  party_role), at least one **field value** filled.

Because the client requires ALL such items, this floor can only ever reject the empty/crafted POST —
**zero false-positives on a client-legitimate submission**. Returns **422** with a user-clear message.
Inserted after the AT-292 freeze gate + consent check, before the consent audit-log write; the two
gates (freeze vs required-floor) coexist.

**Deliberately client-only (documented, not server-reproducible without re-rendering):** disclosure
completeness (rows exist only in rendered HTML, disclosing-party-only) and exact required-signature/
initial counts. These remain enforced by `webIncompleteCount`; the server floor covers the
none-submitted hole. A future hardening could re-render the party's merged_html to count them.

Test: `SigningView/WebCompletionRequiredGateTest.php` — no-signature→422, editable-all-blank→422,
signature+field→passes, consent-still-required.

## AT-294 — empty-email recipient dead-end (2026-07-18, built; stacked on AT-293)

Audit A1: a recipient with no email hit `Mail::to('')` in `SignatureService::sendSigningRequest()`,
which threw and was **swallowed** by the surrounding try/catch — the ceremony parked as a
healthy-looking `awaiting_*` (request `pending`, template `awaiting_<role>`) with no link delivered
and no agent-visible error. The token is minted at request-creation (so a link always existed), and
the controller reported unconditional "sent" success. The only email validation anywhere was on the
explicit `sign_later` path.

Fix = PREVENT + ABSORB (defence in depth), reusing the EXISTING `DEFERRED` / `AWAITING_DEFERRED` /
`resumeDeferredSigning` machinery (the `sign_later` pattern) — no new state, no new UI:
- **ABSORB (primitive guard, the core):** `sendSigningRequest()` — if `signer_email` is blank, park
  the request `DEFERRED` + template `AWAITING_DEFERRED` + audit `send_skipped_missing_email`, and
  return before the doomed send. The agent sees it in the visible deferred bucket, adds an email, and
  resumes via `resumeDeferredSigning()`; the token survives, nothing is lost. Guards the primitive,
  so every caller (including the agent-completion auto-advance, which has no controller round-trip)
  is covered (BUILD_STANDARD §6).
- **PREVENT (agent-facing, upfront):** `SignatureController::sendForSignature()` — both the initial
  send and the awaiting-party resend reject email-less WAITING parties (excluding sign-later
  DEFERRED and supervisor queue roles) with a clear per-recipient `withErrors` message ("These
  recipients have no email address: … Add an email, or mark them 'sign later / in person'").
- Reminder sends (`sendReminderEmail` / `sendManualReminderEmail`) skip an email-less party cleanly
  (log + return) instead of swallowing a `Mail::to('')`.

Test: `tests/Feature/ESign/EmptyEmailDeferralTest.php` — empty-email→DEFERRED+AWAITING_DEFERRED (no
throw, no mail, token preserved), with-email→PENDING+mail sent, resume-with-email→re-enters flow.

## AT-295 — agent fill&sign pre-send duplicate seller (REOPENED ⑥, wrong surface) (2026-07-18)

Johan's on-site test of deployed qa1 (ac580cb6) showed the AGENT fill&sign PRE-SEND screen STILL
doubles the seller block. AT-291 ⑥ fixed the RECIPIENT ceremony but not this. Root cause: the wizard
Step-5 preview (`ESignWizardController::templatePages`) feeds RAW blade HTML — which has NO
`data-role-block` contract (0/39 web-templates carry it; it's stamped into `merged_html` only at
document generation) — into `expandWithLooping`, so `$hasContract=false` and it takes the LEGACY
clustering path where the ⑥ `hasSamePartyRoleBlockAncestor` dedup never runs. The recipient ceremony
feeds contract-stamped HTML → contract path → deduped. Fix: run `RoleBlockNormalizer::normalize()` on
the preview HTML BEFORE `expandWithLooping` (one renderer, both surfaces). Test:
`SigningView/AgentPresendContractTest.php` (normalizer stamps the contract → preview enters the deduped
path). **NEW RULE (Johan, on-site): DONE only when verified rendering correctly on the deployed qa1
site — on-site verification recorded on the ticket post-deploy.**
