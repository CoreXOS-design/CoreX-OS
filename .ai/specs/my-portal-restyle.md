# My Portal restyle: Sub-tabs (Option D, second round)

Status: BUILT on Staging 2026-09-30; REWORKED on QA1 2026-10-26 for no scrolling, one Chrome extension sub-tab and a My Performance sub-tab (sections 9 to 11). Original status: BUILT on Staging 2026-09-30 (Johan: "lets build d"). Uncommitted at time of writing. A first "Hairline Grid" cell layout was built and reverted the same day; Johan then ruled out card/block layouts and asked that the signature area stay big. This replaces it.
Mockup: https://claude.ai/artifact/XQ8CSXztuuJnqr1qAnAkrg (Option D, all ten tabs)
Route: `/my-portal` (`agent.portal`), view `resources/views/agent/portal.blade.php`

## 1. What and why

The agent portal scrolls too much and looks plain. This is a **visual restyle only**. The ten tabs, every field, every permission rule, every route and every saved value stay exactly as they are today. The goal is less scrolling, more polish, and one consistent card size.

Pillars: Agent (User). No new data, no new events, no new settings.

## 2. Design (as built)
- Header and the top tab bar are exactly as before. No sidebar, no card blocks.
- Under each tab, a second row of text sub-tabs lists that tab's sections. **One section shows at a time, full width.** State is `sub.<tab>` in Alpine, not in the URL hash.
- Sections are plain content (no borders or backgrounds). Compliance, Payslips and Leave have one section and no second row.
- The signature and initial pads are full width (Profile > Signature & PIN).

| Tab | Sub-tabs |
|---|---|
| Overview | My Earnings, Compliance Overview, My Presentations, Recent Activity, Training Progress (each hidden exactly when it was before) |
| Profile | Signature & PIN, Photo & Public Page, Contact & Licence, Public Website Profile (not assistants), Admin Managed, Branches I Manage (when permitted), Articles |
| Favourites | Favourites, My Favourites, All Pages. One Save Favourites stays visible under all three |
| Tools | Theme, App Access, API Token + Chrome Extension (when shown), Social Media (when shown), Client QR Code, WhatsApp (when permitted) |
| Documents | One sub-tab per document type, with a status dot |
| Training | RMCP Acknowledgement, Policies (if outstanding), Other Training (if any) |
| Password | Update Password, Delete Account (not assistants) |

## 3. Rules that hold
- Every field keeps its name, form, method and route. The Profile form stays one form with three sections inside it.
- A failed profile save reopens the section holding the error.
- Assistant restrictions (AT-267 §10) and `data-tour` anchors unchanged. CoreX tokens only, light and dark. No new setting, so Setup Wizard rule 10a does not apply.

## 4. Files
Modified: `resources/views/agent/portal.blade.php` (one scoped `<style>` block, no asset build) and `resources/views/agent/_signature-settings.blade.php` (root wrapper only).

## 5. Acceptance
1. All ten tabs and every sub-tab render for an agent; the right subset for an assistant.
2. Every form saves as before.
3. `#compliance` deep links and the "Upload document / Update in Profile" jump buttons still switch tabs.
4. No horizontal scroll at 400px. Light and dark both correct.

## 9. Ruling 1 (Johan, 26 Oct 2026): NO scrolling on My Portal

Johan: "we have tabs, so no screen is holding massive amounts of data. why do we want it to scroll?" Every tab and every sub-tab must fit below the fixed header on a standard laptop (1366x768 and 1536x735, sidebar open) with no vertical page scroll and nothing off-screen. Designed in, not by shrinking fonts.

**How it is built**
- The header is ONE row (title, who you are, profile %, tour, compliance status, settings); the helper line and the second identity strip are gone. Tab bar, sub-tab row and pane padding are tight (see the `.pg-*` / `.mp-*` styles at the top of `agent/portal.blade.php`: `mp-card`, `mp-grid mp-g2..g5`, `mp-row`, `mp-h`).
- Every line on screen is data or a control (STANDARDS: screen real estate goes to function): helper paragraphs and repeated metadata were removed or folded into one-line notes.
- Dense layouts: Where I Am = four figures across, then You / branch / company beside Momentum (the comparison that used to sit below the fold). Targets & Budget = stock + budget strip, what the budget needs beside the deal summary, my commission strip. My Activity, Compliance (two columns), Contact & Licence (three across), Password (three across), Training, Favourites, Articles and the PPRA letters were re-laid the same way.
- Lists are capped and still reach everything: recent activity 8 rows with Show all (earnings page); other training 10 rows with Show all (training page); admin access log 4 entries plus "and n earlier"; managed branches 12; articles and PPRA letters are paged inside the pane (6 and 3 a page, Older/Newer), so the pane never grows with the data. The Favourites "All Pages" picker no longer scrolls inside itself: pick a section chip and see its pages four across, or search / "Only my favourites" to see matches across every section; at most 40 pages are drawn at once, the search narrows the rest.
- Hooks for the checks: `data-portal-tab`, `data-portal-pane`, `data-portal-sub` on every tab, pane and sub-tab; `data-portal-rows` / `data-portal-cap` on capped lists.

**How it is proven (two ways, both kept)**
1. `tests/Feature/Agents/MyPortalNoScrollTest.php` - a server-side structure and row budget. PHPUnit has no layout engine, so it cannot measure pixels: it proves every tab and sub-tab exists and is reachable, no pane is orphaned, capped lists draw at most their cap with a way to the rest, no inner scroll box is left, the Chrome extension merge and the My Performance rules below, and the assistant strip-down.
2. `scripts/my-portal-fit-check.mjs` - a real Chromium opens `/corex/my-portal` as a real user at 1366x768 and 1536x735 and, for every tab and sub-tab, requires the page scroller's scrollHeight <= clientHeight (no vertical scroll) and no sideways scroll. Run on QA1, 26 Oct 2026: an HFC agent (Retha Kelly) 72/72 screens fit; Johan 74/74 (his page has the extra My Performance and Branches screens). Before the rework, 8 of 36 screens overflowed for the same agent (worst: Targets & Budget +700px, Where I Am +447px).
   `node scripts/my-portal-fit-check.mjs --app-root=/corex-qa1 --user-id=<id> [--shots=DIR]`

## 10. Ruling 2 (Johan, 26 Oct 2026): API token and Chrome extension are ONE sub-tab

Tools now reads Theme, App Access, **Chrome extension**, Social Media, Client QR Code, WhatsApp. The token exists only for the extension, so the one sub-tab shows, in this order and side by side: (1) your API token (shown only when generated; Copy / Regenerate / Generate), (2) install from the Chrome Web Store (version shown), (3) connect: paste the token into the extension's API Token field. Same gate as before (`portal_show_api_token`); the old separate API Token sub-tab is gone. The admin setting that switches it on keeps its existing label (Company Settings, outside this task).

## 11. Ruling (agency-tracker-rehome.md ruling F, audited NOT STARTED at 09:31): My Performance on the Overview

New Overview sub-tab **My Performance** (after My Activity; not for assistants; shown when the `performance-dashboards` feature is on). It is the agent's OWN audited figures, the same `ReportingService` calls as Dashboard > My Performance (`command-center.reporting.agent`): `getAgentMetrics`, `getConversionFunnel(['user_id' => me])`, `getAgentInsights`, always with the signed-in user's id and nobody else's (OWN scope only; the test mocks the service and asserts the id). One fitting screen: eight figures in two rows (events completed with change vs the prior period, viewings, presentations, feedback rate, active buyers, high-risk buyers, lost deals, lost value), the conversion funnel beside up to four insights, range chips 7d / 30d / 90d / Year (default 30d, same four as the full report) and ONE link "Full performance" to the full report. Code: `AgentPortalController::myPerformanceBlock`, view `agent/portal-blocks/_my-performance.blade.php`. `?sub=performance&days=N` opens it directly.

