# Rental notices - breach notices, notices to vacate, the breach register

**Status:** built on QA1, 14 Oct 2026 (this document was written BEFORE the code). Johan's brief, 14 Oct 2026. Companion to `lease-notices.md` (a tenant / landlord GIVING notice to end the lease - the review flow) and `rental-portal-access.md` §8 (the older "Send notice" screen and notice templates).

**Rule:** breach notices and notices to vacate are the **agency's own documents** - they relate to the agency's own lease agreement - so CoreX supplies **no template wording**. The agency uploads its own; nothing is seeded.

## 1. What this is

1. **Templates** - the agency uploads three document templates (Word documents with placeholders): *breach notice (payment)*, *breach notice (other)*, *notice to vacate*.
2. **Bulk payment breach** - from the leases list the agent ticks leases and chooses **Breach notice**; a spreadsheet-like screen lists them with an **Outstanding amount** to fill in. Only rows with an amount become a breach.
3. **One-off "other" breach** - the agent picks a lease, who is in breach, the lease clause and the reason.
4. **Notice to vacate** - only on a lease with an issued, unremedied **tenant** breach whose **remedy window has passed**.
5. Every notice is sent from the **agent's own mailbox** (so it is also in their Sent items), the **other party is told**, both parties see the notice in their portal, and it is on the lease's log.
6. A **breach register** lists every breach and where it stands.

## 2. Pillars and events

Contact (tenant, landlord), Property (the lease's), Deal/lease (the breach is a fact about the lease; the lease screen shows breach status and remedy deadline), Agent (the issuing agent sends from their mailbox; the lease agents see the register). Every step writes a `lease_events` row (`breach_issued`, `breach_remedied`, `vacate_issued`, `breach_edited`).

## 3. Templates (Settings -> Rental Notice Templates)

The existing agency templates screen (`RentalNoticeTemplate`, `rental_notice_templates`, agency-scoped, create / edit / archive / restore, search / sort / filter / pagination) gains three **types**: `breach_payment`, `breach_other`, `notice_to_vacate` (the older single `breach` template stays and reads as "other"). **Upload:** the form takes a Word document (`.docx`, up to 10 MB), converts it to the template body with the same converter the e-sign importer uses (Mammoth - `resources/js/mammoth-convert.mjs`), keeps the original file on the template, and the agent can edit the converted text before saving; the body can also still be typed. This is the agency-supplied-document mechanism of the e-sign importer **without** its signature-field detection (a notice is not signed on screen). Placeholders are `{{name}}` and are filled from the lease and the breach: `agency_name agency_address agency_phone agency_email agent_name today property_address tenant_name landlord_name lease_ref lease_start_date lease_end_date monthly_rent amount clause reason breach_date remedy_days remedy_deadline`; anything else is typed by the agent as `name=value` lines. A placeholder still empty at send time **stops the send** and is named. With **no template of a type** the screen that needs it shows a real empty state that links to the templates screen - never a dead end.

## 4. Data model

**`lease_breaches`** (one row per breach; `BelongsToAgency`; soft delete only): `lease_id`, `branch_id`, `kind` (`payment` | `other`), `breach_party` (`tenant` | `landlord`), `amount` (payment), `clause`, `reason`, `status` (`issued` | `remedied` | `escalated`), `issued_on`, `remedy_deadline`, `remedy_business_days` (the window in force when issued), `issued_by_user_id`, `rental_notice_id` (the breach notice sent), `batch_ref` (a bulk send), `remedied_at/by/note`, `vacate_issued_at/by`, `vacate_rental_notice_id`. `rental_notices.lease_breach_id`; `rental_notice_templates.source_file_name/path` (the uploaded Word document). Setting **`lease_settings.breach_remedy_business_days`** (default 20).

## 5. Rules

- **Remedy window** = the agency's `breach_remedy_business_days` (default **20 business days**, the Consumer Protection Act's period for a breach to be put right before a lease can be cancelled for it; weekends and public holidays do not count - `LeaseNoticeReviewService::addBusinessDays`). The deadline = issue date + window. Settings -> Leases and the Setup Wizard's Leases step (`LeaseSettingsController@update`, `has()`-guarded).
- **Payment breach** is against the tenant. **Other breach**: the agent chooses the party (tenant or landlord). **Notice to vacate** is only for a tenant breach, only while it is `issued` (not remedied) and only **after** the remedy deadline; before that the lease screen says "from <date>". Issuing it sets the breach `escalated`.
- **Mark remedied** (agent, with a note) ends the breach (`remedied`); it can no longer be escalated.
- One open (`issued`) payment breach per lease at a time: a second is refused ("a breach notice is already out for this lease") - in a bulk send that lease is skipped and named, the rest go.
- **Sending** (`LeaseBreachService`): fill the agency's template -> PDF -> filed as a Document -> emailed to the party in breach (each contact with an email) **from the issuing agent's own mailbox** through `RentalMailDispatcher` (SMTP + Sent-folder copy; falls back, audited, to the shared mailer when the agent has no mailbox - the existing e-sign behaviour). **The other party is told**: tenant in breach -> the landlord gets "A breach notice has been issued against your tenant X for outstanding R<amount>" (or "...for a breach of the lease"); landlord in breach -> the tenant is told; same mailbox, agency-branded, no CoreX text. Both parties see the notice (breach notice, and the notice to vacate) in their portal under **Documents** (kind *Notice*); the portal **Notices** tab does not carry it.

## 6. Screens (design standard: CRUD, search, sort, filter, pagination, empty state, scoping)

- **Leases list** gains a tick box per row and a **Breach notice** bulk button (permission `rental_breaches.manage`).
- **Bulk breach screen** `/corex/breaches/bulk`: the chosen leases (own / branch / agency scope applies to each id - an id the user cannot see is dropped), columns tenant, property, monthly rent, last paid (not tracked in CoreX yet - shown as a dash), **Outstanding amount** (R, input). Template picker (the agency's active payment-breach templates). Rows with no amount are ignored. **Send** creates one breach per lease with an amount and reports what was sent and what was skipped (and why).
- **One-off breach** `/corex/leases/{lease}/breaches/create`: breach party, lease clause (typed; the agreement's own clauses are not stored as a list in CoreX today, so it is typed - quoted into the notice), reason, template, preview, send.
- **Breach register** `/corex/breaches` (nav: Rentals -> Breach Register): search (tenant / landlord name, property address, clause, reason); sort (issued, remedy deadline, amount, status, property; default newest first); filter (status, kind, who is in breach, issued date range, archived); pagination 25; empty state; own / branch / agency through `Lease::visibleTo()`; direct URL by id blocked. Row -> **breach screen**: the facts, the document(s), who was told, status, **Mark remedied**, **Issue notice to vacate** (only when allowed), archive / restore. Equal-size aligned buttons.
- **Lease screen**: a **Breach panel** (each breach: kind, party, amount, status, remedy deadline, link) and a **Breach notice** button.
- **Permissions:** `rental_breaches.view` (register, breach screen, downloads) and `rental_breaches.manage` (issue, remedy, vacate, archive), added to the roles that can renew leases. Templates stay under `rental_notice_templates.manage_settings`.

## 7. Not changed / reported

- "Last paid if known": CoreX holds no rent-payment ledger yet (`rental-money.md` is not built), so the column is a dash.
- The agreement's clauses are not stored as a pickable list, so the clause is typed.
- The older free-form **Send notice** screen stays (for any other notice the agency has a template for), now with a real empty state linking to the templates screen.

## 8. Tests

`tests/Feature/RentalNotices/`: template upload (docx -> body, original kept, CRUD / archive, empty state), bulk (3 leases, amount on 2 -> 2 notices; skips named; scope; one open breach per lease), one-off, vacate gating (before / after the window, remedied, landlord breach, not offered otherwise), both parties told (rendered mails: agency-branded, no CoreX, the agent as sender), portal Documents for both parties, register (search / sort / filter / scope / archive), lease panel, remedy setting (settings + wizard). All mail faked and rendered.
