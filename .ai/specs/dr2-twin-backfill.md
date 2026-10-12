# DR1 → DR2 Twin Backfill (Option A + no-pipeline-on-twins)

> **Status:** Johan-ruled 2026-07-06. Extends the DR2 programme (AT-158 lineage, `deal-register-v2-spec.md` D1/§13.2). **Staging + QA1 only — NOT live** (DR2 live cutover remains a separate Johan word).

## Why
DR2 (`deals_v2`) shows **zero deals** because no DR1 deal was ever paired into DR2: the `DealSyncService` mirror is built and wired (observers on both models) but only *updates existing twins* — it never *creates* one, and all DR1 deals have `deal_v2_id = 0`. This backfill is the missing pairing step that makes the DR2 register show the complete book and activates the already-built shared-field mirror.

## Johan's ruling (the amendment — now doctrine)
> "For all current DR1 deals the pipeline does NOT activate. It only activates on new deals loaded. We will create test data to test it."

1. **All DR1 deals get a linked DR2 twin** (`deals_v2.legacy_deal_id` ↔ `deals.deal_v2_id`) so the register shows the whole book, states honestly mapped from DR1.
2. **Backfilled twins carry NO active pipeline** — no `deal_step_instances`, no clocks, no RAG countdown, no notifications. They are linked register history, presented as **"captured pre-pipeline."**
3. **Pipeline attachment stays exclusively on the new-deal capture path** — a deal captured in DR2 from today gets its template pipeline exactly as built.
4. **Backfill is idempotent/resumable and non-invasive to DR1** (DR1 = read-only; verify the DR1 row-count invariant before/after). New DR1 deals captured during transition are paired by **re-running** the command (the honest minimal answer — no behavioural hook on DR1).

## DR1-untouchable compliance
- The **only** write to DR1 is its already-existing additive pointer `deals.deal_v2_id`, written via a **raw** `DB::table()->update()` (no Eloquent event, no observer, no `deal_logs`, no `updated_at` churn beyond the pointer). No DR1 behaviour changes; no hard deletes.
- Twin rows are created with **`saveQuietly()`** so `DealV2Observer::saved → DealSyncService::syncFromV2` does NOT fire and cannot write back to DR1 during backfill.

## Data model (additive migration — DR2 tables only, reversible)
`deals_v2` currently forces several NOT NULL columns that a legacy twin cannot honestly provide (all 137 DR1 deals have `property_id = NULL`; DR1 has no pipeline). Additive relaxations:
- `deals_v2.property_id` → **nullable** (legacy twins have no linked Property; DR1 stored free-text `property_address`).
- `deals_v2.pipeline_template_id` → **nullable** (NULL template = "no pipeline" = the literal truth for a pre-pipeline twin).
- **add** `deals_v2.backfilled_at` (nullable timestamp) — the explicit marker: "this is a linked legacy twin, captured pre-pipeline" + audit of when.

**New-deal capture is unaffected:** `DealV2Controller::store` validation still requires `property_id`, `deal_type`, `pipeline_template_id`, `purchase_price`, `offer_date` (lines 247-255). Relaxing the DB constraints does not weaken new capture — the app layer enforces them.

## Field mapping (DR1 → twin), reusing `DealSyncService` semantics
| deals_v2 | source |
|---|---|
| `legacy_deal_id` | `deals.id` |
| `reference` | `"DR1-{deals.id}"` (unique) |
| `deal_type` | `'cash'` — DR1 never captured type; neutral default, documented |
| `status` | `DealSyncService::v1StateToV2Status($deal)` (reuse) |
| `property_id` | **NULL** (DR1 has none) |
| `listing_agent_id` | `deal_user` side=`listing` → else any-side agent (all 137 have ≥1) |
| `selling_agent_id` | `deal_user` side=`selling` (nullable) |
| `pipeline_template_id` | **NULL** (no pipeline) |
| `purchase_price` | `sale_price ?: property_value` |
| `commission_amount`/`commission_vat` | 15%-incl split of `total_commission` (matches `DealSyncService::splitInclVat`) |
| `commission_status` | `deals.commission_status ?: 'Not Paid'` |
| `offer_date` | `deal_date ?: sale_date ?: created_at` |
| `actual_registration` | `registration_date` when status completed |
| `overall_rag` | `'grey'` (no pipeline → no RAG) |
| `branch_id`, `agency_id` | from `deals` |
| `created_by_id` | resolved listing agent (DR1 has no creator column) |
| `backfilled_at` | `now()` |

## Honest presentation ("captured pre-pipeline")
- `DealV2::isPrePipeline()` ⇔ `backfilled_at !== null` (accessor).
- **Register index** (`deals-v2/index.blade.php`): for a pre-pipeline twin, replace the RAG dot with a neutral **"pre-pipeline"** marker; never render a false pipeline/RAG warning. Property cell already null-safe (`$deal->property->address ?? '—'`).
- **Detail** (`deals-v2/show.blade.php`): a banner — "Captured in DR1 before the pipeline system; linked for reference, no pipeline attached." Suppress the step/pipeline UI for twins.
- New-deal (native) rows render pipeline/RAG exactly as before.

## Command
`php artisan deals:backfill-v2-twins [--agency=] [--dry-run]` (`app/Console/Commands/DealV2/BackfillV2Twins.php`)
- Processes DR1 deals with **no** twin (`deal_v2_id` null/0 AND no `deals_v2.legacy_deal_id = deals.id`). **Idempotent + resumable** — re-run pairs newly-captured DR1 deals; never double-creates.
- Per-deal transaction; `saveQuietly()` twin + raw pointer update; asserts DR1 count unchanged.
- **Re-runnable during transition** = the ongoing-sync answer: no DR1 insert hook; a scheduled/manual re-run keeps DR2 complete while DR1 capture continues. Once a twin exists, the built `DealSyncService` observers keep shared fields synced automatically.

## Acceptance / tests (`tests/Feature/DealV2/`)
1. **Idempotency** — run twice → one twin per DR1 deal, no dupes.
2. **DR1 untouched** — DR1 row values unchanged except `deal_v2_id`; DR1 count invariant before == after.
3. **No pipeline on twins** — `deal_step_instances` count == 0 after backfill; twin `pipeline_template_id` NULL, `backfilled_at` set.
4. **Register renders both populations** — a twin shows pre-pipeline treatment; a native deal shows pipeline/RAG.
5. **New capture unaffected** — a deal captured via the DR2 path still seeds its template pipeline.
6. **Status mapping** — twin status equals `v1StateToV2Status` of its DR1 source.

## Deploy
Staging (branch → origin/Staging) → staging host (migrate + backfill + clears + php8.2 reload + worker) → QA1 (`qa-deploy.sh` + backfill). `schema:dump` after the migration. **No live.** Johan creates test data on Staging/QA1 to prove new-deal pipeline activation.

## Amendment 2026-10-08 — twins never own money
`commission_amount`/`commission_vat` (and the twin's default splits / external markers) copied above are a **dormant copy**: a linked
twin reads every money figure from the real deal (`DealV2::money()`), and `syncFromV2` no longer writes commission back. See
`deal-register-v2-spec.md` §22. `deals:parity-check` now compares money, the saved money lines and the link in cents, daily.
