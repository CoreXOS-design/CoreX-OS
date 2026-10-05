# PPRA FFC Renewal — Confirmation of Employment Letter

> Status: Built, verified in a worktree, not yet landed. Branch `ppra-ffc-employment-letter-2026-10-05`.
> Investigation report: `/tmp/ppra-employment-letter-investigation-2026-10-05.md` (2026-10-05).

---

## 1. What this does and why

PPRA requires a Confirmation of Employment letter, signed by the agent and the
agency's principal, for every agent's FFC renewal. CoreX generates this letter
from data already on file, walks the agent and the resolved principal through
a fixed two-step PIN-signing ceremony (agent first, then principal), and
produces a downloadable/printable signed PDF. There is no PPRA submission
step — the letter ends at "signed, download/print" and the agent attaches it
to their own renewal paperwork outside CoreX.

## 2. Pillars

- **Agent (User)** — the subject of the letter; every merge field (name, ID
  number, FFC/PPRA reference, designation) is read live from `users`.
- **Agency** — legal/trading name, PPRA firm number, logo, and the two new
  settings below.
- No Property/Contact/Deal involvement.

## 3. Data model / migrations

- `database/migrations/2026_10_08_090000_create_ppra_employment_letters_table.php`
  — `ppra_employment_letters` (agency_id, user_id, principal_user_id nullable,
  branch_id snapshot, created_by_user_id, status enum, agent/principal
  signature images (encrypted), agent/principal signed_at + signed_ip,
  signed_pdf_path, reminder_last_sent_at, SoftDeletes).
- `database/migrations/2026_10_08_090100_add_ppra_employment_letter_settings_to_agencies_table.php`
  — `agencies.ppra_employment_letter_address_block` (text, nullable, default
  falls back in code to PPRA's own published Sandton address),
  `agencies.ppra_employment_letter_reminder_days` (unsigned smallint, default 2).
- Model: `app/Models/Compliance/PpraEmploymentLetter.php` — `BelongsToAgency`,
  `SoftDeletes`, `scopeVisibleTo()` (own/branch/all via
  `PermissionService::getDataScope('ppra_employment_letters')`).

### Status machine

`draft -> awaiting_agent_signature -> (agent signs, PIN) -> awaiting_principal_signature -> (principal signs, PIN) -> signed`

A letter is created directly as `awaiting_agent_signature` — every merge
field and the principal resolution are validated BEFORE the row is created
(`PpraEmploymentLetterService::missingFieldsFor()` / `create()`), so there is
nothing left to sit in `draft` in practice. `STATUS_DRAFT` stays in the enum
for the status machine named here, not because any code path persists it.

## 4. Principal resolution (Johan's ruling)

- 0 principals flagged (`users.is_principal_practitioner`) for the agency →
  blocked with "This agency has no principal set" + a link to Admin → Users.
- Exactly 1 → auto-selected, no picker.
- >1 → whoever starts the letter picks one (`principal_user_id` posted on
  `store()`); the model's `principal_user_id` is explicitly the hook for a
  later true per-agent mentor override — not built now, just not foreclosed.
- If the signing agent IS themselves the resolved principal, they sign both
  blocks in sequence through the same two PIN steps (audit trail preserved);
  no principal-notification email is sent in that case (no round-trip needed
  — `PpraEmploymentLetterService::signAsAgent()`).

Resolution uses the existing `PractitionerFfcRosterService::principalsFor()`
— no new principal-roster logic was built.

## 5. Signing mechanism

Built on `AgentSignatureService` (the saved-signature + PIN mechanism already
used by My Portal's Signature setup and `EvaluationCertificateController`) —
**deliberately not** the canon web/CDS e-sign pipeline, which has no PIN
concept and no fixed-specific-signer routing (see the 2026-10-05
investigation report §3). This build does not touch any of the e-sign
pipeline-gate files in CLAUDE.md's "E-sign integration moat" list.

## 6. Letterhead / PDF

Reuses the PPRA Inspection Pack letterhead header/footer Blade pattern
(`resources/views/admin/ppra-inspection-pack/letterhead-sample.blade.php`)
and the same `GeneratesPdfViaPuppeteer` trait every other PPRA Inspection Pack
PDF uses — a sibling generator in the same module
(`app/Services/Compliance/PpraEmploymentLetterPdfService.php` +
`resources/views/compliance/ppra-employment-letters/pdf.blade.php`), not a
new letterhead renderer. Unsigned stages render empty signature lines, never
blank merge-field placeholders. The signed PDF is baked once (both
signatures) and stored immutably at `signed_pdf_path`; every later
download/print streams that exact file.

## 7. UI placement and navigation entry

- **My Portal → Documents** (`resources/views/agent/portal.blade.php`): a new
  "PPRA Employment Letter" sub-tab — missing-field block (with fix links),
  "Start new letter" button, the agent's own letters, and — only shown to a
  user holding `ppra_employment_letters.sign_as_principal` — "Awaiting your
  signature as principal". Wired same-day in `AgentPortalController@index`.
- **Dedicated signing screen** — `resources/views/compliance/ppra-employment-letters/show.blade.php`
  (route `ppra-employment-letters.show`): PDF preview iframe, PIN-sign forms
  for whichever step is live, archive action.
- **Admin → PPRA Employment Letters** — sidebar entry added in
  `corex-sidebar.blade.php` next to PPRA Inspection Pack, gated on
  `ppra_employment_letters.view`. Full list (search by agent/principal name,
  sort by created date/agent name/status with a stated default of newest
  first, filter by status/branch/year/date range, pagination, real empty
  state), detail view, download, archive/restore.

## 8. User flow

1. Agent opens My Portal → Documents → PPRA Employment Letter.
2. If any merge field or the principal resolution is missing, the agent sees
   exactly what's missing and a link to fix it; no letter can be started.
3. Agent clicks "Start new letter" (picking a principal first if the agency
   has more than one flagged). Letter is created `awaiting_agent_signature`.
4. Agent enters their signing PIN → their saved signature is baked in →
   status becomes `awaiting_principal_signature` → the resolved principal
   gets an email with a link to open and sign (skipped if the agent IS the
   principal).
5. Principal opens the link, enters their own PIN → their saved signature is
   baked in → the final immutable PDF is produced, status becomes `signed`.
6. The agent gets an in-app notification AND an email that it's signed.
7. Download/print is available at every stage from either screen.
8. While `awaiting_principal_signature`, `SendPpraEmploymentLetterReminders`
   (scheduled daily, `routes/console.php`) re-sends the principal email every
   `agencies.ppra_employment_letter_reminder_days` days (0 = off). No expiry.
9. The agent can archive (soft delete) any unsigned letter at any time and
   start a new one. A signed letter cannot be cancelled this way.

## 9. Permissions

`config/corex-permissions.php`, module `ppra_employment_letters`:

- `ppra_employment_letters.view` — Admin register visibility; scope
  (own/branch/all) resolved via Role Manager + `scope_defaults` exactly like
  `viewing_packs.view`/`deals_v2.view`. Granted by default: agent → own
  (via `scope_defaults`), branch_manager → branch, admin → all (via the
  all-minus-exclude role_defaults rule).
- `ppra_employment_letters.create` — start a letter about oneself.
- `ppra_employment_letters.sign_as_principal` — sign the principal block;
  only takes effect for a given letter when the signer is ALSO that letter's
  resolved `principal_user_id` (checked server-side every time).
- `ppra_employment_letters.manage` — Admin archive/restore, and gates the
  agency settings save.

## 10. Search / sort / filter / scoping (BUILD_STANDARD §1b/§1c, decided up front)

**Admin list** (`admin.ppra-employment-letters.index`):
- Search: agent name, principal name.
- Sort: created date (default, newest first), agent name, status.
- Filter: status, branch, year, date range.
- Pagination: 25/page, real empty state (different copy for "no letters yet"
  vs "no letters match this filter" vs "no archived letters").
- Scoping: own/branch/all via `PpraEmploymentLetter::scopeVisibleTo()`,
  enforced on list, detail (`show`), and `download` — direct-URL-by-id from
  an out-of-scope admin 404s (verified by test).

**My Portal**: self-service only — an agent only ever sees/creates/signs
letters about themselves, or awaiting their OWN principal signature; no
search/sort/filter needed at that scale (own data only).

**Agency isolation**: `BelongsToAgency` on the model is the hard boundary,
independent of the above; verified by test that a different agency's admin
gets a 404 on direct-URL access to both the My Portal and Admin show routes.

## 11. Settings

Agency Settings → "PPRA FFC Employment Letter" section
(`resources/views/corex/settings.blade.php`, saved via
`CoreXSettingsController::savePpraEmploymentLetterSettings`, gated on
`ppra_employment_letters.manage`):

- **Addressee block** ("RE:" line) — defaults to PPRA's own published Sandton
  address (`PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK`) — the
  regulator's own address, not HFC's, so it is correct out of the box for any
  agency while remaining editable.
- **Remind the principal every N days** — integer, default 2, 0 = off.

### Deliberately NOT in the Setup Wizard

Per CLAUDE.md Non-negotiable #10a, every new setting is either surfaced in
the Agency Onboarding Setup Wizard or explicitly recorded here as a deliberate
omission. These two settings are **not** in the wizard:

- They are compliance-tier settings an agency configures once it is already
  running (and has at least one agent due for FFC renewal), not something
  relevant during initial onboarding.
- The sensible default (PPRA's real address, a 2-day reminder) works
  correctly for every agency, including a brand-new one, with zero setup —
  nothing ships inert by being left out of the wizard.
- This mirrors the judgement already made for the PPRA Inspection Pack's own
  settings section, which is also not in the wizard for the same reason.

## 12. Audit trail

`agent_signed_at` / `agent_signed_ip` and `principal_signed_at` /
`principal_signed_ip` are recorded at the moment each PIN-sign succeeds
(`PpraEmploymentLetterService::signAsAgent()` / `signAsPrincipal()`), using
the real request IP (`$request->ip()`).

## 13. Acceptance criteria

- [x] Zero/single/multiple principal resolution, each with the correct
      on-screen behaviour.
- [x] Missing merge data blocks letter creation with an exact, actionable list.
- [x] Fixed-order two-signer PIN ceremony; self-sign-both works with no
      email round-trip, still through both PIN steps.
- [x] Signed PDF is immutable, baked once, downloadable/printable at every
      stage (empty lines before signing).
- [x] Agent can archive an unsigned letter; a signed letter cannot be
      cancelled this way.
- [x] Own/branch/all scoping enforced on the Admin list, detail, and download
      — direct-URL-by-id blocked for out-of-scope users.
- [x] Cross-agency access denied on both the My Portal and Admin show routes.
- [x] Reminder email re-sent on the agency's configured cadence; no PPRA
      submission mechanism built anywhere.
- [x] Soft deletes only; no hard delete path exists.
- [x] Permission keys added; sidebar + My Portal nav entries added same day.
- [x] No HFC-specific wording/defaults — the PPRA address is the regulator's
      own address, reminder cadence defaults sensibly for any agency.

## 14. Files created / modified

**Created:**
- `database/migrations/2026_10_08_090000_create_ppra_employment_letters_table.php`
- `database/migrations/2026_10_08_090100_add_ppra_employment_letter_settings_to_agencies_table.php`
- `app/Models/Compliance/PpraEmploymentLetter.php`
- `app/Services/Compliance/PpraEmploymentLetterService.php`
- `app/Services/Compliance/PpraEmploymentLetterPdfService.php`
- `app/Http/Controllers/Compliance/PpraEmploymentLetterController.php`
- `app/Http/Controllers/Admin/PpraEmploymentLetterController.php`
- `app/Mail/Compliance/PpraEmploymentLetterPrincipalNotificationMail.php`
- `app/Mail/Compliance/PpraEmploymentLetterSignedMail.php`
- `app/Console/Commands/SendPpraEmploymentLetterReminders.php`
- `resources/views/compliance/ppra-employment-letters/{pdf,show}.blade.php`
- `resources/views/admin/ppra-employment-letters/{index,show}.blade.php`
- `resources/views/emails/compliance/ppra-employment-letter-{principal,signed}.blade.php`
- `tests/Feature/Compliance/PpraEmploymentLetterTest.php`
- `.ai/specs/ppra-ffc-employment-letter.md` (this file)

**Modified:**
- `routes/web.php` (My Portal + Admin route groups)
- `routes/console.php` (reminder scheduler)
- `config/corex-permissions.php` (4 new keys, role_defaults for
  branch_manager/agent)
- `resources/views/layouts/corex-sidebar.blade.php` (Admin nav entry)
- `resources/views/agent/portal.blade.php` (My Portal Documents sub-tab)
- `app/Http/Controllers/Agent/AgentPortalController.php` (loads the My Portal
  card's data)
- `app/Http/Controllers/CoreX/SettingsController.php` (settings saver)
- `resources/views/corex/settings.blade.php` (settings section)
- `database/schema/mysql-schema.sql` (snapshot refreshed, DEFINER stripped)
