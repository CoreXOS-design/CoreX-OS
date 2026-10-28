# E-sign template demo view ("see the document as the signer will, with sample data")

> Status: SPEC, written 28 Oct 2026 before code (build 3 of the e-sign document workshop). Orders: Johan's 26 Oct shape (step b: "viewed as a demo — preview with sample data, every merge field, every signature/initial place and every party linked correctly"), relayed 28 Oct.
> Companion specs: `esign-template-readiness.md`, `esign-template-transfer.md` §13. Related: `esign-signing-modes.md`, `cds-reimport-from-word.md` §10–11 (the page frame and paginator this view reuses), `agency-letterhead.md`.

## 1. What it does and why

Before a template is released to an agency (and again before the agency approves it) a person has to *look at it the way a signer will*: every blank filled with believable data, every signature and initial place visible and coloured by who signs it, every page as the signing link will draw it. Today the only preview (`TemplateController::webPreview`) prints each blank as "[Label]" and shows nothing about signers. Mega's lease was demo'd by hand: a test lease prepared in tinker, the link opened, pages counted.

The demo view is a **read-only render of one template with a built-in sample property, sample people and a sample lease**. It writes nothing, sends nothing, creates no document, flow, envelope or contact, and cannot be signed. It is not a signing session; it is the same document body drawn by the same code.

## 2. How it is drawn (what is real and what is not — stated plainly)

Real (reused, not copied):
- the template's compiled page, rendered by `WebTemplateBladeEnsurer::renderOrFallback` with the sample values (the same call the signing flow's renderer makes, so every `{{ $field ?? '' }}` blank is filled the same way);
- the page frame and the e-sign **paginator** (`signatures/partials/a4-page-styles.blade.php`, `paginateDocument`) inside the shared document body (`docuperfect.shared._document-body`), so the page count and where pages break are the signing link's, including the Word header/footer repeated on every page and the per-page initials strip;
- the agency letterhead (`<x-agency-letterhead>` through the template's own `header_display`), resolved for the template's owning agency (a workshop template with no agency uses neutral sample letterhead).

Not real (declared on the page in one plain line): there is no signing controller, no token, no ink; the signature and initial places are drawn as marked boxes, not clickable. The count under "pages" is the paginator's count in the viewer's own browser (the two signing screens differ by up to two pages because of their page shells — the 14 vs 16 seen on Mega's lease 63; the demo uses the public signing link's shell, the one signers use).

## 3. Sample data

`config/template-demo-sample.php` (neutral, South African, **no HFC and no real person**): sample property ("12 Sample Street, Table View, Cape Town, 7441", erf, description, stand size), sample lessor, lessee and agent (names, surnames, ID numbers in the right shape and obviously fake, 082 555 01xx phones, `@example.co.za` e-mails), sample lease (rent R12 500, deposit R18 750, start date 1st of next month, 12 months, notice 20 days, bank details of "Sample Bank"), sample agency name from the template's agency (or "Your Agency"). `App\Services\Docuperfect\Demo\TemplateDemoSample::valuesFor(Template): array` returns `variable => value` and, for every blank, **where the value came from**: `bound` (the blank's own binding — named field `source_column` / lease field-map key — mapped to the sample), `guessed` (matched by the field's name or label, flagged amber), or `placeholder` ("Sample: <label>", flagged amber). The frame tokens `{PROPERTY}` `{SURNAME}` `{YEAR}` `{PAGE}` are filled from the sample the same way the signing page fills them from the document.

## 4. The screen

`GET /docuperfect/templates/{id}/demo` (`docuperfect.templates.demo`), in the Templates hub context, **fixed shell (left menu + top header), no page scrolling**: the document pane scrolls inside itself; the side panel is tabbed.

- **Banner (always on):** "DEMO — sample data. Nothing can be signed or sent from here."
- **Document pane:** the paginated document. Every signature and initial place is outlined in its signer's colour with the signer's name; every blank that came out empty is outlined red; every `guessed` / `placeholder` blank is outlined amber.
- **Tab "Signers and places":** one row per place — kind (signature / initial), page it landed on, the name as written in the template, the declared signer it resolves to (or "not a declared signer" in red), colour. Below: each declared signer with its count of signature places and initial places. Zero places for a declared signer is red.
- **Tab "Fields":** one row per blank — label, what it is linked to (named field / lease field / typed by hand), the sample value, where it came from (bound / guessed / placeholder / EMPTY). Search by label; filter by status (all / empty / guessed / typed by hand); sorted by position in the document (stated default); filter chips; empty state ("every blank has a value" vs "no blanks match").
- **Tab "Page frame":** the header and footer exactly as printed with sample values; the pages count; letterhead on or off and why (`header_display`).
- **Tab "Readiness":** the verdict and failing/warning checks of the readiness report, with a link to the full report.
- Actions: "Check readiness", "Back to templates". The verify and release buttons live on the Releases screen, not here (so looking never changes anything).

## 5. Permissions, scoping

- Same gate as the readiness screen: `manage_templates` + `Template::assertAccessibleBy` + `isVisibleToAgency`; 404 for another agency's template by direct URL. A template waiting for approval (§13) can also be demo'd by that agency's authorisers (they must be able to look before approving) and by the owner.
- Read-only: GET only; a test records `documents`, `flows`, `contacts`, `properties`, `signature_*`, `domain_events`, mail and storage before and after.
- Not a list screen (the Fields tab is a search/filter table inside the page); not an entity, so CRUD floor = none to add.

## 6. Navigation, feature switch, wizard

- Buttons "Demo view" on the Template Management list, both template edit screens, the Releases screen and the Approvals screen. No new sidebar item or hub tab (so no feature-registry row); it lives under the existing `docuperfect` feature.
- Not an agency setting; not in the Setup Wizard (non-negotiable 10a): a CoreX preparer / approver view. Decision on the record; Johan can overrule.

## 7. Input space — prevent or absorb

PDF (non-web) template → the page says "This is a PDF template; the demo shows its pages and signature zones" and lists zones (no blanks). Template with no `blade_view` / missing page file → regenerated via the ensurer; if that fails, the stored-HTML fallback `renderOrFallback` already gives; if nothing renders, a plain message, never a 500. Field with no variable name → listed as "cannot be filled" (red). A name the sample does not know → placeholder (amber). Very long template → paginator runs once. Template with no declared signers → "no signers declared" notice, places listed as not declared. Another agency / unknown id → 404.

## 8. Acceptance criteria

1. Mega 82 and 83 render with every blank filled or flagged, every place coloured, the header showing sample property/surname/year, and a page count; the page writes nothing.
2. A blank named by a bound lease field (e.g. `lease_fee`, `rental_amount`) shows the sample lease value and "bound".
3. A signature place naming an undeclared signer is red in the document and in the places table.
4. The banner is present; the response has no form that posts anywhere and no signing script.
5. Another agency's user gets 404; no permission gets 403; the demo of an awaiting-approval copy is allowed for that agency's authorisers.

## 9. Tests

`tests/Feature/Docuperfect/Workshop/TemplateDemoSampleTest.php` (value sources: bound / guessed / placeholder; frame tokens; no HFC strings in the sample), `tests/Feature/Docuperfect/Workshop/TemplateDemoViewTest.php` (route 200 owner and own-agency manager, 404 other agency, 403 no permission, banner, places and fields tables from a Mega-shaped fixture, empty blank flagged, no-write proof, PDF template message). Paths named in the report: all-fields, template with no blanks, unnamed variable, undeclared signer, unknown id.

## 10. Files

`config/template-demo-sample.php`, `app/Services/Docuperfect/Demo/TemplateDemoSample.php`, `app/Services/Docuperfect/Demo/TemplateDemoRenderer.php`, `app/Http/Controllers/Docuperfect/TemplateDemoController.php`, `resources/views/docuperfect/templates/demo.blade.php`, buttons on `templates/index.blade.php`, `edit.blade.php`, `edit-web.blade.php`, route in `routes/web.php`, tests above.
