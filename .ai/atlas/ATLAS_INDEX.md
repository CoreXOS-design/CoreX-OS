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

## Atlas status — feature-complete

The atlas covers **18 features + the cross-cutting platform foundations** — the four pillars (Property,
Contact, Deal, Agent), both halves of the MIC/matching loop, the e-Sign/document/comms/calendar/HR layer,
rentals, syndication (doc-only, Andre's domain), and the tenancy/event/audit spine. The core
"how does X work / what breaks if I change Y" coverage is done.

**[FRAGILITY_REGISTER.md](FRAGILITY_REGISTER.md) is the "what to harden" backlog** — every §9 fragility from
all 18 docs, consolidated and prioritised: **9 × P0 · 38 × P1 · 46 × P2**. None of the P0s gate the Thursday
portal go-live (per AT-81, the import/MIC layer is isolated from the syndication publish path).

**Minor surfaces still TODO** (thin — mostly sub-features of DONE docs or low-traffic tools; addable on
demand): Core Matches *surface*, Portal Leads, finance calculators (Revenue Share / Deposit Interest), Map,
Commercial Evaluations, Performance/Targets, Staff Take-On / Onboarding, misc Tools (CMA tool, calculators,
PDF suite, image converter) / TV Display / Training-Help, and the Agency Public API.

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
| **E-Sign / DocuPerfect** | [esign-docuperfect.md](esign-docuperfect.md) | **DONE** | 6-step wizard, P0 signing-view invariant, FICA gate, pipeline moat, pack filing; V2→V3 reconciliation |
| **Document Library / Filing Register** | [document-library-filing.md](document-library-filing.md) | **DONE** | unified `documents` auto-link + Library + metadata-only Filing Register; the two pivot lineages; storage/recovery |

### Compliance
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Compliance (FICA/POPIA/PPRA)** | [compliance.md](compliance.md) | **DONE** | FICA (24-mo validity), POPIA/CPA opt-in/out engine (AT-45→50), Whistleblower, RMCP/screening/policy, IO/CO registers, retention |
| **Communications Capture / Archive** | [communications-capture.md](communications-capture.md) | **DONE** | **LIVE** WA IndexedDB ingest (AT-44), email IMAP, device-token gate, provisional/reconcile, AT-59 tiles, consent send-gate |

### Calendar & Command Center
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Calendar / Command Center** | [calendar-command-center.md](calendar-command-center.md) | **DONE** | ~47 event-class system, Threshold/Visibility/Notification resolvers, viewing-feedback arc (AT-66/69/70), 8 sources, View-As gotcha |
| Performance / Targets | performance.md | TODO | `admin.performance`, `admin.targets`, `command-center.performance` |

### HR / Payroll
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Payroll + Leave** | [payroll-leave.md](payroll-leave.md) | **DONE** | BCEA accrual engine, take-on wizard, 4 leave reports, SARS PAYE/UIF/SDL engine; confirms the PAYE duality |
| Staff Take-On / Onboarding | onboarding.md | TODO | `staff-take-on.index`, `onboarding.index`, agent QR |

### Rentals
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Rentals / Leases** | [rentals-leases.md](rentals-leases.md) | **DONE** | TWO disconnected systems (commission `Rental` + eSign `LeaseRecord`); pillar-island + no agency_id; built-vs-roadmap |

### Syndication & Advertising
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Syndication / Ad Manager** | [syndication-overview.md](syndication-overview.md) | **DONE** | **DOC-ONLY — Andre's domain.** P24 ExDev REST + PP SOAP, trigger/feed/payload, location lookups, AT-81 taxonomy |
| Agency Public API | public-api.md | TODO | `.ai/specs/agency-public-api.md` |

### AI & Tools
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Ellie / AI + AI Cost Ledger** | [ai-tools-cost-ledger.md](ai-tools-cost-ledger.md) | **DONE** | Ellie chat/voice, all AI call sites, `ai_usage_events` ledger + per-agency budget cap; unmetered gaps |
| Tools (CMA tool, calculators, PDF suite, image converter) | tools.md | TODO | `tools.*`, `calculators.index` |
| TV Display | tv-display.md | TODO | `admin.tv-messages`, `bm.tv-messages` |
| Training / Help / Knowledge | training-help.md | TODO | `training.*`, `training-help.index`, `admin.knowledge.index`, help-tour engine |

### Platform / Admin
| Feature | Doc | Status | Notes |
|---------|-----|--------|-------|
| **Platform Foundations** (multi-tenancy · branch · domain events · soft-delete · audit) | [platform-multitenancy.md](platform-multitenancy.md) | **DONE** | AgencyScope/BranchScope, `effectiveAgencyId`, domain-event spine, soft-delete registry, audit observer; the agency_id-null class |
| Admin / Settings / API catalog | admin.md | TODO | `admin.*`, `corex.settings`, `admin.api.catalog` (largely covered by platform-multitenancy.md) |

---

## Progress

- **DONE: 18** — Presentations, Properties, Prospecting/Tracked Properties, Market Intelligence Centre,
  CMA Report Import, Contacts, Buyer Pipeline, Deal Register/Commission, Compliance, E-Sign/DocuPerfect,
  Calendar/Command Center, Communications Capture, Payroll+Leave, Rentals/Leases, Document Library/Filing,
  Ellie/AI+Cost Ledger, Syndication (doc-only), Platform Foundations.
- **The atlas now covers every major operational pillar and the cross-cutting platform foundations.**
  The four pillars (Property, Contact, Deal, Agent), both halves of the MIC/matching loop, the document/
  comms/calendar/HR layer, syndication, and the tenancy/event/audit spine are all documented.

### Remaining minor TODO surfaces (thin — covered indirectly or low-traffic)
These are small surfaces, mostly sub-features of DONE docs or low-complexity tools; documented here as
honest gaps rather than left implied-complete:
- **Core Matches** — the `corex.core-matches.index` *surface* (scoring engines fully in `market-intelligence.md`).
- **Portal Leads** — `corex.portal-leads.index` (lead capture → Contact).
- **Finance tools** — Revenue Share / Deposit Interest calculators (`revenue-share.calculator`, `deposit-interest-calculator.*`).
- **Map** — `corex.map.index`; **Commercial Evaluations** — `commercial-evaluations.index`.
- **Performance / Targets** — `admin.performance`, `admin.targets`, activity definitions.
- **Staff Take-On / Onboarding** — `staff-take-on.index`, agent QR (lease/payroll take-on covered in payroll-leave.md).
- **Tools** (CMA tool, calculators, PDF suite, image converter), **TV Display**, **Training/Help/Knowledge** (help-tour engine).
- **Agency Public API** (`.ai/specs/agency-public-api.md`); **Admin / Settings / API catalog** (foundations in platform-multitenancy.md).

These can be added on demand; the core "how does X work / what breaks if I change Y" coverage is complete.
