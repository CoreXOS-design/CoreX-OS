# Agency Setup Wizard (AT-57) — coverage gap audit

**Date:** 2026-07-11
**Auditor:** Claude (QA2)
**Scope:** Every setting reachable from `/corex/settings` and Admin → Company Settings,
compared against the wizard in `config/agency-onboarding-copy.php`.
**Explicitly out of scope (per Johan):** the Custom Website section — separate workstream.

> **Correction notice.** An earlier draft of this file claimed the commission step
> rendered only 6 of 23 fields, and then claimed commission could never be saved at all.
> **Both claims were wrong** and have been removed. The first came from grepping for
> `name="..."` in a Blade partial whose inputs are generated inside `@foreach`/`@for`
> loops, so the grep saw 6 of them; the partial in fact renders all 23. The second came
> from hand-reconstructing the validation rules and testing the reconstruction instead of
> the controller — the real rules require FLQA only for tiers 4–7, exactly matching the
> form. **The commission step is complete and correct.** Findings below are stated only
> where they were verified by executing the real code.

---

## Verdict

The wizard's architecture is right: config-driven steps that write through the *same*
savers the settings page uses, so the two cannot drift. The gaps are in coverage, and in
one place that reuse pattern actively bites.

By field count the wizard surfaces roughly half of what an agency needs to configure. The
shape of the gap: **it asks the on/off questions and skips most of the numbers** — it
asked "do you want FICA reminders?" but never "after how many days".

Three defects were found and **all three are now fixed** (§1). Two dead-ends were found;
**both are now closed** (§2). The remaining coverage gaps (§3, §4) are documented and
unbuilt.

---

## 1. Defects found — the saver-precondition bug class (FIXED)

The wizard reuses the settings page's canonical savers. But its step forms carry a
**subset** of each saver's fields. Any saver that coerces an absent checkbox to `false`
therefore silently wipes settings the wizard never showed.

`updatePresentations` even states the now-false assumption in a comment: *"the
presentations form always carries this field, so absence means 'off', not 'leave as-is'"*.
The wizard became a second caller and broke that precondition.

| # | Saver | Field(s) silently disabled | Status |
|---|---|---|---|
| 1 | `SettingsController@updateAgencyDashboardSettings` | `weekend_visible`, `open_hours_enabled` | **Fixed** |
| 2 | `SettingsController@updatePresentations` | `ss_show_complex_section` | **Fixed** |
| 3 | `AgencyController@update` (latent trap, not yet triggered) | `is_active`, all four brand colours, 6 feature flags — plus `name` is `required` | **Designed around** |

**Blast radius of 1 & 2:** a brand-new agency defaults all three columns to `0`, so no
visible harm. But re-opening the Setup Guide from Settings is a supported path
(`resolveOrCreateSetup()` exists precisely for it), and on a configured agency saving those
steps silently switched off quiet hours, weekend calendar visibility, and the presentation
complex section.

**#3 is the important one.** `AgencyController@update` is the obvious place to hang portal
credentials off — and doing so would have **deactivated the agency and reset its branding**
on every save, because six booleans and four colours are force-defaulted when absent. It is
a booby trap for exactly the reuse pattern this wizard is built on. It was left alone; a
narrow saver was written instead (§2a).

**The fix:** guard the coercion with `$request->has()`. Forms that own a toggle post a
hidden `"0"` companion, so *rendered-but-unchecked* still arrives and still saves as false;
*absent* now means "leave it alone". `CompanySettingsController@update` already solved this
correctly with `_present` markers — that guard simply never made it to the other savers.

**Class scan (BUILD_STANDARD §6):** every `$request->boolean()` in the savers the wizard
reuses was checked. The other seven are single-field savers whose forms always render their
own toggle. The class is bounded at the three above.

---

## 2. Dead-ends found — the wizard asked for what it couldn't accept (CLOSED)

**a) Portal credentials.** The properties step's explainer said syndication *"only works
once your portal credentials are saved against the agency"* — and gave the admin nowhere to
type them. They lived only on `/corex/settings/agencies/{id}/edit`, a page the wizard never
named.

Fixed by adding `SettingsController@updatePortalCredentials`: a narrow saver that touches
the six portal columns and nothing else, gated on `manage_performance_settings` — the same
permission the agency-edit route already required, so this widens **where** credentials can
be entered, never **who** may enter them. A blank password means "keep the stored one",
never "erase it".

**b) No team.** There was no "invite your agents" step. An agency could complete the entire
wizard and have **one admin and zero agents** — unable to list a property, run a deal, or
use anything the previous ten steps configured. The final step was "Access & finish", which
asked only about platform-owner remote access.

Fixed by adding step 11, **Invite your team**, writing through the canonical
`UserManagementController@store` (creates the user with an `INVITE_PENDING` sentinel
password and emails them to set their own). Editing/deactivating/deleting stay on the User
Management page — a delete reroutes printed QR codes and deserves its confirmation UI — and
the step links there.

---

## 3. Fields still missing inside existing steps (NOT built)

| Step | Missing |
|---|---|
| identity | `phone_label`, `phone_secondary`, `phone_secondary_label`, `fax`, `popi_url` |
| properties | `updatePropertiesSort` (default list sort); the **`category`** collection group (the settings page has 5 property groups, the wizard has 4) |
| presentations | 23 of 29 keys: `presentations_freshness_days`, `cma_compute_recency_months`, `cma_compute_iqr_multiplier`, `comp_price_band_pct`, `comp_erf_band_pct`, `comp_radius_m`, `comp_radius_widen_steps`, `comp_radius_max_m`, `comp_min_count`, `comp_max_count`, `anchor_divergence_pct`, `range_lower_pct`, `range_upper_pct`, `cma_band_lower_pct`, `cma_band_upper_pct`, + 7 holding-cost defaults. Also `updatePresentationSections` is never surfaced. |
| contacts | Contact **tags / sub-tags** (full CRUD exists at `corex.settings.contact-tags.*`). Contact *types* are correctly omitted — fixed signing roles. |
| compliance | Agency compliance **provisions** (`AgencyComplianceSettingsController`, full CRUD) |
| notifications | Timings for the *other* reminders — `doc_reminder_hours_before`, `lease_reminder_days_before`, `task_reminder_hours_before`, `event_reminder_*`, `auto_archive_done_days`, `working_hours_*`. **Note:** these are in the model's `$fillable` but **not in the saver's `only()` list**, so the settings page cannot set them either — a separate pre-existing gap worth its own look. Also `updateNotificationPreferences` (the per-notification channel/threshold matrix) is absent from the wizard. |

Company Settings fields with no wizard home: `public_contact`, `marketing_unsubscribe_footer`,
`privacy_policy_markdown` + publish/unpublish, `whatsapp_launch_mode_*`,
`prospecting_pitch_temp_lock_minutes`, `show_prospected_badge`, the outreach send-window,
`outreach_live_deal_statuses`, `outreach_queue_*`.

**The POPIA privacy policy is the one to flag hardest** — it is a legal publication
obligation with an explicit publish gesture, and an onboarding wizard that never mentions it
lets an agency go live non-compliant.

---

## 4. Entire settings sections with zero wizard coverage (NOT built)

Rail groups on `/corex/settings` (`settings.blade.php:74`) that no step touches:

| Section | Why it matters at onboarding |
|---|---|
| **Prospecting Setup** | Towns, suburbs, bedroom segments, price bands, buyer-match tiers |
| **Outreach Templates** | Seller outreach WhatsApp/email templates + merge fields |
| **Command Center Rules** | Expectations, automation rules, event classes, thresholds |
| **Rentals** | Rental document types + reminders |
| **Documents / DocuPerfect** | Named fields — the merge fields every generated document depends on |
| **Leave Visibility** | Leave calendar matrix by role/branch |
| Document Types / PDF labels / P24 Suburbs | Filing labels; suburb→P24 ID mapping |

Some are fairly "configure later". But **Prospecting Setup, Outreach Templates and Command
Center Rules ship inert and stay inert** if nobody is told they exist — and the wizard is
the only place an agency would ever be told.

**Standing recommendation:** whatever is left out, the closing step should **name every
section it did not cover and link to it**. Today the wizard ends without ever admitting
there is more. "Not in the wizard" must never mean "the agency never learns it exists".

---

## 5. Verification

No PHPUnit on this host (`composer install --no-dev`), so the fixes were proven by driving
the real controllers with the exact payloads each step posts, inside a rolled-back
transaction. Proven paths:

- All **12** steps render (was 11).
- Notifications: timings save; `weekend_visible` — never rendered — **survives**.
- Presentations: `ss_show_complex_section` **survives** a step that never rendered it.
- Settings page can **still turn all three off** — the guard did not break the canonical form.
- Portal credentials save; agency stays **active**; brand colours **not reset**; a blank
  password **keeps** the stored secret.
- Team: invite creates the agent with an unverified `INVITE_PENDING` account, filed to the
  branch; duplicate email rejected cleanly; incomplete invite rejected with field errors,
  nothing created; inline removal 404s rather than flashing a false "Removed.".

Committed regression tests: `tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php`
(9 cases). **These have not been executed** — this host has no test runner. They must be run
on a dev machine before merge.
