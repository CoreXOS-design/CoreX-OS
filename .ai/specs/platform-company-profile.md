# Platform Company Profile (RR Technologies) — Spec

> Status: **Interface built on QA1 (cc5, 2026-10-06); page in the follow-up commit.**
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
  email_accounts, phones (json `[{label,number}]`), websites (json `[url]`), strap_line, letterhead_footer,
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

## 4. The interface other lanes call (STABLE — do not rename)

```php
use App\Models\Platform\PlatformCompany;

$c = PlatformCompany::current();                 // never null, never an agency
$c->letterheadHtml(context: 'web'|'pdf');         // header block, self-contained inline styles
$c->letterheadFooterHtml(context: 'web'|'pdf');   // footer text block
$c->emailSignatureHtml();                         // sanitised; generated default when none saved
$c->logoUrl();                                    // absolute, streamed, public (route platform-company.logo)
$c->logoDataUri();                                // base64 data URI — used by the 'pdf' context
$c->legal_name; $c->trading_name; $c->registration_number; $c->vat_registered; $c->vat_number;
$c->directors; $c->physical_address; $c->postal_address; $c->email_general; $c->email_support;
$c->email_accounts; $c->phones; $c->websites; $c->bank_details; $c->strap_line; $c->letterhead_footer;
```
`'pdf'` context: print layout, black on white, logo embedded as a data URI (no network fetch from Puppeteer).
`'web'` context: responsive, logo by URL. Neither ever renders a blank block — missing optional values are omitted,
never printed as empty labels.

## 5. The page — System Developer → "Company — RR Technologies" (`admin.platform-company.*`, `/admin/platform-company`)
Owner-only (route middleware + `abort_unless(isOwnerRole())`). Sections: Logo (upload / replace / restore a
previous version) · Company details · Letterhead (strap line, footer text, live web + PDF preview) · Email
signature (editor + preview + "use standard") · Bank details (optional, encrypted) · History.
**Search/sort/filter/pagination:** only the History list is a list — newest first, 25 per page, filter by
action; empty state "No changes recorded yet". **CRUD:** a single-record settings page has no Create/Archive
by design; logos follow Create (upload) / Read / Replace / Archive-by-supersession (never deleted) / Restore.
**Scoping:** platform-wide, owner only; agencies never reach it. Validation, trimming, optimistic-lock
(`version`) and transaction-wrapped saves per BUILD_STANDARD §2–4.

## 6. Security notes
* SVG logo is sanitised on upload (no script, event attributes, `javascript:`, `foreignObject`, external refs)
  and streamed with `nosniff` + a locked-down CSP. The public logo route exposes only the logo (not sensitive).
* The signature HTML is sanitised server-side (allow-list of tags/attributes; `javascript:`/`data:` URLs
  stripped except the CoreX logo data-URI) before it is stored and again before it is rendered.
* Bank details: `encrypted` cast; never written to the audit log in clear; never part of the letterhead.

## 7. Consumers / hard-coded leftovers — see the lane report
`platform-esign/email/{invite,signed}.blade.php` now use the company signature/footer (this build).

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
