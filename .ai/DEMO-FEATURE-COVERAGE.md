# DR2/Demo Feature Coverage — Master Table

Built 2026-09-03, enumerated from `routes/web.php` + `resources/views/layouts/
corex-sidebar.blade.php` (~200 sidebar routes), not from memory. "Has data"
reflects direct DB counts or renders checked THIS session (tonight) — where
I have not personally verified, marked `unknown`, not guessed.

| Screen | Route | Owning lane | Has data? | Risk if clicked tomorrow |
|---|---|---|---|---|
| **Command Center Today (the real dashboard)** | `corex.dashboard` (`/corex`) | cc6 | Y — verified admin/BM/agent all populated | Low |
| Command Center Tasks | `command-center.tasks` | cc6 | unknown | unknown |
| Command Center Performance | `command-center.performance` | cc6 | unknown | unknown |
| Command Center Reporting (agency/agent/branch) | `command-center.reporting.*` | cc6 | unknown | unknown |
| Command Center Lost Deals | `command-center.lost-deals` | cc6 | unknown | unknown |
| Command Center Buyers Pipeline | `command-center.buyers.pipeline` | cc6 | Y (dashboard card shows 111) | Low |
| Calendar | `command-center.calendar` | cc6 | Y — 1,455 events | Low |
| Calendar Invitations | `command-center.calendar.invitations` | cc6 | Y — FIXED tonight, 5 seeded | Low |
| Viewing Packs | `corex.viewing-packs.index` | cc6 | Y — 3 live | Low |
| Feedback Reports | `command-center.feedback-reports` | cc6 | unknown | unknown |
| BM My Dashboard / Daily Summary / Performance / Listings / Worksheet | `bm.*` | cc6 | unknown | unknown |
| Agent Daily / Daily Summary | `agent.daily*` | **UNOWNED** | unknown | flag |
| Worksheet | `worksheet.index` | cc6 | unknown | unknown |
| DR2 Pipeline (list/timeline) | `deals-dr2.pipeline.*` | cc6 | Y — showcase deal 111 built + verified tonight | Low |
| DR2 Register | `deals-dr2.index` | cc6 | Y — 125 live deals, 0 dupes (verified last night) | Low |
| DR2 Unfiled Emails | `deals-dr2.unfiled-emails.index` | cc6 | Y — 7 live suspense rows | Low |
| Deal-link Review | `corex.admin.deal-link-review.index` | cc6 | unknown | unknown |
| DR2 Pipeline Setup | `deals-v2.pipeline.index` | cc6 | Y (config) | Low |
| Legacy deals-v2 register | `deals-v2.index` | cc6 | N/A — soft-retired, redirects | none, don't demo |
| Agent's Own Deals | `agent.deals.index` | cc6 | unknown | unknown |
| Commission (index/dashboard/principal) | `commission.*` | cc6 | unknown | unknown |
| Deposit Interest Calculator | `deposit-interest-calculator.index` | cc6 | N/A — calculator tool | none |
| Comms-suspense (attorney emails) | `corex.comms-suspense.index` | cc6 | Y — 7 live rows | Low |
| Market Intelligence Work (login landing) | `market-intelligence.work` | cc6 | Y — verified rich | Low |
| Map | `corex.map.index` | cc6 | Y — verified | Low |
| Suburb Report | `market-intelligence.suburb-report.index` | cc6 | Y — verified | Low |
| Core Matches | `corex.core-matches.index` | cc6 | Y — 2,382 + 1,730 real matches | Low |
| Outreach (canvassing/queue/summary) | `corex.outreach-*` | **cc2 (reassigned)** | N — 0 rows, cc2 owns fix | **HIGH if clicked — empty** |
| Portal Leads | `corex.portal-leads.index` | cc6 | Y — 98 metric rows with leads | Low |
| Buyers Report | `buyers-report.index` | cc6 | unknown | unknown |
| Commercial Evaluations | `commercial-evaluations.index` | **UNOWNED** | N — 0 rows confirmed | **HIGH — empty, flag** |
| Evaluation | `evaluation.index` | **UNOWNED** | unknown (table not found under guessed names) | unknown, flag |
| Properties list/detail | `corex.properties.index` | cc2 | Y — verified rich, 512 live properties | Low |
| Rentals | `rentals.index`, `rental.dashboard` | cc2 | Y — 15 rental listings seeded | Low |
| Presentations (index/analytics/outcomes/refresh) | `presentations.*`, `corex.presentations.*` | cc2 | Y — verified renders, 52 presentations | Low |
| Filing Register | `filing-register.index` | cc2 | unknown (was in cc1's brief) | unknown |
| Documents Library / Shared Drive | `documents.library.index`, `documents.shared-drive.index` | cc4 | unknown | unknown |
| PDF Splitter / Image Converter tools | `tools.*` | cc2/cc4 | N/A — utility, low priority | Low |
| FICA | `compliance.fica.index` | cc3 | Y (per earlier config sweep) | Low |
| Compliance Policy (index/dashboard) | `compliance.policy.*` | cc3 | Y — FIXED, 1 policy | Low |
| RMCP (index/dashboard) | `compliance.rmcp.*` | cc3 | unknown — never checked | unknown, flag |
| Screening Dashboard | `compliance.screening.dashboard.index` | cc3 | Y — 11 rows | Low |
| Whistleblow | `compliance.whistleblow.index` | cc3 | unknown | unknown |
| Comm Archive/Flags/Mailboxes | `compliance.comm-*` | cc3/cc5 | unknown | unknown |
| Document Types (compliance) | `compliance.document-types.index` | cc3 | Y — FIXED | Low |
| Verification | `compliance.verification.index` | cc3 | unknown — never checked | unknown, flag |
| Seller Info | `compliance.seller-info.index` | cc3 | unknown — never checked | unknown, flag |
| Payroll Employees/Runs | `payroll.employees.index`, `payroll.runs.index` | cc3 | Y — verified, Jul/Aug finalised, real payslips | Low |
| Payroll Leave (applications/balances/types/holidays/dashboard) | `payroll.leave.*` | **UNOWNED / cc3 by proximity, unconfirmed** | unknown | flag |
| Staff Take-on | `staff-take-on.index` | cc3 | unknown | unknown |
| Billing | `billing.index`, `admin.billing.index` | N/A | N/A — real invoicing, do not demo | none |
| DocuPerfect dashboard/templates/packs/clauses/compiler | `docuperfect.*` | cc4 | Y — verified working | Low |
| E-sign recipient presets/templates | `docuperfect.esign.recipient-presets.index`, `docuperfect.recipient-templates.index` | cc4 | Y (config) | Low |
| Field Groups / Import | `docuperfect.field-groups.index`, `docuperfect.import.index` | cc4 | unknown | unknown |
| Misfiled Documents (admin) | `admin.misfiled-documents.index` | cc4 | unknown | unknown |
| E-sign My Documents / packs | `docuperfect.esign.myDocuments`, etc. | cc4 | Y — verified, packs 16-21 real | Low |
| Communications Triage | `communications.triage.index` | cc5 | unknown — never checked, flag | unknown |
| WA Devices | `communications.wa-devices.index` | cc5 | unknown | unknown |
| Capture Review (WhatsApp consent) | `communications.capture.review` | cc5 | Y — 6 live rows | Low |
| My Capture | `communications.capture.my` | cc5 | unknown | unknown |
| Email Setup | `settings.email-setup.index` | cc5 | Y (config) | Low |
| Mailpit | external, `mail.demo1.corexos.co.za` | N/A | Y — live, password gap (see known gaps) | Medium — auth gap, not data |
| Agencies List | `agencies.index` | N/A | N/A — System Owner only | none, do not demo |
| Assistants (agent/admin) | `agent.assistants.index`, `admin.assistants.index` | cc1 | unknown | unknown |
| Knowledge Base | `admin.knowledge.index` | **UNOWNED** | unknown | flag |
| Ellie (AI assistant) | `ellie.index` + `admin.ellie.reference-sources.index` | **UNOWNED** | unknown — flagged twice now | **HIGH if empty chat — flag** |
| AI Usage | `admin.ai-usage.index` | N/A | internal metric | Low |
| Backups / System Health / System Updates | `admin.backups.index`, `admin.system-health.index`, `admin.system-updates.index` | N/A | N/A — infra, DO NOT show live | none if avoided |
| Soft Deletes Admin | `admin.soft-deletes.index` | N/A | infra tool | Low |
| Marketing Suppressions | `admin.marketing-suppressions.index` | cc5 | unknown | unknown |
| Importer | `admin.importer.index` | N/A | infra tool | Low |
| Integrations | `admin.integrations.index` | cc1 | unknown | unknown |
| Developer Users | `admin.developer-users.index` | N/A | infra, do not demo | none |
| Deposit Trust Interest | `admin.deposit-trust-interest.index` | cc3 | unknown | unknown |
| Training / Training Help | `training.index`, `training-help.index` | **UNOWNED** | unknown | flag |
| Whats New / Guided Tours | `corex.whats-new.index`, `corex.guided-tours.index` | **UNOWNED** | unknown, low stakes | Low |
| Onboarding Wizard | `onboarding.index` | cc1 | Y — FIXED, 100% complete | Low |
| Agency/Company Settings | `admin.company-settings` | cc1 | Y — FIXED, branding+logo | Low |

---

## ⚠️ Orphan list — nobody's slice, explicit

1. **Ellie (AI assistant)** — `ellie.index` + reference sources. Never checked by anyone tonight. A prominent, named feature with a blank chat/no sources reads as obviously fake if Johan clicks it live. **Highest-risk orphan.**
2. **Commercial Evaluations / Evaluation** — `commercial-evaluations.index`, `evaluation.index`. Confirmed empty (0 rows). MI-adjacent but never claimed by cc6 explicitly.
3. **Agent Daily / Daily Summary** — `agent.daily`, `agent.daily.summary`. Personal agent dashboard, unclaimed.
4. **Knowledge Base** — `admin.knowledge.index`. Unclaimed, unchecked.
5. **Training / Training Help** — `training.index`, `training-help.index`. Unclaimed, unchecked.
6. **Whats New / Guided Tours** — low stakes but genuinely nobody's.
7. **Payroll Leave module** (applications/balances/types/holidays/dashboard) — substantial real HR feature next to cc3's payroll work, not explicitly confirmed as theirs.

## Reassignments since last report
- Seller Outreach / Outreach Queue → **cc2** (was flagged unowned by cc6, Johan reassigned). Confirmed 0 rows, real seeder logic exists (`stage3_claimsAndPitches`) but isn't producing rows — root cause not yet found.

## Known non-data risks (not "no data" but still risk)
- Mailpit basic-auth password undocumented — access risk, not a data risk.
- 17 old e-sign packs (bulk-seeded pre-tonight) still unfiled — don't click those specifically, use the showcase ones.
