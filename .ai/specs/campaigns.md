# Campaigns — outbound email sequences for CoreX's own prospecting

**File:** `.ai/specs/campaigns.md`
**Jira:** AT-534 (branch `AT-534-Bulk-emails`)
**Status:** APPROVED 2026-11-02 — building on `AT-534-Bulk-emails`.
**Build lane:** branch `AT-534-Bulk-emails` for now (instruction 2026-11-02). Promotion to a QA lane is a separate, explicit instruction.

---

## 0. Decisions on record (2026-11-02)

| # | Question | Ruling |
|---|---|---|
| D1 | Who uses Campaigns? | **CoreX only.** It lives in the owner-only **System Developer** section of the left menu. Agencies do not see it. |
| D2 | Whose account / mailbox? | **CoreX's own** — mail is sent from a CoreX address (see D4). Nothing is filed under any agency (not Home Finders). |
| D3 | Where is it built? | On `AT-534-Bulk-emails` for now. |
| D4 | Which address does it send from? (2026-11-02, revises D2) | **A setting.** Campaigns → Settings holds the CoreX **sending addresses** (first: **andre@corexos.co.za**), each with its own display name and **signature**. More than one can be added; each campaign picks one. |

### 0.1 What these rulings change against the original brief (stated so they are decisions, not drift)

1. **No Role Manager keys.** System Developer tools are `owner_only` with deliberately no permission key (precedent `routes/web.php:5583`, `:5603`): a key is grantable in Role Manager, and this tool emails the outside world as CoreX. So **no `campaigns.view` / `campaigns.manage`**. Every route is `owner_only` AND every controller action re-checks `isOwnerRole()` (Push Test Lab pattern, `PushTestLabController.php:76`).
2. **No agency scoping, no agency menu link, no agency settings page, no Setup Wizard step.** The data is CoreX's own platform data, not any agency's. Tables carry **no `agency_id`** and do **not** use `BelongsToAgency`. CLAUDE.md non-negotiable #10a: Campaigns settings are platform settings, not agency settings — **deliberately not in the wizard** (record this in `agency-onboarding-setup.md` §5.1 at build time).
3. **Mailbox is not picked from Communication Mailboxes.** Every `communication_mailboxes` row must belong to an agency (`agency_id` NOT NULL FK), and CoreX is not an agency. Campaigns keeps its own **sending addresses** (`campaign_senders`, §3.0), managed on the Campaigns Settings page (D4). Each holds the address, display name, signature and the mail-server login (password encrypted, never shown again, same `'encrypted'` cast + `$hidden` discipline as `CommunicationMailbox`). The transport is built the same way and passes through the same OutboundMailGuard gate as `PerMailboxMailTransportBuilder` (`:93`, `:101-103`).
4. **Opt-out list is Campaigns' own** (`campaign_suppressions`), not the agency `marketing_suppressions` table — that table is per-agency and CoreX is not an agency.
5. **Test sender.** The brief's test sender `johan@hfcoastal.co.za` is replaced by `andre@corexos.co.za` (D4). Test recipient remains **only `can.assurance@gmail.com`**. Nothing ever connects to `mail.hfcoastal.co.za`.
6. **Pillars.** CLAUDE.md wants every module on a pillar. Campaign leads are CoreX's sales prospects (agencies), **not any agency's Contacts**, so Campaigns deliberately does **not** write to the Contact pillar. It reads/writes the **Agent** pillar only (`created_by` / `updated_by` on every record = the owner user). Flagged for approval as a deliberate exception.
7. **Domain events.** No cross-pillar reactivity → no events (non-negotiable #9 not triggered).

---

## 1. What it does

CoreX imports a list of prospects (CSV/XLSX), writes a 1–3 step plain-text email sequence with merge fields, and starts the campaign. A scheduled job sends due emails from the campaign's CoreX sending address (e.g. andre@corexos.co.za, with its signature), only on the allowed days and hours, never more than the daily limit, and **never the same step to the same lead twice**. Each email ends with an opt-out line and a one-click unsubscribe link. A reply stops that lead's sequence; a reply saying STOP, an unsubscribe click, or a hard bounce stops the lead and suppresses the address for every CoreX campaign.

---

## 2. Navigation and access

- **Sidebar:** one item **"Campaigns"** in the System Developer section (`corex-sidebar.blade.php`, inside `@if($isOwner)`, placed after Push Test Lab), active on `admin.campaigns.*`. No `@permission` wrapper (§0.1-1).
- **Inside the module** a tab strip (`corex.partials._hub-tabs` pattern): **Campaigns · Lists · Suppressed · Settings**.
- **Routes:** `Route::middleware('owner_only')->prefix('admin/campaigns')->name('admin.campaigns.')`. Every controller action calls `$this->guard()` → `abort_unless(isOwnerRole(), 403)`.
- **Public routes (no auth):** unsubscribe preview + confirm (§6.4), throttled.
- **Visibility within CoreX:** every owner sees every campaign/list (owners are AGENCY-level by definition, `PermissionService.php:205-209`). `created_by` is recorded and shown; there is no OWN restriction between owners. Non-owners: 403 on every route including direct-URL-by-ID and exports.

---

## 3. Data model (all tables `SoftDeletes`; archive + restore everywhere; no hard delete)

All tables: `id`, `created_by` (FK users, nullable on user delete), `updated_by`, timestamps, `deleted_at`. No `agency_id`.

### 3.0 `campaign_senders` (the sending addresses — D4)
| Column | Type | Notes |
|---|---|---|
| `email` | string(254) NOT NULL | lower-cased; unique among non-archived senders |
| `from_name` | string(150) NOT NULL | e.g. "Andre Roets — CoreX OS" |
| `signature` | text NULL | plain text, added under the step body, above the opt-out footer |
| `smtp_host` | string(255) NOT NULL | default `mail.corexos.co.za` |
| `smtp_port` | unsigned int NOT NULL default 587 | |
| `smtp_encryption` | enum `tls, ssl, none` default `tls` | |
| `username` | string(255) NOT NULL | default = `email` |
| `encrypted_password` | text NOT NULL | `'encrypted'` cast, `$hidden`; blank on edit = keep existing |
| `imap_host` | string(255) NULL | NULL = same as `smtp_host`; used for replies/bounces (§6.6) |
| `imap_port` | unsigned int NOT NULL default 993 | |
| `imap_encryption` | enum `ssl, tls, none` default `ssl` | |
| `is_default` | boolean NOT NULL default false | exactly one default among non-archived senders (set by the save, not by the user picking two) |
| `last_send_error`, `last_send_error_at`, `last_sent_at` | | sanitised health, shown on the Settings page |
| `last_inbox_check_at`, `last_inbox_error` | | inbox health (§6.6) |

**Test connection** button per sender: sends one test email **to the sender's own address** and logs into its inbox; both results shown separately (AT-395 §6 pattern). Archive is blocked while a running campaign uses the sender (prevent); restore allowed.

### 3.1 `campaign_lists`
| Column | Type | Notes |
|---|---|---|
| `name` | string(150) NOT NULL | unique among non-archived lists (validation) |
| `source_filename` | string(255) NULL | last imported file name |
| `last_imported_at` | timestamp NULL | |

### 3.2 `campaign_leads`
| Column | Type | Notes |
|---|---|---|
| `campaign_list_id` | FK NOT NULL | |
| `first_name`, `last_name`, `company`, `town` | string(150) NOT NULL default `''` | never null — merge fields always have a value |
| `email` | string(254) NOT NULL | trimmed, lower-cased |
| `phone` | string(40) NOT NULL default `''` | |
| `notes` | text NULL | |
| `status` | enum `new, queued, sent, replied, stopped, bounced, suppressed` default `new` | §3.7 |
| `status_reason` | string(100) NULL | e.g. `reply_stop`, `unsubscribe`, `hard_bounce`, `manual` |
| `status_changed_at` | timestamp NULL | |
| `email_active_key` | **generated** `IF(deleted_at IS NULL, email, NULL)` + **UNIQUE** | dedupe on email across all CoreX leads; archived leads do not block re-import |

### 3.3 `campaigns`
| Column | Type | Notes |
|---|---|---|
| `name` | string(150) NOT NULL | |
| `campaign_list_id` | FK NOT NULL | a list may belong to only ONE non-archived campaign (prevent, §8) |
| `campaign_sender_id` | FK NOT NULL | the sending address; defaults to the default sender; changeable in draft/paused |
| `status` | enum `draft, running, paused, done` default `draft` | |
| `send_days` | json NOT NULL | ISO weekdays `[1..5]`; default from settings |
| `send_from`, `send_to` | time NOT NULL | default from settings; `send_from < send_to` |
| `daily_limit` | unsigned int NOT NULL | 1–500; default from settings |
| `started_at`, `done_at` | timestamp NULL | |
| `paused_reason` | string(100) NULL | set when the system pauses it (mailbox login failure, §6.3) |
| `last_run_at` | timestamp NULL | |

### 3.4 `campaign_steps`
| Column | Type | Notes |
|---|---|---|
| `campaign_id` | FK NOT NULL | |
| `step_number` | tinyint 1–3 NOT NULL | unique per campaign (non-archived) |
| `subject` | string(200) NOT NULL | |
| `body` | text NOT NULL | plain text, merge fields |
| `day_gap` | unsigned smallint NOT NULL | days after previous step; step 1 = 0 (ignored); steps 2–3 ≥ min day gap setting |

### 3.5 `campaign_sends`
| Column | Type | Notes |
|---|---|---|
| `campaign_id`, `campaign_step_id`, `campaign_lead_id` | FK NOT NULL | |
| **UNIQUE** (`campaign_step_id`, `campaign_lead_id`) | | **the idempotency guarantee** — a lead/step pair can exist once, ever (archived rows included; never soft-deleted) |
| `status` | enum `sending, sent, failed, unknown` | §6.2 |
| `attempts` | tinyint NOT NULL default 0 | |
| `to_email`, `from_email` | string(254) NOT NULL | snapshot |
| `subject`, `body` | NOT NULL | rendered snapshot — what the lead actually got |
| `message_id` | string(255) NULL, indexed | our own Message-ID, set before sending (§6.5) |
| `sent_at` | timestamp NULL | |
| `error_reason` | string(100) NULL | sanitised (`MailFailureClassifier` vocabulary) |
| `replied_at`, `bounced_at`, `unsubscribed_at` | timestamp NULL | |

`campaign_sends` rows are an audit log: no edit, no archive in the UI (they are history, not a managed entity).

### 3.6 `campaign_suppressions`
| Column | Type | Notes |
|---|---|---|
| `email` | string(254) NOT NULL | lower-cased |
| `email_active_key` | generated `IF(deleted_at IS NULL, email, NULL)` UNIQUE | |
| `reason` | enum `reply_stop, unsubscribe, hard_bounce, manual` | |
| `campaign_send_id` | FK NULL | the send that caused it |
| `note` | string(255) NULL | |

Archive = lift the suppression (leads stay `suppressed` until manually reset to `new`, §7.3). Restore = suppress again.

### 3.7 Lead status meaning
- `new` — imported, not in a started campaign.
- `queued` — its campaign is running; step 1 not sent yet.
- `sent` — at least one step sent; more may follow, or the sequence finished with no reply.
- `replied` — the lead replied (not STOP). Sequence stops. Not suppressed.
- `stopped` — STOP reply or unsubscribe. Sequence stops. Suppressed.
- `bounced` — hard bounce. Sequence stops. Suppressed.
- `suppressed` — address is on the suppression list (imported while suppressed, or suppressed via another route). Never sent to.

Terminal statuses (`replied, stopped, bounced, suppressed`) are never overwritten by the send job.

---

## 4. Settings (platform settings, `DevSetting`, owner-only page — Article Library precedent)

| Key | Default | Validation |
|---|---|---|
| `campaigns.default_daily_limit` | 60 | 1–500 |
| `campaigns.default_send_days` | Mon–Fri `[1,2,3,4,5]` | ≥ 1 day |
| `campaigns.default_send_from` / `_to` | 08:00 / 16:00 | from < to |
| `campaigns.opt_out_footer` | "You are receiving this email from CoreX OS. Reply STOP or use the link below and you will not hear from us again." | 1–500 chars |
| `campaigns.min_day_gap` | 2 | 1–30 |

Defaults live in `config/campaigns.php`; the settings page overrides them. Defaults apply when a campaign is **created**; changing settings never rewrites existing campaigns. Times are in the app timezone (`config/app.php:83`, Africa/Johannesburg).

The sending addresses (with signature and login) are the second part of the Settings page (§3.0, §7.5).

Also in `config/campaigns.php` (env-backed, not on screen): `max_import_rows` (10 000), `max_import_mb` (10), `send_batch_size` (20 per run), SMTP/IMAP timeouts (15 s).

---

## 5. Lists and import

### 5.1 Flow (copied from the Rentals Take-On import pattern: `RentalTakeOnImportController.php:86/142/167`, `map-columns.blade.php`)
1. Lists tab → **New list** (name) or open a list → **Import**.
2. Upload CSV or XLSX (≤ `max_import_mb`). File read with OpenSpout streaming (pattern `ContactImportController.php:359-379`).
3. **Map columns** screen: each field (first name, last name, company, town, email, phone, notes) has a select of the file's headers, pre-filled from header aliases. **Email is required**; the rest optional.
4. **Import** → result panel: imported / skipped-duplicate / imported-as-suppressed / rejected, and **every rejected or skipped row listed with its row number and reason**.

### 5.2 Whole input space (BUILD_STANDARD §2)
| Input | Behaviour |
|---|---|
| Empty file / header row only | Rejected: "This file has no rows to import." |
| No column mapped to email | Prevented: Import button disabled + server rejects. |
| `.xls`, other type, > size | Rejected with a plain message (".xls is not supported — save as .xlsx or .csv"). |
| > `max_import_rows` data rows | Rejected before any write: "This file has N rows; the limit is 10 000. Split it." |
| CSV with `;` delimiter, UTF-8 BOM, Windows-1252 text | Delimiter detected from the header line; BOM stripped; non-UTF-8 converted. |
| Leading/trailing whitespace, upper-case email | Trimmed; email lower-cased before validation and dedupe. |
| Blank email / malformed email | Row rejected: "missing email" / "not a valid email address". |
| Email already a lead (any list) | Row skipped: "already on list {name}". |
| Same email twice in the file | First kept, later rows skipped: "duplicate of row N". |
| Email on the suppression list | Imported with status `suppressed`. |
| Missing names/company/town/phone | Accepted; stored as `''`. |
| Over-long value | Truncated to column length (absorb), counted in the result. |
| Formula-prefixed cell (`=`, `+`, `-`, `@`) | Guard stripped (pattern `ContactImportController.php:459`). |
| Completely blank row | Ignored silently (not counted as rejected). |

### 5.3 Transactions
The write phase runs in **one DB transaction**; any exception rolls back every row of that import and shows "Nothing was imported — {plain reason}". Rejected rows are not exceptions; they never roll back good rows.

---

## 6. Sending

### 6.1 Schedule
`routes/console.php`: `Schedule::command('campaigns:send-due')->everyFiveMinutes()->onOneServer()->withoutOverlapping()`. The command dispatches one `SendCampaignBatchJob` per `running` campaign (job is `ShouldBeUnique` per campaign id) on the `default` queue (the one queue every environment's workers drain).

### 6.2 What one run does, per campaign
1. **Window:** if now is not on a `send_days` day between `send_from` and `send_to` → stop.
2. **Daily limit:** `sent_today` = `campaign_sends` of this campaign with `status in (sending, sent, unknown)` and `created_at` today. Budget = `daily_limit − sent_today`, capped at `send_batch_size`. Budget ≤ 0 → stop.
3. **Due pairs:** for each lead of the list whose status is `queued` or `sent`: next step = lowest step without a `campaign_sends` row. Due when step 1, or previous step's `sent_at + day_gap days ≤ now`. Order: oldest-due first, then lead id.
4. **Per pair (the idempotency rule):**
   a. Re-check suppression for the address (fresh query). Suppressed → lead `suppressed`, skip.
   b. **Claim:** `INSERT` the `campaign_sends` row with `status=sending`, rendered snapshot, our `message_id`. The UNIQUE (`step`, `lead`) index makes a second claim fail → skip. Committed **before** any SMTP traffic.
   c. Send through the campaign's sender (plain-text Mailable, §6.5).
   d. Success → `sent`, `sent_at`, lead → `sent`. Exception → `failed`, `attempts+1`, sanitised `error_reason`.
5. **Retries:** a `failed` row (the mailer threw → not accepted) is retried on a later run by a conditional claim `UPDATE … SET status='sending' WHERE id=? AND status='failed' AND attempts < 3`. After 3 attempts it stays `failed` and shows on Activity.
6. **Crash safety:** a row still `sending` after 15 minutes is marked `unknown` (the mail may or may not have gone) and is **never re-sent automatically**. Shown on Activity.
7. **Mailbox login failure** (`auth_failed`/`connect_failed` on two consecutive pairs) → campaign set `paused`, `paused_reason` = plain text, sender's `last_send_error` recorded, run stops. The header shows why.
8. **Done:** when no lead in the campaign has a pending step and none is `queued` → `done`, `done_at`.

### 6.3 Start / Pause / Resume
- **Start** (draft → running): requires ≥ 1 step, valid window, a list with ≥ 1 sendable lead. Every `new` lead in the list → `queued`. `started_at` set.
- **Pause** (running → paused): the next run sends nothing. **Resume** (paused → running) clears `paused_reason`.
- Leads added to the list after start are `queued` on import if the campaign is running/paused.
- Archive allowed in draft/paused/done; running must be paused first (prevent). Restore returns it as `paused` (or `draft`/`done` as it was).

### 6.4 Unsubscribe (signed route)
- Link per send: `URL::signedRoute('campaigns.public.unsubscribe.show', ['send' => $send->id])`, no expiry. Invalid signature → 403 page in plain words.
- **GET shows a confirm page** (email masked, one "Unsubscribe" button) — GET never writes, because mail scanners pre-open links (precedent `routes/web.php:186-197`). **POST** writes: lead → `stopped` (`unsubscribe`), suppression added, `unsubscribed_at` set. Idempotent: a second POST shows "You're already unsubscribed".
- RFC 8058 one-click: every email carries `List-Unsubscribe: <signed POST URL>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; the POST route accepts that body without CSRF (route excluded from CSRF, signature is the auth).
- Public page wording and branding: CoreX OS only.

### 6.5 The email
- Plain-text only. From = the campaign's sender (`from_name <email>`), Reply-To the same.
- Body = rendered step body + blank line + **sender's signature** (if any) + blank line + opt-out footer + unsubscribe URL. Signature and footer are snapshotted into `campaign_sends.body`.
- Merge fields `{first_name} {last_name} {company} {town}` (also in subject). Empty value renders as empty text. Unknown `{tokens}` are rejected when the step is saved ("{fname} is not a merge field — use {first_name}, {last_name}, {company} or {town}").
- Own `Message-ID: <cx-campaign-{send_id}-{random}@corexos.co.za>`, stored on the send before sending (precedent `DealPackMail.php:33-36`).
- **OutboundMailGuard applies unchanged.** On local/QA/staging the guard intercepts and redirects per its existing rules; nothing reaches a real inbox unless the environment is confirmed for sending.

### 6.6 Replies, STOP and bounces
New command `campaigns:check-inbox`, scheduled every 5 minutes (`onOneServer`, `withoutOverlapping`). For **each sender used by a running or paused campaign, or by one that finished in the last 30 days** (see 9.2-1), reads that sender's INBOX over IMAP (Webklex, same library as `ImapMailboxPoller.php:9`) for messages since its `last_inbox_check_at` minus 1 day overlap. Does **not** mark messages read, move, or delete anything. All updates are idempotent, so re-reading a message changes nothing.

- **Bounce** = sender `mailer-daemon@` / `postmaster@`, or `multipart/report; report-type=delivery-status`. The original is found by our Message-ID inside the report (or `Final-Recipient`). Status `5.x.x` (or, with no Status field, a real SMTP failure line - see 9.2-2) → lead `bounced` + suppression `hard_bounce` + `bounced_at`. Status `4.x.x` (temporary) → ignored.
- **Reply** = `In-Reply-To` / `References` contains a stored `message_id`; fallback: sender address equals a lead with a `sent` send in the last 90 days.
  - Reply text (quoted lines `>` and the part after "On … wrote:" removed) contains the word **STOP** or **UNSUBSCRIBE** (whole word, any case) → lead `stopped` (`reply_stop`) + suppression.
  - Otherwise → lead `replied`; sequence stops; not suppressed.
  - `replied_at` set on the matched send.
- Auto-replies (`Auto-Submitted: auto-replied`, `X-Autoreply`, out-of-office subjects) are **not** treated as replies.
- **Only on the confirmed live / Staging servers** (`OutboundMailGuard::isSendingConfirmed()`, the same �42 hard rule as `ImapSentFolderAppender`). Everywhere else the check and the Test button never open an IMAP connection; they record `off_on_this_server` and say "inbox checks only run on the live and Staging servers." (Found in the 2026-11-02 click-through: the local Test button had logged in to the real server with a placeholder password.)
- If the sender's inbox login fails: `last_inbox_error` is recorded, the Campaigns list shows a banner "Replies and bounces for {email} are not being checked — {reason}", and sending continues.

---

## 7. Screens (Properties page standard: full-height flex column, flat header, `_hub-tabs`, `ds-table`, `ds-badge`, `x-sort-header`, `corex-brand-scroll` scroll region, `->links()`; template `corex/portal-leads/index.blade.php`). No helper text; space goes to the data.

### 7.1 Campaigns list (`admin.campaigns.index`)
- One row per campaign: **Name · List · Mailbox · Steps · Sent · Replied · Stopped · Status · Next run**. Row click → campaign page.
- **Search:** campaign name, list name.
- **Sort:** name, list, steps, sent, replied, stopped, status, next run, created. **Default: created, newest first.**
- **Filters:** status (draft/running/paused/done/archived), created date range.
- **Pagination:** 25.
- **Empty states:** "No campaigns yet — New campaign" vs "No campaigns match these filters — Clear filters".
- Header button: **New campaign**.

### 7.2 Campaign page (`admin.campaigns.show`)
- **Sticky header:** name, status badge, paused reason if any, counters, buttons **Start / Pause / Resume / Edit / Archive** (shown per status), Restore when archived.
- **Tabs (each fits the screen; the table scrolls inside itself):**
  - **List** — the campaign's leads: name, company, town, email, status, last step sent, last sent. Search: name, company, town, email. Sort: every column, default last name A–Z. Filter: status, last-sent date range. Pagination 50. Row expands in place to show that lead's sends.
  - **Steps** — 1–3 steps: subject, day gap, body. Add / edit / archive a step while `draft` or `paused`; a step that already has sends can be edited (future sends use the new text — history keeps the snapshot) but not archived.
  - **Activity** — every send: when, lead, step, status, reason, replied/bounced/unsubscribed. Search: lead name, email, subject. Sort: when (default newest), lead, step, status. Filter: status, date range. Pagination 50.
- Create/edit form: name, sender (sending addresses, default pre-selected), list (lists without a non-archived campaign), send days, from/to, daily limit.

### 7.3 Lists (`admin.campaigns.lists.*`)
- Lists table: name, leads, new/sent/replied/stopped counts, campaign, last import. Search: name. Sort: name, leads, last import (default newest). Filter: created date range, archived. Pagination 25. Empty states as §7.1. Buttons: New list, Import.
- List page: leads table as §7.2 List tab (same columns + phone), row expands in place to show send history. Lead CRUD: add one lead by hand, edit, archive, restore (restore blocked with a plain message if the email is now on another active lead); **Reset to new** for a `suppressed` lead after its suppression was lifted.
- Archive a list: blocked while its campaign is running (prevent).

### 7.4 Suppressed (`admin.campaigns.suppressions.*`)
Email, reason, when, by which send/campaign. Search: email. Sort: email, reason, when (default newest). Filter: reason, date range, archived (= lifted). Pagination 50. Add by hand (reason `manual`), Lift (archive), Suppress again (restore).

### 7.5 Settings (`admin.campaigns.settings`)
Two parts:
1. **Defaults** — the five settings in §4.
2. **Sending addresses** — table: email, name, default, last sent, health (send + inbox). Add / edit (email, name, signature, server, port, encryption, username, password, inbox server) / Test connection / Make default / Archive / Restore. Archived shown under a toggle.

Validation messages in plain words.

### 7.6 Export
Not in the brief → not built.

---

## 8. Guard rails (prevent or absorb)

| Risk | Rule |
|---|---|
| Same lead/step sent twice (double job, crash, retry) | UNIQUE (`campaign_step_id`, `campaign_lead_id`) + claim-before-send; `unknown` rows never auto-resent |
| Two campaigns on one list | Prevented: list select only offers free lists; server re-checks |
| Sending outside window / over limit | Checked inside the job every run, from the DB, not the UI |
| Editing a running campaign | Edit buttons only in draft/paused; server re-checks |
| Start with no steps / no sendable leads | Start blocked with a plain message |
| Step 2/3 gap below the minimum | Rejected on save |
| `send_from ≥ send_to`, no days | Rejected on save |
| CoreX mailbox login broken | Campaign paused with reason (§6.2-7) |
| Inbox check broken | Banner; sending continues |

---

## 9. Files

**New (as built):** `config/campaigns.php`; `database/migrations/2027_01_04_100000_create_campaigns_tables.php` (all seven tables); `app/Models/Campaigns/{CampaignSender,CampaignList,CampaignLead,Campaign,CampaignStep,CampaignSend,CampaignSuppression}.php`; `app/Services/Campaigns/{CampaignSendService,CampaignImportService,CampaignImportException,CampaignMergeRenderer,CampaignInboxChecker,CampaignSettings}.php`; `app/Jobs/Campaigns/SendCampaignBatchJob.php`; `app/Console/Commands/Campaigns/{SendDueCampaigns,CheckCampaignInbox}.php`; `app/Mail/Campaigns/CampaignStepMail.php` + `resources/views/mail/campaigns/step-text.blade.php`; `app/Http/Controllers/Admin/Campaigns/{CampaignController (campaigns + steps),CampaignListController (lists + import + leads),CampaignSuppressionController,CampaignSettingsController (defaults + sending addresses),GuardsCampaigns (trait: owner re-check, sort/date/leads-table helpers)}.php`; `app/Http/Controllers/Campaigns/PublicUnsubscribeController.php`; `app/Support/Navigation/Hubs/CampaignsTabs.php`; views under `resources/views/admin/campaigns/` and `resources/views/campaigns/public/`; `tests/Feature/Campaigns/CampaignsTest.php`.

**Modified:** `routes/web.php` (owner-only group under `/corex/admin/campaigns` + public `/campaign-unsubscribe/{send}`), `routes/console.php` (two schedules), `resources/views/layouts/corex-sidebar.blade.php` (one item), `bootstrap/app.php` (CSRF exception `campaign-unsubscribe/*` only), `.ai/specs/agency-onboarding-setup.md` §5.1 (deliberately not in wizard), `.ai/CHAT_STARTER.md`.

### 9.1 Build notes — what differs from the text above (2026-11-02)

- **No separate transport class.** A sender is sent through the existing `PerMailboxMailTransportBuilder` via `CampaignSender::toTransientMailbox()` (an unsaved `CommunicationMailbox` carrying the sender's SMTP settings). That keeps the one OutboundMailGuard raw-socket gate (§42 hard rule) in one place instead of copying it.
- **"Next run" is shown but not sortable** — it is computed per row from the window, the limit and the due steps, not stored.
- **Unsubscribe** is one signed URL: GET (`campaigns.public.unsubscribe`) shows the page, POST (`campaigns.public.unsubscribe.confirm`) acts; `List-Unsubscribe` carries the same URL.
- **Inbox parsing tests** use raw header/body strings in the test, not `.eml` fixture files.
- **Schema snapshot NOT refreshed.** `schema:dump` from the test DB would also pull ~20 tables from other lanes' migrations not yet in the committed snapshot (plus a whole-file line-ending churn) — outside this task. Tests stay correct (migrations run on top of the snapshot); refreshing the snapshot is reported to the conductor.
- **Click-through 2026-11-02 (headless Chrome, owner login):** every screen and action clicked, 0 browser errors. Fixed from it: campaign page header sat under the module tabs; the "today" count had no upper bound; the inbox check / Test opened a real IMAP login from a non-live server (now gated, see �6.6).
- **Local verification:** this machine's mail settings trip OutboundMailGuard, so the real transport refuses with `blocked_by_guard` before opening any socket (proved in the run). The rest of the flow was proved with a recording transport, exactly as the test suite does.

**Environment:** nothing in `.env` — sender logins are entered on the Settings page per environment (andre@corexos.co.za's password is typed in there, never committed).

---

## 10. Acceptance / verification

1. `php -l` on every changed PHP file; `php artisan view:clear`; `scripts/dev-check.ps1` 0 new failures; single relevant test files only (non-negotiable #13).
2. Tests (`tests/Feature/Campaigns/`): import input space (§5.2 table, one case each); rollback on mid-import exception; claim idempotency (two jobs on the same campaign send each pair once); window and daily limit; retry/`unknown` rules; unsubscribe GET no write / POST writes / bad signature 403; reply, STOP reply, hard bounce, soft bounce, auto-reply parsing from fixture `.eml` files; non-owner 403 on every route including by-ID.
3. Tinker functional run (local; OutboundMailGuard sends to Mailpit — nothing leaves the machine, nothing connects to mail.hfcoastal.co.za):
   - Import a 5-row list, all to **can.assurance@gmail.com** with different names — **see §11 Q1**.
   - Add sender andre@corexos.co.za with a signature; 2-step campaign through it; show `campaign_sends` rows with the signature in the body.
   - Unsubscribe link → lead `stopped`, suppression row present, no further send.
   - Daily limit 2 → only 2 sends that day; time outside window → 0 sends.
4. A real send to can.assurance@gmail.com from andre@corexos.co.za happens only on a QA host and only on an explicit instruction.

---

## 11. Open points for approval — ANSWERED 2026-11-02, spec APPROVED

- Q1 → plus-addressing approved (`can.assurance+1..5@gmail.com`).
- Q2 → approved: prospects are not written to Contacts.
- Q3 → "one email" needs nothing further; multiple sending addresses as specced.

Original questions:

- **Q1 — the 5-row test list vs dedupe on email.** Five rows with the same address are, by the rule in §5, one lead (the other four are skipped as duplicates). To prove five leads, the verification would use `can.assurance+1@gmail.com` … `+5@gmail.com` (Gmail delivers all five to the same inbox). Approve plus-addressing, or keep a single address and prove dedupe instead.
- **Q2 — the pillar exception in §0.1-6** (prospects are not written to Contacts).
- **Q3 — "one email"** (2026-11-02 instruction: "add a sig and one email etc"). Read as: more than one sending address can be added. If it means sending a single one-off email to one person, that is not in this spec yet.


### 9.2 Audit fixes - 2026-11-02 (branch `AT-534-campaigns-audit-fixes`)

Found in the post-merge audit of AT-534. Where this section differs from the text above, this section wins.

1. **Replies to the last email were never read.** A campaign becomes `done` the moment its last email is out, and the inbox check only
   covered running/paused campaigns, so a reply / STOP to the final email was missed. The check now also covers the sender of any
   campaign that finished within `campaigns.inbox_watch_after_done_days` (30). Older than that: no longer watched.
2. **Hard-bounce rule tightened.** A bounce is hard when its `Status:` field is `5.x.x`; temporary when `4.x.x`. With NO Status field, only a
   real SMTP failure line counts (`550 5.1.1 ...` at line start, or `Diagnostic-Code: smtp; 550 5.1.1 ...`). The old rule matched any
   `5.x.x`-looking text (an IP address in a Received header) and could suppress a good address for good. Anything else is a soft bounce.
3. **Uploaded import files are pruned.** The file is deleted when an import finishes, but an abandoned or failed one stayed on disk (prospect
   personal data). `campaigns:send-due` (every 5 minutes) now deletes upload files older than `campaigns.import_upload_keep_hours` (24).
4. **CSV encoding detection.** The first 64 KB decides UTF-8 vs Windows-1252; a sample cut inside a multi-byte character (only when the
   sample really is 64 KB) no longer makes a valid UTF-8 file read as Windows-1252.
5. **Step numbers.** `campaign_steps` has no unique index on (`campaign_id`, `step_number`) (3.4 said unique). Adding a step now locks the
   campaign row and re-counts under the lock, so a double-click cannot create two "step 2"s; the second request is told the steps changed.
   No migration, so the schema snapshot is unaffected.

**Deliberately NOT changed (decision pending):** a reply containing the whole word STOP / UNSUBSCRIBE anywhere in its own text still opts the
lead out for good (6.6). "Stop by on Tuesday" therefore suppresses a warm prospect. Awaiting the owner's ruling on whether only a reply that
is essentially just STOP should count.

Not fixed, noted: the inbox check reads at most 500 messages per run (date-granular IMAP SINCE) - fine at current volume.


### 9.3 Image signature - 2026-11-02 (branch `AT-534-campaigns-signature-image`)

Asked for by the owner: a sending address can carry a picture signature (logo / image) as well as the text signature. Where this section differs from the text above (6.5 "plain text only"), this section wins.

1. **Setting.** Campaigns -> Settings -> sending address (add or edit): optional **Signature picture**. JPG, PNG or GIF, up to 1 MB and 1200 x 600 pixels (SVG is refused on purpose: it can carry scripts and most mail programs block it). Edit shows the current picture with a **Remove this picture** tick. Replacing or removing deletes the old file. Files are private (local disk, folder `campaign-signatures/`, random names); the owner-only route `admin.campaigns.senders.image` previews them.
2. **What is sent.** A sender WITH a picture sends a two-part email: the same plain text as before (kept as the text copy) plus an HTML version = body, signature text, **the picture embedded inside the email** (no remote image, nothing to "load images" for, no tracking pixel), then the opt-out footer with the unsubscribe link. A sender WITHOUT a picture is unchanged: plain text only. All text and merge-field values are escaped in the HTML; URLs become links.
3. **Never-send-twice is unchanged.** The HTML version is snapshotted on the send row (`campaign_sends.body_html`, NULL = plain only) at claim time exactly like `body`, so a retry re-sends the identical message. If the picture file has gone missing the email simply goes without it (text-only when nothing usable). The picture is read at send time; the snapshot holds only a placeholder.
4. **Test button** on a sender with a picture sends the formatted version (text, signature, picture) to the sender's own address so the look can be checked before a campaign starts.
5. **Migration** `2027_01_05_100000_add_signature_image_to_campaigns` (two nullable columns: `campaign_senders.signature_image_path`, `campaign_sends.body_html`). Schema snapshot not refreshed, same as 9.1 (a refresh touches every lane's test bootstrap and is outside Campaigns).
6. **Not in scope / unchanged:** no per-campaign or per-step picture, no open/click tracking, still no agency scoping and no Setup Wizard step (platform data, 1.2).
