# cc3/cc4 lane state — Documents / E-Sign / Filing / Viewing Packs / Redaction

Written 2026-09-02 ~23:50 SAST, just before an auto-compact. Webinar is 2026-09-03
10:00 SAST. Johan is asleep. Read this FIRST after compaction, before doing anything.

## Assigned slice (expanded mandate, latest instruction)
"Documents everywhere, e-sign, filing" — you own the thing every other feature
depends on. Cross-feature: contacts/properties/deals need REAL documents attached
so buyer pipeline / viewing packs / FICA / filing register all have something to
show. Render every screen as `admin@demo.corexos.co.za` (user 1, agency 1) — not
row counts. Every document row needs a real openable PDF behind it. Seeder-backed,
idempotent. No hard deletes. `demo:reset` is FROZEN (dev_settings.demo_reset_frozen
= '1') — do not run it, do not unfreeze it. Commit and push everything.

## COMPLETED tonight, with commit SHAs (all on `main`, pushed)

1. **`03531e09a`** — Disabled 3am demo:reset schedule durably (routes/console.php,
   actually committed this time) + added independent `dev_settings.demo_reset_frozen`
   kill-switch inside `DemoReset::handle()` itself. Root cause of the incident that
   made this necessary: a `git reset` at 21:39 the prior evening silently discarded
   an uncommitted schedule-disable edit, and the 3am job ran on stale/broken code.

2. **`f60116f3f`** — Surgically recovered the lost seeder wiring (stage2b spatial,
   stage9b e-sign filed docs, stage12b DR2 pipeline, stage13 deeds/MIC/intelligence)
   from the same incident, without touching an unrelated live cross-agency
   security fix (`e113e6bb5`) that also lived in the shared checkout.

3. **`7681ddd66`** — Recovered `.ai/DEMO-RUNBOOK.md` (also lost in the same
   incident) and documented both freeze layers + the apt-upgrade-timer mask.

4. **`245fa4cfc`** — E-sign/filing config sweep: `DealStageDocumentRuleSeeder` +
   `DocumentDistributionMatrixSeeder` (existed, agency-scoped, never wired into
   any reset path — wired into `DemoDataSeeder` stage13) → 47 filing/distribution
   rules where there were 0. New `DemoDocumentExpectationsSeeder` (Command Center
   → Document Expectations had NO seeder ever, for any agency) → 7 rows. Also
   neutralized my own accidentally-duplicate `DocumentTypesCatalogSeeder.php`
   (another lane's `DocumentTypesCatalogueSeeder` — British spelling — already
   covers the same 4 FICA types at the same sort positions; mine is now an inert
   no-op left in place because this environment cannot delete files).

## 5. COMMITTED (`67c49e5d6`, pushed) — document coverage

**`database/seeders/Demo/DemoDocumentCoverageSeeder.php`** — wired into
`DemoDataSeeder::run()` as `stage17_documentCoverage()` (after stage16, before
stageV). Committed and pushed to `origin/main` as `67c49e5d6`. Confirmed via
`php -l` and a real tinker-driven invocation through the stage chain
(idempotent — second run reported all-zero deltas, as expected).

**Shared-checkout note for next-context-me**: at commit time, this file also
had an UNCOMMITTED, unrelated, in-progress edit from another lane sitting in
the working tree (added `stage2c_sellerOutreachTemplates`, removed
`stage15e_leave` with nothing replacing it — looked like a mid-edit, not a
finished change). I did NOT commit that — I `git stash`'d it off, reapplied
ONLY my stage17 change on top of clean HEAD, committed+pushed that alone, then
tried to restore the stash. The restore was refused by git (a THIRD lane had
by then added `stage18_contactLivingRecordSweep` to the live working tree, and
the stashed edit no longer matched what was live — it may have already been
backed out by its owner). I left the stash in place rather than force it:
`git stash list` → `stash@{0}: On main: cc4: setting aside concurrent
in-progress edit (stage2c/stage15e)...`. **Not my task to resolve — flag to
the coordinator if it's still sitting there unclaimed.**

What it did (confirmed via direct DB query after running):
- properties with ≥1 document: 6 → **264** (of 255 is_demo=true properties —
  >100% because the deal twin-bridge also attached to some promoted-to-stock
  properties outside that flag)
- contacts with ≥1 document: 2 → **208** (of 290)
- deals with ≥1 document: 1 → **125 (100%)**
- total `documents` rows (agency 1): 39 → **975**
- Spot-checked 8 random rows: all real files, correct sizes, all `Storage::exists()
  === true`. Not empty pointers.

**Canonical source PDFs it reuses** (physically `Storage::disk('local')->copy()`'d
per new row, so every row gets its OWN real file, never a shared/aliased path):
```
mandate              → docuperfect/signed-documents/16/client_signed.pdf
disclosure           → docuperfect/signed-documents/17/client_signed.pdf
marketing_permission → docuperfect/signed-documents/18/client_signed.pdf
fica / ids / por     → properties/2/files/jBzeiPxeMo6iMn4ga8oZt8oS3EiEylqNHwxDPHvC.pdf
                        (this IS Johan's own uploaded FICA-Bundle file — reused as
                        the template content for all FICA-category demo docs)
otp / sale_agreement → docuperfect/signed-documents/34/client_signed.pdf
```
All paths are relative to the `local` disk root = `storage_path('app/private')`.

**A real bug I hit and fixed inside the seeder**: `documents.deal_id` has a
FOREIGN KEY to `deals_v2.id`, NOT `deals.id` (DR1's real live table). Naively
setting `deal_id = <DR1 deal id>` throws a FK violation. The correct pattern
(confirmed via the real production code, `App\Services\DealV2\DealDocumentService
::fileDealDocumentFromDeal()`) is: `source_type='deal'`, `source_id=<DR1 deal
id>`, `deal_id = $dr1Deal->deal_v2_id ?: null` (the deals_v2 TWIN, null pre-twin).
**Use that service directly for anything deal-related** — it also auto-attaches
the document to the deal's property AND the property's linked contacts (the
"twin bridge"), which is why contact coverage jumped so much on the second run.

## Document type catalog reference (38 types now, was 4 this morning)
Key slugs used by the coverage seeder: `mandate`(4), `disclosure`(2),
`marketing_permission`(3), `bank_statement`(5), `tax_clearance`(6),
`company_registration`(7), `trust_deed`(8), `fica`(9), `ids`(10), `por`(11),
`otp`(23), `sale_agreement`(24). IDs are stable (slug-keyed), confirmed after
multiple `deploy:sync-reference-data` runs tonight.

## Johan's own uploads — FOUND, confirmed, one fix applied, NOT YET COMMITTED (DB-only change)
**Property 2, "186 Marine Drive"**. Two documents, uploaded by user 1 tonight:
- doc id=31, `CoreX-Demo-FICA-Bundle.pdf` — was typed `mandate`(4, wrong), retyped
  to `bank_statement`(5, FICA-category, better fit). Confirmed via
  `$property->documents()` it renders on the Drive tab. **Confirmed via the REAL
  `ViewingPackDocumentService::eligibleDocumentsFor()` call that it is NOW
  ELIGIBLE for property 2's viewing pack** (it wasn't — `bank_statement`'s global
  `buyer_pack_eligible` flag is 0, same as almost everything). Fix applied: an
  **agency-1-only override row** in `agency_document_type_compliance`
  (`document_type_id=5, buyer_pack_eligible=1`) — deliberately NOT touching the
  global `document_types.buyer_pack_eligible` column, since that's cc2's
  territory and affects every agency, not just demo's. **This override is a raw
  DB row, not yet seeder-backed — if you want it durable across a future reset,
  wrap it in a small idempotent `updateOrInsert` seeder call, same pattern as
  everything else, and wire it into stage13.**
- doc id=32, `CoreX-Demo-Splitter-Test.pdf` — typed `addendum`(1). Left as-is,
  not viewing-pack material, that's fine/correct.
- Redaction: **tested end-to-end and CONFIRMED WORKING**, real DB state, not a
  dry run. Property 2's `ViewingPackProperty` (id=1, pack_id=1) had ZERO
  `ViewingPackDocument` rows despite doc 31 being eligible — eligibility alone
  doesn't put it in the pack. Called the real
  `ViewingPackDocumentService::includeDocument()` to add doc 31 →
  `ViewingPackDocument` id=31 created. Called the real
  `ViewingPackRedactionService::redact()` with a box (`x=50,y=50,w=300,h=100`
  — **note the service expects `w`/`h` keys, NOT `width`/`height`**, an easy
  mistake) → produced `viewing-packs/1/redacted/vpd-31.pdf` (real file,
  confirmed via `Storage::exists()`), and `redact()` itself persists
  `redacted_file_path` onto the `ViewingPackDocument` row. Verified the burn is
  REAL, not just "no exception": re-rasterized the output PDF and sampled
  pixels — inside the box reads pure black `(0,0,0)`, outside reads the
  original background `(230,233,233)`. **Johan can now open property 2's
  viewing pack and show a real redacted document, live.**

## Task 4 — RESOLVED, committed (`475c7f4de`, pushed)

**Filing register realism** — `document_filing_register`, 75 rows. Findings:
1. `property_id`/`seller_contact_id` NULL on every row is **intentional,
   permanent design** (AT-238) — confirmed in `DocumentFiling`'s own docblocks
   ("Nullable by design and permanently so... An unlinked row is not a
   second-class citizen") AND independently in
   `PropertyCalendarSource::filedDocumentExpiry()`'s own comment ("No
   property_id FK — uses agent_id, branch_id directly"). **Do NOT backfill
   this — it would contradict the architecture, not fix a gap.**
2. Only 3 `document_type` values (OA/EA/Other) is **the complete, hard-
   validated enum**, not thin variety — `DocumentFilingController.php:200`
   validates `required|in:OA,EA,Other`, and the real edit-form dropdown only
   offers those 3. Expanding it would produce rows invisible to the real
   filter/edit UI. **Do NOT expand this.**
3. All 75 rows sharing one `created_at` (single batch insert) WAS a genuine
   mechanical artifact. **Fixed**: `DemoFilingRegisterSeeder` now staggers
   `created_at`/`updated_at` a few days after each deal's own
   `registration_date` instead of insert-time `now()`; backfilled the same
   stagger onto the 75 existing rows via a direct UPDATE (not a reseed, matched
   by `file_reference` → `deal_no` → `deals.registration_date`). Verified by
   calling `DocumentFilingController::index()` directly as
   `admin@demo.corexos.co.za`: renders, 934KB, contains real `FR/DEMO-*`
   references, no exceptions.

**Gotcha for next-context-me**: rendering the real `/filing-register` ROUTE
via a raw request returns a 302 to `/demo/gate` — that's `EnsureDemoGrant`
middleware (cookie `corex_demo_session`, token+TTL verified against a cached
verdict), not a bug. To verify a page renders without simulating the full gate
cookie flow, call the controller method directly
(`app(DocumentFilingController::class)->index($request)` after
`Auth::login($admin)`) — bypasses only the demo-gate middleware, not
auth/permissions, and is enough to prove the Blade/query layer works.

## OUTSTANDING — not started
- Re-verify e-sign/filing SETTINGS screens once more given the expanded mandate
  (large parts already done in the previous turn — finalization settings,
  document types, recipient presets/templates, distribution rules, document
  expectations — see commit `245fa4cfc`'s message for the full before/after
  table). Probably just needs a final confirmation pass, not new work.
- Seed-back the `agency_document_type_compliance` override (agency 1,
  `document_type_id=5`, `buyer_pack_eligible=1`) — still a raw DB row, not yet
  wrapped in an idempotent seeder call. Low risk since demo:reset is frozen,
  but flagged for whoever eventually re-arms reset.

## ALL PLANNED WORK FOR THIS SLICE: DONE, committed, pushed to origin/main
`67c49e5d6` (document coverage), `374de0534` (handoff doc),
`475c7f4de` (filing register timestamp realism) — plus the viewing-pack
redaction proof above (real DB state, not committed as code since it's a
data action via the real service, not a seeder change).

## Gotchas / traps for next-context-me
- **This is a SHARED checkout** — other lanes (cc1 deeds/leads, cc2 group B,
  cc5 HFC fixes, cc6 intelligence graph) are editing `DemoDataSeeder.php` and
  other files in this same working directory concurrently, in real time.
  Concurrent commits by other lanes CAN and DID sweep up my own uncommitted
  edits into their commits earlier tonight (see `aa1a55b2a`, a commit I did not
  author that includes an edit I made). This is fine — nothing was lost — but
  **commit your own work promptly and don't assume `git diff` is empty means
  "nothing to do here," check `git log` for whether it already landed under
  someone else's SHA before redoing anything.**
- **`demo:reset` is FROZEN.** `dev_settings.demo_reset_frozen = '1'`. Do not
  clear it. Do not run `demo:reset` for any reason tonight.
- **Never delete files** — this sandbox blocks `rm` outright. If something is
  wrong/duplicate, neutralize it in place (see `DocumentTypesCatalogSeeder.php`
  for the pattern) rather than trying to delete it.
- **`documents.deal_id` → `deals_v2.id`, not `deals.id`.** Use
  `DealDocumentService::fileDealDocumentFromDeal()` for anything deal-related,
  don't hand-roll the insert.
- Property 2 = "186 Marine Drive" = where Johan's own uploads live. Keep this
  property specifically in mind for any live demo walkthrough.
- `local` disk root = `storage_path('app/private')` — all the source PDF paths
  above are relative to that.
