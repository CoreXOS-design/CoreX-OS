# Spec — AT-508: Rentals in the mobile app (staff, owner, tenant)

> Status: APPROVED by Johan (25 Oct 2026) — built directly on Staging by his order. P1 built (see §15 build log).
> Branch: built on `Staging`. Nothing goes to live without Johan's explicit order for that exact action.
> Pillars: Property, Contact (lessor/lessee), Deal-adjacent (Lease), Agent.
> Consumer: Andre's mobile app. This spec defines the API the app calls; the app itself is not built here.

## 1. Goal

An agent, an owner (lessor) and a tenant (lessee) can do their rentals work from the phone:
report and track faults, approve or progress repairs, see a lease, and (staff) run move-in / move-out
inspections on site, including with no signal.

Principle (fault-work-orders spec §7): web and mobile are clients of ONE set of service methods. No
second implementation of any rule. Every new route calls the same service the web already calls.

## 2. What already exists (verified in routes, not assumed)

- Tenant + owner portal API under `/api/v1/client/rentals/*`: fault report/track/withdraw/add-note,
  work-order progress and completion answer, owner fault decision/query/stop/resume, variation decisions,
  appointment, fault settings, notices, leases, documents, inspections (dates), inventories. NOT rebuilt.
- Staff: `/api/v1/rentals/command-centre`, `/api/v1/rentals/reports/{key}`, `/api/v1/leases/*`
  (tenancy-log, capture, signing, confirm, renewal actions), `/api/v1/mobile/rental-job-cards/*`,
  `/api/v1/mobile/properties/{id}/rental-images/*`.
- Web has the complete inspection (rooms, observations, discrepancies, three-party signing, wet-ink,
  report, deposit comparison) but NO mobile API (`rental-inspections.md` §14.2 is specced, unbuilt).
- Web staff fault + work-order screens exist (`RentalFaultReportController`, `RentalWorkOrderController`
  and friends) but the staff mobile API in `rentals-faults-work-orders.md` §7.1 is unbuilt.

## 3. Scope — phases (each independently shippable to QA1)

| Phase | Deliverable | New API? |
|---|---|---|
| P1 | Push notifications + "waiting for you" count + fault progress line | Yes (device token, counts, timeline) |
| P2 | Staff fault + work-order API (`rentals-faults-work-orders.md` §7.1, all 11 routes) | Yes |
| P3 | Staff lease list + lease file + notices-to-review | Yes (list/search); actions reuse existing |
| P4 | Tenant/owner "My Lease" card endpoint + camera-photo + emergency flag on tenant fault | Small additions |
| P5 | Staff inspection API (`rental-inspections.md` §14.2) incl. offline-safe rules (§5 below) | Yes (largest) |
| P6 | Inspections-due list, inventories (staff) | Yes |
| Later (not in AT-508) | Rental applications, arrears/deposits, rent/payments view | — |

Rent/payment status is explicitly OUT (Johan: not doing it). "My Lease" shows lease facts only.

## 4. Endpoint contract (all under `/api/v1`, all named, all visible in Admin → API)

### P1
- `POST /mobile/devices` — register/refresh a push token (user or ClientUser). `DELETE /mobile/devices/{token}` — unregister.
- `GET /mobile/rentals/waiting` — counts + list of items waiting for ME (owner: decisions; staff: owner replies, notices to review, jobs awaiting action). Same data as the portal "decisions" list.
- `GET /client/rentals/fault-reports/{id}` and staff equivalent gain `timeline[]`: ordered steps (reported, owner decision, contractor booked, work done, confirmed) with timestamps. Derived from existing history; no new state.
- Notification events (via domain events catalogue, NN #9): fault needs owner decision; owner decided; contractor booked/appointment set; work finished; notice submitted/reviewed. Respect existing notification preferences; no new preference UI in this phase.

### P2 — exactly the table in `rentals-faults-work-orders.md` §7.1
`GET /mobile/rental-fault-types`, `POST /mobile/properties/{p}/fault-reports`, `POST /mobile/fault-reports/{f}/photos|approval|outcome`,
`POST /mobile/properties/{p}/work-orders`, `POST /mobile/work-orders/{w}/photos|assign-supplier|contractor-response|invoices|complete`.
Add list/detail: `GET /mobile/fault-reports`, `GET /mobile/fault-reports/{f}`, `GET /mobile/work-orders`, `GET /mobile/work-orders/{w}`.

### P3
`GET /mobile/leases` (list), `GET /mobile/leases/{l}` (lease file: dates, tenants, owner, status, documents, signing state, renewal/notice state — delegates to `LeaseHubService`),
`GET /mobile/rental-notices` (to review). Actions reuse existing `/leases/{l}/renewal/*`.

### P4
`GET /client/rentals/my-lease` (tenant) and `GET /client/rentals/landlord/my-lease` (owner, one block per property):
start, end, time left, renewal/notice status, notice period (nullable — not captured on every lease),
agent phone + email only (tenant side shows nothing about the lessor), next inspection date. Rent amount only if already returned by the existing overview; no payment status.
Tenant fault create accepts `urgent: bool` (emergency) which triggers the existing emergency handling (spec §6.5) and an immediate agent alert.

### P5 — per `rental-inspections.md` §14.2 table (checklist, start, list, detail, observations, observation photos, discrepancy resolve, signatures, complete). Shapes mirror model fillables; errors are `{"message"}` with 409/422/403 as §14.2.

### P6
`GET /mobile/rental-inspections/due`, `GET /mobile/rental-inventories` (+ detail), read plus photo add.

## 5. Offline / poor-signal rules (server side; the queue itself is the app's job)

1. Every write the app may queue carries `client_idempotency_key` (uuid, unique per agency+route). Same key replayed = same record returned, HTTP 200 with `duplicate: true`, never a second row. Applies to: fault reports, fault/work-order photos, notes, inspection start, observations, observation photos, signatures.
2. Late arrivals accepted: payload carries `captured_at`; server stores it and orders by it. Out-of-order arrival is fine.
3. Completion guard: `POST .../complete` accepts `pending_client_items` (int the app still holds). If > 0 → 409 `{"message":"...","code":"items_pending"}`. Server cannot know the app's queue, so the app must send it; the server also refuses completion if any referenced item/photo ID is unknown.
4. Photos after completion: existing behaviour (stored, flagged `added_after_signing`) is kept.
5. A retried request for a record archived meanwhile returns 410 `{"code":"archived"}` so the app can drop it from the queue instead of retrying forever.
6. `GET /mobile/rental-inspections/{id}` is the resync source after reinstall/new phone.

## 6. List-screen floor (BUILD_STANDARD §1a) for every new list endpoint

| Endpoint | Search fields | Default sort | Sort options | Filters | Page |
|---|---|---|---|---|---|
| fault-reports | property address, tenant name, fault type, reference | newest | newest, oldest, status, property | status, urgency, date range | 25, `page` |
| work-orders | property, contractor, reference | newest | newest, due, status | status, date range | 25 |
| leases | property address, tenant, owner, lease ref | end date asc | end date, start, property, status | status, expiring within N days, date range | 25 |
| rental-notices | property, party name | oldest unreviewed | date, status | status, date range | 25 |
| rental-inspections (+due) | property, tenant | due date asc | due, type, status | type (in/out), status, date range | 25 |

Empty list returns `data: []` plus `meta.empty_reason` text for the app's empty state. Archive/restore: no new hard-delete anywhere; fault withdraw and archive use existing soft-delete paths.

## 7. Scoping and permissions

- Staff routes: `AuthorizesRentalRecordScope` (own / branch / agency ceiling) in the controller, plus global `AgencyScope`. Direct-URL by ID blocked (404 other agency, 403 same agency outside scope) — same two-layer pattern as `MobileRentalJobCardController`.
- Existing permission keys reused: `rental_fault_reports.*`, `rental_work_orders.*`, `rental_job_cards.*`, `leases.view/create/renew`, `rental_notices.review`, `rental_inspections.*`, `rental_inventory.*`. Any key not present is added to `config/corex-permissions.php` with route middleware.
- Tenant/owner routes stay in the `/client/rentals` group (`portal.role`, `portal.same-login`, `portal.once`); tenant can never reach owner data.
- Cost fields (`total_amount`, unit prices) only with `rental_job_cards.view_costs`, as today.
- Throttle: `throttle:60,1` on writes; photo routes use existing named limiters.

## 8. Multi-agency (NN #9)

No HFC wording, branding, addresses or IDs anywhere. Push text, timeline labels, empty-state text and fault-type names come from agency settings or neutral defaults. All lists agency-scoped at query layer. Second-agency test: Cape Town (rentals-first) sees only its own faults, leases and inspections, with neutral wording.

## 9. Settings / wizard (NN #10a)

P1 adds no new setting. If Johan wants an agency toggle for push categories, that is a setting and must also reach `config/agency-onboarding-copy.php` — flagged for Johan, not assumed.

## 10. Domain events (NN #9)

Use existing fault/work-order/lease-notice events from `corex-domain-events-spec.md`; push is a new LISTENER on them, not a new hook. Any missing event is added to the catalogue in the same commit.

## 11. Acceptance criteria

- Each endpoint resolves (`route:list`), is named, appears at `/admin/api`.
- Per phase: one targeted feature test file via `scripts/lane-test.sh` covering success, scope (own/branch/agency/cross-agency), permission, validation, idempotent replay (§5.1), and 409 `items_pending` (P5).
- Web behaviour unchanged (regression: existing web tests for touched services still pass, run one file at a time).
- `mobile-app-access` / `MobileVisibilityController` descriptor updated so the app knows which rental screens the signed-in user may show.
- Spec files kept in sync in the same commit; `.ai/MOBILE_APP.md` gains the new contract; the mobile hand-off prompt is written to `.ai/specs/at-508-mobile-rentals-MOBILE-PROMPT.md` when P1–P6 are done.

## 12. Files (indicative; final list fixed at build)

New: `app/Http/Controllers/Api/Mobile/Rental*Controller.php` (faults, work orders, leases, notices, inspections, inventories, devices, waiting), `app/Listeners/Rentals/*Push*.php`, migration for `device_tokens` (agency_id, BelongsToAgency, soft-delete) and idempotency-key columns where missing, `tests/Feature/Api/MobileRentals*Test.php`.
Modify: `routes/api.php`, `MobileVisibilityController`, `config/corex-permissions.php` (only if a key is missing), `ClientTenantRentalsController` / `ClientLandlordRentalsController` (timeline, my-lease, urgent flag).

## 13. Johan's rulings (25 Oct 2026)

1. Push notifications: MOST ARE MANDATORY. Mandatory (cannot be switched off): anything needing the person's decision, anything urgent/emergency, appointments and work-finished confirmations, notice outcomes. Switchable per person: purely informational updates only (e.g. general status changes, reminders). Which exact types fall in each group is fixed in the P1 build and listed in the API so the app can show only the switchable ones. The switchable list is an agency-wide default plus a per-person override; the default reaches the Setup Wizard (NN #10a).
2. My Lease card, tenant side: the tenant sees the managing agent's PHONE and EMAIL only (no WhatsApp number) and NOTHING about the lessor: no name, contact, or details. The tenant payload must not carry any owner field. Owner side keeps seeing the tenant's name (existing behaviour).

## 14. Verified at build start (2026-10-25)

- Push infrastructure EXISTS for staff: `DeviceToken` model, `POST/DELETE /api/v1/device-tokens`, `PushNotificationService` (idempotency, rate cap, retry, dead-token pruning), `FcmService` transport. Tokens are keyed to `user_id` only.
- GAP: tenants and owners are `ClientUser`s, not `User`s, so they have no device-token path. P1 must add one for `ClientUser` (same service, same rules) rather than a second push system. P1 therefore does NOT build a sender; it extends the existing one and adds rental listeners.
- Portal sign-in issues a bearer token via `ClientAuthService` (createToken), so the app can use it as-is.
- Still to verify: whether rent amount is in the portal overview payload.

## 15. Build log — the contract as actually built

### P1 — push, "waiting for you", timeline (built 25 Oct 2026)

**Correction to §4 P1:** the device-token routes are `POST /api/v1/client/device-tokens` and `DELETE /api/v1/client/device-tokens/{token}` (tenant / owner, client token) next to the existing staff `POST/DELETE /api/v1/device-tokens`. There is no `/mobile/devices`.

| Method + path | Auth | Purpose |
|---|---|---|
| `GET /api/v1/mobile/rentals/waiting` | staff, `rental_command_centre.view` | the command centre's action queue flattened: `waiting_count`, `by_type`, `data[]` (`type,label,detail,age_days,property{id,address},lease_id,subject_type,subject_id`), `meta{page,per_page,total,empty_reason}`; 25/page via `?page=` |
| `GET /api/v1/client/rentals/waiting` | tenant | finished work awaiting their check: `waiting_count`, `items[]` |
| `GET /api/v1/client/rentals/landlord/waiting` | owner | decisions waiting (faults, work orders, variations) — same lists as `landlord/decisions` |
| `GET/PUT /api/v1/client/rentals/push-preferences` | tenant | the SWITCHABLE notifications only: `switchable[{key,label,description,enabled,default}]`; PUT `{preferences:{key:bool}}` |
| `GET/PUT /api/v1/client/rentals/landlord/push-preferences` | owner | same, owner list |

Push categories (`config/rental-push.php`; `RentalClientPushService`):
- **Mandatory (never listed, never switchable; PUT → 422 `not_switchable`):** `decision_needed` (owner), `work_check_requested` (tenant), `work_finished` (owner), `appointment` (tenant+owner), `notice_outcome` (tenant+owner). Emergency/urgent fault alert is added in P4.
- **Switchable:** `fault_status` (tenant), `work_order_update` (owner). Resolution: the person's own choice, else the agency default `rental_portal_settings.push_informational_default` (on if never set; Rental portal settings page + Setup Wizard step, NN #10a).
- Each fact is pushed once per phone (cache claim 30 days, plus the PushNotificationService idempotency/rate cap). Tenants' payloads never name or describe the lessor.
- Staff phones are NOT changed: they are already served by `NotificationDispatcher` through each person's own alert settings (`rental_fault_report.*`, `rental_work_order.*`, `lease_notice.received`).
- New domain events (catalogue updated): `RentalOwnerDecisionNeeded`, `RentalTenantFaultStatusChanged`, `RentalAppointmentSet`, `LeaseNoticeReviewed`; existing `RentalWorkReportedDone` also feeds push. Listener: `SendRentalClientPush`.

Timeline: tenant `GET /client/rentals/fault-reports/{id}` and owner `GET /client/rentals/landlord/fault-reports/{id}` carry `timeline[{key,label,state,current,at}]` (flat copy of the existing `progress.steps`, no new state). Staff gets the same in P2.

Tests: `tests/Feature/Api/MobileRentalsP1Test.php` (9), `ClientDeviceTokenTest` (4).

### P4 — My Lease, urgent flag, queued-write rules for tenants/owners (built 25 Oct 2026)

| Method + path | Auth | Purpose |
|---|---|---|
| `GET /api/v1/client/rentals/my-lease` | tenant | `leases[]`, one card per property the tenant rents |
| `GET /api/v1/client/rentals/landlord/my-lease` | owner | `properties[]`, one card per property the owner has (`lease` / `status` are `null` while it has no lease); also carries `tenant_names` |

Card fields: `property{id,address}`, `lease{id,start_date,end_date,is_month_to_month,days_left,time_left{days,text}}`, `status{state,headline,detail,in_renewal_window}`, `notice_period{value,unit}|null` (the lease's OWN confirmed term — null when none, never a guess), `notice{date,move_out_date,given_by}|null`, `renewal|null`, `agent{name,phone,email}|null` (+ `office{phone,email}` only when no agent qualifies), `next_inspection{id,type,type_label,date,time}|null`.
- **Tenant privacy (Johan's ruling):** the tenant card has no owner/landlord/lessor key or value, no WhatsApp number, no rent/deposit/payment fields; a notice given by the owner reads "Notice has been given" and `given_by: "other_party"`.
- **Rent amount:** NOT returned. The portal overview does not carry it (verified); the older `GET /client/rentals/leases/{id}` does (see "found outside scope" in the hand-off).
- `X-Portal-Role: owner|tenant` (optional) makes a call for the other side a 403 `role_mismatch`; the app sends no header — a login with no tenancy simply gets empty lists.
- **Urgent:** `POST /client/rentals/properties/{p}/fault-reports` accepts `urgent: bool` (only with `resolution: still_a_problem`). Stored on `rental_fault_reports.reported_urgent` (new column); the fault is then an emergency for the list badge and the agent alert ("EMERGENCY fault reported", elevated severity) — fault type, approval route and spend rules are untouched. The fault summary returns `urgent`.
- **Offline:** the same call (and every tenant/owner write) accepts `client_idempotency_key` as a field or `X-Client-Idempotency-Key` header (also the older `X-Submission-Key`); a replay answers HTTP 200 with the original body plus `duplicate: true`, never a second record. `captured_at` (ISO8601, never in the future) is stored as the fault's `reported_at`. A queued note / withdraw for a fault that was withdrawn or archived answers 410 `{"code":"archived"}`.
- **Camera / HEIC:** fault photos accept jpg, png, webp and HEIC/HEIF (converted to JPEG by `HeicConverter`); covered by `AuditP3PortalTest`.

Tests: `tests/Feature/Api/MobileRentalsP4Test.php` (8).

### P2 + P3 — staff faults, work orders, leases, notices (built 25 Oct 2026)
Full contract: `.ai/specs/at-508-P2P3-build-notes.md` (also Appendix A of `at-508-mobile-rentals-MOBILE-PROMPT.md`). 15 routes `v1.mobile.*` (fault types, fault list/detail/create/photos/approval/outcome, work-order list/detail/create/photos/assign-supplier/contractor-response/invoices/complete) + `mobile/leases`, `mobile/leases/{l}`, `mobile/rental-notices`. Web logic shared, not copied: photo store, appoint-contractor and complete moved into `RentalFaultReportService::storePhoto`, `RentalWorkOrderService::assignSupplierAndDispatch / completionRules / completeByStaff`. New column `rental_fault_reports.client_idempotency_key`. Decisions: contractor-response is staff-recorded using the contractor link's own rules (Johan to confirm); no money on leases; refs FR-/WO-/L-<id>; WO "due" sort = appointment. Not built: staff fault notes, WO-create idempotency, document downloads. Tests: MobileRentalsP2Test (26), MobileRentalsP3Test (9).

### P5 + P6 — staff inspections, due list, inventories (built 25 Oct 2026)
Full contract: `.ai/specs/at-508-P5P6-build-notes.md` (Appendix B of the mobile prompt). 16 routes `v1.mobile.rental-inspections.*` / `rental-inventories.*`: checklist, list, due, start, detail (full resync), observations, observation photos, room notes, overall notes, attendance (added — the web refuses completion without it), discrepancy resolve, signatures, complete (`pending_client_items` → 409 `items_pending`; unknown ids → 422 `unknown_items`), inventories list/detail/photo. New column `rental_inspections.client_idempotency_key`. `client_idempotency_key` is REQUIRED on mobile queueable writes. Not built: add room/item to checklist, header details, unattached photos, lifecycle actions, inventory create/sign/complete. Tests: MobileRentalsP5Test (29), MobileRentalsP6Test (11).

### Found outside scope (reported, not changed)
- `GET /client/rentals/leases/{lease}` (ClientTenantRentalsController::leaseShow) returns `landlord_names`, `rent_amount`, `deposit_amount` to a tenant — conflicts with Johan's rulings; My Lease does not use it.
- `RentalInventoryCaptureController.php:243`: web photo replay lookup is per inventory but the unique index is on the key alone — same key to another inventory = 500 on the web.
- Web `markRoomNa`, `updateOverallNotes`, `resolveDiscrepancy` check "not recordable" before the scope guard (masked by route binding).
- A stale comment above the P1 `mobile/rentals/waiting` route in routes/api.php.

### Client view of the move-in / move-out inspection (Johan, 25 Oct 2026)
`GET /api/v1/client/rentals/inspections/{id}` (tenant) and `GET /api/v1/client/rentals/landlord/inspections/{id}` (owner): read-only, ONLY an inspection the portal already lists as SENT (completed, or a copy logged as sent); a booked date, a draft, someone else's, or the other side's route is a 404. Payload (`RentalPortalInspectionViewService`): `id,type,type_label,status,date,completed_at,property{id,address},header{meters,furnished,keys,remotes},overall_notes,rooms[{id,label,note,items[{id,label,condition,notes,photos[{id,url,taken_at,added_after_signing}],move_in?{condition,notes}}],photos[]}],meters[],other_items[],signatures,related{id,type,type_label}|null`. A move-out shows each item's `move_in` condition when the move-in was also sent; `related` points to the other half of the pair. Tenant `signatures` = `{you_signed, agent_signed}` (nothing about the lessor); owner adds `tenant_signed`. No discrepancies, attendance, user ids or storage paths. The overview/inspections list rows gain `viewable: true|false`. Photo urls are signed, expiring. Test: `MobileRentalsClientInspectionTest` (4).

### Rentals only for lessees and lessors (Johan, 26 Oct 2026)

**Rule (Johan):** a client sees the rental screens only when they are a **Lessee** (tenant) or a **Lessor** (owner) **and linked to a rental**; an agent sees them only when Rentals is on for them. Everyone else sees no rental screens, tabs or tiles at all.

**Client app** — `GET /api/v1/client/me` now carries `rentals: { tenant: bool, owner: bool }` (agency = the sign-in's current agency).
- `tenant` is true only when the login has a contact in that agency that is **linked to a rental as a tenant** (on a lease); `owner` only when a contact is **linked to a property as its landlord / lessor**. Johan, 26 Oct 2026: "when a user is marked as a lessee they need to be linked to a rental to see all the rental info; if they are not linked to a property they shouldn't see anything". So the **Contact Type Lessee / Lessor alone grants nothing** — a person marked Lessee or Lessor but linked to no rental gets both flags false. Rule lives in `PortalIdentityService::holds()` (one place; `/client/me` side contacts, the portal header and call-time contact choice all use it).
- AND the agency has Rentals on (feature `rentals`) AND that side's portal switch on (`RentalPortalSetting` tenant/landlord) — `RentalPortalAccessService::enabledFor()`.
- Both false = a buyer/seller/anyone else: the app shows no Rentals anywhere. Both true = a person who is both: two views.
- Server-side nothing else changed: every rentals call was already limited to the person's own leases/properties (a non-party only ever got empty lists), and the agency switches already answered 403.

**Agent app** — `GET /api/v1/mobile/visibility` now carries `rentals_enabled: bool` beside the existing `rentals{screen: {can_view, can_act, scope}}` map. True = the agency has Rentals on AND the person may view at least one rental screen (their role's rental permissions). The per-screen `can_view` now also respects the agency's Rentals switch (it previously looked at permissions only, so an agency with Rentals off still told its agents they could see rental screens; the web menu and the permission middleware already honoured the switch).
- The old per-user "Can Capture Rentals" tick on the user form is retired (AT-439, gates nothing) and is NOT the switch; a person's rental access is their role's rental permissions.

Multi-agency: nothing here names an agency; all four inputs (feature, portal switch, contact type, permissions) are per-agency data.
Tests: `tests/Feature/Api/MobileRentalsOnlyForPartiesTest.php`.

### Inspection photos and typed inventory lines (Johan, 26 Oct 2026)

Johan: inspection images must flow both ways between web and app; the inventory on the app must let you type, not only upload photos; the app needs a move-out inspection section; lessee and lessor must see the inspection and move-out photos.

Verified first (Staging, 26 Oct): photo links the API hands out load (HTTP 200, image/jpeg); the detail returned all photos; but the app only ever *read* (no photo POST reached the server that day) and never opened the tenant/owner inspection view. Server gaps found and closed:

| Method + path (`v1.mobile.…`) | Purpose |
|---|---|
| `POST /mobile/rental-inspections/{id}/photos` (`rental-inspections.photos.store`) | camera on an item (`item_id`), a room (`room_id`) or the whole inspection (neither = loose in the tray). Multipart `photo`, required `client_idempotency_key`, optional `captured_at`. Wraps the web's `RentalInspectionRecordingController::storePhotos` — an item photo creates the item's pending observation on the spot (no condition needed first), exactly as the web does. 201 `duplicate:false` + photo (+ `observation` when one is filed); replay 200 `duplicate:true`; archived 410. Before this the phone could only attach a photo to an observation it had already created. |
| `POST /mobile/rental-inventories/{id}/lines` (`rental-inventories.lines.store`) | type a line: `description` (required), `property_room_id` or `room_label` (one required), `quantity`, `condition_key`. Required `client_idempotency_key` (replay = 200 `duplicate:true`, held 30 days in cache — `rental_inventory_lines` has no key column, no migration). Completed / cancelled inventory → 409. |
| `PUT /mobile/rental-inventories/{id}/lines/{line}` (`…lines.update`) | change room / description / quantity / condition (draft only). |
| `POST /mobile/rental-inventories/{id}/lines/{line}/retire` (`…lines.retire`) | remove a line (kept, never deleted); repeating answers `duplicate:true`. |

All four call the same web action as the office screen and sit behind `rental_inspections.create` / `rental_inventories.create`; scoping as the rest of P5/P6 (404 other agency, 403 outside own/branch). Every inspection photo payload (detail `photos[]`, `observations[].photos[]`, upload responses) now carries `item_id` (null for a room-only or loose photo) beside `room_id` / `observation_id`, so the app can place web-uploaded tray and room photos, which have no observation.

Move-out: the server already starts, lists (`type=out`), schedules (`/due`) and records a move-out (`type: "out"`) identically to a move-in; the missing "Move-out" section is the app's (see the mobile prompt). Tenant/owner viewing of sent move-in / move-out inspections and photos is the existing `GET /client/rentals/{landlord/}inspections/{id}` (§ "Client view of the move-in / move-out inspection"); the app had not yet opened it.

Tests: `tests/Feature/Api/MobileInspectionPhotosAndInventoryLinesTest.php`.
