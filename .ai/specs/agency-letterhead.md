# Agency letterhead — one shared component for every document an agency's name goes on

Status: built on QA1 (27 Oct 2026). Found by cc1's Mega document-readiness audit (26 Oct): the inventory report, inspection forms and FICA form carried no (or a half) letterhead.

## 1. Rule

Every document CoreX produces for an agency carries the agency letterhead, from the agency's own settings, through ONE component — no per-document copies:

- **Header:** logo left; name, address, tel, e-mail, website and VAT line right. No logo file on disk = the name in large type instead (never a broken image).
- **Footer:** `<legal name> | Reg No <reg> | PPRA Reg <ppra>`. A blank part is left out with its label (no legal name = the trading name; no reg no / PPRA = those parts vanish).
- **A blank setting prints nothing** — never `VAT No:` with nothing after it.

## 2. Settings (per agency; a branch's own trading name / address / phone / e-mail / reg no / VAT no / PPRA / logo wins where it has one)

| Setting | Column | Where |
|---|---|---|
| Trading name | `agencies.trading_name` (else `name`) | Company Settings, wizard identity step (existed) |
| **Registered company name (footer)** | `agencies.legal_name` (new) | Company Settings → Company Identity; wizard identity step |
| Registration no | `agencies.reg_no` | existed |
| PPRA reg | `agencies.ppra_number` | existed |
| VAT no | `agencies.vat_no` | existed; blank = no VAT line |
| Address / phone (+ label, secondary) / e-mail / logo | existing columns | existed |
| **Website printed on documents** | `agencies.document_website` (new) | Company Settings → Company Identity; wizard identity step |

`document_website` is deliberately NOT `agencies.website_url`: that one drives the public-listing redirect and must never reach a document (a test pins it). Both new fields are written only when the form posts the box (`validated()` omits absent keys, so a post that does not carry them never empties them).

Mega (agency 23, QA1): legal name `VIPS cc t/a Mega Rent Real Estate`, reg `CK2003/088713/23`, PPRA `1135017`, website `www.megarent.co.za` (read from her Word header), VAT blank.

## 3. The component

- `App\Support\Documents\AgencyLetterhead::for($agency, $branch = null)` / `forAgencyId($id)` / `current()` (the document's agency via `DocumentAgencyContext`, else the signed-in user's; no fallback agency) — the values.
- `<x-agency-letterhead :agency-id="…" />` — the header (table layout so DomPDF and a browser draw the same thing).
- `<x-agency-letterhead-footer :agency-id="…" :fixed="true" />` — the footer; `fixed` repeats it on every DomPDF page inside the 36 pt page margin, off it is a block at the end of a web page.
- Another agency's branch can never speak for an agency (the branch is dropped unless it belongs to it).

## 4. Where it is applied

| Document | View | Note |
|---|---|---|
| Inventory report (PDF, also the "print for signature" copy) | `corex/rental-inventories/report-pdf` | header + fixed footer |
| Completed inspection report (PDF) | `corex/rental-inspections/report-pdf` | replaces the old name + logo strip; the inspecting agent's contact line sits under it. Still switched off by the agency's own `report_shows_agency_branding` rule (RentalInspectionSetting) — that is an agency choice, kept |
| Blank (and pre-filled) inspection form (PDF) | `corex/rental-inspections/form-pdf` + `RentalInspectionFormPdfService` | letterhead block on page 1 above the title (compact three-line letterhead, kept clear of the page-identifier grid in the top-right; page-1 header grows by `LETTERHEAD_HEIGHT` 56 pt, vertical pitches already scale), footer line on every page left of the QR. `LAYOUT_VERSION` 3 → 4: each inspection's form regenerates once; a form printed earlier keeps its stored geometry, so scans of old prints still read. Nothing is marked or read back in the letterhead |
| FICA form (public page) | `fica/form` | replaces name + logo; footer at the end of the form |
| Rental application | cc1 builds it | consumes the component (see `/tmp/m61/NOTE-cc2.txt`) |

Not changed (reported): Word-imported lease templates keep their own letterhead (`header_display` rules, cds-reimport-from-word.md); the e-sign `company-header` block is the older, separate document header.

## 5. Importer: a running header with document-specific text (cds-reimport-from-word.md §10 item 10)

Mega's Word file prints "Mega Rent Lease – 50d Madeira Crescent – Arendse - 2026" on every page — a previous tenant's details. `CdsParserService::frameMergeFields` recognises that shape (a title, then a street-numbered address segment, a year, and a single capitalised word between them) and stores `Mega Rent Lease – {PROPERTY} – {SURNAME} – {YEAR}`. `CdsRendererService::frameTokens` draws merge chips (`corex-merge`), and `TemplateController::frameMergeFieldsToBlade` turns them into the document's own `property_address`, `lessee_last_name` (else `seller_last_name`) and lease-start year (current year when none). An ordinary header ("Mega Rent Lease – Confidential", "Lease Agreement – 2026") is left exactly as written.

## 6. Tests

`tests/Feature/Documents/AgencyLetterheadTest.php` (full-settings letterhead on all four documents; VAT line absent when blank on all four; blank settings leave their lines out; the redirect website never printed; foreign branch ignored; Company Settings saves the new fields and a post without them leaves them alone), `tests/Unit/CdsImporterFidelityTest.php` (header becomes merge fields; ordinary header untouched).

## 7. Audit hardening (28 Oct 2026)

- The VAT line prints only for an agency that is VAT-registered (`agencies.vat_registered`); a stale `vat_no` on a de-registered agency prints nothing.
- The logo is read only when its resolved path (`realpath`) sits inside `storage/app/public`; a stored `../` path or a symlink out is ignored (no logo).
- The compact header (printable inspection form) is clamped: name, address and at most two contact lines, each cut with an ellipsis, so it cannot outgrow `LETTERHEAD_HEIGHT` and push into the form title.
- `RentalInspectionFormPdfService::contentHash` includes the letterhead values (name, address, phones, e-mail, website, VAT, footer line, logo digest): a changed agency detail makes the next download a new form version. Tests in `AgencyLetterheadTest`.
