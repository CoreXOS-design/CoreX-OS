# E-Sign importer — CDS-path binding convergence: PROOF (AT-177)

**Date:** 2026-07-18 · **Author:** m6 (importer lane) · **Branch:** QA1 (`cf59dcf3`, `[READY-FOR-QA1]`)
**Target of convergence:** template **#70** "EXCLUSIVE AUTHORITY TO SELL - Johan fixed manually" — Johan's hand-setup IS the importer specification.

---

## For Johan — the one-paragraph version

When you import an EXCLUSIVE AUTHORITY TO SELL and set it up, the importer now produces the **same
binding structure you built by hand on #70 — automatically**. The seller name clause binds to the
"Seller full" field group (one shared "I / We … and …" line), every "Seller - …" field binds to its
OWN attribute (address, phone, email) instead of all collapsing to the seller's name, every field is
fillable by the right party, and the three "____ / Signature" acknowledgement lines become
Seller + Agent sign-here spots. You should be **confirming** the import, not repairing it. The only
thing still done by hand is the commission %, because the 7.5% in your document is typed-in text with
no fill-in marker — that is logged as **AT-290** for a future importer rule, not a bug.

---

## What was wrong (root cause)

Your EATS imports travel the **`import/cds`** path (`CdsParserService` → `CdsDraft` → the CDS builder).
The earlier binding fix only touched the **other** import path (`.docx`/`import/parse`). So on the path
you actually use, the builder's auto-suggest **substring-matched** every `Seller - X` token to the bare
"Seller" field — throwing away the attribute and leaving the field with an empty "who can fill this".
That is exactly the structure you had to repair by hand on #70.

## What changed

A single deterministic resolver — `CdsBindingSuggester` — reads your explicit `{Party} - {Attribute}`
tokens up front and binds each correctly; the builder shows them bound out of the box.

| Divergence | Before (auto-suggest) | Now (converged to #70) |
|---|---|---|
| **D1** Seller name clause | bare "Seller" name field | **field group `fg:7` "Seller full"** → single "I / We Name (ID) and Name (ID)" clause |
| **D2** `Seller - Physical address` | → bare "Seller" | → **contact.address**, fillable `[owner, agent]` |
| **D2** `Seller - Telephone` | → bare "Seller" | → **contact.phone**, `[owner, agent]` |
| **D2** `Seller - Email` | → bare "Seller" | → **contact.email**, `[owner]` |
| **D2** property street/township/district/complex/erf | empty "who fills" | property columns, `[owner, agent]` |
| **D2** price / price-in-words / expiry | mixed | property.price / computed.price_in_words / property.expiry_date, locked `[]` |
| **D2** Other conditions | → bare "Seller" | **manual field**, `[agent, owner]` |
| **D4** three "____ / Signature" lines | not detected | **Seller + Agent, sig_only**, ×3 |
| **D5** Commission % | — | no token in the doc → **AT-290** (out of scope) |

## Proof — suggester output vs #70's real `field_mappings`

Ran `CdsBindingSuggester` against #70's actual `cds_json` (qa1 database), compared by **resolved render
target + editable_by set** (order-independent):

```
primary_role = Seller
seller_full_name_and_surname    -> fg:7                     [agent, owner_party, witness]
property_erf_scheme_unit_number -> property.property_number [agent, owner_party]
property_complex_estate_name    -> property.complex_name    [agent, owner_party]
property_street                 -> property.address         [agent, owner_party]
property_township               -> property.town            [agent, owner_party]
property_district               -> property.district        [agent, owner_party]
seller_physical_address         -> contact.address          [agent, owner_party]
seller_telephone                -> contact.phone            [agent, owner_party]
seller_email                    -> contact.email            [owner_party]
document_asking_price_rand      -> property.price           []
document_asking_price_in_words  -> computed.price_in_words  []
document_mandate_expiry_date    -> property.expiry_date     []
document_other_conditions       -> manual                   [agent, owner_party]

TARGET MULTISET DIFF vs #70 inputs: EVERY target matches 1:1.
ONLY divergence: property.commission_percent (suggester 0 / johan 1) = D5, no source token → AT-290.
```

D4 detector run against #70's real sections: **3 signature placeholders created, each
`[Seller, Agent]` sig_only** — matching #70's three sig_only tags exactly; raw "Signature" label
paragraphs consumed; renderer emits `data-sig-parties="Seller,Agent"` / `data-sig-variant="sig_only"`.
Guard confirmed: an underscore line NOT labelled "Signature" (and a sentence merely containing an
underscore run) is left untouched.

## Files

- `app/Services/Docuperfect/CdsBindingSuggester.php` (new — the convergence point)
- `app/Http/Controllers/Docuperfect/TemplateController.php` (`cdsBuilder` attaches per-field binding)
- `resources/views/docuperfect/templates/cds-builder.blade.php` (consume server binding first; sig roster)
- `app/Services/Docuperfect/CdsParserService.php` (`detectUnderscoreSignatureLines` + guard)
- `app/Services/Docuperfect/CdsRendererService.php` (signature data attributes)
- Tests: `tests/Unit/CdsUnderscoreSignatureDetectionTest.php` (4 pass, DB-free) ·
  `tests/Feature/Docuperfect/CdsImportBindingConvergenceTest.php` (D1/D2/disambiguation, CI)

## Deployed verification (qa1 host) — PASSED 2026-07-18 17:36 UTC

Re-ran the full proof against the **deployed** code (`/corex-qa1`, HEAD `ac580cb6` which contains
`cf59dcf3`) + the qa1 database:

- [x] **qa1 HEAD contains `cf59dcf3`** — suggester + parser + renderer + builder changes present on host.
- [x] **suggester proof vs #70:** `TARGET-COUNT DIVERGENCES: 1` — every render target reproduced 1:1
      with matching `editable_by`; the sole divergence is `property.commission_percent` (D5, no source
      token → AT-290).
- [x] **alignment:** `extractFieldsFromCds` (13) = suggester tokens (13) = bindings (13); every
      `field_name` matches its binding index 1:1; **13/13 fields bind non-null** (the right binding
      lands on the right field).
- [x] **D4 proof:** 3 signature placeholders created, each `[Seller, Agent]` sig_only; raw "Signature"
      paragraphs consumed; renderer emits `data-sig-parties="Seller,Agent"` / `data-sig-variant="sig_only"`
      ×3; guard leaves a non-"Signature" underscore line untouched.

**Result: zero substantive divergences vs #70. D1 / D2 / D4 closed on the deployed qa1 host.**
Ready for Johan's fresh-import test. (Browser-side consumption of the server binding is a direct
pass-through — `cds-builder` `_mappingFromServerBinding` — and is the camera/e2e stage's confirm step.)
