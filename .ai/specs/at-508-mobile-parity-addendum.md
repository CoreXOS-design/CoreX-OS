# Mobile rentals parity addendum (27 Oct 2026)

> Companion to `at-508-mobile-rentals.md` and its two build-notes files. Johan, 27 Oct 2026: the phone must do what the web does today. **Scope rule (his words, in substance): only what is live on the web right now. Nothing added. No role-manager / permission work.** This file lists the extra staff endpoints that make that so, family by family. It is the contract the mobile app builds to.

## 0. Rules that never change

1. **No new rule is decided on the phone side of the server.** Every endpoint either (a) validates the web action's own fields and calls the SAME model method, or (b) is routed straight at the web controller action that already answers JSON. If a web action answers a redirect, a wrapper calls its model method.
2. **Permission keys are the web's own, route for route.** Nothing new was added to `config/corex-permissions.php`.
3. Scoping is the same two layers as every other mobile rentals route: another agency's id is `404`; a same-agency id outside the user's own / branch ceiling is `403`; an archived record is `410 {"code":"archived"}` (except restore).
4. Refusals keep the web's own message. A model refusal comes back as `409 {message, code}`; a bad request is `422` with the web's own validation fields.
5. These actions are **not queueable** (no idempotency key): they are deliberate office-style actions that need a line. The model refuses a repeat itself (second cancel, second start).
6. All routes are under `/api/v1/mobile/...`, named `v1.mobile.*`, and appear in the Admin → API catalogue.

## 1. Inspections — `MobileRentalInspectionLifecycleController` + direct routes

Base: `/api/v1/mobile/rental-inspections` (group permission `rental_inspections.view`).

| Method + path | What the web does | Permission | Notes |
|---|---|---|---|
| `POST /schedule` | Schedule button on the New inspection form | `.create` | body: `lease_id`, `type`, `scheduled_for`, `scheduled_time`, `scheduled_duration_minutes`, `inspector_user_id`, `schedule_note`, `planned_date_id`. 201 + the full detail + `notice_warning` (agency minimum-notice note; never blocks) |
| `POST /{id}/reschedule` | Reschedule | `.reschedule` | old values kept as history; parties re-notified by the model |
| `POST /{id}/cancel` | Cancel | `.cancel` | `cancel_reason` required; `409 not_recordable` if it can't be |
| `DELETE /{id}` | Archive | `.archive` | a completed or signed one needs `.archive_completed` else `403 needs_manager` |
| `POST /{id}/restore` | Restore | `.restore` | `409 cannot_restore` if something started after it |
| `POST /{id}/next` | Next inspection (chain) | `.create` | web action; may answer the "start anyway" question (`confirm_stack`) |
| `POST /{id}/replace` | Start new inspection for a sent report | `.create` | 201 + detail of the new one |
| `POST /{id}/public-link`, `DELETE …` | Generate / revoke the read-only report link | `.public_link` | `409 cancelled` for a cancelled inspection |
| `POST /{id}/ready-to-sign` | Ready to sign | `.create` | web guards (unresolved discrepancy, unrecorded items, attendance, empty checklist) come back as 409 |
| `POST /{id}/details` | Header block (meters, furnished, type, keys, remotes, move-in date) | `.edit_details` | |
| `POST /{id}/rooms/{room}/mark-na`, `/mark-good`; `POST /{id}/mark-all-good` | Room N/A, room All Good, whole-inspection All Good | `.create` | |
| `POST /{id}/photos/tag-bulk`; `POST /{id}/photos/{photo}/tag`, `/untag`; `DELETE /{id}/photos/{photo}` | Tag / untag / archive a photo | `.create` | locked `409 signed_locked` once signed |
| `POST|PATCH|DELETE /{id}/photos/{photo}/notes[/{note}]`, `POST …/restore` | Photo notes | `.create` | read-only once signed |
| `POST /{id}/attendance/{attendance}/withdraw`; `POST /{id}/attendance-invitations` | Withdraw an attendee; record invitations given | `.record_attendance` | |
| `GET /{id}/signing-links`; `POST …` (issue), `POST …/send-all`, `POST …/{link}/email`, `POST …/{link}/revoke`, `GET …/{link}/qr`, `POST …/{link}/device` | Personal signing links, email / WhatsApp / QR, and "Sign now on this device" | `.public_link` to issue / send-all; `.create` for device | party reads the report, then posts the signature to `…/{link}/device` (typed name, drawn signature, read-confirmed, comment — the web's own fields) |
| `POST /{id}/signatures/{signature}/supersede-wet-ink` | Replace a bad paper scan | `.create` | |
| `GET /{id}/report`, `GET /{id}/print-for-signature` | The agent's PDF; the paper form to print for signature | view | PDF download. The DRAFT stamp and "downloads only once everyone has signed" rules are the web's |
| `POST /{id}/resend-report` | Resend the completed report | `.create` | |

**Deliberately NOT exposed (not live-for-phone by Johan's ruling):** Edit report (reopen) — office only. Not wrapped yet, because the web only offers them on screens the phone has no place for: the paper site-form scan upload and review, checklist editing / standard-checklist seeding, planned-date management, follow-up from an item, the move-out deposit comparison findings. They are listed in the audit as still to do.

## 2. Inventories — `MobileRentalInventoryLifecycleController` + direct routes

| Method + path | What the web does | Permission |
|---|---|---|
| `POST /mobile/properties/{property}/inventory` | Start the property's inventory (a second tap opens the same one) | `rental_inventories.create` |
| `POST /mobile/rental-inventories/{id}/cancel` (`cancel_reason` required), `DELETE /{id}`, `POST /{id}/restore` | Cancel, archive, restore | `.create` |
| `GET /{id}/comparison` | Move-out comparison, read-only | view (only once completed; `409 not_completed` / `no_lease`) |
| `POST /{id}/move-out/complete`, `POST /{id}/move-out/sign-off` | Complete the move-out check; tenant / landlord / agent signed or refused with a signed name | `.create` |
| `POST|DELETE /{id}/rooms/{room}/mark-empty`; `POST /{id}/copy-from-last` | "Nothing in this room" and undo; copy the last inventory | `.create` |
| `DELETE /{id}/photos/{photo}`; `GET|POST /{id}/inspection-photos[/{photo}]`; `POST|DELETE /{id}/lines/{line}/photos/{photo}` | Archive a photo; share the inspection's room photos by reference; tag / untag a photo to a line | `.create` |
| `POST /{id}/lines/{line}/dispositions`; `POST …/move-out-photos`; `GET|POST …/move-out-inspection-photos[/{photo}]` | Move-out finding per line, move-out photos | `.create` |
| `POST /{id}/signatures`, `POST …/{signature}/supersede-wet-ink`, `POST /{id}/complete`, `POST /{id}/resend-report` | Sign (tenant / landlord / seller / agent; signed, refused, wet-ink, awaiting wet-ink), complete, resend | `.create` |
| `GET /{id}/report`, `GET /{id}/print-for-signature` | PDFs | view |
| `POST /{id}/buyer-acceptances/{contact}/send` | Email a buyer their acceptance link | `.create` |

The web's completion rules apply unchanged: a draft only is editable; completing is refused while a room is unvisited, a party has neither signed nor refused, anyone awaits a wet-ink scan, or the agent has not signed.

## 3. Leases, notices, breaches, rental applications

Two mechanisms, both "the web action itself":

- **`MobileRentalLeaseActionsController`** (`RunsWebAction`): runs the web controller's action in-process and answers its outcome as JSON, merging the lease as the phone's lease file shows it (`lease`). Routes under `/api/v1/mobile/leases/{lease}/...`: `PUT /` (edit), `PUT /agents`, `PUT /notice-terms`, `POST /notice-terms/confirm`, `POST /expiry-notice/send`, `POST /expiry-notice/mark-given`, `POST /activate`, `POST /cancel` (`cancel_reason`), `POST /escalate`, `POST /archive` (`archive_reason`), `POST /restore`, `POST /run-date-checks`, `POST /signing/prepare`, `/signing/prepare-again`, `/signing/signed-copy`, `POST /renewal/cancel-draft`, `POST /breaches`, `POST /notice-cases` (the agent records a notice given), `POST /notices` (send a templated notice). Plus `POST /mobile/rental-notices/{notice}/accept` (accept a notice, with the correcting date, note and the three-way outcome).
- **`mobile.web-action` middleware** (`App\Http\Middleware\MobileWebAction`): used where a mobile route points *straight* at the web action (so route binding, form requests, guards and permissions are the web's own). It marks the request as wanting JSON, then turns a redirect into JSON: a flashed error or error bag -> `409 {ok:false, message, errors, code:"refused"}`; any other redirect -> `200 {ok:true, message, warning, redirect}` where `redirect` is the web path it would have opened (how the app learns the id of a record it just created).

Generated this way from the web route table, with each route's own permission keys copied across: faults (edit, archive, restore, cancel, owner decision / questions / version, send to owner / inspect / authorise, raise work order, reclassify, repair anyway, resume, tenant confirmation, photo marks and notes), work orders (edit, archive, restore, access contact, appointment, approval, cancel, completion rounds, contractor appoint / done / resend / WhatsApp, cost authorise / owner decision / same call, dispute seen, emergency approval void, external fee, invoices edit / archive / restore / share, notes, owner contractor, quotes add / edit / archive / restore / select, send back, start progress, variations decide / resend, who pays), job cards (create, archive, restore, agent / worker sign-off, assign crew, cancel, complete, crew lines accept / reject, crew link issue / revoke / email / send, lines, mark-up, price requests, schedule, send quote, signed copy, start, tasks, tenant confirm), the rest of the inspection screen (planned dates add / edit / skip / reopen / archive / restore, print-for-signature with a link, resend to one recipient, the paper site form scan upload / apply / delete, the move-out comparison finding, follow-up fault reports, checklist items add / rename / retire / restore / retype / reorder, rooms reorder / default order / add missing standard items, build from advertising / the standard checklist, photo pairing / auto-pair / unpair), breaches (bulk send, remedy, vacate, archive, restore), notices sent (resend, archive, restore), notices received (edit, archive, restore), the remaining lease actions (portal access setup / invite / switch, co-applicant contact, deposit deductions reviewed), and **rental applications: create the contact (quick-create), create the application, send it, share the link, re-issue the link — nothing else** (Johan: reviewing documents and authorising stay on the web).

**Never routed to the phone:** *Edit report* (reopen) on an inspection; *Reject* on a received notice (Johan: an agency can only act on a notice). Both are asserted absent in the tests.

Responses: `422 {message, errors, code:"invalid"}` for the web's validation; `403/404/410` for permission, scope, archived; `409 {message, code:"refused"}` for a business refusal in the web's own words.

**Answers the app should know about (found by the mobile app's code review, 27 Oct):**
- `POST /mobile/breaches/bulk/send` answers `{ok, batch, issued:[{id,lease_id,tenant,property,amount,remedy_deadline}], skipped:[{lease_id,reason}], ignored}` (the web answers a result page; this is the same data).
- `POST /mobile/rental-applications/{id}/share-link` answers `link:{url, expires}`; `POST .../reissue-link` answers `link:{url, code, expires}`. The re-issue code is shown **once** and never stored in clear: show it to the agent straight away.
- A create that redirects to a *parent* page (a notice, a breach, a quote, a task, a planned date) cannot say the new record's id; the answer's `redirect` is the parent. Re-read the parent to get the new row. An application's `redirect` does carry its id.
- Where the web creates a record and then flashes an error because an email only partly went out (breach notice, templated notice, notice to vacate), the answer is `409 refused` **although the record exists**. Re-read before you retry, or you will make a duplicate.
- `GET /mobile/rental-suppliers` lists maintenance contractors only (attorneys, conveyancers and bond originators are never offered for a work order, as on the web).
- `GET /mobile/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items` names the room.

## 4. Extra reads the web screens have

| Method + path | What it is | Permission |
|---|---|---|
| `GET /mobile/rental-job-cards` | The web job-card list: `q`, `status`, `rental_crew_id`, `property_id`, `date_from`, `date_to`, `overdue`, `needs_pricing`, `awaiting_owner`, `variation_pending`, `archived`, `scope`, `sort`, `direction`, `page`. It *is* `RentalJobCardListQuery`, the object the web list and print-out share. Rows carry no money. | `rental_job_cards.view` |
| `GET /mobile/rental-suppliers` | The contractors an agent picks from to assign a work order (`agency_service_provider_id` for `POST /mobile/work-orders/{id}/assign-supplier`): `q`, `kind`, `page`; active only, preferred first | `rental_work_orders.view` |
| `GET /mobile/properties/{property}/rental-inspection-tab` | The web inspection tab's data, including the photo pairing and compare data | `rental_inspections.view` |
| `GET /mobile/properties/{property}/rental-inspection-rooms/missing-standard-items` | Standard items a room is missing | `rental_inspections.view` |
| `GET /mobile/rental-inspections/{id}/attendance`, `/form`, `/signatures/{signature}/{kind}` | Who attended; the printable paper site form; a signature or wet-ink scan file | `rental_inspections.view` |

## 5. Tests

- `tests/Feature/Api/MobileInspectionLifecycleTest.php`, `MobileInventoryLifecycleTest.php`, `MobileLeaseActionsTest.php`
- `tests/Feature/Api/MobileMaintenanceActionsTest.php`, `MobileInspectionWebActionsTest.php`, `MobileNoticesAndApplicationsTest.php` — each checks *the whole generated set*: every route carries the web's permission keys, a viewer is refused on every route that needs more than view, another agency gets `404` on every route that names a record, and the forbidden routes (Edit report, Reject, application review) do not exist.
- `tests/Feature/Api/MobileMaintenanceReadsTest.php`

## 6. Not in this addendum (Johan's 27 Oct instruction: nothing new)

Not built, because they do not exist on the web today: staff screens for rent / deposit / lease fee by role; a notice screen without a Reject; a staff "record the contractor's answer" screen with a mandatory note on the web; CPA-versus-business classification of a notice. Still to do (web reads that are Blade views with no JSON twin, so they need a read wrapper first): the breach register list, the notices-sent list, the rental-applications list, the move-out deposit comparison data for an inspection, and the lease "who signs" party check. These are the next slice.
