# OTP "OFFER TO PURCHASE" V13 — import readiness pass (READ-ONLY)

**Date:** 2026-07-19 · **For:** Johan's own import test tomorrow (critical importer + e-sign test).
**Method:** each construct in the doc verified against the CURRENT importer's detection. No code changed.

## ⚠️ THE HEADLINE — the tokenizer is MARKER-ONLY

The CDS importer detects fields ONLY from four explicit typed markers
(`CdsParserService::ACCEPTED_MARKERS`, **lines 30–69**; split at `markerSplitPattern()` **85–88**;
applied by `detectMarkers()` **~1029**):

| Marker | Meaning |
|---|---|
| `~~~~NAME~~~~` | named/insertable field (e.g. `~~~~Seller - Full name~~~~`) |
| `@@@@` | input field |
| `%%%%` | signature |
| `####` | initial |

**Every raw-blank detector is switched OFF** — `parse()` **lines 267–272** comment out
`detectFieldPlaceholders`, `labelFieldPlaceholders`, `detectLabelValuePairs`, `detectInlineSignatures`,
`insertPageInitials`. So **dotted-leader runs (`.........`) and raw underscores are NOT detected as
fields.** The EATS imported cleanly because Johan had **typed `~~~~` tokens into it**; the OTP as a raw
dotted-leader doc will detect **≈ zero fields**.

**→ For tomorrow to land like the EATS, the OTP must be MARKED UP with `~~~~` tokens first** (or we add a
dotted-leader tokenizer — a build, needs your word). Everything below is assessed on that reality: what
is a *markup* need vs a genuine *resolver* gap that survives even after markup.

## READY / GAP table

| # | Construct | Verdict | Why / resolver (file:line) |
|---|---|---|---|
| **1** | **Dotted-leader blanks** (`.........`), multi-field-per-line, inline mid-sentence | **GAP (markup)** | Tokenizer is marker-only (`ACCEPTED_MARKERS` 30–69). No dotted/underscore field detection (`parse()` 267–272 all commented). Multiple-per-line works ONLY if multiple `~~~~` markers per line. Raw dots → nothing. |
| **2** | **Amount-pairs** `R…… (……words……)` (price, deposit, balance, bond, occ-rent, fee) | **GAP** | (a) needs #1 markup to exist at all; (b) **no PAIR-linkage concept** — each marker binds independently. Words→`property.price_in_words` IS resolvable per-field (`CdsBindingSuggester` document branch; `attributeFieldFromContext` 1318) but the numeric↔words *link* and the 6 distinct amounts (each its own named field) are not modelled. Marked up, each field binds singly; they don't pair. |
| **3** | **Delete-whichever / option-choice** ("(Delete (a)/(b))", is/is not, 1.1.1-vs-1.1.2, 2.2.1/2.2.2, Conveyancer/Seller, 3.4 OR 3.5) | **GAP — by design (no primitive)** | The importer has **NO option-choice / conditional concept** (ESIGN-CANON §5 "Conditional rendering — MISSING"; no code). These import as **plain text** — the agent strikes them manually at signing (signing-time strikethrough is the only binary). **← This is the e-sign feature question for you.** |
| **4** | **Letterhead** (image logo + text company block) | **PARTIAL** | `detectCompanyHeader()` **842–878** classifies a header ONLY when it is a **TABLE** with ≥2 of {reg no, ffc, vat, tel:, fax:, email address, registration}; then AT-303/R1 strips it (`CdsRendererService::renderSection` company_header→`''`). **If the OTP letterhead is a table → stripped (READY). If it's plain paragraphs → NOT classified → NOT stripped → doubles under CoreX's header (GAP).** The **image logo is DROPPED entirely** — the parser has no `w:drawing`/`w:pic` handling, so it never appears (no double, but no logo either; CoreX's header replaces it — fine). |
| **5** | **Commission** clause 5 "7.5% R……" | **PARTIAL** | The **7.5% → READY**: `detectCommissionField()` **~317–345** (regex line **325**) tokenises the first "professional fee / commission … N%" from body text → `property.commission_percent`. The **fee AMOUNT "R……" → GAP** (dotted leader, #1). |
| **6** | **Per-party blocks + signature page** (Seller/Purchaser "I/We", domicilium per party; sig page: Purchaser×2 + witnesses×2 + names, Seller×2 + witnesses×2 + names, Practitioner + Co-Sign) | **GAP** | (a) D4 sig-line detection `detectUnderscoreSignatureLines()` **367–383** is **underscore-only** (guard regex 383 `^[_\-…\s]+$`) → will NOT catch **dotted** sig lines. (b) The end sig page is collapsed by `detectSignatureSections()` (~1620) + `extractPartyRoles()` and then **REPLACED by CoreX's own signature-block** (`TemplateController::generateCdsBladeView` **970–980**) whose parties for a sale = `['Seller','Buyer','Agent']`. **Witness name lines, Co-Sign, and the practitioner-acceptance paragraph are NOT modelled** — the rich OTP sig page renders as CoreX's standard 3-party block. Identity "I/We" clause via field-group works only if marked (`~~~~Seller - Full name~~~~` → fg), same as EATS. |
| **7** | **Special** (14 cooling-off ≤R250k conditional; 15 irrevocable date/time; 17 free-text dotted block; 2.2.2 second-property nested; 5.6 agency split names/%/FFCs) | **GAP** | Cooling-off / conditional text → no primitive (#3) → plain text. Irrevocable date/time, second-property fields, agency-split (names/%/FFCs), free-text "other conditions" → all **dotted leaders → #1 markup** needed; `~~~~OTHER_CONDITIONS~~~~` exists for the free-text block (EATS used `document_other_conditions`) but only if marked. Agency-split %/FFC have no dedicated named fields either. |

## Bottom line for tomorrow

- **Precondition:** the OTP must be **marked up with `~~~~` tokens** (like the EATS) or it imports empty.
  This is the single biggest "surprise-avoider."
- **Even marked up, genuine resolver gaps remain:** option-choice/conditional constructs (#3, #7) import
  as plain text (no primitive); amount-pairs don't link (#2); the signature page loses witnesses /
  co-sign / practitioner-acceptance (#6); paragraph-letterhead may double (#4).
- **What's genuinely READY (marked up):** attribute binding (address/phone/email/id), property fields,
  price + price-in-words per-field, the 7.5% commission %, the single "I/We" seller/purchaser field-group
  clause, the header strip (if the letterhead is a table).

**Decisions this surfaces for you (fix nothing until you confirm):**
1. Do we add a **dotted-leader / underscore tokenizer** so raw docs import without hand-markup? (biggest lever)
2. Do we build an **option-choice primitive** for the "delete whichever" constructs (#3) — the e-sign feature question?
3. Do we extend the **signature-page model** to witnesses + co-sign + practitioner-acceptance (#6)?
4. Amount-pair linkage (#2) and agency-split fields (#7) — model as named fields, or leave as vet?

---

## BUILT 2026-07-20 (AT-304) — 3 of 4 gaps closed; a raw dotted OTP now imports with fields

Per Johan's ruling (build the 3 detection levers this morning; ticket the 4th). Class-level in
`CdsParserService` + `TemplateController::generateCdsBladeView`.

- **OTP-1 dotted-leader tokenizer** (`detectDottedLeaderFields`) — ≥5-dot / ≥2-ellipsis runs → fields,
  multiple per line, labelled by `identifyFieldsFromContext`. Guarded: ordinary ellipsis never
  tokenises; signature-area lines left for the sig detectors. **The precondition is gone — raw dotted
  docs no longer import empty.**
- **OTP-4 amount-pairs + agency split** (`refineAmountPairsAndAgencySplit`) — links each
  "R<numeric> (<in-words>)" (`property.price` ↔ `property.price_in_words` via `linked_to`); names the
  5.6 agency split (listing/selling name · share % · FFC).
- **OTP-3 N-party signature page** — `extractPartyRoles` now captures witnesses + practitioner +
  co-signatory (leader-strip so dotted lines aren't length-gated out); `generateCdsBladeView` feeds the
  detected roster + `show_witness` to the signature-block (witness columns per party).
- **OTP-2 option-choice primitive → AT-303** (not built; needs Johan's e-sign-treatment ruling).

**Proof (representative OTP fixture — Johan's exact docx is today's office import):** 22 dotted fields
(multi-per-line); ellipsis guard holds; 3 amount-pairs linked; 6 agency-split fields named; sig roster
{buyer, witness×2, seller, agent} + generated sig-block carries `show_witness`. Tests:
`tests/Unit/CdsDottedLeaderDetectionTest.php` (6) + existing parser suites green (no EATS regression).

**KNOWN LIMITS (honest, for the office import):** built against a representative fixture, not Johan's
exact V13 — the office upload is the live confirmation. The dotted-run threshold is ≥5 (tune if his doc
uses shorter runs or spaced dots). Bindings for dotted fields fall to label/best-match (not the
`{Party}-{Attribute}` convention). Sig-page co-signatory on a combined practitioner+co-sign line can be
length-gated; witness/practitioner NAMES aren't distinct sign surfaces yet.
