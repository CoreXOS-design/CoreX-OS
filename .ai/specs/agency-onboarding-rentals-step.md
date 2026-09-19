# Rentals step in the take-on wizard — one home for every rental setting

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-19
**Author:** cc4
**Pillar:** Property (every setting here governs how a rental property is tracked); no Contact/Deal/Agent
connection of its own — this spec is the onboarding surface for settings that already belong to
`leases.md`, `rental-inspections.md`, and `rental-work-orders.md`.

---

## 0. Why this spec exists, in Johan's own words

Two settings shipped homeless, each held back deliberately rather than guessed at:

- The lease expiry-notice window (`leases.md` §5.2) was in fact wired into its own wizard step
  (`'leases'`) and its own settings page — see §1 below, this is more built than the ruling implied.
- The two rental-inspection windows (`rental-inspections.md` §3.5, fault-report window and
  out-inspection signing window, both defaulting to 7 days) were built into `RentalInspectionSetting`
  in Stage 1 of that build but never wired to any wizard step or settings page — Stage 5 was held
  specifically because there was no natural home for them among the wizard's 9 (now 18) steps.

Johan's ruling, verbatim: **"We will have to set up a rental in the take on wizard with all things
rental related."** One step, not settings scattered across the wizard. This spec designs that step,
lists everything that belongs in it — including things that turned out to already half-exist — and
specifies exactly how it avoids a documented incident class where a wizard step silently destroyed
settings on agencies it never meant to touch.

---

## 1. Correction to the brief — the `'leases'` step already exists and is more built than expected

Before writing this spec I checked what actually exists on QA1, not just what the specs claim (this
codebase has real precedent for a spec claiming "built" when it wasn't — `leases.md` §1.2 corrects its
own earlier optimistic claim about lease-field extraction). What I found is the opposite situation:
**more is built than the task brief assumed.**

Confirmed directly against `origin/QA1`, not inferred from a spec:

- `app/Models/LeaseSetting.php` — `expiryNoticeWindowDaysFor()`, `DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS = 60`.
- `app/Http/Controllers/CoreX/LeaseSettingsController.php` — a real, narrow, two-method controller.
  `edit()` renders `corex.settings.leases`; `update()` validates and writes exactly one column,
  `expiry_notice_window_days`, nothing else.
- Routes `corex.settings.leases.edit` / `.update`, gated on a `leases.manage_settings` permission
  (`routes/web.php:2842-2845`).
- A settings-hub entry under an existing **"Rentals" section** (`resources/views/corex/settings.blade.php:103`
  for the section header, `:104-109` for the two existing links) — `feature-rentals` already groups
  "Rental Applications" and "Leases" as two separate links under one section header. The hub does NOT
  force one combined page per section; each domain keeps its own small page, grouped by label. This is
  the existing convention, not something I'm proposing.
- A wizard step, key `'leases'`, position 8 of 18 in `AgencyOnboardingSetup::STEPS`
  (`app/Models/AgencyOnboardingSetup.php:41`), title "Leases", exactly one control
  (`expiry_notice_window_days`), exactly one saver (`LeaseSettingsController::update`)
  (`config/agency-onboarding-copy.php:330-348`).

**What this changes about the brief:** Johan's ruling reads as "build a new rentals step." The more
accurate framing is "the rentals step already exists under the name `leases` and needs to grow to
cover the rest of rentals" — a smaller, lower-risk change than standing up something new, but one with
a real backward-compatibility question attached (§4). I'm flagging this correction up front rather than
silently building as if from scratch, per this codebase's own standard for catching an earlier
inaccurate claim before it compounds.

---

## 2. What belongs in the step — every rental setting across all three specs

Read in full for this spec: `leases.md`, `rental-inspections.md` (base + lease-id amendment),
`rental-work-orders.md`. Every agency-configurable rental setting either spec names, whether built,
partially built, or not yet built:

| # | Setting | Model / column | Default | Status | Source |
|---|---|---|---|---|---|
| 1 | Lease expiry-notice window (days before end date agents get warned) | `LeaseSetting.expiry_notice_window_days` | 60 | **Built** — step, saver, settings page all live | `leases.md` §5.2 |
| 2 | Fault-report window (days after in-inspection a tenant may report further faults) | `RentalInspectionSetting.fault_report_window_days` | 7 | **Built** (model only, Stage 1) — no wizard/settings surface yet | `rental-inspections.md` §3.5 |
| 3 | Out-inspection signing window (days a tenant has to sign after it opens) | `RentalInspectionSetting.out_inspection_signing_window_days` | 7 | **Built** (model only, Stage 1) — no wizard/settings surface yet | `rental-inspections.md` §3.5 |
| 4 | Work order completion requires a photo | `rental_work_order_settings.completion_requires_photo` (not yet migrated) | `true` | **Not built** — table doesn't exist yet, `rental-work-orders.md` is still an unmerged spec (`cc4-rental-work-orders-spec-2026-09-14`) | `rental-work-orders.md` §8 |
| 5 | Work order overdue-reminder window (days with no status change before an internal reminder fires) | `rental_work_order_settings.overdue_reminder_days` | 3 | **Not built**, same table | `rental-work-orders.md` §8 — flagged there as `[cc4 design call]`, not Johan-specified |

**Explicitly checked and excluded, with reasons, so the list is complete rather than silently short:**

- **Work-order approval/spending threshold** (`rental-work-orders.md` §5.2) — "not requested by Johan,
  not built... raised, not decided." Not a setting yet because there's no decided feature to configure.
  If Johan decides to build an approval gate, ITS threshold belongs in this same step at that time.
- **Deposit handling / trust-ledger reconciliation** (`leases.md` §3.3, `rental-work-orders.md` §5.1) —
  both specs flag this as "a real accounting feature, materially bigger than a field" and explicitly
  do not build it. No setting exists to onboard.
- **One-click lease renewal** (`leases.md` §5.1) — "Johan has NOT asked for this to be built." No
  setting.
- **Inventory lists** (`rental-inspections.md` §9, the spec's own numbering) — named as a future,
  separate spec. Nothing to onboard yet.
- **Rental application field configurability** — cc5 is specifying this separately (per-agency which
  application fields show/are required). Confirmed directly with cc5 this is a field-schema/form-builder
  concept, not a threshold, and does not belong in this step — see §7.
- **DR2 supplier work orders' per-pipeline-step toggle** (`dr2-supplier-work-orders.md` §10a) — a
  different, older, unrelated feature (deal-pipeline work orders, not rental-inspection work orders).
  Its own spec already reasons through, and rejects, putting it in the onboarding wizard, because it's
  per-pipeline-step config set in the pipeline builder, not a global per-agency setting. Cited here only
  as a precedent for why NOT every "work order" concept belongs in this step — this spec's work-order
  settings (#4/#5 above) are genuinely global per-agency values, so the two cases don't conflict.

So the step carries **5 settings total**, 3 buildable today (#1 already live, #2/#3 need wiring) and 2
reserved for when `rental-work-orders.md` itself is built.

---

## 3. Where the step sits, and what changes about it

**Recommendation: expand the existing `'leases'` step in place. Do not create a second step, and do
not rename the step's internal key.** Argued below.

### 3.1 Retitle, don't duplicate

The step's **key** stays `'leases'` (internal, never shown to a user). Its **title** and **intro copy**
change from "Leases" / lease-specific wording to "Rentals" / covering the full scope. Its **controls**
array gains the two rental-inspection settings (and, later, the two work-order settings once that spec
is built) alongside the existing lease control. Its **savers** array gains one new entry — it does not
touch the existing `LeaseSettingsController` entry at all.

```php
'leases' => [                                    // key unchanged — see §4 for why
    'title' => 'Rentals',                        // was 'Leases'
    'intro' => 'How CoreX handles lease expiry, inspection windows, and (once built) work-order '
        . 'reminders for your rental portfolio.',
    'what' => [
        'title' => 'What this covers',
        'body'  => 'Everything here is a timing rule CoreX uses across your rental properties: how '
            . 'far ahead agents get warned of a lease expiring, how long a tenant has to report a '
            . 'fault after moving in, and how long they have to sign an out-inspection.',
    ],
    'savers' => [
        ['controller' => LeaseSettingsController::class, 'method' => 'update'],
        ['controller' => RentalInspectionSettingsController::class, 'method' => 'update'],  // new
    ],
    'controls' => [
        // existing, untouched:
        ['key' => 'expiry_notice_window_days', 'source' => 'leases', ...],
        // new:
        ['key' => 'fault_report_window_days', 'source' => 'rental_inspections', 'type' => 'number',
         'default' => 7, 'min' => 1, 'max' => 90,
         'label' => 'Days a tenant has to report a fault after moving in',
         'explain' => 'After the move-in inspection, a tenant can report anything missed without it '
             . 'counting against them, for this many days.',
         'affects' => 'How long the "report a fault" window stays open on a new tenancy. 7 days suits '
             . 'most agencies — a report after this window still reaches the agent, it is just their '
             . 'call whether to accept it.'],
        ['key' => 'out_inspection_signing_window_days', 'source' => 'rental_inspections', 'type' => 'number',
         'default' => 7, 'min' => 1, 'max' => 60,
         'label' => 'Days a tenant has to sign the out-inspection',
         'explain' => 'Once an out-inspection is ready to sign, the tenant has this many days before '
             . 'an agent may sign on their behalf (with a note recording that they were unreachable or '
             . 'declined).',
         'affects' => 'How long CoreX waits for the tenant\'s own signature before allowing an agent to '
             . 'close it out on their behalf. 7 days suits most agencies.'],
    ],
],
```

Why retitle rather than duplicate into a second step: Johan's own words are "ONE home for all of it."
Two steps — "Leases" and "Rental Inspections" — would be exactly the scattering he ruled against, even
if each one is individually well-formed.

### 3.2 Why the key does not change, argued from the actual persistence mechanism

`AgencyOnboardingSetup::completed_steps` (`app/Models/AgencyOnboardingSetup.php:74`) is a **JSON array
of the literal step-key strings**, not positions — confirmed by reading `markStepComplete()`
(`:260-282`), which pushes the string key directly, and `progressPercent()` (`:243-254`), which does
`array_intersect($this->completed_steps, $steps)`.

**If the key were renamed from `'leases'` to `'rentals'`:** every agency who already completed the old
`'leases'` step has the literal string `'leases'` sitting in their `completed_steps` array. After a
rename, `array_intersect` would no longer find it — that agency's progress bar would drop below 100%,
and the step would read as not-yet-done, for a step they already genuinely completed. This is the exact
failure shape the conductor asked me to guard against: an agency silently shown as having a blank
setting they in fact already configured. A rename is fixable (a one-time data migration rewriting the
string in every `completed_steps` array), but it is an unforced cost for a purely cosmetic internal name
— the user never sees the key, only the title, which this spec already changes to "Rentals" without
touching the key at all.

**Recommendation: keep the key `'leases'`, ship the retitle.** If Johan or the conductor wants the
internal key to also read `'rentals'` for its own sake, that is a one-line addition to this spec (a
data migration rewriting `completed_steps` — the mechanism is simple, I just don't think the cosmetic
gain justifies doing it by default) — flagging it as the alternative rather than silently deciding it's
not wanted.

### 3.3 Position in the sequence does not change — and reordering the array would be actively dangerous

The step stays at its current position (8 of 18, between `'proforma'` and `'properties'`). Two reasons,
one cosmetic and one a real mechanism-level hazard:

1. Cosmetically, "Rentals" sits fine near "Properties" — no one is asking for a different sequence.
2. **`current_step` is a bare integer array-offset, not a step-key**, per the same model
   (`app/Models/AgencyOnboardingSetup.php:75`, cast `'integer'`; resolved at
   `AgencySetupWizardController.php:45` as `STEPS[($setup->current_step ?? 1) - 1]`). It is only
   recalculated when `markStepComplete()` runs. **Reordering `STEPS` — inserting a step earlier in the
   array, or moving `'leases'` to a different position — would silently repoint every agency's stale
   `current_step` at a different step than the one they left off on**, for any agency who is mid-wizard
   and hasn't advanced since. This is a sharper, less obvious version of the same class of risk as the
   key-rename question in §3.2, and it costs nothing to avoid: only ever APPEND new steps to the end of
   `STEPS`, or edit an existing step'S CONTENT in place (as this spec does) — never reorder. Worth
   stating as a standing rule for whoever touches `AgencyOnboardingSetup::STEPS` next, not just for this
   change.

---

## 4. The saver risk — named incident, exact avoidance

The conductor asked for this named precisely, because a settings-corrupting wizard step is worse than
no wizard step at all.

**The incident:** `.ai/specs/agency-onboarding-setup.md` §6.1, "The saver-precondition rule (MANDATORY
— added 2026-07-11)." A wizard step's form carries a SUBSET of its saver's fields, but several savers
were written assuming *"my form always carries this field"* and coerced an absent checkbox to `false`.
When the wizard became a second caller of those same savers, re-opening the guide from Settings
**silently disabled `weekend_visible`, `open_hours_enabled`, and `ss_show_complex_section`** on any
agency that did so — settings they had already turned on, wiped without their knowledge, by a step that
didn't even render those fields. The spec names two sites; **its own line numbers (441/866) are stale** — I checked directly and the
methods have since moved as the file grew: `SettingsController::updatePresentations` is now at
`app/Http/Controllers/CoreX/SettingsController.php:523`, and
`SettingsController::updateAgencyDashboardSettings` is now at the same file's `:1136`. Both are
recorded in `agency-onboarding-setup.md` §6.1's own table as already patched with `$request->has()`
guards — I did not re-verify the guard's exact line, only that both methods still exist at these
(corrected) locations. Worth fixing the stale citation in that spec too, flagged here rather than
silently repeated. `Admin\AgencyController::update` is separately named in the same section as a
standing "booby trap" that must never be reused as a wizard saver at all, because a partial post to it
would deactivate the agency and reset its branding.

**Why this step cannot hit the same bug class, structurally, not just by discipline:**

The incident happened because a **shared, multi-field controller action** (`updatePresentations` owns
many settings) got a second caller (the wizard) that posted only some of its fields. This step avoids
that shape entirely rather than adding a guard to prevent it:

- `LeaseSettingsController::update()` (already built, already proven safe in production) validates and
  writes **exactly one column**. It has no other field to force-default, because it was never given one.
- `RentalInspectionSettingsController::update()` (new, this build) is specified the same way: it
  validates and writes **exactly its own two columns** (`fault_report_window_days`,
  `out_inspection_signing_window_days`) against `RentalInspectionSetting`, and touches nothing on
  `LeaseSetting` or anywhere else. It is a new, narrow, single-purpose controller — not an extension of
  `LeaseSettingsController` and not a shared multi-domain settings action.
- Two savers on one step is already an established, working pattern in this exact config file (the
  `properties` step alone lists `@updatePropertiesPerPage`, `@updateMarketingEnabled`,
  `@updateSyndicationPortals`, and more, per `agency-onboarding-setup.md` §6's own save-path table) — a
  step with multiple savers is not new, and each saver in that list is independently narrow for the
  same reason this one is.
- **No field here is a boolean.** All five settings in §2 that are actually buildable numbers today
  (#1/#2/#3) or a bool (#4, once built) — but #4's `completion_requires_photo` will need the
  `$request->has()` guard from day one when that controller is written, per §6.1 rule 1 verbatim
  ("a boolean write MUST be guarded by `$request->has($field)`"). Flagging this now so whoever builds
  `rental-work-orders.md`'s settings controller doesn't have to re-discover the rule.

**Regression coverage required:** a new test, mirroring
`tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php`'s existing pattern (cited in §6.1 as the
regression coverage for the original incident), asserting: posting only the two rental-inspection fields
to the rentals step never changes `LeaseSetting.expiry_notice_window_days` for that agency, and posting
only the lease field never changes either `RentalInspectionSetting` column. This is the concrete,
automatable proof that the two savers stay independent, not just an assertion in prose.

---

## 5. Defaults — every value, and the agency-configurable rule

No threshold here is ever hardcoded at a call site; every one resolves through a model method that
returns the stored value or falls back to a named `DEFAULT_*` constant, matching the pattern already
proven by `RentalApplicationQualifyingSetting`, `LeaseSetting`, and `RentalInspectionSetting`:

| Setting | Default | Constant |
|---|---|---|
| Lease expiry-notice window | 60 days | `LeaseSetting::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS` (built) |
| Fault-report window | 7 days | `RentalInspectionSetting::DEFAULT_FAULT_REPORT_WINDOW_DAYS` (built) |
| Out-inspection signing window | 7 days | `RentalInspectionSetting::DEFAULT_SIGNING_WINDOW_DAYS` (built) |
| Work-order completion requires photo | `true` | `RentalWorkOrderSetting::DEFAULT_COMPLETION_REQUIRES_PHOTO` (not yet built) |
| Work-order overdue-reminder window | 3 days | `RentalWorkOrderSetting::DEFAULT_OVERDUE_REMINDER_DAYS` (not yet built) — flagged in `rental-work-orders.md` §8 as a `[cc4 design call]`, not a number Johan specified; worth his eyes when that spec is built |

An agency that never touches this step, never opens Settings, and never re-opens the wizard is fully
functional on every one of these defaults from the moment their agency record exists — the
`?? DEFAULT_*` fallback in every `*For($agencyId)` method guarantees this, and is exactly why a missing
settings row is never a broken state, only an unconfigured one.

---

## 6. Existing agencies — how they get these settings, and what does NOT happen

**The safe default already covers every agency, immediately, with no action from anyone.** The
`fault_report_window_days`/`out_inspection_signing_window_days` fallback-to-7 behavior in
`RentalInspectionSetting` (built, Stage 1) applies identically whether the agency was created five
minutes ago or three years ago — there is no "unmigrated" state to worry about for correctness. The
question this section actually answers is **discoverability**: how does an agency that already exists
find out this is now something they can change, given the wizard's `completed_steps` mechanics mean
they will NOT be nagged about it (§3.2 — the step key isn't new, so it doesn't newly appear as
incomplete)?

**Two paths, both already-established mechanisms, neither of which is new to this spec:**

1. **The Settings hub gets one new link.** `resources/views/corex/settings.blade.php`'s existing
   `feature-rentals` section (§1) already lists "Rental Applications" and "Leases" as separate links
   under one header. This spec adds a third: **"Rental Inspections"**, pointing at a new
   `corex.settings.rental-inspections.edit` route (new `RentalInspectionSettingsController@edit`,
   narrow, matching `LeaseSettingsController@edit` exactly), gated on a new
   `rental_inspections.manage_settings` permission. This is the PRIMARY path — always visible, not
   dependent on wizard state at all, and it's how `leases.md`'s own setting is discoverable today with
   no special existing-agency handling beyond exactly this.
2. **"Re-open setup guide"** (already a permanent link in Settings, `route('corex.agency-setup.index')`,
   confirmed at `settings.blade.php:29-39`) takes an agency back into the wizard. Per
   `AgencyOnboardingSetup::isActive()`'s own docblock, "a COMPLETED setup stays re-openable... completion
   is not an inactive state for re-entry." Navigating (or being resumed) to the now-retitled "Rentals"
   step shows all five controls, including the two new ones, with their real saved values pre-filled if
   already set, defaults shown otherwise — the same `edit()`-style resolution `LeaseSettingsController`
   already does.

**What this spec deliberately does NOT do, and why:**

- **No forced re-completion.** I will not strip `'leases'` out of any agency's `completed_steps` to make
  the step "reappear as outstanding." That would misreport an agency's genuine progress (they DID
  complete the lease setting) and is the wizard-progress-level version of exactly the corruption class
  §4 guards against at the settings-value level — a change that makes an agency's own history lie to
  them is not an acceptable way to improve discoverability.
- **No proactive notification/email/banner announcing the new fields.** No existing mechanism in this
  codebase does this for a settings addition (the original `'leases'` step shipped without one, per
  `leases.md` §5.2's own build note — no announcement is described there either), and inventing one here
  would be new scope beyond what Johan asked for. If Johan wants existing agencies actively notified,
  that is a distinct, statable ask — flagged, not assumed.
- **No migration backfilling a `RentalInspectionSetting` row for every existing agency.** Unnecessary —
  the get-with-fallback pattern already makes "no row" and "row with the same values as the default"
  behaviourally identical. Writing a row for everyone would only be useful if the default were about to
  change and existing agencies needed to be pinned to the OLD value — not the case here.

---

## 7. Boundary with cc5's rental-application field-configurability spec

Confirmed directly with cc5 (cross-session, 2026-09-19) before writing this section, not assumed: their
new spec covers which rental APPLICATION fields show, are required, their labels, and custom fields —
a form/field-schema concept. This spec's five settings are all timing/threshold NUMBERS (or, once built,
one boolean) — a fundamentally different shape that doesn't fit the wizard's existing
`key/type/default/min/max/label` control shape at all. **Zero overlap, by design:** this step will not
define, reference, or reserve space for application-field configuration, and cc5's spec should treat
this step's existence as settled context, not something to coordinate placement against — theirs is
correctly a separate, ongoing-admin surface (a field editor), not a wizard step.

---

## 8. Files to create or modify (none yet written — spec only)

**New:**
- `app/Http/Controllers/CoreX/RentalInspectionSettingsController.php` — `edit()`/`update()`, narrow,
  mirrors `LeaseSettingsController.php` exactly. Validates `fault_report_window_days` (integer, 1-90)
  and `out_inspection_signing_window_days` (integer, 1-60) — ranges chosen to comfortably exceed the
  7-day defaults without allowing an accidentally-huge or zero/negative value; open to adjustment, not
  load-bearing on anything else.
- `resources/views/corex/settings/rental-inspections.blade.php` — mirrors
  `resources/views/corex/settings/leases.blade.php`'s structure.
- `tests/Feature/RentalInspections/RentalInspectionSettingsTest.php` — mirrors the existing
  `tests/Feature/Leases/LeaseSettingsTest.php` (edit/update/validation/scoping), plus the cross-saver
  independence regression test named in §4.
- A permission key `rental_inspections.manage_settings` in `config/corex-permissions.php` (this is a
  DIFFERENT key from the four `rental_inspections.*` keys already added in the Stage 4 build —
  `view`/`create`/`resolve_discrepancy`/`sign_on_behalf` — none of which currently gate settings
  management; this one does, matching `leases.manage_settings`'s exact role).

**Modified:**
- `config/agency-onboarding-copy.php` — the `'leases'` step's `title`, `intro`, `what.body`, `savers`,
  and `controls` (§3.1). No other step touched.
- `routes/web.php` — two new routes, `corex.settings.rental-inspections.edit`/`.update`, adjacent to the
  existing `corex.settings.leases.*` pair.
- `resources/views/corex/settings.blade.php` — one new entry in the existing `feature-rentals` rail
  group (§6), immediately after the "Leases" link.

**Deferred to when `rental-work-orders.md` is built** (not written now — the table doesn't exist):
- `RentalWorkOrderSetting` model, its own narrow settings controller, and its two controls/one saver
  added to this same `'leases'`/"Rentals" step, following the identical pattern established here.

---

## 9. Acceptance criteria

- The wizard step at position 8 (key `'leases'`, unchanged) displays the title "Rentals" and all three
  currently-buildable controls: lease expiry-notice window, fault-report window, out-inspection signing
  window.
- Submitting only the two rental-inspection fields never changes `LeaseSetting.expiry_notice_window_days`
  for that agency (regression test, §4).
- Submitting only the lease field never changes either `RentalInspectionSetting` column (regression
  test, §4).
- An agency with no `RentalInspectionSetting` row reads 7/7 for both windows via
  `faultReportWindowDaysFor()`/`signingWindowDaysFor()` — already true today (Stage 1), unaffected by
  this spec.
- A brand-new `RentalInspectionSettingsController@edit` page exists at
  `corex.settings.rental-inspections.edit`, gated on `rental_inspections.manage_settings`, listed under
  the Settings hub's existing "Rentals" section, immediately reachable without touching the wizard at
  all.
- No agency's `completed_steps` array is rewritten by this change; no agency's progress percentage
  regresses because of this change.
- `AgencyOnboardingSetup::STEPS` is edited in place at index 7 (`'leases'`); nothing is inserted before
  it and nothing is reordered.

---

## 10. Out of scope

- Building `rental-work-orders.md` itself (a separate, larger spec/build already written and pending
  its own go-ahead) — this spec only reserves this step's shape for its two settings once that lands.
- The work-order approval/spending threshold (§2) — not a decided feature.
- Any deposit/trust-ledger accounting settings (§2) — not a decided feature.
- Renaming the step's internal key to `'rentals'` (§3.2) — recommended against by default, named as an
  available alternative if Johan wants it enough to accept the one-time `completed_steps` migration cost.
- Reordering `AgencyOnboardingSetup::STEPS` in any way (§3.3).
- Proactively notifying existing agencies of the new fields (§6) — flagged as a distinct possible ask,
  not built here.
- cc5's rental-application field-configurability spec (§7) — explicitly a separate, non-overlapping
  piece of work.
