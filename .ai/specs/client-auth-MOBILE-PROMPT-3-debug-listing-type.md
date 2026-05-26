# Mobile App Prompt — Debug: rental listings appearing in sale matches

> Paste into the mobile-app Claude session.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

We have a bug: a client creates a Core Match in the app with **"For Sale"** selected, but the results list (on the agent's web view and possibly in the app too) includes **rental** properties. The backend was fixed — `MatchingService` now hard-filters by `listing_type` when the match has one set. So this is most likely a mobile-side issue where the create-match form isn't actually sending `listing_type: "sale"` in the request body, or is sending an empty/wrong value.

I need you to verify, in this order:

### 1. Inspect the create-match request

Find the screen and the API call (probably `ClientMatchEditScreen` calling `POST /api/v1/client/matches`). Log the **exact JSON body** that's sent to the server when a user picks "For Sale" and taps Save. Confirm:

- The body contains a key `listing_type`.
- Its value is exactly the string `"sale"` (not `"Sale"`, not `"SALE"`, not `"for_sale"`, not `true`, not `1`, not `null`, not `""`).
- The field is in the **top level** of the JSON body — not nested under `match` or `filters`.

Reference: the server expects:
```json
POST /api/v1/client/matches
{
  "name": "...",
  "listing_type": "sale",      ← required, must be "sale" or "rental"
  "category": "...",
  "property_type": "...",
  ...
}
```

If your "Buy / Rent" segmented control stores its state as a boolean, an integer, or a label string, you'll need to map it to `"sale"` / `"rental"` before the POST.

### 2. Verify the response after create

The server returns `201` with:
```json
{ "match": { "id": ..., "listing_type": "sale", ... } }
```

Log `response.match.listing_type` after a successful create. If it comes back as `null` or anything other than what the user picked, the request body was wrong — fix step 1.

### 3. Verify the match detail fetch

After creating the match, the app fetches `GET /api/v1/client/matches/{id}`. Log `response.match.listing_type` from that detail call too — it should match what you sent.

The detail response now also includes `listing_type` on each result property (recently added). Log a sample: if you see `results[].listing_type === "rental"` in a match whose `match.listing_type === "sale"`, that's a server bug to flag back to me — but very likely those results will all be `"sale"` (or `null` for incomplete listings, which is intentional).

### 4. Check the edit form too

The same bug could exist in the **edit filters** flow: `PUT /api/v1/client/matches/{id}`. If the user edits a match and the segmented control resets to a default before save, you might overwrite a valid `"sale"` value with something else. Verify the edit POST body the same way.

### 5. Common pitfalls to check

- A `useState<'sale' | 'rental' | null>(null)` that hasn't been initialised from the loaded match data on the edit screen → submits `null`.
- A `Picker` component that emits `{ label: "For Sale", value: "sale" }` but the form binding accidentally uses `label` instead of `value`.
- A form serialiser that strips falsy values — fine, but the value isn't falsy here; `"sale"` is truthy. Just make sure nothing converts it.
- A controlled segmented control where the on-change handler maps "For Sale" → `0` (index) → ends up in the body as `0`.

### What I need back

A short report (under 200 words):
- The exact body the app sends today for a "For Sale" create.
- Whether `listing_type` is correct, missing, or wrong.
- If wrong: the one-line fix + the file + line where it lives.
- If correct: a note saying so, and we'll investigate further server-side together.

Don't refactor anything else while you're in there. Just verify + fix this one field. If the user already has broken matches saved (with `null` listing_type), they may need to open each one and re-save to fix them — flag that in your note.
