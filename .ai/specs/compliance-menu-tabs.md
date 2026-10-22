# Compliance menu — 13 links become 5 items

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre: "now compliance"). Not committed.**
> Johan must see this before it leaves QA2.
> Owner: Andre · Same mechanism and rules as `.ai/specs/real-estate-menu-tabs.md` (§2 there applies word for word).

## 1. The new Compliance menu

| # | Menu item | Tabs (in order) — each is today's page on today's route |
|---|---|---|
| 1 | FICA | *(unchanged plain link — the daily work)* |
| 2 | **RMCP & Policies** | RMCP · RMCP Dashboard · Policies · Policy Register |
| 3 | **Agents & Staff** | Verification Queue · Agent Compliance · Staff Screening · Document Types · Agency Documents |
| 4 | **Reports & Logs** | Compliance Reporting · Communications Log |
| 5 | Send Standalone Info Pack | *(unchanged plain link — an action)* |

Every label is unchanged. The counts move with their screens: the pending-verification number sits on the Verification Queue tab,
the pending-approval number on the Compliance Reporting tab, and the Agent Compliance amber dot on its tab; each menu item shows the
total of its numbers (or the dot when there is no number) — the same amber styling as before.

### 1.1 Gates per tab (copied verbatim — not changed). Every tab also carries the panel's own gate: `access_compliance` + feature `compliance`.

| Hub | Tab | Route | Permission(s) / condition |
|---|---|---|---|
| RMCP & Policies | RMCP | `compliance.rmcp.index` | `access_rmcp` |
| RMCP & Policies | RMCP Dashboard | `compliance.rmcp.dashboard.index` | `access_compliance_dashboard` |
| RMCP & Policies | Policies | `compliance.policy.index` | `access_policy` |
| RMCP & Policies | Policy Register | `compliance.policy.dashboard.index` | `access_compliance_dashboard` |
| Agents & Staff | Verification Queue | `compliance.verification.index` | `verify_user_documents` |
| Agents & Staff | Agent Compliance | `compliance.agents` | System Owner, or effective role `super_admin` |
| Agents & Staff | Staff Screening | `compliance.screening.dashboard.index` | `manage_employee_screenings` AND `access_compliance_dashboard` |
| Agents & Staff | Document Types | `compliance.document-types.index` | `manage_agency_compliance` |
| Agents & Staff | Agency Documents | `compliance.agency-settings.index` | `manage_agency_compliance` |
| Reports & Logs | Compliance Reporting | `compliance.whistleblow.index` | `compliance.whistleblow.view` |
| Reports & Logs | Communications Log | `compliance.communications.index` | `access_communication_archive` |

## 2. No data, settings or new list screens

Navigation only — no pillar data, migration, permission/feature key or agency setting (nothing for the Setup Wizard); each tab keeps
its own search/sort/filter/scoping. Labels are neutral.

## 3. As built / proof

- `App\Support\Navigation\Hubs\{RmcpPolicies,ComplianceAgents,ComplianceReports}Tabs`, registered in `TabHubs`; landing routes
  `corex.hubs.rmcp-policies`, `corex.hubs.compliance-agents`, `corex.hubs.compliance-reports` (resolve to the Compliance panel).
- `TabHub` gained a dot-only badge (`dotOnly()` / `hasDot()`): a dot never adds to the item's number.
- `tests/Feature/Navigation/ComplianceMenuTabsTest.php` — equivalence (5 user types × 11 tabs, incl. a user holding tab keys but not
  the panel's `access_compliance`), the five-item menu, landing, every tab the admin sees opens with its bar and its own tab marked,
  the counts. `TabHubSwitchesTest` covers these hubs too (Agent Compliance skipped there — System-Owner-only, not a switch).
- Nine sidebar allow-list entries came off (RMCP / Policy dashboards + reports, the staff-screening screens): those routes now light
  exactly one item.
- Guided tours: comp-rmcp, comp-policy, comp-verification, comp-whistleblow — all 23 targets present on the rendered pages before and
  after; none lost.

## 4. Noted, not changed (pre-existing, for a separate decision)

- The Agent Compliance dot counts active users without an FFC number across **all agencies** (no agency filter) — it is shown only to
  the System Owner, so it may be intentional, but under the multi-agency rule it should be checked.
