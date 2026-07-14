# Proforma Invoices — spec

> **Foundation of the Accounting pillar.** Last updated 2026-07-12. Johan-specced.

## 1. What & why (design principle first)

A proforma invoice is **a structured financial RECORD**, not a PDF generator. Each record carries:
number, deal FK, a breakdown of amounts, the parties it is issued to, status, and a full audit
trail. The **PDF is only a rendering** of the record. The future accounting **ledger consumes these
records** — so the schema is designed for that consumer, not for a throwaway document.

Pillars: **Deal** (source of truth for parties + financials), **Agency** (numbering, branding, VAT,
bank details), **Contact** (seller), **Document** (the filed PDF). Cross-pillar reactivity uses the
domain-events catalogue where applicable (`.ai/specs/corex-domain-events-spec.md`).

## 2. Data model

### 2.1 `agency_proforma_settings` (one row per agency, `firstOrCreate` default)
| column | type | default | note |
|---|---|---|---|
| id | pk | | |
| agency_id | fk unique | | tenant anchor (BelongsToAgency) |
| number_prefix | string(16) | `'PRO-'` | configurable |
| next_number | unsigned int | 1 | START NUMBER; the sequence counter (never reused) |
| number_padding | tinyint | 4 | zero-pad width (PRO-0001) |
| due_date_rule | enum(`end_of_month`,`days_after`,`on_receipt`) | `end_of_month` | |
| due_days | unsigned int | 30 | used when rule=`days_after` |
| bank_details | text null | | free block rendered under "Notes" |
| timestamps | | | |

Letterhead/logo, VAT number, company name/address, VAT-registered flag are **reused from the
Agency branding settings — NOT duplicated here** (zero hardwiring).

### 2.2 `proforma_invoices` (the record)
| column | type | note |
|---|---|---|
| id | pk | |
| agency_id | fk | tenant; THIS agency's record (split deals → this agency's share only) |
| deal_id | fk `deals` | the DR1-twin deal |
| number | string(32) unique-per-agency | e.g. `PRO-0001`; assigned atomically |
| sequence_no | unsigned int | the raw integer behind the number (integrity/ordering) |
| status | enum(`issued`,`voided`) | voided kept (no hard delete) |
| issued_to_contact_id | fk `contacts` null | the SELLER |
| issued_to_name | string | snapshot: seller name at issue |
| care_of_provider_id | fk `agency_service_providers` null | transferring attorney |
| care_of_name | string null | snapshot: attorney firm name at issue |
| reference | string | "deal# – property address" |
| due_date | date | resolved from the agency rule at issue |
| vat_registered | boolean | snapshot of the agency VAT setting at issue (drives rendering) |
| vat_rate | decimal(5,2) | snapshot (15.00) |
| subtotal_excl | decimal(14,2) | Σ line excl |
| vat_amount | decimal(14,2) | Σ line vat (0 when not vat_registered) |
| total_incl | decimal(14,2) | Σ line incl |
| document_id | fk `documents` null | the filed PDF |
| communication_id | fk null | the comms record for the email |
| created_by_id | fk users | who generated |
| voided_by_id / voided_at / void_reason | | admin void audit |
| timestamps + softDeletes | | |

Unique `(agency_id, sequence_no)` and `(agency_id, number)`.

### 2.3 `proforma_invoice_lines`
| column | type | note |
|---|---|---|
| id | pk | |
| proforma_invoice_id | fk | |
| agency_id | fk | tenant stamp |
| description | string | e.g. "Sales commission", "Discount on commission" |
| amount_excl | decimal(14,2) | |
| vat_amount | decimal(14,2) | 0 when agency not VAT-registered |
| amount_incl | decimal(14,2) | |
| kind | enum(`commission`,`adjustment`) | commission = system line (locked); adjustment = admin-added |
| is_locked | boolean | true for the commission line (agents/BMs cannot edit) |
| created_by_id | fk users | |
| sort_order | int | |
| timestamps | | |

### 2.4 `proforma_invoice_audit` (append-only; `update()`/`delete()` throw)
`id, proforma_invoice_id, agency_id, event enum(generated, line_added, line_removed, voided, regenerated, number_changed, emailed), actor_id, meta json, created_at`.
Mirrors the immutable audit pattern (deal_document_access_log / CommsAccessAuditLog).

## 3. Numbering (sequence integrity)
- Per agency. `PROFORMA number = prefix + zero-pad(next_number, padding)`.
- Assignment is **atomic**: inside a DB transaction, `SELECT ... FOR UPDATE` the settings row,
  read `next_number`, stamp it as the record's `sequence_no`/`number`, `next_number++`. No two
  records ever share a sequence; a void does **not** free the number for reuse.
- Configurable prefix + **start number** (default 1); changing `next_number` forward is admin-only + audited.

## 4. Generation (the button)
- Surfaces: **DR2 deal view** (`resources/views/dr2/pipeline.blade.php`, near the documents section)
  AND **agent My Deals**. Button gated `proforma.generate`.
- **Granted-status-onward ONLY** — never pending/declined. Server-authoritative gate on the deal
  status (see §7). The button is hidden AND the endpoint refuses otherwise.
- **Any agent may generate.** Every generation is **audited** (actor + time → `proforma_invoice_audit`
  event=`generated`) and **admins are notified** on each creation.
- Idempotency: generation always mints a NEW record (a deal can have several proformas over its life —
  e.g. after an admin adjustment); regeneration of the SAME record's PDF is a separate admin action (§6).

## 5. Content — locked to the deal's truth
- **Made out to:** the **SELLER** (from the deal) **c/o the TRANSFERRING ATTORNEY** (from the deal).
  Both snapshotted onto the record at issue.
- **Reference:** `"{deal#} – {property address}"`.
- **Commission line:** "Sales commission" with **excl / VAT / incl** taken from the deal's VAT-aware
  financials (§ deal financial resolver). **Split deals → THIS agency's share only.**
- **Agents and BMs cannot edit figures.** The commission line `is_locked=true`.
- **ADMIN ONLY** may: add adjustment lines (e.g. "Discount on commission" — negative excl allowed),
  change/advance numbers, void, regenerate. All audited.

## 6. VAT rendering
- Follows the **agency VAT-registered setting** (snapshotted as `vat_registered` at issue).
- Registered → show excl / VAT (15%) / incl split + the agency VAT number.
- Not registered → **no VAT split**; a single amount column; no VAT number line.

## 7. Granted-gate (deal status)
Resolved from the deal's status truth (confirmed by investigation). Generation allowed when the deal
is **granted / registration / completed**; refused for **pending / declined / lapsed**. One
server-side predicate `Deal::isProformaEligible()` (or a resolver) — used by BOTH the button
visibility and the endpoint guard. Never trust the client.

## 8. Regeneration / void (admin-only)
- Void: `status=voided`, `voided_by/at/reason`, audit event `voided`. Record **kept** (no hard delete);
  its number is **never reused**. The filed PDF is marked voided (watermark on re-render).
- Regenerate PDF: admin re-renders the current record's PDF (e.g. after adding a line) → re-files the
  Document, audit `regenerated`. Same number.

## 9. Lifecycle (integration is the moat)
1. **Auto-file** the PDF on the deal as document type **`proforma_invoice`** ("Proforma Invoice") —
   register the type in the splitter `document_types`. File via the AT-225 `DealDocumentService`
   (reachable from deal + property + seller contact).
2. **Register in deal comms** — a `communications` record + `communication_links` to Deal + Property +
   the seller Contact (three pillars), mirroring the AT-225 distribution comms pattern.
3. **Email** — simple **attach-email** for now (Mailpit-testable on qa1): the PDF to the seller
   (and/or attorney) via the app Mailer. **Marked upgrade path:** swap to the **AT-228 compose flow**
   when it lands (a single clearly-commented seam `ProformaMailer::send()` → TODO(AT-228)).

## 10. Agency settings section
New "Proforma Invoices" section under agency settings: prefix, start number, padding, due-date rule
(+ days), bank details. Reuses the existing agency-settings controller/blade pattern. Letterhead/logo/
VAT-no/VAT-registered pulled live from branding — shown read-only with a link to branding settings.
Nav entry added same day (non-negotiable #2).

## 11. PDF layout (Pastel feel)
Single page, Blade → PDF. Blocks: **header** (agency letterhead/logo + company + VAT no) · **number /
date / reference / due panel** · **made-out-to** (seller c/o attorney) · **single-line table**
(description | excl | VAT | incl — or one amount col when not VAT-registered) · **totals** (subtotal
excl / VAT / total incl) · **notes = bank details**. Voided → diagonal "VOID" watermark.

## 12. Permissions
- `proforma.generate` — agent + BM + admin (granted-onward, server-gated).
- `proforma.manage` — **admin only**: add/remove lines, void, regenerate, change numbers, edit settings.
Registered in `config/corex-permissions.php` role_defaults + `corex:sync-permissions --merge-defaults`;
route middleware + controller checks + sidebar/settings gating.

## 13. Acceptance / tests
- **Granted-gate:** generate refused (403) for pending/declined; allowed for granted+. (button hidden too)
- **Admin-only overrides:** agent/BM cannot add a line / void / change number (403); admin can.
- **Sequence integrity:** N concurrent generations get N distinct consecutive numbers; a void does not
  reuse a number; start-number honoured.
- **Content truth:** commission excl/VAT/incl equals the deal's financials; split deal → this agency's
  share only; seller + transferring attorney correct.
- **VAT rendering:** registered agency shows split + VAT no; unregistered shows none.
- **Lifecycle:** PDF files as `proforma_invoice` on the deal, a comms record links all three pillars,
  email lands in Mailpit with the PDF attached.
- **No hard deletes:** voided record + lines remain (softDeletes), recoverable.

## 14. Files (create/modify) — filled during build
Migrations (4), models (5), `ProformaNumberService`, `ProformaFinancialResolver`,
`ProformaGenerationService`, `ProformaPdfRenderer`, `ProformaMailer`, controllers (deal-facing +
admin settings/overrides), routes, Blade (PDF + settings section + buttons), permission keys,
document-type registration, `AllBladeViewsCompileTest`-safe views, feature tests.
