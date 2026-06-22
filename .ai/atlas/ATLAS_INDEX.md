# CoreX System Atlas — Master Index

> **Purpose.** A per-feature "how it works + what it affects" reference. Answers two
> questions for Johan, Andre, CC, and every future session:
> 1. **"How does feature X work?"** — entry points, flow, services, file:line.
> 2. **"If I change value Y, what breaks?"** — reads/writes, upstream/downstream blast radius.
>
> **This is documentation only.** No code, no migrations, no data writes are made by the
> atlas. It is a living, version-controlled backend reference that grows one feature at a time.
>
> Last updated: 2026-06-22 · Maintainer: Johan / CC

---

## How to use the Atlas

- **One file per feature** in `.ai/atlas/<feature>.md`, all using the same 9-section structure
  (WHAT IT DOES · ENTRY POINTS · THE FLOW · DATA IT READS · DATA IT WRITES · AFFECTS DOWNSTREAM ·
  AFFECTED BY UPSTREAM · AGENCY SETTINGS/CONFIG · KNOWN FRAGILITIES).
- **`CROSS_REFERENCE.md`** is the reverse lookup: pick a table/column/setting → see every feature
  that READS it and every feature that WRITES it. Update it whenever a feature doc is added.
- **Status tags:** `TODO` (not started) · `WIP` (partially documented) · `DONE` (full 9-section doc landed).
- **Cite file:line for every claim.** When you can't verify, mark it `(unverified)`.

---

## Feature register

Enumerated from `routes/web.php`, the controllers under `app/Http/Controllers/`, and the sidebar
nav (`resources/views/layouts/corex-sidebar.blade.php`). Grouped by pillar/area.

### Property & Market Intelligence
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Presentations (incl. CMA generation)** | [presentations.md](presentations.md) | **DONE** | Generator → snapshot → version → PDF; CMA compute engine; draft-vs-published freeze |
| **Properties (Agency Stock)** | [properties.md](properties.md) | **DONE** | `properties` table (the spine), address fragmentation, condition_level_id, title_type, status, write-backs |
| **Tracked Properties / Prospecting** | [prospecting-tracked-properties.md](prospecting-tracked-properties.md) | **DONE** | `tracked_properties`, Match-or-Create (5 strategies), source_chain, promoteToStock, three import islands (AT-81) |
| **Market Intelligence Centre (MIC)** | [market-intelligence.md](market-intelligence.md) | **DONE** | `market-intelligence.work`, Engine A/B, canonical scoring (AT-75), tile + %-slider, recompute jobs |
| Core Matches | core-matches.md | TODO | `corex.core-matches.index` surface (scoring engines documented in market-intelligence.md) |
| **CMA Report Import** | [cma-report-import.md](cma-report-import.md) | **DONE** | `market-intelligence.reports.*`, parse → market_reports → comp_rows; two pipelines; parsed-benchmark bypass (AT-82) |
| Map | map.md | TODO | `corex.map.index` |
| Commercial Evaluations | commercial-evaluations.md | TODO | `commercial-evaluations.index`, `evaluation.index` |

### Contact & Buyer
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Contacts** | [contacts.md](contacts.md) | **DONE** | `Contact` pillar, structured address (AT-60), comms tiles (AT-59), wishlist, derived comm status, ClientUser distinction |
| **Buyer Pipeline** | [buyer-pipeline.md](buyer-pipeline.md) | **DONE** | `command-center.buyers.pipeline`, countable gate (AT-71), auto-land (AT-72), staleness + manual-protection (AT-74), nav AT-76 |
| Portal Leads | portal-leads.md | TODO | `corex.portal-leads.index` |

### Deal & Commission
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Deal Register / Commission** | [deals-commission.md](deals-commission.md) | **DONE** | V1 (live money path) + V2 pipeline + orphaned cap/rev-share engine; settlement, PAYE; built-vs-backlog |
| Revenue Share / Deposit Interest | finance-tools.md | TODO | `revenue-share.calculator`, `deposit-interest-calculator.*` |

### Documents & E-Sign
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| DocuPerfect (templates/packs/clauses) | docuperfect.md | TODO | `docuperfect.*` |
| E-Sign (signing pipeline) | esign.md | TODO | `docuperfect.esign.*`; P0 signing-view invariant; pipeline gate |
| Document Library / Filing Register | documents.md | TODO | `documents.library.index`, `filing-register.index` |

### Compliance
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Compliance (FICA/POPIA/PPRA)** | [compliance.md](compliance.md) | **DONE** | FICA (24-mo validity), POPIA/CPA opt-in/out engine (AT-45→50), Whistleblower, RMCP/screening/policy, IO/CO registers, retention |
| Communications Capture / Archive | communications.md | TODO | `communications.*`, `compliance.comm-*`, WA capture |

### Calendar & Command Center
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Calendar | calendar.md | TODO | `command-center.calendar`, visibility resolver |
| Command Center (today/tasks/reporting) | command-center.md | TODO | `command-center.*` |
| Performance / Targets | performance.md | TODO | `admin.performance`, `admin.targets`, `command-center.performance` |

### HR / Payroll
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Leave | leave.md | TODO | `payroll.leave.*` |
| Payroll (runs/employees/types) | payroll.md | TODO | `payroll.*` |
| Staff Take-On / Onboarding | onboarding.md | TODO | `staff-take-on.index`, `onboarding.index`, agent QR |

### Rentals
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Rentals / Leases | rentals.md | TODO | `rental.*`, `rentals.index`, active/expired leases |

### Syndication & Advertising
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Syndication / Ad Manager | syndication.md | TODO | **DOC-ONLY — Andre's code; do not touch.** `tools.ad-manager`, `admin.listings.*` |
| Agency Public API | public-api.md | TODO | `.ai/specs/agency-public-api.md` |

### AI & Tools
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Ellie (AI assistant) | ellie.md | TODO | `ellie.index`, AI cost ledger |
| Tools (CMA tool, calculators, PDF suite, image converter) | tools.md | TODO | `tools.*`, `calculators.index` |
| TV Display | tv-display.md | TODO | `admin.tv-messages`, `bm.tv-messages` |
| Training / Help / Knowledge | training-help.md | TODO | `training.*`, `training-help.index`, `admin.knowledge.index`, help-tour engine |

### Platform / Admin
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| Multi-tenancy / Agency isolation | multi-tenancy.md | TODO | AgencyScope, branch switcher, View-As vs Switch-User |
| Admin / Settings / API catalog | admin.md | TODO | `admin.*`, `corex.settings`, `admin.api.catalog` |
| Domain Events (cross-pillar) | domain-events.md | TODO | `domain_event_log`, event/listener catalogue (architectural spine) |

---

## Progress

- **DONE:** 9 — Presentations, Properties, Prospecting/Tracked Properties, Market Intelligence Centre,
  CMA Report Import, Contacts, Buyer Pipeline, Deal Register/Commission, Compliance.
  This closes BOTH halves of the MIC/matching loop (Property side + Contact/Buyer side) and the
  Deal + Compliance clusters that consume them.
- **Next queued:** DocuPerfect / E-Sign (signing pipeline — FICA-gated, consumes Contacts as recipients) →
  Calendar / Command Center → Communications Capture/Archive → Payroll/Leave. Rationale: E-Sign is the
  next-densest cross-reference (FICA gate, contact parties, deal-document linkage) and has a standing P0
  signing-view invariant worth documenting precisely.
