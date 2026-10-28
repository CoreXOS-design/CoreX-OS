# Attorney Portal

> **Status:** BUILD (AT-454 family, QA1 only, 28 Oct 2026). Johan's rulings R1-R6 (28 Oct) are the law of this spec.
> **Cross-refs:** `rental-portal-access.md` (login/OTP/audit pattern reused, NOT the lease scoping), `signed-document-distribution.md` (the audit-trail and "private file, never a /storage URL" discipline), the AT-227/AT-228 distribution matrix (`DocumentDistributionMatrix`, `Dr2DistributionComposer`), `at231-inbound-attorney-comms-filing.md` (the firm/contact directory it shares).

## 1. What this is, and why

When the agent has a deal in conveyancing, the transferring attorney (and the bond attorney) need the deal's papers. Today the agent emails a pack. This gives the attorney a **link of their own**: they open it, confirm with a code emailed to them, and see **only the deals they personally are assigned to**, with the documents CoreX would have sent them, ready to download. No account, no password, nothing to learn.

**Download only (R1).** The attorney cannot upload, tick a step or change a date. They read the milestone dates the agent keeps.

## 2. Pillars

| Pillar | How |
|---|---|
| Deal | Read: the deal, its parties, milestone dates, stage. Never written. |
| Contact | Read: buyer/seller names only (see 7.3). Never written. |
| Property | Read: address; its filed documents (via the matrix, never a free listing). |
| Agent | Read: the deal's agent(s) name, phone, email so the attorney can call them. |

The access itself belongs to the **supplier contact** (`agency_service_provider_contacts`), the person at the firm.

## 3. The access model (R2)

- **One access = one supplier contact at one firm.** A firm with three people has three accesses, each its own link, its own code, its own last-login.
- The attorney sees a deal only if **that supplier contact** is the deal's chosen attorney person:
  - the transferring attorney: `deals.attorney_contact_id` (firm `attorney_provider_id`),
  - the bond attorney: `deals.bond_attorney_contact_id` (firm `bond_attorney_provider_id`).
  - The deal register (DR1 `deals`) is the record the distribution composer already reads for these recipients; it is the one place a deal says *which person*. DR2 party rows (`deal_v2_contacts`) record only the firm, never a person, so they cannot grant a person access; a DR2 deal reaches the portal through its DR1 twin. A deal whose attorney firm is set but whose person is not is shown on the deal page as "Choose the attorney's contact to give them portal access" and grants nobody anything.
  - A colleague at the same firm who is **not** the deal's chosen person sees nothing of that deal.
- `conveyancer` as a role: no deal column carries a conveyancer person today. A supplier contact whose firm is marked conveyancer sees the deals where they are the chosen transfer or bond attorney. The matrix defaults for `conveyancer` are seeded (7.4) so it works the day a column exists.
- Everything is scoped in ONE class, `AttorneyPortalScope`, filtering `agency_id` AND the supplier contact explicitly (the portal has no signed-in staff user, so the global `AgencyScope` does not apply; it is bypassed on purpose and replaced by explicit filters).

## 4. Login (R3)

Link + emailed one-time code. No password.

1. The agent's "Send portal link" creates/rotates the access's token (only a SHA-256 hash is stored) and emails the supplier contact a link `/attorney/p/{token}`.
2. The link page says who they are (their name, the agency) and offers **Email me a code**. It does not reveal the email address in full (`j***@firm.co.za`).
3. The code is the canonical `OtpService` (purpose `attorney_portal:{access_id}`, 6 digits, 10 minutes, 5 attempts, resend cooldown, hourly cap; the same engine and limits as the client portal).
4. A correct code starts a **portal session**: a random token in an HttpOnly cookie, hash stored in `attorney_portal_sessions` (12 hours, sliding-free, absolute). It is separate from the staff login and the client portal login, so nothing here can sign anyone in as a user.
5. An unknown, expired or revoked link is **404** (the same page for all three; it never says which).
6. Every request in the portal re-checks the access is still live (not revoked, not expired, agency switch still on); a revoked access kills its sessions at once.
7. **Link expiry** is agency-configurable (`link_valid_days`, default 30). A signed-in session never outlives the access.

## 5. Settings (per agency, R3/R6) and the setup wizard

`attorney_portal_settings` (one row per agency, read-time defaults like `RentalPortalSetting`):

| Setting | Default | Meaning |
|---|---|---|
| `attorney_portal_enabled` | **off** | Nothing external is exposed until the agency turns it on. Off = no "Send portal link" action, and every link 404s. |
| `access_grace_days` | 30 | Days after a deal is registered/cancelled that the attorney can still reach it. |
| `link_valid_days` | 30 | How long a portal link stays valid before the agent must resend it. |
| `attorney_portal_notify` | **daily** | `off` / `daily` - whether attorneys are emailed when new documents reach their portal (section 15). |

Each reaches the Setup Wizard (rule 10a) on the **Properties & listings** step of `config/agency-onboarding-copy.php` (the wizard has no Deals step; that step already carries the deal-to-property settings) with an `explain` and an `affects`, saved by `AttorneyPortalSettingsController::update`, every field has()-guarded so a partial post never wipes another (onboarding §6.1). If Johan wants a Deals step later the three controls move with their saver; nothing else changes. A settings page lives at `/corex/settings/attorney-portal` (permission `attorney_portal.manage_settings`).

## 6. Permissions

- `deals_v2.invite_attorney` — send, resend and revoke an attorney's portal link (Role Manager; granted to the roles that already hold `deals_v2.distribute_documents` by default through the same migration pattern as that key).
- `attorney_portal.manage_settings` — the settings page.
- The portal itself has no CoreX permission; its permission is possession of a live access.

## 7. Screens

### 7.1 The attorney's "My deals" (`/attorney/deals`)

First-time-user clear, plain words, no CoreX jargon. Header: agency name + "Hello Sipho" + Sign out.

| Column | Source |
|---|---|
| Property | deal address |
| Buyer and seller | names |
| Agent | name |
| Where it is | plain stage: "Offer accepted", "Bond granted", "Lodged at the Deeds Office", "Registered", "Cancelled" |
| Latest document | date of the newest document they can download |

- **Search** (named fields): property address, buyer name, seller name, deal reference.
- **Sort**: every column; default **latest document first** (deals with nothing yet last).
- **Filter**: *Open* / *Finished* (registered or cancelled, still inside the grace days), and a date range on the latest-document date.
- **Pagination**: 20 per page. **Empty states**: "No deals yet. When <agency> adds you to a deal, it appears here." vs "No deals match your search." with a Clear link.

### 7.2 The deal view (`/attorney/deals/{deal}`)

1. Property, reference, and a one-line plain status.
2. **Your contact at <agency>**: the agent's name, phone, email.
3. **The parties**: buyer(s), seller(s) (names), the other attorney if any (firm and name).
4. **Key dates** (read-only): offer accepted, bond granted, lodged, expected registration, registered. Shown only if set; nothing editable.
5. **Documents for you**: a table of file name, type, date added, size, **Download** (and **View** for PDFs/images). Empty: "<Agent> has not filed any documents for you yet. You will get an email when they do." (No promise of an email unless the notification exists: the sentence says "Check back soon" until then.)
6. A finished deal shows a banner: "This deal is registered. You can keep downloading until <date>."

### 7.3 What an attorney is shown about people

Names of buyer and seller; no ID numbers, phone numbers or emails of the parties on the screens (those live inside the documents the matrix releases). The agent's own business phone/email are shown.

### 7.4 Documents: what the attorney gets (R5)

The set is exactly what `Dr2DistributionComposer::defaultDocumentsForParty($deal, <their role on this deal>)` returns: the **matrix** document types for that party role, **filtered to what is actually filed on that deal** (deal, property, linked contacts), de-duplicated. The portal never offers a document the matrix does not name, and one document type removed from the matrix disappears from the portal at once. The matrix is the single source; this feature adds no second list.

**Defaults seeded for every agency (the matrix is editable per agency afterwards).** A default is added only where that agency has **never** had a rule for that exact (document type, role), including a soft-deleted one — so an agency that deliberately removed one is never overridden:

| Document | Transfer attorney | Bond attorney | Conveyancer |
|---|:-:|:-:|:-:|
| Offer to Purchase (OTP) | ✔ (already) | ✔ | ✔ |
| Sale Agreement | ✔ | ✔ | ✔ |
| Mandate | ✔ | | ✔ |
| FICA (the parties' FICA packs) | ✔ | ✔ | ✔ |
| IDs / Identity | ✔ (already) | ✔ | ✔ |
| Proof of Residence | ✔ (already) | ✔ | ✔ |
| Rates & Taxes | ✔ | | ✔ |
| Levy Statement | ✔ | | ✔ |
| Bond Grant (new document type) | | ✔ | |

**FOR JOHAN'S SIGN-OFF.** Notes: *Bond Grant* is a new global document type (`bond_grant`, label "Bond Grant") because no type for a bank's grant letter exists; until filed against a deal it shows nothing. The FICA pack is the parties' FICA documents; they are encrypted at rest and are decrypted only when streamed (7.5). The bond attorney does not get the mandate or the rates/levy papers; the transfer attorney gets everything about the property. Existing stage rules (e.g. "Bond Approved" → bond attorney) are untouched and still drive the *email* send; the portal reads the type-level rules only.

### 7.5 Downloads: authorised, private, logged (R6)

- A download is allowed only if the document is in the set from 7.4 **for a deal that attorney currently sees**. Anything else is a **404**, so a guessed id learns nothing.
- Files stream through PHP (`Document::decryptedContents()`), never a `/storage` URL, with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. "View" is allowed only for the existing inline whitelist (`inlineMimeType()`), otherwise it downloads.

## 8. Audit (R6, POPIA)

Append-only `attorney_portal_logs` (never updated or deleted): `agency_id`, `access_id`, `event`, `deal_id`, `document_id`, `ip`, `user_agent`, `meta`, `created_at`.

Events: `invited`, `resent`, `revoked`, `link_opened`, `code_sent`, `code_failed`, `signed_in`, `signed_out`, `deals_listed`, `deal_viewed`, `document_viewed`, `document_downloaded`, `refused` (a document/deal asked for outside their set, with the id), `session_expired`.
`last_login_at`, `login_count`, `last_ip` live on the access row for the agent's panel.

## 9. Ending access (R6)

An attorney sees a deal while it is open. When it ends the access ends after a grace period:

- **Ended** = registered (`registration_date` set or `accepted_status` R), declined (`accepted_status` D), or cancelled/declined on the DR2 twin; the moment is the registration date, or for declined/cancelled the latest applied stage move to declined/cancelled, falling back to the deal's last update.
- **Grace** = `access_grace_days` (default 30) after that moment, then the deal vanishes from the portal and 404s.
- A deal deleted (archived) in CoreX disappears at once.
- Revoking an access ends all of its sessions at once.

## 10. The agent's side (R4)

- **Supplier contact** (Supplier Directory, the firm's contact row): a **Send portal link** button (only with `deals_v2.invite_attorney` and the agency switch on, and only for a contact with an email). It becomes **Resend link** / **Revoke access** with "Last signed in <date>" once an access exists.
- **The deal's attorney panel** (the Attorney / Bond Attorney part of the deal page): the same button for the chosen person, and a small panel **Portal access**: who has access (name, firm, role on this deal), link status (sent / expires / revoked), last sign-in, last document downloaded. Only people chosen on this deal appear.
- **Send** = rotate token, email (template below), log `invited`/`resent`. **Revoke** = revoke, kill sessions, log.
- Mail: the OTP mailer family's standard envelope; subject `"<Agency> has shared your deals with you"`; plain body: who, what they will see, the link, "You will be asked for a code we email you. Only you can see your deals." Off-production the standard non-production redirect (suppress/redirect) applies, as for all outbound mail.

## 11. Navigation

External: the emailed link. Internal: the buttons above and the settings page link under Settings → Portals, plus the wizard step. No sidebar entry for the attorney (they have no sidebar).

## 12. Files

New: `app/Models/AttorneyPortal{Access,Session,Log,Setting}.php`, `app/Services/AttorneyPortal/{AttorneyPortalScope,AttorneyPortalAccessService,AttorneyPortalSessionService,AttorneyPortalDocuments}.php`, `app/Http/Controllers/AttorneyPortal/*`, `app/Http/Middleware/EnsureAttorneyPortalSession.php`, `app/Mail/AttorneyPortalInviteMail.php`, `resources/views/attorney-portal/*`, migrations, tests in `tests/Feature/AttorneyPortal/`.
Edited: `routes/web.php`, `config/corex-permissions.php`, `config/agency-onboarding-copy.php` + `AgencySetupWizardController`, the supplier-contact list view, the deal page attorney panel, `Deploy/SyncReferenceData` (document type + matrix defaults), `DocumentTypesCatalogueSeeder`.

## 13. Acceptance

1. Attorney A (assigned) sees A's deals and not B's; colleague B at the same firm sees a different set; a deal with the firm but no person shows to nobody.
2. Another agency's access can never reach this agency's deals (404), by token or by guessed ids.
3. A revoked, expired or unknown link is 404; a revoked access's open session stops working on its next click.
4. A document outside the matrix, or on a deal the attorney is not on, is 404 and logged `refused`.
5. Every list view, deal view, view and download writes a log row.
6. Registered/cancelled deals vanish after the grace days; inside them they show the banner.
7. With the agency switch off nothing is sendable and every link is 404.

## 14. Build log (AT-454 family, QA1, 28 Oct 2026)

**Built:** access + session + log + settings tables; `AttorneyPortalScope` (the one scoping class), `AttorneyPortalDocuments` (matrix-driven, narrowed to what is filed on the deal), `AttorneyPortalAccessService` (invite / resend / revoke, link + emailed code, sessions), the attorney pages (`/attorney/p/{token}`, `/attorney/deals`, `/attorney/deals/{id}`, downloads), the agent's Send / Resend / Revoke on the supplier contact and the deal's "Attorney portal access" panel (under Email Parties), the settings page `/corex/settings/attorney-portal` and the three wizard controls, the `bond_grant` document type and the default matrix rows (`AttorneyDistributionDefaultsSeeder`, deployed by `deploy:sync-reference-data`).

**Decisions made while building (all reversible, business consequence in one line):**
- **DR2 parties cannot grant a person.** `deal_v2_contacts` records only the firm; the person is chosen on the deal register (`attorney_contact_id` / `bond_attorney_contact_id`). The portal therefore keys on those; a deal with only a firm chosen is visible to nobody and the deal panel says so.
- **The portal is OFF by default.** It shares deal papers outside the agency, so an agency turns it on deliberately (Settings, or the wizard).
- **Narrower document pool than the email button.** The email button offers every document of the matrix types on the deal, the property and every contact ever linked to the property. The portal offers deal-specific papers (OTP, sale agreement, bond grant, addendum, proforma) only when filed on THIS deal, and personal papers (FICA, ID, proof of address) only for the deal's own buyer and seller. Reason: an earlier failed offer's OTP or an old bidder's FICA on the same property must not reach this deal's attorney (POPIA). The email button was left alone (out of scope) - flagged for Johan.
- **Ended deals:** registered = registration date (or status R); declined/cancelled = the latest applied stage move to declined/cancelled, else the last update; visible until that + grace days.
- **One access per supplier contact.** A resend rotates the token on the same row and ends any open session; the old link dies at once.
- The cookie is HttpOnly, path `/attorney`, 12 hours (platform value in `config/attorney-portal.php`), never longer than the link.

**Not built:** upload-back and milestone updates (R1); an "attorney has new documents" email; a conveyancer person column on deals (the role's defaults are seeded for when one exists); a Deals step in the wizard.

## 15. "New documents" email, and the in-panel contact chooser (follow-up, 28 Oct 2026)

### 15.1 The email

When a document that is in an attorney's portal set (section 7.4) is filed on a deal they currently see, they get ONE short email for that deal - **at most one per deal per calendar day** - so they do not have to keep checking.

- **Setting:** `attorney_portal_notify` = `daily` (default) or `off`, per agency. On the Attorney Portal settings page and in the Setup Wizard (Properties & listings step, with explain / affects). The portal switch must also be on.
- **What triggers it:** documents in THAT attorney's set (the matrix for their role, narrowed to the deal as in 7.4) whose *filed* time is after the last time they were told about that deal. Filed time = the document's created time, or later when it was linked to the deal's property or one of the deal's own parties afterwards. The first time, "after" means after the access was created, so papers that already existed when the attorney was invited never produce an email (the invite is their start). A colleague's deals and documents outside the attorney's set never trigger anything.
- **Wording (plain):** subject `New documents for <address>`. Body: "<Agency> has added new documents for <address> that you can download:" then the KINDS of document ("OTP (Offer to Purchase)", "FICA (2)") - never file names, never a person's name - and an **Open this deal** button. Footer: "We never attach documents to this email. You will be asked for a code we email you before anything opens. You get at most one of these a day for each deal."
- **No attachments, ever.** The email carries no file and no download link of its own.
- **The link:** a signed address (`/attorney/open/{access}/{deal}`, valid for the agency's link life, 30 days by default). It shows the same "email me a code" page, then lands on that deal; if the attorney is already signed in on that browser it goes straight to the deal. It is only for the person whose access it was made for; a revoked, expired or switched-off access makes it a 404, a tampered address a 403.
- **When it runs:** `php artisan attorney-portal:notify-new-documents [--agency=ID] [--dry-run]`, scheduled daily at 07:30. Running it more often is harmless (the once-per-deal-per-day guard). State per (access, deal) in `attorney_portal_notices` (`notified_through`, `last_sent_at`). Each send is logged as `notified` in the audit log with the number of documents.
- **QA1 has no scheduler**: run the command by hand there. Staging / live schedulers pick the schedule entry up on deploy.
- Test mail rules apply: QA1's outbound guard catches the email in Mailpit; it never reaches a real attorney.

### 15.2 The Portal access panel when no contact person is chosen

A deal can name a firm without naming a person; the portal cannot open for a firm. The panel (Email Parties tab) therefore states the next action and puts the control right there:

- **Choose the attorney contact for this deal** - with "the portal is opened for one person, so pick who at <firm> is handling this deal", a list of that firm's active contacts (with their email, or "no email yet"), and **Use this contact**.
- Saving sets the deal-register column the portal scopes on (`attorney_contact_id` / `bond_attorney_contact_id`) through `POST deals-dr2/{deal}/pipeline/attorney-contact` (`permission:create_deals`, the same permission as editing the deal). The contact must be an active contact of THAT deal's firm and the deal's pipeline must not be locked; anything else is refused with a plain sentence.
- If the user may invite but may not edit deals, the panel says "You can't edit this deal. Ask someone who can to choose the contact." and shows no control.
- If the firm has no contacts: "<Firm> has no contacts yet. Add a contact in the Supplier Directory, then come back here."
- After choosing, the same panel shows **Send portal link** for that person.

### 15.3 Files

New: `AttorneyPortalNotifier`, `AttorneyPortalNotifyNewDocuments` (command), `AttorneyPortalNewDocumentsMail` + view, migration `2026_12_29_110000`, tests `AttorneyPortalNotifyTest`. Edited: `AttorneyPortalDocuments::forDealWithFiledAt`, the signed `open` routes and controller methods, `AttorneyPortalAccessController::chooseContact`, the panel partial, the settings page / controller / wizard control, `routes/console.php`.

**Not changed:** the agent's email-button document pool (still the wider pool, awaiting Johan).

