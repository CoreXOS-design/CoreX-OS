# Spec: Flow Map (CoreX Interconnection Guide)

**Status:** APPROVED for Staging build (Andre, 2026-05-17). v1 = curated map + live event catalogue (NO activity feed — deferred to v2). "Flows" naming to be reconciled with Johan separately; built as "Flow Map" to avoid collision with `flows.md` (Flow Runner).

**Revision 2026-05-17b (per user feedback):** Scope widened from the deal lifecycle to a **full guide to everything in CoreX** — every module a new user needs (incl. Administration, Role Manager, Settings, System/Developer), each with an optional "how to use it" mini-flow (`steps`, e.g. Role Manager: pick role → tick permissions → set scope → save). Layout changed from a horizontal-scrolling stage pipeline to a **vertical, responsive, category-grouped wrapping grid** (no sideways scroll; better spacing). Config now keyed by `categories` (ordered sections) instead of `stages`; nodes carry `category` + `steps`. The "what comes next" chain is preserved via per-card Next chips.
**Priority:** Core — onboarding + orientation surface; front-end of the domain-events architecture
**Author:** Andre (drafted by Claude)
**Date:** 2026-05-17
**Sister specs (this consumes / depends on):**
- `.ai/specs/corex-domain-events-spec.md` — the event catalogue this page visualises
- `.ai/specs/flows.md` — Johan's **Flow Runner** (DIFFERENT thing; see Naming below)
- `.ai/specs/multi-tenancy.md` — agency scope on the audit feed
- `.ai/specs/UI_DESIGN_SYSTEM.md` — binding for the view

---

## 0. Naming — read this first

CoreX already overloads the word "Flows":

| Term | What it is | Spec | Status |
|------|-----------|------|--------|
| **Flow Runner** | Stateful, resumable multi-step transaction engine (Presentation → Mandate → Sale). `flows` DB table, step state machine. | `.ai/specs/flows.md` | Phase 2, not built |
| **Flow Map** (this spec) | A read-and-click **guide page**: a visual map of how every CoreX module interconnects and what comes next. No state machine. | this file | proposed |

These are complementary, not competing. The Flow Map is the *picture of the whole web*; the Flow Runner is *one journey through it*. To prevent user and developer confusion the page is titled **"Flow Map"** and routed under `/tools/flow-map`. If Johan prefers a different label (e.g. "How CoreX Connects", "System Map") that is a one-line change — flagged as an open question.

---

## 1. What this feature does and why

**Business requirement.** A new agent's hardest first-week problem is not any single screen — it is *not knowing how the screens connect*. They learn Properties, then Contacts, then Deals as islands, and never see that creating a property ripples into prospecting, buyer matching, the branch dashboard, and the audit log. Johan's domain-events architecture (`corex-domain-events-spec.md`) makes CoreX a spider web; **the Flow Map is the page that makes that web visible to a human**.

It answers three questions for every user:
1. *"Where am I in the bigger picture?"* — every module shown as a node in the lifecycle.
2. *"What comes next?"* — every node links to the next logical step (clickable navigation map).
3. *"What does this trigger?"* — when an action emits domain events, the map shows the cascade ("Property created → flagged on prospecting, 7 buyers matched, branch stats updated, audited").

It is an **orientation and navigation surface**, not a data-entry tool. It changes no business data. It reads.

**Why it is not just a static diagram.** A hand-drawn PNG rots the day Johan or Andre adds an event. Per the user decision, the map is **hybrid**:
- A **curated backbone** (config) defines the human lifecycle and module nodes — the big picture, written for first-day agents in plain English (STANDARDS F.8 binding rule).
- That backbone is **enriched live** from the domain-events catalogue (`app/Events/**`) + recent rows in `domain_event_log`, so the technical cascades stay accurate automatically as the system grows.

---

## 2. Pillars it connects to

The Flow Map does not own data. It is a **reader of the pillar graph**, not a writer. It connects to all four pillars *by reference* (it visualises how Property → Contact → Deal → Agent interlink) and writes nothing back. This is explicitly allowed: it is a guide/navigation surface, analogous to Training and the API catalogue, both of which are non-pillar-writing tool pages already accepted in CoreX.

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| Property | Node definitions reference the Property module + `PropertyCreated` etc. events | None |
| Contact | Same — Contact node + contact events | None |
| Deal | Deal node + `DealCreated`/`DealRegistered` | None |
| Agent | Current user's permissions decide which nodes render; actor on audit feed | None |

Cross-pillar reactivity: this page is a **subscriber-side visualiser only**. It does NOT emit events and does NOT add observer hooks. It reads the existing `domain_event_log` and the static `app/Events` class docblocks. (Per `corex-domain-events-spec.md` §E9 the event catalogue is the API contract — this page renders that contract.)

---

## 3. Data model / migrations

**No new tables. No migrations.** This is a deliberate constraint.

Content sources:

1. **Curated backbone** — a new config file `config/flow-map.php` returning an array of:
   - `stages` — ordered lifecycle stages (Property Identified → Listing Appointment → Mandate → Marketed → Offer → Sale → Registered; + Rental track), each with: `key`, `label` (plain English), `description`, `icon`.
   - `nodes` — every CoreX module/surface as a node: `key`, `label`, `description`, `stage`, `route` (named route or null), `permission` (the permission/route gate that decides visibility), `pillar` (property|contact|deal|agent|tool), `emits` (array of event class short-names from the catalogue, optional), `next` (array of node keys — "what comes next").
   - `tracks` — `sale`, `rental`, `prospecting` (for visual grouping / filtering).

   Rationale for config not DB: no per-tenant variation, no user editing, must be code-reviewed and version-controlled (it is documentation that ships with the code). Matches the "No hardcoding" rule's intent — this is configuration, not a hardcoded Blade array, and it is not user-facing terminology that varies per agency (it is the system's own architecture).

2. **Live enrichment (read-only)** — a service reflects over `app/Events/**/*.php` to list events that actually exist and their docblock summary, and optionally queries `domain_event_log` (agency-scoped, last N rows) so a node can show "last fired 2h ago" / recent real cascades. Read-only, cached.

If the live layer ever needs to be disabled, it degrades to the curated backbone alone (graceful — same pattern as `COREX_DOMAIN_EVENT_AUDIT_ENABLED`).

---

## 4. UI placement & navigation entry (non-negotiable #2)

- **Sidebar:** new item **"Flow Map"** inside the existing **Tools** section of `resources/views/layouts/corex-sidebar.blade.php`, immediately after **Training** (it is an orientation companion to Training).
- Gated exactly like its neighbours: inside the existing `@permission('sidebar.section.tools')` block, wrapped in its own `@permission('access_flow_map')` **and** `@if(\Illuminate\Support\Facades\Route::has('tools.flow-map'))`.
- **Per-user node visibility (user's explicit requirement):** every node on the map is rendered only if the current user can actually reach it. A node with `permission => 'access_deals'` is hidden for a user without that permission, exactly as the sidebar hides links. Implementation: the controller filters `config('flow-map.nodes')` through the same gate the sidebar uses (`$user->hasPermission(...)` / `Route::has(...)`) before passing to the view. **A user never sees a node for a section they cannot open.** Edges pointing to a hidden node are dropped so the map stays coherent.
- Page route: `GET /tools/flow-map` → `name('tools.flow-map')` (consistent with existing `tools.*` routes like `tools.commission`, `tools.pdf_suite.hub`).

No new top-level sidebar section. No orphaned page.

---

## 5. User flow (step by step)

1. User clicks **Tools → Flow Map** in the sidebar.
2. Page loads showing the CoreX lifecycle as a connected map: lifecycle stages left-to-right (Sale track) with the Rental track below, plus a Prospecting/Intelligence lane. Only nodes the user can access are drawn.
3. Each node is a card: icon, plain-English label, one-line description, and — if applicable — a small "Triggers" badge listing what it sets in motion (e.g. "Saving a property → flags it on prospecting, matches buyers, updates branch stats, audits it").
4. Connectors ("what comes next") are drawn between nodes. Arrows are directional and follow the curated `next` graph.
5. **Click a node →** navigates to that module (the clickable navigation map the user asked for). E.g. click "Properties" → `/corex/properties`. Nodes with no route (pure concept stages) are non-clickable, styled as labels, with a `title=` tooltip (STANDARDS F.8).
6. **Hover / focus a node →** highlights the node, its incoming and outgoing edges, and dims the rest, so the user sees *this connects to that*.
7. Optional track filter chips (All / Sale / Rental / Prospecting) to reduce visual load on mobile.
8. Mobile: the map degrades to a vertical, scrollable, stage-grouped list of the same nodes/links (STANDARDS "Mobile Awareness" — functional, not pixel-perfect).

No create/edit/delete on this page → Rule 13 (full CRUD) is N/A: it owns no entity. This is documented explicitly so the done-check does not flag it.

---

## 6. Permissions required (non-negotiable #5)

- New permission key **`access_flow_map`** added to the permission catalogue.
  - Root `CLAUDE.md` says `config/corex-permissions.php`; `.ai/CLAUDE.md` + memory say `CoreXPermissionSeeder.php`. **Pre-flight will confirm the live mechanism** and the key is added wherever the other Tools permissions (e.g. `access_pdf_suite`, `access_filing_register`) are defined, mirroring them exactly.
  - Default: granted to **all roles** (every user should see the map) — but each *node* is still individually gated by that node's own permission, so a low-permission user sees a smaller map. This satisfies "all users can see it but only the sections they have access to."
- Route middleware: `->middleware('permission:access_flow_map')` on the route (matching the pattern used by sibling tool routes; pre-flight confirms exact middleware name).
- Controller check: `$this->authorize` / explicit permission check at the top of the action, consistent with existing tool controllers.
- Node-level gating in the controller as described in §4.

---

## 7. Files to create / modify

**Create:**
- `config/flow-map.php` — curated backbone (stages, nodes, edges, tracks).
- `app/Services/FlowMap/FlowMapBuilder.php` — merges curated backbone + live event catalogue, filters by user permissions, returns the view model. Thin controller, logic in service (STANDARDS code style).
- `app/Http/Controllers/Tools/FlowMapController.php` — single `index()` action.
- `resources/views/tools/flow-map/index.blade.php` — the map view. Header comment declares `DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v <current>`. Alpine.js for hover/highlight + track filter. No jQuery. Tokens only, no hardcoded colours (must pass `scripts/check-design-tokens.ps1`).
- `tests/Feature/Tools/FlowMapTest.php` — see §9.

**Modify:**
- `routes/web.php` — register `GET /tools/flow-map` as `tools.flow-map` with permission middleware (placed with other `tools.*` routes).
- `resources/views/layouts/corex-sidebar.blade.php` — sidebar entry in Tools section (after Training), gated as in §4.
- Permission catalogue file (confirmed at pre-flight) — add `access_flow_map`.
- `.ai/ROADMAP.md` — add Flow Map under built/in-progress.
- `.ai/CODEBASE_MAP.md` — add the new controller/service/route to the map (only after build, per its own rules).

**Explicitly NOT touched:** `.ai/specs/flows.md` (Johan's Flow Runner — different feature, lives on main), any pillar model, any observer, any migration.

---

## 8. How the hybrid stays accurate

- The curated backbone is reviewed like documentation — when a major module ships, its node is added to `config/flow-map.php` in the same PR (added to the done-checklist of large feature specs going forward; flagged to Johan).
- The live layer reflects `app/Events/**` at runtime (cached 1h) so newly added events (Johan's or Andre's) surface on the relevant node's "Triggers" badge **with zero edits to this page** — fulfilling the user's "it should include everything in CoreX" and the spec's hybrid decision.
- A node whose `emits` references an event class that no longer exists renders without the badge (no fatal) and is surfaced by the test in §9 so the backbone gets corrected — keeping the map honest.

---

## 9. Acceptance criteria

1. `GET /tools/flow-map` resolves, returns 200 for a permitted user, 403 for a user lacking `access_flow_map`.
2. Sidebar shows "Flow Map" under Tools for permitted users; absent for users without the permission; absent if the route is not registered (no orphan, no crash).
3. The map renders all stages and only the nodes the current user can access. A user without `access_deals` does not see the Deals node, and no edge points to it.
4. Every clickable node navigates to a route that resolves. No dead links (test asserts every node `route` satisfies `Route::has`).
5. Every visible label is plain English or has a `title=` tooltip (STANDARDS F.8) — asserted by reviewer, and no raw enum/jargon in the config.
6. Live enrichment: adding a new event class under `app/Events/` makes its summary available to its node's "Triggers" badge after cache clear, with no edit to the view.
7. Disabling the live layer degrades cleanly to the curated backbone (no error).
8. Multi-tenancy: the recent-activity feed (if shown) only ever reads `domain_event_log` for the user's effective agency (AgencyScope / explicit agency filter). Test asserts no cross-agency rows.
9. Mobile viewport: page is usable (vertical stage list), no horizontal scroll trap.
10. View passes `scripts/check-design-tokens.ps1`. No hardcoded colours/fonts.
11. `scripts/dev-check.ps1` passes with 0 new failures. `php -l` clean on all new PHP. Routes/views/cache cleared and functionally verified via Tinker (route resolves, view renders, controller returns expected node count for a seeded user with limited permissions).
12. No new table, no migration, no observer, no event emitted by this feature (it is read-only) — verified.

---

## 10. Out of scope (this spec)

- The Flow **Runner** state machine (`.ai/specs/flows.md`, Phase 2).
- Editing the map from the UI (it is code-reviewed config).
- Per-agency customisation of the map.
- Real-time websocket updates of the activity feed (a cached read is sufficient).
- Emitting any domain event (this is a pure subscriber/visualiser).

---

## 11. Open questions for Johan / Andre

1. **Label.** "Flow Map" vs "How CoreX Connects" vs "System Map" vs "Journey Map". Recommend **Flow Map** but defer to Johan since "Flows" is loaded.
2. **Permission default.** All roles by default (recommended, since node-level gating already restricts content), or restrict the whole page to a permission Johan assigns per role?
3. **Activity feed.** ✅ DECIDED — deferred to v2. v1 is curated backbone + live event catalogue badges only; no `domain_event_log` reads in v1 (removes the multi-tenancy surface from v1 entirely; AC #8 becomes N/A for v1).
4. **Backbone ownership.** Agreement that every future major feature spec adds its node to `config/flow-map.php` as part of its done-checklist (keeps the hybrid honest).
5. Does Johan want this committed to `main` as the agreed spec (per spec-sync rule) before Andre builds on `Staging`?
```
