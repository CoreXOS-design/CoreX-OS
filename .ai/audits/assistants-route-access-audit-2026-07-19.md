# AT-267 — full per-route assistant-access audit

> Date: 2026-07-19 · Branch: QA2 · Method: enumerated all 2,022 registered routes, resolved each
> route's gathered middleware, and classified by HOW an assistant reaches it. Goal: for a feature
> this sensitive, prove there is no route that exposes agent-personal / financial / admin /
> cross-user data or actions to an assistant. Phpunit dev-deps installed in-lane; fixes tested.

## Method

An assistant's permissions are their agent's, intersected live (minus the property-upload lock
and the admin-default-off sections). So a **`permission:`-gated route** is only reachable by an
assistant if the agent has that permission — the resolver already governs it. The real risk is
routes an assistant reaches **regardless of permissions**:

- **Ungated** — authed route with NO `permission:` and NO `deny_assistant`. Reachable by ANY
  authenticated user, assistant included. (My Earnings was one.)
- **Feature-only** — gated by `feature:` but no permission. `feature:` is per-AGENCY, not
  per-user, so it does not keep an assistant out on its own.

Each route was bucketed from its resolved middleware (`$route->gatherMiddleware()`).

## Numbers

| Bucket | Count |
|---|---:|
| Total registered routes | 2,022 |
| Not authenticated (login/asset/public — skipped) | 159 |
| **Permission-gated** (assistant governed by the resolver) | 1,269 |
| **Already blocked** (`deny_assistant*` / `owner_only`) | 183 |
| **Ungated authed** (reachable by any assistant) | 379 |
| **Feature-only** (reachable if agency has the feature) | 19 |

The 379 ungated routes are mostly **legitimate agent work** an assistant is meant to do, scoped
to the agent's data by `dataIdentityIds()`: calendar, tasks, contacts, core-matches, ellie,
presentations, viewing packs, deal/property reads. Those are fine. Filtering the 379 + 19 for
financial / admin / personal / cross-user surfaces yields the risk set below.

## Findings

### A. FIXED — confirmed assistant gaps (no permission AND no in-controller check)

| Route(s) | Why a gap | Fix |
|---|---|---|
| `commission.dashboard` (/my-earnings), `commission.index`, `commission.principal`, `commission.confirm`, `commission.pay`, `revenue-share.calculator` | Agency finance / personal commission. `/my-earnings` + revenue-share were fully ungated; the others were `feature:`-only (per-agency). An assistant has no commission (§10) and must never see it. | **`deny_assistant`** on every route + nav `@unless`; verified an assistant is redirected, agent unaffected. |
| `profile.destroy` | An assistant could DELETE THEIR OWN ACCOUNT (no permission/assistant check — verified). §10: deleting an assistant is an admin action. | **`deny_assistant`**. |

Locked by a permanent ratchet — `AssistantRouteGuardTest`: the named routes above must carry
`deny_assistant`, AND every `commission.*` / `revenue-share.*` route must, so a NEW finance route
that forgets fails the suite instead of silently exposing finance to an assistant.

### B. PRE-EXISTING BROAD GAPS — ungated for EVERY user, not assistant-specific (needs its own hardening ticket)

These carry no permission MIDDLEWARE, so they are reachable by any authenticated user — an
assistant is not uniquely privileged here. Several likely have IN-CONTROLLER checks my
middleware-level pass cannot see (e.g. `CoreX\SettingsController` gates features with
`hasPermission()` internally), so each needs per-controller verification before being called a
hole. Flagged for a **separate, non-assistant hardening pass** (adding proper `permission:`
middleware benefits all users, not just assistants):

- `corex/compliance/rcr/*` (index/show/export/store/submit/send-for-review/auto-populate/evidence/answers) — compliance-return surfaces; **verify** whether these are the practitioner's own return (assistant help may be legitimate) or officer/cross-agent (must gate).
- `corex/compliance/agents` — a cross-agent compliance view; **verify** in-controller scoping.
- `corex/admin/activity-mappings/*` — agency activity-point config; a `manage_activity_mappings` permission EXISTS but is not on the route.
- `corex/admin/rcr/questionnaires/*` (incl. `import-csv`) — agency compliance-questionnaire admin.
- `corex/command-center/settings/*` — command-centre config (event classes, expectations, rules); **verify** per-user vs agency.
- `corex/settings/generate-token` — API token generation (`SettingsController` gates on auth; verify it is the caller's own token only).

**Recommendation:** open a CoreX route-hardening ticket for Section B — add `permission:` (or
`deny_assistant` where genuinely agent-personal) to each after confirming in-controller state.
Because these are broad (any user), they are lower assistant-specific risk than Section A but
higher overall-platform risk. Not mass-blocked here: blocking compliance surfaces an assistant
may legitimately help the agent with is Johan's business call, and silently changing all-user
routes under an assistant ticket would be the wrong lever.

### C. LEGITIMATE — the bulk of the 379

Agent-scoped operational routes (calendar/tasks/contacts/matches/ellie/presentations/viewing
packs/deal+property reads). An assistant using these on the agent's behalf, confined to the
agent's data by `dataIdentityIds()`, is the entire point of the feature. No action.

## Bottom line

For the **assistant feature specifically**, the confirmed gaps — agency finance and account
self-deletion — are closed and ratcheted. The remaining Section-B items are **platform-wide**
ungated-route hygiene (any user reaches them) that an assistant does not make uniquely worse;
they deserve their own hardening pass with per-controller verification and a business call on
compliance-surface access. There is no known route that hands an assistant escalation or another
agent's private data beyond those tracked items.
