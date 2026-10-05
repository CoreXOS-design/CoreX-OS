# PPRA FFC Renewal — Confirmation of Employment Letter

> Status: Landed on QA1, then fixed again same day (2026-10-05) after Johan's QA1
> testing found four real bugs — see §15 below. Branch `ppra-letter-fixes-2026-10-05`.
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
- `ppra_employment_letters.receive` — "Can receive PPRA employment letter"
  (§17). Eligibility, per role per agency: who is listed in the admin New-letter
  picker AND who sees the PPRA Employment Letter tab in their own My Portal.
  Shown in Role Manager beside `ppra_inspection_pack.roster` (module
  `ppra_inspection_pack`, Johan's instruction) but keeps this feature's key prefix.

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

## 15. QA1 bug-fix round (2026-10-05, Johan's testing)

Four bugs found testing the feature live on QA1. All four reproduced in a
real browser (logged in as clones of the real users' role/permission/
designation configuration, built from a read-only query against QA1) before
any fix was written, per Johan's explicit instruction.

**Bug 1 — My Portal PPRA area blank for an Administrator.**
Root cause: a pure Blade structural defect, not a permission issue — the
PPRA pane (`x-show="sub.documents === 'ppra_employment_letter'"`) was nested
inside the COMPLIANCE tab's wrapper (`x-show="tab === 'compliance'"`) in
`resources/views/agent/portal.blade.php`, while its own nav button and the
`sub.documents` key both live in the DOCUMENTS tab. Clicking the sub-tab set
`sub.documents` correctly, but the enclosing `tab === 'compliance'` check
stayed false while viewing Documents, so the pane never rendered — blank,
for every user, every time, regardless of role or permissions. Fixed by
moving the pane block into the Documents tab wrapper, as a sibling of the
other document-type panes. Confirmed via the real QA1 database that the
admin's own `ppra_employment_letters.view`/`.create` grants were already
correct — this was never a permissions bug for admin.

**Bug 2 — PPRA option entirely absent for `office_admin` (Angelique's role).**
Root cause: `config/corex-permissions.php`'s `office_admin` role_defaults
entry never had the `ppra_employment_letters.*` keys added when this
feature shipped — not a zero-keys resolution like the (intentional,
unrelated) `assistant` role, but a literal absence from the include list.
Confirmed against the real QA1 `role_permissions` table: `office_admin` had
zero rows for any `ppra_employment_letters.*` key. Fixed by adding `.view`,
`.create`, `.sign_as_principal` to `office_admin`'s include list (same
shape already used for `agent`/`branch_manager`). Backfill for every
existing agency's `office_admin` role: `php artisan corex:sync-permissions --merge-defaults`
(existing, idempotent, additive-only command — safe to re-run).

**Bug 3 — Admin had no way to create a letter.**
This was the original build's deliberate design (see the now-removed
docblock on `app/Http/Controllers/Admin/PpraEmploymentLetterController.php`)
— Johan's explicit ask on 2026-10-05 reverses it. Added `create()`/`store()`
to the Admin controller: an agent-picker scoped to the SAME own/branch/all
scope as the list (`ppra_employment_letters.view`'s stored scope, via
`PractitionerFfcRosterService::rosterFor()` filtered by
`PermissionService::getDataScope()`), gated on `ppra_employment_letters.manage`.
The created letter runs through the identical `PpraEmploymentLetterService::create()`
status machine and `missingFieldsFor()` validation as self-service — no
second code path. The agent still signs with their own PIN afterward. The
unsigned-PDF download for a draft/awaiting-signature letter already existed
on the Admin `download()` action (falls back to `PpraEmploymentLetterPdfService::generate()`
with a null principal signature) — confirmed working, no change needed.

**Bug 4 — Static helper text on the Admin list; no real empty state.**
Removed the banner subtitle ("Every Confirmation of Employment letter…")
and the empty-state body text ("A letter is started by the agent
themselves…") from `resources/views/admin/ppra-employment-letters/index.blade.php`.
Added a "New letter" primary action in the page banner (always visible, not
just when empty) and a real empty state — shown only when the list is
genuinely empty with no filter active — carrying the same "New letter" CTA
instead of static prose.

**Files additionally created/modified for this round:**
- Created: `resources/views/admin/ppra-employment-letters/create.blade.php`
- Modified: `resources/views/agent/portal.blade.php` (moved PPRA pane into
  the Documents tab)
- Modified: `config/corex-permissions.php` (office_admin include list)
- Modified: `routes/web.php` (admin `create`/`store` routes)
- Modified: `app/Http/Controllers/Admin/PpraEmploymentLetterController.php`
  (`create()`, `store()`, `scopedRoster()`)
- Modified: `resources/views/admin/ppra-employment-letters/index.blade.php`
  (helper text removed, New letter CTA, real empty state)
- Modified: `tests/Feature/Compliance/PpraEmploymentLetterTest.php` (backfill,
  create-on-behalf, scoping, cross-agency coverage)

**Bug 5 (found during landing, not one of Johan's original four) — admin
register reachable by direct URL with 'own' scope.** cc1's concurrent
HR -> Documents nav fix (`.ai/specs/hr-menu.md`, commit `6182b12da`) found
that the sidebar link to this admin register was only ever hidden from an
'own'-scoped agent by an unrelated accident (the old `sidebar.section.admin`
wrapper), not by a real scope check, and explicitly flagged the matching
route-level gap for this lane to close: `admin.ppra-employment-letters.*`
had no scope check at all, only a bare `hasPermission('ppra_employment_letters.view')`
boolean — every agent is seeded 'own' scope on that key (for their own
My Portal self-service letter), which also satisfies the boolean, so an
agent could still reach the ADMIN register by direct URL even though the
sidebar never links there for them. Not a data leak (`scopeVisibleTo()`
already narrows query results to their own row), but the wrong screen for
that role regardless. Fixed with `PpraEmploymentLetterController::assertAdminScope()`
— every admin action (index/show/download/create/store/archive/restore) now
requires scope `branch` or `all`, matching cc1's own sidebar-visibility
logic exactly. Covered by
`test_own_scoped_agent_cannot_reach_admin_register_by_direct_url`.

## 16. QA1 round 2 (2026-10-05 evening, conductor's browser check)

Branch `cc1-ppra-letter-fixes-2026-10-05`. Four faults, root cause first.

**1. Agent missing from the Admin → New letter picker (Angelique Venter).**
Root cause: the picker's candidate list came from
`PractitionerFfcRosterService::rosterFor()`, which hard-filters
`users.role IN ('agent','branch_manager','admin')`. Angelique's role on QA1 is
`office_admin` (designation "Candidate Property Practitioner", FFC number
1275207, active, agency 1) — the one role the whitelist never named — so she was
dropped before the search box was ever involved. Scope was NOT the cause
(Johan resolves to `all`), and neither was her My Portal tab: she already holds
`ppra_employment_letters.view/create/sign_as_principal` on QA1 (the §15 bug 2
grant fix), so her own Documents → PPRA Employment Letter sub-tab shows from
`$ppraLetterCanCreate`. What she actually hit on that tab is fault 4 (the
firm-number blocker).
Bug class: a role name is a poor proxy for "can hold an FFC" — any agency-defined
custom role would be dropped the same way. Fix: new
`PractitionerFfcRosterService::letterCandidatesFor()` — active, non-deleted,
same agency, never an assistant (AT-267 §10), and EITHER role in
agent/branch_manager/admin (unchanged behaviour) OR an FFC number on file (adds
office_admin and any custom role). `Admin\PpraEmploymentLetterController::scopedRoster()`
now uses it, with the own/branch/all narrowing and the store-time
"in your scope" re-check unchanged, so direct-POST by id still 403s outside scope.
`rosterFor()` is untouched (Inspection Pack's role ruling, 2026-09-28).

**2. Empty search.** The agent search only toggled each row's `x-show`, so a
no-match left a blank box. Added a "No agents match" row shown when the search
text matches nobody. Same edit also fixed a latent defect: the row filter
interpolated the name into an inline JS string, so a name containing an
apostrophe (O'Brien) broke the whole Alpine expression; names now go through
`@js()`.

**3. Helper text removed, no replacement.** (a) My Portal → Documents → PPRA
Employment Letter: the "Confirmation of Employment letter for your FFC renewal —
signed by you and the agency's principal." line. (b) Create page subtitle "Pick
the agent this letter is for…".

**4. Firm-number blocker is now a link.** The check is unchanged
(`agencies.ppra_number`, falling back to the agent's branch `ppra_number`). The
message "The agency's PPRA firm number is not set" now links to **Admin → Company
Settings → Company tab → "PPRA Registration Number"** (`route('admin.company-settings')#company`;
a branch-specific number is under the Branches tab). Previously it linked to
`corex.settings`, which has no such field. The link is rendered only for a viewer
holding `manage_performance_settings` (what Company Settings itself requires);
everyone else sees the message as plain text. `missingFieldsFor()` takes an
optional `$viewer` for this; `fix_url` is now nullable. QA1 state (read-only
check, not changed): agency 1 "Home Finders Coastal" has `ppra_number` NULL, so
every agent on QA1 currently sees the blocker until an admin sets it.

**Files:** `app/Services/Compliance/PractitionerFfcRosterService.php`,
`app/Services/Compliance/PpraEmploymentLetterService.php`,
`app/Http/Controllers/Admin/PpraEmploymentLetterController.php`,
`app/Http/Controllers/Agent/AgentPortalController.php`,
`resources/views/admin/ppra-employment-letters/create.blade.php`,
`resources/views/agent/portal.blade.php`,
`tests/Feature/Compliance/PpraEmploymentLetterTest.php`, this spec.
No migration, no new setting (nothing to add to the Setup Wizard).

## 17. Eligibility is a Role Manager setting (2026-10-05 late evening, Johan's ruling)

Branch `cc1-ppra-letter-role-manager-2026-10-05`. **Supersedes the §16 item 1 candidate rule**
("practitioner role OR an FFC number on file"): no hardcoded role list and no FFC-number rule remain in code.

**The setting.** `ppra_employment_letters.receive` ("Can receive PPRA employment letter"), Role Manager →
Admin → PPRA Inspection Pack group (beside "Appears on inspection pack staff roster"). Per role, per agency;
ticking autosaves. It is a membership permission — same rules as the roster key (roles-permissions.md
appendix): read the agency's own live `role_permissions` rows, never `userHasPermission()` for list
membership; an un-tick soft-deletes the row and survives every deploy.

**Where it applies (both, same setting):**
1. Admin → New letter picker — `PractitionerFfcRosterService::letterCandidatesFor()` is now
   `users.role IN (agency's ticked roles)` plus the unchanged filters: active, non-deleted, same agency,
   never an assistant. own/branch/all narrowing, the store-time "in your scope" re-check and "No agents
   match" are unchanged (a direct POST for an unticked role's user 403s).
2. My Portal → Documents → PPRA Employment Letter tab (`AgentPortalController`, `agent/portal.blade.php`) —
   the tab shows when the user holds `.receive` (standard `hasPermission`: owner roles bypass, so the
   platform owner still sees it) or has a letter awaiting their principal signature. The "Start new letter"
   button needs `.receive` AND `.create`; the self-service `store` aborts 403 without `.receive`. Having an
   old letter on record no longer keeps the tab visible on its own.

**Backfill** (`2026_10_08_130000_grant_ppra_employment_letter_receive_permission`, idempotent via
`withTrashed()->firstOrNew()` + restore, `down()` removes exactly the key). Nobody who sees the letter today
loses it: every agency gets agent/branch_manager/admin; plus every role with at least one active,
non-assistant member who appeared in the old picker (e.g. office_admin); plus every role of a user who
already has a letter. Global template rows get only agent/branch_manager/admin (neutral default for new
agencies). `role_defaults` (agent, branch_manager; admin via all-minus-exclude) updated so provisioned
agencies inherit it.

**Not in the Setup Wizard (rule 10a):** the defaults already work for any new agency; whether it should be
walked through there is Johan's call (raised in the lane report, same as the roster key).
