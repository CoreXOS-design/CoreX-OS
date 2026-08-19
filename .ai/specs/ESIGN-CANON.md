# ESIGN-CANON — the governing doctrine of CoreX e-signature

> **Status: CANON (Johan's order, 2026-07-15). This document governs all e-sign content and
> behaviour work. Where any other spec, blade, or service disagrees with a ruling below, this
> document wins and the code is the defect.**
>
> Compiled by merging repo truth (`.ai/specs/esign-ceremony-v3.md`, `esign-v3-complete-spec.md`,
> `.ai/audits/2026-07-11-esign-v3-gap-analysis.md`, `.ai/qa/esign-contract-walk-test/README.md`,
> `.ai/tickets/esign-phase1-implementation-plan.md`) with seven conductor-verified HISTORY RULINGS
> (quoted below as law), and checking each ruling against the current code. **No code was changed.**
>
> Verdict key per point: **CONFORMS** / **DIVERGES** (states how) / **MISSING**. Every claim carries a
> `file:line`.

---

## §0 — The two paths, and the one structural truth

E-sign has **two rendering families** (gap analysis §0-A):

- **web/CDS is THE e-sign path.** Contract role-blocks, `signature-line`/`signature-block` components,
  the recipient signing surface, the disclosure machinery — all live here.
- **PDF-image overlays are the wet-ink / stopgap family only.**

**The structural truth that colours every point below:** the mandate documents agents *actually use*
today — **#27 Shelly EATS, #25 OTP, #33 FICA, #30 Disclosure — are all `render_type=pdf` on live**
(gap analysis ll. 56-57). The canon web/CDS infrastructure is **built** but the real documents **are
not on it in any database**; seeded CDS versions exist in the repo but were never imported to a live
DB. So for most rulings the pattern is: *the canon-conformant engine exists and conforms by design, but
it is dormant — the live documents take the legacy path.* Read every "CONFORMS" below with that caveat.

**Legal foundation** (`esign-v3-complete-spec.md` §2): ECTA s13 gives an e-signature wet-ink weight
**except** s13(1) exclusions (alienation of land, wills, bills of exchange). Alienation of Land Act
68/1981 s2(1) requires alienation to be in writing and signed. → **The e-sign estate = mandates,
marketing permissions, FICA, disclosures, addenda, leases < 10 years. Sale/OTP/alienation = wet-ink
only.**

---

## §1 — RECIPIENT LOOPING

**THE LAW (ruling 1):** *Templates carry ONE field/block per role, NEVER indexed copies; render-time
expansion loops per linked recipient of that role; inline name lines read "Name Surname (ID: …) and
Name Surname (ID: …)" — never name-name-surname-surname (the legacy bug); ADDRESS/detail BLOCKS render
one full block per recipient, stacked, each recipient editing ONLY their own block; applies to ALL
roles (seller/buyer/lessor/lessee/landlord/tenant).*

**CONFORMANCE — mostly CONFORMS by design, but DORMANT + one legacy divergence.**

- **One block per role, not indexed:** **CONFORMS for CDS templates** — `RoleBlockNormalizer::normalize()`
  stamps `data-role-block="{role}"` once per role at save time (`RoleBlockNormalizer.php:53-142`, invoked
  `TemplateController.php:598`). **DIVERGES for legacy/non-CDS templates** — `WebTemplateDataService.php:213-238`
  bakes indexed `seller_1_phone … seller_4_phone` into merge-data, **hard-capped at 4 recipients**;
  `RoleBlockDetectionService.php:30-47` documents this indexed pattern as the current reality. **⚠️ The
  walk-test proves the divergence is what runs live:** `data-role-block` is on **0 of 64 templates on
  qa1, 0 on staging, 0 blade views in the repo — the backfill has never run** (walk-test README ll. 35-39).
  Everything today renders through the legacy clustering fallback.
- **Render-time expansion loop:** **CONFORMS** — `RoleBlockExpansionService::expandWithLooping()`
  (`:262-365`), loop at `:1233-1252`; authoritative live call site `SigningController.php:348-359`
  (every `SignatureRequest` for the template). The un-normalised fallback logs the knife-edge line
  `"rendering unnormalised template via legacy clustering"` (`RoleBlockExpansionService.php:325`).
- **Inline name lines interleaved with IDs:** **CONFORMS in the primary engine** —
  `buildRecipientCompositeSpan()` builds `"{first} {last} (ID: {id})"` and joins with `" and "`
  (`RoleBlockExpansionService.php:1459-1490`); its docblock names this the deliberate fix for the legacy
  "Name Name Surname Surname" bug. **Latent DIVERGENCE risk:** `WebTemplateDataService::resolveContactColumnAllRecipients()`
  (`:657-677`) joins *per column* — for a CDS template with ungrouped `first_name`/`last_name`/`id` fields
  it would reproduce the legacy shape unless the loop engine later overwrites it.
- **Address/detail blocks stacked, per-recipient edit isolation:** **CONFORMS** —
  `duplicateBlockForRecipients()` clones one full block per recipient, stacked, with a per-recipient
  header (`RoleBlockExpansionService.php:1220-1341`); isolation via `stampViewerEditability()` matching the
  viewer's `SignatureRequest::role_identity` against `data-recipient-identity` + `data-viewer-editable`
  (`:565-615`).
- **All roles generic:** **CONFORMS** — `RoleBlockDetectionService::ROLE_BASES` (`:54-66`) covers
  seller/buyer/lessor/lessee/landlord/tenant (+ owner_party/acquiring_party/agent/witness/spouse); no
  seller/buyer special-case.

---

## §2 — IMPORT STRIPS (header + signature)

**THE LAW (ruling 2):** *First table = agency header, stripped UNCONDITIONALLY, replaced by the
company-header component (settings-fed: logo, reg, FFC/VAT); signature boundary detected FROM THE
BOTTOM, original signature content DISCARDED and replaced by the CoreX signature-block component —
every doc ends with the specced block; strip NOTHING else (the March over-strip lesson).*

**CONFORMANCE — DIVERGES on all three mechanics; the replacement components are correct.**

- **Header strip:** **DIVERGES — not unconditional.** `DocxParserService::stripDocumentHeader()`
  (`:570-601`) only strips the first table **if** it contains a base64 image OR both "reg" and "ffc"/"vat"
  text; a text-only letterhead table leaks into the body. Injection is correct + settings-fed:
  `DocumentTemplateGenerator.php:981` includes `company-header`, resolving live agency logo/reg/vat/ffc/fic
  (`company-header.blade.php:15-81`).
- **Signature strip:** **DIVERGES — not bottom-anchored.** The correct bottom-up boundary scanner
  `detectSignatureBoundary()` **exists but is DEAD CODE** — never called, explicitly disabled
  (`DocumentTemplateGenerator.php:620-746`, note at `:807-809`). The live strip is
  `DocxParserService::stripSignatureSections()` (`:678-808`) — **cluster/pattern-based across mixed
  positions**, not a tail scan; it replaces the cluster with a placeholder, and the real signature-block
  component is injected later at generate-time and only conditionally (`DocumentTemplateGenerator.php:813-819`).
- **Strip nothing else:** **DIVERGES (weak guard).** A two-signal requirement exists (`DocxParserService.php:750`)
  but the clustering window is generous (seeds within 10 positions, ±5 expansion, `:722,734-735`) with no
  "must be in the document tail" bound — a mid-body clause combining underscores and a party word can be
  swept in. This is exactly the **March over-strip failure mode** the ruling names, left unguarded.

---

## §3 — OTHER / ADDITIONAL CONDITIONS

**THE LAW (ruling 3):** *Every document carries an editable "other/additional conditions" section
parties can fill during signing (built March on external sign view — verify it survived into current
paths).*

**CONFORMANCE — CONFORMS on the external recipient path; MISSING on the main e-sign signing view.**

- The mechanism is the `~~~~OTHER_CONDITIONS~~~~` marker → `InsertableBlockRenderer` → a live
  `.insertable-block` with "+ Add condition"; rows persist to `document_conditions`
  (`InsertableBlockRenderer.php:239-255,581`; spec §7.5 `other_conditions_text`).
- **CONFORMS — external sign view:** wired via `SigningController.php:332-340` (CONTEXT_RECIPIENT_SIGNING),
  `external/sign.blade.php` includes `add-condition-modal` (`:3553-3554`). Present + editable during signing.
- **MISSING — main e-sign signing view:** `SignatureController::sign()` (`app/Http/Controllers/Docuperfect/SignatureController.php:857-946`)
  **never calls `InsertableBlockRenderer`**; `sign.blade.php` has no add-condition affordance — the marker
  would render literally/blank. The March build **did not survive into this path.**
- **Wet-ink portal:** structurally N/A — `wetInkPortal()` is a download-print-sign-upload flow
  (`SigningController.php:875-922`), not an on-screen editable document.

---

## §4 — MANDATORY DISCLOSURE = MASTER

**THE LAW (ruling 4):** *The law-prescribed form (CDS templates 112/113 lineage) with working
radio/conditional-date machinery (`_processDisclosureTable`, `webDisclosureAnswers`,
mandatory-before-complete) — REUSABLE ACROSS ALL AGENCIES, never editable per-agency; the May regression
lesson is law: the CDS PATH IS THE PATH, blade data-field shortcuts lack the machinery.*

**CONFORMANCE — CONFORMS on master-ness + no-data-field-regression; DIVERGES on the machinery reaching
the main signing view for the active flagship templates.**

- **Master, reusable, not per-agency editable:** **CONFORMS.** `Docuperfect\Template` has **no `agency_id`
  and no BelongsToAgency** (`app/Models/Docuperfect/Template.php`); the Sales + Letting Mandatory Disclosure
  masters are seeded `is_global = true` (`SalesMandatoryDisclosureSeeder.php:133`, `WebTemplateSeeder.php:53`);
  editing requires `manage_templates` (`TemplateController.php:217-222`) — structurally one shared row, no
  per-agency copy to diverge.
- **Radio / conditional-date / mandatory-before-complete machinery:** **CONFORMS for the
  `.corex-disclosure-checklist` shape** (`template-120`/`template-123`, `disclosure-logic.blade.php:107-188`).
  **DIVERGES for the bare-table shape that the currently-active flagship disclosures actually use**
  (`sales-mandatory-disclosure.blade.php:55`, `letting-mandatory-disclosure-v7.blade.php:422`,
  `cds/template-112.blade.php:29`, `cds/template-113.blade.php:27`): `_processDisclosureTable()` is defined
  **only** in `external/sign.blade.php:2958-3059` and flagged **"external-only (legacy path)"**
  (`disclosure-logic.blade.php:11-13`). On the main/internal `sign.blade.php` those forms render as
  **empty, non-interactive YES/NO/N/A cells with no restored answers** — breaking the agent's read-only
  review of the flagship form (the legal *gate* is not bypassed, because `_signerIsDisclosingParty()` is
  false on that agent-only route). **Server-side completion (`SigningController::completeWeb()`, `:1328-1384`)
  does NOT re-validate the mandatory gate — enforcement is client-JS only.**
- **CDS-path-is-the-path / no data-field shortcut:** **CONFORMS / not present** — no active disclosure grid
  substitutes `data-field` for the table+radio machinery (`data-field` appears only on simple text spans);
  the May-regression pattern is absent. The divergence found is narrower (bare-table converter being
  view-scoped), not a data-field substitution.

---

## §5 — CONDITIONAL RENDERING (binary clauses)

**THE LAW (ruling 5):** *Binary clauses (VAT is / is not, etc.) selected by the agent at creation, only
the applicable text prints — kills strike-through-and-initial.*

**CONFORMANCE — MISSING from the live path.**

- The live import pipeline (`DocumentTemplateGenerator` tag processing, `:251-287`) handles only `input`,
  `signature`, `initial` — **no conditional/binary/either-or tag type**.
- A genuine conditional primitive exists — `App\Support\Docuperfect\Cds\Condition` (`Condition.php:28-117`,
  `FIELD_TRUTHY`/`FIELD_EQUALS`, `evaluate()`) — but it belongs to the **separate CDS Compiler (AT-177),
  which is HELD, not deployed**, and is not wired into the docx-import flow.
- The **only wired binary mechanism is `DocumentClauseStrikethrough`** (`app/Models/Docuperfect/DocumentClauseStrikethrough.php`)
  — a **signing-time strikethrough routed through agent review**, i.e. exactly the strike-through-and-initial
  pattern this ruling says to replace. The ceremony spec agrees this is the intended replacement
  (`esign-ceremony-v3.md` ll. 216-223 "into-the-losing-branch habit … under V3 there is one").

---

## §6 — FICA AT THE E-SIGN GATE

**THE LAW (ruling 6):** *E-sign of a non-compliant/unsubmitted signer KICKS OFF the electronic FICA
process at the gate; an approved FicaSubmission lifts the gate; else FICA form flow, recipient completes
their OWN identity fields — never pre-filled (the FICA bright-line ruling); wet-ink FICA auto-creation
from splitter stays.*

**CONFORMANCE — CONFORMS on the flow + the bright-line; DIVERGES on what lifts the gate.**

- **Gate exists + kicks off FICA:** **CONFORMS** — `SigningController.php:123-175`: if `fica_required` and
  no qualifying `FicaSubmission`, the signer is sent to `external.fica-gate`, which links to the electronic
  FICA form (`fica-gate.blade.php:67-68` → `fica.form`).
- **⚠️ What lifts the gate: DIVERGES.** The gate opens on
  `whereIn('status', ['submitted','under_review','agent_approved','approved'])` (`SigningController.php:125-127`)
  — i.e. as soon as the recipient has merely **submitted**, not on a fully **approved** FicaSubmission as the
  ruling requires. (The variable is even named `$ficaApproved`.) An unvetted submission currently lets the
  signer through.
- **Recipient fills own identity, never pre-filled:** **CONFORMS (the bright-line holds)** — `FicaPublicController::form()`
  passes `$contact` to the view but `fica/form.blade.php` **never references it**; every identity field is
  blank Alpine state the recipient fills themselves (`fica/form.blade.php:731-733`).
- **Splitter wet-ink FICA stays:** **CONFORMS** — `FicaWetInkService` via `PdfSplitterController::kickoffMultiFica()`
  (`:604-606,834`), a separate agent-toggled path, intact.

---

## §7 — WET-INK BOUNDARY (sale/alienation never e-sign)

**THE LAW (ruling 7):** *Sale/alienation agreements NEVER e-sign (Alienation of Land Act / ECTA s13(1)) —
mandates, disclosures, FICA, leases < 10y are the e-sign estate.*

**CONFORMANCE — CONFORMS at the binding (signing) gate; verify the pack-flow wizard entry.**

- **CONFORMS** — `Template::isEsignBlocked()` (`app/Models/Docuperfect/Template.php:331-359`) blocks
  `otp, sale_agreement, deed_of_sale, deed_of_alienation, offer_to_purchase` via slug + template_type +
  name-regex, and writes a `LegalBlockAuditLog` row. Enforced **at signing time**
  (`SigningController.php:184` forces `wet_ink` + redirects to the wet-ink portal) and at wizard entry
  (`ESignWizardController.php:138,1496`); `getEffectiveDeliveryModes()` strips `esign` from blocked templates
  (`:403-425`). Mandates/disclosures/FICA/leases are not in the blocklist → remain e-sign eligible. This
  **post-dates and largely closes** the July-11 gap-analysis legal hole (AT-254 `8812f92b` consolidated the
  OTP slug into this blocklist).
- **Residual to verify:** gap analysis C2 found the *wizard `store()`* hard block scoped to single templates
  (`!$isPackFlow && !$pdfPackId`) — so a sale/OTP **inside a web pack** may pass the wizard entry. The
  **signing-time** check (`SigningController.php:184`) is per-document and catches it before any mark is made,
  so the net exposure is closed at the mark; but the pack-flow wizard-entry block should be confirmed/closed
  for defence-in-depth (a blocked doc should never *enter* an e-sign pack flow, not merely be stopped at sign).

---

## §8 — DIVERGENCE TABLE (for Johan)

| # | Canon point | Verdict | The divergence (one line) | Governing file:line | Severity |
|---|---|---|---|---|---|
| 1a | Recipient looping — one block per role | **DIVERGES (live)** | Legacy templates bake `seller_1..seller_4` (cap 4); the `data-role-block` backfill has **never run** (0 templates) → legacy clustering path is what renders live | `WebTemplateDataService.php:213-238`; walk-test ll. 35-39 | **HIGH** — the canon engine is dormant |
| 1b | Inline name line | CONFORMS (+latent risk) | Engine builds "Name Surname (ID) and …" correctly; per-column CDS join could reproduce the legacy shape if not overwritten | `RoleBlockExpansionService.php:1459-1490`; risk `WebTemplateDataService.php:657-677` | LOW |
| 2a | Header strip unconditional | **DIVERGES** | Strip gated by base64/reg/ffc/vat heuristic, not unconditional — text-only letterheads leak | `DocxParserService.php:570-601` | MED |
| 2b | Signature strip from bottom | **DIVERGES** | Bottom-up detector is dead code; live strip is cluster-pattern, not tail-anchored | `DocxParserService.php:678-808`; dead `DocumentTemplateGenerator.php:620-746` | MED |
| 2c | Strip nothing else | **DIVERGES (weak)** | Clustering window unbounded to the tail → mid-body over-strip risk (the March lesson) | `DocxParserService.php:722,734-735,750` | MED |
| 3 | Other conditions editable during signing | **MISSING (main view)** | Present on external sign view; not wired into `SignatureController::sign()` / `sign.blade.php` | `SignatureController.php:857-946` (absence); present `SigningController.php:332` | **HIGH** |
| 4 | Disclosure machinery reaches signing | **DIVERGES** | Active flagship disclosures use the bare-table shape, whose converter is "external-only" → inert on the main signing view; gate is JS-only (no server re-validate) | `disclosure-logic.blade.php:11-13`; `external/sign.blade.php:2958`; `SigningController.php:1328-1384` | **HIGH** |
| 5 | Conditional (binary) rendering | **MISSING** | No conditional tag in the live pipeline; only wired binary mechanism is signing-time strikethrough (the thing to replace); the real primitive is in the held CDS Compiler | `DocumentTemplateGenerator.php:251-287`; `Cds/Condition.php` (held) | **HIGH** |
| 6 | FICA gate — what lifts it | **DIVERGES** | Gate lifts on `submitted`/`under_review`/`agent_approved`, not only `approved` — an unvetted submission passes | `SigningController.php:125-127` | **HIGH** (compliance) |
| 7 | Wet-ink boundary | CONFORMS (verify pack) | Blocked at signing time + wizard entry; confirm sale/OTP can't *enter* a web-pack e-sign flow (gap C2) | `Template.php:331-359`; `SigningController.php:184` | LOW (residual) |

**CONFORMS as-is:** 1c-1e (loop/blocks/roles), 2-injection (company-header + signature-block components),
3-external-view, 4-master/no-data-field, 6-no-prefill + splitter FICA, 7-signing-gate.

---

## §9 — The one thing to hold above all

The canon web/CDS engine is **substantially built and substantially correct** (recipient looping,
components, roles, disclosure master, wet-ink block, FICA no-prefill). Its failures are **two shapes**:

1. **Dormant** — the contract role-block backfill has never run, so live documents (all `render_type=pdf`)
   take the legacy indexed path. *Getting the real documents onto the web/CDS path (the import work) is the
   precondition for §1 conformance in practice.*
2. **Path-split** — the editable Other Conditions (§3) and the disclosure machinery (§4) exist on the
   **external recipient** signing view but not on the **main** `SignatureController::sign()` view; and
   conditional rendering (§5) exists only in the held CDS Compiler, not the live import. Plus two
   compliance-grade single-line divergences: the FICA gate lifts too early (§6) and the disclosure
   completion gate is client-only (§4).

**Before any further content build, these divergences are the work — not new templates.**
