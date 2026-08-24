# MDF + Addendum B — live deploy — 2026-08-24

Johan approved ("yes run mdf seeders on live"). Executed after cc3 released `/corex`.
Full diagnosis and Staging rehearsal are in the preceding session transcript; this is the
live-deploy record.

## What was on live before

Baseline established directly (not trusted from any earlier report): **68 rows** in
`docuperfect_templates`. Neither `Sales Mandatory Disclosure` nor `HFC Addendum B` present.
Live `main` confirmed at `origin/main` exactly, 0 divergence, before any write.

## Root cause (cc5) and the durable fix

`app/Console/Commands/Deploy/SyncReferenceData.php` — the command `CLAUDE.md` documents as
the carrier for seeder-owned GLOBAL reference rows on every deploy — never included any
document-template seeder. Not a migration miss; this exact deploy-gap, just never wired up
for `docuperfect_templates`. Confirmed by grepping every seeder that writes to that table
(11 found) against QA1's real, tested rows: only two have ever actually been used —
`SalesMandatoryDisclosureEsignSeeder` (QA1 id 71) and `HfcAddendumBEsignSeeder` (QA1 id 72).
Two more (`SalesMandatoryDisclosureSeeder`, `SellerMandatoryAddendumSeeder`) are dead,
superseded first attempts — proved on Staging that running the dead MDF one after the real
one silently overwrites `blade_view` back to an untested template on the same row id, no
error. Neither dead seeder was registered or run anywhere in this deploy.

**Registered** (commit `ecd1d8e1c`, cherry-picked from `qa1-property-status-prospecting` via
`bb6432539`/`8d190b924`/`3a12be4cd`): only the two verified seeders added to
`SyncReferenceData::$seeders`, with a comment on each documenting why the other two exist but
aren't registered. Every deploy from now on carries these two forward automatically — this
was the actual point of tonight's work, not the seeding itself.

## agency_id / is_global

Both seeders used a raw `DB::table()->insert()` with **no `agency_id` set anywhere** — `Template`
(the Eloquent model) deliberately has no `BelongsToAgency` auto-fill (Johan's own 2026-08-15
docblock: templates can be genuinely shared across every agency; `is_global` is the sanctioned,
explicit mechanism for that, not `agency_id IS NULL`). Confirmed directly with cc6 (mid-deploy
on the multi-tenancy fix at the time) that raw server-side writes were never in scope of that
fix — only user-facing creation paths (upload/import/CDS-Builder/`saveFields()`). Confirmed
myself, by reading `Template::scopeVisibleTo()` directly, that `is_global=true` bypasses the
`agency_id` check entirely at query time — `agency_id` is attribution, not scoping, for a
global row.

**`is_global=true` on these two rows is deliberate, not an oversight** — a PPRA-mandated form
every agency in the country must receive identically is exactly the legitimate case that flag
exists for. A customer's own paperwork is agency-scoped; a regulatory form that applies to
every agency is genuinely shared. Both seeders now carry a comment saying so, specifically so
the next person reading the code doesn't read `is_global=1` as a bug and "fix" it back to
agency-scoped. `agency_id` is stamped anyway (resolved by name lookup, never hard-coded) for
correct attribution.

## What actually landed on live

```
id=74  Sales Mandatory Disclosure   template_type=cds  blade_view=cds.template-123
       is_esign=1  is_global=1  agency_id=1  document_type_id=11  page_count=2
       created_at=2026-08-24 06:12:27

id=75  HFC Addendum B               template_type=cds  blade_view=cds.template-120
       is_esign=1  is_global=1  agency_id=1  document_type_id=13  page_count=1
       created_at=2026-08-24 06:12:33
```

Total: 68 → 70, exactly +2. Confirmed zero other rows in the table touched (`updated_at`
check across the whole table, 10-minute window, excluding the two new ids: 0).

## Verification — Johan's actual account, not just row existence

Rendered the real pages in-process as `johan@hfcoastal.co.za` (user id 22, live, agency 1):

- `access_docuperfect` permission: held.
- `GET /docuperfect/templates` → 200, page HTML contains both template names.
- `Template::active()->visibleTo($user)` (the exact scope the page uses) → returns both,
  count 2.
- `Template::isVisibleToAgency(1)` (the direct-by-id guard `TemplateController::webPreview()`
  uses) → true for both.
- `GET /docuperfect/create` (the actual "start a new document" screen) → 200, contains both
  names.

Not verified: actually clicking through to generate a real signature request end-to-end —
that would create real `docuperfect_documents`/`signature_requests` rows on live as a side
effect, which wasn't asked for and isn't needed to prove reachability. Everything up to and
including the screen Johan would click from is confirmed rendering and listing both documents
correctly, for his real account.

## Rollback (unused, kept ready)

```sql
UPDATE docuperfect_templates SET deleted_at = NOW()
WHERE id IN (74, 75) AND deleted_at IS NULL;
```
