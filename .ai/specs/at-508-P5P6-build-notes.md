# AT-508 P5 / P6 — build notes and API contract (staff inspections, inspections-due, inventories)

> For the mobile-app developer. Built on branch `at508-p5p6`, 25 Oct 2026. Contract source: `.ai/specs/at-508-mobile-rentals.md` §4–§7,
> `.ai/specs/rental-inspections.md` §14.2. Tests: `tests/Feature/Api/MobileRentalsP5Test.php` (29), `tests/Feature/Api/MobileRentalsP6Test.php` (11).
> Controllers: `app/Http/Controllers/Api/V1/MobileRentalInspectionController.php`, `.../MobileRentalInventoryController.php`.
> Routes: one block in `routes/api.php` between `// AT-508 P5/P6 BEGIN` and `// AT-508 P5/P6 END`. All are named and appear in Admin → API.

---

## 0. Rules that apply to every endpoint below

**Base URL / auth.** `https://<host>/api/v1/...`, header `Authorization: Bearer <staff Sanctum token>`, `Accept: application/json`. Staff
tokens only (the tenant/owner client tokens are not accepted here). Behind `auth:sanctum`, `app_access`, `throttle:staff-api`.
Send `X-CoreX-App-Version` (spec §14.6) — nothing reads it yet.

**No rule is re-implemented.** Each write validates the request shape, then calls the SAME web action the office recording screen uses
(`RentalInspectionRecordingController`, `RentalInspectionAttendanceController`, `RentalInventoryCaptureController`), which calls the one model
method for the rule (`RentalInspectionObservation::record()`, `RentalInspectionSignature::capture()`, `RentalInspection::markCompleted()`,
`RentalInspectionDiscrepancy::resolve()` …). What the web refuses, the phone is refused too — with the same sentence in `message`.

**Errors.** Always `{"message": "..."}`; many also carry a stable `code` the app can switch on; validation is Laravel's
`{"message": "...", "errors": {"field": ["..."]}}`.

| HTTP | Meaning | `code` (when present) |
|---|---|---|
| 401 | no / bad token | — |
| 403 | no permission key, OR the record is in your agency but outside your own / branch ceiling | — |
| 404 | record does not exist, OR it belongs to another agency | — |
| 409 | state conflict: the rule refused it | `items_pending`, `items_ungraded`, `attendance_missing`, `notes_missing`, `needs_acknowledgement`, `signed_locked`, `not_recordable`, `cannot_start`, `conflict` |
| 410 | the inspection / inventory / photo was **archived** while the write sat in the app's queue — drop it | `archived` |
| 422 | validation or rule refusal (bad field, retry key used on another record, unknown ids at completion) | `unknown_items` on completion; `refused` on a shared-rule 422 |

**Scoping (two layers, same as `MobileRentalJobCardController`).** (1) The global AgencyScope: another agency's id is a **404**.
(2) `guardRentalRecordScope()` (`rental_inspections` / `rental_inventories`): a same-agency id outside the user's own / branch ceiling is a **403**.
Lists are narrowed at the query layer by `RentalInspection::scopeVisibleTo()` / `RentalInventory::scopeVisibleTo()` (an out-of-scope row is simply
not listed). "Own" for inspections = creator, the inspector, or either of the lease's agents. Checklist (property-addressed) uses the property's
own scope AND the inspections ceiling (same rule as the web tab, shared trait `AuthorizesRentalInspectionProperty`).
An owner-role account (`admin`) is held to the agency it is working in.
*Difference from the web:* for an inventory the web answers an out-of-scope id with 404; the phone answers 403, like every other rentals route.

**Offline / poor-signal rules (spec §5) — server side.** The app owns the queue; the server guarantees:

1. **Idempotency.** Every queueable write REQUIRES `client_idempotency_key` (a uuid the app mints when the agent taps, and resends unchanged on
   every retry). Missing/invalid → 422. A repeat returns the **same record, HTTP 200, `"duplicate": true`**, never a second row. A first
   success is **201 `"duplicate": false`**. A key already used on a *different* inspection / inventory is a 422 ("already used for a different …").
2. **Late / out-of-order arrival.** The payload carries `captured_at` (ISO-8601, e.g. `2026-10-25T09:14:03+02:00`). It is stored on the record's own
   time column and every list is ordered by it (table below). A missing, malformed or future (> now + 5 min) value is silently ignored (the server
   receive time is used) — it never produces a 422 on evidence.
3. **Completion guard.** `POST …/complete` requires `pending_client_items` (int, the number of items the app still holds unsent). `> 0` →
   **409 `items_pending`**. If the app also lists the observation/photo ids or keys it believes it sent and any is not on this inspection →
   **422 `unknown_items`**.
4. **Evidence after signing/completion** keeps the existing rule: offline evidence captured *before* the report was locked is stored and flagged
   `added_after_signing: true`; a live change (no capture time) to a locked report stays a 409.
5. **Archived meanwhile → 410 `archived`** — including a *replay* of something already stored. The app drops it from the queue.
6. `GET /mobile/rental-inspections/{id}` is the **full resync source** (reinstall / new phone).

| Record | Column `captured_at` is stored in |
|---|---|
| inspection (start) | `rental_inspections.created_at` (= `started_at` in the payload) |
| observation | `rental_inspection_observations.created_at` (`received_at` keeps arrival) |
| inspection photo | `rental_inspection_photos.taken_at` (`taken_at_source = client`) |
| room note | `rental_inspection_room_notes.created_at` |
| signature | `rental_inspection_signatures.disposition_recorded_at` |
| inventory photo | `rental_inventory_photos.created_at` (`updated_at` keeps arrival) |

**Photos.** `photo` is a multipart file: jpg, jpeg, png, webp, **heic, heif**, up to 50 MB — the web pipeline (private disk, EXIF orientation fixed,
GPS stripped, resized, HEIC converted when Imagick is present; a HEIC the server cannot convert is refused with a readable 422). Every returned
`url` is **absolute** and is a **signed, expiring link** (lifetime = the agency's photo-link setting). Do not cache the URL; re-fetch the detail
endpoint for fresh ones.

---

## 1. Endpoint index

| # | Method + path | Name (`v1.mobile.…`) | Permission key(s) | Phase |
|---|---|---|---|---|
| 1 | `GET /mobile/properties/{property}/rental-inspection-items` | `rental-inspections.checklist` | `rental_inspections.view` (+ property scope) | P5a |
| 2 | `GET /mobile/rental-inspections` | `rental-inspections.index` | `rental_inspections.view` | P5a |
| 3 | `POST /mobile/rental-inspections` | `rental-inspections.store` | `rental_inspections.create` | P5a |
| 4 | `GET /mobile/rental-inspections/{inspection}` | `rental-inspections.show` | `rental_inspections.view` | P5a |
| 5 | `POST /mobile/rental-inspections/{inspection}/observations` | `rental-inspections.observations.store` | `rental_inspections.create` | P5a |
| 6 | `POST /mobile/rental-inspections/{inspection}/observations/{observation}/photos` | `rental-inspections.observations.photos.store` | `rental_inspections.create` | P5a |
| 7 | `POST /mobile/rental-inspections/{inspection}/rooms/{room}/notes` | `rental-inspections.rooms.notes.store` | `rental_inspections.create` | P5a |
| 8 | `POST /mobile/rental-inspections/{inspection}/overall-notes` | `rental-inspections.overall-notes` | `rental_inspections.create` | P5a |
| 9 | `POST /mobile/rental-inspections/{inspection}/attendance` | `rental-inspections.attendance.store` | `rental_inspections.record_attendance` | P5a (added, see §6) |
| 10 | `POST /mobile/rental-inspections/{inspection}/discrepancies/{discrepancy}/resolve` | `rental-inspections.discrepancies.resolve` | `rental_inspections.resolve_discrepancy` | P5a |
| 11 | `POST /mobile/rental-inspections/{inspection}/signatures` | `rental-inspections.signatures.store` | `rental_inspections.create` (+ `rental_inspections.sign_on_behalf` for a *refusal*) | P5b |
| 12 | `POST /mobile/rental-inspections/{inspection}/complete` | `rental-inspections.complete` | `rental_inspections.create` | P5b |
| 13 | `GET /mobile/rental-inspections/due` | `rental-inspections.due` | `rental_inspections.view` | P6 |
| 14 | `GET /mobile/rental-inventories` | `rental-inventories.index` | `rental_inventories.view` | P6 |
| 15 | `GET /mobile/rental-inventories/{inventory}` | `rental-inventories.show` | `rental_inventories.view` | P6 |
| 16 | `POST /mobile/rental-inventories/{inventory}/photos` | `rental-inventories.photos.store` | `rental_inventories.create` | P6 |

`{inspection}`, `{observation}`, `{room}`, `{discrepancy}`, `{inventory}`, `{property}` are numeric ids. `GET …/due` is declared before `…/{inspection}`.
No new permission key was added. Types (`type`): `in`, `out`, `interim`, `ad_hoc` (shown to people as "Routine"; use `type_label`).
Statuses: `draft`, `in_progress`, `awaiting_signature`, `completed`, `cancelled` (inventories: `draft`, `awaiting_signature`, `completed`, `cancelled`).

---

## 2. P5 — inspections

### 2.1 `GET /mobile/properties/{property}/rental-inspection-items` — the checklist (never hardcode it in the app)

Query: `include_retired` (bool, optional, default false).
Scope: the property's own/branch/agency scope AND the inspections ceiling → 404 other agency, 403 same agency outside scope.

```json
{
  "rooms": [
    { "id": 191, "label": "Bedroom 1", "type": "bedroom", "sort_order": 1, "is_retired": false,
      "items": [
        { "id": 587, "label": "Ceiling", "kind": "space", "space_type": "bedroom", "sort_order": 1, "is_retired": false },
        { "id": 588, "label": "Floor",   "kind": "space", "space_type": "bedroom", "sort_order": 2, "is_retired": false } ] } ],
  "meters": [ { "id": 589, "label": "Electricity meter", "kind": "meter", "space_type": null, "sort_order": 3, "is_retired": false } ],
  "other_items": [],
  "property": { "id": 335, "address": "12 Sea Road" },
  "condition_states": [ { "key": "good", "label": "Good", "requires_notes": false, "severity": "blue" }, "…" ],
  "refusal_reason_presets": [ { "key": "disputes_condition", "label": "Disputes the recorded condition" }, "…" ],
  "meta": { "empty_reason": null }
}
```
`condition_states` and `refusal_reason_presets` are the **agency's own vocabulary** — build the condition picker and the refusal picker from them.
`requires_notes: true` → the observation needs `notes` (422 otherwise). `other_items` = legacy spaces not under any room (still recordable).
`meta.empty_reason` is a sentence when the property has nothing to inspect.
Offline: read-only; cache it for the walkthrough.

### 2.2 `GET /mobile/rental-inspections` — my inspections

Query (all optional): `q` or `search` (property address, tenant name, inspector name), `type` (in|out|interim|ad_hoc), `status`, `date_from`, `date_to`
(`YYYY-MM-DD`; on the scheduled date, or the day it was started when it has none), `lease_id`, `sort` (`due` default | `type` | `status`),
`direction` (`asc` default | `desc`), `scope` (own|branch|all, clamped to the user's ceiling), `page` (25 per page). Archived inspections are not listed.
Default order: soonest due first (`COALESCE(scheduled_for, started date)` ascending, then id). Bad enum/date → 422.

```json
{
  "data": [ { "id": 358, "type": "in", "type_label": "In", "status": "draft", "status_label": "Draft",
              "scheduled_for": "2026-10-28", "scheduled_time": "09:30", "due_date": "2026-10-28",
              "property": { "id": 335, "address": "12 Sea Road" }, "lease_id": 323, "tenant_names": "Tina Test",
              "inspector": { "id": 12, "name": "Aileen Agent" }, "has_unresolved_discrepancy": false,
              "started_at": "2026-10-25T12:43:18+02:00", "completed_at": null } ],
  "meta": { "page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null }
}
```
`meta.empty_reason` (plain sentence) when `total` is 0: "There are no inspections for you to see yet." (nothing visible at all) or
"No inspections match your search or filters." (filters are hiding rows). Scoping: query layer (`scopeVisibleTo`).

### 2.3 `POST /mobile/rental-inspections` — start an inspection (queueable)

| Field | Type | |
|---|---|---|
| `lease_id` | int | required |
| `type` | in\|out\|interim\|ad_hoc | required |
| `client_idempotency_key` | uuid | required |
| `captured_at` | ISO-8601 | optional — when the agent tapped Start |
| `planned_date_id` | int | optional — "book from this date" for a loaded interim date (re-checked server-side exactly as the web does) |

Calls `RentalInspection::start()` (same one-In-per-tenancy and "already under way" rules). **201** `{"duplicate": false, …full detail payload (§2.4)}`;
replay **200** `{"duplicate": true, …}`. 404 lease in another agency; **403** lease or its property outside the user's scope; **409** `cannot_start`
(e.g. "This tenancy already has an In-inspection (#…)"); 410 `archived` if that start was archived meanwhile; 422 validation.
A scheduled (booked) inspection already exists as a draft — the app does NOT start it again, it just opens it (`GET …/{id}`) and records.
Race-safe: two identical retries in flight resolve to one row (unique `(agency_id, client_idempotency_key)` + a lock on the lease).

### 2.4 `GET /mobile/rental-inspections/{inspection}` — full detail (the resync source)

Everything the app needs to rebuild its screen from nothing. 403/404 as §0; **410 `archived`** for an archived inspection.

```json
{
  "id": 358, "type": "in", "type_label": "In", "status": "draft", "status_label": "Draft",
  "is_recordable": true, "signed_locked": false,
  "started_at": "2026-10-25T12:43:18+02:00", "completed_at": null,
  "scheduled_for": null, "scheduled_time": null, "signing_deadline_at": null, "fault_report_deadline_at": null,
  "inspector": null, "created_by": { "id": 868, "name": "Miss Ericka Mraz" },
  "property": { "id": 335, "address": "12 Sea Road" }, "lease_id": 323,
  "parties": { "tenants": [ { "contact_id": 302, "name": "Tina Test" } ], "landlord": { "contact_id": 303, "name": "Lenny Test" } },
  "header": { "electricity_meter_reading": null, "water_meter_reading": null, "furnished_status": null, "property_type": "house",
             "keys_count": null, "keys_description": null, "remotes_count": null, "remotes_description": null, "move_in_date_recorded": null },
  "overall_notes": null,
  "checklist": { "rooms": [ { "id": 191, "label": "Bedroom 1", "type": "bedroom", "sort_order": 1, "is_retired": false,
                              "items": [ { "id": 587, "label": "Ceiling", "kind": "space", "space_type": "bedroom", "sort_order": 1, "is_retired": false,
                                           "latest_observation_id": 167 } ] } ],
                 "meters": [ { "id": 589, "label": "Electricity meter", "kind": "meter", "…": "…", "latest_observation_id": null } ],
                 "other_items": [] },
  "observations": [ { "id": 167, "inspection_id": 358, "item_id": 587, "item_label": "Ceiling", "condition": "damaged", "notes": "Crack above window",
                      "source": "in_inspection", "observed_by_user_id": 868,
                      "captured_at": "2026-10-25T11:43:19+02:00", "received_at": "2026-10-25T12:43:19+02:00", "added_after_signing": false,
                      "client_idempotency_key": "f8467a23-31f8-4b72-8bba-ea168c5c3309", "discrepancy_id": null,
                      "photos": [ { "id": 101, "inspection_id": 358, "observation_id": 167, "room_id": 191, "url": "https://…/private-image/…?expires=…&signature=…",
                                    "taken_at": "2026-10-25T12:43:19+02:00", "taken_at_source": "server", "added_after_signing": false,
                                    "client_idempotency_key": "f25f90ed-…", "archived": false } ] } ],
  "photos": [ "…every live photo on the inspection, incl. any not attached to an observation (same shape as above)…" ],
  "room_notes": [ { "id": 14, "inspection_id": 358, "room_id": 191, "note": "Damp smell", "captured_at": "…", "created_by_user_id": 868, "client_idempotency_key": "…" } ],
  "discrepancies": [ { "id": 9, "item_id": 587, "detected_at": "…", "resolved": false, "resolved_at": null, "accepted_observation_id": null,
                       "resolution_note": null, "observation_ids": [167, 168] } ],
  "signatures": {
    "required": true,
    "entries": [ { "id": 45, "inspection_id": 358, "party_role": "tenant", "party_contact_id": 302, "disposition": "signed", "signed_by_name": null,
                   "signed_via": null, "refusal_reason_preset": null, "refusal_reason_note": null, "has_signature_image": true, "has_wet_ink_scan": false,
                   "captured_at": "…", "superseded": false, "client_idempotency_key": "…" } ],
    "outstanding": [ { "party_role": "landlord", "party_contact_id": 303, "name": "Lenny Test" } ],
    "agent_signed": false },
  "attendance": { "required": true, "board": { "rows": [ { "key": "tenant:302", "party_role": "tenant", "contact_id": 302, "user_id": null, "name": "Tina Test",
                                                          "attendance": null, "invitation": { "lines": [ { "text": "No invitation recorded", "printable": true } ], "recorded": false } } ],
                                               "others": [], "expected": 3, "recorded": 0, "attended": 0, "complete": false, "missing": [ { "key": "tenant:302", "party_role": "tenant", "name": "Tina Test" } ] } },
  "readiness": { "state": "blocked", "unrecorded": 2, "rooms": [ { "room_id": 191, "room": "Bedroom 1", "count": 1 } ], "message": "Cannot start the signing window: …" },
  "condition_states": [ "…" ], "refusal_reason_presets": [ "…" ]
}
```
Notes: `observations` and `room_notes` are ordered by `captured_at` ascending (then id) — the order they happened, not arrived.
`latest_observation_id` = the newest REAL assessment of that item (a photo-anchor row with no condition, and late evidence, are skipped). A
discrepancy is open while `resolved: false`; `observations[].discrepancy_id` names the open one an observation is part of.
`attendance.required` false for a Routine inspection when the agency's "routine follows full checks" rule is off. `readiness.state`:
`ready`|`blocked`|`signing`|`closed`. `signatures.required` follows the agency's per-type setting (default: In/Out/Interim yes, Routine no).
Signature storage paths never appear (`has_signature_image` / `has_wet_ink_scan` only).

### 2.5 `POST /mobile/rental-inspections/{inspection}/observations` — record one observation (queueable)

| Field | Type | |
|---|---|---|
| `item_id` | int | required (alias `rental_inspection_item_id`); must be an item of this inspection's property |
| `condition` | string | required — a `key` from `condition_states` |
| `notes` | string ≤ 4000 | required when that state `requires_notes` |
| `source` | string | optional; default from the inspection type (`in_inspection` \| `out_inspection` \| `ad_hoc`; also `tenant_fault_report`) |
| `client_idempotency_key` | uuid | required |
| `captured_at` | ISO-8601 | optional but send it |

Calls `RentalInspectionObservation::record()` (create + discrepancy detection, atomically). **201** (new) / **200 `duplicate:true`** (replay):
```json
{ "duplicate": false, "id": 167, "inspection_id": 358, "item_id": 587, "item_label": "Ceiling", "condition": "damaged", "notes": "Crack above window",
  "source": "in_inspection", "observed_by_user_id": 868, "captured_at": "2026-10-25T11:43:19+02:00", "received_at": "2026-10-25T12:43:19+02:00",
  "added_after_signing": false, "client_idempotency_key": "f8467a23-…", "discrepancy_id": null }
```
`discrepancy_id` non-null = this observation conflicts with another author's for the same item → show "resolve" (§2.10). Same author correcting
themselves never raises one. Late evidence (captured before the report was signed/completed): **201** with `"added_after_signing": true` and a `message`.
Errors: 422 (bad condition / missing notes / retired item / unknown item), 409 `signed_locked`/`not_recordable` (live write to a locked/closed report),
403/404 scope, 410 archived. Replay is answered even after the inspection has closed.
Offline: queue freely, any order; each carries its own `captured_at`.

### 2.6 `POST …/observations/{observation}/photos` — one photo against one observation (queueable)

`multipart/form-data`: `photo` (file, required), `client_idempotency_key` (uuid, required), `captured_at` (ISO-8601, optional — otherwise the file's own EXIF
time, otherwise upload time). The observation must belong to this inspection (404 otherwise). Returns **201** / **200 `duplicate:true`**:
```json
{ "duplicate": false, "id": 101, "inspection_id": 358, "observation_id": 167, "room_id": 191, "url": "https://…signed…",
  "taken_at": "2026-10-25T12:43:19+02:00", "taken_at_source": "client|exif|server", "added_after_signing": false,
  "client_idempotency_key": "f25f90ed-…", "archived": false }
```
A photo is binary: 2xx = CoreX definitely has it (delete the local copy); any 5xx = retry. A replay of a photo archived meanwhile → **410**.
Photo after completion: stored and `added_after_signing: true` (with `message`) when `captured_at` is before the lock.
Create the observation first (queue order: observation → its photos). Photos not attached to an observation (room/tray) are NOT part of this build (§6).

### 2.7 `POST …/rooms/{room}/notes` — the note box under a room (queueable)

`{ "note": string ≤ 4000 (required), "client_idempotency_key": uuid (required), "captured_at": ISO-8601 (optional) }`. `{room}` must be a room of the
inspection's property (404). Notes are immutable; a correction is a new note; the room's current note is the latest by `captured_at`.
**201** `{ "duplicate": false, "id": 14, "inspection_id": 358, "room_id": 191, "note": "Damp smell", "captured_at": "…", "created_by_user_id": 868, "client_idempotency_key": "…" }`;
replay **200 `duplicate:true`**.

### 2.8 `POST …/overall-notes`

`{ "overall_notes": string ≤ 4000 | null }` → `{ "overall_notes": "Fair overall" }`. A single mutable field (last write wins), so a repeat is harmless and it needs no key.
409 once the report is locked or closed.

### 2.9 `POST …/attendance` — who was there (queueable)

Needed before an In/Out/Interim (and a Routine when the agency's rule says so) can be signed or completed — the board is in the detail
(`attendance.board.rows[]`: `party_role`, `contact_id`, `user_id`). Fields = the web's: `party_role` (tenant|landlord|agent|other, required), `party_contact_id` (tenant/landlord),
`party_user_id` (agent), `outcome` (attended|did_not_attend, required), `attended_as` (self|representative|co_occupant|other; default self), `attendee_name`
(required for a representative / other), `arrived_at` ("HH:MM"), `note`, `client_idempotency_key` (uuid, required). **201** `{ "duplicate": false, "attendance_board": {…}, "recorded_id": 60 }`;
replay **200** `{ "duplicate": true, "recorded_id": 60, "attendance_board": {…} }`. Correcting someone else's record needs `resolve_discrepancy` (403 otherwise).

### 2.10 `POST …/discrepancies/{discrepancy}/resolve`

`{ "accepted_observation_id": int (required, one of the discrepancy's observations), "resolution_note": string ≤ 2000 (optional) }`.
**200** `{ "duplicate": false, "id": 9, "item_id": 587, "detected_at": "…", "resolved": true, "resolved_at": "…", "accepted_observation_id": 168, "resolution_note": "Confirmed on site", "observation_ids": [167, 168] }`.
Idempotent by content: the *same* decision retried → **200 `duplicate:true`**; a *different* decision on an already-resolved difference → **409** (the record only appends). An
`accepted_observation_id` that is not in this agency → 404; not one of this discrepancy's participants → 422. Unresolved discrepancies block completion.

### 2.11 `POST …/signatures` — a party's signature / refusal / paper marker, or the agent's own (queueable)

| Field | Type | |
|---|---|---|
| `party_role` | tenant\|landlord\|agent | required |
| `disposition` | signed\|refused\|wet_ink\|awaiting_wet_ink | required |
| `client_idempotency_key` | uuid | required |
| `captured_at` | ISO-8601 | optional — when it was signed |
| `party_contact_id` | int | required for tenant/landlord; a tenant on this lease / the property's landlord |
| `signature_image` | base64 PNG (data-URI ok), ≤ 1 MB | for `signed` |
| `wet_ink_file` | file pdf/jpg/png/heic ≤ 10 MB | for `wet_ink` (multipart) |
| `refusal_reason_preset`, `refusal_reason_note` | string | for `refused` (`other` needs the note) |
| `signed_by_name` | string | a representative signing on the party's row (must match a recorded attendance) |
| `signing_method` | `drawn` | the agent's own signature on the phone; **defaulted to `drawn` for `party_role=agent`+`signed`** (the PIN-unlock route is a web-session feature) |

Rules come from `RentalInspectionSignature::capture()`: the **agent signs last** (422 until every tenant and the landlord have a disposition and no
paper signature is outstanding); one live disposition per party; only the inspection's own agent (inspector, else creator) may sign as the agent
(403); a *refusal* also needs `rental_inspections.sign_on_behalf` (403). **201** / replay **200 `duplicate:true`**:
```json
{ "duplicate": false, "id": 45, "inspection_id": 358, "party_role": "tenant", "party_contact_id": 302, "disposition": "signed", "signed_by_name": null,
  "signed_via": null, "refusal_reason_preset": null, "refusal_reason_note": null, "has_signature_image": true, "has_wet_ink_scan": false,
  "captured_at": "2026-10-25T12:43:19+02:00", "superseded": false, "client_idempotency_key": "7ae98f19-…" }
```
Signing links (a tenant/landlord signing on their own phone) already work in a phone browser and are not part of this build. Once anyone has really signed,
the report is **signed-locked** (`signed_locked: true` in the detail): content writes without a pre-lock `captured_at` are 409.

### 2.12 `POST …/complete` — finish the inspection

| Field | Type | |
|---|---|---|
| `pending_client_items` | int ≥ 0 | **required** — items the app still holds unsent for this inspection |
| `acknowledge_warnings` | bool | optional — an Out inspection with open findings/repairs answers 409 `needs_acknowledgement` + `warnings[]`; resend with `true` to complete anyway |
| `observation_ids` / `photo_ids` | int[] | optional — server ids the app believes it sent |
| `observation_keys` / `photo_keys` | uuid[] | optional — retry keys the app believes it sent |

Order of checks: scope/archived → validation → already completed (200 `duplicate:true`, nothing changes) → `pending_client_items > 0` (**409 `items_pending`**) →
unknown ids/keys (**422 `unknown_items`** with `unknown: { observation_ids: […], … }`) → the shared completion rules
(`RentalInspection::assertCanComplete()`/`markCompleted()`): all discrepancies resolved, every item recorded (`items_ungraded` + `ungraded_items[]`), required notes
(`notes_missing`), attendance (`attendance_missing`), all signatures incl. the agent's (409 `conflict` + the sentence), then files + e-mails the report copy per the agency's
auto-send setting (a send failure never undoes the completion). **200**:
`{ "duplicate": false, …full detail payload with "status": "completed" }`.
```json
{ "message": "The app still has 2 item(s) waiting to be sent for this inspection. Send them first, then complete.", "code": "items_pending", "pending_client_items": 2 }
{ "message": "Some of the items the app says it sent are not on this inspection, so it was not completed. …", "code": "unknown_items", "unknown": { "observation_ids": [999999] } }
{ "message": "Cannot complete: 2 checklist items have not been recorded yet — Bedroom 1 (1), General (1). …", "ungraded_items": [ { "item_id": 588, "item_label": "Floor", "room_id": 191, "room_label": "Bedroom 1" } ], "code": "items_ungraded" }
```
Offline: complete is online-only by nature (it needs everything sent). A retry after a lost response is safe (200 `duplicate:true`).

---

## 3. P6 — inspections due

### 3.1 `GET /mobile/rental-inspections/due`

The SAME rows, scope, filters and sorts as the web "Due" tab (`RentalInspectionDueController::baseRows/applyFilters/sorted` are called directly): the In/Out inspections
that are due for the agency's active leases, plus the interim dates the agency loaded. Scope = `rental_inspections` own/branch/agency, applied to the leases' property.
Query (optional): `q`/`search` (property, tenant, agent, note), `type` (in|out|interim), `status` (`open` default = overdue+due+upcoming+booked | `all` | overdue | due | upcoming |
booked | done | skipped | lease_ended), `date_from`, `date_to`, `agent_id`, `sort` (`due` default asc | property | type | status | overdue), `direction`, `scope`, `page` (25). Archived loaded dates are not offered.
```json
{ "data": [ { "key": "io-323-in", "source": "due_list", "type": "in", "type_label": "Move-in", "due_on": "2026-08-25", "state": "overdue", "state_label": "Overdue",
              "days_overdue": 61, "reason": "Move-in: the lease starts 25 Aug 2026 and no move-in inspection has been completed.", "note": null,
              "property": { "id": 335, "address": "12 Sea Road" }, "lease_id": 323, "tenant_names": "Tina Test", "agent": { "id": 869, "name": "…" },
              "planned_date_id": null, "inspection_id": null } ],
  "meta": { "page": 1, "per_page": 25, "total": 2, "last_page": 1, "empty_reason": null } }
```
`source`: `due_list` (computed In/Out) | `planned` (an interim date the agency loaded; `planned_date_id`, and `inspection_id` once booked). To start one from the phone:
`POST /mobile/rental-inspections {lease_id, type[, planned_date_id]}` — starting a move-in/out removes it from this list, exactly as on the web.
`meta.empty_reason`: "No inspections are due right now." / "No due inspections match your search or filters." Read-only; cache for the day.

---

## 4. P6 — inventories

### 4.1 `GET /mobile/rental-inventories`

Query (optional): `q`/`search` (property address, tenant name, creator name), `status`, `date_from`, `date_to` (on the created date), `sort` (`created` default | `property` | `status`),
`direction` (`desc` default = newest first | `asc`), `scope`, `page` (25). Archived inventories are not listed.
```json
{ "data": [ { "id": 73, "status": "draft", "status_label": "Draft", "property": { "id": 335, "address": "12 Sea Road" }, "lease_id": 323, "tenant_names": "Tina Test",
              "created_by": { "id": 869, "name": "…" }, "lines_count": 1, "created_at": "2026-10-25T12:43:19+02:00", "completed_at": null } ],
  "meta": { "page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null } }
```
`empty_reason`: "There are no inventories for you to see yet." / "No inventories match your search or filters."

### 4.2 `GET /mobile/rental-inventories/{inventory}`

```json
{ "id": 73, "status": "draft", "status_label": "Draft", "editable": true,
  "property": { "id": 335, "address": "12 Sea Road" }, "lease_id": 323, "tenant_names": "Tina Test", "created_by": { "id": 869, "name": "…" },
  "created_at": "…", "completed_at": null,
  "rooms": [ { "id": 191, "label": "Bedroom 1", "type": "bedroom", "sort_order": 1, "marked_empty": false } ],
  "unvisited_room_ids": [],
  "lines": [ { "id": 2, "room_id": 191, "room_label": "Bedroom 1", "quantity": 2, "description": "White wooden headboards", "condition_key": null, "photo_ids": [14] } ],
  "photos": [ { "id": 14, "inventory_id": 73, "room_id": 191, "line_ids": [2], "side": "move_in", "from_inspection": false, "url": "https://…signed…",
                "captured_at": "…", "client_idempotency_key": "…" } ],
  "signatures": [ { "id": 3, "party_role": "tenant", "party_contact_id": 302, "disposition": "signed", "captured_at": "…", "superseded": false } ],
  "condition_states": [ { "key": "new", "label": "New", "requires_notes": false }, "…" ] }
```
`editable` = status `draft` (the only status new photos are accepted in). Photos are ordered by `captured_at`. `from_inspection: true` = a photo shared from the move-in inspection (no file of its own).
403/404 scope, **410 `archived`**.

### 4.3 `POST /mobile/rental-inventories/{inventory}/photos` — add a photo (queueable)

`multipart/form-data`: `photo` (file, required), `client_idempotency_key` (uuid, required), `captured_at` (optional), `property_room_id` (int, optional — a room of the property),
`rental_inventory_line_id` (int, optional — tags the photo to that line in the same request). One photo per request. Through `RentalInventoryCaptureController::storePhotos()`.
**201** / replay **200 `duplicate:true`**:
```json
{ "duplicate": false, "id": 14, "inventory_id": 73, "room_id": 191, "line_ids": [2], "side": "move_in", "from_inspection": false,
  "url": "https://…signed…", "captured_at": "2026-10-25T12:43:19+02:00", "client_idempotency_key": "b17ac013-…" }
```
Errors: 409 `{message, status}` when the inventory is not a draft (completed = signed record; a retry of a photo already stored is still answered 200), 410 archived (inventory, or the photo
archived while queued), 422 (validation; key used on another inventory), 403/404 scope. The inventory lines, room marks, signatures and completion stay web-only (§6).

---

## 5. Server changes outside the two new controllers (all additive / behaviour-neutral)

* Migration `database/migrations/2026_12_03_100000_add_client_idempotency_key_to_rental_inspections_at508.php`: `rental_inspections.client_idempotency_key char(36) null`,
  unique `(agency_id, client_idempotency_key)`. (Observation/photo/signature/room-note/attendance/inventory-photo keys already existed.) `database/schema/mysql-schema.sql` was **not** re-dumped
  (needs a dev DB with `mysqldump`; the migration runs on top of the snapshot, as non-negotiable #12a allows) — re-dump with the DEFINER strip before this reaches Staging.
* `RentalInspectionRecordingController::authorizePropertyForInspections()` moved **unchanged** into trait `app/Http/Controllers/Concerns/AuthorizesRentalInspectionProperty.php`
  (one rule for web + mobile). Web test `RentalInspectionRecordingControllerTest` (113) passes.
* `RentalInspectionController::linkPlannedDate()` and `RentalInspectionDueController::baseRows()/applyFilters()/sorted()` changed `private` → `public` so the mobile endpoints call the
  same code. Web test `RentalInspectionDueScopeTest` passes.
* No P1 file was edited. No config, wizard, MOBILE_APP.md, shared test trait or MobileVisibilityController change.

## 6. Decisions I made (conservative) and what is NOT built — for Johan / Andre

Decisions:
1. **`client_idempotency_key` is REQUIRED** on every queueable write (422 if absent) so the contract is enforceable; the web keeps it optional.
2. **Same-agency, out-of-scope = 403** (inspections AND inventories) per spec §7; the web answers 404 for inventories.
3. **410 beats replay**: a replay of something that belongs to an archived inspection is a 410, not a 200 — the app should drop it either way.
4. **Added beyond the §14.2 table, because completion is impossible without it:** `POST …/attendance` (the web refuses to sign/complete an In/Out/Interim until every expected party has an
   attendance outcome). Also added `rooms/{room}/notes` and `overall-notes` (the task's "notes"). Nothing else was added.
5. **Agent signature on the phone is always `drawn`** (defaulted); the web "saved signature + PIN" route is session-based and is not offered.
6. `complete` retried after success returns 200 `duplicate:true` (it has no key of its own); `discrepancy resolve` is idempotent by content, `overall-notes` by nature.
7. `captured_at` for a **signature** is stored as the moment it was signed (`disposition_recorded_at`): the report's content-lock time reads this, so a signature captured offline locks the
   report from when it truly happened.
8. Inspection list default sort follows the web list (`COALESCE(scheduled_for, started date)`); the filter "date range" applies to that same date.

Not built (web-only today; all are additive to this contract later):
* `POST /mobile/properties/{property}/rental-inspection-items` (adding a room/item/meter to the checklist — §14.2 row 2) and its siblings (rename, retire, reorder, seed). **Gap for Johan:** an agent on site cannot add a missing room from the phone yet.
* Header details (`POST …/details`: meter readings, keys, remotes, furnished status) — the detail returns them read-only. **Gap for Johan.**
* Photos not tied to an observation (room / tray / item-first upload: web `POST …/photos`), photo tags, photo notes, the photo-compare/match screens.
* Start-awaiting-signature, signing links, wet-ink supersede, reopen/replace, cancel/reschedule/archive/restore, scans, resend report, deposit comparison, planned-date CRUD.
* Inventory: create, lines, dispositions, room marks, signatures, move-out, complete, copy-from-last (the app reads inventories and adds photos only, per the P6 brief).

## 7. Found outside scope (reported, NOT changed)

* `app/Http/Controllers/CoreX/RentalInventoryCaptureController.php` `storePhotos()` (line 243): the replay lookup is `where client_idempotency_key AND rental_inventory_id`, but the table's unique index is on the key alone — the
  same key sent to a *different* inventory skips the replay and hits the unique index (500 on the web). The mobile endpoint pre-checks globally and answers 422, so the app is not exposed.
* `RentalInspection::resolveRouteBinding()` applies `visibleTo()`, so the web answers an out-of-scope inspection with 404 while `guardRentalRecordScope()` (used by the mobile API) answers 403; the two layers are
  deliberately different — noting it so nobody "fixes" one to match.
* The two web guards `refuseIfNotRecordable()` run before `guardRentalRecordScope()` in `markRoomNa`, `updateOverallNotes`, `resolveDiscrepancy` (web only; masked there by the scoped route binding — the mobile API scopes first).

## 8. How it was proven

`scripts/lane-test.sh tests/Feature/Api/MobileRentalsP5Test.php` → 29 passed (396 assertions); `.../MobileRentalsP6Test.php` → 11 passed (131 assertions). Existing web files re-run once each because
their code was touched: `RentalInspectionRecordingControllerTest` 113 passed, `RentalInspectionDueScopeTest` 5 passed. Covered: success, permission denied (no key / view-only role / no
sign_on_behalf), cross-agency 404, same-agency outside own/branch 403, validation 422, idempotent replay (one row, `duplicate:true`) for start / observation / photo / room note / attendance /
signature / inventory photo / discrepancy resolve / completion, late `captured_at` stored and ordered, 409 `items_pending`, 422 `unknown_items`, 410 `archived` (writes, replays, detail, start replay), resync detail
completeness, list search / sort / filter / pagination (25) / `empty_reason`, due list (rows, filters, scope, paging), inventory detail + photo (draft-only, archived, cross-inventory key).
