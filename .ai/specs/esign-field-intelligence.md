# E-Sign Field Intelligence — HFC's four core documents

> **Status:** investigation deliverable (read-only harvest of live data). NOT a build spec.
> **Feeds:** the e-sign ceremony spec Johan + Andre are drafting. Pairs with
> `.ai/specs/claude_esignature_v2_spec.md` (wizard/roles/CDS conventions) and
> `.ai/specs/esign-document-compiler-spec.md` (the CDS compile engine, AT-177).
> **Scope:** the four current HFC templates — Exclusive Authority To Sell (EATS) V10,
> Offer To Purchase (OTP) V13 (Enviro Clause), FICA Natural Person Questionnaire V8
> (Schedule 4), Seller Mandatory Disclosure V7 (+ Addendum B).
> **Last updated:** 2026-07-11 (cc3).

---

## Why this document exists

The e-sign compiler (AT-177) needs to bind **every blank in every template to a typed
CoreX data-dictionary entry** — and to know **who fills it, when, and in what format**.
That truth doesn't live in the template structure; it lives in what real HFC agents have
actually written into thousands of completed documents on live. This document harvests
that fill-pattern truth, per document, so the ceremony spec can be written from evidence,
not guesswork.

## How this was harvested (methodology + provenance)

Each of the four documents is a legacy `render_type=pdf` DocuPerfect template: an uploaded
PDF rendered to per-page images, with **positional overlay fields** (`fields_json`) placed
at x/y coordinates. A field carries no text label — its meaning comes from the printed
document text beneath it. So each document was harvested three ways and cross-referenced:

1. **Blank structure** — the rendered template page images were read visually to transcribe
   every printed blank, numbered clause, strike/select choice point, and signature block.
2. **Who-fills + surfaces** — each template's `fields_json` was enumerated for
   `assigneeRole` (who completes the field), `type` (`placeholder` vs `signature`),
   `strikethroughType` and `options`/`selectedValue` (the strike/select choice points),
   and `pageIndex`+`position` (to correlate a field with the blank above it).
3. **Fill patterns** — 12–15 completed instances per document were sampled from
   `docuperfect_documents.fields_json` across the document's whole template family
   (the SB-2026 lineage + the current Shelly version) and across **different agents**
   (`owner_id`), to derive real-world format conventions.

**POPIA:** only *format patterns* were extracted. No real client name, ID number, address,
or personal amount appears in this document — every example is abstracted or masked.

## Reading the tables — the CDS-source legend

`Who fills` and `CDS source` follow the e-sign V2 conventions
(`claude_esignature_v2_spec.md` §3):

| Term | Meaning |
|------|---------|
| **agent-prefill** | The agent completes the field before sending (V2: `assignedTo=agent`, editable). |
| **client-at-signing** | The signing party fills/initials during the ceremony (owner_party / acquiring_party). |
| **office/staff-after** | Back-office completes after the client (FICA verification zone). |
| **CDS source: property** | Value is owned by the `Property` pillar (address, erf/scheme/title, freehold-vs-sectional, advertised price). |
| **CDS source: contact** | Value is owned by the `Contact` pillar (party name, SA-ID, contact details, marital status). |
| **CDS source: deal/mandate** | Value is owned by the `Deal`/mandate (commission %, mandate type/period, offer price, deposit, dates). |
| **CDS source: manual-only** | No CoreX pillar owns it — the party's own knowledge (disclosure answers, source of funds). |

**Signing-role vocabulary** (V2 role map): seller → `owner_party`, buyer → `acquiring_party`,
agent/practitioner → `agent`, witness → `witness`. A document's declared roles live in
`docuperfect_templates.signing_parties`.

---
## Cross-document findings (read this before the per-doc tables)

Four documents, harvested independently, tell one consistent story. These patterns
hold across EATS, OTP, FICA and the Disclosure — they are the load-bearing findings
for the ceremony spec.

### 1. Every signature surface is printed-only — the overlay captures almost none of them
Across all four templates there are **effectively zero `type=signature` overlay fields**
(FICA has a single, mis-placed one; EATS, OTP and the Disclosure base have none). Every
clause-signature and final block Johan named — EATS §2.6 / §2.7.1 / §2.7.4 + final block;
OTP purchaser + 2 named witnesses, seller + 2 named witnesses, practitioner + co-sign;
FICA client + office/staff; Disclosure seller-at-mandate + purchaser-at-offer + Addendum B
— **exists only as printed underlines in the page image.** The only signing surfaces that
*are* digital are the per-page `initial` fields (present on all four).

**Johan's topology maps are all correct against the documents** — the divergence is at the
data layer, not the paper. **Consequence for the CDS:** every signing surface and its role
must be modelled as first-class declared data and injected at ceremony time (the
`SignatureSurfaceNormalizer` path), because it cannot be harvested from the current field
layer — it isn't there.

### 2. One generic filler role — no party routing exists yet
Every overlay field on all four templates is `assigneeRole=user` / `assignedTo=creator`.
There is **no seller / buyer / witness / agent / office distinction in the data.** So the
multi-party ceremonies are entirely unmodelled today:
- **FICA** — client fills + signs, then office/staff verifies (risk rating, method, staff
  signature). The office zone isn't even a set of fields (see §4 below).
- **Disclosure** — the *same physical document* is signed by the **seller at mandate stage**
  and re-served to the **purchaser at offer stage**. Two signing events, two lifecycle
  points, one document — none of it routed.
- **OTP** — purchaser, seller, two named witnesses per party, practitioner, and a co-signing
  practitioner — seven+ distinct signing identities, all flattened to one role.

**Consequence:** the CDS must declare the party topology and, critically, **multi-stage
recipient routing** — especially the Disclosure's two-stage *seller-at-mandate → purchaser-
at-offer re-serve*, which no current template enforces. The "agent-prefill vs client-at-
signing" split Johan wants is a **design target inferable from clause meaning**, not a value
present in the data.

### 3. Strike / select choice points are modelled three inconsistent ways
The "delete whichever is not applicable" decisions — the heart of the OTP — are handled
differently in every lineage:
- **EATS** — a uniform `strikethroughType=horizontal` capability on every placeholder; no
  discrete choice fields. Agent strikes the printed alternative.
- **OTP V13 (#25)** — **printed text only**; agents resolve by typing **"N/A"** into the
  losing branch. (Its own siblings #11/#12 *do* model some as real `strikethrough` overlays —
  the family is internally inconsistent.)
- **Disclosure** — real `tick` fields (`options:["Yes","No","N/A"]`), but **added per-document
  by the agent at signing, not baked into the template** — so a missed column silently drops a
  statutory answer.

**Consequence:** the CDS needs **one typed conditional-branch / choice field concept**, baked
into the published template so it cannot ship incomplete, and driven by a CDS source wherever
one exists — e.g. `property.tenure` selects freehold vs sectional (OTP §PROPERTY a/b; the
Disclosure SECTION-TITLE vs FULL-TITLE variant), deal flags select deposit method (OTP
1.1.1/1.1.2), second-property state (2.2.1/2.2.2), and VAT-vendor election.

### 4. The overlay captures agent-prefill identity only; the compliance content is printed & hand-resolved
What's actually stored as data is the agent's pre-fill of **identity + money + dates**. The
substantive compliance content is largely *not* captured:
- **FICA** — the office verification zone (risk rating 1/2/3, verification method, staff
  name/signature/date, the 7-row YES/NO checklist) has **no fields at all**; across 87 stored
  instances, zero values ever land there. It happens on paper after export.
- **Disclosure** — the §4 "additional information" explanation column is **almost always blank**
  even though the Act obliges a written explanation on any "Yes".
- **OTP** — the choice resolutions, witness names, and signature blocks are print-only.

**Consequence:** these are the highest-value binding targets — modelling them turns paper-and-
after-the-fact compliance into captured, enforceable, auditable data.

### 5. There is zero CDS wiring on the current templates
`cds_json`, `field_mappings`, `wizard_config`, `insertable_blocks`, `signing_parties` are
**all empty** on the four current templates. Nothing auto-prefills from Property / Contact /
Deal today — every value is hand-typed by the agent. **The `CDS source` column in every table
below is the design target to build, not an as-wired mapping.**

### 6. Format conventions the data dictionary should enforce
Consistent across the real fills:
- **Dates are inconsistent free-text** — `DD Month YYYY`, split `DD / MM / YYYY`, and ISO all
  appear; no `type=date` on the current templates. → the CDS must impose a typed date field.
- **Money is always digits + amount-in-words, paired** (OTP price / deposit / balance / bond;
  EATS gross price). → the CDS should auto-generate the words from the figure (ZAR), never ask
  the agent to type both.
- **SA ID = 13 digits, habitually appended into the name blank** (EATS, OTP, FICA all show
  `NAME SURNAME <13-digit ID>` in one field). → split name and SA-ID into typed fields with the
  SA-ID checksum validation the compiler spec already calls for.
- **FFC = 7 digits**; **agency share = a 2–3 digit %**; **commission** is hard-printed 7.5%+VAT
  on EATS but a fillable figure/% on the OTP. → one typed commission concept sourced from the
  deal/mandate.
- **Per-page initials** are the one reliable digital signing anchor on every document — a working
  surface the CDS can keep.

### The four-pillar ownership map (where each value comes from)
| Pillar | Owns (examples across the four docs) |
|--------|--------------------------------------|
| **Property** | address, erf / stand no, sectional scheme + section/unit no, **tenure (freehold vs sectional — drives multiple choice branches)**, complex/estate, township, district, levy, rates, advertised price |
| **Contact** | party full name, **SA-ID / passport**, citizenship, residential + postal address, phone, email, SARS tax no, marital status, VAT-vendor status |
| **Deal / Mandate** | mandate type + period, commission %, offer price, deposit + securing method, balance, bond amount + deadline, occupation date, occupational rent, nominated conveyancer, all deadline dates |
| **Manual-only** | disclosure condition answers, FICA source-of-funds / source-of-wealth / PEP declarations, OTP special conditions / inclusions / exclusions |

---

## Per-document field intelligence

The four sections below are the detailed harvest — each has a field inventory, the strike/
select decision points, the signature/initial surface map (verified against Johan's stated
topology), and the template-vs-practice divergences. `CDS source` columns are design targets
(finding §5). All examples are POPIA-masked patterns.
## Exclusive Authority To Sell (EATS) V10 — template #27

Sole-mandate document a seller signs to appoint Home Finders Coastal. 3-page
`render_type=pdf` template ("Shelly EATS (V10)"), letterhead: Johan and Elize
Properties T/A Home Finders Coastal. Live overlay = **29 positioned fields**
(27 text `placeholder` + 2 page-corner `initial`), all `assigneeRole=user`,
every field carries `strikethroughType=horizontal` (uniform strike capability).
**No `type=signature` fields exist in the overlay** — see divergences.

### Field inventory
| # | Page/Clause | Field (business meaning) | Who fills | Format pattern (from real fills) | CDS source | Notes |
|---|-------------|--------------------------|-----------|----------------------------------|------------|-------|
| 0–1 | P1 intro "I ___ / We ___" | Seller name(s) (nf14 "Seller Name") | agent-prefill | Free text, often ALL CAPS; frequently `NAME SURNAME ID <13-digit SA ID>`; multiple sellers joined `&` / `AND`; len seen 11–129 | contact (owner_party) | Two overlays for the I / We split; ID number habitually appended into the same blank |
| 2 | P1 "Property Erf / Sectional Scheme / Unit no" | Erf / unit number (nf10 "Property Number") | agent-prefill | Digits; `999`, `999/9999` (erf/portion), `SS xxx 99`, occasionally range `99-9` | property | The "Erf / Sectional Scheme / Unit" trio is a strike-choice (see below) |
| 3–4 | P1 "Complex / Estate known as ___" | Complex / estate name (nf11 "Complex") | agent-prefill | Free text; **overwhelmingly `N/A`** for freehold; "Complex / Estate" is a strike-choice | property | Two overlays (name + trailing) |
| 5 | P1 "___ (Street)" | Street address (nf12 "Street") | agent-prefill | `<no> <street name>` or street name only | property | |
| 6 | P1 "___ (Township)" | Township (nf13, default value "Ray Nkonyeni") | agent-prefill | Consistently the KZN South Coast municipality (12-char, ~"Ray Nkonyeni") | property | Field seeded with the default township value |
| 7 | P1 "___ (District)" / Seller-1 physical address | District & Seller-1 domicilium (nf15 "Seller address") | agent-prefill | Free text address, e.g. `<no> <street>, <suburb>` | property / contact | Serves district + first seller physical address line |
| 8–18 | P1 §1 Domicilium — Seller 1–4 Tel/Email/address rows | Seller contact rows (nf16 "Seller", 11 overlays) | agent-prefill | Tel = `9999999999` (10-digit); Email = `xxx@xxx.xxx`; **Seller 2/3/4 rows almost always `N/A`** | contact | Only Seller 1 typically populated; extra rows struck/`N/A` |
| 19 | P1 bottom-right corner | Page-1 initial (initial) | client-at-signing (seller) | Handwritten/typed initials | — | Per-page initial surface, not a clause signature |
| 20–22 | P2 §2.1 "gross price for the Property is R___ (___words___)" | Gross list price + amount-in-words (nf17 "Price") | agent-prefill | Digits SA format `9 999 999.99` / `999 999.99` / plain `9999999`; words = free text ("Two Million …"); no explicit "R" typed (printed on form) | deal/mandate (list price) | 3 overlays: R-amount + two words lines |
| 23–25 | P2 §2.3 "midnight on ___/___/___" | Mandate expiry date (nf18 "Expiry Date") | agent-prefill | Split day/month/year: `99` `99` `9999`, or day `99` / month `xxx` (e.g. "Jun") / year `9999`. Whole-date variant elsewhere in family = `YYYY-MM-DD` | deal/mandate (mandate period end) | Commencement is "on signature" (printed, no field) |
| 26 | P2 bottom-right corner | Page-2 initial (initial) | client-at-signing (seller) | Initials | — | Per-page initial surface |
| 27–28 | P3 §2.8 "Other: ___" | Free-text special conditions | agent-prefill | Free text; **usually left blank** | manual-only | Two ruled lines |

Note: §2.4 shows "7.5% per centum, plus VAT" on this example, and the harvest read
it as a printed constant with no fill blank. **CORRECTION (per Johan, 2026-07-11 —
see `esign-ceremony-v3.md` §11.1 / decision 3):** that printed value was a
**DocuPerfect agent-fill on this example, not a template constant.** Templates ship
**empty-for-completion**, so professional fee is an **editable field** prefilled from
the deal/mandate commission — not fixed. The §5 POPIA consent and §4 pledge are
static text, no fields.

### Strike / select decision points
Strikes are inline "delete whichever is not applicable" choices in the printed
text (each nearby placeholder carries `strikethroughType=horizontal`; there are
no discrete `options`/`selectedValue` choice fields):
- **P1 intro** "registered owner/s, **or** duly authorised representative/s" — strike the branch that does not apply. Agent decides. Default: keep "registered owner/s".
- **P1** "Property **Erf / Sectional Scheme / Unit no**" — strike the two of three that do not apply (Erf for freehold, Sectional Scheme/Unit for sectional title). Agent decides.
- **P1** "**Complex / Estate**" — strike one; in practice both struck or `N/A` for freehold. Agent decides.
- **P3 final block** "at ___ am / **pm**" — strike one. Filled at signing.
- **P3** §2.7.3 "(whether **personal or otherwise**)" — printed alternative, generally left intact.
- Any Seller 2/3/4 domicilium row not used — struck or written `N/A` (dominant real-world pattern).

### Signature & initial surface map
| Surface | Page/Clause | Role | Type (sign/initial) | Notes |
|---------|-------------|------|---------------------|-------|
| Page-1 corner initial | P1 bottom-right (field 19) | seller (owner_party) | initial | Per-page initial; NOT a clause signature |
| Page-2 corner initial | P2 bottom-right (field 26) | seller (owner_party) | initial | Per-page initial |
| §2.6 "Signature" line | P2 §2.6 (12-month post-withdrawal liability) | seller | sign (PRINTED line) | Present in document text; **NOT wired as an overlay field** |
| §2.7.1 "Signature" line | P2 §2.7.1 (no other agency/practitioner) | seller | sign (PRINTED line) | Present in document; **NOT an overlay field** |
| §2.7.4 "Signature" line | P2 §2.7.4 (material provisions explained) | seller | sign (PRINTED line) | Present in document; **NOT an overlay field** |
| Final block — Seller & Co-Seller/s | P3 | seller (owner_party) | sign | "Registered Owner/s or Duly Authorised Representatives"; PRINTED line, not an overlay field |
| Final block — Witness | P3 | witness | sign | PRINTED line |
| Final block — Property Practitioner | P3 "Exclusive Authority To Sell Property Practitioner" | agent | sign | PRINTED line |
| Final block — place / day / month / year / time / am-pm | P3 | filled at signing | text | "done and signed at ___ on ___ day of ___ 20__ at ___ am/pm"; PRINTED lines, not overlay fields |

**Johan's topology verification:** the THREE mid-clause signature points at
§2.6, §2.7.1, §2.7.4 **plus the final signature block are all confirmed in the
printed document** (page 2 shows three "Signature" underlines at exactly those
clauses; page 3 shows Seller & Co-Seller/Witness + Practitioner block). Johan's
map is **correct against the document.** The divergence is at the overlay layer,
not the document layer — see below.

### Template-vs-practice divergences
- **Signature surfaces are absent from the overlay.** `fields_json` contains
  **zero `type=signature` fields**; the only signing surfaces stored are the two
  page-corner `initial`s. The three §2.6/§2.7.1/§2.7.4 signature lines and the
  full P3 final block exist only as printed underlines with no positioned signing
  field. The ceremony spec must inject/normalise these signing surfaces (seller
  ×3 mid-clause + seller/co-seller + witness + practitioner in the final block) —
  they cannot be harvested from field data because they are not there. (Consistent
  with `render_type=pdf` templates relying on SignatureSurfaceNormalizer at signing
  time rather than stored surfaces.)
- **Commission is hard-printed, not a field.** §2.4 fixes 7.5% + VAT. Agents cannot
  vary commission on this template via a blank; any deviation would be handwritten.
- **All fields are `assigneeRole=user`** — the overlay does NOT distinguish
  seller vs agent vs witness. Every text blank is agent-prefill in practice, but
  the role metadata is flat and must be re-derived by clause meaning in the spec.
- **Extra seller rows (Seller 2/3/4) almost always `N/A`** — the domicilium block
  is built for 4 sellers; real docs fill Seller 1 only and write `N/A`/strike the rest.
- **`Complex/Estate` almost always `N/A`** for freehold stock.
- **Seller ID number is typed into the name blank**, not a separate field — agents
  append `ID <13 digits>` to the seller name free-text.
- **§2.8 "Other" left blank** in the overwhelming majority of docs.
- **Date entry is inconsistent** across the family: split day/month/year on
  #27/#41/#29 vs a single `YYYY-MM-DD` on #2/#4 — no enforced format.

### Harvest provenance
- Blank template read visually: page-0/1/2 PNGs for template #27 (3 pages).
- Overlay enumerated from live `docuperfect_templates.fields_json` id 27 — 29 fields.
- Fill patterns sampled across the sale-mandate family templates **[2, 4, 41, 5, 27, 29, 3]**
  (SB EATS, SB OATS, Shelly OATS V9, Dual, EATS V10, #29, #3).
- **~17 filled documents with values read**, across **7 distinct agents**
  (owner_id 22, 24, 25, 29, 32, 36, 38).
- **POPIA:** no real name, ID, address, email, or amount reproduced — all values
  abstracted to length + character-class + masked shape (`x`=letter, `9`=digit) only.
- Uncertain: template #27's own filled instance (doc 122) was blank (no values),
  so #27 fill patterns are inferred from its named-field siblings in #2/#4 (which
  share the identical Seller/Property/Price/Date named fields) and the positional
  #41/#29 twins. The named-field IDs (nf14 Seller Name, nf17 Price, nf18 Expiry
  Date, etc.) confirm the field-to-meaning mapping directly from the #27 template.
## Offer To Purchase (OTP) V13 — Enviro Clause — template #25

> **Structural headline (read first).** Live template #25 is a *flat* `render_type=pdf`
> overlay: **100 fields, ALL `assigneeRole="user"`**, types only `placeholder` (92) +
> `initial` (8). `is_esign=0`, `party_mode="shared"`, and **`signing_parties`,
> `field_mappings`, `cds_json`, `wizard_config`, `insertable_blocks` are ALL EMPTY**.
> There is **no CDS mapping, no role routing, no e-sign signing-party config, and NOT ONE
> `type=signature` field** on this template (nor anywhere in the OTP family). Every field's
> `strikethroughType:"horizontal"` is a **blank-underline render style, NOT a delete marker**
> — the STEP-2 probe's "strike=100 / choice=0" is an artefact of that style key, not 100 real
> choice points. The genuine "delete whichever is not applicable" choice points live **only in
> the printed body text**; in practice agents resolve them by typing **"N/A"** into the
> non-applicable blanks (observed 11× exact "N/A", plus the recurring 3-char "x/x"→N/A masked
> pattern at every choice location). Sibling templates #11/#12 DO model deletes as real
> `strikethrough`-type overlay fields (17 in #12) — **V13 #25 does not.** This is the single
> most important harvest finding.

### Field inventory
Positions are % of page (x,y). Business meaning derived from the printed page image at that
coordinate AND from `named_field_name` labels carried by sibling templates #11/#12/#26 at the
identical coordinate (template #25's own fields are unnamed positional overlays).

| # | Page/Clause | Field (business meaning) | Who fills | Format pattern (masked, from real fills) | CDS source | Notes |
|---|---|---|---|---|---|---|
| 0 | p1 header | Property Practitioner name | agent-prefill | `xxxxx xxxxxxxxx` (first last) | agent (User.name) | |
| 1 | p1 header | Contact No | agent-prefill | `##########` (10 digit) | agent (User.phone) | |
| 2–3 | p1 THE PARTIES | Seller name(s) + entity/ID | agent-prefill | `xxx xxx xxxxxxxxxxxx (xxx) xxx ####/######/##` — name + ID/reg no often on one line | contact (owner_party) | multi-line, freetext |
| 4 | p1 | Seller residing at (address) | agent-prefill | `## xxxxx xxxxx, xxxx xxxxx xxxx xxxxxx, xxxxxxxxx ####` | contact (address) | |
| 5–6 | p1 | Purchaser "I/We" name(s)+ID | agent-prefill | name + `(id) ############` ID number appended | contact (acquiring_party) | multi-line |
| 7 | p1 | Purchaser residing at (address) | agent-prefill | `xxxxxx xxx, xxxxxxx xx, xxxxxxxxx ####` | contact (address) | |
| 8 | p1 §PROPERTY(a) | **Freehold** Stand No | agent-prefill | `####` | property (erf_no) | freehold branch (a) |
| 9 | p1 (a) | Township | agent-prefill | `xxxxxxx` | property (suburb) | |
| 10 | p1 (a) | District | agent-prefill | `xxx xxxxxxxx` | property (district) | |
| 11 | p1 (a) | Street Address / Unit number | agent-prefill | `# xxxxxxxxx xxxxxxxx` | property (street_address) | |
| 12 | p1 (a) | (freehold complex cont.) | agent-prefill | often `N/A` when sectional used | property | choice-fill blank |
| 13 | p1 (a) | Complex / Estate known as | agent-prefill | `xxxxxxxx xxxxx xxx xxxxxx` | property (complex_name) | "(where applicable)" |
| 14 | p1 §PROPERTY(b) | **Sectional** Apartment/Townhouse No | agent-prefill | `#` | property | sectional branch (b) |
| 15 | p1 (b) | Section No | agent-prefill | `#` / `###` | property | |
| 16 | p1 (b) | Sectional Scheme (SS) No | agent-prefill | `###/####` | property (scheme_no) | |
| 17 | p1 (b) | building known as | agent-prefill | `xxxxx` | property (complex_name) | |
| 18 | p1 (b) | on stand no | agent-prefill | `####` | property (erf_no) | |
| 19 | p1 (b) | in (Street) | agent-prefill | `xxxxxx xxxxx` | property (street_address) | |
| 20 | p1 (b) | of (Township) | agent-prefill | `xxxxxx` | property (suburb) | |
| 21 | p1 (b) | of (District) | agent-prefill | `xxx xxxxxxxx` | property (district) | |
| 22 | p1 | Refer to attached house rules | agent-prefill | free / often blank | manual-only | sectional only |
| 23 | p1 | Levy R …/pm | agent-prefill | `####.##` | property (levy) / manual | |
| 24 | p1 | Municipal fees R …/pm | agent-prefill | `####.##` | property (rates) / manual | |
| 25 | p1 | Special Levy R (if any) | agent-prefill | `N/A` common | property / manual | |
| 26 | p1 | Including exclusive use areas | agent-prefill | free / blank | property / manual | sectional only |
| 27 | p1 | Per-page initial | client-at-signing | initial | contact (both parties) | |
| 28 | p2 §1 PURCHASE PRICE | Purchase Price R (digits) | agent-prefill | `R # ### ### - ##` OR `# ### ###.##` | deal (offer price) | see price note |
| 29–30 | p2 §1 | Purchase Price (amount in words) | agent-prefill | `xxx xxxxxxx xxxxx xxxxxxx …` (multi-line) | deal (derived) | words always given alongside digits |
| 31 | p2 §1.1 | Deposit amount R | agent-prefill | `# ### ###.##` | deal (deposit) | |
| 32–33 | p2 §1.1 | Deposit amount in words | agent-prefill | `xxxx xxxxxxx xxx xxxxx xxxxxxxx xxxx` | deal (derived) | |
| 34 | p2 §**1.1.1** | Deposit lodged with Conveyancer by midnight of (Date) | agent-prefill | date freetext / `N/A` if 1.1.2 chosen | deal (deposit_due_date) | **choice 1.1.1** |
| 35 | p2 §**1.1.2** | Bankers-guarantee delivered by midnight of (Date) | agent-prefill | date / `N/A` if 1.1.1 chosen | deal | **choice 1.1.2** |
| 36 | p2 §1.2 | Balance R | agent-prefill | `# ### ###.##` | deal (balance) | |
| 37–38 | p2 §1.2 | Balance in words | agent-prefill | words | deal (derived) | |
| 39 | p2 §2.1.1 | Bond obtained by midnight of (Date) | agent-prefill | date freetext | deal (bond_deadline) | |
| 40 | p2 §2.1.1 | Loan not less than R | agent-prefill | `# ### ###.##` | deal (bond_amount) | |
| 41 | p2 §2.1.1 | Loan amount in words | agent-prefill | words | deal (derived) | |
| 42 | p2 | Per-page initial | client-at-signing | initial | contact | |
| 43–56 | p3 §**2.2** SALE OF SECOND PROPERTY | Second-property stand/section, street, township, district, agreement dated, gross price R + words, deadline days | agent-prefill | mix: `####`, `## xxxx ####` date, `# ### ###.##`, words; whole block often `N/A` | deal / manual | **choice 2.2.1 vs 2.2.2** — see decision table |
| 57 | p3 | Per-page initial | client-at-signing | initial | contact | |
| 58 | p4 §3.1 OCCUPATION | Vacate / occupation date | agent-prefill | `## xxxx ####` (DD Month YYYY freetext, NOT ISO) | deal (occupation_date) | |
| 59 | p4 §3.2 | Occupational rent R /month | agent-prefill | `## ###.##` | deal (occ_rent) | |
| 60 | p4 §3.2 | Occupational rent in words | agent-prefill | words | deal (derived) | |
| 61 | p4 | Per-page initial | client-at-signing | initial | contact | |
| 62 | p5 §5 PROFESSIONAL FEE | Professional fee R (on 7.5%) | agent-prefill | `### ###.##` | deal (commission) | 7.5% printed; a %-override blank exists in siblings |
| 63 | p5 §5 | Professional fee in words | agent-prefill | `xxx xxxxxxx xxx xxxxxx xxxxxxxx xxxx` | deal (derived) | |
| 64 | p5 §5.6 | Listing Agency name | agent-prefill | `xxxxx xxxxxxxxx` | agent / agency | commission-split |
| 65 | p5 §5.6 | Listing Agency FFC | agent-prefill | `#######` (7-digit) | agent (FFC) | |
| 66 | p5 §5.6 | Listing Agency share % | agent-prefill | `##` / `###` | deal / manual | |
| 67 | p5 §5.6 | Selling Agency share % | agent-prefill | `##` / `###` | deal / manual | |
| 68 | p5 §5.6 | Selling Agency name | agent-prefill | `xxxxx xxxxxxxxx` | agent / agency | |
| 69 | p5 §5.6 | Selling Agency FFC | agent-prefill | `#######` | agent (FFC) | |
| 70 | p5 | Per-page initial | client-at-signing | initial | contact | |
| 71–72 | p6 §7 DOMICILIUM | Seller physical address | agent-prefill | `## xxxxx xxxxx, xxxx xxxxx xxxx xxxxxx, xxxxxxxxx` | contact (address) | |
| 73 | p6 §7 | Seller fax number | agent-prefill | `##########` / blank | contact | often blank |
| 74 | p6 §7 | Seller email address | agent-prefill | email | contact (email) | |
| 75–76 | p6 §7 | Purchaser physical address | agent-prefill | address | contact (address) | |
| 77 | p6 §7 | Purchaser fax number | agent-prefill | blank common | contact | |
| 78 | p6 §7 | Purchaser email address | agent-prefill | email | contact (email) | |
| 79 | p6 §10 TRANSFER | Conveyancer nominated | agent-prefill | `xxx xxx & xxxxx xxx` (firm) | deal (attorney) / supplier | |
| 80 | p6 | Per-page initial | client-at-signing | initial | contact | |
| 81 | p7 §11 FIXTURES | Alarm radio-transmitter Security Company | agent-prefill | firm name / blank | manual-only | |
| 82–83 | p7 §11 | Specific Inclusions (2 lines) | agent-prefill | free text / blank | manual-only | handwritten-style |
| 84–85 | p7 §11 | Specific Exclusions (2 lines) | agent-prefill | free text / blank | manual-only | |
| 86 | p7 | Per-page initial | client-at-signing | initial | contact | |
| 87 | p8 §14 COOLING OFF | Termination notice by 5pm on (date) | agent-prefill | date; only ≤ R250k | deal (derived) / manual | |
| 88 | p8 §15 IRREVOCABLE OFFER | Offer irrevocable until midnight on (date) | agent-prefill | date freetext | deal (offer_expiry) | |
| 89–98 | p8 §17 OTHER UNDERTAKING/CONDITIONS | 10 free condition lines | agent-prefill | long free text (obs. 558-char single fill) | manual-only | free-form clause bay |
| 99 | p8 | Per-page initial | client-at-signing | initial | contact | |
| — | p9 §18 SIGNATURE | **(no overlay fields — see signature map)** | — | — | — | zero fields on final page |

### Strike / select decision points  (THE CORE OF THIS DOC)
On template #25 **none of these are overlay/`options`/`strikethrough`-type fields** — they are
printed "delete whichever is not applicable" instructions, resolved in practice by the agent
typing **"N/A"** into (or leaving) the non-applicable branch's blanks and completing the chosen
branch. (Siblings #11/#12 model some of these as real `strikethrough`-type overlays.)

| Choice point | Clause | Options (printed) | Who decides | How resolved in practice | CDS driver (if any) |
|---|---|---|---|---|---|
| **Freehold vs Sectional title** | §THE PROPERTY "(Delete (a) or (b) below)" | (a) Freehold Stand No … / (b) Apartment/Section/SS No … | agent | Completes the applicable branch's blanks (fields 8–13 freehold OR 14–21 sectional); non-applicable branch blanks typed `N/A` / left. No printed line struck on #25. | **property.property_type** (Freehold vs Sectional) drives which branch |
| **Deposit securing method** | §1.1 "delete whichever is not applicable" → **1.1.1 / 1.1.2** | 1.1.1 cash deposit with Conveyancer by (date) / 1.1.2 bankers guarantee by (date) | agent (per buyer's finance) | Chosen sub-clause's date filled (field 34 or 35); other left blank/`N/A`. Both dates rarely both filled. | deal (deposit method / deposit_due_date) — not structurally enforced |
| **Balance guarantee timing** | §1.2 "delete whichever is not applicable" → **1.2.1 / 1.2.2 / 1.2.3** | 1.2.1 30 days of bond grant / 1.2.2 7 days of 2nd-property transfer / 1.2.3 15 days of signature (no 2.1/2.2) | agent | Purely printed either/or — **no fill blank per option**; decided by which suspensive conditions apply. Selected by striking/circling on paper; NOT captured digitally on #25. | deal (has_bond? has_second_property?) — inferable, not mapped |
| **Second property** | §2.2 "delete 2.2.1 and/or 2.2.2" → **2.2.1 / 2.2.2** | 2.2.1 PROPERTY SOLD BUT NOT REGISTERED / 2.2.2 SALE OF SECOND PROPERTY STILL BE SOLD | agent | Whole §2.2 block (fields 43–56) filled for the applicable sub-clause; if no second property the entire block is `N/A`/blank. | deal (second_property flag + which state) |
| **Occupational-rent payee** | §3.2 "Conveyancer/Seller (delete whichever is not applicable)" | Conveyancer / Seller | agent | Printed inline choice; resolved by strike on paper. No overlay field for the payee on #25 (only the R-amount + words fields 59–60 are captured). | deal / manual — not mapped |
| **Occupation: tenant vs vacant** | §3.4 vs §3.5 "delete whichever is not applicable" | 3.4 property let to a Tenant (subject to tenant rights) / 3.5 Purchaser gets actual occupation | agent | Printed either/or, no fill blank; strike on paper. | property (is_tenanted) — inferable |
| **VAT vendor election** | §1.4 "he is / is not (delete whichever is not applicable) a registered vendor" | Seller **is** / **is not** a registered VAT vendor | agent (per seller) | Printed inline "is / is not"; resolved by striking the wrong one on paper. No overlay field on #25. | contact/deal (seller_vat_registered) — not mapped |
| Cooling-off applicability | §14 "only applicable to properties up to 250 000" | applies / N/A | agent | Date field 87 filled only when price ≤ R250k; otherwise blank. | deal.offer_price threshold |
| Commission split (listing/selling) | §5.6 | one agency vs two-agency split | agent | Fills listing + selling name/%/FFC rows (fields 64–69); single-agency deals fill one side, other `N/A`. | deal (commission split) / agency |

**All 6 Johan-named points located:** freehold-vs-sectional ✔, deposit 1.1.1/1.1.2 ✔,
guarantees 1.2.1/1.2.2/1.2.3 ✔, second-property 2.2.1/2.2.2 ✔, occupational-rent payee
(Conveyancer/Seller) ✔, VAT vendor is/is-not ✔ — **plus** cooling-off applicability,
tenant-vs-vacant occupation, and the commission split.

### Signature & initial surface map
**No `type=signature` fields exist on template #25 (or anywhere in the OTP family — 0 found).**
`is_esign=0`, `signing_parties` empty. The signature block is entirely **printed** on page 9 and
matches Johan's stated topology exactly, but is **wet-ink / not digitally captured** on this
template. Digital surfaces present = **8 per-page `initial` fields only** (one on pages 1–8;
page 9 has none).

| Surface | Page/Clause | Role | Type (sign/initial/witness) | Named? | Notes |
|---|---|---|---|---|---|
| Per-page initials ×8 | p1–p8, top-right (~x90 y92) | user (both parties) | initial | n/a | fields 27,42,57,61,70,80,86,99 — **the only digital signing surfaces** |
| Purchaser signature ×2 | p9 §18 | acquiring_party | sign (printed line) | line labelled "Purchaser" ×2 | printed, no overlay field |
| Purchaser witnesses ×2 | p9 §18 | witness | witness (printed line) | **YES** — "As Witness" + dedicated "Name of Witness" line under each | witnesses ARE named |
| Seller signature ×2 | p9 §18 | owner_party | sign (printed line) | line labelled "Seller" ×2 | printed, no overlay field |
| Seller witnesses ×2 | p9 §18 | witness | witness (printed line) | **YES** — "Name of Witness" line under each | |
| Property Practitioner | p9 §18 | agent | sign (printed line) | "Property Practitioner", "accepts benefits of clause 5" | stipulatio alteri signature |
| Practitioner Co-Sign | p9 §18 | agent (co-sign) | sign (printed line) | "Co-Sign (where applicable)" | second-agent co-signature |

Topology verdict: **matches Johan's stated map** (purchaser + 2 named witnesses; seller + 2
named witnesses; practitioner + co-sign) — witnesses **named = YES** — **but only in print**;
digitally the template captures none of these, only 8 page-initials.

### Template-vs-practice divergences
- **No digital signature surfaces.** Page 9's purchaser/seller/witness/practitioner/co-sign
  blocks are printed lines with zero overlay fields; `is_esign=0`. This template is a
  print-and-sign artefact, not an e-sign pipeline document. Major divergence from an
  overlay-driven signing model.
- **Choice points not modelled as fields.** All 6 Johan-named delete-choices are printed text
  only. Resolution is by typing "N/A" into the losing branch (11 exact "N/A" + recurrent "x/x"
  masked 3-char fills observed) — never a struck line or a `selectedValue`. Siblings #11/#12
  DO carry real `strikethrough`-type overlays (17 in #12), so the family is inconsistent;
  V13 #25 is the un-modelled one.
- **`strikethroughType:"horizontal"` is noise.** Present on all 100 fields as a blank-line
  render style; it is NOT a delete signal (the STEP-2 "strike=100" reading is misleading).
- **All fields single-role (`user`).** No buyer/seller/agent routing — `party_mode="shared"`
  means one filler completes everything; there is no per-role assignment to drive a ceremony.
- **Zero CDS wiring.** `cds_json`, `field_mappings`, `wizard_config`, `insertable_blocks` all
  empty → no auto-prefill from Property/Contact/Deal today; every value is hand-typed by the
  agent. The CDS-source column above is the *target* mapping, not what exists.
- **Always-hand/free-form bays:** §17 Other Undertakings (10 lines, one 558-char fill seen),
  §11 Specific Inclusions/Exclusions, alarm Security Company — free text, frequently blank.
- **Date formats inconsistent:** occupation/cooling-off/irrevocable dates are freetext
  (`DD Month YYYY`), while sibling templates use ISO `####-##-##` `type=date` fields — #25 has
  no `date`-type fields at all.
- **Price dual-format:** digits appear as either `R # ### ### - ##` (space groups, "- 00"
  cents) or `# ### ###.##` (decimal); the amount-in-words is **always** supplied alongside.
  FFC = 7 digits; agency share = 2–3 digit `%`.

### Harvest provenance
- **Templates:** #25 (target) fully transcribed from 9 rendered page images (page-0…page-8) +
  live `docuperfect_templates` row (fields_json 100 fields, plus render/esign config columns).
- **Filled instances read:** 23 documents across the family [11,12,25,26]; **8 distinct agents**
  (owner_id 26,32,35,36,38,39,41,44). Only **4 documents carried populated `value`s**
  (119 filled fields total) — and **template #25's own 4 instances were all empty drafts**, so
  fill-pattern truth comes from siblings **#11 (SB OTP)** and **#12 (SB OTP-VL)**, which share
  #25's page layout and additionally carry `named_field_name` labels that name each shared
  coordinate. This is the basis for the business-meaning column.
- **Value key discovered:** `value` (plus siblings add `named_field_id`/`named_field_name`,
  `assignedTo`; a `strikethrough` field *type* and one `selection` field exist in siblings, not
  in #25).
- **POPIA:** every real value was programmatically masked (digits→`#`, letters→`x`, structure/
  punctuation/`R`/`%` preserved) before it left the DB; no real name, ID, address, email, or
  price appears above.
- **Unreadable / gaps:** none unreadable. Gap = #25 has no filled instance of its own and no CDS
  config, so CDS-source column is the *design target*, not an as-wired mapping.
## FICA Natural Person Questionnaire V8 (Schedule 4) — template #33

**Doc:** "Shelly FICA Natural person (V8)" — `docuperfect_templates.id=33`, `render_type=pdf`, 5 pages.
**Live title on page:** "HOME FINDERS COASTAL QUESTIONNAIRE FOR NATURAL PERSONS" / "SCHEDULE 4" / "Version 8".
**Field layer:** 32 fields, ALL `assigneeRole=user` (single role). Types: 27 placeholder, 3 initial, 1 signature, 1 selection. Every placeholder carries `strikethroughType` (surface-normalizer artifact). FICA is **contact-role agnostic** — a seller, buyer, tenant, landlord or any party can be FICA'd; nothing in the field layer binds it to a deal role.

---

### Field inventory — CLIENT section

| # | Page | Field (business meaning) | Format pattern (masked) | CDS source | Notes |
|---|------|--------------------------|-------------------------|------------|-------|
| 0 | 0 | Q1 Client full name + SA ID / foreign passport (person completing) | Name text + `SA ID 13 digits YYMMDD•••••••`, OR bare 13-digit ID, OR foreign passport alphanumeric | contact (name + id_number) | **The one field almost always filled.** Combined "Name + ID" one-liner OR bare ID. |
| 1 | 0 | Q2 Are you SA citizen / permanent resident? | Free-text `YES` / `NO` (not a checkbox) | contact (citizenship flag) / manual | Observed value `YES`. |
| 2 | 0 | Q3 Address of main place of residence | One-line residential address, ~40 chars | contact (physical address) | Proof-of-address doc <2 months required (paper). |
| 3 | 0 | Q4 Telephone number + email address | Phone (9–11 digits) + email combined | contact (phone + email) | Rarely populated in stored e-sign data. |
| 4 | 0 | Q5 SA SARS income tax number | `tax no: 10 digits` (NUM10) | contact (tax_number) / manual | Optional ("if so"). |
| 5 | 0 | Q6 Principal's full name + SA ID (acting on behalf of) | Name + 13-digit ID / passport | manual (third-party principal) | On-behalf-of branch; usually blank for direct clients. |
| 6 | 0 | Q7 Principal SA citizen / PR? | `YES` / `NO` free text | manual | |
| 7 | 0 | Q8 Principal's residential address | Address one-liner | manual | |
| 9 | 1 | Q9 Principal telephone + email | Phone + email | manual | |
| 10 | 1 | Q10 Principal SARS tax number | 10 digits | manual | |
| 11 | 1 | Q11 Authority to act (auth letter / POA) | Short free text | manual | Copy of instrument required (paper). |
| 12 | 1 | Q12 Representative full name + SA ID | Name + 13-digit ID / passport | manual | Downstream-representative branch. |
| 13 | 1 | Q13 Source of Representative's authority | Free text | manual | |
| 14 | 1 | Q14 "Other" service description (line 1) | Free text | manual | The 4 radio options (sell/purchase/let/rent) are NOT wired as fields — only the "Other" free-text lines are captured. |
| 15 | 1 | Q14 "Other" service description (line 2) | Free text | manual | |
| 16 | 1 | Q15 How payments will be financed | Free text | manual | Source-of-funds. |
| 18 | 2 | Q15 cont. / Q16 lead line | Free text | manual | |
| 19 | 2 | Q16 Cash payment ≥ R50 000? (YES / NO) | `YES` / `NO` — printed as strikethrough `YES / NO` | manual | AML cash-threshold declaration. |
| 20 | 2 | Q17 Foreign PEP position held (last 12 mo) | Free text (position name if "yes") | manual | Foreign prominent-person screen (7 listed roles). |
| 21 | 2 | Q18 SA PEP / domestic-prominent-person position | Free text | manual | 14 listed SA roles. |
| 23 | 3 | Q19 Family member / close associate of a PEP | Free text (name + position) | manual | |
| 24 | 3 | Q20 Source of wealth (line 1) | Free text | manual | Fired only if any PEP answer = yes. |
| 25 | 3 | Q20 Source of wealth (line 2) | Free text | manual | |
| 26 | 3 | Q20 Source of wealth (line 3) / SIGNED-AND-DATED date | Date `DD/MM/YYYY` or free text | manual | "SIGNED AND DATED ON ______ (date)". |
| 28 | 4 | Outstanding requirements (line 1) | Free text | office/staff | Bottom of OFFICE-USE page — see below. |
| 29 | 4 | Outstanding requirements (line 2) | Free text | office/staff | |
| 30 | 4 | Outstanding requirements (line 3) | Free text | office/staff | |

*(Field-index→question mapping inferred from `pageIndex` + y-position against the page images; questions with multi-line blanks share consecutive fields.)*

---

### Field inventory — OFFICE USE section

The visual document has a substantial staff-completed zone across pages 3–4:
**Page 3:** `Risk [ 1 | 2 | 3 ]` rating table; `Verification Done by means:` (Whatsapp video call / Physically met with clients / Video call with ID document and newspaper).
**Page 4:** `FOR OFFICE USE ONLY` box — Full name of employee administering questionnaire / Signature / Date; then `HOME FINDERS COASTAL CHECK LIST` — 7 YES/NO (+ N/A) compliance rows; then "List of outstanding requirements".

| # | Page | Field | Who completes | Format / values | Notes |
|---|------|-------|---------------|-----------------|-------|
| 31 | 3 | `selection` widget `["Option 1","Option 2"]` | (unassigned) `assigneeRole=?` | `selectedValue=null` | Generic/stray 2-option selector at y≈51; the only non-`user` field. NOT wired to the Risk 1/2/3 table. |
| — | 3 | Risk rating (1 / 2 / 3 — low/med/high) | staff/office | Single choice | **NOT wired as an e-sign field.** Paper/manual only. |
| — | 3 | Verification method (WA video call / physically met / video call + ID & newspaper) | staff/office | Tick one | **NOT wired.** Paper/manual only. |
| — | 4 | Full name of employee administering questionnaire | staff/office | Name | **NOT wired.** Paper/manual only. |
| — | 4 | Employee SIGNATURE | staff/office | Signature | **NOT wired** — no signature field on page 4. |
| — | 4 | DATE (office) | staff/office | Date | **NOT wired.** |
| — | 4 | Check list — 7 rows (ID copy / address copy / authority / delegating authority / VIP / anything suspicious / transaction consistent) YES/NO/(N/A) | staff/office | YES / NO / Not Applicable | **NOT wired** as fields. |
| 28–30 | 4 | List of outstanding requirements (3 lines) | office/staff | Free text | The ONLY office-zone content that IS a field. |

**Critical:** apart from the 3 "outstanding requirements" lines (28–30) and the stray selection (31), the entire OFFICE-USE / verification / checklist zone is **not represented in the e-sign field layer** — no risk-rating field, no verification-method field, no staff-name/signature/date field, no checklist YES/NO fields.

---

### Two-role sequential ceremony

Johan's map: FICA-NP is two-role sequential — **client signs first, THEN staff completes risk rating + verification method + staff signature.**

**As-built divergence (template #33):**
- **The e-sign template is SINGLE-ROLE.** All 32 fields are `assigneeRole=user`; there is no second `assigneeRole` for an office/staff signer.
- There IS a client-fill zone (Q1–Q20) and per-page initial/signature surfaces owned by that single `user` role.
- The office zone (risk / verification / staff name / staff signature / checklist) exists **only as printed layout**, not as assignable fields, and no second signer role owns it.
- Therefore the "two-role sequential" ceremony is **NOT implemented in the field layer of #33.** The intended second (office/staff) step happens on paper / manually after export, not as a wired sequential second e-sign role.
- Fill data confirms it: across 87 filled family docs, **zero** values ever land on page 2 or page 3, and page-4 fills are only the outstanding-requirements lines — the risk/verification/checklist cells are never captured in-system.

**Sequence, as it actually runs:** single `user` completes/initials the questionnaire and signs → export → office verification & risk rating done outside the e-sign field layer (paper/manual). The two zones exist visually; only one is wired.

---

### Signature surface map

| Surface | Page | Role | Type | Notes |
|---------|------|------|------|-------|
| Field 8 — page initial | 0 | user | initial | Bottom-right x≈89, y≈91. Per-page initialling. |
| Field 17 — signature | 1 | user | signature | Bottom-right x≈90, y≈91. **Only full-signature field.** Visually "CLIENT'S SIGNATURE HERE" is printed on page 3 — a surface-placement quirk to flag. |
| Field 22 — page initial | 2 | user | initial | Bottom-right x≈90, y≈91. |
| Field 27 — page initial | 3 | user | initial | x≈82, y≈85. |
| (Employee/staff signature) | 4 | — | — | **No signature surface on the OFFICE-USE page** — staff sign on paper. |

Note: `signature`/`initial` fields never carry a `value` string in `fields_json` (the signature image is captured by the signing ceremony separately), so blank `value` on these ≠ unsigned.

---

### Template-vs-practice divergences

- **Single-role, not two-role.** Office/staff zone is printed layout only; no second signer role, no staff-signature field. Two-role sequential ceremony is not wired (Johan's map diverges from as-built #33).
- **Office verification captured on paper, not in-system.** Risk rating (1/2/3), verification method (WA video / physically met / video+ID+newspaper), employee name/signature/date, and the 7-row YES/NO checklist have no fields and are never populated in the 87 stored instances.
- **Questionnaire is very lightly filled in e-sign data.** Median fill ≈ 1–5 of 14–66 fields. The reliably-completed field is **Q1 (name + SA ID)**; Q2 (citizen YES/NO), Q3 (address), Q5 (10-digit tax no) appear next. Everything from Q6 onward (principal / representative / PEP / source-of-wealth) is predominantly blank — the "on-behalf-of" and PEP branches rarely apply.
- **Radio options not captured.** Q14's 4 service radio buttons (sell/purchase/let/rent) are not fields; only the free-text "Other" lines are. Q16/Q17/Q18 PEP answers are free-text, not selections.
- **Stray selection widget** (field 31, "Option 1 / Option 2", `selectedValue=null`, the only non-`user` field) sits on page 3 unconfigured — not bound to the Risk table.
- **Signature-surface placement:** the sole `signature` field is on page 1 while the printed "CLIENT'S SIGNATURE HERE" label is on page 3 — flag for the compiler/normalizer.
- **Family shape varies:** sibling templates in the family carry 14 (SB FICA NP #7), 30 (#42), 32 (#33) or 41/66 (on-behalf-of #50) fields — the on-behalf template expands the principal/representative section and adds a page-4 ID field.

---

### CDS source mapping (summary)

- **contact-owned (bulk of client identity):** full name, SA ID / passport, citizenship flag, residential address, phone, email, SARS tax number — Q1–Q5. filled_by = **client-at-signing**. FICA is contact-role agnostic (any party).
- **manual-only:** principal/representative details (Q6–Q13), service purpose "Other" (Q14), financing / source of funds (Q15), cash-threshold declaration (Q16), PEP screens (Q17–Q19), source of wealth (Q20). filled_by = **client-at-signing** (declarations).
- **office/agent (not a CoreX pillar value):** risk rating, verification method, administering-employee name/signature/date, 7-row compliance checklist, outstanding requirements. filled_by = **office/staff-after**. In #33 only "outstanding requirements" (fields 28–30) is a real field; the rest is paper/manual.

---

### Harvest provenance

- **Templates sampled:** family ids 7, 50, 42, 33 (SB FICA NP #7, On-behalf-of NP #50, NP V8 #42, Shelly V8 #33).
- **Filled docs read:** 87 non-deleted `docuperfect_documents` across the family (per template: #7=61, #50=15, #33=6, #42=5).
- **Distinct agents (owner_id):** 10 — [22, 29, 31, 32, 33, 36, 38, 39, 44, 45].
- **Blank template pages read:** template 33 page-0…page-4 images.
- **PII safety:** confirmed. No name, ID number, address, tax number, phone, email or bank detail was copied. Every value was reduced to a masked format signature (character-class + length only); raw values were never printed or transcribed. All examples in this file are abstracted patterns.
## Seller Mandatory Disclosure V7 (+ Addendum B) — template #30

Full title of the document (page-0 header): **"IMMOVABLE PROPERTY CONDITION REPORT IN RELATION TO THE SALE OF ANY IMMOVABLE PROPERTY (Property Practitioner Act 22 of 2019, Section 70 – Property Practitioners Regulations 2022 Section 36 – Mandatory Disclosure)"**. Live DocuPerfect template `#30` "Shelly Seller Mandatory Disclosure (V7)", `render_type=pdf`, `page_count=4` (3 numbered pages + Addendum B as page 4). Branded Home Finders Coastal / "The Mandate Company", Version 7 footer.

### What Addendum B is
- **Addendum B is the 4th page of template #30 itself** (page image `page-3.png`), NOT one of the separate `%Addendum%` templates. It is titled **"ADDENDUM B"** with a single **"EXTRA INFORMATION"** table and its own Seller / Purchaser / Property Practitioner / Co-signature block (footer "Page 1 of 1", Version 7). It is bound into the same PDF and signed as part of the same instance.
- The separate Addendum templates found by name search — `#14 SB 2026 OTP Addendum`, `#17/#36/#40 Finance`, `#37`, `#38 Furniture`, `#39 Occupation` — are **OTP (offer-to-purchase) addenda, a different family**. They are NOT "Addendum B". Do not confuse them.
- **Purpose of Addendum B** — captures the compliance/statutory-certificate posture of the property that the base disclosure omits:
  1. Are there **registered building plans** for the whole property, all improvements and solid roof structures (e.g. carport, pools, etc)? — Yes/No/NA
  2. Are you in possession of a valid **Certificate of Compliance** for: **Electrical CoC** (if Yes, when issued?), **Electrical Fence Certificate** (when issued?), **Gas Compliance Certificate** (when issued?), **Entomology (beetle) Certificate** (when issued?) — each Yes/No/NA + a "when issued" date note.

### Field inventory — identification + disclosure items

Template #30 stores only **9 default fields** (`fields_json`): 1 Property Address text, 6 unlabelled page-1 placeholders, 2 initials. The **Yes/No/NA choice cells and the extra signature/date lines are NOT baked into the template** — the agent adds them per-document at signing time as `tick` fields (`options:["Yes","No","N/A"]`, `selectedValue`) and `signature`/`initial` fields. A fully built instance (doc #246) carries **57 fields**: 33 ticks on page 1 (11 items × 3 columns) + 15 ticks on page 3/Addendum B (5 items × 3 columns) + identification + initials. Tick columns cluster at x≈76 (YES), x≈81/82 (NO), x≈87 (N/A).

| # | Page/Item | Field (business meaning) | Answer type | Who fills | Format pattern | CDS source | Notes |
|---|-----------|--------------------------|-------------|-----------|----------------|------------|-------|
| 1 | p1 §1 | Property address (disclaimer blank) | text | agent-prefill | free text, ~80 chars, street + suburb + town, comma-separated | property.address | `named_field_name="Property Address"`; sole reliably-filled text field |
| 2 | p1 §4 initial | Seller initial (page 1) | initial | seller @ mandate | initial mark | contact (seller) | `assigneeRole=user`, `assignedTo=creator` |
| 3 | p2 §5 item | Aware of defects in the **roof** | Yes/No/NA tick | seller @ mandate | one tick, overwhelmingly **No** | manual-only | seller's own knowledge |
| 4 | p2 §5 | Aware of defects in **electrical systems** | Yes/No/NA | seller | mostly No | manual-only | |
| 5 | p2 §5 | Aware of defects in **plumbing incl. swimming pool** (if any) | Yes/No/NA | seller | mostly No | manual-only | |
| 6 | p2 §5 | Aware of defects in **heating/air-conditioning** (filters, humidifiers) | Yes/No/NA | seller | often N/A on coastal stock | manual-only | |
| 7 | p2 §5 | Aware of defects in **septic/sanitary disposal** | Yes/No/NA | seller | mostly No / N/A | manual-only | |
| 8 | p2 §5 | Aware of defects to property / **basement/foundations** (cracks, seepage, flooding, damp, mould, drains, sump) | Yes/No/NA | seller | mostly No | manual-only | longest item |
| 9 | p2 §5 | Aware of **structural defects** | Yes/No/NA | seller | mostly No | manual-only | |
| 10 | p2 §5 | Aware of **boundary dispute / encroachment / encumbrance** | Yes/No/NA | seller | mostly No | manual-only | |
| 11 | p2 §5 | Aware **remodelling/refurbishment affected structure** | Yes/No/NA | seller | mostly No | manual-only | |
| 12 | p2 §5 | Aware **additions/erections only done with proper consents/permits** | Yes/No/NA | seller | Yes/No — plan-approval flag | manual-only | most-commonly-flagged item (plans) |
| 13 | p2 §5 | Aware structure **earmarked historic/heritage** | Yes/No/NA | seller | almost always No / N/A | manual-only | |
| 14 | p2 | **ADDITIONAL INFORMATION** (6 blank rows) | free text | seller | usually **left blank**; used only to explain any "Yes" (§4 obliges full explanation on a Yes) | manual-only | not a stored field; paper lines |
| 15 | p3 §10 | **Seller** — Signed at / on / Signature | text + signature | seller @ mandate | place + date + signature | contact + deal date | see signature map |
| 16 | p3 §10 | **Purchaser** — Signed at / on / Signature | text + signature | purchaser @ offer | place + date + signature | contact (buyer) + offer date | countersignature block |
| 17 | p3 §10 | **Property Practitioner** — Signed at / on / Signature | text + signature | agent | place + date + signature | user (agent) | |
| 18 | p3 §10 | **Co-signature (if required)** — Property Practitioner | signature | agent 2 | optional | user | dual-mandate / co-listing |
| 19 | p4 (Addendum B) | Registered **building plans** whole property/improvements/roof structures | Yes/No/NA | seller | Yes/No | manual-only | |
| 20 | p4 | **Electrical CoC** held? + "when issued" | Yes/No/NA + date | seller | Yes + date if held | manual-only | date "when issued" |
| 21 | p4 | **Electrical Fence Certificate** held? + date | Yes/No/NA + date | seller | often N/A | manual-only | |
| 22 | p4 | **Gas Compliance Certificate** held? + date | Yes/No/NA + date | seller | often N/A | manual-only | |
| 23 | p4 | **Entomology (beetle) Certificate** held? + date | Yes/No/NA + date | seller | Yes/No coastal-relevant | manual-only | |
| 24 | p4 | Addendum B Seller / Purchaser / Practitioner / Co-signature | signature | seller@mandate, purchaser@offer, agent | mirrors p3 block | contact + user | second signature surface repeated |

### SECTION-TITLE vs FULL-TITLE variant
The disclosure family carries two identification layouts driven by **property tenure (sectional-title scheme vs freehold/full-title)**, confirmed by the identification field names:
- **SECTION TITLE** variants (#47 `SB Manditory Disclosure SECTION TITLE`, #59 `MANDITORY DISCLOSURE SEC TITLE`) expose **Complex** + **ST sec. no.** (sectional-title unit/scheme number) + Street + Suburb + Property Number. Used for **sectional-title units** (flats/townhouse-in-scheme).
- **FULL TITLE** variants (#51 `SB Manditory Disclosure FULL TITLE`, #63 `MANDITORY DISCLOSURE FULL TITLE`) expose **Property Number + Street + Suburb + District** (erf/freehold), NO Complex/scheme field. Used for **freehold / full-title erven**.
- The Shelly base #30 uses a single free-text "Property Address" line (tenure-agnostic; agent types whatever fits). The "SB" prefix = Southbroom branch templates; "Shelly" = Shelly Beach branch — same statutory form, per-branch letterhead. **Driver for CDS: property.tenure (sectional vs freehold) selects the variant; property.scheme_name/unit populates Complex + ST sec. no. for sectional.**

### Twice-in-lifecycle signing — VERIFIED
Johan's map holds. The physical document defines **two distinct signer zones on the SAME instance**:
- **§10 Signatures (page 3)** — separate **Seller** block and **Purchaser** block (plus Property Practitioner + optional Co-signature). Addendum B (page 4) repeats the identical Seller/Purchaser/Practitioner/Co-signature set.
- **Seller signs at MANDATE stage** — completes the §5 condition answers, initials pages 1 and 2, signs the Seller block. This is the disclosure the owner makes when the mandate is taken.
- **Purchaser countersigns at OFFER stage** — the §9 "Buyer's acknowledgement" ("acknowledges receipt of a copy of this statement") is signed by the prospective buyer at offer. It is the **same physical document re-presented to the buyer for countersignature**, NOT a separate countersignature page — one document, two signing events across the lifecycle.
- **CoreX lifecycle mapping:** Seller block → mandate stage (My Listings / mandate signing, `owner_party`). Purchaser block → offer stage (Deal / OTP bundle, `acquiring_party`). Property Practitioner → agent, both stages.
- **DIVERGENCE (flag):** DocuPerfect does **not** model the two events as two distinct `assigneeRole`s. Every field in the stored `fields_json` is `assigneeRole=user`, `assignedTo=creator` — a single generic signer. The seller-at-mandate / purchaser-at-offer split is a **paper/business convention the template does not enforce**; a CoreX two-stage routing (seller recipient at mandate, buyer recipient at offer on re-serve) would have to be added on top of the current single-role field model.

### Signature surface map
| Surface | Page | Role | Stage | Type | Notes |
|---------|------|------|-------|------|-------|
| Seller signature §10 | 3 | owner_party (seller) | mandate | signature + "Signed at __ on __" | primary disclosure signature |
| Purchaser signature §10 | 3 | acquiring_party (buyer) | offer | signature + place/date | countersignature (§9 acknowledgement of receipt) |
| Property Practitioner §10 | 3 | agent (User) | mandate (& offer) | signature + place/date | witnessing practitioner |
| Co-signature (if required) §10 | 3 | agent 2 | mandate | signature | dual/co-mandate only |
| Page-1 initial | 1 | seller | mandate | initial | `initial` field, role=user |
| Page-2 initial | 2 | seller | mandate | initial | each content page initialled |
| Addendum B — Seller | 4 | owner_party | mandate | signature + place/date | mirrors §10 |
| Addendum B — Purchaser | 4 | acquiring_party | offer | signature + place/date | mirrors §10 |
| Addendum B — Practitioner + Co-sig | 4 | agent(s) | mandate | signature | mirrors §10 |

### Template-vs-practice divergences
- **Y/N/NA cells and signature/date lines are not template-baked.** Template #30 ships 9 fields; the 33 page-1 + 15 Addendum-B tick fields (`options:["Yes","No","N/A"]`) are **added per document by the agent** at signing. Consequence: fill quality depends on the agent placing every tick; a missed column = an unanswered statutory item. A CoreX build should bake the full Y/N/NA grid + signature grid into the template so it cannot be shipped incomplete.
- **Answer skew:** condition items are answered **"No" in the overwhelming majority** (seller asserting no known defect). The one item routinely flagged **Yes** is the plans/consents item (#12) and, on Addendum B, the building-plans / CoC questions.
- **Comment / ADDITIONAL INFORMATION column is almost always left blank** — even though §4 legally obliges a full written explanation whenever any statement is answered "Yes". This is a real compliance gap in practice (Yes answered, explanation omitted). CoreX should make the comment field conditionally-required when a tick = Yes.
- **`selectedValue` frequently null in stored JSON** on draft instances — the tick's answer is captured at signing, not at template placement; drafts show `selected=null`. Do not read draft `fields_json` as the final answer.
- **Addendum B "when issued" dates** are free-text, no date-picker — inconsistent formats expected (DD/MM/YYYY vs "2023" vs blank).
- **Single generic signer role** (see Twice-in-lifecycle divergence) — no seller-vs-purchaser recipient routing in the current field model.

### Harvest provenance
- **Templates sampled:** #30 (Shelly V7, canonical — 4 page images read incl. Addendum B), plus family #47 (SB SECTION TITLE, 36 docs), #51 (SB FULL TITLE, 22 docs), #63 (FULL TITLE, 6 docs), #59 (SEC TITLE, 3 docs). Letting disclosure #49 **excluded** per scope (sale context only).
- **Filled documents read:** 15 across the family (doc ids 115, 120, 181, 183, 237, 246, 286, 295, 300, 302, 309, 333, 334, 344, 345). Deep-read doc #246 (57-field fully-built instance) + #237/#120/#181 for field/value structure.
- **Distinct agents:** 11 distinct `owner_id` across the sampled family (agents 22, 24, 29, 32, 33, …).
- **Addendum B identity confirmed:** page-3.png of template #30 = "ADDENDUM B / EXTRA INFORMATION"; the separate `%Addendum%` templates (#14/#17/#36–#40) verified as OTP-family, excluded.
- **PII:** confirmed masked. No real seller/purchaser name, ID, or address reproduced — property-address values reported as length/format metadata only; all patterns are structural.
---

## Implications for the ceremony spec + CDS compiler

This harvest converts into concrete requirements for the ceremony spec Johan + Andre are
drafting, and for the AT-177 compiler. In priority order:

1. **Signature surfaces are declared data, not harvested fields.** The compiler must let a
   template declare each signing surface — role, clause anchor, sign-vs-initial, stage — and
   inject them at ceremony time. Every one of the four documents proves this is mandatory:
   the paper defines the surfaces, the current field layer does not carry them.

2. **Party topology + multi-stage routing is first-class.** Model the roles per document
   (EATS: seller[s] + witness + practitioner; OTP: purchaser + 2 witnesses + seller + 2
   witnesses + practitioner + co-sign; FICA: client + office/staff; Disclosure: seller +
   purchaser + practitioner + co-sign). The Disclosure forces the hardest case: **one document,
   two lifecycle stages** — seller signs at mandate, the *same* document is re-served to the
   purchaser at offer. The ceremony engine needs a re-serve/countersign stage, not just a
   one-shot multi-signer flow.

3. **One typed choice/branch field, baked and source-driven.** Replace the three ad-hoc
   mechanisms (strike-capability / typed-"N/A" / per-doc ticks) with a single conditional-branch
   field type that ships *in* the published template. Drive it from a CDS source where one
   exists — `property.tenure` (freehold/sectional; also selects the Disclosure variant), deal
   flags (deposit method, second-property state, bond present), `contact.vat_registered`. Where
   no source exists (the genuine either/or), present it as an explicit agent decision, never a
   silent blank.

4. **Bake the compliance grids so a document cannot ship incomplete.** The Disclosure Y/N/NA
   grid + signature grid (added per-doc today) and the FICA office-verification zone (no fields
   today) must be baked template structure. Make the Disclosure §4 explanation **conditionally
   required** when any item = "Yes" (a current, real compliance gap). Model the FICA office zone
   as the **office/staff role's** fields (risk rating 1/2/3, verification method, staff
   name/signature/date, 7-row checklist) so verification becomes captured data, not paper.

5. **Typed data dictionary entries with validation, auto-derivation, prefill.** SA-ID (13-digit
   + checksum) split from name; ZAR money with **auto-generated amount-in-words**; typed dates
   (kill the free-text date variance); FFC (7-digit); erf/scheme/title. Prefill every identity /
   money / date field from the pillar that owns it (four-pillar map above) — today all of it is
   hand-typed because there is zero CDS wiring.

6. **Legal e-sign block, per the compiler spec.** Note the OTP is the Alienation-of-Land-Act /
   ECTA case the compiler spec already flags — it must not publish with wet-ink e-sign on the
   agreement-of-sale surfaces. This harvest confirms the OTP's signature block is wet-ink by
   construction today (`is_esign=0`, print-only signatures).

## Harvest provenance (all four)

| Doc | Template | Pages read | Family sampled | Filled docs read | Distinct agents |
|-----|----------|-----------|----------------|------------------|-----------------|
| EATS V10 | #27 | 3 | [2,4,41,5,27,29,3] | ~17 | 7 |
| OTP V13 Enviro | #25 | 9 | [11,12,25,26] | 23 (4 populated) | 8 |
| FICA NP V8 | #33 | 5 | [7,50,42,33] | 87 | 10 |
| Disclosure V7 (+Addendum B) | #30 | 4 | [30,47,51,63,59] | 15 | 11 |

**Method:** blank structure from rendered template page images (`docuperfect/templates/{id}/
page-*.png`); who-fills / choice-points / surfaces from live `docuperfect_templates.fields_json`
(`assigneeRole`, `type`, `strikethroughType`, `options`); fill patterns from sampled
`docuperfect_documents.fields_json` across each family and across distinct `owner_id` agents.

**POPIA:** every real value was masked to character-class + length (`x`=letter, `9`/`#`=digit,
`R`/`%`/punctuation preserved) before leaving the database. No client name, SA-ID, address, tax
number, phone, email, or personal amount appears anywhere in this document. Read-only harvest —
no live data was modified.

**Caveat:** the current Shelly templates (#25/#27/#30/#33) are newly cut and thinly filled in
their own right; fill-pattern truth is drawn from their SB-2026 lineage siblings, which share
the identical page layout and (unlike the Shelly cuts) carry `named_field_name` labels that
name each shared coordinate directly. Where a current template had no populated instance of its
own, this is noted in that document's provenance block.
