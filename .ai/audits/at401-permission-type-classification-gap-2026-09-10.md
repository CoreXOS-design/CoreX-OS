# AT-401 — permission-type classification gap: `type => 'access'` keys whose runtime needs a scope

Status: **REPORT ONLY — not fixed, not decided.** This is Johan's call. Written
alongside the AT-401 fix that restored agency 1 admin's `rental_applications.view`
scope and stopped `RoleManagerController::savePermissions()` from nulling out
unsubmitted scope values (see that commit for the incident this gap caused). cc3 has
a related, parallel investigation into this same schema area — this write-up is meant
to sit alongside those findings, not replace them.

## The gap, precisely

`config/corex-permissions.php` classifies every permission as one of a small set of
`type` values (`action`, `access`, `view`, ...). Role Manager's own UI
(`resources/views/corex/role-manager.blade.php:169-180`) builds its own/branch/agency
scope selector **only** for permissions where `type => 'action'` AND the key ends in
`.view` (`$fActionMap`). Any `.view` key classified as anything else — `access`,
`view`, or otherwise — gets no scope selector at all: an admin editing that role in
Role Manager cannot see or set a scope for it, because the control simply never
renders.

That's fine for a `.view` key whose runtime access check is a plain yes/no toggle. It
is **not fine** for a `.view` key whose runtime behaviour is read through
`PermissionService::getDataScope($user, $module)` — a function that returns
`'own'`/`'branch'`/`'all'`/`null`, not a boolean. A key in this state has real,
load-bearing scope semantics with no UI to configure them and — as the incident this
accompanies proved — no protection against a full-role resave silently blanking
whatever scope value it happened to hold.

## What was checked, and how

Two questions, checked against the real codebase rather than assumed:

1. Every permission key in config whose name ends in `.view` and whose `type` is
   **not** `'action'` (i.e. no scope selector renders for it).
2. Whether that key's module string is actually passed to
   `PermissionService::getDataScope()` (directly, or via one of its wrapper methods —
   `calendarScope()`, `taskScope()`, `contactRentalHistoryScope()`,
   `marketIntelligenceScope()`, `outreachCanvassingScope()`, `deedsCaptureScope()`,
   `dr2UnfiledEmailsScope()` — all of which resolve to `getDataScope()` internally)
   anywhere in `app/`.

## Result: thirteen candidates, exactly one is actually broken this way

| Key | Type | Module | Scope read at runtime? |
|---|---|---|---|
| `rental_applications.view` | `access` | `rental_applications` | **YES** — `AuthorizesRentalApplicationAccess::guardRentalApplication()` and `RentalApplicationController::index()` both call `getDataScope($user, 'rental_applications')` directly, with no `??` fallback in the per-record guard. **This is the one that broke.** |
| `dashboard.oversight.view` | `access` | `dashboard` | No — no `getDataScope($user, 'dashboard')` call site found anywhere. |
| `esign.compiler.view` | `access` | `esign-compiler` | No. |
| `agency_api.view` | `access` | `agency_api` | No. |
| `marketing_suppressions.view` | `access` | `marketing` | No. |
| `mic.comments.view` | `access` | `mic` | No — the only "own" check in that controller is comment-author-only, unrelated to data scope. |
| `deal_comms_suspense.view` | `access` | `deal_comms_suspense` | No. |
| `billing.view` | `view` | `billing` | No. |
| `command_center.view` | `access` | `command_center` | No. |
| `command_center.health.view` | `access` | `command_center` | No. |
| `command_center.automation.view` | `access` | `command_center` | No. |
| `outreach.summary.view` | `access` | `outreach` | No. |
| `assistants.view` | `access` | `assistants` | No — assistant breadth is resolved through `AssistantPermissionResolver`, a separate mechanism keyed off the assistant *user*, not this permission's own scope. |

The other twelve are plain on/off toggles today — correctly classified, not affected
by this bug class, nothing to fix on them. `rental_applications.view` is the only
key in this codebase, right now, that combines "no scope UI" with "runtime scope
consumption with no safe default."

## The correct classification — recommendation, not a decision

Two honest options, and the choice is Johan's:

1. **Reclassify `rental_applications.view` as `type => 'action'`.** It would then get
   Role Manager's existing scope selector for free, exactly like `properties.view` and
   `core_matches.view` already do — no new UI, no new mechanism, just joining the
   pattern that already works. This is the smaller change and the one that matches how
   every other scope-bearing `.view` permission in this codebase is already handled.
   Open question this raises: `type => 'access'` vs `'action'` also affects whichever
   OTHER part of Role Manager's grouping/matrix logic keys off `type` (the module
   grouping headers, any bulk-toggle behaviour) — worth a quick check that
   reclassifying doesn't move this permission into a visually different part of the
   Role Manager screen than agents are used to finding it in.
2. **Add a second code path in Role Manager's UI** for `type => 'access'` keys that
   also need a scope, without changing their `type`. More surface area, more code, no
   real benefit over (1) that this investigation could find — included for
   completeness, not because it looks like the better option.

Recommendation, stated plainly since asked for: **option 1.** It is strictly less code
and brings `rental_applications.view` in line with the pattern every other scoped
`.view` permission already uses, rather than inventing a second one.

## What this write-up does NOT do

It does not reclassify `rental_applications.view`. It does not touch
`config/corex-permissions.php`. It does not change Role Manager's rendering logic.
Those are all Johan's call, and — per the standing "one concern per prompt" rule —
would be their own separately-scoped piece of work if approved.
