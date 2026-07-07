# Daily Activities — Manual vs Automatic Points Match-Up

> **Working-session document for Johan & Elize.** Read-only audit of the live system (`nexus_os`, HFC / agency 1), 6 July 2026.
> **Purpose:** decide which manually-logged daily activities are now captured automatically, align the point values, and retire the manual entry for those — so only the auto entry continues going forward.
> **Rule this serves (Johan, verbatim):** *"what's the manual entry value, and can we set the auto values the same, and then retire the manual entries so that only the auto entries continue going forward."*

---

## Headline numbers (live, agency 1)

| Metric | Value |
|---|---|
| Manual activity **types** configured (the legacy list) | **41** (all enabled, all with real point values) |
| Auto activity **types** in the new spine | **27** `[Auto]` actions across **9 groups** (all currently at placeholder **weight = 1 point**) |
| Manual point entries on the books | **7,009** (source=`manual`, Feb 2026 → 6 Jul 2026) |
| Auto point entries on the books | **5,832** instant + **39** calendar (source=`auto_instant` / `auto_calendar`, Jun 2026 →) |
| **Mechanical double-counting** (same agent, same day, same activity, both manual + auto) | **0 — none found** |
| Manual entries still being made **after** go-live (10 Jun) | **Yes** — 159–282 rows/week through June by 7–11 agents, tapering to ~17 in early July |

**One-line verdict:** the two systems run in parallel today. They do **not** mechanically double-count (they post to *different* activity types), but agents are scoring the *same day's work twice conceptually* — once by hand, once automatically — and the auto side is scored at a flat 1 point because its values were never tuned. The job is to (1) set the auto values, (2) retire the ~6 manual types the system can now see for itself, and (3) keep the ~30 physical-world manual types that CoreX genuinely cannot observe.

---

## How the two systems work (plain language)

**Manual (the legacy Agency-Tracker daily activity log).** An agent opens the daily activity screen and ticks off what they did — "Took photos of Property", "Sign an OTP", "Canvass Door to Door" — each worth a fixed number of points the agency set. These are the **41 types**, ids 1–41, with the point values HFC configured (e.g. Sign an OTP = 500, Appointments = 200, Prospecting Lead = 150). Every tick is a row in `daily_activity_entries` with `source = 'manual'`.

**Auto (the new activity-points spine, shipped pre-go-live).** When something happens *in CoreX* — a property is captured, a FICA is submitted, a deal is registered, a viewing is booked on the calendar — the system automatically credits the responsible agent. These are the **27 `[Auto]` actions** (ids 42–68), grouped into 9 categories. They post rows with `source = 'auto_instant'` (event-driven) or `source = 'auto_calendar'` (calendar-driven). **Every one is currently worth 1 point** — the spine shipped with placeholder values "for the agency to tune to taste," and HFC hasn't tuned them yet.

The 9 auto groups: **Contacts & Buyers · Properties & Listings · MIC / Prospecting · Seller Outreach · Presentations · Deals & Mandates · Compliance & FICA · Marketing · Multi-actor deal roles.**

---

## Is manual still being used since go-live? (freshness)

Yes — declining, but live. Manual logging has **not** stopped:

| Week of | Manual rows | Manual agents | Auto rows | Auto agents |
|---|---|---|---|---|
| 1 Jun | 282 | 11 | 6 | 3 |
| 8 Jun | 251 | 9 | 53 | 10 |
| 15 Jun | 219 | 9 | 129 | 16 |
| 22 Jun | 165 | 9 | **5,349*** | 20 |
| 29 Jun | 159 | 7 | 227 | 16 |
| 6 Jul (part) | 17 | 3 | 68 | 10 |

*\*The 22 Jun auto spike is almost entirely the `property.captured` backfill (5,028 rows on that one action) — a one-off data-load, not organic daily activity. Real organic auto volume is ~50–230 rows/week.*

**Reading:** agents are drifting off manual as auto takes over, but 7–9 agents were still hand-logging through late June. Manual is still the primary points source for the 41 physical activities. It won't retire itself — it needs a deliberate switch-off per activity.

---

## Double-counting check (the "fix what needs fixing" material)

**Mechanical double-count = 0.** We checked every case where an agent has, on the *same day*, both a manual entry **and** an auto entry **for the same activity type**. There are none. The reason is structural: manual entries post to the 41 legacy types (ids 1–41); auto entries post to the 27 separate `[Auto]` types (ids 42–68). The only place they *could* collide is the 3 calendar-mapped types below, and in practice they haven't (auto_calendar volume is tiny: 2 confirmed + 7 pending).

**The real risk is a designed-in collision that hasn't fired yet.** The calendar maps three event types straight onto **existing manual definitions at the same point value**:

| Calendar event | Posts to manual type | Value | Status |
|---|---|---|---|
| `viewing` | **Take out Buyers** (id 24) | 50 | active |
| `property_evaluation` | **Appointments** (id 15) | 200 | active |
| `listing_presentation` | **Presentation** (id 16) | 200 | active |
| `meeting` | Appointments (id 15) | 200 | inactive |

So the moment an agent books a viewing on the calendar *and* also ticks "Take out Buyers" by hand for that day, they get 50 points **twice** on the same definition. Today that's 0 occurrences — but it is the first thing to close when manual is switched off, because these three are exactly the manual types the calendar already auto-feeds at the correct value.

---

## THE MATCH-UP TABLE

One row per manual activity. `Auto equivalent` names the spine action that covers it (or NONE). `Values match?` compares the manual point value to the auto action's current value (all auto = 1 today). **Verdict:** 🟢 AUTO-COVERED (retire manual, align value) · 🟡 PARTIAL (auto sees some, not all — policy call) · ⚪ MANUAL-ONLY (physical world, keep).

### 🟢 / 🟡 — Manual types the system can now see (candidates to align + retire)

| Manual activity | Manual pts | Auto equivalent (spine action) | Auto pts now | Match? | Verdict |
|---|---|---|---|---|---|
| **Appointments** (15) | 200 | `property_evaluation` → same def (calendar) | **200** ✔ (calendar) / 1 (instant n/a) | aligned | 🟡 PARTIAL — only *evaluation* appointments auto-fire; other appointment kinds still manual |
| **Presentation** (16) | 200 | `listing_presentation` → same def (calendar) **200** ✔; also `presentation.generated` (instant) | 200 / 1 | aligned (calendar) | 🟡 PARTIAL — calendar presentation auto-fires at 200; the *document-generated* event is separate at 1 pt |
| **Take out Buyers** (24) | 50 | `viewing` → same def (calendar) | **50** ✔ | aligned | 🟡 PARTIAL — only calendar-booked viewings; ad-hoc viewings still manual |
| **Sign a Exclusive Mandate** (23) | 300 | `mandate.signed` | 1 | ✗ set 1→300 | 🟢 AUTO-COVERED once mandates are signed in-system (0 auto fired yet — verify the event wiring before retiring) |
| **Sign an OTP** (22) | 500 | `deal.created` / `deal.registered` | 1 | ✗ set to 500 | 🟡 PARTIAL — a deal in CoreX ≈ an OTP, but "registered (sold)" ≠ "OTP signed"; decide which deal-stage inherits the 500 |
| **Contacted New Buyers** (4) | 100 | `contact.captured` | 1 | ✗ set 1→100 | 🟡 PARTIAL — captures a *new contact saved*; a follow-up call to an existing buyer isn't a capture event |
| **Load Property on P24** (14) | 50 | `property.published` | 1 | ✗ set 1→50 | 🟡 PARTIAL — `property.published` = first publish; re-loads/updates aren't re-counted |
| **Took photos of Property** (25) | 50 | `property.captured` (photos part of capture) | 1 | ✗ | 🟡 PARTIAL — capture ≠ specifically "photos"; overlaps but not 1:1 |
| **List a Property Open Listing** (12) | 150 | `property.captured` / `property.published` | 1 | ✗ | 🟡 PARTIAL — listing creation overlaps capture/publish |

### ⚪ MANUAL-ONLY — physical-world work CoreX cannot observe (keep going forward)

These have **no** auto equivalent and should stay manual. Flagged where a *future* automation is conceivable.

| Manual activity | Pts | Note |
|---|---|---|
| Canvass Door to Door (1) | 100 | physical |
| Drive an Area (5) | 150 | physical |
| Hand out Business Cards (10) | 100 | physical |
| Put adverts up in Shops (20) | 100 | physical |
| Put up Boards (21) | 100 | physical — *could* later tie to a "board up" checkbox |
| Put up Sold Boards (30) | 100 | physical |
| Check your Boards (3) | 50 | physical |
| Update Photo Board (26) | 1 | physical |
| Write article/blog in Media (31) | 15 | off-system; *could* tie to `marketing.published` later |
| Load Property on Facebook (13) | 50 | off-system social; no event today |
| Feedback to Sellers – EATS (8) | 80 | *could* tie to seller-feedback/outreach event later |
| Liase with Attorneys (11) | 20 | off-system comms |
| Follow up bond originators (6) | 20 | off-system comms |
| Follow up emails (7) | 100 | outside CoreX mail unless sent in-system |
| Send Wishlist to Buyers (9) | 100 | *could* tie to a buyer-match send event later |
| Check Matches on Listings (28) | 100 | *could* tie to MIC/match-view event later |
| Prospecting – Contact Made (17) | 10 | phone canvassing outside system |
| Prospecting – No answers (18) | 5 | phone canvassing |
| Prospecting – Lead (19) | 150 | *overlaps* `mic.claim_*` / lead capture — review |
| Stats Send (33) | 80 | *could* tie to a stats/report-sent event later |
| Update Property 24 (27) | 80 | ongoing edits (not first publish) — mostly manual |
| Virtual Tour (29) | 20 | off-system |
| Check your admin file (2) | 15 | admin |
| Overall admin, excl above (32) | 50 | admin catch-all |
| Rentals – In and Out Inspection (34) | 100 | physical |
| Rentals – Pre-approvals (35) | 100 | off-system |
| Rentals – Applications & Marketing (36) | 150 | off-system |
| Rentals – General Inspections (37) | 50 | physical |
| Rentals – Contractor Arrangements (38) | 20 | off-system |
| Rentals – Contract Renewals (39) | 150 | off-system |
| Rentals – New Contracts (40) | 500 | off-system |
| Rentals – Breaches / Notice To Vacate (41) | 100 | off-system |

### ➕ AUTO-ONLY — new actions with NO manual equivalent (just need values set)

These fire automatically and never existed as a manual tick. They only need point values (all at 1 today). No retirement involved.

`property.compliance_passed` · `mic.claim_taken` (18 fired) · `mic.claim_feedback` · `tracked_property.promoted_to_stock` · `map.prospect_launched` (inactive) · `outreach.pitch_sent` (263) · `outreach.outcome_logged` · `presentation.won` · `presentation.lost` · `deal.stage_advanced` (16) · `deal.registered` (9) + listing/selling-side role slugs (8/9) · `deal.commission_finalised` · `fica.submitted` (48) · `fica.approved` · `fica.reviewed` (33) · `rcr.submitted` · `marketing.published`.

---

## Value-alignment checklist (so "set the auto values the same" is a to-do list)

Every auto action is currently **1 point**. To make auto match manual before switch-off, set these agency values (Spine settings → Activity scoring):

- [ ] `mandate.signed` → **300** (= Sign a Exclusive Mandate) — **and confirm the event actually fires** (0 auto rows so far)
- [ ] `deal.registered` (or the chosen deal stage) → **500** (= Sign an OTP) — decide OTP-signed vs sold-registered mapping first
- [ ] `contact.captured` → **100** (= Contacted New Buyers) — accept it only credits *new* captures
- [ ] `property.published` → **50** (= Load Property on P24)
- [ ] `property.captured` → decide value vs "Took photos" (50) / "List a Property" (150) — one capture shouldn't silently absorb three manual ticks
- [ ] `presentation.generated` → decide vs manual Presentation (200): is generating the CMA worth the same as delivering the presentation? (calendar `listing_presentation` already = 200)
- [ ] Calendar trio already aligned (Take out Buyers 50 / Appointments 200 / Presentation 200) — **no change needed**, but see double-count step below.
- [ ] Set values for the AUTO-ONLY actions (FICA, deal stages, outreach, MIC) — currently 1 pt each; these drive the new scorecard and need real weights regardless of retirement.

---

## Recommended sequence (for Johan's word — nothing changes until you say)

1. **Set the auto values** per the checklist above (agency settings only — reversible, no code). This makes the auto side score correctly and is safe to do *before* any retirement, because manual and auto don't collide today.
2. **Retire the 3 calendar-aligned manual types first** — Take out Buyers, Appointments, Presentation. The calendar already credits them at the right value; switching off manual here closes the only designed-in double-count *before* it ever fires. Lowest-risk, cleanest win.
3. **Wire-verify then retire the two "signing" types** — Sign a Mandate (→ `mandate.signed`) and Sign an OTP (→ deal event). **Do not retire until you confirm the events fire in production** (`mandate.signed` has 0 auto rows — either no mandates were signed in-system yet, or the event isn't wired; check before trusting it).
4. **Leave the ~30 MANUAL-ONLY types alone.** They are the physical, off-system work (boards, canvassing, area drives, attorney liaison, rentals, admin). Retiring these would silently zero out real agent effort.
5. **Park the PARTIAL captures** (contact.captured, property.captured/published vs the manual load/photo ticks) for a policy call: auto only sees the first system event, so retiring the manual tick loses the "re-load / follow-up / re-shoot" credit. Decide per activity whether the lost granularity matters.

"Retire" = hide the manual type from the daily screen and stop accepting new manual entries (per no-hard-delete, the 7,009 historical rows stay for the record). No data is destroyed.

---

## RCR-FIC-2026 slice (bonus — feeds the 31 July Elize session)

While in the tracker tables: the **listing-stock** table (`listing_stocks`, fed by the Agency Tracker "Import Listings") holds a **Propcon export of 213 listings**, last imported **4 Mar 2026**, `listed_at` spanning **May 2024 → Mar 2026 — all inside the RCR period (Apr 2023–Mar 2026)**.

What it can back on the RCR readiness matrix (Part 8 — Estate agents), with honest limits:

| RCR question | What `listing_stocks` gives | Row count | Caveat |
|---|---|---|---|
| 8.1–8.5 property types / nature | Residential vs Commercial split | **208 Residential, 5 Commercial** | Snapshot of stock on the books at import, **not** a full count of transactions facilitated 2023–2026 |
| 8.6–8.28 high-value listings (R5–10m, ≥R10m) | Price bands | **8 × R5–10m, 5 × R10m+** (198 < R5m, 2 null) | Same caveat — these are *listings held*, not *sales concluded* |

**Matrix update to make:** Part 8 sub-themes 8.1–8.5 and the high-value-listing line in 8.6–8.28 can cite CoreX `listing_stocks` for a **defensible property-mix and high-value-listing snapshot covering ~May 2024–Mar 2026** — shortening (not replacing) Elize's Agency-Tracker gathering. The **period sales/lease *counts*** (8.1 "sales facilitated", leasing volumes) are **not** in this table — those still come from the legacy Agency Tracker / manual records as the matrix states. *No inflation: this is a 213-row stock snapshot, not a transaction ledger.*

*(The "RCR · FIC 2026" sidebar entry itself maps to the live CoreX RCR submission module `corex.compliance.rcr` — the tool Elize will file through — and was accessed today; it is not a dead screen and is out of scope for retirement. Full note in the parked menu-retirement audit.)*

---

## Evidence appendix (how to reproduce)

- Manual catalogue + weights: `activity_definitions` ids 1–41 (`scope=system`, `is_enabled=1`).
- Auto catalogue: `activity_definitions` ids 42–68 (`[Auto]` prefix, `weight=1`, `is_enabled=0`); defined in `database/seeders/ActivityInstantActionsSeeder.php` (9 groups) + calendar map in `ActivityCalendarMappingSeeder.php`.
- Points ledger: `daily_activity_entries` (`source` ∈ manual / auto_instant / auto_calendar; `point_state` ∈ confirmed / provisional / revoked). Points = `value × definitions.weight`.
- Raw auto event stream: `agent_activity_events` (24,493 rows) via listener `app/Listeners/Activity/LogAgentActivity.php`.
- Manual entry screen: `App\Http\Controllers\Agent\DailyActivityController@store` (`POST /agent/daily`, perm `access_daily_activity`).
- Legacy v1 tables `daily_activities`, `activity_columns`, `branch_activity_columns` = **0 rows** (dead; ignore).
