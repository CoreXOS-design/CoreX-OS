# Title Deed Register (AT-450)

> Status: BUILT on QA1 only (2026-10-12/13). Johan approved the design. Not on Staging, not on live.
> Investigation: `/tmp/title-deed-register-investigation-2026-10-12.md` (why a sibling register and not the Filing Register).
> Pillars: Property (the deed is for a property) x Contact (the owners) x Agent (the responsible agent).

## 1. What it is and why

After a sale registers, the attorneys send some title deeds to the agency. The agency keeps each deed at the office until the
owner collects it. This register is the agency's record of which deeds are on hand and exactly when, by whom, and on what proof
each one was handed over.

It is a SIBLING of the Filing Register (which is a mandate paperwork index). Nothing in the Filing Register is changed.

## 2. Status

`Received` -> `Owner notified` -> `Collected`, plus `Archived` (soft delete). Status only changes through the actions below
(never by editing a field).

- Received: the deed is logged.
- Owner notified: the first owner notice has been sent (an email accepted by the mail system, or a WhatsApp a staff member
  opened and then CONFIRMED as sent - section 14.7). It never goes back.
- Collected: a handover completed. The record is then read-only except notes and archive.

## 3. Data model

| Table | Purpose |
|---|---|
| `title_deeds` | the record. agency_id, branch_id, agent_id (responsible), created_by, property_id (+ `property_address` snapshot), attorney_firm, received_at, storage_location, notes, status, notified_at, collected_at, collected_by_name, collected_method, scan_document_id, PIN state (pin_version, pin_encrypted, pin_issued_at, pin_failed_attempts, pin_locked_at), reminder state (last_reminder_at, reminder_count), soft deletes |
| `title_deed_owners` | one row per owner: contact_id + `name_snapshot`; soft deletes; unique (title_deed_id, contact_id) |
| `title_deed_events` | the notification ledger: one row per owner per channel per send (kind, channel, destination_masked, status, error, user). `payload_encrypted` holds a prepared WhatsApp consent message (it carries the owner's link) until the staff member confirms the send, then it is cleared |
| `title_deed_audit_log` | the audit trail shown on the record (inspection audit-log pattern) |
| `title_deed_consents` | an owner-consent request (link) or a recorded phone consent, for ONE named representative |
| `title_deed_consent_links` | one tokenised link per owner per consent request (token stored hashed) |
| `title_deed_receipts` | the handover record, ONE per deed (unique), signature + evidence |
| `title_deed_settings` | the agency settings (section 9) |

Reference data (migration backfill, global): document type `title_deed_copy` ("Title deed (copy)"), notification event type
`title_deed.collected`; permission keys come from `config/corex-permissions.php` through `corex:sync-permissions --merge-defaults`
(the normal `deploy:sync-reference-data` path).

Schema contract: every NOT NULL column is supplied by the code on every path (see the services).

## 4. Screens, routes, permissions

Sidebar: Tools, directly under Filing Register, label "Title Deeds" (`@feature('title-deeds')`, `access_title_deeds`).

| Route (prefix `/title-deeds`, name `title-deeds.*`) | Permission |
|---|---|
| `GET /` index, `GET /export` CSV | `access_title_deeds` + `title_deeds.view` |
| `GET /create`, `POST /` store | `title_deeds.create` |
| `GET /{id}` show | `title_deeds.view` |
| `GET /{id}/edit`, `PUT /{id}` update | `title_deeds.edit` |
| `DELETE /{id}` archive, `POST /{id}/restore` | `title_deeds.archive` |
| `POST /{id}/scan`, `GET /{id}/scan` | upload: `title_deeds.edit`; download: `title_deeds.view` |
| `POST /{id}/notify`, `POST /{id}/events/{event}/confirm`, `POST /{id}/pin/resend`, `POST /{id}/pin/new` | `title_deeds.notify` |
| `GET /{id}/handover` + `POST` variants, consent request / cancel / phone-consent | `title_deeds.handover` |
| `GET /search/properties`, `GET /search/contacts` (own pickers) | `title_deeds.create` or `title_deeds.edit` |
| `GET /api/v1/title-deeds/{id}/consent-status` (`api.v1.title-deeds.consent-status`) | `title_deeds.handover` |
| Public `GET/POST /title-deed-consent/{token}` (no login, token is the credential) | none |
| Settings `GET/POST /settings/title-deeds` | `title_deeds.manage_settings` |

Permission keys: `access_title_deeds`, `title_deeds.view`, `.create`, `.edit`, `.archive`, `.notify`, `.handover`, `.override`
(release a deed although an owner's contact details changed recently), `.manage_settings`.

### Scoping (OWN / BRANCH / AGENCY)

`TitleDeed::scopeVisibleTo($user)` reads `PermissionService::getDataScope($user, 'title_deeds')` (the scope on `title_deeds.view`):
all -> whole agency; branch -> `branch_id`; own -> `agent_id` OR `created_by` in the user's data identity ids (assistants use their
agent's); anything else -> no rows. `BelongsToAgency` is the outer wall. List, detail, edit, archive, restore, export, scan
download, notify, PIN, handover, consent request and the poll endpoint ALL resolve the deed through `visibleTo` (direct-URL by id =
404). The public consent page resolves ONLY through its token.

## 5. List screen

- Columns: property, owner(s), agent, received, status, days waiting, collected (date + by). Every column sorts.
- Default sort: oldest uncollected first (uncollected before collected, then `received_at` ascending, then id).
- Search (named): property address, owner name, attorney firm, collector name.
- Filters: status (Received / Owner notified / Collected / Archived), branch (all-scope users), agent, received date range (from/to).
- Pagination (50), real empty state (different copy for "no matches" and "nothing logged yet"), CSV export of the filtered set.
- CSV cells that start with `= + - @` are prefixed with `'` (spreadsheet formula injection).

## 6. Record (create / edit)

Required: property (picked, own picker, `Property::visibleTo`), at least one owner (picked, `Contact` scope), responsible agent,
branch, date received. Optional: attorney firm, storage location, notes. Branch and agent default from the property (then the
user), overridable. Owners: 1..n, duplicates collapsed. A collected record accepts notes only. Status is never an input.

Input space (prevent or absorb): no owners -> refused with a plain message; owner archived after the record was made -> the
snapshot name renders, the owner is skipped on notices with the reason logged; owner without email / without mobile -> that channel
is skipped for that owner and logged; two owners sharing an email or number -> one send covers both, logged for both; archived
property -> the address snapshot renders; deed already collected -> every collection route answers "already collected"; double
submit / two staff at once -> the deed row is locked inside the transaction and the second request is refused cleanly.

## 7. Scan

Optional PDF / image (max 25 MB), added or replaced while the deed is open (a collected deed takes no new scan - its record is read-only), stored on the PRIVATE `local` disk as a `Document` (type "Title deed (copy)", `source_type`
`title_deed`, attached to the property and the owners). Downloaded only through `title-deeds.scan.download` (scope-checked, audited).
Label on the record: "Copy only - original held at office" until collected, then "Original collected <date>".

## 8. Notifying the owners, the PIN, the handover

### 8.1 Notify owners (one action, every owner)

- Email: the existing agency sending path (`SignedDocumentDistributionService::sendGenericMail`, the agent's own mailbox) to the
  email STORED on the contact (primary email, else the contact mirror). WhatsApp: the existing click-to-WhatsApp pattern to the
  number stored on the contact (WhatsApp-flagged number, else primary, else mirror), then the staff member presses "I sent it".
  The destination is NEVER an input anywhere in this flow.
- The message carries the collection PIN and "the deed is only released on presentation of the PIN" (PIN setting on) or "bring
  your ID" (PIN setting off). Neutral wording; the agency's name and the agent's footer come from the sending agent. Wording is not
  an agency setting (it was not in the approved settings list).
- Every send is logged per owner per channel (who sent it, when, destination masked, result). WhatsApp rows are honest:
  `prepared` until the staff member confirms (`sent`). CoreX cannot send WhatsApp itself.
- On the mail guard: non-live environments redirect every address to `MAIL_NON_PRODUCTION_REDIRECT`; unchanged.

### 8.2 The collection PIN

One PIN per deed, 6 digits, on the existing OTP engine (`OtpService`, purpose `title_deed_collect`, destination
`title_deed:{id}:{version}`, valid ~5 years i.e. until collected). Verified against the hash. A reversible copy
(`pin_encrypted`, Laravel `Crypt`, app key) is kept ONLY so "Resend PIN" can resend the same PIN; it is cleared on collection and
on replacement. The PIN is never shown on a CoreX screen; it appears only in the owner email and in the prepared WhatsApp text.
Wrong attempts are counted on the deed and logged; at N (setting) the PIN locks and only "Issue new PIN" clears it.
"Resend PIN" and "Issue new PIN" (cancels the old one: new version, old row expired) go only to the stored email / WhatsApp.
The PIN is issued with the first owner notice (PIN setting on).

### 8.3 Handover (one screen, mobile friendly)

Methods recorded on the receipt: `PIN`, `ID check`, `Owner consent`, `Phone consent`, `Authority letter` (a representative with a signed letter and no consent, in "consent link or authority letter" mode).

Common: the deed must be Received or Owner notified, not archived, not collected. The signature (when the setting requires it) is
drawn on screen with the signature pad; captured: signature image, typed name, time, IP, device (user agent), and the staff
member. A photo of the collector's ID is required when that setting is on. If any owner's email or phone changed within the last
N days (setting), a user with `title_deeds.override` must do the handover and give a reason (logged); other users are told why.
"Changed" = a stored email / phone row created, edited or removed in the window, or a contact audit row whose email or phone differs
(`TitleDeedDestinations::detailsChangedWithin`). A contact created inside the window therefore counts: that is deliberate - an
address typed in last week is exactly what the check exists for. 0 days switches the check off.

a. **With PIN**: staff enter the PIN, choose the collector (an owner, or a representative: name, ID number, relationship, all
   required; refused when representatives are not allowed), collector signs -> Collected (method "PIN").
b. **Owner in person, no PIN**: staff record the ID number seen (required) -> signature -> Collected (method "ID check").
c. **Representative, no PIN**: staff capture name, ID number, relationship and press "Ask owner to confirm". Every owner gets a
   tokenised link (stored email / prepared WhatsApp) reading "<name>, ID ending <last 4>, is at <agency> to collect your title deed
   for <property>" with Approve / Decline. First response wins (row lock). Approve records the owner, time, IP, device and unlocks
   the handover for THAT named representative only, for the consent validity (setting). Decline blocks and is logged. Token:
   unguessable (48 random chars, stored hashed), single use, expiring. The handover screen shows waiting / approved / declined
   and polls `api.v1.title-deeds.consent-status` every 5 s (paused while the tab is hidden).
d. **Phone-call consent** (only if the setting allows it; default off): staff pick which STORED number of which owner was
   called, who they spoke to and the time. It counts as the consent for (c); method "Phone consent".
e. **Authority letter**: required for a representative without PIN when the setting says so (or, in "consent link or authority
   letter" mode, a letter alone is enough). The upload is a private `Document` attached to the deed.
f. **Contact changed recently**: see "Common".

Representative without PIN mode (setting): refuse / owner consent link / consent link or authority letter.

### 8.4 After collection

The responsible agent is notified through `NotificationDispatcher` (event type `title_deed.collected`) and EVERY owner is emailed
who collected, when and by which method, by a domain event listener (`TitleDeedCollected` -> `NotifyOnTitleDeedCollected`),
failure-isolated. The PIN copy is cleared.

### 8.5 Reminders

`php artisan titledeeds:send-reminders [--dry-run] [--agency=ID]`. For deeds in "Owner notified" (not archived, not collected):
when `last_reminder_at ?? notified_at` is at least `reminder_days` ago and `reminder_count < max_reminders`, email every owner
(stored email, PIN included), log it, bump the counters, all under the deed row lock (idempotent). Reminders are email only:
WhatsApp cannot be sent automatically. Scheduled daily in `routes/console.php`; QA1 has no scheduler cron, so it is run by hand.

## 9. Agency settings (`title_deed_settings`, one row per agency, nullable columns, defaults in code)

| Setting | Default |
|---|---|
| PIN required | on |
| Signature required | on |
| Representatives allowed | on |
| Representative without PIN | owner consent link |
| Phone-call consent allowed | off |
| Authority letter required for representatives (without PIN) | off |
| Photo of collector's ID | off |
| Wrong-PIN lock count | 5 |
| Consent validity (minutes) | 60 |
| Reminder after (days) | 14 |
| Max reminders | 3 |
| Contact-changed block (days; 0 = off) | 7 |

Screen: Settings -> Title Deeds (`/settings/title-deeds`, linked from the settings navigation). Setup Wizard: the same controls on
the Properties step, same saver, `has()`-guarded (BUILD_STANDARD 10a, spec `agency-onboarding-setup.md` 6.1). Every change writes a
log line (agency, user, the values that changed); there is no separate audit table for settings.

## 10. Audit

`title_deed_audit_log` (event, user, summary, before/after JSON), shown on the record. Events: created, edited, scan_uploaded,
scan_downloaded, notice_sent, whatsapp_confirmed, pin_issued, pin_resent, pin_replaced, pin_wrong, pin_locked, consent_requested,
consent_approved, consent_declined, phone_consent, authority_letter, override, handover, reminder_sent, archived, restored.

## 11. Test matrix (`tests/Feature/TitleDeeds`)

Create with two owners; no owners refused; owner without email / mobile; two owners one email; archived contact; archived
property; scope own / branch / all and cross-agency by direct id (list, show, export, scan, handover); notify logs per owner per
channel; PIN wrong / lock / correct / resend / new cancels old; handover (a)-(f); double submit and already collected; consent
approve / decline / expired / reused token / first response wins; reminder cap and idempotency; settings defaults and saver.

## 12. Known limits (reported, not hidden)

- The sending staff member necessarily sees the PIN when the WhatsApp message is composed (it is in the message). The email path never shows it to staff.
- The reversible PIN copy exists only so "Resend PIN" can resend the same PIN; the engine row is hashed.
- Reminders are email only; WhatsApp cannot be sent without a person.
- Message wording is neutral and fixed (not an agency setting - it was not in the approved settings list).

## 13. Deliberately NOT built

Automatic WhatsApp sending; editable message wording; an ID-number match against the contact record (the number seen is recorded and
the audit notes whether it matches); WhatsApp reminders; any change to the Filing Register.

## 14. Round 2 (AT-450, QA1)

### 14.1 One-time form tokens - Back + submit never makes two of anything
Every state-changing form (log a deed, notify owners, resend PIN, new PIN, handover, consent request, phone consent, signing-link
actions) carries `_form_token`: a signed value (`v1.<time>.<nonce>.<hmac>`) bound to the person, the purpose and the deed.
`TitleDeedFormGuard::claim()` inserts the token into `title_deed_form_claims` (unique on the hash) **inside the same database
transaction as the action**. A failed action rolls the claim back (the same form can be sent again); a successful one makes any
second send of that rendered form (Back, double click, refresh-resend, two tabs sharing a form) answer "already done" and land on
the result - nothing repeats. A token for another purpose or person, a forged or missing one, is refused. Cause of the duplicate
pair 6 / 7: the create form had no protection, so Back-then-submit re-posted the old form.
**Real duplicate guard:** an OPEN (not collected, not archived) deed for the same property - or the same deal - already in the
agency (agency-wide, not just my own) shows a warning with a link; logging another needs the explicit button "Log another deed for
this property" (app dialog), server-enforced by `confirm_duplicate`.

### 14.2 Responsible agent = what the form shows
The property pick only PRE-FILLS the Responsible agent select visibly (with a one-line "Filled from the property: <name>" note and an
outline); the person can change it, a deal pick (14.3/14.8) outranks the property, and a value the person chose is never
overwritten. What is saved is exactly the select's value. (Cause of the old bug: the server replaced the posted agent with the
property's agent when they differed.)

### 14.3 Deal picker
Optional "Deal" search (new deeds, `title_deeds.create`) over REGISTERED DR2 deals the user can see (`deals.accepted_status` R /
Registered, or `registration_date` set), by `deal_no` / `file_no`, the property address, seller / buyer name or a linked buyer
contact. Picking pre-fills, visibly and editably: property (`deals.property_id`), transferring attorney firm
(`deals.attorney_provider_id` -> `agency_service_providers.company/name`, else `deals.attorney_name`), branch (`deals.branch_id`),
agents (14.8) and owners = the deal's linked BUYERS (`deal_contacts.role = buyer`; after registration the deed belongs to the new
owner). A buyer only named as text (`deals.buyer_name`) is shown as text and never turned into a contact. Stored as
`title_deeds.deal_id`; the record links to `/deals-dr2/{deal}/pipeline` and shows "Deal <no>". Routes: `GET title-deeds/search/deals`,
`GET title-deeds/search/deal/{deal}/prefill`.

### 14.4 Where it is kept = the agency's own list
`title_deeds.storage_location_id` -> `title_deed_storage_locations` (agency, name, optional branch, sort order, soft delete).
Required select on the form (only locations for the deed's branch or for every branch). Settings screen
**Settings -> Title Deeds -> Storage locations** (`title_deeds.manage_settings`; own agency only; search, sort, show archived, add /
rename / reorder / archive / restore) - the same write path as the Setup Wizard's list editor (step "Title deed storage
locations"). An agency with no locations is told where to add one (or to ask an admin) and cannot log a deed until it has one. Old
free text is kept and shown as "Legacy: ...". An archived location keeps showing on the deeds that used it. A move writes a
`location_changed` history entry (from -> to).

### 14.5 Scan on the create form
Optional file input on "Log a title deed", saved atomically with the deed (if the file cannot be stored the whole create rolls back
and the typed input is kept). Replacing a scan on the record keeps the previous file (soft) under "Earlier scans".

### 14.6 Row actions on the list
A compact group at the right of every row (icons with tooltips): open, notify owners, resend PIN, new PIN, email owner, WhatsApp
owner, hand over - only those valid for the row's status and the user's permissions. They post to the SAME routes with the same
one-time token, confirm through ONE shared app dialog, record the same audit, and return to the exact list the user was on
(`return_to`, accepted only if it is this module's own list path). The whole row opens the record (clicks on links, buttons and the
actions do not).

### 14.7 WhatsApp is one honest step
"Open WhatsApp and send" (POST) records `opened_at` / `opened_by_user_id` and redirects to the wa.me link on the STORED number.
Only a message that was opened can then be marked "Mark as sent" or "Not sent" (the server refuses both for a message nobody
opened). Same for PIN resend and the consent link. "Owner notified" moves only via a sent email or a confirmed WhatsApp. The list
shows a "WhatsApp waiting to be sent" panel. History shows who opened it and when, who confirmed.

### 14.8 Agents from the deal
Responsible agent = the deal's first SELLING agent (`deal_user.side = selling`; they work with the buyer, the new owner); further
selling agents are saved as `additional_agent_ids` ("Also told on collection"); the deal's LISTING agent (`side = listing`) is shown
as "Listing agent" and saved as `listing_agent_id`. Agency setting `notify_listing_agent` (default on) decides whether the listing
agent is told on collection. No deal -> 14.2. People from another agency are never stored.

### 14.9 Signature pad (BLOCKER)
Reported: the signature box on the handover screen could not be drawn on, so handover was impossible. Reproduced in real headless
Chromium on QA1 (mouse and touch, 390 and 1280 wide): the old pad drew in every scenario tried, so the exact user-side trigger
could not be reproduced here. What the old design depended on: a third-party library loaded as a separate module script that the
Alpine page found only if it had arrived first, kept INSIDE Alpine's reactive state (proxy-wrapped), sized once from the box's
size at load. Any of those failing leaves a box that does nothing and says nothing. Replaced by one self-contained pad
(`_signature-pad.blade.php`): no third-party dependency, outside Alpine's state, sizes itself on every resize and when first laid
out, redraws its strokes, mouse + touch + pen (pointer events with a fallback), and **says so** when it cannot start ("Reload this
page, or use 'Collector signs on their own phone instead'") - and the handover can always be done by link (14.10). Used by the
staff screen (all three methods) and the public phone page.
**Why the first build's checks missed it:** they only proved the SERVER accepts a correct signature image; nothing ever drew on the
box in a browser. `scripts/verify-title-deed-signature.mjs` is that check: it draws a stroke (mouse at 1280, 1280 @2x, touch at
390) and asserts ink is on the canvas and that the signature field the server will receive is a real PNG, without submitting.

### 14.10 Sign by link
Second way to sign (agency settings `sign_by_link_enabled`, default on; `signing_link_minutes`, default 30). All gates are passed at
the counter FIRST (PIN proven but NOT spent, ID number seen, owner consent, authority letter, ID photo, override) by
`TitleDeedHandoverService::prepareLink()`; then a single-use link is created for THIS deed and THIS named collector (token hashed in
`title_deed_signing_links`, 192-bit, shown once). The collector opens `/title-deed-sign/{token}` on their phone (no login): "I,
<name>, confirm I am collecting the title deed for <property>", a working pad, typed name, a confirm tick; it records the
signature, typed name, time, IP and device and does NOT collect the deed. The staff screen polls
(`GET /api/v1/title-deeds/{id}/signing-status`), flips to "Signed by <name> at <time>" and enables "Hand the deed over", which
finishes from what was proven at the counter (`signing_link_id`; the PIN is spent then, and re-checked: a replaced / locked PIN
refuses). Where the link goes: an OWNER's link only to the email / WhatsApp stored on that contact; a REPRESENTATIVE's to the
email / mobile captured at the counter. Email is sent from the staff member's mailbox; WhatsApp uses 14.7; a QR code on the staff
screen is the third way. The on-screen pad and the link are alternatives: the first to complete cancels the other. Staff can
cancel or regenerate (history keeps every step: generated, sent, opened, signed, cancelled, used). The signing POST is exempt from
CSRF (the token is the credential) and throttled; every dead link (expired, cancelled, used, unknown) is a plain page.

### 14.11 Round-2 test files
`TitleDeedRound2Test` (items 1-8), `TitleDeedSigningLinkTest` (item 10), the adapted round-1 files, and the real-browser check
`scripts/verify-title-deed-signature.mjs` (item 9; also signs on the public page with `--sign-url`).
