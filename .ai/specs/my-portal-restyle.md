# My Portal restyle: Sub-tabs (Option D, second round)

Status: BUILT on Staging 2026-09-30 (Johan: "lets build d"). Uncommitted at time of writing. A first "Hairline Grid" cell layout was built and reverted the same day; Johan then ruled out card/block layouts and asked that the signature area stay big. This replaces it.
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
