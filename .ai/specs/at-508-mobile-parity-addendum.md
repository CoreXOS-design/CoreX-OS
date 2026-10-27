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

## 3. Tests

- `tests/Feature/Api/MobileInspectionLifecycleTest.php`
- `tests/Feature/Api/MobileInventoryLifecycleTest.php`

Each proves: the action works through the phone's bearer token; the web's refusals pass through; another agency is `404`; a viewer is `403`; an archived record is `410`.

## 4. Not in this addendum (Johan's 27 Oct instruction: nothing new)

Not built, because they do not exist on the web today: staff screens for rent / deposit / lease fee by role; any notice screen without a Reject; a staff "record the contractor's answer" screen with a mandatory note on the web; CPA-versus-business classification of a notice.
