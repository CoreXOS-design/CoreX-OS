# Platform Company Profile (RR Technologies) — Spec

> Status: **Built and proven on QA1 (cc5, 2026-10-06): interface, owner page, company email signature.** Awaiting Johan's test before Staging.
> Area: **System Developer** (owner-only), beside Platform E-Sign.
> Sister specs: `agency-timeline-and-platform-esign.md` (consumer #1: the CoreX Subscription Agreement web
> document, lane cc3), `agency-onboarding-setup.md` (NOT affected — see §8).
> Pillars: none directly. This is the platform owner's own letterhead record; it is deliberately **not** an
> agency, has no `agency_id`, and is invisible to every agency.

## 1. Business requirement (Johan, 2026-10-06 09:34, verbatim)

"we going to need a company page in corex for rr tech like agencies have. place where we can put the logo,
company details etc for the letterhead, place to upload company logo to use in letterhead, email signatures
etc. ... it will form part of the contract as well."

One record. Everything that renders "CoreX / RR Technologies" on a document or an email reads it from here
instead of hard-coding it.

## 2. STEP 0 — what already exists (investigated read-only, file:line on `origin/QA1` 56868e36b)

### 2.1 Agency company details
`Admin\CompanySettingsController::update()` (`app/Http/Controllers/Admin/CompanySettingsController.php:96`) —
validates (`:100-140`) trading_name, tagline, address, phone(+label), phone_secondary(+label), fax, email,
reg_no, vat_no (`required_if:vat_registered`), ffc_no, ppra_number, ncc, fic_no, email_disclaimer, colours, logo
— then `$agency->update($data)` (`:270`). Columns live on `agencies` (`Agency::$fillable`, `app/Models/Agency.php:220`
carries `logo_path`). Route `admin.company-settings` (`routes/web.php:4765`), gated by permission
`manage_performance_settings` — an **agency** permission, wrong for a platform record.

### 2.2 Logo upload
`CompanySettingsController.php:133` rule `image|mimes:jpg,jpeg,png,webp|max:2048`; stored on the **`public`**
disk at `agencies/{id}/logo.{ext}` (`:243-246`), **overwriting** the previous file (`:234-241`) — no version
history. Rendered through `asset('storage/'.$logo_path)` (`Agency.php:923`, `company-header.blade.php:62`).

### 2.3 Letterhead builder / how documents render it
There is no "builder": the agency letterhead is the Blade component
`resources/views/docuperfect/web-templates/components/company-header.blade.php`. It resolves branch → agency
from the logged-in user (or `$previewAgency`; **falls back to the `hfc-coastal` agency** at `:29`), draws a
bordered box: trading name, logo, two-column contact strip (address, Reg, VAT, NCC, email, cell | fax, FFC,
FIC, second cell). `LetterheadRefresher::refresh()` (`app/Services/Docuperfect/LetterheadRefresher.php:24`)
re-resolves that component inside stored e-sign HTML by matching its inline-style signature
(`HEADER_STYLE_NEEDLE`). PPRA pack uses `PpraLetterheadSampleService` + `admin/ppra-inspection-pack/letterhead-sample`;
proforma PDFs embed the logo as a data URI (`resources/views/proforma/pdf.blade.php:56`).

### 2.4 Email signature handling
Agencies have **no editable email signature**. Emails get a per-agent footer from
`BaseSignatureMail::getAgentFooter()` (`app/Mail/Signatures/BaseSignatureMail.php:204`) built from agent + agency
fields; its "no sending agent" branch (`:228-245`) is a name/email stub. Only `marketing_unsubscribe_footer`
and `email_disclaimer` are free text.

### 2.5 Owner-only gating and navigation
`owner_only` middleware (`app/Http/Middleware/OwnerOnly.php`, alias `bootstrap/app.php:108`) +
`abort_unless($u->isOwnerRole(), 403)` inside every action, **no permission key by design** (a key is grantable
via Role Manager) — `routes/web.php:4667-4675`, same as Platform E-Sign. Sidebar: System Developer area,
`resources/views/layouts/corex-sidebar.blade.php:2830` (Platform E-Sign nav-item).

### 2.6 Reusable as-is for a non-agency owner?
| Component | Reusable? | Decision |
|---|---|---|
| `company-header` letterhead component | **No.** Bound to Agency/Branch, falls back to HFC, FFC/FIC/Fax lines meaningless for RR Technologies. Passing a stub `$previewAgency` would work mechanically but would put an agency-shaped, HFC-fallback component on a platform document. | Own small letterhead renderer (logo left, company block right, optional strap line). |
| Agency logo upload rules | Partly — same 2 MB cap; agency version overwrites and has no png/svg. | Own versioned logo store (no overwrite, no hard delete). |
| `CompanySettingsController` | No — agency-id route model + agency permission. | Own owner-only controller. |
| `BaseSignatureMail` footer | No — per-agent. | Own company signature (§4). |
| `owner_only` + sidebar slot + flash partial `admin.partials.platform-flash` | **Yes.** | Reused. |

## 3. Data model (migration `2026_10_06_120000_create_platform_company_tables`)

* `platform_company` — exactly one row (`singleton` TINYINT UNIQUE DEFAULT 1, enforced by the DB and by
  `PlatformCompany::current()`): legal_name, trading_name, registration_number, vat_registered, vat_number,
  directors (json `[{name,title}]`), physical_address, postal_address, email_general, email_support,
  email_accounts, **send_from_address, send_from_name** (migration `2026_10_06_150100_add_sender_to_platform_company` — the From of every Platform E-Sign /
  Subscription Agreement email, see §3a), phones (json `[{label,number}]`), websites (json `[url]`), strap_line, letterhead_footer,
  email_signature_html, bank_details (**encrypted** json), logo_id, `version` (optimistic lock), timestamps.
  Seeded **in the migration** (so every environment has it): RR Technologies (Pty) Ltd; trading CoreX OS; reg
  2026 / 444132 / 07; not VAT registered; directors Johan Reichel, Andre Roets; 3123 San Lameer, R61 Lower South
  Coast Road, Southbroom, KZN, 4277; admin@/support@corexos.co.za; (039) 004 0125 · 076 618 5578 · support
  076 423 2426; www.corexweb.co.za, www.corexos.co.za.
* `platform_company_logos` — versions, never deleted: path (private `local` disk), original_name, mime, size,
  uploaded_by, soft deletes. `platform_company.logo_id` points at the current one; NULL = the built-in asset
  `public/images/corex-os-logo.svg` (the CoreX OS wordmark — the repo had no logo file, only the CSS wordmark).
* `platform_company_audit` — who / when / action / `changes` json `{field:{from,to}}`. Bank details are logged
  as "changed" with no values.

## 3a. Sending address and sender name (`send_from_address`, `send_from_name`)
The From of **every** Platform E-Sign mail (all six mailables use `SendsFromPlatformCompany`, which calls `PlatformCompany::mailFrom()`) is the
company's own sending address and name — never the box-wide `MAIL_FROM_*`, which belongs to whichever agency the install was first set up
for. Replies go to the person who sent the agreement (Reply-To). It is operational, so it is **not** pinned to a sent document (reminders
always use today's address). On the page: "Sending address" and "Sender name", both required, in Company details.
* **Validation (syntax only — saving must work offline, so no DNS/MX lookup):** the address is trimmed, lower-cased, an RFC email, max 255,
  and a bare mailbox — no display name, `<` `>` `"` `,` `;` or whitespace smuggled into it. The sender name is max 150 and cannot contain control
  characters (line breaks), `<`, `>` or `"` (Symfony's `Address` already strips CR/LF; this refuses them up front).
* **Domain warning (advisory, never blocks a save):** if the address's domain is not the install's mail domain (`MAIL_FROM_ADDRESS`) or app host
  (or a parent/child of either), the field shows "The sending address is on X, which is not the domain this system sends mail from …" —
  mail from a domain that is not set up to send for CoreX is the usual cause of refused or spam-foldered agreement emails.
* Falls back to the seeded address/name only if the stored address is not a valid email (cannot happen through the page).

## 4. The interface other lanes call (STABLE — do not rename)

```php
use App\Models\Platform\PlatformCompany;

$c = PlatformCompany::current();                 // never null, never an agency
$c->letterheadHtml(context: 'web'|'pdf');         // header block, self-contained inline styles
$c->letterheadFooterHtml(context: 'web'|'pdf');   // footer text block
$c->emailSignatureHtml();                         // sanitised; generated default when none saved
$c->logoUrl();                                    // absolute, streamed, public (route platform-company.logo?l=<logo id|0>)
$c->logoDataUri();                                // base64 data URI — used by the 'pdf' context
$c->legal_name; $c->trading_name; $c->registration_number; $c->vat_registered; $c->vat_number;
$c->directors; $c->physical_address; $c->postal_address; $c->email_general; $c->email_support;
$c->email_accounts; $c->phones; $c->websites; $c->bank_details; $c->strap_line; $c->letterhead_footer;
```
`$c->snapshot()` / `PlatformCompany::fromSnapshot(array)` — the non-sensitive fields + `logo_id` as a plain array, and an unsaved company
rebuilt from it (every helper above works on it). Used to pin a sent document (§7a). Bank details are never part of a snapshot.
The public logo route takes `?l=<logo id>` (0 = built-in wordmark) and serves exactly that version (soft-deleted versions included, so a
pinned document keeps its logo); with no `l` it serves the current logo.
Presentation helpers (also stable — `AgreementCompany` in Platform E-Sign already calls them):
`directorNames()`, `phoneList()` (`[{label,number}]`, blank numbers dropped), `websiteList()`, `addressLines('postal'|null)`,
`emailList()`, `letterheadFooterText()`, `defaultEmailSignatureHtml()`, `PlatformCompany::websiteHref($site)`,
`PlatformCompany::preview(array $attrs)` (unsaved copy for live previews).

`'pdf'` context: print layout, black on white, logo embedded as a data URI (no network fetch from Puppeteer).
`'web'` context: responsive, logo by URL. Neither ever renders a blank block — missing optional values are omitted,
never printed as empty labels.

## 5. The page — System Developer → "Company — RR Technologies" (`admin.platform-company.*`, `/admin/platform-company`)
Owner-only (route middleware + `abort_unless(isOwnerRole())`). Sections: Logo (upload / replace / restore a
previous version) · Company details · Letterhead (strap line, footer text, live web + PDF preview) · Email
signature (editor + preview + "use standard") · Bank details (optional, encrypted) · History.
**Search/sort/filter/pagination:** only the History list is a list — newest first, 25 per page, filter by
action; empty state "No changes recorded yet". **CRUD:** a single-record settings page has no Create/Archive
by design; logos follow Create (upload) / Read / Replace / Archive-by-supersession (never deleted) / Restore. Restoring the logo that is
already current says "already the current one" and writes no audit row.
**Scoping:** platform-wide, owner only; agencies never reach it. Validation, trimming, optimistic-lock
(`version`) and transaction-wrapped saves per BUILD_STANDARD §2–4.
**Layout (restyled 2026-10-06, Johan chose "Settings sidebar"):** a left sidebar lists the six sections and
stays in view while the page scrolls; only the selected section is shown. The sections are hidden, not removed,
so there is still ONE form and ONE Save for details/letterhead/signature/bank, and edits survive switching
sections. Logo (own upload/restore forms, applies immediately) and History (read-only) hide the save bar. The
live preview (screen, PDF, email signature) opens in a slide-in panel from the save bar. The section to open
is, in order: the section holding the first server validation error, the `#pc-…` URL hash (history filter and
old links), the section the user saved from, then Logo. A required field left empty in a hidden section opens
that section before the browser reports it. No field, route, validation or permission changed.

## 6. Security notes
* SVG logo is sanitised on upload (no script, event attributes, `javascript:`, `foreignObject`, external refs)
  and streamed with `nosniff` + a locked-down CSP. The public logo route exposes only the logo (not sensitive).
* **The public logo route does not serve every version.** `/platform-company/logo` (no `l`) is the current logo; `?l=N` is served only
  when N is 0 (built-in), the current logo, or a version pinned by a Platform E-Sign document snapshot (`company_snapshot.logo_id`, so
  already-sent emails keep rendering). Any other uploaded version — a superseded or mistaken upload — answers 404 and cannot be found by
  counting ids. Versioned responses are `immutable` for a year (a version's bytes never change), which keeps mail-image proxies off the
  throttle. Logos are not deleted: a mistaken upload is withdrawn from the public URL simply by uploading/restoring another logo, because
  only the current and document-pinned versions are public. (A dedicated "archive this version" button is not built — it needs its own
  route; not requested.)
* **Upload limits (decompression bomb):** PNG/JPG at most 2 MB **and** 2000 × 2000 px (a small file can decode to a huge bitmap); at least
  64 × 32 px. For PDFs a raster logo longer than 1000 px on its longest side is downscaled in memory when embedded
  (`logoDataUri()`); the stored original is untouched.
* **Preview payload:** the live-preview JSON and the page's initial preview reference the logo by URL (`letterheadHtml('pdf', false)`),
  never as base64 — a refresh is a few KB. Only real PDF rendering (`letterheadHtml('pdf')`) embeds the bytes.
* **Input shape:** a non-text value posted to a text field (`legal_name[]=x`) reads as blank and fails the normal rules with a 422, never a
  500. A director row with a title but no name, or a phone row with a label but no number, is kept so validation says so ("Each director
  needs a name"); only a fully blank row is dropped.
* The signature HTML is sanitised server-side (`App\Support\Platform\SafeHtml::clean` — allow-list of tags/attributes) before it is stored
  and again before it is rendered. Links: `http`, `https`, `mailto`, `tel` or relative only (any other scheme, however obfuscated with entities,
  tabs or newlines, is dropped). **Images: remote `http(s)` images are stripped (they are tracking pixels); only the CoreX logo route
  (`/platform-company/logo`, on this site's own host) or an inline `data:image/(png|jpeg|gif|webp);base64` image of at most ~300 KB survives;
  every other `data:` URL is removed.** `style` attributes are rebuilt from an allow-list of properties (colour, font, text, spacing, border,
  width/height, display …) — CSS escapes such as `\75rl(` are DECODED before checking, so they cannot hide a `url(`; `position`, `float`,
  `z-index`, `background` shorthands and anything with `url(`/`expression(`/`@import` are dropped. Comments and processing instructions are removed.
  The same sanitiser (profile `wording`) guards the Subscription Agreement wording — see agency-timeline-and-platform-esign.md §11.14.
* Bank details: `encrypted` cast; never written to the audit log in clear; never part of the letterhead.

## 7. Consumers / hard-coded leftovers
* **Done here:** `platform-esign/email/{invite,signed}.blade.php` render the company signature + footer through
  `platform-esign/email/_signature.blade.php`.
* **Already on the interface (cc3):** `Services/PlatformEsign/Agreement/AgreementCompany.php` (letterhead lines, logo, beneficiary address).
* **Wired (2026-10-06, cc5):** `AgreementCompany` reads the company record only (no interim constants). The agreement invite and "received"
  emails (party name, "write to" address, footer), the letterhead on screen + PDF + attestation, the RR signer's role label, the
  countersign pages, the recipient notices, the audit-trail wording and the pagination sample all come from the company record.
  A test (`AgreementCompanyPinningTest::test_no_company_detail_is_hardcoded…`) scans `app/Services/PlatformEsign`, its controllers and mail
  classes and `resources/views/platform-esign` for the company's name/address/phones/emails/registration/websites and fails on any literal.
* **Deliberately still literal:** `resources/legal/subscription-agreement/*.md` and the stored wording versions (legal wording — pinned per
  version on purpose; clause B25 prints the details exactly as signed, Part C its support lines); the line in `AgreementContent` that matches
  the source text "Agency initials … RR Technologies initials"; the "For RR Technologies" hint on the countersign page (quotes the wording's
  signature-block label); the migration seed. Seen and NOT changed (outside this task): `sealed.blade.php` generic-document meta line "CoreX OS"
  (product name, not a company detail); the "Awaiting RR countersign" status label in `Document::STATUSES`.

## 7a. Pinning — a sent agreement keeps what it was sent with
`platform_esign_documents.company_snapshot` (json) is written at send from `PlatformCompany::current()->snapshot()`: legal/trading name,
registration, VAT, directors, addresses, emails, phones, websites, strap line, footer text, email signature, and the **logo version**
(`logo_id`; NULL = built-in wordmark). Everything that renders the agreement — recipient page, owner review/countersign pages, the sealed PDF,
the wet-ink attestation, the invite/received emails, notices, the RR role label — goes through `AgreementCompany::for($doc)`, which reads the
snapshot. A later edit on the company page (details or logo) never alters a sent or signed contract; **new** agreements pin the record as it is
at their send. Resend / reminders reuse the pinned snapshot. Agreements sent before the column existed were pinned to the record as it stood
when the migration ran; a document with no snapshot (should not occur) falls back to the live record.

## 7b. Logo on the contract letterhead
> Superseded by §4a: the display height is 60 px / 45 pt and the width cap is 45% of the header; the paragraph below is the original wording.

Screen sheets and PDFs (sealed, wet-ink download, attestation) show the pinned logo at a **fixed 40 px / 34 pt height** with the company
block (name, address, contact line) beside it. PDF width follows the logo's own proportions, capped at 190 pt (a very wide logo is scaled down
keeping its proportions so it can never squeeze the company block); screen uses `object-fit: contain` inside a 220 px cap. With no uploaded
logo the built-in CoreX OS wordmark is used (`public/images/corex-os-logo.svg`).

## 8. Not applicable
* Setup Wizard (non-negotiable 10a): this is a platform-owner record, not something an agency configures.
* Domain events (non-negotiable 9): no cross-pillar reactivity.
* API catalogue (non-negotiable 7): session web pages, same as Platform E-Sign; the logo route is a public
  asset stream, not a JSON API.

## 9. Acceptance
1. `PlatformCompany::current()` returns the seeded record with no blank letterhead on a fresh DB.
2. Exactly one row can exist (second insert fails at the DB).
3. Non-owners get 403 on every page route; the logo route is the only public one.
4. Phone change + logo upload show in both letterhead previews; the previous logo is kept and restorable.
5. Every save writes an audit row; a failed save leaves nothing behind (no half-saved row, no orphan file).
6. A stale form (someone saved in between) is refused with a plain message, not overwritten.
7. Platform E-Sign emails carry the company signature.
8. Every Platform E-Sign email is sent From the company's sending address and sender name (§3a); an address with a display name, brackets,
   commas or spaces, or a sender name with line breaks / `<` `>` `"`, is refused with a plain message; a sending address on another domain
   shows a warning but saves.
9. `/platform-company/logo?l=N` serves only the built-in, current or document-pinned logo versions; any other id is a 404.
10. A logo over 2000 px on either side is refused with no file left behind; the PDF embeds a bounded version; the preview JSON carries
    no base64 image.
11. `legal_name[]=x`-style input is a validation error, not a server error; a director/phone row with a title/label but no name/number
    is reported; restoring the current logo does not claim it was restored.

## 4a. Letterhead logo sizing — one rule everywhere (cc2, 2026-10-06, follow-up after the Staging promo)
Whatever logo is uploaded, it is displayed by ONE rule (`PlatformCompany::logoSizePx()` / `logoBoxPt()` / `logoMetrics()`):
- **fixed display height** — 60px on screen, 45pt in the PDFs (the same height); **width follows the logo's shape**;
- **never wider than 45% of the header** (then scaled down together, keeping its shape) so it can never squeeze the company details beside it;
- **never upscaled past its own size** (a 40×20 px image is shown at 40×20 px, not blown up);
- **crisp in the PDFs**: a raster logo is embedded at up to 1000 px on its longest side (larger ones are downscaled in memory first, uploads are capped at 2000 × 2000 px) and scaled down by DomPDF; an SVG stays vector.
Used by: the agreement sheets (recipient page, owner preview, RR countersign screen), the agreement and attestation PDF headers (`AgreementCompany::logoBoxPt()` delegates here), `/legal`, the email signature block, and the company page letterhead previews (web and PDF).
The company page shows the uploaded image's pixel size and a recommendation: the logo on its own, landscape about 3:1 (e.g. 900 × 300 px), trimmed tight with no empty space, transparent PNG / white background or SVG, at least 300 px tall, up to 2 MB — not a whole letterhead page with the address on it.
The rule never crops: if an uploaded image has large empty margins (or contains the whole letterhead), the mark simply looks small — that is reported to the owner, not silently trimmed.
Tests: `tests/Feature/Platform/LogoSizingTest.php`.

