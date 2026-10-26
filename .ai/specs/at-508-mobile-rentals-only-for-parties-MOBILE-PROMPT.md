# AT-508 addendum — Rentals visibility, inspection photos, move-out, typed inventory, client inspection view (prompt for the mobile-app Claude)

You are changing the rentals part of the CoreX mobile app (Flutter). The rest of AT-508 is in `at-508-mobile-rentals-MOBILE-PROMPT.md`, `at-508-P5P6-build-notes.md` and `at-508-mobile-INSPECTION-VIEW-PROMPT.md`; this is an addition and a correction list. The server side is finished and on the Staging branch. Do not guess fields: everything below is real, tested server behaviour. If you need something that is not here, ask — do not invent an endpoint.

Five jobs, in this order.

---

## 1. Rentals only for people who are in rentals (hide everything else)

A client sees rental screens ONLY when they are linked to a rental: a **lessee** (on a lease) or a **lessor** (landlord of a property). A person who is merely *marked* Lessee/Lessor in the agency but is not linked to any rental must see **no rental anything** — no tab, no tile, no menu entry, no empty "Rentals" screen. Same for an agent whose account has Rentals off.

**Clients — `GET /api/v1/client/me` (existing call, new field):**
```json
{ "client": {…}, "agencies": […], "contact": {…}, "side_contacts": {…}, "rentals": { "tenant": true, "owner": false } }
```
- `rentals.tenant` true → show the tenant (lessee) rentals views. `rentals.owner` true → show the owner (lessor) views. Both false → hide Rentals completely. Both true → keep the tenant/owner switch, showing only the true sides.
- The flags are for the **current agency** of the sign-in: call `/client/me` again after `POST /client-auth/agency/select` and re-evaluate. No `rentals` key (old server) = both false.
- The server already folds in the agency's own on/off switches, so `true` is always safe to show. Never re-derive it, and never infer "is a tenant" from an empty list.

**Staff — `GET /api/v1/mobile/visibility` (existing call, new field):**
```json
{ "contacts": {…}, "properties": {…}, "rentals": { "waiting": {"can_view": true, "can_act": true, "scope": "own"}, … }, "rentals_enabled": true }
```
- `rentals_enabled` false → hide Rentals completely: the Rentals tab/section, home tiles, waiting badge, and the rental sections on contact and property screens. Call none of the `/mobile/rentals/*`, `/mobile/fault-reports`, `/mobile/work-orders`, `/mobile/leases`, `/mobile/rental-*` endpoints.
- true → keep using `rentals.<screen>.can_view` / `can_act` exactly as before.
- Fetch it at sign-in and on every app resume.

Build one `RentalsAccess` provider holding these flags; every rental screen asks it. **Hide, don't grey out.** A rental deep link or push opened by someone whose flag is false goes to the normal home screen, no error toast. Do not cache the flags past the session.

---

## 2. Inspection photos must flow both ways (web ⇄ app)

**What was wrong (server side):** the phone could only attach a photo to an observation it had already created, and had no way to file a photo to an item or room the way the web does. Photos uploaded on the web "tray" (loose) or against a room have **no observation**, so an app that builds its photo list from observations never sees them. (Server checked: the photo links load, and the detail returns every photo — check that the app lists from `photos[]`.)

### 2a. Showing photos (read)
`GET /api/v1/mobile/rental-inspections/{id}` already returns **every** live photo of the inspection in `photos[]`, not only those under `observations[].photos[]`. Each photo:
```json
{ "id": 11, "inspection_id": 3, "observation_id": 25, "item_id": 587, "room_id": 14,
  "url": "https://…signed…", "taken_at": "…", "taken_at_source": "client|exif|server", "added_after_signing": false,
  "client_idempotency_key": "…", "archived": false }
```
- **Always render from `photos[]`** and place each photo by `item_id` (on that checklist item), else `room_id` (a general photo under that room), else both null (the inspection's "Unsorted photos" tray). Never build the photo list from observations alone.
- `url` is a signed, expiring link: don't cache it; re-fetch the detail (pull-to-refresh, and on screen open) for fresh links. Load with the normal HTTP client — no extra auth header needed.
- Treat the detail as the source of truth: after any upload, re-fetch it so web uploads and phone uploads appear together.

### 2b. Taking photos (write) — new endpoint
`POST /api/v1/mobile/rental-inspections/{id}/photos` — multipart: `photo` (jpg/jpeg/png/webp/heic/heif, ≤ 50 MB), `client_idempotency_key` (uuid, **required**, minted when the agent taps the shutter and resent unchanged on every retry), optional `captured_at` (ISO-8601), and optionally ONE of:
- `item_id` — file against a checklist item. The item does not need a condition first; the server creates its pending observation. The response includes that `observation`.
- `room_id` — a general photo of a room.
- neither — loose, goes to the tray (the agent can sort it later).

Responses: `201 {duplicate:false, …photo, observation?}`; a retry of the same key → `200 {duplicate:true, …}` (never a second photo); `410 {code:"archived"}` → drop it from the queue; `409` the inspection is locked (completed/signed) and the photo was not captured earlier; `422` bad or missing file / key; `404` an item or room that is not on this inspection's property, or another agency's inspection; `403` outside the agent's own/branch. Queue it offline like every other write. Put a camera button on **each checklist item**, on **each room heading**, and one on the inspection screen (loose).

The older `POST …/observations/{observation}/photos` still works; use the new endpoint for the camera.

---

## 3. A Move-out inspection section

The server already treats a move-out exactly like a move-in; the app simply has no section for it.
- Start: `POST /mobile/rental-inspections` with `{lease_id, type: "out", client_idempotency_key}` (types: `in`, `out`, `interim`, `ad_hoc`).
- List: `GET /mobile/rental-inspections?type=out`; due ones: `GET /mobile/rental-inspections/due?type=out`; the lease screen's `lifecycle` already has an `out_inspection` step.
- Record: identical to move-in (checklist, observations, photos §2b, signatures, attendance, complete). A completed out-inspection with open findings answers `409 needs_acknowledgement` + `warnings[]` — show them and resend with `acknowledge_warnings: true` if the agent confirms.
- Build: a **"Move-in" / "Move-out"** split wherever inspections are listed (tabs or a segmented control), a "Start move-out inspection" action on a lease that is ending, and the same inspection screen for both. Label everything from `type_label`.

---

## 4. Inventory: let the agent type, not only upload photos

`GET /mobile/rental-inventories/{id}` returns `editable` (true only while the inventory is a draft), `rooms[]`, `lines[]` (`id, room_id, room_label, quantity, description, condition_key, photo_ids`), `photos[]` and `condition_states[]` (the agency's own condition vocabulary — build the picker from it).

New endpoints (all need `rental_inventories.create`; show the controls only when `editable` is true):
| | |
|---|---|
| `POST /mobile/rental-inventories/{id}/lines` | add a line. Body: `client_idempotency_key` (uuid, **required**), `description` (**required**, e.g. "Wooden double bed"), `property_room_id` (pick from `rooms[]`) **or** `room_label` (typed, one of the two required), `quantity` (optional integer — leave blank if unknown), `condition_key` (optional, one of `condition_states[].key`). `201 {duplicate:false, id, inventory_id, room_id, room_label, quantity, description, condition_key}`; a retry of the same key → `200 {duplicate:true,…}` with the same line. |
| `PUT /mobile/rental-inventories/{id}/lines/{line}` | change a line: same fields (`description` and a room required). Returns the line. |
| `POST /mobile/rental-inventories/{id}/lines/{line}/retire` | remove a line (kept on record, never deleted). Repeating is harmless (`duplicate:true`). |

Errors: `422` (missing description / room), `409` (inventory completed or cancelled — "can no longer be edited"), `410 archived`, `404`, `403`.
Screen: per room, a list of lines with quantity + description + condition chip; "Add item" (description, quantity, condition, room preselected); tap a line to edit or remove it; and the existing camera/photos. Photos can be tagged to a line with the existing `rental_inventory_line_id` field on `POST …/photos`. Queue these writes offline.

---

## 5. Lessee and lessor can see the inspection and move-out photos

Both the lessee and the lessor open the move-in and move-out inspection of their home from their own app, **read-only**, once it has been sent to them. This is already built on the server (`at-508-mobile-INSPECTION-VIEW-PROMPT.md` has the full JSON) and the app has not built it yet (the server log shows the inspection list is read but the detail is never opened). Build it:
- The inspection lists (`GET /api/v1/client/rentals/inspections` tenant, `GET /api/v1/client/rentals/landlord/inspections` owner, and `homes[].inspections.upcoming|past` in the overview) carry `viewable: true|false` per row. `true` → tappable. `false` → a booked date only (type and date, not tappable).
- Open: tenant `GET /api/v1/client/rentals/inspections/{id}`, owner `GET /api/v1/client/rentals/landlord/inspections/{id}` — client token, header `X-Portal-Role: tenant|owner`. A 404 means not theirs / not sent yet — do not retry.
- Show room by room: each item's condition, notes and **photos** (`rooms[].items[].photos[]` and `rooms[].photos[]` — tap for a full-screen viewer, swipe between photos, show `taken_at`); a **move-out** also shows each item's `move_in{condition, notes}` beside the move-out condition so the person can compare; `meters[]`, `signatures` (tenant sees `you_signed` / `agent_signed` only), and `related` to jump between the move-in and its move-out. Photo `url`s are signed and expire — re-fetch the inspection, don't cache.
- The tenant must see **nothing about the lessor**; the payload already carries none — if you ever see an owner field in a tenant payload, it is a server bug: report it, don't show it.
- Reached only through §1: a person with `rentals.tenant`/`rentals.owner` false never sees any of this.

---

## Hard rules (unchanged)
Multi-agency neutral wording (names, labels, empty states come from the server); the server decides, the app displays; no rent/payments; soft delete only ("archived", never "deleted"); every write queues offline with its `client_idempotency_key`.

## How to check it works
1. Buyer-only client → no Rentals anywhere. A client marked Lessee but on no lease → no Rentals. On a lease → tenant view; landlord of a property → owner view; both → both.
2. Staff with rental rights → Rentals; staff without, or an agency with Rentals off → none.
3. Upload photos on the **web** inspection (item, room and tray) → all appear in the app's inspection, in the right place, after a refresh. Take photos in the app against an item, a room and loose → all appear on the web inspection.
4. Start a Move-out inspection from the app; record it; it shows under Move-out.
5. Open a draft inventory, add a typed line "Wooden bed ×1, room Bedroom 1, condition Good", edit it, remove it; check the web inventory shows the same.
6. Sign in as the tenant and as the owner of a home with a sent move-in and move-out inspection → open each, see the photos full-screen, and (move-out) the move-in comparison.
