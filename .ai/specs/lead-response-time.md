# Lead response time

_New 2026-10-07 (Johan). QA1 first. Plan: `/tmp/qa1-cc2-lead-response-plan-2026-10-07.md`; Johan's rulings: first contact is NOT taken from notes; a manager answering counts on the agent's lead; target + counting hours are agency settings, per weekday; shown on the Buyers Report and the agency Performance Report, built like them (every figure clickable)._

## 1. What it measures
How fast a portal **enquiry** is first answered by a person. A **lead** is a row in `portal_leads` (Property24, Private Property, website, shared link). Manual adds, QR sign-ups and imports are not enquiries and are not measured.

* **Arrived** = `portal_leads.received_at`.
* **Whose lead** = `received_by_user_id`, else the listing's agent (`properties.agent_id`), else the contact's primary agent.
* **First response** — counts ONLY when:
  1. the agent uses the explicit contacted action (**"Contacted and note"**, the Last Contacted tile's **Mark as Now / Pick date** — `Contact::markContacted()`), or
  2. a **message actually sent** to the contact (outbound WhatsApp / email that was ingested or reconciled — not the provisional click), or
  3. a **live/shared link** shared (Core Match share **confirmed** sent — `ContactMatchShare::confirmSent()`), or
  4. the contact is part of a **calendar appointment and feedback has been captured** on it (`calendar_event_feedback.captured_at`).

  **Never**: "Note only", free-text notes, and the quick-pick note types (Viewing booked / Viewing done / Offer discussed / …) by themselves; inbound messages; unconfirmed sends or minted-but-unshared links.
* **A manager answering** counts on the **agent's** lead; `first_response_by_user_id` is the manager and the report shows them as the responder.
* An action dated **before** the lead arrived is ignored; a repeat enquiry later starts a fresh clock; one real contact answers every open enquiry of that contact that had already arrived.

## 2. Storage (record once, never overwritten)
`portal_leads`: `first_response_at`, `first_response_by_user_id`, `first_response_channel` (`contacted_action|message|shared_link|appointment_feedback`), `response_tracked` (true for every lead from now on; **false for every pre-existing lead** — no first-contact history exists for them). Written by `App\Services\LeadResponse\LeadResponseRecorder`, reached through the domain event `Contact\ContactContactedByAgent` (listener `RecordLeadFirstResponse`; catalogued in `corex-domain-events-spec.md`; hooks via `Contact::recordAgentContact()`). Untracked leads are never touched by the recorder.

**Not measured.** Old leads (`response_tracked = false`) with no provable response are **left out of every figure** — never shown as a failure; the report states how many are left out. The one-off command `lead-response:backfill [--dry-run] [--agency=ID]` fills an old lead only where history **proves** a response after arrival (outbound message sent, confirmed link share, appointment feedback) and marks it tracked; it never guesses (the old contacted action was overwritten; phone calls were never recorded). Idempotent.

## 3. Settings (agency-owned, never hardcoded) — `agency_contact_settings`
* **Respond within N minutes** — `lead_response_target_minutes`, default **60**, 1–10080.
* **Counting hours, per day of the week** — `lead_response_hours` JSON `{mon..sun: {counted,start,end}}`; for each of the 7 days a start time and an end time, or **not counted**. Default: **every day 08:00–20:00, all 7 counted**. UI has **"Copy Monday to all days"**. Rules (`LeadResponseSettingsService`): a counted day needs both times; end may not be before start; **start = end is an empty window that counts nothing for that day**; at least one day must count something. Everything is evaluated in the **agency timezone** (`Agency::outreachTimezone()`), never server time.
* Settings page: **Settings → Lead response** (`command-center.settings.lead-response.update`, permission `command_center.settings`). Setup Wizard: the **Contacts** step (partial `agency-setup.steps.lead-response-hours` + the number control `lead_response_target_minutes`, `explain` + `affects`). Both post `lead_response_present` and save through the ONE saver `ContactGovernanceController::updateLeadResponse` (§6.1 of `agency-onboarding-setup.md`: only what was rendered is written; a post without the marker changes nothing; each field written only if present). Every change is audited (`lead_response_setting_audit`: who, old, new, when).

## 4. The calculation — one service, one per-lead result
`App\Services\LeadResponse\LeadResponseService::results()` returns one **`LeadResponseResult`** per enquiry (lead, source, contact, listing, agent, arrived, first response, responder, channel, **counted minutes**, status `in_target | late | waiting | not_measured`, `overdue`). **Every summary is built on it** — `summarise()` (counts, average, median), `report()` (company + per agent + per source), `rows()` (the drill-down list) — so a figure and the leads behind it can never disagree, and new summaries add a method over `results()` rather than a second query.

* **Counted minutes** = minutes between arrival and first response that fall inside the counting hours (`App\Support\LeadResponse\BusinessHours`): a lead arriving outside the hours starts counting at the next start time; a day not counted contributes nothing; spans across midnight/days sum each day's window; whole minutes rounded down; if no day counts at all the clock is the safe fallback.
* **In target** = counted minutes ≤ target; **late** = more; **waiting** = no genuine contact yet; **overdue** = waiting and already past the target (a lead that arrived 5 minutes ago is never a miss).
* **Average / median** are over answered leads only, in counted minutes.
* "Leads received" = measured leads in the period (answered + waiting).

## 5. Where it shows — built like the two existing reports (reuse their components)
* **Buyers Report** (`/corex/buyers-report`, plus the agent and branch pages): section **Lead response** under the tiles — tile row (Leads received · Responded in target · Responded late · Not yet contacted · Average · Median, each a button), "N already past target", **by agent** and **by source** tables, every number a button opening the report's own drill-down popup (`drill('lead_response', title, agentId, subtype, level, source)`). Same period selector, compare/type controls, own/branch/agency scope and print/PDF (summary only — drill-down lists are omitted from print, like every other section).
* **Agency Performance & ROI report** (`/corex/performance/agency-report`): the same block under Buyer activity, drawn with that page's tile style and its own `drill()` modal; print carries a summary table.
* Drill-down popup columns: Lead (links to the contact) · Source · Property · Agent · Arrived · First contact · By · How (Contacted action / Message sent / Link shared / Appointment feedback) · Minutes counted · Result. Default order: slowest / longest-waiting first. Capped at 1000 rows with the true count shown (the reports' own convention).
* **Search / sort / filters.** Per the reports' own contract the popup is a read-only list opened from a figure; the **filters are the figures themselves** (status buckets, agent, source, period, scope). No list-level search box or re-sort in v1 — consistent with the existing popups; add when those popups gain them.
* **Scoping (own / branch / agency).** The cohort is the viewer's own: Buyers Report via `BuyersReportScopeResolver` (+ `HierarchyResolver`), Performance report via `PerformanceReportScopeResolver` ceiling; the leads must belong to an agent in that cohort and to the viewer's agency (AgencyScope on `portal_leads`). `agent_id` / level / branch narrowing is honoured only inside the cohort; agent and branch pages re-check `canViewAgent` / `canViewBranch`. Direct-URL drill requests outside the cohort return nothing (or 403/404 as each report already does). No new permission (rides `view_buyers_report` / `view_performance`).
* No create / archive / restore: these are derived records; nothing is created or deleted by the reports.

## 6. Known limits (stated, not hidden)
* An agent who phones and never presses Contacted, sends a message, shares a link or records appointment feedback will look slow / never contacted. Tap-to-call logging is a separate job.
* Public holidays are not excluded; only the per-weekday hours are.
* A lead with no resolvable responsible agent is in no agent's cohort and is not counted.
* Old leads are "Not measured" except where `lead-response:backfill` can prove a response.

## 7. Tests
`tests/Unit/LeadResponse/BusinessHoursTest.php` (day boundaries, a day not counted, start = end, timezone, fallback) · `tests/Feature/LeadResponse/` — `LeadResponseRecorderTest` (what counts / what does not, once, before-arrival, repeat enquiry, untracked, manager), `LeadResponseServiceTest` (statuses, average/median, hours, target, not measured, whose lead, scope, per source, drill = figure), `LeadResponseSettingsTest` (page + wizard + saver guard + validation + audit), `LeadResponseReportsTest` (both reports, clickable figures, scope per level, other agency), `LeadResponseBackfillTest`.
