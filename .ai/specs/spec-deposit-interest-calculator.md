# Deposit Interest Calculator — CoreX OS Spec
> Single source of truth for the Deposit Interest Calculator module
> Last updated: 2026-03-30

## Overview
A freestanding tool for BM and Admin users to calculate interest earned on tenant rental deposits held in the agency's trust investment account. The agency pools all deposits into a single trust account; each month the bank pays interest on the total pool; each deposit earns a proportional share.

## Database

### `deposit_trust_interest` table
Stores monthly trust-level data from bank statements.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| interest_date | date | unique, indexed |
| total_invested_funds | decimal(14,2) | Trust account balance on this date |
| interest_earned | decimal(10,2) | Bank interest paid on this date |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | soft delete |

Seeded with 89 months of historical data (Oct 2018 – Feb 2026).
Admin adds new rows monthly as bank statements arrive.

## Calculation Algorithm

**Proportional compound interest:**

1. `running_balance = deposit_amount`
2. For each trust interest record between invest_date and refund_date (chronological):
   - Apply any topups dated on or before this interest date
   - `share_pct = running_balance / total_invested_funds`
   - `interest_share = interest_earned × share_pct`
   - `running_balance += interest_share`
3. `total_interest = running_balance - deposit_amount - sum(topups)`
4. `grand_total = running_balance`

Uses bcmath for precision. Scale 10 for intermediates, 2 for display.

## User Inputs
- Property name (text, freestanding — not linked to CoreX property records yet)
- Deposit amount (R)
- Date invested
- Date refunded (defaults to today)
- Topups: zero or more (date + amount)

## Outputs
- Summary: Total Deposit, Total Interest, Grand Total
- Breakdown table: Date, Description, Total Invested Funds, Running Balance, Share %, Interest Earned, Share of Interest
- Downloadable PDF statement

## Pages

### Trust Interest Register (`/admin/deposit-trust-interest`)
- Admin-only CRUD page
- Table: Date, Total Invested Funds, Interest Earned, Actions
- Inline add/edit, soft-delete
- Paginated 24 per page

### Deposit Interest Calculator (`/deposit-interest-calculator`)
- BM + Admin access
- Form with property, deposit, dates, topups
- Results display with summary + breakdown table
- "Download PDF Statement" button

## Routes
```
GET    /admin/deposit-trust-interest           admin.deposit-trust-interest.index
POST   /admin/deposit-trust-interest           admin.deposit-trust-interest.store
PUT    /admin/deposit-trust-interest/{record}   admin.deposit-trust-interest.update
DELETE /admin/deposit-trust-interest/{record}   admin.deposit-trust-interest.destroy

GET    /deposit-interest-calculator             deposit-interest-calculator.index
POST   /deposit-interest-calculator/calculate   deposit-interest-calculator.calculate
POST   /deposit-interest-calculator/download-pdf deposit-interest-calculator.download-pdf
```

## Key Files
- `app/Models/DepositTrustInterest.php`
- `app/Services/DepositInterestCalculatorService.php`
- `app/Http/Controllers/Admin/DepositTrustInterestController.php`
- `app/Http/Controllers/DepositInterestCalculatorController.php`
- `resources/views/admin/deposit-trust-interest/index.blade.php`
- `resources/views/deposit-interest-calculator/index.blade.php`
- `resources/views/deposit-interest-calculator/pdf.blade.php`
- `database/migrations/xxxx_create_deposit_trust_interest_table.php`
- `database/seeders/DepositTrustInterestSeeder.php`

## Navigation
- Sidebar: "Trust Interest Register" (admin only) + "Deposit Interest Calculator" (BM + admin)
- Placed in Tools section

## Future
- Link to CoreX property/tenant records (read deposit from deal/lease record)
- Auto-populate from accounting module when built
- Batch processing for multiple deposits at once
