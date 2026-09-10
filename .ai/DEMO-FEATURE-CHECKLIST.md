# Master Feature Screen Checklist — "Does this read as LIVE?"

Built 2026-09-03, ~6 hours to the 10:00 webinar. Companion to
`.ai/CONFIG-SWEEP-CHECKLIST.md` (settings screens) — this covers every
significant FEATURE screen, enumerated from `resources/views/layouts/
corex-sidebar.blade.php` (~200 distinct routes) and `routes/web.php`, not
from memory. Rough and fast per instruction — sent now, will firm up as I
work my own slice.

**The test for every row:** does this look like a real agency's months of
work, or a freshly-seeded shell? Configured-but-empty still reads as dead.

**🔴 CRITICAL FINDING:** the dashboard Johan will see FIRST after login is
**NOT** a page called "dashboard" — `/dashboard` redirects to `corex.dashboard`
→ `/corex` → `CommandCenter\DashboardController@today`. That's **Command
Center → Today**, and it's in **my slice (cc6)**. I'm checking it right now,
first thing, per your explicit flag ("if it shows zeroes... nothing else
matters").

**Lane key:** cc1 = agency/branches/users/branding · cc2 =
properties/listings/portals/marketing · cc3 = compliance/HR/payroll/suppliers
· cc4 = e-sign/documents/filing · cc5 = communications/notifications · cc6 =
deals/calendar/dashboard/MI (this lane).

---

## 🏠 Landing / cross-cutting (check FIRST — everyone sees these)

| Screen | URL | Lane | Note |
|---|---|---|---|
| **Command Center Today (THE dashboard)** | `/corex` (`corex.dashboard`) | **cc6** | Checking now — highest priority in this doc |
| Command Center → Tasks | `command-center.tasks` | cc6 | |
| Command Center → Performance | `command-center.performance` | cc6 | |
| Command Center → Reporting (agency/agent/branch) | `command-center.reporting.*` | cc6 | |
| Command Center → Lost Deals | `command-center.lost-deals` | cc6 | |
| Command Center → Buyers Pipeline | `command-center.buyers.pipeline` | cc6 | |
| BM My Dashboard | `bm.my.dashboard` | cc6 | branch-manager landing, same family |
| BM Daily Summary / Performance / Listings / Worksheet | `bm.*` | cc6 | |
| Agent Daily / Daily Summary | `agent.daily*` | cc1 or cc6? — agent-level personal dashboard, flag |
| Worksheet | `worksheet.index` | cc6 (agency-tracker family) | |
| Onboarding wizard | `onboarding.index` | cc1 | already FIXED per config checklist |
| Whats New | `corex.whats-new.index` | **UNOWNED** | low stakes, skip unless time |
| Guided Tours | `corex.guided-tours.index` | **UNOWNED** | low stakes |
| Ellie (AI assistant) | `ellie.index` | **UNOWNED** — could look very dead if untouched | flag |
| Training / Training Help | `training.index`, `training-help.index` | **UNOWNED** | flag |

---

## Deals / DR2 / Commission — cc6 (mine, working now)

| Screen | URL | Note |
|---|---|---|
| DR2 pipeline (list/timeline) | `deals-dr2.pipeline.*` | Showcase deal 111 in progress — see separate report |
| DR2 register | `deals-dr2.index` | |
| DR2 unfiled emails | `deals-dr2.unfiled-emails.index` | Real feature (AT-231-adjacent) — check has content |
| Deal-link review (admin) | `corex.admin.deal-link-review.index` | |
| DR2 pipeline setup | `deals-v2.pipeline.index` | config, already in CONFIG-SWEEP-CHECKLIST #32 |
| Supplier directory | `deals-v2.suppliers.index` | cc3's data, already live |
| Legacy deals-v2 register | `deals-v2.index` | soft-retired, redirects — skip |
| Agent's own deals | `agent.deals.index` | |
| Commission (index/dashboard/principal) | `commission.*` | |
| Deposit interest calculator | `deposit-interest-calculator.index` | |
| Comms-suspense (attorney emails) | `corex.comms-suspense.index` | Verified last night, 7 live rows |

## Calendar / Command Center — cc6

| Screen | URL | Note |
|---|---|---|
| Calendar | `command-center.calendar` | Verified working, 1455 events |
| Calendar invitations | `command-center.calendar.invitations` | Check has content, not just empty inbox |
| Viewing packs | `corex.viewing-packs.index` | Verified last night, 3 live |
| Feedback reports | `command-center.feedback-reports` | Check populated |
| Duplicate cleanup (admin) | `command-center.admin.duplicate-cleanup` | admin tool, low priority |

## Market Intelligence / Prospecting — cc6

| Screen | URL | Note |
|---|---|---|
| MI Work (= login landing per DEMO_DATA.md) | `market-intelligence.work` | Verified working |
| Map | `corex.map.index` | Verified working |
| Suburb report | `market-intelligence.suburb-report.index` | Verified working |
| Core matches | `corex.core-matches.index` | Check has real matches, not zero |
| Outreach (canvassing/queue/summary) | `corex.outreach-*` | Check populated |
| Portal leads | `corex.portal-leads.index` | Check has real leads (P24/PP) |
| Buyers report | `buyers-report.index` | Check populated |
| Commercial evaluations | `commercial-evaluations.index` | **Never touched this session — likely empty, flag** |
| Evaluation | `evaluation.index` | Same concern |

---

## Properties / Listings / Marketing — cc2

| Screen | URL | Note |
|---|---|---|
| Properties list/detail | `corex.properties.index` | Verified last night, rich |
| Rentals | `rentals.index`, `rental.dashboard` | New today per index — verify not a shell |
| Presentations (index/analytics/outcomes/refresh) | `presentations.*`, `corex.presentations.*` | Verified renders |
| Filing register | `filing-register.index` | Mentioned in cc1's brief last night — verify populated |
| Documents library / shared drive | `documents.library.index`, `documents.shared-drive.index` | Check not empty |
| Tools: PDF splitter, image converter | `tools.*` | Utility screens, low priority for "reads as live" |

## Compliance / HR / Payroll / Suppliers — cc3

| Screen | URL | Note |
|---|---|---|
| FICA | `compliance.fica.index` | |
| Compliance policy (index/dashboard) | `compliance.policy.*` | FIXED per config checklist (1 policy) |
| RMCP (index/dashboard) | `compliance.rmcp.*` | **Never checked — flag** |
| Screening dashboard | `compliance.screening.dashboard.index` | 11 rows confirmed last night |
| Whistleblow | `compliance.whistleblow.index` | |
| Comm archive/flags/mailboxes | `compliance.comm-*` | |
| Document types | `compliance.document-types.index` | FIXED per config checklist |
| Verification | `compliance.verification.index` | **Never checked — flag** |
| Seller info | `compliance.seller-info.index` | **Never checked — flag** |
| Payroll employees/runs | `payroll.employees.index`, `payroll.runs.index` | Verified last night — July/Aug finalised |
| Payroll leave (applications/balances/types/holidays/dashboard) | `payroll.leave.*` | **Never checked — flag, real HR feature** |
| Staff take-on | `staff-take-on.index` | **Never checked** |
| Billing | `billing.index`, `admin.billing.index` | Likely N/A for demo (real invoicing) |

## E-sign / Documents / Filing — cc4

| Screen | URL | Note |
|---|---|---|
| DocuPerfect dashboard/templates/packs/clauses/compiler | `docuperfect.*` | Verified working last night |
| Recipient presets/templates | `docuperfect.esign.recipient-presets.index`, `docuperfect.recipient-templates.index` | config, likely fine |
| Field groups / import | `docuperfect.field-groups.index`, `docuperfect.import.index` | **Never checked** |
| Misfiled documents (admin) | `admin.misfiled-documents.index` | admin tool |

## Communications / Notifications — cc5

| Screen | URL | Note |
|---|---|---|
| Communications triage | `communications.triage.index` | **Never checked — flag** |
| WA devices | `communications.wa-devices.index` | **Never checked** |
| Capture review (WhatsApp consent) | `communications.capture.review` | Verified last night, 6 live rows |
| My capture | `communications.capture.my` | **Never checked** |
| Email setup | `settings.email-setup.index` | config |

---

## Admin / platform (mostly cc1, some genuinely N/A for a demo)

| Screen | URL | Note |
|---|---|---|
| Agencies list | `agencies.index` | System-Owner only, likely N/A |
| Assistants (agent/admin) | `agent.assistants.index`, `admin.assistants.index` | **Never checked** |
| Knowledge base | `admin.knowledge.index` | **Never checked** |
| Ellie reference sources | `admin.ellie.reference-sources.index` | tied to Ellie, see above |
| AI usage | `admin.ai-usage.index` | internal metric, low priority |
| Backups / system health / system updates | `admin.backups.index`, `admin.system-health.index`, `admin.system-updates.index` | infra, **N/A for demo — do not show these live** |
| Soft deletes admin | `admin.soft-deletes.index` | infra tool |
| Marketing suppressions | `admin.marketing-suppressions.index` | **Never checked** |
| Importer | `admin.importer.index` | infra tool |
| Integrations | `admin.integrations.index` | config-ish, likely fine to skip |
| Developer users | `admin.developer-users.index` | infra, N/A |
| Deposit trust interest | `admin.deposit-trust-interest.index` | **Never checked** |

---

## ⚠️ Falls in NOBODY's slice — flagging per your instruction

1. **Ellie (AI assistant)** — `ellie.index` + `admin.ellie.reference-sources.index`. A named, prominent feature. If it's a blank chat with no history/sources it reads as obviously fake. Doesn't map to any of the 6 slices.
2. **Training / Training Help** — `training.index`, `training-help.index`. Same concern, no owner.
3. **Whats New / Guided Tours** — `corex.whats-new.index`, `corex.guided-tours.index`. Lower stakes (nobody demos these deliberately) but genuinely unowned.
4. **Agent Daily / Daily Summary** — `agent.daily`, `agent.daily.summary`. Personal agent dashboard — could be cc1 (users) or cc6 (dashboard family); nobody has explicitly claimed it.
5. **Payroll Leave module** (applications/balances/types/holidays/dashboard) — `payroll.leave.*`. This is a real, substantial HR feature sitting right next to cc3's payroll work but not explicitly named in cc3's brief — likely theirs by proximity but not confirmed.
6. **Commercial evaluations / Evaluation** — `commercial-evaluations.index`, `evaluation.index`. Sound MI-adjacent (cc6) but never touched by me or anyone tonight — genuinely unverified, could be empty.

---

## Running status

- ~90 distinct screens enumerated across all lanes (rough count from the sidebar's `.index` routes plus named dashboards).
- Most of cc6's own slice (deals, calendar, MI, map) verified working in prior sessions tonight — re-verifying now for "reads as live," not just "renders."
- The dashboard finding above is the single most important thing in this document — checking it first, next message.
- This list is NOT exhaustive (~200 total sidebar routes exist; I prioritized the ones a real visitor would actually click). Will add more as I find them.
