# Audit — Legacy "HFC Premium" web portal vs `published_at` (2026-06-02)

Gate for Agency Public API spec §13 Q2 ("remove the legacy `web`/HFC Premium portal").
Run before any removal. **Conclusion: do NOT remove the legacy mechanism in the
Phase 3 build — it is a LIVE production integration entangled with a load-bearing
field. Build the new per-key syndication additively; plan the legacy retirement as
a separate, deliberate migration with Johan.**

## What the legacy "HFC Premium" portal actually is

It is a **single-website push integration**, currently **ACTIVE in production**:

- `.env`: `WEBSITE_SYNC_ENABLED=true`, `WEBSITE_SYNC_URL=https://www.themandatecompany.co.za`,
  `WEBSITE_SYNC_TOKEN=<real token>`. So HFC's real website is receiving listings right now.
- Trigger: `app/Observers/PropertyObserver.php` — on `published_at` transition it logs a
  `website_published` / `website_unpublished` audit event and dispatches
  `App\Jobs\SyncPropertyToWebsite` (`upsert` when published, `delete` when unpublished).
- Transport: `SyncPropertyToWebsite` POSTs to `{WEBSITE_SYNC_URL}/api/listings/sync`
  (or DELETE `/api/listings/{external_id}`) with a bearer token. Single global site.
- Per-property "on/off" = `published_at` (set = live on the website).
- Portal visibility toggle = `syndication_website_enabled` PerformanceSetting (agency-global).
- UI: `corex/settings.blade.php:1707` (`'web' => 'HFC Premium'` portal),
  `corex/properties/show.blade.php` syndication panel, `MobilePropertyController::buildPortalPlacements()`
  ("HFC Premium" placement when published).

## Why `published_at` cannot be removed or repurposed

`published_at` is the general "this listing is published/live" lifecycle timestamp,
read by 30+ files far beyond website sync, e.g.: PresentationPdfService,
PresentationSnapshot/Review controllers, PropertyMarketing, ArticleMatcher/Scraper,
BuyerIntelligenceService, MarketDataSnapshotService, PropertyIntelligenceService,
ReportingService, GeneratePropertyRecommendations, MobilePropertyController,
Property/Branch/Agency models. Repurposing it into the new pivot would ripple through
presentations, marketing, recommendations, and mobile.

## Decision

- **New model (this feature):** per-agency, per-key PULL API + webhooks; per-(property×website)
  visibility via `property_website_syndication` pivot. Independent of `published_at`.
- **Phase 3 build = ADDITIVE only:** add the named website portals + pivot toggle + bulk-activate
  ALONGSIDE the legacy web portal. Do not touch `published_at`, the observer's sync dispatch,
  `SyncPropertyToWebsite`, the `syndication_website_enabled` setting, or the mobile placement.
- **Legacy retirement = separate migration (Johan):** point themandatecompany.co.za at the new
  pull API + webhooks (mint its own "HFC Premium" key, bulk-activate active stock), verify parity,
  THEN disable `WEBSITE_SYNC_ENABLED` and remove the legacy portal/job/observer-dispatch. Decide
  what (if anything) keys off `published_at` for "website live" vs the pivot at that point.

## Open question for Johan/Andre

Does the new per-key website portal **replace** `published_at`-as-website-signal for
themandatecompany.co.za (full cutover), or do both run in parallel during transition?
Until decided, both coexist and `published_at` keeps its current meaning everywhere.
