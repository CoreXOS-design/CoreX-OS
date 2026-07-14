# ⟳ REFRESH — 2026-07-14 EVENING (live executor: this lane; Johan pushes ~2h)

> **THE §0–9 PROMOTION BELOW IS DONE AND LIVE.** State verified against the hosts, not the doc.

## Current reality (verified from the checkouts, not assumed)
| | ref | note |
|---|---|---|
| **LIVE `/corex`** (main, **php8.3**) | **`8ad78657`** | HEAD == `origin/main`, **0 commits / 0 migrations pending**. Fully migrated. |
| **`origin/Staging`** | `8ad443f9` | **`main..Staging` = 0** — nothing left to promote to main; main is a superset. |
| Already live | the **seven promotions** (AT-246 region revert→rebuild + comms attribution, AT-241/254/261/245/228) **+ AT-263 intro template + Guided-Tours mobile card + QA2 merge + a p24 refresh-cost fix** | all merged to main (`38e9a69c` Merge Staging) and deployed. |

**So tonight's push is NOT the old 62-commit haul — that already landed.** Tonight = the **new delta** that lands on `origin/main` in the next ~2h (m3's **FICA** deploy — just unblocked on QA1 — and any other QA1/QA2 items promoted to Staging→main tonight). The executor computes the real set at push time: `git -C /corex rev-list --count HEAD..origin/main` and `git -C /corex diff --name-only --diff-filter=A HEAD origin/main -- database/migrations/`.

## The three flagged surprises — VERIFIED on live (read-only), 2026-07-14 eve
1. **Payroll SARS tables (AT-237):** ✅ **SEEDED on live** — `payroll_tax_tables`=7, `payroll_tax_rebates`=1 → PAYE computes (not R0). Both seeders are in `deploy:sync-reference-data`; **the delta deploy MUST still run `sync-reference-data`** (idempotent) so a fresh/global row can't be missed.
2. **AT-263 prospecting intro template:** ✅ **PRESENT on live** — 6 intro-named of 14 seller-outreach templates on agency 1; the one-shot `HfcConsentTemplatesSeeder` ran. ⚠ **It is NOT in `sync-reference-data`** — a one-shot. If tonight's delta touches it or targets a new agency, run `php artisan db:seed --class=HfcConsentTemplatesSeeder --force` explicitly (idempotent updateOrCreate on agency 1).
3. **Region model (AT-246 rebuild):** ✅ code live; **DATA empty by design** — `towns`=0, `region_aliases`=0. The region column on `/settings/p24-suburbs` shows **no regions until the GATED** `php artisan prospecting:assign-municipalities` run (its own separate Johan-word step — §4 below). **Not a defect; do not expect regions on live tonight** unless Johan fires that data run.

## Tonight's delta runbook (executor — on Johan's word)
```
# 0. SAFETY (tag current live + DB backup BEFORE anything)
git -C /corex tag live-pre-2026-07-14-eve 8ad78657 && git -C /corex push origin live-pre-2026-07-14-eve
mysqldump <live-db> > /root/backups/live-pre-2026-07-14-eve.sql
# 1. CODE  (main must contain tonight's delta first — confirm origin/main advanced)
git -C /corex fetch origin && git -C /corex rev-list --count HEAD..origin/main   # the delta size
git -C /corex diff --name-only --diff-filter=A HEAD origin/main -- database/migrations/   # the delta migrations — eyeball ⚠ data/constraint ones
git -C /corex pull --ff-only
# 2. MIGRATE
sudo -u www-data php /corex/artisan migrate --pretend    # confirm ONLY the delta migrations
sudo -u www-data php /corex/artisan migrate --force
# 3. REFERENCE DATA + PERMISSIONS (idempotent)
sudo -u www-data php /corex/artisan deploy:sync-reference-data
sudo -u www-data php /corex/artisan corex:sync-permissions --merge-defaults
#    + if the delta includes AT-263 template work: db:seed --class=HfcConsentTemplatesSeeder --force
# 4. CACHES + SERVE  (LIVE pool is php8.3 — NOT 8.2)
sudo -u www-data php /corex/artisan config:clear && route:clear && view:clear
systemctl reload php8.3-fpm
supervisorctl restart corex-worker-live: corex-worker-live-mail: corex-worker-live-matching:
sudo -u www-data php /corex/artisan queue:restart
# 5. SMOKE (real admin pages): MIC loads · a DR2 deal + Documents card · AT-264 pack send · payroll a run (tax non-zero) · AT-263 template in outreach
# 6. ⚑ REGION DATA RUN — SEPARATE Johan "go" only (§4): prospecting:assign-municipalities [--dry-run first]
```
**Rollback:** `git -C /corex reset --hard live-pre-2026-07-14-eve` + clears + reload 8.3 + restart workers; DB → restore the mysqldump (data/constraint migrations don't cleanly reverse). Env-parity (AT-169): live php8.3 already carries the extensions this code path used on staging (no new ext in the delta as of now — re-check if FICA adds one).

---
*Below: the ORIGINAL §0–9 manifest for the 62-commit Staging→main promotion — **SUPERSEDED, now LIVE**. Kept for the migration/rollback detail of what already shipped.*

---

# LIVE PROMOTION MANIFEST — 2026-07-14

> **Deploy lead:** m2. **Trigger:** Johan's explicit word AFTER his morning walk (Wednesday agent training runs on LIVE today).
> **NOTHING in this manifest executes until Johan fires it.** This is the loaded gun; he pulls the trigger.

---

## 0. Refs & scope

| | ref | note |
|---|---|---|
| **LIVE now** (`/corex`, `main`, php8.3) | `e26ce8c1` | deployed HEAD == `origin/main`, 0 behind |
| **Promote to** | `origin/Staging` = **`5c02ff53`** | the target |
| **Commits going over** | **62** (`main..origin/Staging`) | full haul below |
| **Rollback point** | `e26ce8c1` | current live HEAD — tag it before deploy (§6) |
| Frontend build | **none needed** | zero changes under resources/js, resources/css, vite, package.json |
| Env-parity (AT-169) | **clean** | live php8.3 `memory_limit=1024M` already; region PIP is pure PHP (no new extension); MDB layer fetched once from github (live reaches github on deploys) |

---

## 1. FEATURE INVENTORY (what ships) + risk per item

Grouped by feature. Risk = L/M/H.

1. **AT-227 — Document-type distribution matrix** (party-first, editable). Spine for AT-228 + m6. `deal_stage_document_rules` (pipeline_step_id nullable). **Risk L** — additive; matrix seeded via `DocumentDistributionMatrixSeeder`.
2. **Proforma Invoices — accounting-pillar foundation.** New tables (proforma_invoices/_lines/_audit, agency_proforma_settings), `agencies.vat_registered`, proforma document-type, permissions `proforma.generate` / `proforma.manage`, banking under Company Settings, Pastel-style PDF, once-active-per-deal. **Risk M** — 6 migrations + a new permission set + a global document-type reference row (carried by sync-reference-data? NO — see §3 note); gated Granted-onward.
3. **AT-237 — Payroll hardening.** Missing SARS tax table = HARD STOP (not silent R0 PAYE); proration fields; soft-delete-safe uniques (payroll_runs, earning/deduction types). **Risk M** — payroll math; **DEPENDS on the SARS tax tables being seeded on live** (PayrollTaxTableSeeder / PayrollTaxRebateSeeder via sync-reference-data — §3). Without them PAYE is R0.
4. **AT-228 — DR2 deal-pack distribution.** Per-party send buttons (attorney/bond/seller/buyer), bond-originator party, compose+send, secure-link/attachment × email/WhatsApp, auto-split, 3-pillar comms. 4 migrations. Permission `deals_v2.distribute_documents`. **Risk M** — new send pathway; mail on live is real SMTP (a real send goes to a real recipient — behaviour is agent-initiated, not automatic).
5. **AT-242 + AT-239 — MIC buyer-led prospecting + REGION ENGINE.** Buyer selector (scoped: My buyers/branch/company) + region filter. **Region engine = MDB municipality (national, 212) + agency alias, point-in-polygon assignment.** `region_aliases` table. **Risk M for code; the region DATA run is a SEPARATE gated step — see §4.**
6. **AT-243 / AT-244 — DR2 walk fixes.** Derive real purchaser (`deal_contacts` table + backfill), lock pipeline on declined deals, buyer-list Blade-comment fix, property-page 500 (inline `@php()` nested-parens) fix. **Risk L–M** — includes a data backfill migration (`backfill_deal_contacts_from_buyer_names`).
7. **AT-238 — Filing register links real records.** Property-first search (fixed super-admin empty-search), one-seller-fact, property+seller links, corroborate-or-queue matcher. **Risk M** — filing/matcher; migrations add columns + a one-seller-fact constraint.
8. **AT-235 — Notification gateway.** Any notification via the gateway; proforma-created + portal-lead notifications; retire dead contact toggles. `NotificationEventTypeSeeder` (global). **Risk L**.
9. **AT-257 — Non-destructive IMAP fetch** (BODY.PEEK — archiving never marks mail read). **Risk L** — mailbox polling only.
10. **AT-253 — Agency-context guards** (super-admin agency_id NULL class). **Risk L** — defensive.
11. **AT-254 — Splitter OTP consolidation** (dual OTP slug → `otp`; offer_to_purchase retired; migration `consolidate_otp_document_type`). **Risk M** — a data migration renaming/merging a document-type slug; verify no orphaned refs.
12. **AT-251 — Non-production env banner** (QA/STAGING). **Risk L, ZERO on live** — live sets no `APP_ENV_LABEL`, renders nothing. **Do NOT set APP_ENV_LABEL on live.**

13. **AT-245 — Walk-bug fixes (code-only, NO new migrations).** (a) DR2 twin minted on deal capture + defensively before a distribution send (structural hole: post-backfill captures were twin-less / invisible to distro); (b) suburb-mapping duplicate now a friendly "already mapped to <town>" (was an unhandled 500). Constraint UNCHANGED (one-suburb-name-per-agency is correct for string matching). **Risk L.**

---

## 2. MIGRATIONS — run order (23 new, `migrate --force`)

Laravel runs them in filename order. Listed in that order; ⚠ = data/backfill or constraint (not a pure additive column).

```
2026_07_13_100000_at235_retire_dead_contact_notification_toggles
2026_07_13_200000_at235_register_proforma_created_notification
2026_07_14_090000_at235_register_portal_lead_notification
2026_07_25_120001_create_agency_proforma_settings_table
2026_07_25_120002_create_proforma_invoices_table
2026_07_25_120003_create_proforma_invoice_lines_table
2026_07_25_120004_create_proforma_invoice_audit_table
2026_07_25_120005_add_vat_registered_to_agencies_table
2026_07_25_120006_register_proforma_invoice_document_type          ⚠ inserts a global document_type row
2026_07_26_120001_add_bond_originator_link_to_deals
2026_07_26_120002_add_delivery_defaults_to_service_provider_contacts
2026_07_26_120003_add_channel_and_parts_to_deal_document_distributions
2026_07_26_120004_add_distribution_size_limit_to_agency_deal_sync_settings
2026_07_27_000001_add_payroll_proration_fields
2026_07_27_100001_create_deal_contacts_table
2026_07_27_100002_backfill_deal_contacts_from_buyer_names          ⚠ DATA backfill (reads live deals)
2026_07_28_100001_add_property_and_seller_links_to_filing_register  (shares timestamp w/ region_aliases; runs first, 'a'<'c')
2026_07_28_100001_create_region_aliases_table
2026_07_28_100002_null_default_p24_suburbs_region                  ⚠ ALTERs p24_suburbs.region default 'kzn-south-coast' → NULL (existing rows unchanged by the migration; the reconcile command corrects them — §4)
2026_07_28_100003_one_seller_fact_on_filing_register              ⚠ constraint — ensure no dup seller facts pre-exist on live (AT-238 dedupes first)
2026_07_29_000001_payroll_soft_delete_safe_uniques                ⚠ unique reshuffle on payroll_runs
2026_07_30_000001_payroll_type_soft_delete_safe_uniques           ⚠ unique reshuffle on payroll earning/deduction types
2026_07_31_000001_consolidate_otp_document_type                   ⚠ DATA — merges OTP document-type slugs
```

**Pre-flight:** run `php artisan migrate --pretend` on live first to confirm exactly these 23 are pending and nothing else. The three ⚠ constraint migrations (one_seller_fact, two payroll uniques) can FAIL if live data violates the new uniqueness — the pretend + a DB backup (§6) are the safety net.

---

## 3. CONFIG / SEED / PERMISSIONS (after migrate, before serving)

Order matters. All idempotent.

1. **`php artisan deploy:sync-reference-data`** — GLOBAL reference rows seeders own (they do NOT run on a git-pull deploy). Now carries (relevant new ones): `NotificationEventTypeSeeder` (AT-235 — else notification settings page empty), `PayrollTaxTableSeeder` + `PayrollTaxRebateSeeder` (**AT-237 — WITHOUT THESE PAYE IS SILENTLY R0**). ⚠ **Known caveat (memory):** `deploy:sync-reference-data` has produced duplicate `role_permissions` in the past — watch its output; it is otherwise idempotent.
   - **Proforma document-type + distribution-matrix defaults:** the proforma doc-type is inserted by its migration (`..._register_proforma_invoice_document_type`); the distribution matrix is `DocumentDistributionMatrixSeeder` — **confirm it is registered in sync-reference-data OR run `db:seed --class=DocumentDistributionMatrixSeeder --force` explicitly** (agency-scoped defaults; verify it does not double-insert).
2. **`php artisan corex:sync-permissions --merge-defaults`** — ADDITIVE only (never prunes). Carries the new keys: `proforma.generate`, `proforma.manage`, and the DR2 distribution keys (`deals_v2.distribute_documents`, `deals_v2.manage_distribution_rules`). Grant to admin/branch_manager per config.
3. Caches: `php artisan config:clear && route:clear && view:clear` (then optionally `config:cache route:cache` per live's convention).
4. **Reload php8.3-fpm** (live pool — NOT 8.2): `systemctl reload php8.3-fpm`.
5. **Restart live workers** (supervisor): `supervisorctl restart corex-worker-live: corex-worker-live-mail: corex-worker-live-matching:` and `php artisan queue:restart`.

---

## 4. ⚑ REGION ENGINE LIVE RUN — ITS OWN JOHAN-WORD LINE ITEM ⚑

> **This is a DATA operation on live, separate from the code deploy. It does NOT run automatically. It requires Johan's explicit "go" as its own step — he may deploy the code and hold this until he's watched it on staging one more time.**

After the code + migrations are live, and ONLY on Johan's word:

```
# (a) National municipal region set + suburb assignment (downloads the MDB boundary
#     layer once to storage/app/mdb/, then offline). Seeds 212 SA municipalities into
#     region_aliases + PIP-assigns every geocoded prospecting suburb → its municipality.
php artisan prospecting:assign-municipalities            # add --dry-run first to preview

# (b) Correct the legacy p24_suburbs.region blanket → municipality where derivable, NULL else.
php artisan prospecting:reconcile-p24-suburb-regions     # add --dry-run first
```

- **Do NOT run** `prospecting:build-regions-from-library` — superseded by (a); it would recreate the old curated 2-coast towns.
- **Pre-req for the MIC buyer selector to show buyers:** the `prospecting_buyer_matches` cache must be warm on live (it is live's own cache; if the dropdown is empty, `php artisan prospecting:recompute-matches`).
- **Verify after:** MIC → By region shows the agency's municipalities (Ray Nkonyeni shows as "Hibiscus Coast", eThekwini, KwaDukuza, Umdoni, Umzumbe for HFC); filtering eThekwini returns Umhlanga ads. Regions screen (Settings → Prospecting → Regions) lists all 212 municipalities.
- **Risk:** read-then-write on live prospecting data; fully reversible (region_aliases + towns/town_suburbs are rebuildable; p24_suburbs.region reconcile is idempotent). **No live customer-facing data is destroyed** — towns/town_suburbs are soft-deleted+rebuilt, p24_suburbs.region only.

---

## 5. RISK SUMMARY (highest first)

- **H-ish:** the 3 ⚠ constraint migrations (one_seller_fact, 2× payroll uniques) — could fail on pre-existing live dup data. Mitigation: `migrate --pretend` + DB backup + the feature commits dedupe before adding the constraint.
- **M:** PAYE R0 if tax-table seeders don't land (AT-237) — sync-reference-data covers it; VERIFY tax tables non-empty on live post-deploy.
- **M:** proforma document-type / distribution-matrix defaults must exist or those screens are empty — confirm the seeder/migration ran.
- **M:** AT-254 OTP document-type consolidation is a data merge — verify no orphaned document-type refs after.
- **L:** everything else is additive/defensive.

---

## 6. ROLLBACK PLAN

1. **Before anything: tag + DB backup.**
   - `git -C /corex tag live-pre-2026-07-14 e26ce8c1 && git -C /corex push origin live-pre-2026-07-14`
   - `mysqldump <live-db> > /root/backups/live-pre-2026-07-14.sql` (full, before migrate).
2. **Code rollback:** `git -C /corex reset --hard live-pre-2026-07-14` (or checkout the tag), clear caches, reload php8.3-fpm, restart workers.
3. **DB rollback:** migrations are the hazard. Prefer **restore from the mysqldump** over `migrate:rollback` — several migrations are data/constraint ops that do not cleanly reverse (backfill_deal_contacts, one_seller_fact, otp consolidation, payroll uniques). The dump is the true rollback.
4. Region engine is independently reversible (re-run assign or restore towns/region_aliases from the dump).

---

## 7. DEPLOY RUNBOOK (on Johan's word — execute top to bottom)

```
# 0. SAFETY
git -C /corex tag live-pre-2026-07-14 e26ce8c1 && git -C /corex push origin live-pre-2026-07-14
mysqldump <live-db> > /root/backups/live-pre-2026-07-14.sql

# 1. CODE
git -C /corex fetch origin
git -C /corex merge --ff-only origin/main        # (m3 promotes Staging→main first; then main→live is ff)
#   NOTE: confirm origin/main == origin/Staging (4a76738f) AFTER m3's Staging→main merge, THEN:
git -C /corex pull   # fast-forward /corex to the promoted main

# 2. MIGRATE (pre-flight then real)
sudo -u www-data php /corex/artisan migrate --pretend    # confirm the 23, nothing else
sudo -u www-data php /corex/artisan migrate --force

# 3. REFERENCE DATA + PERMISSIONS
sudo -u www-data php /corex/artisan deploy:sync-reference-data
sudo -u www-data php /corex/artisan db:seed --class=DocumentDistributionMatrixSeeder --force   # if not in sync-reference-data
sudo -u www-data php /corex/artisan corex:sync-permissions --merge-defaults

# 4. CACHES + SERVE
sudo -u www-data php /corex/artisan config:clear && route:clear && view:clear
systemctl reload php8.3-fpm
supervisorctl restart corex-worker-live: corex-worker-live-mail: corex-worker-live-matching:
sudo -u www-data php /corex/artisan queue:restart

# 5. SMOKE (as an admin, real pages): MIC loads; a DR2 deal pipeline + Documents card;
#    proforma generate on a granted deal; payroll tax tables non-empty; notification settings page not empty.

# 6. ⚑ REGION ENGINE — SEPARATE JOHAN "GO" (§4) ⚑
sudo -u www-data php /corex/artisan prospecting:assign-municipalities --dry-run    # preview
sudo -u www-data php /corex/artisan prospecting:assign-municipalities
sudo -u www-data php /corex/artisan prospecting:reconcile-p24-suburb-regions
#    verify MIC region filter shows the municipalities; eThekwini→Umhlanga ads.
```

**Env note:** do NOT set `APP_ENV_LABEL` on live (banner must stay hidden). Live pool is **php8.3** (reload 8.3, not 8.2). Live mail is real SMTP — the AT-228 send pathway sends to real recipients only when an agent clicks send.

---

## 8. STATUS OF TWO ITEMS JOHAN'S LIST CAN'T STATUS

- **(1) R1 integer-cents money fix — NOT BUILT (investigation/audit only).** No branch, no build commit exists anywhere; it is NOT on Staging, so it is **NOT in this promotion**. It is the `.ai/audits/2026-07-12-r1-settlement-drift-investigation.md` finding (settlement money computed 3 ways; fix = integer-cents core) — scoped, never coded. Ref: memory `r1-settlement-drift-rounding` ("NOT built").
- **(2) AT-234 NCC-number field + all stationery — BUILT but STRANDED.** Commit `809cb75b` ("feat(AT-234): NCC registration number — Company Settings + all stationery", m1, 2026-07-13 11:10) lives only on branch **`AT-234`** — confirmed **NOT on Staging, NOT on main, NOT on QA1**. It never landed, so it is **NOT in this promotion.** To include it: rebase `AT-234` onto current Staging → merge → dual-deploy (its own small landing) BEFORE the live sync, or promote it separately later.

---
*Manifest carries m2's context post-/clear. Nothing here has executed. Awaiting Johan's word.*

---

## 9. APPROVAL LEDGER (2026-07-14 — dual-deploy recipe RETIRED; QA1-only henceforth)

Johan rules per NOT-PASSED item: **pass or revert BEFORE any live promotion.** "Passed" = his explicit qa pass or explicit "move to staging" on record.

| Item on Staging | Owner | Johan-PASSED? | Note |
|---|---|---|---|
| AT-228 DR2 deal-pack distribution | m2 | ✅ PASSED | explicit coordinated-landing GO; staging Mailpit-verified |
| AT-242 Door 1 — MIC buyer selector | m2 | ✅ PASSED | same GO; Johan used/tested MIC on qa (access + selector) |
| AT-239 region FILTER (mechanism) | m2 | ✅ PASSED | part of AT-242 GO — the filter UI only |
| AT-251 env banner (QA/STAGING) | m2 | ✅ PASSED | Johan-ordered "fold in"; zero-on-live |
| **AT-246 region ENGINE** (migration `towns.p24_city_id`, `assign-municipalities`, MIC `p24_city_id` filter, `region_aliases`) | m2 | ❌ NOT PASSED | screen reverted (§task1); **model remnants still on Staging → REVERT recommended** (rebuild to signed spec later, QA1) |
| AT-245 twin-on-capture + suburb friendly-error | m2 | ⚠️ ORDERED, not qa-passed | Johan ordered urgently; built+verified qa; awaits his pass |
| Proforma Invoices | ? | ❌ no pass on record | owner lane to confirm |
| AT-227 distribution matrix | ? | ❌ no pass on record | owner lane to confirm |
| AT-237 payroll hardening (tax hard-stop, proration, uniques) | m3 | ❌ no pass on record | owner lane to confirm |
| AT-238 filing register | m5? | ❌ no pass on record | owner lane to confirm |
| AT-235 notification gateway | m5 | ❌ no pass on record | owner lane to confirm |
| AT-257 non-destructive IMAP | m3 | ❌ no pass on record | owner lane to confirm |
| AT-253/AT-260/AT-261 agency-context + double-fire fixes | ? | ❌ no pass on record | owner lane to confirm |
| AT-254 splitter → canonical filing | m2? | ❌ no pass on record | owner lane to confirm |
| AT-243/AT-244 DR2 walk fixes | m1? | ❌ no pass on record | owner lane to confirm |
| e-sign §-contract test harness | ? | ❌ (tests only) | no runtime risk |

**Reading:** only 4 items carry an explicit Johan pass on my record. Everything else needs his ruling (or the owning lane's evidence of a pass) before it rides to live. The AT-246 region engine remnants should be reverted from Staging like its screen was.
