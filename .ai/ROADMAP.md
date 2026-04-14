# CoreX OS — Roadmap

---

## Phase 1 — Consolidation Sprint (Current)

Andre works this list top-down. No new features until every item below is resolved.

### Consolidation Checklist

- [ ] Migrate all PDF generation from DomPDF to Puppeteer
- [ ] Settings audit — replace every hardcoded dropdown system-wide
- [ ] Migrate existing PDF overlay templates to web documents (Blade/HTML)
- [ ] Property ↔ Contact owner link (bidirectional — property knows owner, contact shows owned properties)
- [ ] Deal record — all parties (buyer/seller or landlord/tenant) + property on one unified record
- [ ] DocuPerfect write-back — completed documents push data back to all four pillars
- [ ] FICA flag visible on both Contact record and Deal record
- [ ] PPRA/FFC status field on Agent (User) profile
- [ ] POPIA consent block on Contact record
- [ ] Agency Tracker ↔ Deal — commission linked to deal record, not standalone
- [ ] Email signature injection on all DocuPerfect outgoing emails (Outlook-style per-user)
- [ ] Soft delete audit — verify `SoftDeletes` trait on every model, add where missing
- [ ] Navigation audit — every page reachable from sidebar or contextual button, no orphaned routes
- [ ] Role-based access control audit — consistent permissions across all modules

---

## Phase 2 — Specced, Build After Phase 1

These items have been identified and scoped. Each requires a full spec in `/.ai/specs/` before Andre begins.

| Feature | Spec Status | Notes |
|---------|-------------|-------|
| Ellie: Document Legal Review | Needs spec | User highlights clause → Ellie references SA legislation and advises |
| Ellie: Pillar Awareness | Needs spec | Ellie queries live Property/Contact/Deal/Agent data |
| Tenant Pre-Approval AI | Needs spec | AI-scored pre-screening before lease is offered |
| Sales Pipeline View | Needs spec | Visual pipeline: mandate → OTP → registered |
| Rental Pipeline View | Needs spec | Visual pipeline: mandate → lease → active → renewal |
| Inspection Reports Module | Needs spec | Ingoing/outgoing inspection with photos, signatures |
| Deferred Signing | Needs spec | Park a document on a property, sign later |
| Pack Chaining | Needs spec | Multi-document flows (e.g. OTP + FICA + disclosure in one pack) |
| P24 Image Scraping | Needs spec | Pull listing photos from P24 into presentation module |
| Presentation Listing Photos | Needs spec | Display P24/own photos inside presentation |
| Clickable P24 Refs on Price Changes | Needs spec | Price change log links to live P24 listing |
| Virtual Agent API Integration | Needs spec | Confirmed API access — integration TBD |
| TVA API: CMA System | Needs spec | Comparative market analysis from TVA data |
| TVA API: Data Flywheel | Needs spec | Cache model for TVA property + owner data |
| Custom Accounting Module | Long-term | Replace Sage — full trust accounting, owner statements |

---

## Built — Stable Modules

| Module | Status | Notes |
|--------|--------|-------|
| Listings | ✅ Live | Multi-agent, P24 email parser, suburb extraction |
| Contacts | ✅ Live | Basic — pillar linkage consolidation pending |
| Agency Tracker | ✅ Live | Commission calculator, BM worksheet, branch dashboards |
| DocuPerfect | ✅ Live | Linked fields, four data buckets, write-back (consolidation pending) |
| E-Signature (Electronic) | ✅ Live | Alpine.js canvas capture, sequential signing, identity gates |
| E-Signature (Wet Ink) | ✅ Live | Upload scan, flatten into document, subsequent signers see scan |
| TV Display | ✅ Live | Live performance dashboard for office screens |
| Ellie (Knowledge Base) | ✅ Live | Vector embeddings, hybrid cosine+structural scoring |
| Ellie (Web Search) | ✅ Live | Routing fixed — KB questions no longer mis-routed to web |
| Role Manager | ✅ Live | Andre's implementation |
| Core Matches | ✅ Live | Andre's implementation, `contact_matches` table |
| Presentations | ✅ Live (partial) | Puppeteer rendering — photo display + P24 data pending |
| Multi-Tenancy Isolation | ✅ Live (2026-04-14) | `BelongsToAgency` trait + `AgencyScope` — see `.ai/specs/multi-tenancy.md` |
| Company Settings (standalone) | ✅ Live (2026-04-14) | Moved out of tabbed settings into `/admin/company-settings` — mirrors Branch Assignments pattern |
| Agency Delete | ✅ Live (2026-04-14) | Hard delete — `slug` is uniquely indexed, so soft-delete would permanently reserve the slug. Guarded: refuses if agency has branches/users/properties/contacts/deals/presentations or is the last agency. Documented exception to the "no hard deletes" rule. |
| System Owner separation | ✅ Live (2026-04-14) | Owner-role users detached from agencies (NULL agency_id/branch_id) + `User::scopeAgencyMembers()` hides them from every agent/user picker + `PropertyObserver::saving()` blocks assigning them as property agents + sidebar "Platform Admin" section. Spec: `.ai/specs/multi-tenancy.md`. |

---

## Decision Log

Architectural decisions made and locked. These are not up for debate during build — raise formally if circumstances change.

| # | Decision | Date | Rationale |
|---|----------|------|-----------|
| 1 | Web Documents only — PDF overlay retired | 2025 | DomPDF/image overlay creates layout drift on legal documents |
| 2 | Puppeteer replaces DomPDF for all PDF generation | 2025 | Real Chrome render = fidelity guarantee |
| 3 | No hardcoding of any dropdown/type/status | 2025 | Multi-agency readiness + agency configurability |
| 4 | Soft deletes only — no hard deletes | 2025 | Data recovery, audit trail, compliance |
| 5 | Four Pillars as core data model | 2025 | Every module connects to Property/Contact/Deal/Agent |
| 6 | Flows as core operating model | 2025 | Real estate is a lifecycle — CoreX moves with it |
| 7 | Ellie advises, humans decide | 2025 | No automated document or data changes by AI |
| 8 | Settings architecture — one menu, module-grouped | 2025 | Agencies configure their own terminology |
| 9 | database.sqlite in .gitignore | 2025 | Constant merge conflicts, irrelevant in MySQL environment |
| 10 | `.ai/` folder synced on main only | 2025 | Specs are the source of truth — not per-branch |
| 11 | PPRA always — never EAAB | 2021 | EAAB dissolved, replaced by PPRA under PPA 22 of 2019 |
| 12 | Investigation before any prompt | 2025 | No guessing at file paths or structure |
