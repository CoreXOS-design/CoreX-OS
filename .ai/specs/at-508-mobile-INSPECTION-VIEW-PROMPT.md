# AT-508 addendum — lessee and lessor can see the move-in and move-out inspection (prompt for the mobile-app Claude)

You are adding one feature to the rentals part of the CoreX mobile app (Flutter). The rest of AT-508 is described in `at-508-mobile-rentals-MOBILE-PROMPT.md`; this is an addition. The server side is done and on Staging.

**What the user wants:** a tenant (lessee) and an owner (lessor) can open the move-in ("In") and move-out ("Out") inspection of their home and see it: room by room, the condition of each item, notes, photos, who has signed, and — for a move-out — how each item stood at move-in.

## Server contract (finished, tested)

The inspection lists — `GET /api/v1/client/rentals/inspections` (tenant), `GET /api/v1/client/rentals/landlord/inspections` (owner), and `homes[].inspections.upcoming|past` in the overview — now carry `viewable: true|false` on every row. `true` = a sent inspection, tap to open. `false` = a booked date only: show type and date, not tappable.

- Tenant: `GET /api/v1/client/rentals/inspections/{id}`
- Owner: `GET /api/v1/client/rentals/landlord/inspections/{id}`
- Auth: the client token (`client` ability), plus the usual `X-Portal-Role: tenant|owner` header. Read-only.
- 404 `{"message":"Inspection not found."}` when it is not theirs, is only a booked date or draft, or has not been sent. Do not retry a 404.

```json
{ "inspection": {
  "id": 358, "type": "out", "type_label": "Out", "status": "completed", "date": "2026-10-24", "completed_at": "2026-10-24T10:02:00+02:00",
  "property": {"id": 335, "address": "12 Sea Road, Margate"},
  "header": {"electricity_meter_reading": "12345", "water_meter_reading": null, "furnished_status": null, "keys_count": 2, "keys_description": null, "remotes_count": 1, "remotes_description": null},
  "overall_notes": "Handed over clean.",
  "rooms": [ { "id": 191, "label": "Bedroom 1", "note": "Damp smell",
      "items": [ { "id": 587, "label": "Ceiling", "condition": "damaged", "notes": "Crack above window",
                   "photos": [ {"id": 101, "url": "https://…signed…", "taken_at": "2026-10-24T09:40:00+02:00", "added_after_signing": false} ],
                   "move_in": {"condition": "good", "notes": "Freshly painted"} } ],
      "photos": [] } ],
  "meters": [], "other_items": [],
  "signatures": {"you_signed": true, "agent_signed": true},
  "related": {"id": 301, "type": "in", "type_label": "In"} } }
```
- `rooms[].items[]` are only items that were assessed. `meters[]` and `other_items[]` have the same item shape. `rooms[].photos[]` are room photos not tied to an item.
- `move_in` exists only on a move-out's items: `{condition, notes}`, or `null` when that item was not recorded at move-in. A move-in has no `move_in` key. This is how to show "then vs now".
- `related` is the other half of the pair when it has also been sent (move-out → its move-in, and back). Use it for a "View move-in / View move-out" link. `null` = none.
- `signatures`: tenant gets `{you_signed, agent_signed}`; owner gets `{you_signed, agent_signed, tenant_signed}`.
- `condition` values come from the agency's own list; show them as the server sends them (capitalise for display only). Never hardcode a list of conditions or colours per agency wording; map unknown values to a neutral chip.
- Photo `url`s are signed and EXPIRE: always fetch the inspection fresh when the screen opens, and never cache or persist a photo url. If an image fails to load (403/expired), re-fetch the inspection once and retry.

## Rules (Johan's)
1. **The tenant sees nothing about the lessor.** The tenant payload contains no owner field; never show or derive owner details on the tenant side (the signatures block deliberately says only "you / agent").
2. **No money.** Inspections here are condition only: no costs, deductions or deposits.
3. Multi-agency, neutral wording: no agency names or branding in the screen text.
4. Read-only: no edit, sign or dispute button on this screen (signing happens by the existing signing link in the phone browser).
5. The owner's list holds every inspection of the owner's properties, including earlier tenancies. Group rows by date/tenancy.

## Screens to build
1. **Inspections list** (tenant and owner, extend the existing one): rows show type (In/Out), date, status; `viewable:true` rows open the detail, `viewable:false` rows show "Booked for <date>" and do nothing. Empty state: "No inspections yet."
2. **Inspection detail:**
   - Header: type chip (Move-in / Move-out — use `type_label` plus your own wording per `type`), date, address, key and meter figures.
   - For each room: room name, room note, then each item as: label, condition chip, note, photo strip (tap = full-screen viewer with swipe).
   - On a move-out, under each item's current condition show "At move-in: <move_in.condition> — <move_in.notes>" (or "Not recorded at move-in" when `move_in` is null). Highlight items whose condition differs from move-in.
   - Overall notes, then a signatures line: "You signed ✓ · Agent signed ✓" (owner also "Tenant signed ✓/pending").
   - A prominent "View move-in" / "View move-out" button from `related`, so the user can flip between the pair.
3. Loading, error (message + retry) and expired-photo handling as above.

## Acceptance
- A tenant with a sent move-in and move-out sees both in the list, opens each, and sees move-in beside move-out; the owner sees the same plus "Tenant signed".
- A booked, unsent inspection shows only as a date and cannot be opened.
- No lessor information anywhere in the tenant screens.
- Works with the app's existing client login, agency selection and `X-Portal-Role` header.
