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

> Round 4 (section 17) replaced the single Status filter and the one flat table with tiles, status ticks and two collapsible sections.
> The columns, search, filters, scoping, CSV rules and row buttons below still apply inside the sections.

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
shows a "WhatsApp waiting to be sent" panel. History shows who opened it and when, who confirmed. A collected deed never shows a
waiting WhatsApp, and the message for a signing link stops "waiting" once that link is used, cancelled or has run out.

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

## 15. Round 3 - the settings screen as sentences (AT-450, QA1)

### 15.1 Why
The first screen was eight bare ticks with noun labels that mixed three meanings (REQUIRED: PIN, signature, ID photo, authority letter;
ALLOWED: representatives, phone consent, sign by link; DO THIS: tell the listing agent). Nobody could tell what a tick meant. Every setting
is now a question with its own explicit answer, in sections, with a one-line plain consequence.

### 15.2 The answers, and what each setting did before (carried over faithfully)
Three-way (Required / Optional / Not used) - `pin_mode`, `signature_mode`, `collector_id_photo_mode`, `authority_letter_mode`:
- **Required** = the deed cannot be handed over without it. **Optional** = offered, may be skipped; the record then says "Not captured".
  **Not used** = not shown at all (anything posted anyway is ignored, never stored).
- Before: `pin_required` on = a PIN was sent and was one way in (ID check stayed available), off = no PIN; `signature_required` off = the box was
  offered "(optional)"; `collector_id_photo_required` off = the field was not shown; `authority_letter_required` off = a letter was still accepted
  where it applied. Migration `2026_10_24_100000`: on -> Required; PIN off -> Not used, signature off -> Optional, photo off -> Not used, letter
  off -> Optional; a NULL (never chosen) stays NULL and takes the new default. The four old columns are dropped after the copy.
- Agency 1 on QA1 (Johan set every tick himself at 15:04 on 12 Oct to test, reminders 30 / 2) therefore became PIN Required, signature Required,
  photo Required, letter Required - his choices, untouched.
Yes / No: `representatives_allowed`, `phone_consent_allowed`, `sign_on_screen_enabled` (new, default Yes), `sign_by_link_enabled`, `notify_listing_agent`.
One select kept: `rep_without_pin_mode` (Refuse / Ask the owner to confirm by link / Owner link or a signed authority letter).
Six numbers, each with its unit and consequence: `pin_lock_attempts`, `consent_validity_minutes`, `reminder_days`, `max_reminders`,
`signing_link_minutes`, `contact_changed_block_days`.
Approved defaults (code, wizard and screen agree): PIN Required, signature Required, representatives Yes, rule "owner confirms by link", phone consent No,
authority letter Optional (the old "off"), photo of ID Not used, sign on screen Yes, sign by link Yes, listing agent told Yes, 5 attempts, 60 / 30 minutes, 14 days, 3 reminders, 7 days.

### 15.3 Effective rules (`TitleDeedPolicy`, the ONE place screens and server read)
- PIN Required: the PIN is the only way to collect. The owner ID check and every "without the PIN" route are not offered (the server refuses them), the
  "representative without the PIN" rule is hidden and effectively Refuse, phone consent is unavailable. To get those routes back, make the PIN Optional.
- PIN Optional: the PIN is sent and may be used; staff may instead check the owner's ID, or take a representative by the rule below; the record says "PIN: Not captured".
- PIN Not used: no PIN is issued or sent, the PIN tab and "PIN" buttons are not shown; ID check / representative routes apply.
- Representatives = No: no representative route; the rule, phone consent and authority letter are ignored (letter effectively Not used).
- Phone consent exists only where a representative can arrive without the PIN and the rule is not Refuse.
- Authority letter: applies to EVERY representative handover (with the PIN, by consent, or alone). Required = must be uploaded; Optional = may be, and is the
  alternative to the owner's confirmation under "owner link or a signed authority letter"; Not used = never asked (the rule then falls back to "owner link").
- Signature Not used: no box, no signing link, "Ways to sign" ignored. Sign on our screen = No: no box on our screen (a Required signature must then come by link).
- A skipped signature leaves `signed_at / typed name / ip / via` empty; the receipt keeps `proof_modes` (as they stood) so the record can say "Not captured".

### 15.4 Combinations refused on save (plain messages; nothing is saved)
1. Signature Required and both ways to sign are No -> "The signature is Required, so at least one way to sign must be Yes".
2. Representatives allowed + PIN Not used + rule Refuse -> no representative could ever collect: pick another rule, or answer No to "May a representative collect?".
3. Representatives allowed + PIN not Required + rule "Owner link or a signed authority letter" + letter Not used -> the rule needs the letter.
Settings hidden by their parent (rule / phone consent / letter when representatives are No or the PIN is Required; the ways to sign when the signature is Not used; numbers that
do not apply) are not posted and never an error. A post that never rendered a field leaves it alone (wizard subset rule).

### 15.5 History
`title_deed_setting_history`: one row per changed setting - who, from, to, when, source (screen / wizard) - shown at the bottom of the settings screen. Unchanged answers are not logged.

### 15.6 Handover and record follow the values exactly
Tabs are spelled as the counter: "The collector has the PIN" / "The owner is here without the PIN" / "Someone else is here without the PIN" (a tab the settings rule out is not shown;
a single way shows a sentence instead of tabs). Under the chosen one a "What happens next" line lists the 3-4 steps that follow from the settings. PIN, signature and ID photo each
proven Required / Optional / Not used in `TitleDeedRound3Test`.

## 16. Round 3 - every screen says what happens

### 16.1 List
One labelled button for the row's next step ("Notify owners" for Received, "Hand over" for Owner notified, "Open" when there is none) and a labelled "More" button opening a
panel of worded buttons (Open this title deed, Hand over, Send the PIN again, Issue a new PIN, Email the owners, Prepare a WhatsApp to the owners, Restore / Archive this title deed - Archive
last and apart). No icon-only buttons in the module. Status is plain text; only "Archived" gets a neutral chip.

### 16.2 WhatsApp waiting to be sent
Each line says which message ("Collection notice with the PIN"), to whom, and since when. Buttons: "Open WhatsApp and send" -> "Mark as sent", and on EVERY line
"Don't send - clear this" (confirmed in the app dialog). Clearing never deletes: the message row stays as `not_sent`, its text is dropped, the deed history gets
"WhatsApp to <name> not sent - cleared by <user>", the record's message table shows "Not sent - cleared by <user> on <date>", the owner is not counted as told, and it leaves the waiting list.

### 16.3 Create form
Required fields say "(required)", optional ones "(optional)". The responsible-agent list shows only active people who are not assistants, each as "Name - Role, Branch". Under the Deal box,
while no deal is picked: "Fills property, owners, agents and attorney." Remove buttons say "Remove".

### 16.4 Record
One solid primary action (the next step for the status: "Notify the owners" / "Hand over" / "Restore this title deed"); the rest are outlined; "Archive this title deed" is separated and
confirmed in the app dialog. (Cause of Archive looking primary: the shared confirm button always added the solid primary class and a passed class was only appended; the component now
takes `:plain="true"` to use the caller's class - default unchanged for every other module.) "Kept at" with no location reads "No storage location chosen" with a "Choose a location" link.

### 16.5 Wording rule
Every label says what happens, every button is a verb phrase, no control depends on a tooltip. Strings changed in round 3 are listed in the round-3 report.

## 17. Round 4 - the list screen /title-deeds (AT-450, QA1)

Only the list changes. Settings, record, handover and create are untouched.

1. Tiles across the top, in this order, each showing a count for the current own/branch/agency scope and current search/branch/agent/date filters:
   - "At the agency" (Received + Owner notified)
   - "Owner not yet told" (Received)
   - "Waiting for collection" (Owner notified)
   - "Collected"
   Tiles are the same style as the Properties list tiles. Clicking a tile scrolls to/opens its section and applies that status tick.
2. The single Status dropdown is replaced by ticks (multi-select): "Owner not yet told", "Waiting for collection", "Collected", "Archived".
   Default ticked: the first two. Ticks, tiles and URL query stay in sync; filters survive pagination, sort and CSV download (CSV exports what is ticked).
3. The list is two collapsible sections, each with its own count in the header and its own pagination and sort:
   - "At the agency (N)" - open by default. Rows: Received and Owner notified. Keep the Days waiting column; default sort longest waiting first.
   - "Collected (N)" - collapsed by default, opens on click or when its tile/tick is chosen. Columns: property, owner(s), agent, received, collected
     (date, time, who collected). No Days waiting column. Default sort most recently collected first.
   Archived deeds show only when "Archived" is ticked, in a third section "Archived (N)" collapsed by default.
4. Real empty state per section ("No title deeds at the agency." / "No title deeds collected yet."). Collapse state remembered per user in the browser.
5. Row buttons, search, branch, agent, date filters and own/branch/agency scoping unchanged. No hard deletes. Works at 1280 and 390 wide.

### 17.1 How it is built (decisions that follow from the wording)
- URL: ticks are `show[]=not_told|waiting|collected|archived`. No `show` at all = the default (first two). A submitted form with nothing ticked sends an empty
  `show[]` and shows the sentence "Tick at least one kind of title deed to see it." (it is never silently turned back into the default).
  An old bookmark `?status=received|notified|collected|archived` is mapped to the matching tick.
- Tile counts follow scope + search/branch/agent/date filters but NOT the ticks (a tile is a count of a kind, whatever is ticked); archived deeds are never counted in a tile.
  A tile click sets the ticks to exactly that tile's kinds ("At the agency" = both of the first two) and adds `focus=<section>` so that section opens and scrolls into view.
- Each section has its own query parameters: `sort_a` / `dir_a` / `page_a` (at the agency), `sort_c` / `dir_c` / `page_c` (collected), `sort_x` / `dir_x` / `page_x` (archived).
  Defaults: at the agency = longest waiting first (received date ascending); collected = most recently collected first; archived = most recently archived first.
- Header count of a section = the rows in it under the current ticks and filters. Ticking a tick submits the form at once (no Apply press) and opens that section.
- Collapse: at the agency open, collected and archived collapsed; a section named by `focus` opens; otherwise the person's last choice in this browser
  (localStorage, per user and section) wins over the default.
- CSV: exactly the ticked kinds under the current filters (archived rows only when Archived is ticked), at the agency first (longest waiting), then collected
  (most recent first), then archived. Same columns and formula-injection protection as before.
- Scoping is unchanged: every query goes through `TitleDeed::visibleTo()`; a count never includes a deed the person cannot open.

## 18. Round 4 addition - a unique reference number on every title deed (AT-450, QA1)

Johan: every title deed gets a unique reference number, like the Deal Register does, for physical filing and for finding a deed.

1. First look at exactly how the Deal Register generates and formats its reference number and mirror that pattern (per-agency sequence, never reused, safe under concurrent saves -
   transaction/lock, unique index per agency). Default format "TD-00001" style following the deal register's convention; prefix is an agency setting on the Title Deed settings screen
   under a new heading "Reference numbers" with label "Reference prefix" and help line "Printed on every title deed record, e.g. TD-00042. Changing it only affects new deeds." Default "TD".
2. Assigned once when the deed is logged; never changes, never reused, kept on archive/restore. Backfill all existing deeds per agency in order of date logged (additive migration, no deletes).
3. Shown: first column "Ref" on the list (sortable), in the CSV, large at the top of the record page next to the address with the label "Reference", on the handover screen heading, on the
   success message after logging ("Logged as TD-00042 - write this on the envelope"), and in the owner notice/receipt wording where the deed is identified (email and WhatsApp text).
4. Search box finds by reference (exact and partial, with or without the prefix/dash/leading zeros); update the search placeholder to "Reference, property, owner, attorney, collector".
5. Tests: uniqueness under concurrency, per-agency isolation, backfill order, search variants, prefix change only affecting new deeds.

### 18.1 What the Deal Register does (read in the code, mirrored here)
- The Deal Register numbers deals PER AGENCY (`deals.deal_no`, a plain number string, legacy "D-####" accepted; a fresh agency starts at 1001), allocates the number inside the creating
  transaction, never reuses one (the unique index `(agency_id, deal_no)` counts soft-deleted rows too) and guards a double submit with a one-time token. (The shared `sequence_counters`
  locked-counter table that an earlier fix added was dropped again, so QA1's Deal Register reads the maximum inside the transaction.)
- Title deeds mirror the per-agency, never-reused, unique-index-backed shape, but allocate from a LOCKED COUNTER ROW (`title_deed_sequences`, one row per agency, `SELECT ... FOR UPDATE` inside the
  creating transaction - the same idiom as the proforma numbers), so two deeds saved at the same moment can never read the same number. The unique indexes `(agency_id, reference_no)` and
  `(agency_id, reference)` are the backstop. Round 2's one-time form token already stops a double submit from making two deeds.

### 18.2 The number
- Stored twice: `reference_no` (the agency's sequence, 1, 2, 3 ...) and `reference` (the printed text as it was issued, "TD-00042": prefix + "-" + the sequence left-padded to 5 digits; a sequence over 99999
  simply grows longer). Printing the text AS ISSUED is what makes "changing the prefix only affects new deeds" true.
- Assigned in the model's `creating` event, inside the caller's transaction, so no code path can log a deed without one. A rolled-back create gives the number back to nobody - the counter row
  lock and the increment roll back together, so a failed create leaves no gap and a successful one is permanent.
- Prefix: `title_deed_settings.reference_prefix`, default "TD", 1-10 letters or digits, stored upper-case (a typed dash, space or "TD-" suffix is stripped). Settings screen heading "Reference numbers",
  label "Reference prefix". It is also a Setup Wizard control (same wording) and a history-logged setting like the others.
- Archive / restore never touch it. Collected deeds keep it.
- Migration (additive): the two columns + indexes, the counter table, the prefix column; every existing deed (archived ones too) is numbered per agency in order of date logged (created_at, then id)
  with the default prefix, and each agency's counter is set to the highest number + 1.

### 18.3 Where it shows
List first column "Ref" (sortable; in all three sections); CSV first column "Reference"; the record: large, next to the address, labelled "Reference"; the handover heading
("Hand over: TD-00042 - address"); the success message after logging ("Logged as TD-00042 - write this on the envelope"); the owner notice, reminder and collected emails and the WhatsApp text
("title deed for <address> (reference TD-00042)"); the consent and signing-link messages where they name the property.

### 18.4 Search
The search box finds a reference exactly or partly, with or without the prefix, dash or leading zeros: "TD-00042", "td00042", "00042", "42", "TD42", "0042" all find TD-00042 (a partial digit string
finds every number containing it). Placeholder: "Reference, property, owner, attorney, collector". An address that merely contains a number still matches as before.

