# Mobile App Prompt — Listing type contract (round 5)

> Paste into the mobile-app Claude session.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

The backend Core Matches API now hard-filters by `listing_type` at three layers (SQL resolver, controller, blade view). The contract between mobile and web is locked. This is a verification / contract-confirmation round, not a refactor.

### Mobile MUST always send `listing_type`

The field is **required** on `POST /api/v1/client/matches` (create) and **must be re-sent** on `PUT /api/v1/client/matches/{id}` (edit) whenever it's set on the current form.

Two specific things to verify:

1. **Create flow** — confirm your existing code at `client_match_edit_screen.dart:112` (or wherever `_buildInput` lives) does NOT use a conditional spread for `listing_type`. It must always be present in the JSON body when the user has picked Buy or Rent. If your "Looking to" segmented control is required for create, this is already correct — just double-check.

2. **Edit flow** — when editing an existing match, **always re-send `listing_type`** with whatever the current form state has, not just the fields the user changed. Reason: if the user opens the edit screen, doesn't touch the segmented control, and saves, your conditional-spread logic might omit `listing_type` entirely. The server is forgiving (omitted = keep existing value), but to be robust, always include it.

```dart
// In your ClientMatchInput.toJson() — make listing_type unconditional
// when it's non-null on the form state:
{
  if (listingType != null) 'listing_type': listingType,
  // ...
}
```

If your form preloads `listingType` from the loaded match data and the user can't deselect it, you're already safe. Just confirm that path.

### Acceptable values

- `"sale"` — for buyers / for-sale searches
- `"rental"` — for tenants / to-rent searches

Anything else (uppercase, "for_sale", "Buy", `1`, `0`, `true`, `null`, `""`) will be rejected by the server. Map your UI labels to these two strings before sending.

### Response shape — unchanged

`GET /api/v1/client/matches/{id}` returns:

```json
{
  "match": { "id": ..., "listing_type": "sale", ... },
  "results": [
    { "id": 501, "listing_type": "sale", "status": "for_sale", ... }
  ]
}
```

Every entry in `results[]` is **guaranteed** to have `listing_type` equal to `match.listing_type` (or null for incomplete listings — which the strict resolver now excludes too, so realistically every result will match).

### Smoke test (one minute, do it on-device)

1. Open a client login on an agency that has both sale and rental properties.
2. Create a match with **For Sale**. Confirm: results list contains zero properties with `status` containing "rent".
3. Edit that same match to **For Rent**. Save. Reload the match. Confirm: results list contains zero properties with `status` containing "sale".
4. Open the agent's web view of the same match (`/corex/contacts/{c}/matches/{m}/results`). Same result set expected — no rentals in a sale match.

### What I need back

Under 100 words:
- One screenshot of the mobile match detail screen for a sale match showing only sale properties.
- Confirmation that step 3 above (toggling sale ↔ rental and saving) updates the results immediately.
- Any value other than `"sale"` or `"rental"` that you see flowing through the segmented control's state — paste it so I know if there's a mapping bug to fix.

Don't change any other screen or behaviour. This is a contract lock-in, nothing more.
