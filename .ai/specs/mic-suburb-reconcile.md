# MIC — Suburb reconcile on complete capture

Status: BUILT on QA1 (cc2). Branch `cc2-esign-recipient-uploads` (same import stream) → origin/QA1.

## Business requirement (Johan)
When a suburb is scraped, the importer should update that suburb's stock: refresh the listings
still present, and mark the ones that have disappeared from the portal as no-longer-available —
immediately, not via the 30-day staleness sweep. SOFT only: keep the rows for lifecycle history.

## Behaviour
On ingest of a **COMPLETE** suburb capture:
- present listings → refreshed by the normal import (last_seen, price, portal_status, `last_search_id`).
- any currently-active listing in the captured suburb(s) whose `portal_ref` was NOT in the fresh
  capture → `is_active=false` + `portal_status='withdrawn'` + `off_market_at` stamped, and its cached
  `prospecting_buyer_matches` purged. No hard delete — the row stays.

## How the scrape batches (checked) + the completeness safeguard
The Chrome capture extension posts a suburb in **many partial batches** (every 100 listings), all
upserting ONE `ProspectingSearch` (per search_url per day). A single import() POST is therefore
NEVER a complete suburb — reconciling on one would wrongly retire listings merely on a later page.

**Trigger = an explicit "capture complete" signal, never inference:**
- `background.js` sets `search_context.capture_complete = true` on its FINAL batch ONLY when it
  walked every page, skipped none (`parseWarnings === 0`), and wasn't cancelled. Intermediate
  batches send `false`. The last page's listings are held back from mid-loop flushing so a complete
  capture always ends with a flagged, non-empty final batch. (Context is snapshotted per batch so
  the late flag can't race into already-queued partial batches.)
- `ProspectingApiController::import` runs the reconcile ONLY when `capture_complete === true`.
- A skipped/failed page (rate-limit 403/429) or a cancel leaves the flag false → no reconcile.

**Cross-batch session identity:** import stamps `last_search_id = search->id` on every listing it
touches. All batches of one capture share that search, so the complete capture's listings are exactly
`last_search_id = search->id`. Gone = active listings in the same suburb(s) with a different/older
`last_search_id`. Suburb scope is derived from the session's own listings (not free text), so a
Uvongo capture only ever reconciles Uvongo stock.

Extension change takes effect on the NEXT capture after the agent re-downloads/reloads (now v3.1.7).

## Manual trigger (testing / one-off)
`php artisan prospecting:reconcile-suburb --search=<id> [--dry-run]` runs the same reconcile against a
ProspectingSearch a human asserts is complete — works even for a capture made by an older extension
(the import still stamps `last_search_id`). Use `--dry-run` first.

## Files
- migration `..._add_last_search_id_to_prospecting_listings.php`
- `app/Models/ProspectingListing.php` (additive: last_search_id fillable/cast)
- `app/Services/Prospecting/SuburbReconcileService.php` (new — the reconcile)
- `app/Http/Controllers/Api/ProspectingApiController.php` (stamp last_search_id + capture_complete trigger)
- `app/Console/Commands/Prospecting/ReconcileSuburbCapture.php` (manual trigger)
- `public/chrome-extension/portal-capture/background.js` (+ `manifest.json` v3.1.7)

## Acceptance
- Ingest a Uvongo set (capture_complete) missing some previously-active refs → those go off-market
  (is_active=false, portal_status=withdrawn), present ones stay active, ZERO hard deletes.
- A partial batch (capture_complete false) never retires anything.
