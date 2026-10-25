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
