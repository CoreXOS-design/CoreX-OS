# Mandatory Disclosure rebuilt as the exact prescribed government form (2026-07-27)

Branch `esign-input-followup`, commit `f56be838`. QA1 only. Johan's directive: the CoreX
disclosure was an approximation and WRONG; rebuild it to render WORD-FOR-WORD / TICK-FOR-TICK
identical to the prescribed Immovable Property Condition Report (Property Practitioners Act
22 of 2019 s70 / Regulations 2022 s36). ONLY the letterhead varies per agency.

## What changed
`resources/views/docuperfect/web-templates/cds/template-123.blade.php` rebuilt from the
verbatim DOCX. Renders, in order:
- Letterhead (`company-header` — the ONLY agency-variable region; `LetterheadRefresher` keeps it current).
- Title (verbatim, incl. the Act/Regulation citation).
- 1 Disclaimer (with the property-address blank, `data-field="property_full_address"`).
- 2 Definitions (2.1 "to be aware", 2.2 "defect") — verbatim.
- 3 Disclosure of information — verbatim.
- 4 Provision of additional information — verbatim.
- 5 Statements in connection with Property — the 11 prescribed statements, NUMBERED 1–11, as a
  tickable YES/NO/N-A table (`corex-disclosure-checklist` / `.corex-disclosure-row` /
  `.corex-radio-placeholder`, `data-disclosure-party="owner_party"`), then an ADDITIONAL
  INFORMATION area (`data-field="additional_information"`) for the seller's "yes" explanations.
- 6 Owner's certification, 7 Certification by person supplying information, 8 Notice re
  advice/inspections, 9 Buyer's acknowledgement — all verbatim.
- 10 Signatures — Seller / Purchaser / Property Practitioner / Co-signature (if required), each
  "Signed at ___ on ___" via ceremony markers (`data-marker-type="location|day|month|year"`)
  + a signature line (`data-marker-type="signature"`).
- Removed the Step-2 `~~~~OTHER_CONDITIONS~~~~` block — it is NOT part of the prescribed form
  (that feature stays on the mandate). Removed a duplicated caption.

Ticks: the chosen YES/NO/N-A renders a real ✓ (Step-3 tick mechanism, identical on review +
PDF). The tick JS + gov-form grid CSS are inlined into the PDF, so screen == PDF.

## QA1 proof — persistent standalone doc 472
Seeded a persistent standalone Mandatory Disclosure on serving corex_qa1 (template 71 →
blade template-123), prefilled with a sample property address, 6 answered statements, and a
sample additional-information explanation (unsigned, so it reads like the blank prescribed form).
Verified (forDisplay canonical == PDF source):
- All 10 sections present VERBATIM (1 Disclaimer … 10 Signatures); title with the Act citation.
- Exactly 11 tickable statement rows; exactly 4 signature surfaces (Seller/Purchaser/
  Practitioner/Co-signature).
- Property address + additional-information flow in; letterhead present; NO other-conditions /
  insertable block.
- PDF embeds the SAME tick-restore JS (✓) and the stored answers → screen == PDF; a real PDF
  was generated (Chromium applied the ✓ ticks end-to-end).

**Johan — open to compare side-by-side with the government form (QA1, logged in):**
- Review (screen): `https://qatesting1.corexos.co.za/docuperfect/documents/472/signatures/review`
- PDF: `https://qatesting1.corexos.co.za/docuperfect/documents/472/signatures/download`

## Still to do (after Johan confirms the render lines up)
1. Signability wiring: add `acquiring_party` (purchaser) to the template's `signing_parties`
   and an `editable_by` `field_mappings` entry for `additional_information` so the owner types
   it live (the render/PDF proof above uses prefilled values). Then seed a ready-to-sign
   standalone MDF (like pack doc 471) so Johan can sign it end-to-end.
2. **Addendum B** — a SEPARATE STANDALONE document (Johan's correction: NOT appended to the
   MDF): heading "ADDENDUM B" + EXTRA INFORMATION table (building plans Y/N; CoC Electrical /
   Electrical Fence / Gas / Entomology each Y/N + when-issued date) + its own Seller/Purchaser/
   Practitioner/Co-signature block. Renders + signs like any single doc.
3. Packs then compose the standalone docs (mandate + MDF + Addendum B) — build only once the
   single docs are right.

Reusable for sales AND rentals as the canonical prescribed disclosure (wording is prescribed,
so it is agency-invariant; only the letterhead varies).
