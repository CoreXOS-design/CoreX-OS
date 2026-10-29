# E-sign: the Lease data source, lease import matching, curated field lists, "Suggest links (AI)"

> **Status:** built on Staging 29 Oct 2026 (then QA1). Pairs with `importer.md`, `cds-reimport-from-word.md`,
> `esign-template-readiness.md`, `leases.md` (§15 lease field registry).
> **Why:** Johan imported "Lease agreement - Popi V8" (CDS draft 48): 9 of 25 blanks stayed unlinked, two were linked to the
> wrong thing (lease start -> *Mandate Start Date*, lease end -> the property's *Expiry Date*), three labels were sentence
> fragments, the type picker had no Lease source at all, and the field lists showed raw database columns.

## 1. The Lease source

`App\Services\Docuperfect\LeaseFieldSource` is the one place that knows which lease fields a template may pick. The list is the
rental agreement registry (`config/lease-agreement-fields.php`) so the lease screen, the agreement set-up and a template can never
disagree: monthly rent (+ in words), deposit (+ in words), lease fee, start date, end date, adults, maximum other persons, pets,
escalation % (+ in words), escalation month, earliest date notice may expire, renewal period (months), electricity arrangement,
other conditions, agent service fee, net amount to owner. Party details (names, addresses, IDs) stay on the Contact sources.

- Named-field rows: `docuperfect_named_fields.source_type = 'lease'`, `source_column = <key>`, name `Lease - <label>`. Written by
  migration `2026_12_29_100000_add_lease_source_to_named_fields` (also widens the `source_type` enum), idempotent, so QA1, Staging
  and live all get the same rows on their next migrate.
- Page variable: `lease_<key>` (`deriveBladeName` in `TemplateController` and `WebTemplateDataService`).
- Resolved at signing: `WebTemplateDataService::resolveCdsTemplate` -> `LeaseFieldSource::valuesForStepData`. The flow carries
  `lease_id` in its step data (`LeaseSigningLauncher::buildStepData` / `buildFlow`); values come from the lease and its agreement
  terms through the existing `LeaseAgreementDocumentValues` (one dressing rule: "R 8,500.00", "1 March 2027", "8%"). Proposed
  rent / dates / deposit in the step data's details win over the saved lease. A page that prints its own "R" or "%" gets the bare
  number (`LeaseSeedPresentation` reads the compiled page). A flow with no lease still fills rent, dates and deposit from its details.
- Demo / readiness: the lease demo context supplies sample values for every `lease_*` variable (`AbstractDemoContext::leaseSource`).

## 2. Import matching (leases only)

`LeasePhraseMatcher` reads each blank from the words before it, after it, the previous blank's answer ("(in words)" companions) and
the paragraph above (a blank alone under "23. OTHER CONDITIONS" or a pets lead-in). Used by `CdsParserService::identifyFieldsFromContext`
only when the document heading says it is a lease (`isLeaseDocument`: "lease" / "rental agreement", and not a mandate, notice,
inspection or application). Recognised: adults, other persons, rental shall be, rental in words, escalation % / in words / month,
commence, expire at midnight, expire prior to (earliest termination), renewal period, pets, other conditions, agent service fee,
net amount to owner, deposit, lease fee. A lease date is **never** linked to a mandate or property date: the matcher answers with
`lease_*`, and the builder's own name matching excludes mandate / property dates in a lease (`_matchableFields`).

The lease fee line is recognised by the name the importing **agency** gives it (Rentals settings "Lease fee label", passed in by
`DocumentImporterController::generateCdsTemplate`) or the generic "lease / letting / placement / administration fee". No agency's
wording is in the code.

`CdsBindingSuggester` turns a `lease_<key>` answer (the page's own variable name) into the Lease binding (`sf:lease`, editable by the agent). `CdsParserService::reidentifyFields`
re-reads an already imported document's blanks with the current rules (used to re-run a saved draft).

## 3. Label cleanup

`FieldLabel::fromPrecedingText` replaces the importer's last-resort label: apostrophes stay ("Let's Assist Fee", no longer "s Assist
Fee"), and a sentence fragment ("The Rental shall be", "Adults and not more than") is not a label - the blank stays "Input".

## 4. Curated field lists

`config/docuperfect-field-catalogue.php` + `FieldCatalogue`: the pickers show the columns that mean something on a document per
source (contact, property, agent, deal; Lease, Computed, Static, Manual whole). "Show every database field" under the picker lists
the rest; the field a tag is already mapped to is always listed. Name matching in the builder and the AI only use curated fields.

## 5. "Suggest links (AI)"

Builder header button -> `POST /docuperfect/templates/cds/builder/{draft}/ai-suggest` (`TemplateController::cdsAiSuggest`, draft owner
only) -> `CdsAiFieldSuggester`. It sends the unlinked blanks with their surrounding words and the curated list, accepts only ids from
that list for blanks it was asked about, applies them as orange (medium) links. Goes through `AiSpendGuard` and the AI cost ledger
(`SOURCE_DOCUPERFECT_FIELD_MAP`). The old `AiFieldMapperService` is unused and not wired in; this replaces its purpose.
The CDS Word import itself never calls AI.

## 6. Tests

`tests/Unit/LeasePhraseMatcherTest.php` (every lease phrase, mandate unchanged, labels), `tests/Feature/Docuperfect/LeaseSource/LeaseFieldSourceTest.php`
(named fields, lease values, proposed figures, binding, curated list, builder page, AI route).

## 7. Files

`LeaseFieldSource`, `LeasePhraseMatcher`, `FieldLabel`, `FieldCatalogue`, `CdsAiFieldSuggester`, `CdsParserService`, `CdsBindingSuggester`,
`TemplateController`, `WebTemplateDataService`, `LeaseSigningLauncher`, `LeaseAgreementDocumentValues::dress`, `DocumentImporterController`,
`cds-builder.blade.php`, `config/docuperfect-field-catalogue.php`, `Demo/Contexts/AbstractDemoContext` + `LeaseAgreementContext`, migration above.

## 8. Known limits (29 Oct)

- The CDS Word import finds fields only where the Word file has `@@@@` markers or dotted leaders (5+ dots). Plain underscore blanks
  ("__________") are not fields, so a document written with underscore blanks imports with none and the author must add markers in
  Word or place the fields by hand in the builder.
- The builder offers "Save Template" only when every field is linked (or turned into a typed-by-hand field).
- A draft keeps the parsed result, not the uploaded Word file; the Word file is stored only once a template is saved (re-import).
  `CdsParserService::reidentifyFields` re-reads a saved parse with the current rules.
- Staging's `scripts/deploy.sh` does not run `deploy:sync-reference-data`, so seeder-owned template fixes (e.g. the seeded
  Sales Mandatory Disclosure's buyer signer) reach Staging/live only when the seeder is run by hand.
