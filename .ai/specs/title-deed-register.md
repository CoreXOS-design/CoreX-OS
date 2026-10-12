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
- Owner notified: the first owner notice has been sent (an email accepted by the mail system, or a WhatsApp the staff member
  confirmed with "I sent it"). It never goes back.
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
