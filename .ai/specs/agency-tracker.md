# Spec: Agency Tracker

**Status:** Live — Deal linkage consolidation pending

---

## What Agency Tracker Does

Agency Tracker is the financial performance engine of CoreX. It tracks all sales and rental transactions, calculates agent commissions, manages branch performance, and provides the principal with real-time financial visibility across the agency.

It is the replacement for the manual spreadsheet-based tracking currently done in Sage.

---

## Core Features

### Commission Calculation
- Configurable commission percentages per deal type
- VAT-exclusive commission calculation (commission rate applied before VAT)
- Joint agent commission splits (configurable per deal)
- BM (Branch Manager) worksheet with override capability
- Handles sole mandate and open mandate scenarios

### Branch Performance Dashboard
- Per-branch breakdown of sales value, commission earned, deals closed
- Agent performance per branch
- Month/quarter/year filters

### Agent Performance
- Individual agent deal count, value, commission
- Comparative view across agents
- Leaderboard data (feeds TV Display module)

---

## Consolidation Items (Phase 1)

- [ ] Commission record linked to Deal record — currently standalone
- [ ] Deal record must exist before commission is calculated (not possible to calculate commission without a linked deal)
- [ ] FICA flag visible on the deal being tracked

---

## Known Fixed Issues (Reference)

Historical bugs that have been resolved — documented to avoid regression:

- Sales value split calculation bug — fixed
- BM worksheet override not persisting — fixed  
- VAT-exclusive percentage calculation applied incorrectly — fixed
- Branch performance dashboard double-counting joint agent deals — fixed

---

## Access and agency scoping (2026-10-07)

**Listing-stock routes** (`admin.listings.stock`, `admin.listings.agents`, `admin.listings.agents.show`, `admin.listings.stock.agents.edit|update`, `bm.listings`):
- Gate: `view_branch_stats` (the manager permission the sidebar's Branch group already sits behind; agents do not hold it) on top of `access_listing_stock` and `view_listings`. Note: on HFC's real grants agents also hold `access_listing_stock`, a listings scope of `all` and `properties.edit`, so only `view_branch_stats` separates them from a manager.
- Rows are narrowed to the viewer's own / branch / agency breadth (`ListingStock::scopeVisibleTo`, data scope `listings`); another agency's rows and users 404 (global agency scope on route binding). There is no export route.
- Reassigning listing agents additionally needs `properties.edit` and the Properties module's gate for picking another agent: a `properties` data scope of `all` or `branch` (assistants never). Every assignee must belong to the listing's agency (422 otherwise).

**Agency scope on performance figures:** every caller of `CompanyPerformanceService::getPeriodRollup / getBranchRollup / getAgentRollup` must pass the agency id — the service's raw queries filter by agency only when handed one. The agent dashboard's Company and Branch tiles pass the viewer's agency (and stay empty when no agency resolves).
