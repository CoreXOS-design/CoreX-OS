# Prod promotion audit — 2026-09-16

**Range audited:** `6545f0262` (previous Prod tip, what live runs now) → `57407d5a5` (new `origin/Prod` = `origin/main`).
**Size:** 601 files, 694 non-merge commits, 92 new migrations, +94,836 / −4,156 lines.
**Method:** mechanical checks (syntax on all 543 changed PHP files, conflict markers, route naming, listener/observer registration, schema snapshot, queue names, env keys, live and staging server state) plus seven independent code reviews, one per area. Every HIGH finding below was re-verified in the code by the orchestrator before inclusion. Read-only: nothing in the repo or on any server was changed.

Detailed reports in this folder:

| File | Area |
|---|---|
| `audit-migrations.md` | All 92 migrations, prod-data safety, ordering, snapshot |
| `audit-rental-applications.md` | AT-392 rental applications (Johan's lane) |
| `audit-core-matches-dr2.md` | Core Matches rebuild, Buyer Pipeline, DR2 multi-property, share links |
| `audit-commission-and-misc.md` | Commission share-percent fix, AT-398 listeners, smaller areas |
| `audit-esign-communications.md` | E-sign, AT-395 agency mailbox send, identity gates |
| `audit-qa2-lane-and-merge.md` | Andre's QA2 lane (AT-393/419/420), the 11 hand-resolved merge files |
| `audit-cross-cutting.md` | Routes, permissions, nav, wizard parity, events, deploy prerequisites |

---

## 1. State of play (verified on the servers)

| Where | Commit | Meaning |
|---|---|---|
| Live `/corex` on 62.238.31.82 | `6545f0262`, 0 pending migrations | **The promotion is NOT deployed.** Live still runs the old code. |
| Staging `/corex-staging` on 91.99.130.85 | `747ac18c3`, 0 pending migrations, deployed 20:21 tonight | Runs the exact promoted code (Prod = Staging + one CLAUDE.md line). All 92 migrations applied cleanly there. No application errors logged since. |
| `origin/Prod`, `origin/main` | `57407d5a5` | Both point at the audited tip. |

So this is a **pre-deploy audit**. Everything below can still be fixed before it reaches an agent.

## 2. Verdict

No blocker that would 500 the site on first request, provided the deploy sequence in §6 is followed. But **six confirmed functional or security defects ship in this code**, two of which touch money and client data, and the commission fix is only complete once a data-correction command is run on live. Recommendation: fix items 2.1–2.6 on Staging first (they are all small, contained changes), re-promote, then deploy with the §6 sequence. Deploying as-is is possible but leaves the six defects live.

### 2.1 Client portal shows a seller properties they were unlinked from — HIGH, new exposure
Contact–property links are now soft-deleted (migration `2026_09_16_100000`), but the client-app seller-insights endpoint reads the link table raw with no `deleted_at` filter (`app/Http/Controllers/Api/V1/ClientSellerInsightsController.php:61` and `:117`). An owner who is unlinked from a property keeps seeing that property's viewings, feedback, compliance and price in the client app. Same gap in `ContactController.php:717-721` and `DeedsCaptureController.php:105`. Fix: `whereNull('deleted_at')` on each read. CONFIRMED.

### 2.2 Commission fix needs the correction command, or affected deals cannot be marked Paid — HIGH, money
`CommissionPoolCalculator` (commit `acaf76b89`) stops applying "our share %" to an internal side, but `DealMoneyLineRebuilder::computeDealPools()` (`app/Services/DealMoneyLineRebuilder.php:48-54`) still computes an external payable of side × (1 − our share %) on a non-external side. Before the fix the halved pool and the phantom payable cancelled out; after code deploy the pool is right and the payable is still there, so the settlement checksum fails ("Cannot mark this deal as Paid") on every deal that has a non-100 share on an internal side (deal 169/1818 shape). `php artisan deals:correct-share-percent-defect` (dry-run, review with Johan, `--apply`) sets those sides to 100 and removes the payable. **This must run right after deploy, not later.** Also: both V1 capture forms still render "Our Share %" for internal sides, so the shape can be re-created by hand (`resources/views/dr2/create.blade.php:606-609`, `admin/deals/form.blade.php:243`). CONFIRMED.

### 2.3 DR2 "Add to deal" on an existing deal is dead — HIGH
Commit `3cb1266dd` ("relocate Financials") dropped `name="property_id" form="dr2mp_add_form_real"` from the picker `<select id="dr2mp_picker">` (`resources/views/dr2/create.blade.php:249`). The standalone add form at `:897` now posts only the token, price and commission; `Dr2\DealRegisterController::addProperty()` (`:1605`) requires `property_id`, so every add attempt fails validation. The test posts `property_id` directly, so it stays green. Fix: restore the binding, renaming the field to `add_property_id` to avoid the `old('property_id')` collision documented in `.ai/audits/2026-09-13-dr2-property-id-old-collision.md`. CONFIRMED.

### 2.4 Core Matches reassignment does nothing visible — HIGH
The board scopes own/branch/agent-filter on `created_by_user_id` (`ContactMatchController.php:225-235`); `reassignTo()` moves only `agent_id`; no creation path stamps `agent_id` (all four `ContactMatch::create` sites leave it null). After a branch manager reassigns buyer A→B, agent A still sees the buyer, agent B never does, and "Assigned to" is blank. Fix: stamp `agent_id = created_by_user_id` on create (plus a one-line backfill in a migration) and scope the board on `agent_id`. CONFIRMED.

### 2.5 Rental application document upload skips both applicant gates — HIGH, security
`RentalApplicationSigningController::uploadDocuments()` (`:993`) checks token expiry and the upload-window rule but never `returnGatePassed()` or `identityGateAwaiting()`, unlike show/autosave/submit/pdf/viewDocument. Anyone holding a forwarded or leaked link can attach files (10 × 15 MB per request, 60 requests per 10 min by default) to the applicant's contact record on the root volume. Fix: add the two gate checks that the sibling actions use. CONFIRMED.

### 2.6 P24 imports with status "Rented" never reach Imported Stock (AT-419) — HIGH
The P24 CSV importer writes status `Rented` (`P24ListingsCsvParser.php:232`), but `rented` is not in `Property::OFF_MARKET_STATUSES` (`app/Models/Property.php:57-61`), so rented imports stay on the Properties list and count as "Available" in the KPI tile. The spec (`at419-imported-stock.md` L28) lists "rented". Fix: add `rented` to the off-market set. CONFIRMED.

## 3. Deploy-order landmine (not a code defect, a sequencing rule)
`Contact::properties()` and `Property::contacts()` now filter on `contact_property.deleted_at`. Between `git pull` and `migrate --force`, every contact page, property page, deal owner gate and e-sign recipients step throws a QueryException. Run `migrate --force` **immediately** after the pull, before `php-fpm` is reloaded, and do it outside office hours. CONFIRMED.

## 4. Pre-existing serious issues found on the way (already live today, unchanged in this range)
These are not introduced by the promotion but are worth a ticket each:
- **E-sign ID gate bypass.** `Docuperfect\SigningController` enforces `session("signing_verified_{$token}")` on `show`, `capture`, `uploadWetInk` and friends but not on `completeWeb` (`:1726`), `complete` (`:2396`), `saveWebFields` (`:1453`), `saveFields` (`:1372`), `decline` (`:3670`), `chooseMethod` (`:1192`). Anyone with the link can complete a signer's turn without the ID/passport number. The file is in the e-sign pipeline gate, so the fix needs a `tests/Feature/Docuperfect/SigningView/` diff. CONFIRMED.
- **E-sign and rental mail is synchronous in the request** (`SignatureService::dispatchSigningMail()`, `RentalApplicationMailer`, `RentalApplicationNotifier`, all seven rental mailables lack `ShouldQueue`). Wrapped in try/catch so no 500, but multi-recipient packs through a slow provider risk FPM timeouts mid-loop, and the known 08:30–09:00 SMTP contention applies. CONFIRMED.
- `SignatureController::resendEmail()` has no `isSigningBlocked()` check: resend on an expired/cancelled document emails a dead link and re-stamps sent status.

## 5. Medium findings (fix soon, not deploy-blocking)
| # | Area | Finding | Where |
|---|---|---|---|
| M1 | Rentals | Identity gate compares against applicant-typed email/ID, not agency-held contact data | `RentalApplicationSigningController::recipientEmail()` |
| M2 | Rentals | One real hard delete: validity-window overrides deleted on every settings save, model has no SoftDeletes | `RentalApplicationSettingsController.php:859-861` |
| M3 | Rentals | Settings routes lack `agency.required`; owner with no active agency gets a silent no-op or a NOT-NULL 500 | routes/web.php settings/rental-applications group |
| M4 | Rentals | RO/CO authoriser without `rental_applications.view` grant sees the nav link but an empty queue + 403s | `guardRentalApplication()` |
| M5 | Core Matches | Single-property price edits desync `deal_properties.allocated_price`; the next add/remove overwrites the deal total with the stale allocation | `Deal::booted()`, `DealPropertyPricingService:50-63` |
| M6 | DR2 | Changing the primary property on a 2+-property deal via the edit form creates the pivot with NULL allocations and bypasses the same-owner gate; next recalculation collapses `total_commission` | `Deal.php:92-107`, `DealRegisterController::persistDeal()` |
| M7 | DR2 | `recalculateTotals()` uses `saveQuietly()`, so the DealV2 twin keeps the old commission and can write it back on its next loud save | `DealSyncService.php:217-220` |
| M8 | Core Matches | A resolvable public share token is minted on every GET of match-results; no cap, expiry or cleanup | `_match-action-bar.blade.php:57` |
| M9 | Core Matches | Unauthenticated share-link GET inserts an `agency_contact_settings` row; `recordView()`/`feedback()` skip the buyer-active check | `SharedMatchController::buildMatchGroups:416` |
| M10 | Core Matches | Share history + working-clock reset fire only from the match action bar, not the buyer-header share bar or the mobile share | `_buyer-share-bar.blade.php`, `MobileCoreMatchController:186` |
| M11 | Commission | Correction command `--apply` is global, includes soft-deleted and Paid/locked deals, does not regenerate ledger rows or refresh `finance_computed_values` | `CorrectCommissionInternalPoolShareDefect` |
| M12 | Permissions | Migration grants `buyer_pipeline.view` and `contact_rental_history.view` scope "all" to every role that holds `core_matches.view`, including agent/viewer roles whose config default is own/branch | `2026_09_10_100000_grant_buyer_pipeline_view…`, `2026_09_10_040000_…` |
| M13 | Wizard rule 10a | ~45 new agency settings (rental qualifying formula, RO/CO tiers, gates, rate limits, kanban limit, working window, price-drop threshold) are absent from the Setup Wizard; the price-drop threshold has no settings screen at all. Only 9 are recorded as deliberately excluded, all "pending Johan" | `config/agency-onboarding-copy.php` |
| M14 | AT-419 | Property opened from Imported Stock has no lens: Back returns to Properties (where it is not listed) and the wrong sidebar item highlights | `PropertyController::index() L76`, `properties/show.blade.php:48-51` |
| M15 | AT-419 | Imported Stock is inert until `properties:backfill-p24-imported --apply` runs; the backfill also moves the agency's OWN sold/let P24-origin listings off the Properties page permanently. Johan's call | spec `at419-imported-stock.md:97` |
| M16 | Migrations | 11 multi-statement DDL migrations have no `hasColumn` guards; a mid-way failure leaves the DB half-migrated and a re-run fails. Take a `mysqldump` right before migrate (prod has no automatic backups) | see `audit-migrations.md` M3 |
| M17 | Rentals / infra | `data_volume` disk root `/mnt/HC_Volume_103099143/corex-data-volume` does not exist on live; parent is `root:root 755`, FPM runs as `www-data`, so it cannot be auto-created. Effect: rental PDF cache never hits, every PDF open re-renders through Chromium (~9 s) and logs a warning | `config/filesystems.php:73-79` |
| M18 | Docs | Both `at419-imported-stock.md` and `branch-archive-reassignment.md` still read "Draft — awaiting Johan's sign-off"; `CHAT_STARTER.md` is 541 lines, lists nothing from this range under LIVE, and still states the old "QA1 only" rule | `.ai/` |

Low findings (≈30) are in the per-area reports.

## 6. Deploy checklist for live (62.238.31.82, `/corex`)

Verified on the box tonight: PHP 8.3.33 FPM+CLI with gd, imagick, intl, zip; `pdftoppm` and `gs` present; node v22 + `node_modules` present; `QUEUE_CONNECTION=database`, `MAIL_MAILER=smtp`, `APP_ENV=production`; worker groups running for default, bg_removal, matching, mail, webhooks, buyer-matching, p24import, p24images, transcription, thumbnails. No new queue names are introduced. No composer or npm dependency changes.

Before pulling:
1. Confirm `/corex/.env` has `APP_URL=https://corexos.co.za` (OutboundMailGuard vetoes all mail otherwise) and that `APP_KEY` is unchanged (encrypted mailbox passwords).
2. Add `DATA_VOLUME_ROOT` if the mount differs, then `mkdir -p /mnt/HC_Volume_103099143/corex-data-volume && chown www-data:www-data …` (M17).
3. `mysqldump corexos > /root/corexos-pre-promotion-$(date +%F).sql` (M16; prod has no auto backups).
4. Optional but recommended: fix §2.1–2.6 on Staging first and re-promote.

Deploy (outside office hours, one uninterrupted sequence):
5. `git pull` → **immediately** `php artisan migrate --force` (92 migrations, §3).
6. `php artisan deploy:sync-reference-data` (provisions the 9 new permission keys to roles; nothing gets them otherwise).
7. `npm run build` (corex.css changed; `public/build` is git-ignored and last built 10 Sep on the box).
8. `php artisan view:clear && route:clear && config:clear && cache:clear` → reload `php8.3-fpm` → `supervisorctl restart corex-worker-live: corex-worker-live-matching: corex-worker-live-mail: corex-worker-live-buyer-matching: corex-worker-live-webhooks:` (trailing colons).

Post-deploy data steps (each is Johan's explicit call; all default to dry-run):
9. `php artisan deals:correct-share-percent-defect` → review the affected-deal list with Johan → `--apply` → re-run dry-run expecting "No deals affected" → open deal 1818's settle screen: checksum green, external payable on the internal side R 0.00 (§2.2). Then refresh `finance_computed_values` for the affected periods (Tinker `RollupService::refreshPeriod`).
10. `php artisan properties:backfill-p24-imported` (dry-run) → Johan decides whether own sold/let P24-origin listings should leave the Properties page (M15) → `--apply`.
11. `php artisan core-matches:backfill-set-aside-lost-buyers --dry-run` → Johan's go (his 2026-09-15 ruling anticipated this) → apply.
12. `php artisan properties:sync-gallery-categories` (dry-run) → apply (repairs "0 photos" in the mobile app for pre-14-Sep web listings).
13. Role Manager: tick `core_matches.reassign` for BM/admin roles (no migration grants it; they get 403 on reassign until then).

Smoke test (Johan, 10 minutes): open a contact and a property page (§3); DR2 deal → Add to deal (§2.3, will fail until fixed); Core Matches → reassign a buyer, log in as the target agent (§2.4); Rental Applications → create, send, fill in a private window, upload a document, submit; Imported Stock page; Branch archive wizard; open a rental PDF twice and check `laravel.log` for "PDF cache" warnings (M17).

## 7. Business decisions Johan must make
1. Is a P24 listing with status "Rented" off-market stock? (Yes fixes §2.6 by derivation.)
2. Should the agency's own sold/let listings that came in via P24 move to Imported Stock when the backfill runs, or stay on Properties? (M15)
3. Should every role that sees Core Matches also see the Buyer Pipeline and rental history, at "all" scope? (M12)
4. Which of the ~45 new rental/core-match settings belong in the Setup Wizard, and which are deliberately expert-only? (M13)
5. Approve the commission correction list before `--apply`, including whether Paid/locked deals may be rewritten. (§2.2, M11)

## 8. Verified clean
- All 543 changed PHP files pass `php -l`; no conflict markers; no dependency changes.
- Every added route is named; every new permission key is used by a route/controller and gates a sidebar entry; every new page has a nav entry.
- All 8 new events, 7 listeners and 1 observer are registered explicitly in `AppServiceProvider`; no listener is queued (AT-261 shape respected).
- All 92 migrations are additive on pre-existing prod tables; no AgencyScope leak under CLI; same-timestamp pairs are order-safe; schema snapshot has all 92 names and 0 DEFINER clauses; Staging replayed the full sequence successfully.
- The 11 hand-resolved merge files (QA2 promotion) and the 3 from the QA1 promotion: both parents' code present, no duplicated blocks, balanced markup, all referenced route names exist.
- AT-393 restyles: no removed features except two "Back" links (commit `bdce97cc6`, sidebar still reaches both) and the task-board "At Risk" strip (Andre's explicit instruction).
- AT-420 branch archive: soft delete + restore, same-agency target validation, events registered, 19 tests.
- AT-395 mailbox credentials: encrypted cast, hidden, write-only, single audited reveal, never logged; Sent only after transport success; no silent fallback to the shared mailer.
- The ~135 e-sign/communications commits in the range are content duplicates of work already in the live tree (net diff on those services is empty); the net e-sign delta is small and gate-compliant.
- Neither the e-sign pipeline gate files nor the portal-sync files changed.
- No `dd`/`dump`/debug leftovers, no `env()` outside config in added code, no hardcoded hosts or agency ids outside migrations.
- Live worker topology serves every queue the new code dispatches to (`default`, `matching`, `p24images`, `p24import`).

---

## 9. Verified in Chrome on localhost — 2026-09-17 (fix branch `fix/prod-audit-2026-09-16`)

Local rehearsal of the whole promotion on the real dev dataset, then every fix walked in a real
Chrome (puppeteer-core, headful) as the users involved. Harness + screenshots in the session
scratchpad (`e2e/`).

| Step | Result |
|---|---|
| `php artisan migrate --force` on the dev DB (94 migrations, real data) | DONE 94, 0 pending, 18 s, no errors |
| `deploy:sync-reference-data` | provisioned; 2 pre-existing WARNINGs: `assistant` role_defaults for agencies 20 and 23 resolve to zero keys (config shape, not this promotion) |
| Dry runs of all four post-deploy commands | commission: 1 deal (#169, LIST A only); imported stock: 2 rented + 56 cancelled + 10 sold-3rd-party + 2 archived → Imported Stock; lost buyers: 83 contacts / 113 matches; gallery: 7,531 rows |
| `properties:backfill-p24-imported --apply --agency=1` (local) | Backfill complete; Imported Stock page lists 20 rows |
| 10 touched pages as admin (contacts ×3, properties, imported stock, core matches, rental apps, rental settings, DR2 index, admin deal form) | all 200, 0 server errors; contacts pages had 3 Alpine expression errors → root cause was `@json` emitting `"branch"` inside a double-quoted attribute → fixed in `6a6340317`, re-checked: 0 console messages |
| Imported Stock lens | property opened from Imported Stock: Back = "Back to Imported Stock", sidebar highlights Imported Stock; entered via Properties: "Back to Properties" |
| "Our Share %" on both V1 deal forms | hidden for an internal side; appears when External is ticked (admin form); hidden on DR2 create |
| DR2 "Add to deal" on deal 171 | picker bound as `add_property_id`; add of property 5874 succeeded → `deal_properties` 2 rows, `property_value` re-summed 560,000 + 1,000,000 = 1,560,000, commission unchanged 48,300 |
| Core Matches reassignment, match 635 (Willemien Trytsman) Shawn → Retha via the real POST | `agent_id` 26 → 24; Shawn (own scope, search) no longer sees the buyer; Retha does |
| Rental applicant gate, fresh browser holding only the link (application 1, status returned) | show renders "Verify it's you" (no form); upload → 403 "Please verify this link before changing documents."; remove → 403 |
| Rental validity windows save | "Document validity windows saved.", no 500 (soft-delete reconcile path) |
| Deal 169 / #1818 settlement screen | selling pool R 25,500.00 (was R 12,750), external payable on the internal side R 0.00 (was R 14,662.50), listing (external) payable R 29,325.00 — and this local row still holds `selling_our_share_percent = 50`, so the screen balances on code alone |

Not exercised in the browser (covered by tests only): e-sign identity gate on the six mutation
actions (`IdentityGateEnforcedOnMutationsTest`, 11 tests) and queued rental mail (needs a worker).
