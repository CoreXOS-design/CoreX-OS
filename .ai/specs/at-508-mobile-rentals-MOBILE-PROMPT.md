# AT-508 — Rentals in the mobile app: hand-off prompt for the mobile-app Claude

> You are building the rentals screens of the CoreX mobile app (Flutter). The server side is finished and live on the Staging branch. This document is the whole contract. Everything below is real, tested server behaviour — do not guess at fields; if something you need is not here, ask, don't invent an endpoint. Source of truth in the repo: `.ai/specs/at-508-mobile-rentals.md` (§15 build log). Server base URL = the app's existing API base; every path below is under `/api/v1`.

## 1. Who uses it — three audiences, two kinds of login

| Audience | Who | Login | Token |
|---|---|---|---|
| **Staff** (agents, managers) | CoreX users | existing staff login `POST /api/v1/login` | Sanctum bearer; staff routes `/api/v1/mobile/...` |
| **Lessor** (owner) | property owner, a client | client login `POST /api/v1/client-auth/login` (or the OTP routes `/client-auth/otp/send` + `/otp/verify`); the existing client flow | client Sanctum token with the `client` ability; routes `/api/v1/client/rentals/landlord/...` |
| **Lessee** (tenant) | tenant, a client | same client login | same; routes `/api/v1/client/rentals/...` |

- One client login can be both an owner and a tenant (two tabs). The app MAY send `X-Portal-Role: owner|tenant` on every client call: a call for the other side is then refused `403 {"role_mismatch": true}`. Without the header a login with no tenancy simply gets empty lists. Send the header — it is the safe default.
- Which screens a **staff** user may show: `GET /api/v1/mobile/visibility` now returns `rentals: { waiting, fault_reports, work_orders, leases, notices, inspections, inventories }`, each `{ can_view, can_act, scope }` (`scope` = own | branch | all, null when they cannot view). Hide a screen when `can_view` is false; hide write buttons when `can_act` is false. For clients, hide nothing by role — the server returns empty lists.
- Client agency: a client with several agencies must pick one (`POST /client-auth/agency/select`) before the rentals calls work (409 "Select an agency first" otherwise).

## 2. Hard rules (Johan's rulings — never break these)

1. **No rent, no payments anywhere.** There is no rent/deposit/payment screen. "My Lease" is lease facts only.
2. **The tenant sees nothing about the lessor.** No owner name, contact, WhatsApp, or detail, in any tenant screen. The tenant payloads carry no owner field; if you ever see one, it is a server bug — report it, do not show it. A notice given by the owner reads "Notice has been given" to the tenant (`given_by: "other_party"`).
3. **The tenant sees the managing agent's phone and email only** (no WhatsApp number). The owner sees the tenant's name (existing behaviour).
4. **Multi-agency, neutral wording.** Never hardcode an agency name, logo, colour or wording. All text that names things (fault types, statuses, labels, empty states) comes from the server. Use `agency` data from `GET /client/rentals/branding` for the client header.
5. **Soft delete only.** Nothing is ever deleted; "archived" is the word. A 410 `archived` answer means: drop the queued item.
6. **Server decides, app displays.** Never compute a business rule (who may approve, what the next step is, whether something is overdue) in the app. Show what the server says.

## 3. Push notifications

Register the phone (both kinds of login): staff `POST /api/v1/device-tokens`, `DELETE /api/v1/device-tokens/{token}`; clients `POST /api/v1/client/device-tokens` body `{platform: ios|android|web, token, app_version?}`, `DELETE /api/v1/client/device-tokens/{token}`. Register again on every login and token refresh; log-out should delete the token. The Android notification channel id MUST be `corex_alerts` (high importance), created at app start.

Payload for tenant/owner pushes: `notification.title/body` plus `data`: `{type: "rental", category, mandatory: "1"|"0", subject_type: fault|fault_report|work_order|lease_notice, subject_id, audience: tenant|owner}`. Use `subject_type` + `subject_id` to deep-link. Staff pushes carry `event_key`, `subject_type`, `subject_id`, `action_url`, `severity` (existing).

**Mandatory (cannot be switched off, never show a switch):** `decision_needed` (owner: a repair/quote/extra cost needs their decision), `work_check_requested` (tenant: finished work to check), `work_finished` (owner), `appointment` (tenant + owner), `notice_outcome` (tenant + owner). An urgent/emergency fault always alerts the agent.
**Switchable (the only ones to show in settings):** call `GET /client/rentals/push-preferences` (tenant) or `GET /client/rentals/landlord/push-preferences` (owner):
```json
{ "switchable": [ { "key": "fault_status", "label": "Progress on a fault you reported", "description": "Your fault was sent to the owner, approved, or declined.", "enabled": true, "default": true } ] }
```
Save with `PUT` same path: `{"preferences": {"fault_status": false}}` → returns the new list. A mandatory or unknown key → `422 {"code":"not_switchable"}`. `enabled` already reflects the person's own choice, else the agency default. Show only what the server lists; never hardcode the list. Staff switch their own alerts in the existing notification settings (unchanged).

## 4. Waiting for me (badges and home lists)

- Staff `GET /mobile/rentals/waiting` (needs `rental_command_centre.view`): `{ waiting_count, by_type: {fault_awaiting_approval: 2, ...}, data: [ { type, label, detail, age_days, property: {id,address}|null, lease_id, subject_type: fault_report|work_order|job_card|lease|inspection|lease_notice, subject_id } ], meta: {page, per_page, total, empty_reason} }`. `?page=N`, 25 per page. Tap → open the subject by `subject_type`/`subject_id`.
- Owner `GET /client/rentals/landlord/waiting`: `{ waiting_count, items: [ {kind: fault_report|work_order|variation, id, title, waiting_since, emergency?} ] }` — the same lists as `landlord/decisions`.
- Tenant `GET /client/rentals/waiting`: `{ waiting_count, items: [ {kind: "work_check", work_order_id, title, waiting_since} ] }` — finished work waiting for "is it fixed?".
Refresh on app open, on push receipt, and after any action.

## 5. Timeline on a fault

`timeline: [ {key, label, state: done|todo, current: bool, at: iso|null} ]` — reported → agent reviewing → owner decision → contractor booked → work done → confirmed (steps differ slightly per audience; labels come from the server). It is on tenant `GET /client/rentals/fault-reports/{id}`, owner `GET /client/rentals/landlord/fault-reports/{id}` and staff `GET /mobile/fault-reports/{id}`. Render one row per step, tick the `done` ones, highlight `current`. Do not reorder.

## 6. My Lease (tenant and owner)

- Tenant `GET /client/rentals/my-lease` → `{ "leases": [ card ] }` (one card per property rented).
- Owner `GET /client/rentals/landlord/my-lease` → `{ "properties": [ card + tenant_names ] }` (one per property; `lease`/`status` are `null` while it has no lease — show "No current lease").
```json
{ "property": {"id": 9, "address": "12 Sea Road, Margate"},
  "lease": {"id": 9, "start_date": "2026-08-25", "end_date": "2027-08-25", "is_month_to_month": false, "days_left": 304, "time_left": {"days": 304, "text": "10 months left"}},
  "status": {"state": "running", "headline": "Lease running", "detail": "Ends 25 Aug 2027 · 304 days", "in_renewal_window": false},
  "notice_period": {"value": 2, "unit": "months"},
  "notice": null,
  "renewal": null,
  "agent": {"name": "Anele Agent", "phone": "0821112222", "email": "anele@agency.example"},
  "office": null,
  "next_inspection": {"id": 31, "type": "in", "type_label": "Move-in", "date": "2026-10-30", "time": "10:30"} }
```
`notice_period` is null when the lease does not state one (do not show it, do not guess). `agent` null → show `office.phone/email` instead. `status.state`: running, renewal_window, notice_given, renewed, month_to_month, ended, upcoming. Tap phone → dial, email → mail. NEVER add rent, deposit or payment UI. Owner card also has `tenant_names`.

## 6b. Seeing the move-in and move-out inspection (tenant AND owner)

Both the lessee and the lessor can open the move-in and move-out inspection once it has been sent to them. The inspection lists now carry `viewable: true|false` per row (`true` = tap to open; `false` = a booked date only). Tenant `GET /client/rentals/inspections/{id}`, owner `GET /client/rentals/landlord/inspections/{id}` (read-only; 404 if not theirs, only a booked date, or not sent). The payload has `rooms[].items[]` (condition, notes, photos, and on a move-out `move_in{condition,notes}`), `signatures` (tenant: you/agent only), `related` (the paired In/Out). Photo urls are signed and expire: re-fetch, never cache. The tenant payload carries nothing about the lessor. **Full contract, example JSON and screen list: `at-508-mobile-INSPECTION-VIEW-PROMPT.md` (same folder).**

## 7. Client (tenant/owner) writes and the offline queue

**Existing client endpoints** (all under `/api/v1/client/rentals`, tenant paths as-is, owner paths under `/landlord/`): fault types `properties/{p}/fault-types`; report a fault `POST properties/{p}/fault-reports` (multipart; fields `rental_fault_type_id` or `other_type=1`, `title`, `description`, `resolution: still_a_problem|first_aid_resolved`, `photos[]`, `self_checks`, **`urgent: bool`**, **`captured_at`**); list/detail `fault-reports`, `fault-reports/{id}`; `POST fault-reports/{id}/notes` (note + photos[]); `POST fault-reports/{id}/withdraw` (`reason`); work orders `work-orders`, `work-orders/{id}`; tenant `POST work-orders/{id}/completion-response` (`fixed`, `note`, `photos[]`) and `POST work-orders/{id}/progress`; owner `fault-reports/{id}/decision|query|stop|resume|repair-request`, `work-orders/{id}/decision|appointment|progress`, `variations/{id}/decision`, `decisions`, `fault-settings`; notices `GET/POST notices`; `leases`, `documents`, `inspections` (dates only), `inventories`, `overview`. Use them exactly as the server's responses describe — they were not changed except as stated here.

- **Urgent:** the report-a-fault screen gets a clear "This is urgent / an emergency" switch → `urgent=true` (only with `resolution=still_a_problem`). The server flags it as an emergency and alerts the agent immediately. Response includes `urgent`.
- **Camera photos:** photos from the camera or gallery in JPG, PNG, WebP, HEIC/HEIF are all accepted (HEIC is converted on the server). Up to 10 per report, 15 MB each. Compress to a sensible size before uploading.
- **Rent/payment:** do not build it.

**Offline queue contract — the queue itself is the app's job.** Every queued client write carries a **`client_idempotency_key`** (uuid v4, generated once when the user taps the button and reused on every retry; field or header `X-Client-Idempotency-Key`). The server's answers:
| Situation | Answer | App does |
|---|---|---|
| first time | the normal 200/201 body | remove from queue |
| same key again (retry after a lost reply) | **200** with the original body plus `"duplicate": true` | remove from queue — it is already done |
| record withdrawn/archived meanwhile | **410** `{"code":"archived"}` | drop from queue, tell the user kindly |
| validation (422), not found (404), not allowed (403) | error `{"message"}` | do not retry; show the message |
| 5xx / network | — | keep and retry with backoff, SAME key |
Send **`captured_at`** (ISO 8601, when the phone captured it) so a report queued in a dead spot keeps its real time; the server never lets it be in the future. Items may arrive out of order — that is fine. A 409 "still being sent" means the first attempt is still running: wait and retry with the same key.

## 8. Staff offline contract (same idea, stricter)

Staff writes the app may queue take `client_idempotency_key` + `captured_at` (see Appendix A §0 and Appendix B §0 for exactly which endpoints). Replay → 200 `duplicate:true`. 410 `archived` → drop. Inspection `complete` requires `pending_client_items` = how many items/photos the app still holds un-sent; `> 0` → `409 {"code":"items_pending"}` — keep the screen open and finish syncing first. Unknown item/photo ids on complete → 422 `unknown_items`. `GET /mobile/rental-inspections/{id}` is the **full resync** source: after a reinstall, new phone, or any doubt, rebuild the screen from it. Photos added after the inspection was completed are stored and flagged "added after signing" (not refused).

## 9. List screens (every list endpoint)

`?q=` search, `?sort=` (+ `direction` where listed), filters `status`, `date_from`, `date_to` (+ endpoint-specific), `?page=N`, 25 per page. Response `{data: [...], meta: {page, per_page, total, last_page, empty_reason}}`. When `data` is empty show `meta.empty_reason` as the empty state. Search boxes: debounce ~400 ms. An unknown sort/status value is a 422 — only offer what the endpoint documents. Lists never contain records you may not open.

## 10. Screens to build

**Staff**
1. *Rentals home*: waiting badge + list (§4); tiles from the existing `GET /rentals/command-centre`.
2. *Faults*: list (search, status/urgency/date filters) → detail (photos, timeline, owner decision, work order) → actions: report a fault (pick a property, `GET /mobile/rental-fault-types`, photos), record the owner's decision, set the outcome. Camera-first photo capture.
3. *Work orders*: list → detail → assign supplier, photos, record contractor response, file an invoice, complete.
4. *Leases*: list (expiring soon filter) → lease file; *Notices to review* list. Actions reuse the existing `/leases/{id}/renewal/*` endpoints.
5. *Inspections*: due list → start (checklist from the server, never hardcoded) → rooms/observations with photos → attendance → discrepancies → signatures → complete, fully offline-capable with the queue; resync from detail.
6. *Inventories*: list, detail, add a photo.
**Lessor (owner)**: Home (waiting decisions badge, My Lease per property, tenant name), Decisions list → fault/quote/variation decision, Faults (report, track with timeline, stop/resume, query), Work orders (appointment, progress), Notices (give notice), Documents, Inspections (dates), notification settings (switchable list only).
**Lessee (tenant)**: Home (My Lease card, agent phone/email, next inspection), Report a fault (type picker with first-aid steps, urgent switch, camera photos, works offline via the queue), My faults (list, timeline, add a note/photo, withdraw), Check finished work ("Is it fixed?" yes/no + note/photos), Give notice, Documents, notification settings (switchable list only). Nothing about the lessor, no money.

## 11. Open product points (do not build around them; ask)
- Staff cannot yet add a room/item to an inspection checklist from the phone, edit inspection header details (meter readings, keys), or take photos not tied to an observation; ready-to-sign/signing links/cancel/reopen are web-only. Inventories: list, detail, photo only.
- Lease documents and supplier invoices are listed as metadata only (no download link yet).
- Work-order creation is online-only (no idempotency key). Fault notes by staff are not an endpoint.
- Rental applications, arrears/deposits and rent/payments are LATER, not part of this work.

---

# APPENDIX A — Staff faults, work orders, leases, notices (exact contract)

# AT-508 P2 + P3 - build notes for the mobile-app developer

> Contract for the staff fault / work-order API (P2) and the staff lease / notices API (P3).
> Spec: `.ai/specs/at-508-mobile-rentals.md` sections 4-7 and 13; route table: `.ai/specs/rentals-faults-work-orders.md` section 7.1.
> Branch `at508-p2p3`, built from the P1 commit. Tests: `tests/Feature/Api/MobileRentalsP2Test.php`, `MobileRentalsP3Test.php`.

## 0. Conventions that apply to EVERY endpoint below

- **Base:** `/api/v1`. **Auth:** staff Sanctum bearer token (`Authorization: Bearer ...`), the same login the rest of the staff app uses. Throttle: `staff-api`.
- **Errors** are `{"message": "..."}` (plain sentence, safe to show). Validation errors (422) are Laravel's `{"message": "...", "errors": {"field": ["..."]}}`. Where the app must react, a `code` is added:
  `{"message": "...", "code": "archived"}`.
  - `401` no / bad token. `403` the role lacks the permission, OR the record is in the same agency but outside the user's own / branch ceiling.
  - `404` the record does not exist OR belongs to another agency (the two are deliberately indistinguishable).
  - `409` `key_reused` (an upload key already used for another record) or `items_pending` (see work-order complete).
  - `410` `archived` - a queued write for a record archived meanwhile. Drop it from the queue; do not retry.
  - `422` the shared model / service refused ("This fault report is already closed.", "At least one completed photo is required...", ...) or the input is invalid.
- **Scoping (two layers):** another agency's id is always a 404; a same-agency id outside the person's own/branch/agency ceiling is a 403 on detail/writes and is simply absent from lists. A list never contains a record the detail would refuse.
- **Permissions:** the same keys the web routes use. A route's permission is listed per endpoint. An owner-role account (`admin`) passes every permission but is held to the agency it is working in.
- **Money:** `cost_amount`, `approved_amount`, quote `amount`/`selling_amount`, invoice `amount` appear only when the person has `rental_job_cards.view_costs`; without it the keys are ABSENT (not null).
- **Image URLs** are absolute. Fault photos from tenants are signed, expiring URLs - do not cache them for long.
- **Lists** (all list endpoints): `{"data":[...], "meta":{"page":1,"per_page":25,"total":27,"last_page":2,"empty_reason":null}}`. 25 per page, `?page=N`. When `data` is empty `meta.empty_reason` is a plain sentence for the empty-state screen (null otherwise). Unknown `sort` / `status` values are a 422.
- **Dates:** ISO 8601 with offset for moments (`2026-10-25T09:30:00+02:00`), `YYYY-MM-DD` for dates. `date_from` / `date_to` are inclusive (a bare end date means the whole day).
- **Offline queue (spec section 5):** writes the app may queue take `client_idempotency_key` (uuid v4, generate once per queued item) and `captured_at` (ISO 8601, when the phone captured it).
  - Same key again for the same record -> **HTTP 200** with `"duplicate": true` and the stored record; never a second row. First time -> 201 with `"duplicate": false`.
  - A key already used for a DIFFERENT record -> 409 `key_reused` (a bug in the app, not a replay).
  - A phone clock ahead of the server is clamped to "now" (never refused - that would strand the queue).
  - Which endpoints are queue-safe is listed per endpoint below. Endpoints without a key are online-only; a blind retry is safe only where stated.

---

# P2 - faults and work orders

## 1. GET `/mobile/rental-fault-types`  (`v1.mobile.rental-fault-types`)

The agency's own active fault catalogue - the picker a tenant sees - for the "report a fault" screen.

- Permission: `rental_fault_reports.create`.
- Query: `property_id` (optional int). With it, the first-aid steps are worked out for that property (and the property's valve / DB-board photos and the agency's documents are included); without it the raw text is returned. The property must be visible to the user, else 404.
- Response 200:
```json
{
  "data": [
    {"id": 7, "name": "Dripping tap", "category": "Plumbing", "urgency": "routine", "first_aid_steps": "Close the valve under the sink."}
  ],
  "other_option": {"id": "other", "name": "Other — not on the list"}
}
```
  With `property_id` each row also has `safety_line`, `steps[]`, `photos[]`, `documents[]` (same shape as the tenant portal's fault-types).
- Use `other_option.id` ("other") as `rental_fault_type_id` when nothing fits; the report is then marked Other until an agent re-classifies it on the web.
- Errors: 403 (no permission), 404 (property not visible / other agency).

## 2. GET `/mobile/fault-reports`  (`v1.mobile.fault-reports.index`)

- Permission: `rental_fault_reports.view`. Scope: the model's own `visibleTo` (own / branch / agency).
- Query:
  | param | meaning |
  |---|---|
  | `q` | search: property address, tenant name, fault type name, title, reference (`FR-12` or `12`) |
  | `sort` | `newest` (default) \| `oldest` \| `status` \| `property` |
  | `status` | `reported, under_review, awaiting_approval, approved, declined, work_order_raised, owner_handling, resolved, cancelled` |
  | `urgency` | the fault type's urgency: `routine, urgent, emergency` |
  | `date_from`, `date_to` | on the reported date |
  | `archived=1` | archived (soft-deleted) faults only; needs `rental_fault_reports.create`, else 403 |
  | `page` | 1-based |
- Response 200:
```json
{
  "data": [
    {
      "id": 41, "reference": "FR-41", "title": "Tap drips", "status": "reported", "status_label": "Reported",
      "urgency": "routine", "is_emergency": false, "fault_type": "Dripping tap",
      "property": {"id": 9, "address": "12 Sea Road, Margate"}, "lease_id": 5, "tenant_names": "Tina Test",
      "reported_at": "2026-10-25T09:30:00+02:00", "owner_approval_status": "not_required", "outcome": null,
      "work_order_id": null, "archived": false
    }
  ],
  "meta": {"page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null}
}
```
- Note: the web list pins open emergency faults to the top regardless of sort; this API does NOT (spec: default newest). Use `urgency=emergency` for an emergency tab.
- Empty reason: "No fault reports match. Clear the search or filters to see more." (archived: "There are no archived fault reports.").

## 3. GET `/mobile/fault-reports/{faultReport}`  (`v1.mobile.fault-reports.show`)

- Permission: `rental_fault_reports.view`. 403 same-agency outside scope, 404 other agency / unknown. An archived fault still opens (`"archived": true`) so the archived list can be tapped.
- Response 200 = the list item above PLUS:
```json
{
  "...": "all list fields",
  "description": "The kitchen tap drips all night.",
  "fault_type_detail": {"id": 7, "name": "Dripping tap", "category": "Plumbing", "urgency": "routine"},
  "reported_by_type": "agent_noticed", "reported_channel": "app", "reported_by": "Agnes Agent",
  "approval_route": null, "outcome_note": null, "repaired_at": null, "resolved_at": null, "cancelled_at": null, "cancel_reason": null,
  "work_order": {"id": 12, "reference": "WO-12", "status": "ordered", "status_label": "Go-ahead sent"},
  "photos": [{"id": 3, "url": "https://.../storage/....jpg", "captured_at": "2026-10-25T09:31:00+02:00", "client_idempotency_key": "6f1c..."}],
  "approvals": [{"id": 2, "decision": "approved", "approval_route": "agency_appoints", "evidence_type": "verbal_note", "evidence_text": "Owner phoned.",
                 "decided_at": "2026-10-25T10:00:00+02:00", "recorded_by": "Agnes Agent", "contractor_name": null, "contractor_phone": null, "agency_service_provider_id": 4}],
  "timeline": [{"key": "sent_to_agent", "label": "Reported", "state": "done", "current": false, "at": "2026-10-25T09:30:00+02:00"}]
}
```
- `timeline[]` = `RentalFaultProgressService::timeline($fault, 'agent')`: the same progress line the agent sees on the web, flat. Empty for a fault the tenant closed themselves with the first-aid step. `state` is `done` or `todo`; `current` marks the active step.

## 4. POST `/mobile/properties/{property}/fault-reports`  (`v1.mobile.properties.fault-reports.store`)

Report a fault. Calls `RentalFaultReportService::report()` - exactly what the web form calls (alerts to the lease's agents included).

- Permission: `rental_fault_reports.create`. Content-Type JSON.
- Fields:
  | field | |
  |---|---|
  | `title` | required, string, max 191 |
  | `description` | required, string |
  | `rental_fault_type_id` | optional: a type id of THIS agency, or the string `"other"` |
  | `lease_id` | optional: a lease of THIS property |
  | `reported_by_type` | optional `tenant \| agent_noticed \| owner_instructed` (default `agent_noticed`) |
  | `reported_by_contact_id` | optional: a contact of this agency |
  | `reported_channel` | optional `phone \| whatsapp \| email \| in_person \| app \| other` (default `app`) |
  | `client_idempotency_key` | optional uuid - **queue-safe** |
  | `captured_at` | optional ISO 8601 -> stored as the report's `reported_at` |
- Response **201** (new) / **200** (replay): the full fault detail (section 3) plus `"duplicate": false|true`.
- Errors: 403 no permission; 404 property not visible to the user / other agency (same as web); 410 `archived` (the property or the already-stored report is archived); 409 `key_reused` (key already used for another property); 422 validation.
- Replay order: a stored report under the key is returned BEFORE any archived/visibility refusal of the property, so a retry never creates a duplicate.

## 5. POST `/mobile/fault-reports/{faultReport}/photos`  (`v1.mobile.fault-reports.photos.store`)

- Permission: `rental_fault_reports.create`. Content-Type multipart (`Accept: application/json`).
- Fields: `photo` (required file jpg/jpeg/png/webp/heic/heif, max 50 MB), `client_idempotency_key` (uuid, **queue-safe**), `captured_at` (ISO 8601 -> the photo's time).
- Response 201 / 200:
```json
{"id": 3, "url": "https://.../storage/....jpg", "captured_at": "2026-10-25T09:31:00+02:00", "client_idempotency_key": "6f1c...", "duplicate": false}
```
- Errors: 403/404 (scope), 409 `key_reused`, 410 `archived`, 422 (no/invalid file).

## 6. POST `/mobile/fault-reports/{faultReport}/approval`  (`v1.mobile.fault-reports.approval.store`)

Record the owner's decision the agent was told (WhatsApp / email / verbal). Calls `RentalFaultReport::recordApproval()`.

- Permission: `rental_fault_reports.record_approval`. JSON or multipart.
- Fields: `decision` (required `approved \| declined`), `approval_route` (`agency_appoints \| owner_handles`; REQUIRED when approved), `evidence_type` (required `whatsapp \| email \| verbal_note`), `evidence_text` (required - for a decline this is the reason), `evidence_file` (optional image), `decided_at` (optional date), `contractor_name`, `contractor_phone` (owner handles), `agency_service_provider_id` (agency appoints: must be one of the contractors offered for this fault's type).
- Response 200: fault detail. The fault moves to `approved` / `owner_handling` / `declined`.
- Errors: 422 with the model's sentence - e.g. "A decision has already been recorded for this fault report (...)", "This fault report is already closed.", "A work order has already been raised...", "Say who appoints the contractor: the owner or the agency.", "Please choose a contractor from the list for this type of work." 410 `archived`. Online-only, no key: ONE decision ever, so a blind retry after success is a harmless 422.
- NOT built here (web only): "save decision and create work order" hand-over (`after=create_work_order`), send-to-owner, owner query/stop/resume. Raise the work order with section 9 or on the web.

## 7. POST `/mobile/fault-reports/{faultReport}/outcome`  (`v1.mobile.fault-reports.outcome.store`)

Close the fault with its outcome. Calls `RentalFaultReport::setOutcome()` then `RentalFaultReportService::notifyResolved()` (the "resolved" alert) - same as the web.

- Permission: `rental_fault_reports.resolve`.
- Fields: `outcome` (required `repaired \| repaired_partially \| not_repaired \| owner_declined \| tenant_liable \| resolved_by_first_aid`), `outcome_note` (required unless repaired / resolved_by_first_aid), `repaired_at` (date; required for repaired / repaired_partially).
- Response 200: fault detail (`status: "resolved"`).
- Errors: 422 model sentences ("This fault report is already closed.", "Say when the repair was carried out..."), 410 `archived`. Online-only.

## 8. GET `/mobile/work-orders`  (`v1.mobile.work-orders.index`)

- Permission: `rental_work_orders.view`. Scope: the model's own `visibleTo`.
- Query: `q` (property address, tenant name, contractor name, title, reference `WO-12` / `12`), `sort` = `newest` (default) \| `due` \| `status` (**`due` = the booked appointment, soonest first, unbooked last** - a work order has no other due date), `status` = `reported, ordered, in_progress, completed, cancelled, disputed`, `date_from`, `date_to` (on the reported date), `archived=1` (needs `rental_work_orders.create`), `page`.
- Response 200:
```json
{
  "data": [
    {
      "id": 12, "reference": "WO-12", "title": "Fix the gate", "status": "ordered", "status_label": "Go-ahead sent", "priority": "normal", "trade_type": "general",
      "property": {"id": 9, "address": "12 Sea Road, Margate"}, "lease_id": 5, "tenant_names": "Tina Test",
      "assignment_type": "outside_supplier", "who": "Agency contractor", "contractor": "Acme Plumbing",
      "reported_at": "2026-10-25T09:30:00+02:00", "appointment_at": "2026-10-28T10:00:00+02:00", "fault_report_id": 41, "archived": false
    }
  ],
  "meta": {"page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null}
}
```
  `assignment_type`: `outside_supplier | internal | owner_contractor`; `contractor` is the supplier's name, or the owner's own contractor's name.
- Empty reason: "No work orders match. Clear the search or filters to see more."

## 9. GET `/mobile/work-orders/{workOrder}`  (`v1.mobile.work-orders.show`)

- Permission: `rental_work_orders.view`; 403/404 as for faults; archived opens with `"archived": true`.
- Response 200 = the list item PLUS:
```json
{
  "description": "Gate sticks", "reported_by_type": "agent_noticed", "owner_approval_status": "not_required",
  "ordered_at": null, "appointment_note": null, "access_contact_name": null, "access_contact_phone": null, "access_note": null,
  "completed_at": null, "completion_notes": null, "cancelled_at": null, "cancel_reason": null, "paid_by": null,
  "supplier": {"id": 4, "name": "Acme Plumbing"}, "contractor_phone": null,
  "contractor_response": "accepted", "contractor_response_label": "Accepted the job", "contractor_decline_reason": null,
  "job_card_id": null,
  "photos": [{"id": 8, "url": "https://...", "photo_type": "reported", "captured_at": "2026-10-25T09:31:00+02:00", "client_idempotency_key": "..."}],
  "quotes": [{"id": 3, "supplier": "Acme Plumbing", "quote_date": "2026-10-26", "is_selected": true, "declined": false, "amount": "1250.00", "selling_amount": "1375.00"}],
  "invoices": [{"id": 2, "invoice_number": "INV-1", "invoice_date": "2026-10-27", "supplier_name": "Acme", "share_with_owner": false, "document_name": "inv.pdf", "amount": "1250.50"}],
  "cost_amount": "1250.50", "approved_amount": "1200.00"
}
```
  `amount`, `selling_amount`, invoice `amount`, `cost_amount`, `approved_amount` only with `rental_job_cards.view_costs`. Invoice documents have no download URL in the API (the web download is a signed-in web route) - see open issues.

## 10. POST `/mobile/properties/{property}/work-orders`  (`v1.mobile.properties.work-orders.store`)

Log a work order directly on a property. Calls `RentalWorkOrderService::report()`.

- Permission: `rental_work_orders.create`. JSON.
- Fields: `title` (required, max 191), `description` (required), `trade_type` (max 60), `priority` (`low \| normal \| urgent`), `lease_id` (a lease of THIS property), `reported_by_type` (`tenant \| agent_noticed \| owner_instructed`, default `agent_noticed`), `reported_by_contact_id`, `captured_at` (-> `reported_at`).
- Response **201**: work-order detail (section 9). The owner and tenant are mailed by the shared service exactly as the web form does.
- Errors: 403, 404 (property not visible / other agency), 410 `archived` (property archived), 422.
- **Online-only: no idempotency key** (spec section 5 lists fault reports, photos, notes and inspection writes as queued; a work order is raised by an agent with signal). A blind retry would create a second work order - do not queue this call.

## 11. POST `/mobile/work-orders/{workOrder}/photos`  (`v1.mobile.work-orders.photos.store`)

- Permission: `rental_work_orders.create`. Multipart.
- Fields: `photo` (required image, max 50 MB), `photo_type` (required `reported \| in_progress \| completed`), `client_idempotency_key` (uuid, **queue-safe**), `captured_at`.
- Response 201 / 200: `{"id": 8, "url": "https://...", "photo_type": "in_progress", "captured_at": "...", "client_idempotency_key": "...", "duplicate": false}`.
- Errors: 403/404, 409 `key_reused`, 410 `archived`, 422. A "completed" photo is what the completion gate counts when the agency requires one.

## 12. POST `/mobile/work-orders/{workOrder}/assign-supplier`  (`v1.mobile.work-orders.assign-supplier`)

Appoint the agency's contractor. Calls `RentalWorkOrderService::assignSupplierAndDispatch()` (extracted from the web controller in this commit; the web calls the same method): the model's gated assignment, the contractor's job link when it is a NEW contractor, and the work order PDF by email.

- Permission: `rental_work_orders.create`.
- Fields: `agency_service_provider_id` (required, a contractor of THIS agency), `trade_type` (optional).
- Response 200: work-order detail PLUS `"contractor_emailed": true|false` (false = the contractor has no email on file; tell the agent to send the PDF themselves).
- Errors: 422 model sentences ("This work order is already closed.", "This job has not been approved by the owner yet...", "The selected quote is from a different supplier..."), 410 `archived`. Online-only.

## 13. POST `/mobile/work-orders/{workOrder}/contractor-response`  (`v1.mobile.work-orders.contractor-response`)

The agent records the contractor's answer on their behalf (e.g. after a phone call). Calls `RentalContractorJobService::recordResponse()` - the call the contractor's own link makes - with the same refusals as that link.

- Permission: `rental_work_orders.create`.
- Fields: `response` (required `accepted \| declined`), `reason` (required when declined, max 1000).
- Response 200: work-order detail with `contractor_response`, `contractor_response_label`, `contractor_decline_reason`. The history gets a second line: "The contractor's answer was recorded by <agent> on the contractor's behalf."
- Errors: 422 "There is no agency contractor on this work order to record an answer for. Appoint one first." (no supplier, or an internal / owner-contractor job); "This job is already closed."; "The work has already started, so the job can no longer be declined." 410 `archived`. Online-only; "last answer wins" while the job is open, so a retry is harmless.
- `quote_uploaded` is NOT accepted here (a quote is a document the contractor uploads on their own link).

## 14. POST `/mobile/work-orders/{workOrder}/invoices`  (`v1.mobile.work-orders.invoices.store`)

File a supplier's invoice. Calls `RentalWorkOrderInvoiceService::upload()` - the web form's call.

- Permission: **`rental_work_orders.manage_invoices`**. Multipart.
- Fields: `invoice_number` (required, max 100), `invoice_date` (required, 2000-01-01 .. today), `amount` (required numeric, >= 0), `document` (required file; types / max size are the AGENCY's settings, default pdf and images), `agency_service_provider_id`, `supplier_name`, `share_with_owner` (bool).
- Response **201**: `{"id": 2, "invoice_number": "INV-1", "invoice_date": "2026-10-27", "supplier_name": "Acme", "share_with_owner": false, "document_name": "inv.pdf", "amount": "1250.50"}` (`amount` only with `rental_job_cards.view_costs`).
- Errors: 422 ("Invoice INV-1 is already filed against this work order." - the invoice-number rule is the natural duplicate guard, so a blind retry of a sent invoice is a harmless 422), 410 `archived`. **No idempotency key** (not in the spec's queued list).

## 15. POST `/mobile/work-orders/{workOrder}/complete`  (`v1.mobile.work-orders.complete`)

Close the work order. Calls `RentalWorkOrderService::completeByStaff()` (extracted from the web controller; the web calls the same method): findings + cause, every gate (cost authorised, final cost within the approval, completed photo, no open dispute, owner hold), then the "completed" alerts and the fault resolving itself.

- Permission: `rental_work_orders.complete`.
- Fields: `paid_by` (`owner \| tenant \| deposit_deduction \| not_yet_paid`; required unless the job came from a fault - then it follows the "who pays" ruling), `cost_amount` (numeric >= 0; REQUIRED for an outside contractor's job unless disputed), `completion_notes`, `found_text` (max 2000), `cause_id`, `cause_note` (max 500), `pending_client_items` (int >= 0).
- `pending_client_items`: how many photos / items the app still holds unsent for this work order. If > 0 -> **409** `{"message":"The app still has items waiting to be sent...","code":"items_pending"}` and nothing is closed (spec section 5.3).
- Response 200: work-order detail (`status: "completed"`).
- Errors: 422 model sentences ("This work order is already closed.", "At least one \"completed\" photo is required before this can be marked complete.", "Our own team's job is closed from its job card...", gates), 410 `archived`.

---

# Decisions made without asking (P2)

1. **Defaults the web form makes you choose:** mobile fault create defaults `reported_by_type=agent_noticed` and `reported_channel=app` (the web requires both); an agent in the field reporting a fault they noticed is the common case, and `app` is the channel value the spec reserved for it.
2. **`reference`** (`FR-<id>`, `WO-<id>`) is derived from the id - the system stores no separate reference. `q=FR-41` / `q=41` match it.
3. **Work-order `due` sort** has no due-date column to use; it sorts on the booked appointment (soonest first, unbooked last).
4. **Contractor response** is implemented because `RentalContractorJobService::recordResponse()` exists, but the web has NO staff screen for it - it is only reachable from the contractor's own link. The mobile route is therefore new behaviour that mirrors that link (same refusals) plus an audit line naming the agent. Johan should confirm he wants agents recording a contractor's answer for them; if not, drop the route (one line in `routes/api.php`).
5. **Invoices:** the API files an invoice; there is no download URL (the web download is a session route). Nothing in the spec asked for viewing the document on the phone.
6. **Archived reads:** an archived fault / work order still opens on its detail route (flagged `archived: true`) and is listed by `archived=1`, because the web allows opening it from the archived list. Writes against it are 410.
7. **Fault-photo and work-order-photo keys are globally unique** (existing DB constraint): the same uuid sent for a different record is a 409, not a silent duplicate.
8. **Web code touched (shared methods extracted, behaviour unchanged):** `RentalFaultReportService::storePhoto`, `RentalWorkOrderService::assignSupplierAndDispatch / completionRules / completeByStaff`, and an optional `$capturedAt` argument on `RentalWorkOrderService::storePhoto`. The web controllers (`RentalFaultReportController::storePhoto`, `RentalWorkOrderController::assignSupplier / complete`) now call them. Regression files run green: `WorkOrderCompletionAndDateTimeTest`, `ContractorJobDispatchTest`, `RentalFaultReportControllerTest`.
9. **Schema snapshot:** the new column (`rental_fault_reports.client_idempotency_key`, unique with `agency_id`; migration `2026_12_03_100000_add_client_idempotency_key_to_rental_fault_reports_at508.php`) is NOT yet in `database/schema/mysql-schema.sql` - same as P1, `php artisan schema:dump` + the DEFINER strip must be run at merge (NN 12a). Migrations apply on top of the snapshot, so tests are correct meanwhile.
10. **Not built (not in the 11 routes):** notes on a fault / work order (spec section 5 lists "notes" as queue-safe, but no notes route exists in section 7.1), start-progress, appointment, raise-work-order-from-fault, send-to-owner, cancel, archive / restore. Say which you want next.

---

# P3 - leases and notices to review (read-only)

The lease actions are NOT rebuilt: the app keeps using the existing `POST /api/v1/leases/{lease}/renewal/*` routes (draft, upload, month-to-month, tenant-notice, landlord-notice, notice/reverse, notice/change-outcome; permission `leases.renew`, notice ones also `rental_notices.review`), `GET /api/v1/leases/{lease}/signing` (signers, differences, confirm) and `GET /api/v1/leases/{lease}/tenancy-log`. The `can` block on the lease file says which of them the signed-in person may offer.

## 16. GET `/mobile/leases`  (`v1.mobile.leases.index`)

- Permission: `leases.view`. Scope: `Lease::scopeVisibleTo` (own = created it, or the lease's owner-side / tenant-side agent; branch; agency). Archived leases are not listed.
- Query:
  | param | meaning |
  |---|---|
  | `q` | search: property address, tenant name, owner (landlord) name, lease reference (`L-12` or `12`), the external id |
  | `sort` | `end_date` (default) \| `start_date` \| `property` \| `status` |
  | `direction` | `asc` (default, so the soonest-to-expire lease is first) \| `desc` |
  | `status` | `draft \| signed_awaiting_start \| active \| expired \| cancelled` (the web list's split of "draft") |
  | `expiring_within_days` | 1..3650: an ACTIVE lease whose end date falls between today and today + N |
  | `date_from`, `date_to` | on the END date (as the web list) |
  | `page` | 1-based |
- Response 200:
```json
{
  "data": [
    {
      "id": 5, "reference": "L-5", "status": "active", "status_label": "Active", "agreement_label": "Signed",
      "property": {"id": 9, "address": "12 Sea Road, Margate"},
      "start_date": "2026-04-25", "end_date": "2027-08-25", "is_month_to_month": false, "days_left": 304,
      "tenants": [{"contact_id": 31, "name": "Tina Test", "phone": "0821112222", "email": "tina@example.com", "is_primary": true}],
      "owners": [{"contact_id": 32, "name": "Lenny Test", "phone": null, "email": "lenny@example.com"}],
      "has_notice": false, "move_out_date": null
    }
  ],
  "meta": {"page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null}
}
```
  `days_left` is negative once the end date has passed and null for a month-to-month lease or one with no end date. `status_label` is the web's wording ("Signed - starts 1 Dec 2026", "Renewed", ...); `agreement_label` is null when no agreement was requested. **Rent and deposit are deliberately not in the payload.**
- Errors: 403 (no permission), 422 (unknown sort / status, `expiring_within_days` out of range, bad date).
- Empty reason: "No leases match. Clear the search or filters to see more."

## 17. GET `/mobile/leases/{lease}`  (`v1.mobile.leases.show`)

The lease file, built from the same `LeaseHubService` the web Lease Hub uses.

- Permission: `leases.view`. 404 other agency / unknown / archived; 403 same agency outside scope. Like the web screen it first reconciles an in-flight agreement with its e-sign envelope (a no-op for every other lease).
- Response 200 = the list item above PLUS:
```json
{
  "lease_type": "residential", "rollover": false,
  "owner_agent": {"id": 4, "name": "Agnes Agent"}, "tenant_agent": null,
  "signing": {"status": "signed", "label": "Signed", "signed_at": "2026-04-20T11:00:00+02:00", "locked": false,
              "signers": [{"role": "tenant", "name": "Tina Test", "status": "Signed", "email": "tina@example.com", "last_reminder": null}]},
  "documents": [{"id": 77, "name": "signed-lease.pdf", "mime": "application/pdf", "size": 1234, "kind": "signed_agreement", "added_at": "2026-04-20T11:05:00+02:00"}],
  "renewal": {"previous_lease_id": null, "renewed_lease_id": null, "has_renewal_in_progress": false, "renewal_signed_awaiting_start": false, "expiry_notice_sent_at": null},
  "notice": {"active": true, "given_by": "tenant", "notice_date": "2026-10-23", "move_out_date": "2026-11-30", "outcome": "readvertise", "note": null, "notices_to_review": 1},
  "lifecycle": [{"key": "application", "label": "Application", "state": "done"}],
  "next_step": {"label": "Review renewal"},
  "open_items": {"faults": 1, "work_orders": 0, "inspections": 0},
  "can": {"renew": true, "review_notices": true}
}
```
  - `signing.signers` is empty when no agreement was requested. `lifecycle[].state` is `done | current | pending` (keys: application, approved, lease_signed, in_inspection, tenancy, renewal_notice, out_inspection).
  - `documents` are metadata only (the signed agreement from e-sign and any signed paper copy; `kind` is `signed_agreement` for the one the lease points at). **There is no file URL** - see open issues.
  - `next_step` is the single next action the web hub offers, as a sentence (null when there is none). Web route names are not returned.
  - `notice` is the notice recorded ON the lease (set when a notice is accepted); `notices_to_review` counts notices still waiting for review on it (see section 18).

## 18. GET `/mobile/rental-notices`  (`v1.mobile.rental-notices.index`)

The notices a tenant or landlord has given (portal or recorded by an agent) that wait for the agent's review - the web "Notices > Received" list (`LeaseNotice`, `.ai/specs/lease-notices.md`). Read-only here: accepting / rejecting / correcting a notice is on the web (and the notice actions on the existing renewal routes).

- Permission: `rental_notices.review`. Scope: a notice is exactly as visible as its lease (`Lease::scopeVisibleTo`), applied in the query. Archived notices are not listed.
- Query: `q` (property address, tenant name, the name of whoever gave the notice, the reason), `sort` = `date` (default; the notice date, `direction` asc = **oldest unreviewed first**) \| `status`, `direction` = `asc` (default) \| `desc`, `status` = `received` (**default = still to review**) \| `accepted \| rejected \| withdrawn \| all`, `date_from`, `date_to` (on the notice date), `page`.
- Response 200:
```json
{
  "data": [
    {
      "id": 14, "status": "received", "status_label": "Needs review", "given_by": "tenant", "given_by_label": "The tenant", "given_by_name": "Tina Test", "via": "portal",
      "notice_date": "2026-10-22", "intended_end_date": "2026-12-05", "accepted_end_date": null, "reason": "Moving to Durban", "has_attachment": false,
      "decided_at": null, "age_days": 3, "lease_id": 5, "property": {"id": 9, "address": "12 Sea Road, Margate"}, "tenant_names": "Tina Test"
    }
  ],
  "meta": {"page": 1, "per_page": 25, "total": 1, "last_page": 1, "empty_reason": null}
}
```
  `age_days` = days since the notice date, only while it is still `received` (null otherwise). `given_by` is `tenant | landlord`. Open the matching lease with `lease_id`.
- Empty reason: default view "No notices are waiting for your review."; with a search / date range / other status "No notices match. Clear the search or filters to see more."
- Errors: 403 (no permission), 422 (unknown status / sort, bad date).

---

# Decisions made without asking (P3)

1. **"Notices to review" = `LeaseNotice`** (given by tenant / landlord, `rental_notices.review`), not `RentalNotice` (notices the AGENCY sends, permission `rental_notices.view`). The spec's wording ("notices waiting review", `rental_notices.review`, `LeaseNoticeService`) points to the first.
2. **Lease reference** (`L-<id>`) is derived from the id; the system stores no lease number.
3. **No rent, deposit or arrears** in the lease payload (spec section 3: rent/payments are out; section 4 P3 lists no money).
4. **Lease `direction` / notices `direction` parameter** added to the spec's sort options (asc/desc), because the defaults are opposite-looking (leases: soonest end first; notices: oldest first) and the app will want a toggle. Defaults are exactly the spec's.
5. **No `archived` parameter** on the three P3 lists (the spec's filter list for them has none; archived leases / notices are a web job).
6. **The lease file's GET reconciles an in-flight agreement** with its e-sign envelope exactly as the web `show()` does (a write on a read, by design, for in-flight agreements only).

# Open issues / for the conductor

1. **Documents have no download URL** (lease file) and invoices have none either (work order). The web serves them from session routes. If the app must open them, a separate, scoped download route is needed - not specced, not built.
2. **Notes** (spec section 5 lists them as queue-safe) have no route in the 11-route table, so none was built. Work-order create has no idempotency key (online-only).
3. **Contractor-response route** is new behaviour (agent answers for the contractor) - Johan to confirm (see P2 decision 4).
4. **`database/schema/mysql-schema.sql`** does not yet contain `rental_fault_reports.client_idempotency_key` (additive migration `2026_12_03_100000_*`); run `schema:dump` + the DEFINER strip at merge.
5. **`config/agency-onboarding-copy.php` / `MobileVisibilityController` / `MOBILE_APP.md`** were not touched (instructed). `MobileVisibilityController` should be taught the new screens (faults, work orders, leases, notices) before the app ships them.
6. **Seen, not fixed (outside scope):** `routes/api.php` has a stale comment above the P1 `mobile/rentals/waiting` route ("internal job-card mirror for Andre's app...") that actually describes the job-card group below it.


---

# APPENDIX B — Staff inspections, inspections due, inventories (exact contract)

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
