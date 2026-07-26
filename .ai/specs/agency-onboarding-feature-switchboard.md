# Agency Onboarding — Feature Switchboard step — Spec

> Status: **Draft pending Johan approval** — 2026-07-18
> Owner: (QA2 lane)
> Parent spec: `.ai/specs/agency-onboarding-setup.md` — this is an ADDITIVE extension of the
> agency-setup wizard, not a new portal. Read the parent first; every rule there (§3.1 no
> parallel settings system, §6.1 saver-precondition, §3.3a inline-only) binds here too.
> Pillars touched: Agent (User), Property, Contact, Deal — the switchboard turns whole
> capabilities on/off, each of which governs one or more pillars. Reads/writes agency-scoped
> config only; ingests no property/contact/deal data (Non-negotiable #10 N/A).
> Sister specs: `.ai/specs/multi-tenancy.md`, `.ai/specs/corex-domain-events-spec.md`.

---

## 1. What this feature does and why (business requirement)

The agency-setup wizard today walks 12 steps of settings, but it never once shows the Admin
**the shape of the product** — *"here is everything CoreX can do for you; turn on what you
want, leave off what you don't."* Feature on/off switches exist, but they are **scattered**:
`marketing_enabled` and the P24/PP syndication toggles live inside the Properties step,
`matches_enabled` inside the Matches step, `split_branches_enabled` inside the Branches step,
and `website_enabled` does not appear in the wizard **at all** (it lives only on the Admin
agency-edit panel). A new Admin has no single place to see the menu of capabilities, and no
plain-English explanation of what each one does before deciding.

**The switchboard fixes this.** A new early wizard step — **"What CoreX can do — turn features
on or off"** — presents every top-level capability as a labelled toggle with a full
plain-English `explain` and a concrete "What this changes:" `affects`, exactly the copy
contract the rest of the wizard already uses (parent §5.1 copy rules). It writes **live**,
through the **existing canonical savers** (parent §3.1) — it is a consolidated front door onto
switches that already exist, never a parallel flag system.

Two things fall out of having one authoritative capability step:

1. **One home per switch.** The master ON/OFF for each feature moves OUT of its detail step and
   INTO the switchboard; the detail step keeps only the *how-it-works* configuration. No switch
   is offered in two places (which would drift and confuse).
2. **Adaptive setup.** A feature turned OFF in the switchboard **skips its dedicated detail
   step** — "You've left Core Matches off, so we'll skip its setup. Turn it on any time." A
   12-step wall becomes a path tailored to what the agency actually uses. This is "built for
   agents, not screens": we don't make anyone configure a feature they turned off.

### Why it makes CoreX *best*, not merely *working*
A settings tree with a switch buried in each section is *working*. A guided, explained menu of
the whole product — that adapts the remaining setup to the choices made — is the front door no
competitor offers. It also pays down real debt: `website_enabled` becomes reachable in
onboarding for the first time, and the scattered feature toggles gain a single, explained,
audited home.

---

## 2. Pillar connections

The switchboard configures **agency-wide capability state** — it does not own pillar rows; it
governs how the pillars behave:

| Pillar | How the switchboard touches it |
|--------|--------------------------------|
| **Property** | Marketing on/off and portal syndication on/off govern how Properties are marketed and pushed to P24 / Private Property; the public website governs how listings are shown. |
| **Contact** | Core Matches on/off governs whether buyer-Contact wishlists are matched to new listings. |
| **Agent** (`User`) | Multi-branch (split branches) governs how agents are grouped and how commission splits attribute; the website governs whether agents are shown publicly. |
| **Deal** | (Indirect) syndication + marketing feed the top-of-funnel that becomes Deals. |

Per Non-negotiable #4: reads agency-scoped capability config and writes enriched config back.
Not an island — it is the front door onto switches the pillars already read.

---

## 3. Architecture decisions (locked)

### 3.1 The switchboard fans each toggle to its EXISTING canonical saver (parent §3.1)
The step declares a `savers[]` list; the wizard's existing `save()` loop
(`AgencySetupWizardController::save`, invokes each saver with the shared `$request`) calls each
one. Each canonical saver reads its own field(s) from the request. **No new write path, no
copied save logic.** Confirmed fan-out map in §6.

### 3.2 One home per switch — move the master toggle out of the detail step
For every capability the switchboard owns, the master ON/OFF toggle is **removed from its
current wizard detail step** and rendered only in the switchboard. The detail step keeps its
*configuration* controls (per-page, ordering, visibility scope, WA template, thresholds, etc.).

| Capability | Master toggle → switchboard | Detail step keeps |
|------------|------------------------------|-------------------|
| Marketing | `marketing_enabled` | (Properties step: per-page, ordering) |
| Property portals | `syndication_p24_enabled`, `syndication_pp_enabled` | (Properties step: nothing extra today; portal credentials stay on agency-edit per parent §5.1) |
| Core Matches | `matches_enabled` | Matches step: `matches_show_on_properties`, visibility scope, WA template |
| Multi-branch | `split_branches_enabled` | Branches step: branch add/remove list |
| Public website | `website_enabled` | (no wizard detail step today) |

The settings page is unchanged — this is a wizard-layout decision, not a settings-page change.

### 3.3 Adaptive step-gating (`gated_by`)
A step may declare `gated_by` = a predicate on current agency capability state. When the
predicate is false, the step is **inactive**: it is not rendered, not counted in progress, and
`show()` of it redirects forward to the next active step. Implemented generically so future
feature-steps plug in by adding one `gated_by` entry.

**v1 gate:** the `matches` step is `gated_by` `matches_enabled`. (It is the one capability today
whose detail is a whole standalone step. Marketing, syndication and website have no standalone
detail step, so nothing else gates a *whole* step in v1 — see §7 for within-step conditional
sections.) The mechanism is built generic; the gate list grows as feature-steps are added.

**Progress denominator becomes the ACTIVE step count.** Today `progressPercent()` divides by
`count(STEPS)`; a gated-off step could never be completed, so 100% would be unreachable. The
denominator moves to `count(activeSteps($agency))` (§7).

### 3.4 Saver-precondition compliance (parent §6.1 — MANDATORY)
Fanning a toggle to a saver makes that saver a **multi-caller**. Four of the five reused savers
currently coerce an absent checkbox to `false` (bare `$request->boolean()`), which is precisely
the §6.1 landmine. Two-part fix, both required, no "later":

1. **Switchboard blade posts a hidden `"0"` companion for every toggle it renders** — so an
   unchecked box still arrives and the toggle saves `false` correctly. (Contract: the
   switchboard form always carries every field its savers read.)
2. **Harden the four bare-boolean savers to the §6.1 reference pattern** — guard each write
   with `$request->has($field)` (absent ⇒ leave alone), and **audit the existing settings-page
   forms** that call them to confirm each posts a hidden `"0"` companion (add it where missing,
   so unchecking on the settings page still turns the feature off). This is "fix the class, not
   the instance" (BUILD_STANDARD): the savers become safe for *any* future caller, not just the
   switchboard. Savers to harden: `updateMarketingEnabled`, `updateSyndicationPortals`,
   `updateMatchesEnabled`, `updateSplitBranches`. `toggleWebsite` is already safe
   (`required|boolean`) and needs no change.

Regression coverage extends `tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php`.

### 3.5 Deliberately NOT in the switchboard (v1)
Out of scope by decision, not oversight — each has a specific reason. Do not add without asking
Johan (parent §5.1 records the same discipline).

- **P24 / Private Property portal *credentials* and `p24_enabled` / `pp_enabled` / `pp_lead_pull_enabled` / `pp_stats_pull_enabled`.** Parent §5.1 already excludes portal credentials from onboarding, and these columns are written only by the `Admin\AgencyController@update` **booby trap** (force-defaults `is_active`, branding and six flags — parent §6.1) with an **auto-enable side effect** (`p24_enabled`/`pp_enabled` are force-set true when username+password are present), so a switchboard "OFF" would be silently overridden back ON. Not cleanly toggleable — stays on agency-edit. Note the *syndication_* keys in §3.2 are a **different** thing (the feature switch "do we push to portals at all"), and those ARE included.
- **Commission sub-toggles `mentor_program_enabled` / `revenue_share_enabled`.** These are commission *configuration*, not top-level features, and they are already rendered — correctly, with §6.1 guards — in the Commission step. They stay there. Duplicating them in the switchboard would violate §3.2 (one home per switch).
- **Presentations / CMA master toggle.** No per-agency `presentations_enabled` column exists today; presentations are always-on and configured (thresholds/scope) in the Presentations step. A real master toggle must also *gate the feature* (hide presentations nav, short-circuit CMA compute) — that is a feature build in its own right, following the `mentor_program_enabled` precedent (parent §5.2: "off means off, not merely hidden"), not a switch. Deferred to a follow-up (§4.2).
- **WhatsApp voice transcription (`wa_transcription_enabled`).** A genuine capability, but it has **no HTTP saver today** (written only by env/migration; read by the transcription batch command). Including it requires a new narrow, `$request->has()`-guarded saver. Recommended as a fast-follow (§4.2); left out of v1 unless Johan wants the narrow saver written in the same build.

---

## 4. Data model / migrations

### 4.1 v1 needs NO migration
Every v1 switchboard toggle reuses an existing backing store:

| Toggle | Store |
|--------|-------|
| `marketing_enabled` | `PerformanceSetting` key/value row |
| `syndication_p24_enabled`, `syndication_pp_enabled` | `PerformanceSetting` key/value rows |
| `matches_enabled` | `PerformanceSetting` key/value row |
| `split_branches_enabled` | column on `agencies` |
| `website_enabled` | column on `agencies` |

Gating is **derived at request time** from current toggle values — no gating state is stored.
`completed_steps` (parent §4.1) already persists which steps were done; a gated-off step simply
never gets added.

### 4.2 Deferred columns (follow-up builds, NOT this spec)
- `agencies.presentations_enabled` (+ narrow saver + nav/compute gating) — presentations master
  toggle, per the §3.5 rationale.
- A narrow saver for `agencies.wa_transcription_enabled` — transcription switch.
Both would be additive switchboard rows once built; neither blocks v1.

### 4.3 `activeSteps` + progress
No column change. Add to `App\Models\AgencyOnboardingSetup`:
- `public static function stepGates(): array` — map of `stepKey => Closure(Agency): bool` (or a
  simple config-driven predicate table). v1: `['matches' => fn($a) => (bool) PerformanceSetting::get('matches_enabled', 1)]`.
- `public static function activeSteps(Agency $agency): array` — `STEPS` filtered by `stepGates`.
- `progressPercent(?Agency $agency = null)` — denominator becomes
  `count(activeSteps($agency))` when an agency is supplied; falls back to `count(STEPS)` when not
  (preserves existing callers / owner tracking page, which can pass the agency).

---

## 5. The switchboard step (copy + control map)

New step key **`capabilities`**, inserted at **position 2** (after `identity`, before
`branding`) — capability choices are made before the feature-specific detail steps.

### 5.1 Copy entry — `config/agency-onboarding-copy.php['capabilities']`
Follows the parent's schema exactly (`title`, `intro`, `what`, `controls[]`, `savers[]`). Every
control carries `explain` (full sentence: what it is, when you'd want it either way) and
`affects` (rendered "What this changes:" — a concrete, observable consequence; tautologies
forbidden, parent §5.1).

- `what` explainer card — title *"Your CoreX toolkit"*, body defining the idea in plain English:
  CoreX is modular; you switch on the parts you use; everything you switch on is set up in the
  steps that follow; everything you switch off is skipped and can be turned on later from
  Settings.
- `controls[]` (all `type: toggle`):

  | key | source | label | explain (gist) | affects (gist) |
  |-----|--------|-------|----------------|----------------|
  | `marketing_enabled` | `perf` | Marketing | Whether CoreX runs its marketing tooling for your listings. | Whether the Marketing area and its actions appear for your agents on each listing. |
  | `syndication_p24_enabled` | `perf` | Publish to Property24 | Whether CoreX pushes your active mandates to Property24. | Whether a listing set to syndicate is sent to P24 — no push happens with this off, even with P24 credentials saved. |
  | `syndication_pp_enabled` | `perf` | Publish to Private Property | Whether CoreX pushes your active mandates to Private Property. | Whether a syndicating listing is sent to Private Property. |
  | `matches_enabled` | `perf` | Core Matches | Whether CoreX matches new listings against your buyers' wishlists. | Whether your agents are told who to call when a new listing lands — off means no match alerts and the Matches setup step is skipped. |
  | `split_branches_enabled` | `agency` | Multi-branch offices | Whether your agency runs as multiple branches with their own agents and splits. | Whether agents are grouped by branch and whether branch appears on commission attribution. |
  | `website_enabled` | `agency` | Public website | Whether your agency's public CoreX website is live. | Whether your public site (agents / listings / branches) is reachable to the public or offline. |

  `source` drives `currentValues()` (parent controller) — `perf` → `PerformanceSetting::get`,
  `agency` → column read — so each toggle renders its current live state for free.

- `savers[]` (see §6 for the full map):
  ```
  ['controller' => SettingsController::class,        'method' => 'updateMarketingEnabled'],
  ['controller' => SettingsController::class,        'method' => 'updateSyndicationPortals'],
  ['controller' => SettingsController::class,        'method' => 'updateMatchesEnabled'],
  ['controller' => SettingsController::class,        'method' => 'updateSplitBranches'],
  ['controller' => AgencyApiKeyController::class,     'method' => 'toggleWebsite', 'pass_agency' => true],
  ```

### 5.2 Blade — `resources/views/agency-setup/steps/capabilities.blade.php`
An inline partial (parent's data-driven `controls` render is fine, but toggles want the richer
card layout, so a dedicated partial is cleaner). **Each toggle MUST render a hidden `"0"`
companion input immediately before its checkbox** (§3.4 part 1) so an unchecked toggle still
posts. Layout: one card per capability — label · `explain` · "What this changes:" `affects` ·
the toggle — matching the existing step card styling. The two portal toggles (P24 / PP) group
under a "Property portals" sub-heading.

---

## 6. Fan-out map — every toggle → its canonical saver (verified)

> Rule (parent §3.1): reuse the SAME write path the settings page uses. Verified signatures.

| Toggle | Backing store | Canonical saver (verified) | Signature | Guard action required |
|--------|---------------|-----------------------------|-----------|-----------------------|
| `marketing_enabled` | `PerformanceSetting` | `SettingsController@updateMarketingEnabled` (`:425`) | `(Request)` | Harden to `$request->has()` (§3.4) |
| `syndication_p24_enabled` + `syndication_pp_enabled` | `PerformanceSetting` | `SettingsController@updateSyndicationPortals` (`:432`) | `(Request)` — writes only the two keys, no credential validation | Harden both to `$request->has()` |
| `matches_enabled` | `PerformanceSetting` | `SettingsController@updateMatchesEnabled` (`:583`) | `(Request)` | Harden to `$request->has()` |
| `split_branches_enabled` | `agencies` | `SettingsController@updateSplitBranches` (`:867`) | `(Request)` — writes only that column | Harden to `$request->has()` |
| `website_enabled` | `agencies` | `AgencyApiKeyController@toggleWebsite` (`:160`) | `(Request, Agency)` — `required\|boolean`, `forceFill` | Already safe — set `pass_agency`; switchboard always posts the field (hidden companion) so `required` is satisfied |

**Note on redirects:** each saver returns a `RedirectResponse` to the settings/agency panel;
the wizard `save()` loop ignores saver return values (it only cares about thrown
exceptions), so the redirects are harmless — the loop continues to the next saver, then the
wizard's own advance/redirect runs. (Same as every other reused saver today.)

---

## 7. Adaptive step-gating — implementation

Changes are confined to `AgencyOnboardingSetup` (the STEPS/gates source of truth) and
`AgencySetupWizardController` (the helpers that iterate steps):

1. **`AgencyOnboardingSetup::STEPS`** — insert `'capabilities'` at index 1 (position 2). New
   order: `identity, capabilities, branding, branches, commission, properties, presentations,
   matches, contacts, compliance, notifications, roles, access` (13 keys).
2. **`stepGates()` / `activeSteps(Agency)`** (§4.3). v1 gate: `matches` ⇐ `matches_enabled`.
3. **`AgencySetupWizardController` helpers** switch from `STEPS` to `activeSteps($this->agency())`:
   - `nav($step)` — prev/next computed over active steps (so a gated-off step is invisible to
     Back/Next).
   - `advance()` — next step is the next *active* step; last-active-step Save routes to
     `finish()`.
   - `progress()` — `total` = `count(activeSteps)`, `percent` = `progressPercent($agency)`.
   - `show($step)` — if `$step` is not in `activeSteps`, redirect forward to the nearest active
     step (never 404 a legitimately-gated step; `assertStep` still 404s truly-unknown keys).
   - `index()` — resume pointer clamps to the nearest active step.
4. **Within-step conditional sections (no whole step to gate).** Marketing / syndication /
   website have no standalone detail step. Where a later step renders a control whose parent
   capability is OFF (e.g. any future portal-detail control in the Properties step), render a
   small server-computed note — *"Property portals are off — turn them on in step 2 to
   configure this"* — instead of a dead control. v1 has no such control (Properties step exposes
   only per-page/ordering after §3.2 moves the toggles out), so this is a forward-looking rule,
   not v1 work.

**Ordering safety:** the capabilities step is step 2, before every gated step, so a gate is
always evaluated against a value the Admin has already had the chance to set. Skipping the
capabilities step leaves the existing live defaults (`marketing`/`matches`/`syndication` read
default-on; `split_branches`/`website` default per column) — gating still resolves cleanly.

---

## 8. UI placement & navigation + copy rules

- The step appears in the wizard's own step rail (parent §5.1 progress indicator) as **step 2 of
  N**, where N is the active-step count for that agency.
- No new top-level nav entry is required — the wizard already has its Settings re-open link and
  incomplete-setup banner (parent §7); the switchboard is reached inside the existing wizard.
- **Copy rules (binding — parent §5.1 / STANDARDS F.8):** the `what` card defines the toolkit
  idea before any toggle; every toggle carries `explain` + a concrete `affects`. Guarded by the
  existing `test_every_step_explains_itself_before_asking_for_config` (parent §12), which
  iterates all steps and will now include `capabilities`.

---

## 9. Permissions (Non-negotiable #5)

No new permission keys. The switchboard runs inside the wizard under the existing
`permission:agency_setup.run` + `agency.required` gate (parent §8). Per-toggle, the reused saver
keeps enforcing the SAME section permission the settings page enforces — a toggle the Admin
could not flip on the settings page is absorbed (403) by the wizard `save()` loop exactly as
every other reused saver is (parent §8). i.e. the switchboard grants no capability the Admin did
not already have.

---

## 10. User flow (step by step)

1. Admin completes step 1 (identity) → lands on step 2 **Capabilities**.
2. Reads the "Your CoreX toolkit" card; sees six toggles pre-set to current live state, each with
   `explain` + `affects`. Flips what they want. **Save & continue** → the `save()` loop fans each
   toggle to its canonical saver (live write) → step marked complete → advance.
3. Remaining steps render **only for active features**: e.g. Matches left OFF ⇒ the Matches step
   is skipped, progress denominator excludes it, Back/Next step over it.
4. Admin can return to step 2 any time (Back, or re-open from Settings) and flip a feature ON;
   its detail step reappears in the flow.
5. Finish (parent §9 step 6) unchanged.

---

## 11. Input space / prevent-or-absorb (BUILD_STANDARD §2/§3)

- **Unchecked toggle** → hidden `"0"` companion arrives → saver writes `false` (with §3.4
  hardening, an *absent* field would instead leave the value alone — but the switchboard form
  always posts every field, so within the wizard the toggle is deterministic).
- **Skip the capabilities step** → no write; existing live defaults stand; gating resolves
  against those defaults (no 500, no dead step).
- **Feature toggled OFF mid-flow after its detail step was already completed** → the completed
  step stays in `completed_steps` (honest record of what was done) but becomes inactive; progress
  %’s denominator drops so the bar stays sane. Re-enabling re-activates it. No crash on either
  transition (steps are independent writes, parent §10).
- **Website `required|boolean`** → always satisfied (hidden companion). A missing field can only
  occur via a hand-crafted POST → Laravel validation 422 re-renders the step (absorb), never 500.
- **P24/PP auto-enable interplay** → not applicable: the switchboard writes the *syndication_*
  feature keys (`PerformanceSetting`), never the `p24_enabled`/`pp_enabled` credential columns
  (§3.5), so the auto-enable side effect is untouched.
- All writes go through Eloquent / existing savers (agency-scoped, `BelongsToAgency`); no raw
  inserts (parent §10).

---

## 12. Acceptance criteria

1. A new step `capabilities` renders at position 2 with the six toggles, each showing its
   current live value, a `what` card, and per-toggle `explain` + `affects`.
2. Saving the step writes each toggle to the **same** store the settings page writes — asserted
   by round-tripping each value (e.g. flipping `matches_enabled` off sets the identical
   `PerformanceSetting` value `updateMatchesEnabled` would).
3. Each hardened saver, called with its field **absent**, leaves the stored value unchanged
   (§3.4) — proven for `updateMarketingEnabled`, `updateSyndicationPortals`,
   `updateMatchesEnabled`, `updateSplitBranches`. Called with the field present-and-`"0"`, it
   writes `false`.
4. With `matches_enabled` OFF: the `matches` step is absent from `activeSteps`, Save on the step
   before it advances past it, `show('matches')` redirects forward, and the progress denominator
   excludes it (100% reachable without ever completing Matches).
5. With `matches_enabled` ON: the `matches` step is present and reachable normally.
6. `website_enabled` is now settable inside onboarding (was previously agency-edit only) and
   round-trips.
7. No master toggle appears in two places: the toggles moved per §3.2 are gone from their old
   detail steps (guarded by extending `test_removed_sections_are_gone` / a new assertion).
8. Skipping the capabilities step leaves existing defaults and does not break any later step.
9. Permissions: an Admin lacking a section permission has that toggle's write absorbed (403), not
   500 — matching settings-page behaviour.
10. Multi-tenancy: the step reads/writes only the authenticated Admin's own agency (inherited
    from the wizard's `agency.required` scoping).
11. The parent spec's §5 step table, `TOTAL_STEPS`, and the model `STEPS` const are updated in
    the same change (no drift — §15).
12. No raw error reaches a user on any bad-input path (§11).

---

## 13. Test matrix (single focused file — Non-negotiable #13)

`tests/Feature/Onboarding/AgencySetupFeatureSwitchboardTest.php` (+ extend
`AgencySetupWizardSaverGuardTest.php` for the newly-hardened savers). Cover:
- capabilities step renders with all six toggles + `what` card + explain/affects present;
- saving flips each toggle and the value round-trips into the real store (== settings-page result);
- each hardened saver: absent field ⇒ unchanged; present `"0"` ⇒ false;
- gating: `matches_enabled` off ⇒ `matches` not in `activeSteps`, advance skips it, `show` redirects,
  progress denominator excludes it; on ⇒ present;
- `website_enabled` round-trips through the wizard;
- moved toggles are gone from their old detail steps;
- skip capabilities ⇒ defaults intact, later steps still load;
- copy guard (`test_every_step_explains_itself_before_asking_for_config`) passes for `capabilities`.

Real SA agency data per BUILD_STANDARD §5. **Do NOT run the full suite** — run only this file
(+ the saver-guard file). Per Non-negotiable #13.

---

## 14. Files to create / modify

**Create**
- `resources/views/agency-setup/steps/capabilities.blade.php` — the switchboard partial (hidden-companion toggles).
- `tests/Feature/Onboarding/AgencySetupFeatureSwitchboardTest.php`.

**Modify**
- `config/agency-onboarding-copy.php` — add the `capabilities` step (copy + controls + savers). Remove the master toggles from `properties` / `matches` / `branches` step configs per §3.2 (keep their detail controls).
- `app/Models/AgencyOnboardingSetup.php` — insert `'capabilities'` into `STEPS`; add `stepGates()`, `activeSteps()`; make `progressPercent(?Agency)` use the active count.
- `app/Http/Controllers/CoreX/AgencySetupWizardController.php` — `nav()`, `advance()`, `progress()`, `show()`, `index()` iterate `activeSteps($agency)`; `stepData()`/`currentValues()` unchanged (toggles are plain `perf`/`agency` controls).
- `app/Http/Controllers/CoreX/SettingsController.php` — harden `updateMarketingEnabled`, `updateSyndicationPortals`, `updateMatchesEnabled`, `updateSplitBranches` to `$request->has()` guards (§3.4).
- The settings-page blades that post those four toggles — verify/add a hidden `"0"` companion so unchecking still turns off (§3.4 part 2).
- `resources/views/agency-setup/steps/*` — remove the moved master toggles from the properties/matches/branches step partials (if rendered there rather than via config controls).
- `tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php` — assertions for the four newly-hardened savers.
- `.ai/specs/agency-onboarding-setup.md` — §5 step table + `TOTAL_STEPS` + §5.1 (record the moved toggles) — see §15.
- `.ai/CHAT_STARTER.md` — dated decision entry.
- (No migration ⇒ no `schema:dump`.)

---

## 15. Required edits to the parent spec (`agency-onboarding-setup.md`)

To keep the single-source-of-truth honest (the parent is already stale at 11 vs the live 12):
- §5 table — insert row `2 | capabilities | Feature switchboard | PerformanceSetting + agencies` and renumber; set `TOTAL_STEPS = 13`; fix the stale "Step X of 9" copy to "of N (active)".
- §5.1 "Deliberately NOT in the wizard" — add the switchboard's §3.5 exclusions (portal-credential toggles, presentations-master, transcription) with their reasons.
- §6 save-path map — note the switchboard reuses `updateMarketingEnabled` / `updateSyndicationPortals` / `updateMatchesEnabled` / `updateSplitBranches` / `toggleWebsite`.
- §6.1 — add the four newly-hardened savers to the "confirmed instances, now guarded" table.

Commit these to `main` per the spec-sync rule once approved.

---

## 16. Build sequence (one concern per prompt)

1. **Spec** (this file) — approve step position, toggle set, the four §3.5 exclusions, and the
   §3.2 "move toggle out of detail step" calls. ← *we are here*
2. Harden the four bare-boolean savers + settings-page hidden-companion audit + extend the
   saver-guard test. (Landmine fix first, independently verifiable.)
3. `AgencyOnboardingSetup` STEPS insert + `stepGates`/`activeSteps` + `progressPercent(Agency)`;
   controller helpers iterate active steps. (Gating plumbing, no UI yet — `matches` gate testable.)
4. `capabilities` copy entry + blade partial + move the master toggles out of properties/matches/
   branches step configs.
5. Focused test file; verification (Tinker: step resolves, toggles round-trip, gate skips
   matches); parent-spec edits; CHAT_STARTER; commit/push; demo deploy (no migration, so
   view/route/cache clear + fpm reload only).

---

## 17. Open decisions for Johan (confirm at approval)

1. **Step position** — proposed step 2 (after identity, before branding). Alternative: after
   branding (step 3). Recommendation: step 2 — choose your tools before the tour.
2. **`matches_show_on_properties`** — keep as a *detail* control in the Matches step (proposed),
   or also surface in the switchboard? Recommendation: keep in Matches step (it is a
   sub-behaviour of Matches, not a top-level feature).
3. **Transcription + Presentations master toggles** — v1 fast-follow (write the narrow
   saver / add the column now), or genuinely later? Recommendation: later — v1 ships the five
   clean toggles; both deferred items are additive rows once their savers/gating exist.
4. **Moving toggles out of detail steps (§3.2)** — confirm the "one home per switch" call vs
   leaving the toggle in both places. Recommendation: move (no drift, clearer onboarding).

---

**End of spec.**
