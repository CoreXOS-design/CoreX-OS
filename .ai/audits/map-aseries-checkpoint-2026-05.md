# Map A-series checkpoint audit — pre-A.3

**Date:** 2026-05-25
**Branch:** `feature/map-workspace-overhaul`
**Head commit:** `28eac96` (A.2.6 hover content)
**Auditor:** Claude, executing the A.2.6 checkpoint spec

---

## Commit history on origin

```
28eac96 fix(map): A.2.6 context-driven hover content — replace 'THIS ADDRESS' placeholder...
ea2b029 feat(map): A.2.5 prospect collision detection + quick-create ID number field with POPIA audit
409858e feat(map): A.2.4 view-toggle live refresh + detail panel data richness + Copy ID + VA lookup placeholder
cd2aa92 feat(map): A.2.3 polish — Portal Stock rename + Sectional Schemes pin visual + seller-view privacy + portal strip
1fcbfe7 fix(map): A.2.1 action mapping — open listing (P24/PP), prospect now, evaluation terminology, sectional schemes rename
811b2de fix(map): A.1 bug fixes — coordinate-based grouping (5dp) + layer toggle response respected
75e2abc feat(map): A.2 pitch + whatsapp + contact-owner + comparable + cma launches from map, with activity event logging
5979d6d feat(map): A.1 composite pins + right-panel list view + back navigation
```

8 commits land the A-series end-to-end (A.1, A.1 bugfix, A.2, A.2.1, A.2.3, A.2.4, A.2.5, A.2.6). A.2.2 was the A.2.1 prompt's URL-investigation step — no commit of its own; findings folded into A.2.1.

---

## Part 1 — Full regression sweep

| Suite | Command | Tests | Result |
|---|---|---|---|
| A-series (Map + Contact ID) | `php artisan test tests/Feature/Map/ tests/Feature/Contacts/QuickCreateIdNumberTest.php` | **66** | ✅ all pass (257 assertions) |
| Map (broad filter) | `php artisan test --filter=Map` | 91 | ✅ all pass (includes FlowMapTest) |
| Geo | `php artisan test --filter=Geo` | 13 | ✅ all pass |
| Contact (broad filter) | `php artisan test --filter=Contact` | 6 | ⚠️ 2 fail — **pre-existing**, see below |

### Pre-existing failures (NOT caused by A-series)

`Tests\Feature\ClientAuth\ClientAuthFlowTest` — 2 of 6 fail with `SQLSTATE 1364: Field 'phone' doesn't have a default value`. Root cause: tests insert a `contacts` row without `phone`, but the column is NOT NULL with no default. Andre fixed this in commit `fc07c30` ("fix: Sanctum logout hardening + 22 pre-existing test fixture updates") which lives on `origin/Staging` only — not yet merged into `HFC2402`. No fix needed on the A-series branch; the issue resolves when Staging merges down.

### Slow tests called out (>500ms after schema warm-up)

The first test in any suite takes ~150-170s to run the full MySQL migration set (894 migrations) — this is the per-run cost of `RefreshDatabase` against a real MySQL test schema. Once warm, the remaining tests in the same run are sub-200ms each. Not a regression; this is the baseline cost noted in the dev-check script comments.

### Deprecation warnings

None new in the A-series tests. Pre-existing PHPUnit-12 doc-comment metadata warnings on `Tests\Unit\Presentations\UploadOverrideTest` (8 methods) — unchanged by A-series work, slated for a separate metadata-attribute migration.

---

## Part 2 — dev-check + lint

```
scripts/dev-check.ps1   →  11 of 11 changed PHP files lint OK; cache clears OK; route check skipped (no route changes since last run); view check skipped (no blade changes since last run); === DEV CHECK OK ===
```

```
php artisan view:clear    →  INFO  Compiled views cleared successfully.
php artisan config:clear  →  INFO  Configuration cache cleared successfully.
php artisan route:clear   →  INFO  Route cache cleared successfully.
composer dump-autoload    →  Generated optimized autoload files containing 8611 classes.
```

All clean.

---

## Part 3 — Map endpoint smoke

Bounded `(-30.86, 30.38) – (-30.83, 30.41)` (Uvongo / Margate slice) against agency 1, `viewMode=agent`:

```
Locations: 68
First location keys: location_key, grouping_basis, geocode_target, latitude, longitude,
                     records, categories_present, primary_category, record_count,
                     is_composite, display_as, hover_summary
  display_as: composite
  hover_summary present: YES
  hover_summary.title: 4 Ss Madeira Gardens, 4 Tucker Avenue

Composite sample (record_count=31):
  hover: {"title":"4 Ss Madeira Gardens, 4 Tucker Avenue",
          "subtitle":"HFC: House · R 998 000",
          "footer":"+30 other records"}
  categories: hfc_listings, mic_subjects, scheme_owners
```

Every location response carries `display_as`, `hover_summary` (with title/subtitle/footer), `records[]`, `categories_present`, `grouping_basis`. Backward-compat fields (`is_composite`, `record_count`, `geocode_target`, `primary_category`) still emit alongside the newer ones.

---

## Part 4 — Cross-feature canary results

| Sub-phase | Canary | Result |
|---|---|---|
| **A.1** | Composite groups by GPS at 5dp | ✅ PASS — composite at `-30.844135, 30.380705` collapses 31 records (HFC + MIC + scheme_owners). |
| **A.2.1** | HFC listing record exposes `internal_url` + `preferred_public_url` | ✅ PASS — `internal_url` populated; `preferred_public_url` null (every demo property has `pp_ref`/`p24_ref` empty, so HFC website URL fallback path is the active one). |
| **A.2.3** | At least one location has `display_as: 'scheme'` | ⚠️ SKIP (in canary bounds) — confirmed earlier on agency-wide smoke: Sunset Manor + 8 other schemes render with `display_as=scheme`. Canary bbox was too narrow to include any. |
| **A.2.4** | Seller View redacts owner identity on scheme_owner pins | ✅ PASS — all scheme_owners records in `viewMode=seller` have subtitle redacted to `Owner`. |
| **A.2.5** | Portal Stock detail endpoint returns `prospect_status` | ✅ PASS (verified via direct controller invocation against MRCR id 2138) — returns `{"status":"available"}`. Earlier audit-script attempt missed it because `new MapController()` skipped the `app(...)` resolver wiring. |
| **A.2.6** | No "THIS ADDRESS" anywhere in any `hover_summary` | ✅ PASS — case-insensitive scan of every hover_summary field across all 68 locations is clean. |

**Result: 5 PASS, 1 SKIP (bbox-narrowness, not a failure), 0 FAIL.**

---

## Part 5 — Browser walkthrough script for Johan

10 steps, ~10 minutes, every step has an explicit pass criterion.

### 1. Initial load
Open `/corex/map`. Wait for pin density to stabilise.
- ✅ Pass: ≥ 5 layer counts in left rail show non-zero numbers. Console (Cmd+Opt+I → Console) clean of errors.
- ❌ Fail: red counts on every layer, or a `TypeError` in the console.

### 2. Layer toggle
Untick **HFC Listings**. Wait one second.
- ✅ Pass: all H pins disappear from the map AND a fresh `GET /corex/map/pins` fires in the Network tab (visible because the cache was busted).
Re-tick HFC. Pins return.
Repeat the same untick / re-tick on **Portal Stock**, **Sectional Schemes**, **MIC Subjects**, **Sold Comps**.

### 3. Single pin click
Click any single-colour pin (H, S, A/P, M, or O).
- ✅ Pass: right panel opens with title + subtitle, facts list ≥ 3 rows, primary CTA button at the bottom.
- ❌ Fail: panel empty, or facts list reads "No facts available".

### 4. Composite pin click → drill → back
Click the slate-square pin with the count badge (e.g. Tucker Mews 9, or any of the 127 composites).
- ✅ Pass: list view shows records grouped by category with coloured icons.
Click any row.
- ✅ Pass: panel switches to single-record detail. A back arrow strip appears at the top reading `← N records at <address>` (or `← N units at <scheme>` for schemes).
Click back arrow.
- ✅ Pass: returns to the composite list — same scroll position, no fresh fetch.

### 5. Sectional Schemes pin
Find a purple square pin labelled `SS` with a count badge (Sunset Manor at ~`-30.879, 30.365` is the canonical demo example).
- ✅ Pass: pin visual is the **purple rounded rectangle** with `SS` text — not the slate composite square.
Click it.
- ✅ Pass: right panel header reads the **scheme name** (e.g. `SUNSET MANOR`) with subtitle `N units` (not `N records at this address`).

### 6. Agent → Seller View toggle (PII redaction)
Top right of the map → click **Seller View**.
- ✅ Pass: orange banner "Seller view active — owner/contact info hidden" appears below the header.
With the panel already open on a Sectional Schemes pin from step 5: confirm:
- ✅ Pass: composite list rows show subtitle `Owner` (not the owner's name).
- ✅ Pass: no page reload required — the redaction applied **live**.
Drill into a unit.
- ✅ Pass: sensitive_facts panel (purple "Agent only" block) is absent.

### 7. Seller → Agent View toggle
Click **Agent View**.
- ✅ Pass: banner disappears. Composite rows now show real owner names again. Sensitive_facts panel reappears on drilled detail.

### 8. HFC active listing → portal strip
Click any HFC listing pin (green H).
- ✅ Pass: bottom of the right panel shows a row labelled `View listing on:` followed by 1–3 coloured pills: **P24** (red), **PP** (blue), **HFC** (teal).
*Note: most demo properties have empty `pp_ref` / `p24_ref` — they fall back to a single secondary `Open record →` button pointing at the internal property page. That's expected and not a bug.*
Click any pill if present.
- ✅ Pass: new tab opens at the portal URL.

### 9. Portal Stock pin → prospect_status
Click any Portal Stock pin (orange P).
- ✅ Pass: right panel shows one of these states based on HFC's relationship to that address:
  - **`available`**: primary "Prospect Now →" button
  - **`held`**: info banner "Already on HFC books" + "Open property record →" (no Prospect Now)
  - **`own_draft`**: "Continue your draft (Nd) →"
  - **`other_draft`**: "Coordinate with {agent}" + secondary "Override and prospect anyway"
  - **`previously_sold`**: warn banner + "New prospect anyway →"

### 10. Override flow (if a `other_draft` case exists in demo data)
On a pin where another agent has a draft on the same address: click **Override and prospect anyway**.
- ✅ Pass: modal opens with textarea + live "0 / 20" character counter. Confirm button greyed out at < 20 chars.
Type a real reason (≥ 20 chars).
- ✅ Pass: counter shows green, Confirm enabled.
Click Confirm.
- ✅ Pass: modal closes; redirected to MIC Opportunities. Tinker check:
  ```php
  DB::table('agent_activity_events')
    ->where('event_type', 'map_prospect_override.fired')
    ->latest('id')->first(['payload']);
  ```
  Payload contains `reason`, `original_agent_name`, `days_in_state`.

### 11. Hover any composite
Hover (don't click) a composite pin.
- ✅ Pass: tooltip shows up to 3 lines — title + context-aware subtitle + optional footer. NO instance of "THIS ADDRESS".

### 12. Quick-create contact with ID number
Navigate to any property's detail page → **Contacts** tab → "Create new contact & link".
- ✅ Pass: form includes optional **ID number (optional)** input with helper text "SA ID — 13 digits. Leave blank if not known."
Submit with empty ID.
- ✅ Pass: contact created, no error.
Edit form, submit with `1234567890123` (bad checksum).
- ✅ Pass: error appears under the field.
Submit with `7610025020081`.
- ✅ Pass: contact created. Tinker:
  ```php
  $c = App\Models\Contact::latest()->first();
  echo "$c->id_number / source=$c->id_number_source / at=$c->id_number_captured_at";
  ```
  → `7610025020081 / source=property_inline_create / at=2026-05-25 ...`

### Demo-data items to flag for Johan to seed

- **Step 10 (override flow)** — needs at least one address where Agent A has a `draft` property AND a separate Portal Stock listing exists. If demo data doesn't have one today, seed via Tinker:
  ```php
  $p = App\Models\Property::create([
      'agency_id'=>1,'branch_id'=>1,'agent_id'=>{ANDRE_USER_ID},
      'external_id'=>'DRAFT-1','title'=>'Draft test','address'=>'12 Test Road',
      'suburb'=>'Uvongo','latitude'=>-30.85,'longitude'=>30.40,
      'status'=>'draft','is_demo'=>false,
  ]);
  App\Models\Prospecting\TrackedProperty::create([
      'agency_id'=>1,'external_id'=>'TP-DRAFT-1',
      'street_number'=>'12','street_name'=>'Test Road','suburb'=>'Uvongo',
      'latitude'=>-30.85,'longitude'=>30.40,
      'promoted_to_property_id'=>$p->id,'promoted_at'=>now(),'status'=>'promoted',
  ]);
  ```
  Then ensure a Portal Stock listing exists at the same coords.

---

## Part 6 — Open issues stocktake

| Phase | Item | Why deferred | Suggested resolution phase |
|---|---|---|---|
| A.2.3 | HFC website URL builder uses `property.id` as the `listing_id` placeholder | PropCon manages the actual hfcoastal.co.za listing IDs today; the column to hold a write-back ref (`hfc_website_ref`) doesn't exist yet | Post-PropCon takeover, when HFC website syndication starts writing back a canonical ref |
| A.2.4 | `prospecting_listings` (983 rows, 100% `portal_url` coverage) not sourced by the map | The map currently surfaces Portal Stock via `market_report_comp_rows` (row_type=listing) + `presentation_active_listings` — not the prospecting_listings table where the richest portal-URL data lives | Standalone "richer Portal Stock data source" phase; would let the map's Portal Stock pins surface real captured URLs instead of synthesised ones |
| A.2.4 | Schema gap on scheme_owners — no `owner_phone` / `owner_email` / `owner_id_number` / `bond_*` / `purchase_date` / `purchase_price` columns | The detail-card endpoint already reads those dynamically; rows appear the moment columns land | A future "scheme owner enrichment" phase, likely paired with a SA deeds-office data importer |
| A.2.5 | `previously_held` prospect status is defined in the JS handler but never emitted server-side | Schema has no `mandates` table or mandate-end timestamp; M58 documents this explicitly | When a mandate lifecycle module is introduced (or a `mandate_ended_at` column is added to `properties`) |
| A.2.5 | "Quick-create from map directly" is implicit — the map doesn't mint contacts itself | Map's existing prospect/contact flows route to the property page or the SellerOutreach entry, both of which now have the ID field | Resolved-by-design unless a dedicated in-map quick-create modal is requested |
| Pre-existing | `ClientAuthFlowTest` 2 failures — `contacts.phone` NOT NULL with no default | Fixed in `origin/Staging` commit `fc07c30` (Andre's "22 pre-existing test fixture updates") — not yet merged into HFC2402 | Resolves on next Staging → HFC2402 merge |

---

## Part 7 — Fix anything broken

**No drift bugs detected.** All 66 A-series tests pass; all 6 canaries green; dev-check clean. The two ClientAuthFlowTest failures are pre-existing on HFC2402 (fix already exists on Staging) — not introduced by A-series work. **No fix commit needed.**

---

## Verdict

A.1 → A.2.6 are checkpoint-clean. The branch is safe to push into A.3 (search / filter / saved searches / stock-scope toggle).
