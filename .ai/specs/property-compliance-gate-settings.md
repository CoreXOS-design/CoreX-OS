# Property Compliance Gate Settings — per-agency on/off, disclaimer, audit trail

> Status: **APPROVED by Andre 2026-10-26** (Q1–Q4 answered, §14). Building on AT-509-Multple-changes.
> Requested by: Andre, 2026-10-26 (branch AT-509-Multple-changes)
> Pillars: **Property** (gate on marketing), **Agent/User** (who switched it), **Contact** (FICA gate reads seller FICA)

---

## 1. What this is and why

Every property shows a "marketing readiness" checklist before it can be marketed:

```
✓ Mandate          Mandate on file
✗ FICA             FICA outstanding — … Verify seller FICA
✓ Disclosure       Disclosure on file
✓ Photos           92 photos uploaded
✓ Listing details  All required listing details complete
Marketing is blocked until all gates are green.
```

Different agencies run this differently. Some do FICA outside CoreX, some don't want the
disclosure gate, and some want no gate at all. This feature lets the agency decide, in its own
Settings section **Settings → Agency → Compliance Switches** (Andre 2026-10-26: its own section,
not under Properties & Listings):

1. Switch each gate on or off.
2. Switch the whole compliance check off with one master switch.
3. Before anything is switched off, accept a disclaimer that the agency takes responsibility
   for compliance (FICA, POPIA, Property Practitioners Act).
4. The system records every switch with who, when, IP address and the exact disclaimer text
   accepted. CoreX keeps that record permanently so it can prove the agency chose this.
5. A **system-owner Dev Settings page** lists every agency, which gates it has on or off, and
   the full history.
6. **History is fixed at go-live.** A listing that went live while a gate was off stays live
   and shows "This check was switched off when this listing went live". Switching the gate
   back on applies it only to listings that go live after that.

## 2. What exists today (verified 2026-10-26)

| Piece | Where | Note |
|---|---|---|
| Gate evaluation | `app/Services/Compliance/MarketingReadinessService.php` `statusFor()` | Single source of truth for the panel, share surfaces, syndication |
| Document gates (Mandate, Disclosure, FICA, any doc type) | `agency_document_type_compliance.is_compliance_required` via `AgencyComplianceDocTypeService` | **Already per-agency**, but only editable on the PDF Splitter doc-types admin page (`admin/splitter/doc-types`), with no disclaimer and no history |
| Photos gate (≥4), Listing-details gate | Hardcoded in `statusFor()` | Cannot be switched off today |
| Go-live freeze | `properties.compliance_snapshot_at` + `compliance_snapshot_data` (v2) | Once set, the listing is ready forever and later gate changes don't affect it. **Rule 6 builds on this.** |
| Global kill switch | `DevSetting compliance_checks_disabled` (Dev Settings) | One switch for **all agencies**. Not changed by this spec (see §10) |
| Readiness panel | `resources/views/corex/properties/partials/readiness-panel.blade.php` | |
| Drive-tab checklist | `complianceChecklistFor()` | Must agree with the gate |

## 3. The gates an agency controls

| Gate key | Label | Backed by |
|---|---|---|
| `master` | Property compliance check (all gates) | new |
| `doc:<slug>` (one per required document type: mandate, disclosure, fica, …) | Document type label | existing `is_compliance_required` |
| `photos` | Photos | new |
| `details_complete` | Listing details | new |

- **Master off** means every gate is off and the property is marketable as soon as it's
  captured. The individual switches keep their positions, so switching master back on
  restores exactly what was on before.
- **Default for a new agency is every gate ON**, the same as today. Nothing changes for any
  agency until it actively switches something off.
- Rentals keep the existing rule that disclosure never applies to a rental
  (`rental_not_required_slugs`).

## 4. Data model

### 4.1 `agency_compliance_gate_settings` (new)
Holds the current state of the switches that have no existing home.

| col | type | |
|---|---|---|
| id | bigint | |
| agency_id | FK agencies | unique with gate_key |
| gate_key | varchar(64) | `master` \| `photos` \| `details_complete` |
| enabled | bool, default true | |
| timestamps, softDeletes | | |

A missing row means **enabled**. Document gates stay on `agency_document_type_compliance` so
there is one source of truth for each gate, never two.

### 4.2 `compliance_gate_changes` (new, append-only)
The permanent record of every change. There is no update or delete path in code, not even
soft delete, because this is CoreX's evidence.

| col | type | |
|---|---|---|
| id | bigint | |
| agency_id | FK | |
| gate_key | varchar(64) | e.g. `doc:fica`, `master` |
| gate_label | varchar | label at the time, kept even if the type is renamed later |
| action | enum `switched_off`, `switched_on` | |
| user_id | FK users | |
| user_name, user_email | varchar | stored as they were at the time |
| ip_address | varchar(45) | `$request->ip()` (trusted proxies honoured) |
| user_agent | varchar(512) | |
| disclaimer_version | varchar(32), nullable | set on `switched_off` |
| disclaimer_text | text, nullable | the **exact rendered text** the user accepted |
| disclaimer_accepted_at | timestamp, nullable | |
| created_at | timestamp | when the change took effect (SAST shown) |

### 4.3 Snapshot v3
`snapshotCompliance()` writes `snapshot_version: 3` and adds:

```json
"gates_off_at_go_live": [
  {"gate_key":"doc:fica","label":"FICA","change_id":123,
   "switched_off_at":"2026-10-26T10:14:00+02:00","switched_off_by":"Jane Smith"}
]
```

Every gate that was off at the moment of go-live is listed, which includes all gates when
master was off. v2 snapshots (every listing that is live today) read exactly as they do now.

## 5. Single writer

New `App\Services\Compliance\ComplianceGateSettingsService` is the **only** code that may
change a gate's state:

- `isEnabled(int $agencyId, string $gateKey): bool`, which applies the master switch first.
- `setGate(agencyId, gateKey, enabled, User, Request, ?acceptedDisclaimerVersion)`: in one
  DB transaction it writes the state and appends the `compliance_gate_changes` row.
  Switching **off** without the current disclaimer version accepted is refused (HTTP 422),
  and the server enforces this, not just the pop-up.
- `offGatesFor(int $agencyId): array` is used by the snapshot.

`statusFor()`, `complianceChecklistFor()` and `isMarketable()` read through this service.
A gate that is off **does not appear as a red or green row**. It appears as a grey row,
"Switched off by your agency", so the agent knows why it isn't checked.

**The PDF Splitter doc-types page's "compliance required" tick** is the existing back door
for switching FICA, Mandate or Disclosure off without a disclaimer. It is changed so the
tick shows the current state read-only, with a link that says "Manage in Settings →
Properties & Listings → Compliance checks". Otherwise the audit trail could be bypassed.

## 6. Go-live rule (the "turned off then back on" case)

| Listing | Gate switched off on 1 Nov, back on 1 Jan |
|---|---|
| Went live 1 Nov – 31 Dec (gate off at go-live) | Stays live. Panel shows: **"FICA — this check was switched off by {agency} when this listing went live (switched off 1 Nov 2026 by Jane Smith)."** Not re-blocked on 1 Jan. |
| Went live before 1 Nov | Unaffected throughout (already snapshotted with the gate on). |
| Goes live after 1 Jan | Gate applies normally. |
| Captured in December but **not yet live** on 1 Jan | **OPEN QUESTION Q1.** |

This works because of the existing go-live snapshot. The listing's record keeps the state of
each gate at the moment it went live.

## 7. UI

### 7.1 Settings → Agency → "Compliance Switches" (its own page)
Route `corex/settings/compliance-switches` (`corex.settings.compliance-switches.edit/update`),
linked from the Settings rail's Agency group. The form itself is one shared partial
(`corex/settings/partials/compliance-switches-form.blade.php`) so the settings page and the
Setup Wizard render the same controls and disclaimer and post to the same saver.

- Master toggle at the top: "Compliance check before marketing".
- Under it, one toggle per gate (the agency's document types + Photos + Listing details),
  each with one sentence explaining what it blocks.
- Below that: "Last changed: FICA switched off by Jane Smith, 26 Oct 2026 10:14".
- **Switching anything OFF** opens a modal with the disclaimer (§8). The user must tick "I
  have read and accept this on behalf of {agency name}", and **Confirm** stays disabled until
  they do. Cancel leaves the toggle as it was.
- **Switching ON** needs no disclaimer. It is still recorded, and a one-line confirmation
  says it applies to every listing that is not live yet (Q1).
- The document list shows every active document type: the ones currently required first, then
  "Other document types you can require". Turning one ON makes it a marketing requirement,
  exactly like the old "Required to market" tick.

### 7.2 Readiness panel and Drive checklist
- Grey "switched off" rows (§5) on the readiness panel for listings that aren't live yet.
- The Drive-tab checklist is a "what still needs uploading" list, so a switched-off check is
  simply not on it (and with master off the list is empty). It therefore never disagrees with the gate.
- "Switched off when this listing went live" rows from snapshot v3 for live listings.
- If master is off: the panel says "Compliance checks are switched off for this agency.
  Marketing is not blocked." instead of "Marketing is blocked until all gates are green."

### 7.3 Dev Settings → "Agency compliance switches" (system owner only)
Route `admin/dev-settings/compliance-gates`, `owner_only` middleware like its siblings,
plus a nav entry in the Dev Settings menu.

**Screen A: agency overview.** One row per agency: agency name, master on/off, a chip per
gate (green on / red off), the latest change, and whether a disclaimer was accepted.
- Search: agency name.
- Sort: agency name (default A–Z), last change date, number of gates off.
- Filter: "has any gate off" yes/no, gate (e.g. only agencies with FICA off), last change
  date range.
- Pagination: 25 per page. Empty state: "No agencies found."

**Screen B: change history** (all agencies, or one agency clicked from A). Columns: date and
time (SAST), agency, gate, switched off/on, who (name + email), IP address, disclaimer
accepted (Yes + version, with a "view the exact text" expander).
- Search: agency name, user name/email, IP.
- Sort: date (default newest first), agency, gate.
- Filter: agency, gate, action, date range.
- Pagination: 50 per page. Empty state: "No compliance switches have ever been changed."
- CSV export of the filtered history (system owner only).

Both screens are cross-agency by design, so they **explicitly drop `AgencyScope`** (see the
AgencyScope owner-switcher blind spot). They are read-only. There are no edit or delete actions.

## 8. Disclaimer (draft wording — Johan to approve; ideally reviewed by the agency's attorney)

Stored in `config/corex-compliance.php` as `gate_disclaimer.version` + `gate_disclaimer.text`
with `{agency}` and `{gates}` placeholders. One acceptance covers every check switched off in
the same save, so the text names them all (`{gates}` = "FICA, Photos"), and that exact rendered
text is stored on each of those history rows. It is system-owned, not agency-editable, because
it protects CoreX. Changing the wording means bumping the version, and the old accepted text
stays in each history row unchanged.

> **You are switching off the "{gate}" compliance check for {agency}.**
>
> While this check is off, CoreX will allow listings to be marketed without verifying it.
> By confirming, {agency} acknowledges that:
>
> 1. {agency} remains solely responsible for its compliance obligations under the Financial
>    Intelligence Centre Act 38 of 2001 (FICA), the Protection of Personal Information Act 4
>    of 2013 (POPIA), the Property Practitioners Act 22 of 2019 and the rules of the Property
>    Practitioners Regulatory Authority (PPRA), and any other applicable law.
> 2. CoreX OS provides this check as a tool only. Switching it off is the agency's own
>    decision, and CoreX OS accepts no liability for any non-compliance, penalty, loss or
>    claim arising from listings marketed while it is off.
> 3. This decision, with your name, the date and time, and your IP address, is permanently
>    recorded.
>
> ☐ I have read and accept this on behalf of {agency}.

## 9. Permissions
- New key `compliance_switches.manage` in `config/corex-permissions.php`. Agency owner roles
  hold it through the owner bypass. `admin` holds it through its all-minus-exclude default on
  fresh installs, and a migration grants it to every existing `admin` role that holds
  `access_settings`. Anyone else is ticked per role in Role Manager. It gates the settings card, the save route and
  the controller check. Agents can see the grey rows but cannot change switches.
- Dev Settings screens: `owner_only` (system owner), the same as the other Dev Settings pages.
- Routes: settings save under the existing `corex.settings.*` group. If the toggles save via
  JSON, the endpoint lives under `/api/v1/…` with a `->name()` (Non-negotiable #7).

## 10. Not in scope / reported only
- `DevSetting compliance_checks_disabled` (global, all agencies) is left as it is. It's
  reported here because it is a single switch across every agency (multi-agency rule) and
  produces no audit row. Removing it or folding it into this feature is a separate decision.
- The PPRA Inspection Pack register (`documentGateSummaryFor()`) keeps reporting whether the
  mandate, MDF and FICA are actually on file. It shows the truth, not the switch. That is
  intended, because an inspector must see what is really missing.

## 11. Setup Wizard (Non-negotiable #10a): in the wizard (Q3 = yes)
The **Compliance** step (the step that already covers FICA, POPIA and the PPRA) renders the
shared partial after its own (`partial` → `['agency-setup.steps.compliance',
'agency-setup.steps.compliance-switches']`) and registers `ComplianceSwitchesController@update`
as a saver. The partial only renders for a user holding `compliance_switches.manage`. §6.1: the saver does nothing unless the marker field
`compliance_switches_submitted` is posted, and it only reads the gate keys that were posted.
Switching off in the wizard shows the same disclaimer and records the same history row.

## 12. Acceptance criteria
1. Agency A switches FICA off. The disclaimer is shown and the switch can't be confirmed
   without the tick. Afterwards a draft property with no FICA becomes marketable and the
   panel shows FICA greyed "switched off".
2. A `compliance_gate_changes` row exists with user, name, email, IP, user agent, version and
   the exact text. A POST without the accepted version is rejected with 422 and nothing changes.
3. Agency B is unaffected by Agency A's switches (multi-agency test).
4. A listing goes live while FICA is off, then FICA is switched back on. The listing stays live
   and the panel shows "switched off when this listing went live (date, by whom)". A new
   listing is blocked by FICA again.
5. Master off makes every gate off. Master on restores the previous individual positions.
   Both are recorded.
6. The Splitter doc-types page can no longer change the compliance-required flag.
7. Dev Settings screens list all agencies and the full history with search, sort, filters,
   pagination, an empty state and CSV export. A non-owner gets 403, including on direct URL.
8. Listings that are live today (v2 snapshots) render exactly as before.
9. Tests: `tests/Feature/Compliance/ComplianceGateSettingsTest.php` covers 1–8.

## 13. Files
- **new** migration ×2, `database/schema/mysql-schema.sql` regenerated (DEFINER-stripped)
- **new** `app/Services/Compliance/ComplianceGateSettingsService.php`
- **new** `app/Models/ComplianceGateChange.php` (no update/delete)
- **new** `app/Http/Controllers/Admin/ComplianceSwitchesAuditController.php` + views under
  `resources/views/admin/dev-settings/compliance-switches/` (index, history, _header)
- **new** `app/Http/Controllers/CoreX/ComplianceSwitchesController.php`,
  `resources/views/corex/settings/compliance-switches.blade.php`,
  `resources/views/corex/settings/partials/compliance-switches-form.blade.php`,
  `resources/views/agency-setup/steps/compliance-switches.blade.php`
- **mod** `MarketingReadinessService.php` (read via service, snapshot v3, grey rows, Drive checklist empty when master off)
- **mod** `readiness-panel.blade.php`
- **mod** `resources/views/corex/settings.blade.php` (rail link only), `routes/web.php`
- **mod** `SplitterDocTypeController.php` + `admin/splitter/doc-types.blade.php` (read-only tick)
- **mod** `config/corex-compliance.php` (disclaimer), `config/corex-permissions.php`,
  `config/agency-onboarding-copy.php` + `AgencySetupWizardController::stepData()` (wizard)
- **mod** Dev Settings rail (`admin/dev-settings/index.blade.php`)
- **new** `tests/Feature/Compliance/ComplianceGateSettingsTest.php`

## 14. Decisions (Andre, 2026-10-26)
- **Q1: Yes.** A not-yet-live property must pass a check that is switched back on. Only the
  go-live snapshot protects a listing.
- **Q2: One set** of switches for sales and rentals.
- **Q3: Yes**, in the Setup Wizard (§11).
- **Q4: Wording approved** as written in §8. The section is its own Settings section,
  "Compliance Switches".

### Original questions
- **Q1.** A property captured **before** a gate is switched back on but **not yet live**.
  Must it now pass the gate before it can be marketed? *Recommended: yes.* It hasn't gone
  live yet, and the agency just said it wants this check, so it is the safer compliance
  position. The alternative ties it to the capture date.
- **Q2.** Is one set of switches for sales **and** rentals enough, or does an agency need to
  switch FICA off for rentals only? *Recommended: one set now.* Disclosure is already
  automatically skipped for rentals.
- **Q3.** Should the switches appear in the Setup Wizard (§11)?
- **Q4.** Approve the disclaimer wording (§8). Should an attorney review it before it goes live?
