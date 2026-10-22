# Audit — Features & Role Manager switches on the tabbed menus (2026-10-22)

> Asked by Andre: "run a full audit check to make sure that all role manager and feature turn on and off functions works".
> Scope: the 20 tabbed menu items built on 22 Oct 2026 (`.ai/specs/{real-estate,dashboard,rentals,communications,documents,compliance}-menu-tabs.md`).
> Re-runnable: `tests/Feature/Navigation/TabHubSwitchAuditTest.php` (set `TAB_AUDIT_OUT=<file>` for the full JSON matrix).

## Verdict

**The switches work.** For every feature switch and every Role Manager permission that any tab depends on, switching it OFF hides
the tab — from the tab bar on its sibling screens, and the whole menu item once no tab is left (whose entry then refuses with 403) —
and switching it back ON brings the tab back. **Zero problems** across all of them.

Separately, the audit also tried each screen **by direct URL** while switched off. That is the screen's own route protection (not
something the menu work touched): **165 of 184 checks refuse (403/404); 18 screen/switch pairs still open** — listed below as
pre-existing gaps, each for its own decision. Nothing was changed to them.

## How it was tested (the real screens, not shortcuts)

- An agency admin holding every key any tab checks, plus the keys to run the two settings screens, in a fresh second agency.
- **Settings → Features**: `POST corex.settings.features.update` with `{key: 0}` / `{key: 1}` — exactly what the Features page submits.
  The one switchboard key involved (`core-matches`) is not on that form; it was switched through its own store (the
  `matches_enabled` agency setting the settings switchboard saves), which is what the Features page reads.
- **Role Manager**: `POST corex.role-manager.save` with the role's full permission matrix minus (then plus) one key — exactly what the
  Role Manager form submits — then confirmed the role_permissions row really changed.
- For each switch: every tab it controls checked as (1) not offered, (2) absent from the rendered tab bar on a still-open sibling tab,
  or — when the item had no tab left — the menu item gone and its entry refusing; (3) back after switching on; (4) its screen's
  direct-URL status recorded.

**Covered:** 24 feature keys — prospecting, deeds-capture, address-review, imported-stock, buyer-pipeline, core-matches, portal-leads,
viewing-packs, outreach, presentations, commercial-evaluations, performance-dashboards, rental-applications, rental-leases,
rental-inspections, rental-faults, rental-job-cards, rental-crews, rental-catalogue, rental-work-orders, communications, docuperfect,
shared-drive, compliance — and 57 permissions (every permission any tab checks). 184 direct-URL checks.

**Not switch-controlled (by design, so not in the switch walk):** Template Packages and Agent Compliance (System Owner only);
Agency Report and Lost Deals' role-name check (admin / owner); Application Authorisation's named-authoriser check (Company Settings);
core screens with no switch at all — Properties, Map, Calendar, Invitations, the Suppliers directory (Deals core).

## Pre-existing gaps — the menu hides these correctly, but the screen still opens by direct URL

| # | Screen | Opens while this is OFF | Why |
|---|---|---|---|
| 1 | All 10 Compliance tab screens (RMCP, RMCP Dashboard, Policies, Policy Register, Verification Queue, Staff Screening, Document Types, Agency Documents, Compliance Reporting, Communications Log) | permission `access_compliance` | The menu shows the whole Compliance panel only with this key; each screen checks only its own key. A user with e.g. `access_policy` but not `access_compliance` sees no menu link but can open Policies by URL. |
| 2 | Communications Log (`compliance.communications.index`) | feature `compliance` | No feature check on that route and not in the compliance feature's route list — the one Compliance screen that stays open with the module off. |
| 3 | Staff Screening (`compliance.screening.dashboard.index`) | permission `manage_employee_screenings` | The menu required it; the route checks only `access_compliance_dashboard`. |
| 4 | Core Matches (`corex.core-matches.index`) | permission `access_core_matches` | The menu required it; the route checks `access_contacts`. (Already reported in the Real Estate spec §7.) |
| 5 | Clause Library (`docuperfect.clauses.index`) | permission `access_clause_library` | The menu required it; the route checks only `access_docuperfect`. |
| 6 | My E-Sign Documents / Authorise Documents (`docuperfect.esign.myDocuments`) | permission `create_docuperfect_docs` | The menu required it; the route checks only `access_docuperfect`. |
| 7 | Packs, Web Packs (`docuperfect.packs.index`, `docuperfect.web-packs.index`) | permission `access_docuperfect_packs` | The menu required it; the routes check only `access_docuperfect`. |
| 8 | Agency Documents (`my-portal.agency-documents`) | feature `docuperfect` | A My Portal compliance screen that sits in the Documents menu; it is not part of the docuperfect module, so the module switch does not lock it. Arguably correct — needs a decision, not necessarily a fix. |

Also still open, already known: **Buyer Pipeline** (sales) checks no permission at all — only its feature (which does lock it: 404).

Each row is a business decision (should the screen lock, or should the menu stop requiring the key?) and a separate ticket. All 18
pairs are pinned in `TabHubSwitchAuditTest::KNOWN_OPEN`, so a NEW gap fails the test and a fixed one asks to be unpinned.

## Correction to an earlier claim

The Real Estate spec (§7, gaps 3 and 4) said Presentation Analytics / Outcomes and Deeds Capture / Address Review open with their
feature off. **They do not** — the audit got 404 on all four: the app-wide `EnforceFeatureRoutes` middleware locks every route a
feature owns even where the route itself has no `feature:` middleware. The spec has been corrected.
