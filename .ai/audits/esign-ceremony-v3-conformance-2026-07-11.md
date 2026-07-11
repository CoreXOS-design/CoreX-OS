# E-Sign Ceremony V3 — Doctrine Conformance Audit

> **Auditor:** m5 (e-sign crew, seat 1) · **Date:** 2026-07-11
> **Subject:** `.ai/specs/esign-ceremony-v3.md` (606 lines, recovered — see §D)
> **Against:** Johan's 20-point locked ceremony doctrine
> **Reads with:** `.ai/specs/esign-field-intelligence.md` (729 lines), `.ai/specs/claude_esignature_v2_spec.md` §13/§17
> **Method:** every point answered with a VERBATIM quote + section number, or stated plainly as DIVERGES / SILENT.
> **Scope boundary:** this is a **spec-vs-doctrine** audit. Spec-vs-code gaps are m6's (`.ai/specs/esign-gap-analysis.md`).

---

## A. Verdict

**AS AUDITED: 19 of 20 points CONFORM** — the chapter captures Johan's doctrine faithfully and in his
own language. **1 point was SILENT: point 20 (recipient sourcing).**

**AS NOW COMMITTED: 20 of 20.** ✅ Point 20 was closed on 2026-07-11 by Johan's existing DR2 capture
ruling, which he confirmed extends to ceremony recipient sourcing. The chapter now carries **§2.1 "Who
the parties are — recipients come from the PROPERTY-LINK ROLE"**, and §0.1 records it as the single
**override** of settled doctrine. See §B.20 below for the closure.

**Why the silence was load-bearing (recorded for the build).** §0.1 explicitly binds V3 to *extend, not
restate* the settled specs — so saying nothing about recipient sourcing meant V3 **inherited** the
settled model, and the settled model (`claude_esignature_v2_spec.md` §13) is `contact_types.esign_role`,
i.e. **global contact type**: precisely the model doctrine point 20 rejects. V2 §17 queued it as **build
item 3**. Silence did not leave the question open — it green-lit the wrong answer. That inheritance is
now explicitly severed in §0.1.

**⚠️ ACTION STILL OUTSTANDING (ticket):** **V2 §17 Build Priority item 3** (*"Bug #1: Contact filtering —
wire esign_role into wizard Step 3"*) **still points at the superseded model** and must be **re-pointed
to the property-link role before any build wires recipient sourcing.** The spec now says so in two
places (§2.1, §0.1); the *build queue* has not been re-pointed. Flagged for the ticket.

Two structural defects, both fixed (§C).

---

## B. Point-by-point proof

### 1. Packs = admin-defined slots resolved to variants at send — **CONFORMS** (§1)

> "An admin defines a **pack template** as an ordered list of **slots** — e.g. *Mandate · FICA ·
> Mandatory Disclosure · docN*. A slot is a *role in the pack*, not a fixed document. At **send
> time**, the agent **resolves each slot to a concrete variant**" — §1

> "The agent's send-time job is variant resolution, not authoring." — §1

Variant table present (Mandate: Open/Exclusive/Dual · FICA: Natural/On-behalf-of/Company/Trust/
Partnership · Disclosure: SECTION- vs FULL-TITLE by tenure · docN). Anchored to the existing
`docuperfect_pack_slots` columns (§0 table) — extension, not greenfield.

### 2. One ceremony out → files as many independent docs — **CONFORMS** (§1)

> "**One ceremony out, one process back, many docs filed.** The resolved pack goes out as **one
> signing ceremony**. It returns as **one process** (one status, one audit trail, one tracker row).
> On completion it **files as many independent documents**, each on its own name, its own
> `document_type_id`, filed against the property and the correct contacts — **exactly the current
> CoreX filing behaviour** (`autoFileSignedDocument()`, V2 §8)." — §1

> "The pack is the *delivery vehicle*, never a merged mega-document in the filing cabinet." — §1

### 3. Pack e-sign eligibility computed from templates, greyed-with-reason — **CONFORMS** (§0.1, §12)

> "**Pack e-sign eligibility is COMPUTED from its templates.** Any template with `is_esign=false` in
> a pack makes the **whole pack wet-ink/download only** — shown greyed in the wizard with the plain
> reason." — §0.1

> "Slot resolution (§1) feeds eligibility: resolve a wet-ink-only variant into a slot and the
> **pack's mode downgrades**, visibly, with the reason. The agent never has to know the law — the
> system computes and explains." — §0.1

> "| Delivery mode | **computed from the pack's templates, not chosen** — any `is_esign=false`
> template forces wet-ink/download (§0.1) | computed; wizard greys blocked modes with the reason |" — §12

### 4. OTP/sales wet-ink only, structurally blocked; Disclosure legitimately carries e-sign stage 1 + wet-ink stage 2 — **CONFORMS** (§0.1, §10, §11.4)

> "**ECTA §13(1) — sale agreements / OTPs cannot be e-signed; wet-ink only.** The wizard **greys the
> e-sign option** with the plain reason … an `is_e_sign_blocked` template throws a `DomainException`
> if bypassed." — §0.1

> "**Delivery mode: the OTP runs WET-INK, not e-sign.** … the ceremony doctrine (groups, checkpoints,
> amendments, visible countdown, evidence tracker) applies **regardless of delivery mode**; the OTP
> simply resolves to wet-ink." — §10

> "**Stage 1 — seller, at mandate.** … This stage **MAY be e-signed** … **Stage 2 — purchaser
> countersign, at offer.** … **only wet ink is allowed** — the **sales-process boundary is
> absolute** … **A single document can therefore legitimately carry an e-signed stage AND a wet-ink
> stage.**" — §11.4

Citation verified: `esign-v3-complete-spec.md` ll. 38-40 + 365-375 carry exactly the ECTA rule and
the greyed-with-tooltip behaviour the chapter cites. The chapter's citations are real.

### 5. Adopt-once + gate-per-surface for PARTIES, apply-all for AGENTS/internal; consent profile = property of the signer ROLE — **CONFORMS** (§2)

> "**The consent profile is a property of the signer role, not of the ceremony.**" — §2 (doctrine line, verbatim)

> "**Client profile (parties) — gate-per-surface.** … every required surface is a deliberate gate …
> Adopting does **not** stamp the mark everywhere; each placement is its own recorded, timestamped
> consent event. Silent 'apply to all' is **forbidden** for clients" — §2

> "**Professional profile (agents) — adopt-once, apply-all.** … **The same once-off professional
> approval governs internal flows** — e.g. a **candidate-document → full-status approval**" — §2

Correctly identified as already-shipped (V2 FIX 2, `isAgent`-gated apply-to-all) — citation verified
at `esign-v3-complete-spec.md` l. 1386.

### 6. No mark is ever auto-placed for a party — **CONFORMS** (§5.4, §2, §14)

> "The system **never auto-carries a party's prior consent onto the amended version and never
> auto-adds a mark on anyone's behalf.** This is the conservative, legally-safe choice Johan calls
> *'the court-kicking-you-into-jail decision'*" — §5 ¶4

> "no client mark is ever placed without a click; the audit shows a consent event per surface" — §14

Note for the record: the agent professional profile *does* place marks across surfaces from one
approval — that is not a violation, because the doctrine's prohibition is scoped to a **party**. The
chapter keeps the two cleanly separated ("the professional profile is never the substitute for a
required party consent", §2).

### 7. Special clauses can demand all parties individually — **CONFORMS** (§2)

> "**Special clauses can still demand every party individually.** Independent of profile, some
> surfaces require **each party to consent in their own right** — e.g. a **letting-agreement
> termination clause** where every co-tenant must individually initial. The pack/template declares
> which surfaces are *individual-consent-required*; the ceremony will not advance until every named
> party has personally gated that surface." — §2

Carried into config as a knob: "Individual-consent surfaces | which clauses require every party
personally (§2) | per template (e.g. letting termination = on)" — §12.

### 8. Strikes visible + initialed — **CONFORMS** (§3)

> "A non-applicable clause is **rendered struck-through and initialed — never hidden.** … the losing
> branch stays on the page with a strike through it, and the responsible party **initials the strike**
> as a deliberate act. Removing the clause from view is forbidden; the struck, initialed clause is
> the **evidence** that the parties saw the alternative and consciously excluded it." — §3

Explicitly retires the three inconsistent mechanisms the field-intelligence harvest found ("one typed
strike/branch behaviour", §3) and enumerates the OTP's nine decision points.

### 9. Group-sequential; agent checkpoints between groups; group order per pack template; OTP purchasers→agent→sellers→agent; deadline timer visible — **CONFORMS** (§4) — all five elements

> "Signing proceeds **party-group by party-group**. **Within a group**, signing is **sequential**
> (member after member). **Between groups**, there is an **agent approval checkpoint** — the ceremony
> pauses, the agent reviews everything the just-finished group did, and only the agent's approval
> releases the next group. **Group order is configurable per pack template.**" — §4

> "**OTP:** `purchasers → agent → sellers → agent`. The **irrevocable-until countdown timer is
> visible to all parties** throughout" — §4

> "**Mandate:** `sellers → agent`." — §4

Checkpoint is correctly bound to the existing Agent Approval Gate (`approveAndAdvance()`, V2 §5)
"promoted from per-signer to per-group and made configurable" — §4.

### 10. Amendments: retain prior marks · attention drawn · all parties initial · agent approves · full version audit — **CONFORMS** (§5) — all five elements

> "**Any party may propose a change** to a condition or clause **mid-flow.**" — §5.1
> "The **agent approves** the amendment (agent is always the gatekeeper of the document's terms)." — §5.2
> "prior signatures and initials are **RETAINED, not voided**" — §5.3
> "the changed clause is **highlighted and navigated-to — no hunting**" — §5.3
> "**Blast radius (answer 2, settled): ALL parties, ALWAYS — no auto-added marks, ever.** … including
> a party who already finished and sits upstream of the change." — §5.4
> "A **full version audit trail** is kept: who proposed what, when, what changed, which version each
> original mark and each amendment-initial belongs to." — §5.5

Correctly reconciled with the settled rule (all parties initial **only the new content**, not re-sign
the whole document) — citation verified at `esign-v3-complete-spec.md` l. 612 (§7.5.7 "Initialing
Cascade"), which reads verbatim: *"After agent approves a change, all parties must **initial only the
new content** — not re-sign the entire document. This matches SA wet-ink practice exactly."*
The chapter's synthesis: "**all parties (blast radius) · only the new content (scope) · every mark
deliberate (no auto-add)**" — §5.

### 11. Hard block on signing after mandate-expiry / irrevocable date — **CONFORMS** (§11-A.1)

> "**A signature placed after that date is null and void.** So the system **hard-blocks signing on a
> lapsed ceremony** — the signing surface will not accept a mark; there is no 'just sign it anyway.'
> A mark that would be legally worthless is never collected in the first place." — §11-A.1

> "the clock is not decoration, it is the enforcement boundary. When it hits zero, the pen stops
> working." — §11-A.1

### 12. Extension = visible strike-and-fill + ALL parties initial → revival; sole resurrection path; lapse/extension/revival/re-lapse first-class — **CONFORMS** (§11-A.2, §11-A.3)

> "It is executed as a **visible strike-and-fill**: the **old date is struck through and preserved on
> the page** (never erased), and the **new date is entered alongside it** — *'13 July → 15 July must
> be SEEN as changed.'*" — §11-A.2

> "It requires **ALL parties to initial next to the change** … **Only when every party has initialed**
> does the ceremony **revive** … Extension is the **sole** resurrection path — there is no other way
> to un-lapse a document." — §11-A.2

> "Model **lapse → extension-proposal → revival → re-lapse** as **first-class ceremony states**, each
> timestamped and attributed on the evidence timeline (§6)" — §11-A.3 (all four states enumerated
> with definitions)

The all-party initial rule is correctly made **non-configurable**: "**all-party initial is fixed, not
configurable** (legal integrity)" — §12.

### 13. Tracker = evidence; lapse attribution proves who sat on it — **CONFORMS** (§6)

> "**The tracker is audit-ready evidence (refinement C).** The ceremony timeline is not just a
> convenience view — it is a **timestamped attribution record**." — §6

> "The purpose is **attribution**: when an offer lapses, the record **provably shows which party sat
> on the document past the deadline** — so the agent doesn't take the flack for a lapse they didn't
> cause; **the record assigns it.**" — §6

> "**Lapse events are first-class.** … an **evidence report** … in a form fit to show a principal, a
> party, or the Ombud." — §6

Handoff / view / nudge / deadline-breach all named as recorded events; entries "immutable and
attributed to an identity".

### 14. Juristic entity + 1..n natural signatories who become linked contacts — **CONFORMS** (§7)

> "A juristic party is modelled as **the entity plus its authorised natural-person
> signatory/signatories (1..n)**. The **authority documents** that prove each signatory's mandate to
> bind the entity are **carried in the pack**" — §7

> "**Signatories become CoreX contacts (refinement D).** The authorised natural persons who sign for
> an entity are **added / linked as CoreX `Contact` records tied to the entity** — not captured as
> free-text names on a page. The system therefore **knows *who* the parties are**, structurally" — §7

> "a trust with two trustees who must both sign is two signatory gates under one entity party" — §7

Correctly wired to the Contact pillar and Match-or-Create ("capture a person once, link them
everywhere", §7) and to §6's evidence ("'which party sat on the document' resolves to a **known
contact**").

### 15. Witnesses optional, default off — **CONFORMS** (§8)

> "**Witnesses are an optional role per ceremony, default OFF.** When enabled, witness surfaces become
> gated signing surfaces like any other party." — §8

> "Default off means the common case (no witnessing) is frictionless; enabling is a per-ceremony
> toggle, with an agency default." — §8

Knob present in §12 ("Witnesses | on/off per ceremony | **off**").

### 16. Amounts-in-words auto — **CONFORMS** (§9)

> "**Every money field auto-generates its amount-in-words.** The agent enters the figure once; the
> words are derived (ZAR), never hand-typed." — §9

> "the words are a derived, locked companion to the figure — consistent, correct, and impossible to
> mismatch." — §9

### 17. Values are editable fields; templates ship empty-for-completion — **CONFORMS** (§11.1, §16.3)

> "**Fee is an EDITABLE FIELD, not a constant (answer 3).** The '7.5% + VAT' the field-intelligence
> saw printed on the EATS PDF was a **DocuPerfect agent-fill on that example**, not a template
> constant — **templates ship empty-for-completion.** So professional fee (and every 'such value') is
> a **fillable field**, prefilled from the deal/mandate commission and overridable by the agent like
> any other." — §11.1

Correctly recorded as superseding the field-intelligence note that misread the printed value as baked
text ("This supersedes the field-intelligence note that read the printed value as a fixed constant —
it is agency/deal data, not baked text", §11.1). The knob in §12 says the default is "prefilled into
the (always-editable) fee field … never a printed constant".

### 18. FICA office section mirrors the existing FicaSubmission flow, cited — **CONFORMS** (§11.3, §0.1)

> "**The office zone MIRRORS the existing FICA module — reference, do not redesign (answer 4).** CoreX
> already has the verification flow: `FicaSubmission` carries the **risk_rating (1-3)** and
> **verification_method**, and moves `submitted → agent_approved ('Awaiting CO Approval') →
> approved`, with a **`FicaComplianceOfficer`** role doing the final approval (V2 §9). The FICA slot's
> office/verification stage **is that flow** — the ceremony hands the submitted questionnaire into the
> existing FICA module rather than inventing a second signer surface." — §11.3

> "the sequence is: **client completes + signs the questionnaire → agent checkpoint → the existing
> FICA module captures risk rating + verification method → compliance-officer approval.**" — §11.3

Cited by file in §0.1: "`app/Models/FicaSubmission.php`, `FicaComplianceOfficer`; V2 §9".

### 19. OTP two-act offer→acceptance — **CONFORMS** (§10)

> "The Offer To Purchase is a **two-act offer→acceptance ceremony conducted under the irrevocable
> deadline.**" — §10

> "**Act I — Offer.** The **purchaser(s)** complete and sign the offer (group one). The offer becomes
> **irrevocable until the stated deadline** … **Agent checkpoint** between acts. **Act II —
> Acceptance.** The **seller(s)** accept and sign under the live countdown; the **practitioner** signs
> (accepting the benefits of the commission clause — the *stipulatio alteri* signature …)" — §10

Lapse-before-acceptance correctly routed to §11-A ("the offer **lapses hard** — signing is blocked and
revival is only via a strike-and-fill date extension").

### 20. Ceremony recipients sourced from PROPERTY-LINK ROLES, not global contact types — **WAS SILENT → NOW RESOLVED (§2.1)** ✅

> **CLOSURE (2026-07-11, Johan).** Johan ruled this exact doctrine on DR2 capture the same day, in his
> own words: *"Parties should not be linked based on their contact assignment, but on their link to the
> property; a seller in contacts can be linked to a property as a buyer."* He confirmed the ruling
> extends to ceremony recipient sourcing. The chapter now carries **§2.1**, which states it verbatim and
> makes the property-link role the primary source, demoting `contact_types.esign_role` to a **fallback
> filter used only where no property link exists**. §0.1 gains an **override row** severing the
> inheritance from V2 §13, and §14 gains the matching acceptance bullet ("The right people are
> offered"). Committed to `main`. **The audit finding below is retained as the provenance of that
> change — it is the argument the ruling answers.**

**The finding as audited (before closure):**

**There is no quote to give. The chapter never addresses recipient sourcing.** An exhaustive grep for
`property-link`, `property_contacts`, `contact type`, `contact_type`, `party role`, `linked role`
returns **nothing** on this subject. The closest the chapter comes is §13, which merely lists the
agent's job as:

> "**Documents → New Signing** / **from a deal or listing** — agent: launch a ceremony, resolve slot
> variants, **choose parties**, toggle witnesses." — §13

"Choose parties" — with no statement of *where the candidate parties come from*.

**Why this silence is dangerous rather than merely incomplete.** §0.1 is an explicit contract that the
chapter **extends** settled doctrine and does not restate it:

> "Much of what this chapter needs is **already decided and partly built** in the settled specs. This
> chapter **extends** those decisions; it does not restate or replace them." — §0.1

So anything V3 does not override, it **inherits**. And the settled doctrine on this exact question is
`claude_esignature_v2_spec.md` **§13 "Contact Filtering"**, which prescribes the **global contact
type** model verbatim:

> "### Solution: esign_role on contact_types
> - New column `contact_types.esign_role` (varchar: seller/buyer/lessor/lessee/null)
> - Set via dropdown in Settings → Contact Types" — V2 §13

> "Template signing_parties contains 'owner_party' → Show contacts where contact_type.esign_role IN
> ('seller', 'lessor')" — V2 §13 (Filter Logic — To Be Built)

And V2 **§17 Build Priority** has it **queued as the next-but-two thing to build**:

> "3. **Bug #1: Contact filtering** — wire esign_role into wizard Step 3 + rental property support" — V2 §17

**Net effect:** doctrine point 20 says recipients come from the **property-link role** (this seller, on
*this* property). Settled doctrine says they come from the contact's **global type** (a contact typed
"seller" agency-wide). V3 is silent, so the settled — wrong — model stands, and it is scheduled.

**This is not a theoretical distinction.** A contact is a seller *on one property* and a buyer *on
another*; a global `contact_types.esign_role` cannot express that, so the wizard will offer the wrong
parties the moment a contact appears in two deals in different roles.

**The mechanism doctrine wants already exists and is already loaded:**

- `app/Models/Property.php:506` — the property↔contact relationship carries the role on the pivot:
  `->withPivot('role')`
- `app/Http/Controllers/Docuperfect/ESignWizardController.php:494` — the wizard **already** eager-loads
  it: `Property::with(['contacts' => fn($q) => $q->withPivot('role')])->find($propertyId)`
- `contact_types.esign_role` exists on `ContactType` but, per V2 §13, is **"NOT YET WIRED INTO WIZARD"**

So the pivot role is *sitting in the wizard's hand already*, while the settled build item would wire the
global type instead. There is also live precedent in the DR2 lane — commit `cc5a4cfd`, *"parties by
property-link role"* — which the ceremony chapter does not cite.

**Recommendation for Johan's verdict → ACCEPTED AND SHIPPED.** The recommendation was: add **§2.1 "Who
the parties are"** resolving recipients from the **property-link role pivot**, demote
`contact_types.esign_role` to a no-property-link fallback, and explicitly supersede V2 §13 / §17-item-3.
Johan authorised it on his existing DR2 ruling; **§2.1 is now in the chapter on `main`**, with the
override row in §0.1 and the acceptance bullet in §14.

**Residual action — the build queue, not the spec:** V2 **§17 item 3** still reads *"wire esign_role
into wizard Step 3"*. The spec is now correct; the **queue is not**. It must be re-pointed to the
property-link role before any build touches recipient sourcing. **Flagged for the ticket.**

---

## C. Structural defects (non-doctrinal, trivial) — **BOTH FIXED, authorised by Johan 2026-07-11**

1. ~~**§15 does not exist — the chapter jumps §14 → §16.**~~ **FIXED.** The final addendum had renamed
   the old §15 "Open questions" to §16 "Doctrine decisions" and left the number vacant. §16 renumbered
   to **§15**; the one cross-reference in the header addendum ("now §16 Decisions") updated to §15.
   Headings now run 12 → 13 → 14 → 15 contiguously, with no dangling §16 reference anywhere in the doc.

2. ~~**§0's one-line doctrine is slightly stale.**~~ **FIXED.** The one-line doctrine predated
   refinement E and omitted lapse/revival. It now carries the clause: *"a document dies at its legal
   date — signing on a lapsed ceremony is hard-blocked, and the sole way back is a visible
   strike-and-fill date extension that every party initials"*, making the summary lossless against
   §11-A and §14.

Both fixes are in the copy committed to `main` (see §F).

---

## D. Open questions in the chapter — **there are none left**

Point-of-fact for the conductor: the chapter carries **zero open questions**. §16 exists precisely to
record that they were all closed, verbatim:

> "The open questions from the prior draft are all resolved by Johan's final addendum. Recorded here so
> the resolution is on the page, not just in the section it touched" — §16

All five are answered in §16 (Disclosure re-serve → §11.4 · amendment blast radius → §5 · fee as field
→ §11.1 · FICA office-zone signer → §11.3 · deadline authority → §11-A).

The only open question this audit raised was **point 20 — now also closed** by Johan's DR2 ruling (§2.1,
§B.20). **The ceremony doctrine therefore carries no open questions.** What remains is not a doctrine
question but a **build-queue correction**: V2 §17 item 3 must be re-pointed off `esign_role` and onto the
property-link role (§A).

---

## E. Full section headings (as they appear)

| Line | Heading |
|------|---------|
| 1 | `# CoreX OS — E-Sign Ceremony V3 (Doctrine Chapter)` |
| 19 | `## 0. What this extends (so nothing is reinvented)` |
| 62 | `## 0.1 Reconciliation with settled e-sign doctrine (cite, don't reinvent)` |
| 81 | `## 1. Packs are slot-based` |
| 116 | `## 2. Two consent profiles — adopt-once for all, gate-per-surface for clients` |
| 156 | `## 3. Strikes are visible` |
| 175 | `## 4. Group-sequential flow with agent checkpoints` |
| 203 | `## 5. In-ceremony amendments — the kicker (refinement B: retain marks, re-circulate)` |
| 247 | `## 6. Live tracker — and the tracker is evidence (refinement C)` |
| 280 | `## 7. Juristic (entity) parties` |
| 313 | `## 8. Witnesses` |
| 326 | `## 9. Automatic amounts-in-words` |
| 338 | `## 10. OTP is a two-act ceremony` |
| 366 | `## 11. Per-document ceremony choreography (cross-referenced to the field inventory)` |
| 372 | `### 11.1 Exclusive Authority To Sell (EATS) — Mandate slot, Exclusive variant` |
| 391 | `### 11.2 Offer To Purchase (OTP) — two-act (§10)` |
| 406 | `### 11.3 FICA Natural Person (Schedule 4) — FICA slot, Natural variant` |
| 426 | `### 11.4 Seller Mandatory Disclosure V7 (+ Addendum B) — Disclosure slot` |
| 453 | `## 11-A. Expiry, lapse & revival — the rules of war (refinement E)` |
| 459 | `### 11-A.1 Hard lapse — signing on a dead document is blocked` |
| 468 | `### 11-A.2 Extension is the sole resurrection path — a visible strike-and-fill amendment` |
| 482 | `### 11-A.3 First-class states with a full evidence timeline` |
| 501 | `## 12. Agency-configurable thresholds (everything with a knob)` |
| 525 | `## 13. Navigation & placement (nav entry same day — non-negotiable #2)` |
| 542 | `## 14. What "done" looks like (business acceptance)` |
| 578 | `## 15. Doctrine decisions (the five questions — now answered)` |

*(Line numbers are those of the committed copy on `main`, post-fix. The chapter as originally audited
had this section numbered §16 with no §15 — corrected per §C.1.)*

---

## F. Provenance — the spec was lost and recovered

`.ai/specs/esign-ceremony-v3.md` and `.ai/specs/esign-field-intelligence.md` were **untracked** in the
`corex-dev-3` worktree (per the spec-sync rule, specs are committed to `main` by Johan, so cc3 correctly
left them uncommitted). That worktree has since been switched to `AT-217-dr2-dr1copy` and **both files
were gone from disk** — no commit on any local or remote ref ever contained them.

Recovered from Claude Code's file-history cache for the authoring session
(`249f5847-4711-43ef-b2f8-2a2e23dc230d`), taking the final versions (ceremony `@v4`, field-intelligence
`@v2` — both stamped 2026-07-11 08:40, i.e. after Johan's final addendum), and restored to:

- `/mnt/HC_Volume_103099143/corex-dev-5/.ai/specs/esign-ceremony-v3.md` (606 lines)
- `/mnt/HC_Volume_103099143/corex-dev-5/.ai/specs/esign-field-intelligence.md` (729 lines)

Content integrity confirmed against the memory record of the addendum (§11-A present, §16 decisions
present, refinements A–E all applied).

**ACTION FOR JOHAN:** these two specs are still **untracked and therefore still one branch-switch away
from being lost again.** They need committing to `main`. Recommend doing that before any e-sign build
starts.
