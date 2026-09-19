# CoreX OS — Commission & Revenue Share Engine
# Spec v1 — March 27, 2026

> Module: Agency Tracker → Commission Engine
> Purpose: The recruitment and retention engine for the virtual agency model
> Dependencies: Users, Deals, Agency Tracker, Contacts, Properties

---

## 1. Overview

The Commission & Revenue Share Engine is the financial backbone of the
virtual agency model. It calculates agent earnings, tracks commission
splits, manages caps, and distributes revenue share through a multi-tier
sponsorship tree. Every agent can log in and see exactly what they earn,
what they've paid in, how close they are to their cap, and what their
recruited agents are generating for them.

This is what agents talk about when they recruit other agents. Without
this dashboard, there is no virtual agency.

---

## 2. SA Real Estate Commission Context

- Agency charges seller 5-7.5% + VAT (15%) on property sale price
- Commission is negotiated per mandate and recorded on the deal
- Commission paid by transferring attorney from sale proceeds after registration
- Agent/agency split varies: typically 50/50 for new agents up to 90/10 for top producers
- For rentals: agency earns management fee (typically 10-12% of monthly rental + VAT)
- First month's rental often charged as letting fee
- No regulatory cap on commission amounts (PPRA doesn't regulate fees)

---

## 3. Commission Split Model (Configurable Per Agency)

### Default Model: Cap-Based Split

Similar to eXp but adapted for SA market and configurable per agency.

**Settings (agency-level, configurable in Settings):**

| Setting | Default | Description |
|---------|---------|-------------|
| `commission_split_agent` | 80 | Agent percentage (before cap) |
| `commission_split_agency` | 20 | Agency percentage (before cap) |
| `annual_cap` | 160000 | Rands — once agent pays this much to agency, they go to 100% |
| `post_cap_transaction_fee` | 2500 | Rands per transaction after capping |
| `post_cap_fee_cap` | 50000 | Max post-cap fees per year |
| `post_cap_reduced_fee` | 750 | Fee per transaction after post_cap_fee_cap reached |
| `monthly_platform_fee` | 850 | Rands per month |
| `mentor_extra_split` | 20 | Extra % taken on first 3 transactions (split between mentor and agency) |
| `mentor_transactions` | 3 | Number of mentored transactions |
| `risk_management_fee` | 400 | Per transaction |
| `risk_management_cap` | 5000 | Annual cap on risk management fees |
| `revenue_share_enabled` | true | Enable/disable revenue share |
| `revenue_share_pool_percent` | 50 | % of agency's portion that goes to revenue share pool |

### How It Works

```
Deal closes → R1,200,000 sale at 5% commission + VAT
  Total commission: R69,000 (incl VAT)
  Commission excl VAT: R60,000

  If agent has NOT capped:
    Agent gets: R60,000 × 80% = R48,000
    Agency gets: R60,000 × 20% = R12,000
    Agent's cap progress: += R12,000

  If agent HAS capped:
    Agent gets: R60,000 - R2,500 transaction fee - R400 risk fee = R57,100
    Agency gets: R2,900

  Revenue share pool (from agency portion):
    R12,000 × 50% = R6,000 → distributed through sponsorship tree
```

### Cap Anniversary
- Cap resets on agent's anniversary date (date they joined), not calendar year
- Agent can see: "You've paid R98,000 of R160,000 cap — R62,000 to go"
- After cap: "You're capped! 100% commission minus transaction fees"

---

## 4. Revenue Share Model (7-Tier)

### How Revenue Share Works

When an agent closes a deal and pays company dollar (the agency's portion
of the split), 50% of that company dollar goes into a revenue share pool.
That pool is distributed up the sponsorship tree — 7 levels deep.

**Revenue share is paid from REVENUE (before expenses), not profit.**
This is the key differentiator from KW's model and makes payouts predictable.

### Tier Structure

| Tier | Relationship | % of Company Dollar | Unlock Requirement |
|------|-------------|--------------------|--------------------|
| 1 | Agents you personally sponsored | 3.5% | Automatic |
| 2 | Agents sponsored by your Tier 1 | 4.0% | Automatic |
| 3 | Agents sponsored by your Tier 2 | 2.5% | Automatic |
| 4 | Tier 3's recruits | 1.5% | 5+ FLQAs |
| 5 | Tier 4's recruits | 1.0% | 10+ FLQAs |
| 6 | Tier 5's recruits | 0.5% | 15+ FLQAs |
| 7 | Tier 6's recruits | 0.25% | 20+ FLQAs |

**FLQA (First Line Qualifying Agent):** A Tier 1 agent who has closed
at least 2 transactions OR earned R50,000+ GCI in the last 6 months.

### Revenue Share Example

```
Johan sponsors Falan (Tier 1)
Falan sponsors Maggie (Tier 2 to Johan)
Maggie sponsors Retha (Tier 3 to Johan)

Falan closes a deal → company dollar = R12,000
  Revenue share pool: R12,000 × 50% = R6,000
  Johan (Tier 1 sponsor) gets: R6,000 × 3.5% = R210

Maggie closes a deal → company dollar = R10,000
  Revenue share pool: R10,000 × 50% = R5,000
  Johan (Tier 2) gets: R5,000 × 4.0% = R200
  Falan (Tier 1 to Maggie) gets: R5,000 × 3.5% = R175

Retha closes a deal → company dollar = R8,000
  Revenue share pool: R8,000 × 50% = R4,000
  Johan (Tier 3) gets: R4,000 × 2.5% = R100
  Falan (Tier 2 to Retha) gets: R4,000 × 4.0% = R160
  Maggie (Tier 1 to Retha) gets: R4,000 × 3.5% = R140
```

### Revenue Share Rules
- Revenue share is only paid when the producing agent is pre-cap (paying company dollar)
- Once an agent caps, minimal company dollar flows → minimal revenue share
- Post-cap transaction fees DO generate small revenue share
- Revenue share is calculated monthly and paid monthly
- An agent must maintain an active license and platform subscription to receive revenue share
- Revenue share continues even if the sponsoring agent is not actively selling

---

## 5. Database Schema

### New Tables

```sql
-- Agency commission settings
CREATE TABLE commission_settings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    agency_id BIGINT NOT NULL,
    commission_split_agent INT DEFAULT 80,
    commission_split_agency INT DEFAULT 20,
    annual_cap DECIMAL(12,2) DEFAULT 160000.00,
    post_cap_transaction_fee DECIMAL(10,2) DEFAULT 2500.00,
    post_cap_fee_cap DECIMAL(10,2) DEFAULT 50000.00,
    post_cap_reduced_fee DECIMAL(10,2) DEFAULT 750.00,
    monthly_platform_fee DECIMAL(10,2) DEFAULT 850.00,
    mentor_extra_split INT DEFAULT 20,
    mentor_transactions INT DEFAULT 3,
    risk_management_fee DECIMAL(10,2) DEFAULT 400.00,
    risk_management_cap DECIMAL(10,2) DEFAULT 5000.00,
    revenue_share_enabled BOOLEAN DEFAULT TRUE,
    revenue_share_pool_percent INT DEFAULT 50,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Agent sponsorship tree
CREATE TABLE agent_sponsorships (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    agent_user_id BIGINT NOT NULL,          -- the agent
    sponsor_user_id BIGINT NOT NULL,         -- who sponsored them
    sponsored_at DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(agent_user_id)                    -- an agent can only have one sponsor
);

-- Agent cap tracking (per anniversary year)
CREATE TABLE agent_cap_periods (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    agency_id BIGINT NOT NULL,
    period_start DATE NOT NULL,              -- anniversary start
    period_end DATE NOT NULL,                -- anniversary end
    cap_amount DECIMAL(12,2) NOT NULL,       -- the cap for this period
    company_dollar_paid DECIMAL(12,2) DEFAULT 0.00, -- total paid into agency
    is_capped BOOLEAN DEFAULT FALSE,
    capped_at TIMESTAMP NULL,
    post_cap_fees_paid DECIMAL(10,2) DEFAULT 0.00,
    risk_fees_paid DECIMAL(10,2) DEFAULT 0.00,
    transactions_count INT DEFAULT 0,
    transactions_mentored INT DEFAULT 0,
    gross_commission_income DECIMAL(14,2) DEFAULT 0.00,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX(user_id, period_start)
);

-- Commission ledger (every commission event)
CREATE TABLE commission_ledger (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,                 -- the earning agent
    agency_id BIGINT NOT NULL,
    cap_period_id BIGINT NOT NULL,
    deal_id BIGINT NULL,                     -- FK to deals if exists
    property_id BIGINT NULL,
    transaction_type ENUM('sale', 'rental_letting', 'rental_management', 'referral', 'other') NOT NULL,
    description VARCHAR(500) NOT NULL,
    gross_commission DECIMAL(12,2) NOT NULL,  -- total commission on the deal
    vat_amount DECIMAL(10,2) DEFAULT 0.00,
    commission_excl_vat DECIMAL(12,2) NOT NULL,
    agent_split_percent INT NOT NULL,
    agent_amount DECIMAL(12,2) NOT NULL,
    agency_amount DECIMAL(12,2) NOT NULL,
    transaction_fee DECIMAL(10,2) DEFAULT 0.00,
    risk_fee DECIMAL(10,2) DEFAULT 0.00,
    mentor_fee DECIMAL(10,2) DEFAULT 0.00,
    is_post_cap BOOLEAN DEFAULT FALSE,
    net_agent_amount DECIMAL(12,2) NOT NULL,  -- what agent actually takes home
    company_dollar DECIMAL(12,2) NOT NULL,    -- what agency actually keeps
    revenue_share_pool DECIMAL(12,2) DEFAULT 0.00, -- portion for revenue share
    status ENUM('pending', 'confirmed', 'paid', 'cancelled') DEFAULT 'pending',
    deal_date DATE NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX(user_id, status),
    INDEX(agency_id, created_at)
);

-- Revenue share distributions
CREATE TABLE revenue_share_ledger (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    commission_ledger_id BIGINT NOT NULL,     -- which deal generated this
    producing_agent_id BIGINT NOT NULL,       -- agent who closed the deal
    receiving_agent_id BIGINT NOT NULL,       -- agent receiving revenue share
    tier INT NOT NULL,                        -- 1-7
    company_dollar DECIMAL(12,2) NOT NULL,
    share_percent DECIMAL(5,2) NOT NULL,
    share_amount DECIMAL(10,2) NOT NULL,
    status ENUM('calculated', 'confirmed', 'paid') DEFAULT 'calculated',
    period_month DATE NOT NULL,              -- month this applies to
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX(receiving_agent_id, period_month),
    INDEX(producing_agent_id)
);

-- Mentor assignments
CREATE TABLE agent_mentors (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    mentee_user_id BIGINT NOT NULL,
    mentor_user_id BIGINT NOT NULL,
    assigned_at DATE NOT NULL,
    graduated_at DATE NULL,
    transactions_completed INT DEFAULT 0,
    transactions_required INT DEFAULT 3,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(mentee_user_id)
);
```

### Additions to Users Table

```sql
ALTER TABLE users ADD COLUMN anniversary_date DATE NULL;
ALTER TABLE users ADD COLUMN sponsored_by_user_id BIGINT NULL;
ALTER TABLE users ADD COLUMN agent_tier ENUM('standard', 'mentor', 'team_lead', 'icon') DEFAULT 'standard';
ALTER TABLE users ADD COLUMN is_mentor_eligible BOOLEAN DEFAULT FALSE;
```

---

## 6. Agent Dashboard — "My Earnings"

### Top Cards Row
```
[ This Month: R48,200 ]  [ This Year: R412,800 ]  [ Cap Progress: R98k / R160k ]  [ Rev Share: R3,400 ]
```

### Cap Progress Visual
- Horizontal progress bar showing cap status
- Green when approaching cap, gold when capped
- "R62,000 to go" or "CAPPED — 100% commission!"
- Days until anniversary reset

### Monthly Earnings Chart
- Bar chart showing last 12 months of earnings
- Stacked: Commission (blue) + Revenue Share (teal)
- Line overlay: cumulative year total

### Recent Transactions Table
| Date | Property | Type | Sale Price | Commission | My Split | Status |
|------|----------|------|-----------|------------|----------|--------|
| 15 Mar | 12 Ocean Dr | Sale | R1.2M | R69,000 | R48,000 | Paid |
| 2 Mar | 8 Beach Rd | Rental | R12,000/m | R13,800 | R11,040 | Pending |

### Revenue Share Section
- "Your Network" tree visualization (simple, max 3 levels visible)
- "Your Tier 1 Agents: 4" with names and their monthly production
- "Total Revenue Share This Month: R3,400"
- "Total Revenue Share This Year: R28,600"

### Revenue Share Calculator
- "What if" tool: "If you sponsor X agents who each close Y deals..."
- Shows projected monthly and annual revenue share
- This is the RECRUITMENT tool — agents show this to prospects

---

### 6.1 Layout — "Two income streams" (2026-09-14)

Andre asked for `/corex/my-earnings` to look better and, shown five drafted layouts on a design
canvas (cockpit strip / cap-first hero / statement ledger / two income streams / tabs), picked
**option 4 · Two income streams**. Canvas: https://claude.ai/code/artifact/7a9f9da2-a58b-4d0c-985f-e93bae2fde2f
(chosen option and the other four on page 1, the old stacked layout on page 2).

- **Frozen header** (`corex-page-banner`, `flex-shrink-0`): title, one-line helper, tour launcher.
  Root is `w-full lg:h-full flex flex-col`; the body is `lg:flex-1 lg:min-h-0` so at lg+ the page
  never scrolls — each column scrolls inside itself (`corex-brand-scroll`). Below lg the columns
  stack and the page scrolls normally.
- **Left column — My commission** (`minmax(0,1.55fr)`): a three-cell headline strip (This month,
  This year, Cap with bar + "R x to go" / Capped + "Resets in N days"); the post-cap fee row only
  when capped; the 12-month stacked Chart.js chart at 170px; the transactions table scrolling
  inside the card with a sticky thead; pagination pinned under it. The table shows Date,
  Description, Type, Net, Status — Gross / My Split / Fees are NOT dropped: they sit as a muted
  second line under the description, so no figure the old eight-column table showed is lost.
- **Right column — My revenue share**: This month ("From N tier 1 agents") and This year; the
  tier 1 agent list with a GCI bar relative to the top agent that month; a 12-bar "Rev share by
  month" strip built from the same `$monthlyData` the chart uses (pure Blade/CSS, no second chart).
- View-only. Every figure, query, route, permission and `data-tour="earn-dashboard-*"` anchor is
  unchanged. Deliberately NOT built from the mockup: nothing — the mockup added no actions.

## 7. Principal Dashboard — Commission Overview

### Top Cards
```
[ Agency GCI This Month: R420,000 ]  [ Company Dollar: R84,000 ]  [ Rev Share Paid: R28,000 ]  [ Net Agency: R56,000 ]
```

### Agent Performance Table
| Agent | GCI This Month | GCI YTD | Cap Status | Transactions | Rev Share Earned |
|-------|---------------|---------|------------|-------------|-----------------|
| Falan | R48,000 | R312,000 | 78% | 8 | R2,100 |
| Elize | R62,000 | R480,000 | CAPPED | 14 | R4,200 |

### Revenue Share Tree View
- Visual tree showing all agents and their sponsor relationships
- Expand/collapse per branch
- Show production per agent

### Monthly P&L Summary
- Total commission earned by agency
- Less: Agent splits paid
- Less: Revenue share distributed
- Less: Platform costs
- = Net agency revenue

---

## 8. Settings UI

Under Settings → Commission & Revenue Share:

**Commission Split Settings**
- Agent/Agency split percentage (slider or input)
- Annual cap amount
- Post-cap transaction fee
- Post-cap fee cap
- Reduced fee after post-cap cap

**Revenue Share Settings**
- Enable/disable toggle
- Pool percentage (% of company dollar into rev share)
- Tier percentages (configurable per tier, 1-7)
- FLQA requirements per tier

**Mentor Program Settings**
- Enable/disable
- Extra split percentage
- Number of mentored transactions
- Auto-assignment rules

**Monthly Fees**
- Platform fee amount
- Risk management fee
- Risk management cap

---

## 9. Models

### CommissionSetting
- belongsTo Agency
- Singleton per agency (firstOrCreate)

### AgentSponsorship
- belongsTo User (agent)
- belongsTo User (sponsor)
- Scopes: active()
- Methods: getTier1Agents(), getFullTree($maxDepth = 7), getFLQACount()

### AgentCapPeriod
- belongsTo User
- Methods: recordCompanyDollar($amount), checkCap(), getRemainingToCap()
- Scopes: current(), forUser($userId)

### CommissionLedger
- belongsTo User, Agency, AgentCapPeriod
- belongsTo Deal (optional)
- SoftDeletes
- Methods: calculateSplit(), generateRevenueShare()
- Scopes: pending(), confirmed(), paid(), thisMonth(), thisYear()

### RevenueShareLedger
- belongsTo CommissionLedger
- belongsTo User (producing), User (receiving)
- Scopes: forAgent($userId), forMonth($date), pending()

### AgentMentor
- belongsTo User (mentee), User (mentor)
- Methods: recordTransaction(), checkGraduation()

---

## 10. Controllers

### CommissionController
- dashboard() — agent's My Earnings page
- principalDashboard() — principal's commission overview
- index() — list all commission entries (admin)
- store() — record a new commission (from deal completion)
- show() — commission detail
- confirm() — confirm pending commission
- pay() — mark as paid

### RevenueShareController
- dashboard() — agent's revenue share view
- tree() — sponsorship tree visualization
- calculator() — "what if" calculator
- monthlyReport() — monthly rev share breakdown

### CommissionSettingsController
- edit() — settings form
- update() — save settings

---

## 11. Routes

```php
// Agent earnings
Route::get('my-earnings', [CommissionController::class, 'dashboard'])
    ->name('commission.dashboard');

// Revenue share
Route::get('revenue-share', [RevenueShareController::class, 'dashboard'])
    ->name('revenue-share.dashboard');
Route::get('revenue-share/tree', [RevenueShareController::class, 'tree'])
    ->name('revenue-share.tree');
Route::get('revenue-share/calculator', [RevenueShareController::class, 'calculator'])
    ->name('revenue-share.calculator');

// Principal commission management
Route::get('commission', [CommissionController::class, 'index'])
    ->name('commission.index');
Route::get('commission/principal', [CommissionController::class, 'principalDashboard'])
    ->name('commission.principal');
Route::post('commission', [CommissionController::class, 'store'])
    ->name('commission.store');
Route::post('commission/{entry}/confirm', [CommissionController::class, 'confirm'])
    ->name('commission.confirm');
Route::post('commission/{entry}/pay', [CommissionController::class, 'pay'])
    ->name('commission.pay');

// Settings
Route::get('settings/commission', [CommissionSettingsController::class, 'edit'])
    ->name('settings.commission');
Route::post('settings/commission', [CommissionSettingsController::class, 'update'])
    ->name('settings.commission.update');
```

### Navigation
- Sidebar: "My Earnings" under Agent section (all agents)
- Sidebar: "Revenue Share" under Agent section (all agents)
- Sidebar: "Commission Management" under Agency Tracker (admin/principal)
- Settings: "Commission & Revenue Share" tab

---

## 12. Build Phases

### Phase 1: Foundation (Day 1)
- Migration: all 6 tables + user column additions
- Models: all 6 with relationships and methods
- CommissionSetting: defaults seeded
- Settings UI: commission split configuration page
- Calculate split logic in CommissionLedger

### Phase 2: Agent Dashboard (Day 1-2)
- My Earnings page with top cards
- Cap progress bar
- Monthly chart (Recharts or Chart.js)
- Recent transactions table
- Commission ledger CRUD

### Phase 3: Revenue Share Engine (Day 2)
- Sponsorship tree (agent_sponsorships)
- Tier calculation logic
- Revenue share distribution from commission events
- Revenue share ledger
- Agent revenue share dashboard

### Phase 4: Principal Dashboard (Day 2-3)
- Agency overview cards
- Agent performance table
- Revenue share tree visualization
- Monthly P&L

### Phase 5: Calculator & Recruitment Tool (Day 3)
- "What if" revenue share calculator
- Shareable projection link for recruitment pitches
- Mentor program tracking

---

## 13. Integration Points

- **Deal completion** → auto-create CommissionLedger entry
- **Agency Tracker** → existing commission data feeds into new engine
- **Agent profile** → shows sponsor, cap status, mentor status
- **SARS IT3(a)** → commission data needed for tax submission (future)
- **Monthly reports** → auto-generate for accounting/Sage

---

## 14. Hard Rules

- All financial calculations use DECIMAL, never FLOAT
- VAT at 15% — always calculated and shown separately
- Soft deletes on all financial records
- Every financial event creates an audit trail entry
- Revenue share only paid from company dollar, never from agent portion
- No agent can sponsor themselves
- Sponsor relationship is permanent (can be transferred by admin only)
- Cap period follows anniversary date, not calendar year
- All amounts in ZAR (South African Rand)
