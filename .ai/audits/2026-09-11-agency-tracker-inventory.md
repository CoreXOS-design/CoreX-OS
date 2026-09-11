# Agency Tracker — Full Inventory

**Date:** 2026-09-11
**Requested by:** Johan, via conductor — "we going to have to take our time to run some numbers on the agency tracker side and look at what we do with all of those screens... he should not start from a blank page."
**Nature of this document:** A factual map of every screen in the Agency Tracker area — what it does, who uses it, where its numbers come from, and whether those numbers can be trusted. This is **report only**. Nothing in the app was changed while producing it, and it deliberately does not propose a redesign — that decision is Johan's to make once he has the map in front of him.

---

## Read this part first — the four things that matter most

1. **The Commission Overview / Commission Management screens (owner or super_admin role only — a plain admin cannot open them) show wrong numbers, and have for weeks.** They calculate commission a second, independent way — not the way the real Deal Register does — and checked against a real deal, the gap is large: one real sale actually paid the agent R10,660 net; the ledger these screens read shows R41,200 for that same agent on that same deal (roughly 3.9x), and because the row was written twice by the same bug, the screens actually sum it as R82,400 — about 7.7x the real figure. Worse, only 5 of your 82 real "paid" deals have ever appeared on these screens at all — the mechanism was only switched on about three weeks ago (2026-08-19) and nothing was ever backfilled. "Year to Date" on this screen is a small, wrong fraction of the real year, with nothing on screen warning you of that. **cc2's commission corrections will not fix this** — this is a separate calculation path. Confirmed display-only: the real Payroll module in this codebase does not read this table anywhere, so nothing is actually paid out on these wrong figures — but the *personal* "My Earnings" view of the same wrong data (see below) is open to every agent, branch manager, and admin, not just owner/super_admin.

2. **The Listing Stock screens (used inside both the Admin and Branch Manager performance dashboards, not just their own pages) are showing data that is over six months stale.** All 213 listings were a one-time import from an outside system in February–March 2026. The screen that would let anyone refresh this data has been removed from the menu (correctly labelled "legacy" by you, Johan, back in August) — but nothing else in the app can put new data in behind it. The "days on market," "stale," and "expiring soon" figures look freshly calculated because the date math runs today — but the underlying listing facts they're measuring from are frozen. Anyone glancing at those tiles is looking at March data believing it's current.

3. **A live Branch/Admin Performance dashboard widget silently shows blank/zero next to correct numbers.** The main "points target vs actual" figures on the Performance pages are correct and current. Right next to them, a small "last 7 days" / "today's activity" widget still reads from an old, abandoned activity table that nobody writes to any more — so it always shows nothing, with no indication it's broken, next to numbers that are working fine.

4. **Almost none of the Agency Tracker area is aware DR2 exists.** Only the newest screen (Performance & ROI Report, under Tools → Reports) was built to count deals from both the old Deal Register and DR2 without double-counting. Every other money screen — Worksheet, Targets, the Admin/Branch Manager Performance dashboards, Commission — reads only the original Deal Register table. This isn't causing a visible mismatch today (because every DR2 deal happens to already be linked back to the old register), but the day DR2 is used more independently, those older screens will quietly under-count.

---

## How the Agency Tracker menu is organised

Everything below sits under the one "Agency Tracker" panel in the sidebar, split into sections by role: a common item everyone with access sees (Worksheet), an Agent section ("My Performance"), a Branch Manager section, an Admin section, a Commission section (owner/super_admin only for the company-wide views, separately switched on — see Section 6 for the exact gate on each individual screen), and a Tools section (calculators, available to everyone in this area). The inventory below follows that same grouping.

---

## Section 1 — Worksheet (personal budget planner)

**What it's for:** Turning a personal take-home money goal into "how many sales and correctly-priced listings do I need this month," and checking that against what the branch/company needs from that person.

**Screens:**
- **Worksheet** (agent's own screen, but reachable by anyone with the "View Worksheet" permission — in practice agents, branch managers, admins, even a read-only "viewer" role). An agent types in a personal/business/savings money goal plus a planning sale price and commission %. The system works backwards to "sales needed," "listings needed," and a "Gap" against current stock. It also shows two auto-fill buttons, a read-only summary of the agent's real captured deals this month and all-time, and a side-by-side comparison of "my own planning numbers" vs. "the market's real average numbers."
- **Worksheet Market — Branch** (branch managers). Shows real branch deal averages and lets the manager set/override an agent's planning sale price.
- **Worksheet Market — Company** (admins). The company-wide version — can override sale price AND commission % for any agent, with a "lock" so an agent can't edit their own commission % once set.
- A small, separate branch manager screen for typing in the month's plain rand "branch budget" figure — this single manually-typed number drives everything else in this section.

**Where the numbers come from:**
- Personal/business/savings targets, the branch budget figure, and (unless overridden) the planning sale price/commission %: all **manually typed in**, with no validation against reality. A brand-new agent with no data yet gets a hard-coded guess (roughly R1,060,000 average sale price, 7.5% commission, 40% correctly-priced) baked into the code.
- Current active listings: pulled live from imported listing stock (see the Listing Stock staleness issue above — this feeds in here too).
- "Correctly priced %" is auto-calculated from imported valuation data when available, otherwise a manual guess.
- The real deal-history summary and the "market-based" comparison column: pulled live from the original Deal Register.
- "Required per agent" = the manually-typed branch budget divided evenly by a headcount of active agents in the branch — a flat split, not weighted by seniority or actual production.

**Soundness concerns found:**
- The headcount shown on screen for "agents in branch" and the headcount actually used by the two auto-fill buttons are counted two different ways — they can disagree if the branch has certain non-agent staff flagged into the split.
- A "Rentals (This Period)" figure is mislabelled — it actually sums every active rental regardless of month, not just the stated period. The screen does honestly disclose in small print that rentals aren't yet part of the budget math, so this figure is informational only.
- The auto-align button can silently fail to fully align a target (it gives up after 5 attempts) while still reporting success to the user.
- There's a leftover duplicate block of calculation code that recomputes the same "all-time" deal totals twice on every page load — wasted work, not a wrong answer.
- Nothing validates the branch budget figure — it can be blank, zero, or unrealistic with no warning.
- Reads only from the original Deal Register — no DR2 awareness at all.

**Live or stale:** Actively patched — recent, real bug fixes in the last few weeks (crash fixes, VAT handling, agency-scoping fixes). But the app's own internal permission naming calls the core Worksheet permissions "Legacy," distinct from the newer Market screens — suggesting whoever built the Market screens saw the original Worksheet page as something to eventually fold in or replace, not build further on. An old, unused backup copy of the whole Worksheet page has sat untouched in the codebase since the very first commit.

---

## Section 2 — Targets

**What it's for:** Setting how much each agent/branch/the company should achieve, and (in theory) checking that against what they actually achieved.

**Screens:**
- **Targets** (admin/branch manager). Today, in practice, this screen does exactly one thing: shows a list of agents and lets you type in a single number — "Monthly Points Target" — per agent. Nothing else is shown, even though the page still quietly calculates a lot more behind the scenes on every load (real deal/sales figures, a full weekly activity grid) and then throws all of it away without displaying it.
- **A second, complete "bottom-up" target calculator** (works backwards from an agent's personal Worksheet goal to a required number of deals/sales value, with manager overrides). This is fully built and fully wired to the database — but there is **no link, button, or menu path to it anywhere in the live app.** It cannot be reached by a normal user today.
- **Listing Targets** (admin). A simple, working, reachable screen: set a target number of listings per agent per month. There is no "actual" anywhere in the app that this gets compared against — the number is recorded and never checked.
- **Monthly Goals** (admin/branch manager). Set an overall company or branch target for listings/deals/value, plus a "rollup" that's meant to sum up individual agent targets. In practice this rollup will read as close to zero, because the one reachable per-agent target screen (above) only ever saves the points number, not deals/listings/value.

**Where the numbers come from:**
- Points target vs. actual: the target is typed in; the "actual" is a genuine live count of the agent's real logged activity this month, multiplied by each activity's point value — and it deliberately only counts confirmed or manager-approved entries, excluding anything still unconfirmed, specifically to stop score inflation. This part is solid.
- Sales value / deals target vs. actual (where shown, on the Performance pages — see Section 3): pulled live from the original Deal Register, correctly splitting credit between co-agents on a joint deal. No DR2 awareness.
- Listings target: pure manual entry, no actual to compare against.
- Monthly Goals rollup: structurally dependent on a per-agent field nothing currently writes real numbers into.

**Soundness concerns found:**
- Almost all of the target-setting screens' displayed functionality has been stripped down over time, but the backend logic behind them hasn't been cleaned up to match — it's still doing (and discarding) real work on every page load.
- A fully-built second target-calculation feature exists with literally no way to reach it.
- Listing targets are set but never measured.
- Monthly Goals rollups will typically show near-zero regardless of real target-setting activity happening elsewhere.

**Live or stale:** Split. The points/actual comparison is real and used. Everything about deal/listing/value targets specifically has been quietly reduced to non-functional remnants — set but not measured, or measured but not shown, or fully built but unreachable.

---

## Section 3 — Daily Activities

**What it's for:** An agent's day-to-day activity log (calls, viewings, mandates signed, etc.), rolled into the points system that Targets measures against.

**Screens (all live and reachable):**
- **My Daily Activity** (agent) — pick a day within a rolling 14-day window, log activity counts against a configurable list of activity types.
- **Daily Activity Summary** — three versions (agent/own, branch manager/branch, admin/company-wide) with date-range picking and drill-down from company total → activity type → branch → individual agent's day-by-day log.
- **Daily Activities Setup** (admin) — configure the list of activity types, their point values, and a catalogue of rules for automatically crediting activity from real actions elsewhere in the system (capturing a contact, publishing a listing, advancing a deal, signing a mandate, submitting FICA, calendar meetings, etc.).

**Where the numbers come from — more sophisticated than a simple logbook:**
Checked against the real data: of roughly 19,000 logged activity entries, over half were generated **automatically** by real actions elsewhere in the system (not manually typed), about 44% were genuine manual entries, and a smaller calendar-linked auto-credit channel exists but is barely used — most of those entries end up "revoked" because the required follow-up confirmation never happens. Every entry carries a state (provisional / confirmed / revoked / manager-overridden), and only confirmed or overridden entries count toward a score — this rule is applied consistently everywhere it was checked. Monthly point targets are genuinely populated for 20+ agents going back to February 2026, summing to roughly a quarter-million points a month agency-wide — this is a real, working, actively-used loop.

**Also found — two dead, disconnected leftover systems sitting alongside the live one:**
- An older, fixed-column daily-activity grid with its own screen and its own database table — not linked from anywhere in the current menu, and its table holds zero rows.
- A third remnant living inside the old Targets page's own code: a similar old-style capture grid, still technically save-able by direct form submission, but no longer shown on the page a user actually sees. Its table also holds zero rows.

**Soundness concerns found:**
- A branch-specific custom activity type can be created via the setup screen, but a naming mismatch between how it's saved and how it's read means it would never actually appear anywhere — it's a silently broken dead end. (Confirmed: zero branch-scoped activity types exist in the real data, consistent with this never having worked.) The agent's own capture form additionally doesn't show branch-specific activities at all, even where the naming would otherwise line up — broken in two separate ways.
- The live "last 7 days" / "today's activity" widget that appears on the Branch/Admin Performance pages (Section 4) reads from one of the dead legacy tables above, not the current activity log — so it always shows blank/zero next to otherwise-correct numbers on those pages, with no warning that it's stale plumbing.

**Live or stale:** The points-based system is real, actively maintained (recent security/correctness fixes, including closing a cross-agency data leak), and has meaningful real usage. The two legacy systems beside it are inert leftovers holding no data.

---

## Section 4 — Performance (Branch Manager / Admin dashboards)

**What it's for:** "How is this agent / this branch / the whole company doing this month" — points, deals, sales value, commission, and listing stock, in one dashboard.

**Screens (all live, actively patched):**
- **Admin Performance** — company-wide dashboard: activity-points leaderboard, deals status summary (counts and Rand values by stage), branch cards with income vs. budget, a Listing Stock summary box, and active TV-display codes per branch.
- **Admin Branch Performance** — reuses the exact same screen a Branch Manager sees for their own branch, so the two never disagree for the same branch.
- **Branch Manager Performance** — the branch's own points/targets, deal status summary, real Deal Register averages (for planning), Listing Stock summary, budget-vs-projected-income box, and one-click target-setting tools that actually write new numbers into the database (not view-only).
- **Agent Performance** (admin can view any agent; branch manager limited to their own branch) — one agent's scorecard: targets vs. actuals, their deals with commission split into agent's cut vs. company's cut, and the broken 7-day activity widget noted above.
- **Performance Settings** (admin only) — VAT rate, "listings per sale" planning ratio, and letterhead details used on printed reports.

**Where the numbers come from:**
- Money figures (commission, agent/company income) come from a pre-computed cache that updates automatically the instant a deal is created, edited, or settled — confirmed genuinely live (writes as recently as today), with a same-page live fallback calculation if the cache is ever missing for a period.
- Deal-status counts, Deal Register averages, and the points/targets system are recalculated fresh on every page load, straight from the real records — no caching, meaning no staleness risk, but it does this inefficiently (one deal at a time in a loop rather than one combined query), which will get slower as deal volume grows. Not a problem at today's volume.
- Listing Stock numbers feeding the summary tiles on these dashboards: see the staleness issue flagged at the top of this document — six-plus months old, presented as current.
- None of these dashboards have any DR2 awareness — they read the original Deal Register only.

**Soundness concerns found:**
- Deal-count risk described at the top: this dashboard group will under-count against the newer Performance & ROI Report the day DR2 gets used more independently of the old register (not visible in the data today, but the gap exists).
- A DR2 commission-settlement path was found in the code explicitly flagged as not yet triggering the same cache-refresh step DR1 deals get — meaning a commission settled through that specific newer flow may not show up promptly here.
- A handful of leftover, unlinked backup copies of Performance pages exist in the codebase (harmless, just clutter).
- The points/scoring weight table (which activities are worth how many points) is set directly in code, not through any admin screen — changing incentive weights currently needs a developer, not a business decision made in the app.

**Live or stale:** Genuinely active and maintained — real, dated bug fixes up to recent weeks (an archived agent's commission being silently dropped, points made consistent across agent/branch/company views, a broken menu permission). The one exception living inside these otherwise-healthy pages is the stale Listing Stock tile and the broken 7-day-activity widget, both flagged above.

---

## Section 4a — Performance & ROI Report (Tools → Reports)

This is a separate, newer screen from the Admin/BM Performance dashboards above, currently reached from Tools → Reports rather than the Agency Tracker panel itself, but it belongs in this same conversation because it measures the same things and is the one screen actively being invested in right now.

**What it does:** A full company → branch → agent report across 13+ metrics (deals, contacts, listings, FICA, buyers, viewings, presentations, portal views, appointments, outreach, commission), with period comparison, click-through to the underlying real records for every number, individual agent/branch "journey" pages, and printable summaries.

**Where the numbers come from:** Calculated fresh on every load, efficiently (one combined query per metric, not per-deal). For deal counts, it was deliberately built to count both the old Deal Register and any DR2 deal not yet linked back to it, specifically to avoid double-counting or under-counting — the code comments describe this as a fix for an earlier version that badly undercounted by only looking at DR2. Commission Rand figures on this report, however, still only come from the DR1-based settlement table — DR2 deals don't yet contribute money figures here, only deal counts.

**Live or stale:** This is the one screen in the whole Agency Tracker area with clear, current, careful investment — dated engineering notes as recent as August 2026, deliberate edge-case handling (an agent who left mid-month still gets credit for what they earned while active; joint deals split correctly), and a recent promotion to a more visible menu location rather than being hidden.

---

## Section 5 — Listing Stock (Company / By Agent / Branch)

Covered in the "read this first" summary above. In short: three view-only screens (company-wide, by-agent breakdown with agent-reassignment editing, branch-scoped) over a single set of 213 listings, all imported one time in February–March 2026 from an outside system, with no working way inside the app today to bring in new or updated listing data. The Company-level versions were correctly hidden from the menu by you, Johan, in August as "legacy" — but the Branch Manager's own Branch Listing Stock screen was not hidden, and it's still fully reachable, still showing the same frozen data. The only activity against this data since March has been admins manually reassigning which agent is attached to a listing.

---

## Section 6 — Commission (separately switched on; access varies by screen — see below)

Covered in detail in the "read this first" summary above — this is the most serious finding in the whole inventory. To restate the facts plainly, with the access gates stated exactly (checked directly in the code, not assumed):

- **Confirmed switched ON** for your real agency today (checked the feature-flag data directly — no agency has it turned off).
- **Commission Overview** and **Commission Management**: gated to **owner role or super_admin only** — confirmed directly in the controller code. A plain `admin` role, despite being able to see everything else in this document, is correctly blocked from these two screens (I proved this by testing with a plain-admin account and having it rejected).
  - Commission Overview shows agency-wide totals, a 12-month trend chart, an agent leaderboard with "cap" progress, a sponsorship/mentor tree, and a year-to-date profit summary.
  - Commission Management is a line-by-line list of individual commission entries with a Pending → Confirmed → Paid → Cancelled status an owner/super_admin can click through.
- **Commission & Revenue Share Settings**: split percentage, annual cap, post-cap fees, a monthly per-agent platform fee, and an optional 7-tier revenue-share scheme — seeded with default values in April and, per the system's own change log, never edited by anyone since.
- These figures are generated **once**, automatically, the moment a deal is first marked "Paid" in the Deal Register — using the agency's one generic default split percentage, not that specific deal's actual agreed split. It does not ever recalculate if the deal or the settings change afterward.
- **Worked example, one real deal (Deal #161, a real closed sale):** the actual settlement that governs what the agent was really paid shows a net amount of **R10,660**. The commission ledger these screens read shows **R41,200** for the same agent on the same deal — about 3.9x — because it re-applies the agency's generic 80% default split to an amount that had already been through that deal's own real split. That row was then written twice, one second apart, by a bug in the write-once logic (there is no database safeguard against this — I checked the table structure directly and confirmed there is no rule stopping a duplicate) — so the screens actually total **R82,400** for this one deal, about **7.7x** the real figure. This is the only duplicated row in the whole table (6 rows total, 5 real deals), but nothing prevents it happening again on any other deal.
- Real-data check: only 5 of your 82 real "paid" deals have ever produced an entry here at all — the mechanism only started working on 2026-08-19, never backfilled for anything before that.
- **Confirmed display-only:** I checked the codebase's real Payroll module (payslips, payroll runs) specifically, and it does not read this commission ledger anywhere — so this wrong number is not paying anyone the wrong amount. It is, however, shown to people as a real figure.
- "Confirm" / "Mark Paid" on Commission Management are just status labels — they don't check or connect to whether the agent was actually paid in the real Deal Register settlement.
- **Separately, a "My Earnings" personal view** (same underlying, currently-wrong data) has a much wider gate — **any agent, branch manager, admin, owner, or super_admin** can see their own figure there, not just owner/super_admin. This is the one screen where an individual agent could personally be looking at a wrong number about their own money.
- **A fourth, completely dead commission calculator** exists in the code (an unreachable "Agent Commission" report using worksheet data) — no link, no screen, orphaned.
- **The Commission Calculator tool** (under Tools, available to any agent) is unrelated to all of the above — a simple client-facing "what would the seller pocket" estimator. It uses a fixed 15% VAT rate and generic 50/60/70% split examples regardless of your agency's real configured split (80/20) — it was never meant to reflect real figures, but worth knowing agents may be showing clients numbers that don't match your actual commission structure.

---

## Where the money math genuinely lives (for reference)

Across everything above, "commission/deal money" is calculated in **four separate places** that do not currently agree with each other:
1. The real settlement inside the Deal Register — the authoritative one, what an agent is actually paid.
2. The Commission Overview/Management ledger — a separate, currently-wrong calculation (see Section 6).
3. The dead "Agent Commission" worksheet-based report — unreachable, a third formula nobody sees.
4. The Commission Calculator pitch tool — a rough client-facing estimate, not tied to real settings at all.

Everything else in this document (Worksheet, Targets, the Admin/BM Performance dashboards, the Performance & ROI Report) reads its money figures from #1, the real settlement — that part is consistent. Only Commission Overview/Management (#2) is the odd one out, and it's the one being presented to owners/super_admins as an authoritative company-wide commission view — with the same wrong underlying figures also visible to every ordinary agent on their own "My Earnings" page.

---

## Summary table — live, stale, or dead

| Screen / area | Status | Notes |
|---|---|---|
| Worksheet (agent) | Live, actively patched | Manual + real deal data mix; some calculation quirks noted above |
| Worksheet Market (branch/company) | Live | Feeds Worksheet's planning figures |
| Targets (points only) | Live but visually stripped down | Backend still computes unused figures |
| Targets — bottom-up calculator | **Dead** | Fully built, unreachable |
| Listing Targets | Live, but one-sided | No actuals ever shown against it |
| Monthly Goals | Live, but effectively empty | Rollup has nothing real to sum |
| Daily Activity (points system) | Live, actively maintained | Real usage, real auto-crediting, real anti-gaming rules |
| Daily Activity — legacy grid | **Dead** | Zero rows, unlinked |
| Daily Activity — old Targets-page grid | **Dead** | Zero rows, unreachable in UI |
| Daily Activity Summary (all 3 levels) | Live, actively maintained | |
| Daily Activities Setup | Live | Branch-specific custom activities are broken (see Section 3) |
| Admin/BM/Agent Performance dashboards | Live, actively maintained | Contains the broken 7-day widget and the stale Listing Stock tile |
| Performance Settings | Live | |
| Performance & ROI Report (Tools → Reports) | Live, currently the most actively developed screen | The only screen aware of DR2 |
| Listing Stock (Company / By Agent / Branch) | **Stale — six+ months, no refresh path** | Company-level hidden from menu; Branch-level still visible |
| Commission Overview | Live, but **producing wrong numbers** | Only 5/82 real deals represented, all inflated |
| Commission Management | Live, same issue as above | Status buttons don't reconcile with real payment |
| Commission Settings | Live | Never edited since April defaults |
| My Earnings (agent view) | Live, same wrong data | Visible to every ordinary agent |
| Agent Commission report | **Dead** | Unreachable, a third commission formula |
| Commission Calculator (Tools) | Live | Not tied to real settings — estimate tool only |

---

*This document was produced by reading the relevant code and, where noted, checking live QA1 data directly. No code, settings, or data were changed in the course of producing it.*
