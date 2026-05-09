# Mobile App Prompt — Client Core Matches (round 2)

> Paste the section below into the Claude session running in the **mobile app repo**.
> Round 1 (Client Login) is already done. This adds the actual Client Home content.
> Backend endpoints are live on `andre` branch.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

The Client side login is done — clients can sign in and you have a valid sanctum token in secure storage. Now build the **Client Home** experience: viewing their Core Matches, reacting to result properties (Interested / Not for me / Saved), opening a property's full detail, editing the search filters, and creating a new match if they don't have one. This must mirror the web shared-match link (e.g. `https://corex.hfcoastal.co.za/shared/match/andre-roets-zk6ee`) — same buttons, same fields, same data — but native and authenticated.

Backend base URL: `https://corex.hfcoastal.co.za`. All endpoints below require `Authorization: Bearer <client_token>` and `Accept: application/json`.

### Endpoints (all under `/api/v1/client/`)

| # | Method | Path | Purpose |
|---|---|---|---|
| 1 | GET  | `/matches` | List client's matches in current agency (each carries `feedback_summary`) |
| 2 | GET  | `/matches/{matchId}` | Match detail: filters + result properties + per-property reaction |
| 3 | POST | `/matches` | Create a new match (only used when client has none) |
| 4 | PUT  | `/matches/{matchId}` | Edit filters on the client's own match |
| 5 | POST | `/matches/{matchId}/feedback/{propertyId}` | Body: `{ reaction, note? }` — see below |
| 6 | POST | `/matches/{matchId}/view/{propertyId}` | Fire-and-forget — increments view counter |
| 7 | GET  | `/properties/{propertyId}` | Full property detail for the live preview screen |
| 8 | GET  | `/match-options` | Suburb list + property type / category enums for the filter form |

403 on 2/4/5/6 means the match doesn't belong to this client — show a toast "This match isn't yours" and pop back. 404 on 7 means the property isn't in any of the client's matches — same handling.

### Data shapes

**Match (list view, endpoint 1)**
```json
{
  "id": 88,
  "name": "Beach house under R2.5m",
  "status": "active",
  "listing_type": "sale",
  "created_at": "2026-04-30T08:11:00+02:00",
  "updated_at": "2026-05-09T14:22:11+02:00",
  "last_engaged_at": "2026-05-09T14:22:11+02:00",
  "feedback_summary": { "interested": 3, "not_interested": 1, "saved": 2 }
}
```
List response:
```json
{ "agency_id": 1, "matches": [ /* array of the above */ ] }
```

**Match (detail, endpoint 2)** — adds full filter fields + results
```json
{
  "match": {
    "id": 88, "name": "...", "status": "active", "listing_type": "sale",
    "created_at": "...", "updated_at": "...", "last_engaged_at": "...",
    "feedback_summary": { "interested": 3, "not_interested": 1, "saved": 2 },
    "category": "Residential", "property_type": "House",
    "price_min": 800000, "price_max": 2500000,
    "beds_min": 2, "baths_min": 1, "garages_min": 0,
    "suburb": "Shelly Beach",
    "suburbs": ["Shelly Beach", "Margate", "Uvongo"],
    "must_have_features": ["Sea view", "Garden"],
    "notes": null
  },
  "results": [
    {
      "id": 501,
      "address": "12 Beach Rd, Shelly Beach",
      "suburb": "Shelly Beach",
      "beds": 3, "baths": 2, "garages": 1,
      "price": 1850000, "price_display": "R 1,850,000",
      "thumbnail": "https://corex.hfcoastal.co.za/storage/...",
      "match_score": 92,
      "hidden": false,
      "reaction": "interested",         // 'interested' | 'not_interested' | 'saved' | null
      "reaction_note": null
    }
  ]
}
```

**Property detail (endpoint 7)**
```json
{
  "property": {
    "id": 501,
    "title": "Modern beachfront family home",
    "address": "12 Beach Rd, Shelly Beach",
    "suburb": "Shelly Beach",
    "beds": 3, "baths": 2, "garages": 1, "parking": 2,
    "floor_size": 220, "erf_size": 800,
    "property_type": "House", "category": "Residential",
    "listing_type": "sale", "status": "for_sale",
    "price": 1850000, "price_display": "R 1,850,000",
    "description": "Long form copy here...",
    "features": ["Sea view","Pool","Solar"],
    "images": ["https://.../1.jpg", "https://.../2.jpg", "..."],
    "thumbnail": "https://.../1.jpg",
    "agent": { "name":"Jane Agent", "phone":"+27...", "email":"jane@..." },
    "branch": "Shelly Beach Office",
    "web_preview_url": "https://corex.hfcoastal.co.za/corex/properties/501/preview"
  }
}
```

**Match options (endpoint 8)**
```json
{
  "listing_types": ["sale","rental"],
  "property_types": ["House","Townhouse","Apartment","Vacant Land","Farm","Commercial"],
  "categories": ["Residential","Commercial","Agricultural"],
  "suburbs": ["Shelly Beach","Margate","Uvongo", "..."]
}
```

**Create / update body (endpoints 3 + 4)** — same fields; on create, `listing_type` is required.
```json
{
  "name": "Beach house under R2.5m",
  "listing_type": "sale",
  "category": "Residential",
  "property_type": "House",
  "price_min": 800000,
  "price_max": 2500000,
  "beds_min": 2,
  "baths_min": 1,
  "garages_min": 0,
  "suburb": "Shelly Beach",
  "suburbs": ["Shelly Beach","Margate"],
  "must_have_features": ["Sea view"],
  "notes": "Must be walking distance to beach"
}
```

**Feedback body (endpoint 5)**
```json
{ "reaction": "not_interested", "note": "Too far from school" }
```
- `reaction` is required, one of `interested | not_interested | saved`.
- `note` is optional. Show the note input ONLY when the client picks **Not for me** (mandatory question: "Tell us why" — but server allows blank). For `interested` / `saved`, just submit with no note.
- This is an upsert — re-submitting overwrites the previous reaction for that property in that match.
- Response:
  ```json
  { "feedback": { "property_id": 501, "reaction": "not_interested", "note": "Too far from school" } }
  ```

### Screens

**Screen A — Client Home / Match List**
- Replaces the current "Core Matches" placeholder on the client home.
- On open: `GET /matches`. Pull-to-refresh re-fetches.
- If `matches.length === 0` → render an empty state with a big **"Set up my search"** button → push to **Screen E (Edit/Create filters)** in *create* mode.
- Else: render each match as a card:
  - Title row: `match.name` (or `"{listing_type} search"` if name is null) + status pill
  - Tags row: `R {price_min}–{price_max}`, `{beds_min}+ beds`, primary suburb
  - Reaction tally: `❤️ 3  ✖️ 1  ⭐ 2` (from `feedback_summary`)
  - Subtitle: `Updated {last_engaged_at | created_at} relative`
  - Tap → **Screen B (Match detail)**.
- Floating action button: **+** → push **Screen E** in *create* mode (so a client with one match can still make another).

**Screen B — Match Detail**
- On open: `GET /matches/{matchId}`.
- Header: match name, status pill, edit icon (→ Screen E in *edit* mode), small "{interested}/{total} interested" line.
- Filter chip row (read-only): price range, beds, baths, suburbs (truncate after 2 + `+N`). Tap any chip → also goes to Screen E in *edit* mode.
- Filter toggle above results: "Hide rejected" (default ON — hides rows where `reaction === 'not_interested'`).
- Results list — each row:
  - Thumbnail (16:10), price, address, beds/baths/garages icons, match-score chip (e.g. `92%`).
  - Reaction state pill on top-right of card: heart (interested), ⭐ (saved), ✖ (not for me) — coloured if set, outline if not.
  - Tap card → **Screen C (Property preview)**. ALSO fire `POST /matches/{id}/view/{propertyId}` (don't await; ignore errors).
  - Long-press OR a 3-icon row at the bottom of the card: 3 buttons:
    - 💚 **Interested** — POST feedback `{ reaction: "interested" }`. Optimistic: flip pill green immediately.
    - ⭐ **Saved** — POST feedback `{ reaction: "saved" }`. Optimistic: flip pill yellow.
    - ✖ **Not for me** — open a small sheet with a textarea labelled *"Tell us why (optional)"* + Send button. POST `{ reaction: "not_interested", note }`. Optimistic: flip pill grey/red and add note text under the card.
  - Tapping the same icon again does nothing (optionally: PATCH to clear — out of scope for v1, leave the reaction).
- Empty state if `results.length === 0`: "No properties match yet — try widening your filters" + "Edit search" button.

**Screen C — Property Preview**
- On open: `GET /properties/{propertyId}`.
- Image carousel at the top from `property.images` (swipeable, paged dots).
- Below: title, address, big price, beds/baths/garages/parking/floor/erf rows, description (collapsible at 200 chars), features as chips.
- Agent card at the bottom: name, **Call** button (`tel:` to phone), **WhatsApp** button (`https://wa.me/{normalised}`), **Email** button (`mailto:`).
- Persistent reaction button bar at the bottom (same 3 buttons as the card on Screen B). Picking one POSTs to `/matches/{currentMatchId}/feedback/{propertyId}`.
  - You'll need to remember which match the user came from — pass `matchId` as a route param so this screen knows. If a property is reached without a match context, hide the reaction bar.
- Optional "View on web" link at the very bottom that opens `property.web_preview_url` in an in-app browser.

**Screen D — Not-for-me reason sheet** (component, not a full screen)
- Slide-up sheet with a textarea (max 500 chars), a Cancel button, and a Send button. Send POSTs feedback with the note. On success the parent screen updates the reaction pill.

**Screen E — Edit / Create filters**
- One screen, two modes (`create` vs `edit`); call `GET /match-options` on entry to populate suburb / property type pickers.
- Form fields (one per row, sectioned):
  - **Looking to** — segmented control: Buy / Rent (maps to `listing_type: sale | rental`). Required in create.
  - **Property type** — single-select dropdown from `property_types` + "Any" option.
  - **Category** — single-select from `categories` + "Any" option (default Residential).
  - **Price** — two number inputs (Min / Max) with a label "ZAR". Show "R 1,850,000" formatting on blur.
  - **Beds / Baths / Garages** — three steppers (0–10, default 0 for "Any").
  - **Suburbs** — multi-select chip picker, sourced from `match-options.suburbs` (searchable, allow free text add).
  - **Must-have features** — chip input, free text (e.g. "Sea view", "Garage", "Solar").
  - **Notes** — multi-line text (max 500 chars).
  - **Name** — single-line, optional ("Beach house under R2.5m").
- Save button:
  - Create mode → `POST /matches` with the validated body. On 201 → pop back to Screen A and refresh the list.
  - Edit mode → `PUT /matches/{matchId}` with only the changed fields (or send all — both work). On 200 → pop back to Screen B and refresh.
- 422 with `errors` field (Laravel default) → show the first error per field inline.

### Sync rules (web ↔ mobile)

- `POST /feedback` writes to the same `contact_match_feedback` table the web shared link uses. So a reaction set in the app shows up on the agent's web view immediately, and vice versa.
- `PUT /matches/{id}` and `POST /matches` write to the same `contact_matches` table the agent's web edits. Always re-fetch on Screen B return so the agent's edits don't get clobbered visually.
- Always treat `match.results` as freshly computed by the server — don't cache locally for more than a single screen lifetime. The match-results algorithm runs server-side on every detail fetch.

### Error / edge handling

- 401 anywhere → token expired. Existing global handler (from round 1) bounces back to login.
- 423 anywhere → `password_must_change` is true (existing handler routes to set-password screen).
- 403 on a match endpoint → that match isn't this client's. Toast + pop.
- 404 on `/properties/{id}` → property has been removed from the agency or isn't on any of the client's matches anymore. Toast "Property no longer available" + pop.
- 409 on any client endpoint → no agency selected; bounce to the agency picker (existing screen).
- Empty `results` on a match: not an error — show empty state (see Screen B).

### Acceptance criteria

1. A client with one or more matches sees the list on Screen A, can tap a match, see results, tap a property, see full details with images.
2. Tapping **Interested / Saved / Not for me** updates the pill instantly (optimistic) and the change is visible on the agent's web shared link within 5 seconds (no server cache to invalidate).
3. **Not for me** opens the reason sheet; submitting with or without a note both work; the note appears under the card on next render.
4. Editing the search via the pencil/icon updates the filters server-side and a fresh `/matches/{id}` returns recomputed results.
5. A client with **zero** matches sees the "Set up my search" empty state and can create one end-to-end without leaving the app.
6. The **+** FAB on the list lets a client with existing matches create another (e.g. one for sale, one for rental).
7. Reactions made by the client appear on the agent's existing Core Match results screen on the web — no new code needed there, the table is shared.
8. Property preview's Call / WhatsApp / Email actions launch the right native app with prefilled phone / message / mailto.

### Files (mobile-side, suggested layout)

```
src/screens/client/
  ClientHomeScreen          // Screen A (list)
  ClientMatchDetailScreen   // Screen B
  ClientPropertyScreen      // Screen C
  ClientMatchEditScreen     // Screen E (used for both create + edit)
src/components/
  ReactionBar               // 3-icon Interested/Saved/NotForMe row
  NotForMeSheet             // Screen D bottom sheet
  PropertyCard              // reusable result card
  FilterChip                // read-only filter chip on detail header
src/services/
  clientMatchesApi.ts       // wraps endpoints 1–8
```

When done, post a short note in the PR with:
- How you handle the optimistic reaction state on flaky network (rollback on error?).
- Whether you re-fetch the match detail after every feedback POST or rely on local update.
- Any spots where the mobile UX deliberately diverges from the web shared link.

Don't over-engineer — match the existing app's styling and idioms. The whole thing should feel like a natural extension of the User-side, just stripped down to "view your stuff" rather than "manage your team".
