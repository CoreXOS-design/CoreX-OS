# AT-366 — Performance & ROI report: who sees what (2026-10-07)

**Why:** the report took `branch_id` / `user_id` from the URL with only an agency check, so any role that could open it (agents included) could read company, branch and any other agent's figures — and commission — by editing the URL, including through the drill-down pop-up, the agent / branch pages and the print pages (audit `qa1-cc6-lead-response-audit-2026-10-07`, defect 1).

## The rule (business)

| Role (Role Manager default) | Sees | A URL asking for more |
|---|---|---|
| Agent | their own figures | the screen shows their own figures anyway; another agent's page / a branch page is refused (403) |
| Branch manager | their own branch | same, for another branch or another branch's agent; an agent **inside** their branch can be opened |
| Admin / owner / agency-level role | the whole agency, never another agency | an id from another agency is ignored |

Applies to: the report screen (incl. the compare-with-previous-period view), every drill-down page, the drill-down pop-up data, and the print pages.

## The breadth setting

`performance_report.view` (module `performance_report`) — Role Manager row **"Performance report → View"** with own / branch / all, defaults from `scope_defaults` (agent = own, branch manager = branch, admin = all, viewer = branch). `view_performance` stays the plain "can open the report at all" gate. A role that holds `view_performance` but **no** `performance_report.view` row (e.g. office admin) reads as **own** — it fails closed; raise it in Role Manager. New rows reach existing agencies through `corex:sync-permissions --merge-defaults` on deploy. (Not an agency setting, so no Setup Wizard row — it is a Role Manager permission, in the same family as `buyers_report.view`.)

## How it is enforced (engineering)

- `PerformanceReportScopeResolver` turns (viewer, requested branch/agent) into a `PerformanceScope` carrying the viewer's **ceiling**; `HierarchyResolver::agents()` and `PerformanceDrilldownService::cohort()` AND that ceiling onto the agent cohort query — so no figure, row or comparison can be built from an agent outside it, whatever was requested. A branch-level viewer with no branch sees nobody, not everybody.
- It reuses `BuyersReportScopeResolver` (pointed at the `performance_report` module) for the ceiling and for `canViewAgent` / `canViewBranch`. **That resolver now fails closed**: a role with no `{module}.view` row = own (it used to mean the whole agency — audit defect 7; affects the Buyers Report too).
- Legacy pages with the same gap now check the same ceiling: `admin.performance` (company → agency only), `admin.branch.performance` (canViewBranch), `admin.agent.performance` and `bm.agent.performance` (canViewAgent), `bm.performance` (branch pages → not for an own-only viewer). Platform owners keep their by-design cross-agency access on the three `admin.*` pages.
- UI: an own-only viewer's branch names are plain text (no link that would 403).

Tests: `tests/Feature/Performance/PerformanceReportScopeTest.php`.

## Not changed (reported)

See `/tmp/qa1-cc3-report-scope-fixes-2026-10-07.md` §"Found, not changed".
