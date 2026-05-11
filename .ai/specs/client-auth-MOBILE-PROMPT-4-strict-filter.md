# Mobile App Prompt — Strict Client Match Filter (round 4)

> Paste into the mobile-app Claude session.
> Backend already shipped a dedicated strict-filter resolver for the client API.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Server-side change: `GET /api/v1/client/matches/{id}` now uses a dedicated `ClientMatchResolver` that enforces every filter on the match as a HARD constraint. No more bleed-through of unrelated properties. This means the API will now behave exactly the way the app expects.

You don't need to change much. Just verify the following and adjust where indicated.

### What the server now enforces

Each of these filters, when set on the match, is a **strict** WHERE clause:

| Filter | Behaviour |
|---|---|
| `listing_type` | strict equality. `sale` matches return ZERO rental listings (and vice versa) regardless of property status. |
| `category` | strict equality |
| `property_type` | strict equality |
| `price_min` / `price_max` | strict numeric bounds. Properties with `price IS NULL` are excluded when a price filter is set. |
| `beds_min` / `baths_min` / `garages_min` | `>=` bounds; NULL excluded |
| `suburbs[]` (or `suburb`) | `LIKE %suburb%` match against the property's suburb |
| `hidden_property_ids[]` | hidden properties are excluded |
| Property `status` | excludes `sold`, `withdrawn`, `draft`, `archived`, `pending`, `rented` |
| Sale-listings status guard | a `sale` match additionally restricts results to statuses `for_sale`, `forsale`, `active`, `available`, `on_market` |
| Rental-listings status guard | a `rental` match additionally restricts results to `for_rent`, `forrent`, `to_rent`, `torent`, `available_rent`, `active` |

If the client sees zero results, it's almost certainly because the filters they picked are too tight. **No more "the data is dirty so we'll be lenient" behaviour** — bad data is excluded, not papered over.

### What you need to verify on the mobile side

1. **No client-side filtering on the results.** The API already returns the correct set. Do NOT add a Dart-side `where((p) => p.listingType == match.listingType)` filter on top — it's redundant and will only mask future issues.

2. **Empty result UX.** Make sure the empty state on the match detail screen is clear:
   > "No properties match your search yet. Try widening your filters."
   With a button → edit search.

3. **Match score display.** Each result still carries `match_score` (0–100). Keep showing it as `92%` etc. With strict filters most results will be 100%; the score now reflects "how many of the optional filters this property explicitly satisfies" rather than fuzzy weighting.

4. **Per-result fields available** in `GET /matches/{id}` → `results[]`:
   ```json
   {
     "id": 501,
     "address": "...",
     "suburb": "...",
     "beds": 3, "baths": 2, "garages": 1,
     "price": 1850000, "price_display": "R 1,850,000",
     "thumbnail": "https://...",
     "match_score": 100,
     "listing_type": "sale",   // safe to use for debugging only — always === match.listing_type now
     "status": "for_sale",     // shows the property status badge text
     "hidden": false,
     "reaction": "interested" | "not_interested" | "saved" | null,
     "reaction_note": "..."
   }
   ```

5. **Create / update bodies unchanged.** `POST /api/v1/client/matches` and `PUT /api/v1/client/matches/{id}` accept exactly the same JSON shape they did before:
   ```json
   {
     "name": "...",
     "listing_type": "sale" | "rental",
     "category": "Residential" | "Commercial" | "Agricultural",
     "property_type": "House" | "Townhouse" | "Apartment" | ...,
     "price_min": 800000,
     "price_max": 2500000,
     "beds_min": 2,
     "baths_min": 1,
     "garages_min": 0,
     "suburb": "Shelly Beach",
     "suburbs": ["Shelly Beach", "Margate"],
     "must_have_features": ["Sea view"],
     "notes": "..."
   }
   ```
   No change required. Continue omitting null/empty fields with map-spread as you already do.

6. **Optional UX tweak — show what's filtering.** Above the results list on the match detail screen, render a small read-only chip row that summarises the current filters (price band, beds, suburbs). This makes it obvious to the client why some properties are excluded. Tapping any chip jumps to the edit screen — you may already have this from round 2.

### Smoke test

Once you've pulled the backend changes (server-side already deployed):

1. Open a client account with a match set to `listing_type: "sale"` in an agency that has a mix of sale + rental listings.
2. View the match. The results list should contain **zero** rental properties.
3. Edit the match to `listing_type: "rental"` and save. Results should now contain **only** rentals.
4. Set a tight price range that nothing in the agency matches. Empty state should appear.

### If the app shows zero results when you expect some

Likely causes:
- Filter is too narrow (price, beds, suburb).
- The agency has no properties in the chosen `listing_type` × `category` × `property_type` combination.
- Property `status` values in the agency aren't in the allowed-status list above — flag those property IDs back to me with their `status` values, and we'll either add the status to the allow-list or fix the underlying data.

### What I need back

A short report (under 150 words):
- Confirmation that the empty-state and chip-row UX are in place on the match detail screen.
- One smoke-test screenshot showing a sale match returning only sale listings.
- Any status values you see returned by the API that look off (e.g. an unexpected free-text status) — paste them so I can extend the allow-list server-side if needed.

Don't refactor the rest of the screens. This is a behaviour-locking change, not a UI redesign.
