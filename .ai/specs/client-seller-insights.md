# Client Seller Insights — Spec

> Mobile client (seller) sees the live marketing intelligence for the
> property/properties they own — the same seller-facing dataset CoreX already
> exposes via the Seller Live Link page and the agent's "Preview as Seller"
> toggle.

Last updated: 2026-06-14 · Owner: Andre

---

## 1. What this does and why

When a client signs in to the mobile app (ClientUser → Contact, see
`.ai/specs/client-auth.md`) and their contact profile is linked to one or more
properties as **owner / seller / landlord / lessor**, they can see live stats
for those listings: viewings, days on market, market value, agent insights,
marketing activity, comparable listings and listing status.

This is the seller's-eye view of the **Intelligence** tab on the agent property
page (the "Preview as Seller" surface). The canonical seller-facing dataset
already exists and is rendered two ways today:

- Public token page — `GET /property/live/{token}` (`SellerLinkController::show`)
- Agent preview — `resources/views/corex/properties/show.blade.php`, Intelligence
  tab, `Preview as Seller` checkbox.

This feature exposes that **same** dataset over the authenticated client API so
the mobile app can render it natively — no token, no webview. Curation is
delegated to `PropertyIntelligenceService` so the API can never leak agent-only
data (internal feedback notes, non-`seller_visible` recommendations, draft
presentations).

## 2. Pillars

- **Contact** (read) — the signed-in client's Contact in the current agency.
- **Property** (read) — properties the contact is seller-linked to.
- **Agent** (read) — the listing agent's contact card.
- Writes back: none (read-only surface). Access is logged via the existing
  client access-log pattern at the auth layer.

## 3. Data model / migrations

None. Reuses:

- `contact_property` pivot (`role` ∈ owner|seller|landlord|lessor) for the
  authorisation linkage.
- `PropertyIntelligenceService` for all aggregation.
- `property_recommendations`, `property_marketing_activities`,
  `property_presentation_snapshots`, `presentations`, `calendar_event_feedback`.

## 4. UI placement / navigation

Mobile app only (Claude mobile build). No new web page. The endpoints are
auto-listed in **Admin → API** (`/admin/api`) because they are registered under
`/api/v1/*` with route names.

## 5. Endpoints

Guard: `auth:sanctum` + `client.ability` (Sanctum token with `client` ability).
Agency context comes from the ClientUser's `current_agency_id`; the Contact is
resolved via `ClientAuthService::contactForAgency()`.

### `GET /api/v1/client/seller-properties`
List of properties the client owns/sells in the current agency.

```json
{
  "agency_id": 1,
  "properties": [
    {
      "id": 12, "title": "3 Bed in Shelly Beach", "address": "12 Marine Dr",
      "suburb": "Shelly Beach", "role": "seller", "status": "active",
      "price": 2500000, "price_display": "R 2,500,000", "thumbnail": "https://…",
      "headline": { "viewings": 4, "days_on_market": 23 }
    }
  ]
}
```

### `GET /api/v1/client/seller-properties/{property}/insights`
Full seller-facing intelligence for one property. `404` if the client is not
seller-linked to it.

```json
{
  "property": { "id": 12, "title": "…", "address": "…", "suburb": "…",
    "status": "active", "listing_type": "sale", "property_type": "House",
    "beds": 3, "baths": 2, "garages": 2, "price": 2500000,
    "price_display": "R 2,500,000", "thumbnail": "…", "images": ["…"] },
  "role": "seller",
  "agency": { "name": "Home Finders Coastal", "logo_url": "…|null" },
  "performance": { "viewings": 4, "days_on_market": 23,
    "market_value": 2450000, "area_average": 2300000 },
  "feedback_summary": { "total_viewings": 4, "total_feedback_rows": 6 },
  "agent_insights": [ { "title": "…", "reasoning": "…" } ],
  "marketing_activity": [ { "date": "2026-06-10", "type": "portal_listed", "label": "Portal listed" } ],
  "comparables": [ { "title": "…", "suburb": "…", "price": 2400000, "price_display": "R 2,400,000", "days_on_market": 31 } ],
  "presentation": { "title": "…", "generated_at": "…" } | null,
  "listing_status": { "published": true, "mandate_active": true },
  "agent": { "name": "…", "email": "…", "phone": "…" } | null,
  "last_refreshed_at": "2026-06-14T10:00:00+02:00"
}
```

## 6. Permissions

Client-side surface — authorised by Sanctum `client` ability + ownership
linkage, consistent with all other `/api/v1/client/*` endpoints. No CoreX
permission key (clients are not CoreX users). The seller-role pivot check is the
authorisation boundary: a client can only read intelligence for properties their
own Contact is linked to as owner/seller/landlord/lessor in the current agency.

## 7. User flow

1. Client logs in on mobile (existing client-auth flow), agency selected.
2. App calls `GET /api/v1/client/seller-properties`.
   - Empty list → client has no listings; hide the "My Listings" area.
   - One or more → render a list of listing cards with headline stats.
3. Client taps a listing → app calls
   `GET /api/v1/client/seller-properties/{property}/insights` → render the live
   marketing dashboard.
4. Pull-to-refresh re-fetches the insights endpoint.

## 8. Acceptance criteria

- A client linked as seller to property X gets X in `seller-properties` and a
  full payload from `…/insights`.
- A client NOT linked to property Y gets `404` from `…/insights` and Y never
  appears in the list.
- No internal-only feedback notes, no non-`seller_visible` recommendations, no
  draft presentations ever appear in the response (verified by reusing
  `PropertyIntelligenceService` with `excludeInternalOnly`/`sellerView`).
- Endpoints appear in `/admin/api` under v1.
- Multi-tenancy honoured: only the current agency's contact + properties.

## 9. Files

- `app/Http/Controllers/Api/V1/ClientSellerInsightsController.php` (new)
- `routes/api.php` (routes added under the `v1/client` group)
- `tests/Feature/Api/Client/ClientSellerInsightsTest.php` (to add)
