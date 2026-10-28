# E-sign template readiness report ("is this document ready to hand to an agency?")

> Status: SPEC, written 28 Oct 2026 before code (build 1 of the e-sign document workshop). Orders: Johan's 26 Oct shape, relayed by the conductor 28 Oct.
> Part of the workshop set: this file (readiness), `esign-template-demo-preview.md` (demo view), `esign-template-transfer.md` §13 (release states, authoriser setting, approval, release log, lease link in the package, CLI).
> Pillars: Documents (templates). Agency is the outer boundary. Related: `cds-reimport-from-word.md` (importer; its code is NOT fixed by this work — documents are prepared by hand and this report tells the preparer what is still wrong), `leases.md` §15.12 (lease guard, reused), `esign-template-transfer.md` §6 (safety scan, reused).

## 1. What it does and why

CoreX builds each agency's documents itself, then releases them. Until now nothing told the preparer, in one place, whether a built template is complete: a field left unlinked, a signature place naming nobody, a header still carrying the previous tenant's name, a Word file the importer only half read. The preparer found these by walking a test lease by hand (Mega, 26 Oct).

The readiness report is a **read-only check of one template**. It changes nothing. It answers, in plain words: *can this template be used for a real signing today, and if not, what exactly is wrong and what do I do about it?* The importer is deliberately not "fixed" here: the report reports what the importer dropped and what is left unlinked so the preparer can finish the document in the builder.

## 2. The checks (each has an id, a plain title, a result, and "what to do")

Result per check: `pass`, `warn` (should be looked at; does not block verification), `fail` (blocks "verified"), `info` (a fact, never blocks). Verdict: `not_ready` when any `fail`; `ready_with_warnings` when any `warn`; else `ready`.

| id | Title | Fail / warn rule | What to do (plain) |
|---|---|---|---|
| R01 | The document has wording | fail when the template has no document content (no saved sections, no tagged text, no page file) | "Open it in the builder and save it once." |
| R02 | The page file is there | warn when `blade_view` is empty or its file is missing (it is regenerated on first use) | "Open the template in the builder and Save to regenerate." |
| R03 | Who signs is declared | fail when an e-sign template has no `signing_parties` | "Add the signers in Template settings." |
| R04 | Every signer has a place to sign | fail for a declared client signer (owner / acquiring party) with no signature place anywhere (marks, zones, sections); **warn** when only the agent has none (the agent can still approve and send — Mega's lease is built this way); passes for every declared signer when the template uses CoreX's own signature block (`signature_block` = corex), because that block adds a place for each | "Place a signature for <party>." |
| R05 | Every signature / initial place names a declared signer | fail when a place names a party the template does not declare (names are compared the way the lease guard does: Lessor = owner party, Lessee = acquiring party) | "Change the place to one of: …". |
| R06 | Initial places | info only: count per signer (CoreX adds its per-page initials strip at signing, so a document without its own initial places is normal) | — |
| R07 | Every blank is linked to a field | fail when a fill-in blank has no field mapping or an empty label; the list names the blanks by position and label | "Link blank #n in the builder." |
| R08 | Linked fields still exist | fail when a mapping points at a named field or field group that no longer exists | "Re-link <label>." |
| R09 | Typed-by-hand blanks | info: count of blanks the agent types by hand while preparing | — |
| R10 | Header and footer words | warn for a token other than `{PROPERTY}` `{SURNAME}` `{YEAR}` `{PAGE}`; warn when the header looks like a specific tenancy (a street number + a year, as in the Mega Word file) | "Replace the specific words with merge fields." |
| R11 | Nothing the Word file printed was lost | warn from the importer's own `parse_report` (`dropped` not empty, or `unread_chars` > 0); lists what was dropped | "Re-create the dropped part by hand." |
| R12 | Document type and e-sign | warn when no document type; fail when `is_esign` is false for a template that is linked as a lease; warn when it is simply not an e-sign document | "Set the document type." |
| R13 | Letterhead | warn when the template prints the agency letterhead (`header_display` not `none`) and the owning agency has none of name, address, phone/e-mail set; info "this document prints its own letterhead" when `none` and the document has a page header | "Fill the agency's company details." |
| R14 | Lease readiness | only when the template is linked in Rentals → Lease agreements: runs `LeaseAgreementTemplateGuard::problemsFor` with the link's field map and `unresolvedWarnings` (each is a `fail` / `warn` line). Otherwise info: "not linked as a lease" and, when it has signing places for agent / landlord / tenant, "can be linked as a lease". | per guard wording |
| R15 | The package would be accepted | fail when the package safety scan (`TemplateContentSafety`) would refuse the content (script, Blade syntax, and so on) | "Remove <what>." |
| R16 | Archived or deleted | fail when archived (`archived_at`) | "Restore it first." |
| R17 | Page count | info: stored `page_count`; "0 = not measured; the demo view measures it" | — |

Every check never throws: a check that cannot run (PDF template with no sections, a JSON column stored double-encoded, a null column) returns `info` "not applicable" or the best honest result. Absorb, never 500 (BUILD_STANDARD §3).

## 3. Where it lives

- Service `App\Services\Docuperfect\Readiness\TemplateReadinessReport` (pure; `forTemplate(Template): array` returning `{verdict, counts, checks[]}` with each check `{id, title, status, detail, action, items[]}`). It reads the template and the already-existing guards; it writes nothing.
- Screen `GET /docuperfect/templates/{id}/readiness` (`docuperfect.templates.readiness`) inside the Templates hub: the verdict as the heading, counts, then one row per check with a coloured status, the plain detail, and what to do; failing and warning checks first. Linked from the Template Management list (a "Readiness" action per template) and from both template edit screens ("Check readiness" button). Also reached from the Releases screen (`esign-template-transfer.md` §13).
- Command `php artisan templates:readiness {template_id} [--json]` (prints the same checks; exit code 1 when `not_ready`). Used for the Mega test set and for rehearsal before a live move. Read-only.
- No new table. The verified snapshot is stored with the release (§13), not here.

## 4. Permissions, scoping (BUILD_STANDARD §1c)

- Screen and command: `manage_templates` (the route's existing gate for Template Management) AND `Template::assertAccessibleBy($user)` AND `isVisibleToAgency` — the same pair `webPreview` uses. A direct URL by id for another agency's template is a 404, not a hidden link. The owner sees every template; an agency's own users see their agency's templates.
- Read-only for everyone; there is nothing to archive or restore on a report (it is computed, not stored). The CRUD floor is satisfied by the template's own CRUD; declared here as "computed view, no stored entity".
- A template waiting for approval (`release_status = awaiting_approval`, §13) is readable to the agency's authorisers and the owner only.

## 5. Navigation, feature switch, wizard

- Reached by buttons on existing screens (list and both edit screens) and from the Releases tab. No new sidebar item and no new Documents-hub tab, so no `config/corex-features.php` row is needed (rule: only a new sidebar item needs a toggle); it sits under the existing `docuperfect` feature and the existing route gate.
- Not an agency setting, so not in the Setup Wizard (non-negotiable 10a): it is a CoreX preparer tool. Recorded here as a decision on the record; Johan can overrule.

## 6. Input space (BUILD_STANDARD §2–§3) — prevent or absorb

Template id not a number / not found / another agency's → 404. PDF template (no CDS sections) → R01 passes on page images, R07/R08 read `fields_json`/zones. Template with null `editor_state` / `cds_json` / `signing_parties` / `field_mappings` → each treated as empty, results stated. Columns stored double-encoded (known, see export spec) → decoded once. Tag with no mapping, mapping with no tag, signature mapping without `parties` → reported, never an exception. A template of 300 fields → one pass over the data, no per-field query (named fields and groups resolved in one `whereIn`). Archived / soft-deleted template → R16 fail, report still shows.

## 7. Acceptance criteria

1. Mega 82 and 83 produce a report with no exceptions; the Mega header (merge fields) is accepted by R10; the signature places naming `Lessor` / `Lessee` / `Agent` pass R05 against the declared parties `owner_party` / `agent` / `acquiring_party`.
2. A test template with a blank that has no mapping gets a `fail` naming that blank; with a signature place naming nobody declared gets a `fail`; with a header "Lease – 50d Madeira Crescent – Arendse – 2026" gets a `warn`; with `parse_report.dropped` non-empty gets a `warn` listing it.
3. The report writes nothing (a test records the template row, flows and documents before and after).
4. Another agency's user gets 404 by direct URL; a user without `manage_templates` gets 403.
5. The command prints the same verdict and exits 1 on `not_ready`.

## 8. Tests (single files, `scripts/lane-test.sh`)

`tests/Feature/Docuperfect/Workshop/TemplateReadinessReportTest.php` — service checks against built fixtures (each check pass and fail), Mega-shaped fixture (copy of the 82 structure: tags, mappings with `parties`, header tokens), null / double-encoded columns, no-write proof. `tests/Feature/Docuperfect/Workshop/TemplateReadinessScreenTest.php` — route 200 for owner and own-agency manager, 404 other agency, 403 no permission, command exit codes. Paths named in the build report: all-clean, each-optional-column-null, malformed (double-encoded) column, unknown template id, archived template.

## 9. Files

`app/Services/Docuperfect/Readiness/TemplateReadinessReport.php`, `app/Http/Controllers/Docuperfect/TemplateReadinessController.php`, `app/Console/Commands/Docuperfect/TemplateReadinessCommand.php`, `resources/views/docuperfect/templates/readiness.blade.php`, link additions in `templates/index.blade.php`, `edit.blade.php`, `edit-web.blade.php`, route in `routes/web.php`, tests above.
