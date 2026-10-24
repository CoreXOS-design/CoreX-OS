# CoreX OS — Current State (lean)
> Replaces the 930 KB Chat Starter. Short on purpose: ENTRY POINTS and TITLES, not history. Full verbatim history: `.ai/archive/CHAT_STARTER-pre-lean-*.md` (never read at session start).
> Last rewritten 2026-10-24 (lean-standing-files draft). **Truth for 'what is deployed where' is git, not this file:** `git fetch origin && git log --oneline -5 origin/{Prod,Staging,QA1,QA2}` and `git log --oneline origin/QA1..origin/Staging` (empty = Staging is inside QA1).

## 1. Identity
Johan Reichel (owner, not a programmer) and Andre Roets (full-stack dev) build CoreX OS for Home Finders Coastal; second agency (Cape Town, rentals) starts Oct 2026. Environments, branches, rules: root `CLAUDE.md`. Specs: `.ai/specs/` (big ones are an index + folder).

## 2. Maintenance rule (replaces the old 350-line rule)
At the end of a prompt that lands work: add ONE line (<= 300 chars) under 'Recent landings' (newest first, drop the oldest beyond 15); move an item between IN FLIGHT / SPECCED / PARKED by title; record a decision as ONE line pointing at the spec section that holds the detail (the spec is the record, not this file). Keep the whole file < 40 KB. Never paste a build log here.

## 3. Recent landings (newest first; one line each, clipped — detail in the named spec / archive part 1)
- 2026-10-23 by **Lease agreement seeds find the right box (QA1 only; spec leases.md 'Lease seeds find the wizard's box'): an agency's field map names a box the way its own document prints it (`deposit_amount`), the e-sign wizard names the same property-bound box `property_deposit_amount`, so the Mega lease's deposit was never seeded and printed raw. New `LeaseWizardFieldResolve…
- 2026-10-23 by **Road to Sold v2 phase 3 = the daily activity moves into the task recipe (Johan's approved plan, AT-454, QA1 only; spec road-to-sold.md section 20 P9; NOT applied to any agency yet): `php artisan rts:migrate-daily-activity` (dry run by default; `--apply`; `--report=`) turns an agency's in-use activities into recipe tasks (Level 1, target 0, points = the weight)…
- 2026-10-23 by **Road to Sold v2 phase 2 = the agent's 'do this next' view and the capture form (Johan's approved plan, AT-454, QA1 only; spec road-to-sold.md section 20 P8): the existing /road-to-sold page now has a header that stays in view with the live status (level, worked today, targets met, points) and the buttons, one 'do this next' card (what is waiting, then the day's…
- 2026-10-23 by **Road to Sold v2 phase 1 = the engine (Johan's approved plan, AT-454, QA1 only; spec road-to-sold.md section 20 P7): new tables `rts_steps/tasks/levels/level_tasks/user_task_overrides/task_completions/task_completion_records`; ONE generic listener (`CompleteTasksFromDomainEvent`, registered beside the audit logger) turns the ten real domain events in `RecipeCata…
- 2026-10-23 by **Staging brought down into QA1 again (sync lane, merge commit "Sync: Staging into QA1 (23 Oct 2026)"; `git log origin/QA1..origin/Staging` is empty). Andre's tab-hub menus (Dashboard, Real Estate, Rentals, Communications, Documents, Compliance: one menu item per hub, the old items are tabs inside it) are now the base of the QA1 sidebar, with QA1's Deals menu, Ro…
- 2026-10-23 by **Agency Tracker re-home step 6 = the Deals menu (Johan's rulings of 20 Oct, AT-454 lane, QA1 only; spec agency-tracker-rehome.md section 17, P7): a new Deals main menu (Deal Register, My Deals, Unfiled deal emails, Deal settings last, admin-only); the old Deal Register addresses redirect (301 pages, 308 forms) to the Deal Register; Agency Tracker lost every deal…
- 2026-10-22 by **RMCP v2 loaded from the approved text (AT-506, branch at506-rmcp-v2-load-2026-10-22; Johan + Elize approved the text): `php artisan rmcp:import-version <agency>` (dry run by default, `--apply`, idempotent) creates a DRAFT v2 from `database/data/rmcp/v2.md` with merge fields filled from the company profile (new: PPRA number, approver name, offices from branches,…
- 2026-10-22 by **Report recogniser - an AI read now leaves a layout (QA1): a 12-column 'Sales (Days on Market)' table with blank cells was read by AI at 0.91 but never saved a layout (the learner counted 2-space cells; rows differ). LayoutLearner now also learns a version-2 table by what is in each row (typed tokens matched to ordered columns, wrapped addresses joined), held to…
- 2026-10-22 by **A sent match is sent (AT-503, Johan's rule: 'new' means only a property never sent to this buyer; branch at503-sent-is-sent-2026-10-22, not yet on QA1; spec core-matches.md 'A sent match is sent'): (1) the board's 'Send N new' and the send itself now use the same property set - a hidden matching property is counted in neither, so 'Send 1 new' can no longer stic…
- 2026-10-21 by **Staging brought down into QA1 (Johan: so nothing blocks sending work to Staging; QA1 now contains everything on Staging, `git log origin/QA1..origin/Staging` is empty). Staging's 20 Oct audit fixes are on QA1 (rate limits, route permission gates, per-agency timezone, private pictures, owner-chase fixes). Staging's six "hold back AT-475" reverts were themselves…
- 2026-10-21 by **Notices tabs rework (AT-495, branch cc2-at495-breaches-rework-2026-10-21, not yet on QA1): each tab has its action through one lease picker (Issue breach notice / Record a received notice / Send notice), template rows get Use this template + Download original, sent notices carry per-person delivery (a contact with no email is NOT sent, visible; send() now from…
- 2026-10-22 by **reOS review screen rebuilt around 'what needs me' (AT-481, Johan: 'such a confusing screen', QA1 only; spec rental-takeon-import.md R14): sticky header '46 leases · 18 ready · 28 need you · 0 imported' with the one primary button 'Import the N ready leases'; an Agent-for-all / Suburb-for-all-new-properties bar (leases inherit, only a lease with its own or none…
- 2026-10-21 by **reOS review fixes from Johan's real-file check (AT-479, QA1 only): solid sticky header above the rows; a company matched to a company (even a person-kind record named like one) is no longer 'matched a person'; 'Spelling differs' (grey, not counted) split from 'Name differs' (amber) with both filters; the row grid fits 1523px (six columns, notes under the agent)…
- 2026-10-21 by **reOS import: people for company owners/tenants (AT-479, QA1 only; spec R6a): on the review screen a company owner/tenant gets 'People for this company' (already linked, add existing/new person with role from the existing capacity list, remove), and a company that matched a person by phone/e-mail gets one click 'Create the company and link <person> as its contac…
- 2026-10-21 by **reOS import fixes from Johan's real files (AT-479, QA1 only): the report header row is now FOUND by scanning the first 15 rows (real Rent Roll has a 'Date Range' line, headers on row 5; columns found by heading, any order), and a contact matched on phone/e-mail under a different name shows 'Name differs' / 'Matched a person (likely the company's contact person)…

## 4. IN FLIGHT (titles only — status words are as last written; verify against git before relying)
- reOS Tenancy Schedule import (AT-479, 2026-10-21), QA1 only; not on Staging or live.
- Crew home link (follow-on to AT-459, 2026-10-19), QA1 only; not on Staging or live.
- Crew + contractor link round (AT-459, 2026-10-15), QA1 only.
- Catalogue item pricing block (AT-458, 2026-10-15), QA1 only; not on Staging or live.
- Title Deed Register (AT-450, 2026-10-13), QA1 only; not on Staging or live.
- Title Deed Register round 2 (AT-450, 2026-10-13), QA1 only.
- Title Deed Register round 3 (AT-450, 2026-10-14), QA1 only.
- Title Deed Register round 4 (AT-450, 2026-10-15), QA1 only.
- Inspection rulings of 8 Oct (cc6, 2026-10-08), QA1 only; not on Staging or live.
- FICA warns, never stops, on the rentals path — one shared rule, and the gate lifts on submitted (cc3, 2026-10-08), QA1 only; not on Staging or live.
- Every inspection type is signed; a signed report is locked; a sent report is final (cc6, 2026-10-07), QA1 only; not on Staging or live.
- Inspection signing by personal link, QR code and the agent's device (cc6, 2026-10-07), QA1 only; not on Staging or live.
- Features on/off for every sidebar item (cc5, 2026-10-07), QA1 only; not on Staging or live.
- A presentation always has a price (cc6, 2026-10-07), QA1 only; not on Staging or live.
- E-sign template export / import ("Template Packages"), QA1 only (cc2, 2026-10-06).
- Maintenance flow BUILD 2 — approvals, variations, emergency approval, external contractors (spec `rental-work-orders.md` §17.6–§17.9; build note §17.…
- AT-449 — CoreX Chrome extension: Web Store readiness + restyle (Andre's lane, QA2, 2026-10-12). Built, NOT submitted to the Web Store, NOT promoted.
- Demo Access — list restyle + "Add time" (QA2, owner-only, 2026-10-07). Built, 25 new tests green, NOT promoted.
- AT-448 — Property expiry: Expired status, expiring-soon pop-up, expiry lock + Drive "Extension" (Andre's lane, branch `AT-448-Expired-section-for-pro…
- CoreX Subscription Agreement web document (Platform E-Sign, AT-447 follow-up) — phases (b), (c) and (d) on QA1, proven in a real browser.
- Subscription Agreement phase (d), QA1 only (cc2, 2026-10-06):
- AT-447 — Agency Timeline + Platform E-Sign (CoreX's own, separate e-sign)
- AT-432 — Auctions, the third side of Properties
- List screens: filter area folds away while scrolling (Properties + Contacts)
- AT-419 — Imported Stock page
- AT-393 — Contacts page frozen header + filter card (only the list scrolls)
- AT-393 — Contacts page frozen header cap (title + count + filters + actions in one sticky bar; only the list scrolls)
- Branch archive + agent reassignment (AT-420)
- Mobile Agent Profile — FFC/Cell/WhatsApp/socials view+edit + preview-my-agent-page button + role fix
- Mobile Gallery Sort — drag-reorder photos + drag-reorder tags
- Contact Notes & Testimonials — mobile API + web note editing
- Webinar joining details — Meeting ID and passcode
- AT-384 — the calendar opens on TODAY
- AT-383 (second half) — Webinar registration on the CoreX website, with demo credentials in the same email
- AT-383 — Website listing statistics (the agency's own site reports back)
- App Access — mobile "Delete my account" (Apple 5.1.1(v))
- A presentation always has a price (cc6, 2026-10-07), QA1 only; not on Staging or live.
- System Updates — Bulk Email tab
- E-sign — recipient additional docs + amendment flow + PDF splitter port — QA1-ONLY, deliberately HELD out of tonight's MIC/scraper/proforma/outreach…
- CMA valuation fix STEP 2a (headline) + 2b (guardrail) — on QA1 `370fba8e` (origin/QA1 + `/corex-qa1`), Johan to test. Headline change QA1-ONLY, NOT f…
- AT-350 — "SOLD BY 3RD PARTY" (a competitor sold our listing)
- AT-338 — SYSTEM UPDATES (the "what's new in CoreX" pop-up)
- AT-11 — AGENCY BILLING (what agencies pay CoreX)
- AT-230b — Demo connector is UNIVERSAL and UI-CONFIGURED (supersedes the AgencyApiKey wiring below)
- AT-230 — Demo Access Control
- AT-208 — Syndication control on the Properties page
- AT-57 — AGENCY ONBOARDING SETUP WIZARD
- AT-177 — E-SIGN DOCUMENT COMPILER (foundation-first campaign)
- AT-178 — Event Reminders (Command Center calendar)
- AT-91 — WhatsApp Outreach Summary board (agents × outreach states)
- AT-41 — Interactive walkthrough engine (driver.js)
- AT-81 — Structured import rework: email alerts + scraper
- AT-79 — User display_email override (Elize multi-login workaround)
- AT-78 — Presentation wrong address (generator borrow + regenerate staleness + display outliers)
- Branch `feature/AT-75-mic-buyer-matched-kpi`
- Branch `feature/AT-74-presentations-staleness-tag`
- Branch `feature/AT-73-canonical-engine-surfaces`
- Branch `feature/AT-72-buyer-pipeline-autoland`
- Branch `feature/outreach-address-gate`
- Branch `WA-CAPTURE-FIX-stale-selectors-history-sweep`
- Branch `AT-36-comm-archive-triage-phase-a`
- Branch `AT-34-communication-archive-whatsapp-adapter`
- Branch `AT-33-communication-archive-email-adapter`
- Branch `AT-32-communication-archive-spine`
- Branch `AT-22-presentation-quality`
- Branch `feature/map-workspace-overhaul`
- Branch `AT-7-Finish-ad-manager-for-CoreX`
- Branch `AT-7` (cont.) — Marketing "Generate with Ellie AI"
- Branch `AT-7` (cont. 2) — Marketing copy polish + preview fixes.
- Branch `AT-7` (cont. 3) — marketing copy persistence + template image display.
- Branch `AT-7` (cont. 4) — Bulk Ad Manager (Tools).
- Branch `AT-7` (cont. 5) — Ad Manager polish + UI-design-system audit.
- Branch `AT-7` (cont. 6) — AI Usage dashboard drill-down + design-system restyle
- 2026-06-25 — Ad Manager permission → data scope.
- Branch `AT-29-policy-acknowledgement-framework`
- Next:

## 5. SPECCED / partial
- Communication Capture Setup
- Communication Archive — Pending Triage & Classification addendum
- Universal signature feature
- Communication Archive
- Policy Acknowledgement (POPIA/CPA/NCC)
- Tenant pre-approval
- Inspection reports module
- Statistics tile builder
- Map A.4 / A.5 / A.6
- CoreGolf
- Module 4 (Buyer CRM) + Module 5 (Seller Live Link)
- Deal Register V2
- Sales pipeline + Rental pipeline views
- Rental portal uploads (AT-484)
- Rental owner live link (AT-483)

## 6. PARKED / future
- AT-80 — Multi-branch user support (retire Elize's 3-login workaround)
- Phase 9e
- System-wide back-nav audit
- User activity tracking spec
- 4 strategic spec pillars
- Auto activity tracking + Exclusive auto-notification
- CDS importer path
- Accounting module

## 7. LIVE history
The old LIVE list (43 items, Jun-Sep 2026: MIC, Ad Manager, mobile portal, login audit trail, 2026-09-16 Prod promotion at `3c052a906`, …) is in `.ai/archive/CHAT_STARTER-pre-lean-3-state-of-the-build.md`. Live = `origin/Prod`; read `git log origin/Prod`. Dated promotion records: `.ai/deploy/` and `.ai/runbooks/`.

## 8. Recent decisions (newest 8, headline + first sentence only; the spec section named in each holds the decision)
- - **2026-10-23 (Deals menu, AT-454 lane step 6, QA1; spec agency-tracker-rehome.md 8 + 17 P7):** Johan's rulings of 20 Oct built. Lane decisions: (1) Deal settings is a short list at the bottom of the Deals menu, not a new page, on the doors' existing permissions — no new key; (2) the rename and the old-register redirects do not wait for the twin backfill, because the Deal Register already reads the same deals table…
- - **2026-10-22 (RMCP v2 load, AT-506, QA1; spec compliance.md):** Johan + Elize approved the v2 text. Lane decisions: (1) the text lives in the repo as Markdown (`database/data/rmcp/v2.md`) and a command loads it as a DRAFT — never active; Johan approves it through the normal RMCP approve screen; (2) every per-agency fact is a merge field, none typed in — the offices sentence comes from the agency's branches that ha…
- - **2026-10-21 (AT-495 gaps, QA1; spec rental-notices.md 9.y):** Johan checked AT-495 on QA1: no Resend on Notices sent, no Download original on templates, 'Use this template' grey on 3 of 4 rows, buttons uneven. Lane decisions: (1) QA1's sent notices are all breach notices and were excluded from Resend - they are now resendable to the party in breach only (the other party was only told, never sent the notice), ever…
- - **2026-10-21 (specs only, no code — AT-484 portal uploads, AT-483 owner live link):** Johan asked for both as spec drafts from cc2's investigation. Lane decisions: (1) owner link keeps ONE table and ONE URL — the controller picks an owner page by `Property::isRental()`; the sale page stays byte-identical; (2) hashing + expiry applies to owner links only (sale links untouched); the cost is that a link can only be s…
- - **2026-10-21 (application "won't send with a property" + breach no-email, QA1; specs rental-applications.md + rental-notices.md 9.x):** Johan reported Send failing when a property is picked. Root cause (proved in a real browser on QA1, not the server): the "Link a property" search box sits inside the application form, whose edited-flag greys out Send; the control now stops its own events at its wrapper. The breach…
- - **2026-10-21 (rental fixes AT-491..494, QA1; specs notification-system-overview.md 17, rentals-faults-work-orders.md 28.10 + 19.2):** Johan's rulings: whole alert row clickable; alerts caused by a contractor/owner/tenant are never held back by open hours (in-app) or swallowed by the 6-hour cooldown (a revised quote, changed answer or second finish report alerts again) and every dropped alert leaves a drop-log row;…
- - **2026-10-21 (Notices tabs rework, AT-495, not yet on QA1; spec rental-notices.md section 9):** Johan's ticket. Lane decisions: (1) one lease picker + one JSON endpoint under /api/v1 serves all three buttons, scoped by Lease::visibleTo; (2) 'Record a received notice' reuses the lease screen's own dialog (?action=) rather than a new form, and those dialogs now post to the spec'd notice-cases route; (3) delivery is…
- - **2026-10-21 (reOS Tenancy Schedule import, AT-479, QA1 only):** Johan approved the approach. Decisions made by the lane: (1) the uploaded reOS files are read from PHP's temp upload and never stored, so the money columns genuinely stay in reOS; (2) dates must be YYYY-MM-DD text (Carbon would read 03/04/2025 the American way); (3) a lease is found for update by agency + 'reos' + reOS id, archived leases included, a…
_(older decisions: 172 more in archive part 4)_

## 9. Outstanding small fixes — OPEN / PARKED only (titles; RESOLVED items are in archive part 5)
- OPEN (2026-08-31) — `property_documents` does not exist, and never has: the "documents missing" alert's premise is permanently false.
- OPEN (2026-08-31) — `ScanDealNotifications.php:107` carries the identical clock-bucket dedup key that caused the property-alert flood.
- OPEN (2026-08-24) — `SignatureService::completeDocument()` has no guard against being called twice on an already-completed `SignatureTemplate`.
- OPEN (2026-08-12) — `OversightNudgeMail` has a 100% send failure rate, chronic since at least 2026-07-01.
- OPEN (2026-08-12) — Communication-archive IMAP polling auth-fails on mailboxes 3 & 4 every ~5 min, chronic since 2026-07-14.
- OPEN (2026-08-12) — `SyncProperty24Activations` job chronically hits `MaxAttemptsExceededException`, every ~15 min, ongoing.
- OPEN (2026-08-12) — disk on `/` (root, `/dev/sdb1`) at 87% used, 4.9G free.
- OPEN (2026-08-12) — two more tables have the same NULL-doesn't-enforce-uniqueness schema shape as roles/role_permissions/calendar_event_class_setting…
- OPEN (2026-08-12) — 7 unrelated junk roles in the `roles` table
- OPEN (2026-08-12) — queue worker wedged 07:35–09:40 on 2026-08-12, backlog peaked at 7,583 jobs, self-drained.
- OPEN (2026-08-05) — `RegenerateBuyerMatchesJob` is O(contacts × stock), not O(stock); still runs 10-15min per agency-wide invocation even after the p…
- OPEN (2026-08-05) — 12 `agency_webhook_deliveries` rows (agency 1) are permanently `failed_at` from 2026-06-17/19 API-key setup
- OPEN (2026-07-12) — demo's calendar event-class rows are still agency-1 rows and need a one-time conversion to global.
- PARKED (2026-06-18) — `ReviewCompTableAddressTest` returns 302 on the review route.

## 10. Next likely move
Not recorded here any more (the old entry was from August). Ask the conductor; priorities live in Jira and the conductor chat.
