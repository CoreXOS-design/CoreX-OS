<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AT-392 Phase 2 — the agent's own affordability capture for a rental
 * application, one row per application (see the migration for the "why").
 * Autosaved from the review screen's right panel; every field nullable so
 * a partially-filled assessment never blocks anything.
 *
 * Round 9 (item 5) — monthly_income/other_monthly_income/monthly_expenses
 * were fixed columns; replaced with incomeItems()/expenseItems(), an
 * agent-growable list of lines each (Johan: "filling the last row auto-adds
 * a fresh empty one"). See
 * 2026_09_08_180000_create_rental_application_income_expense_items_tables.php
 * for the data migration that preserved existing captured amounts.
 */
class RentalApplicationAssessment extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'rental_application_id', 'notes', 'statement_months',
        'statement_period_from', 'statement_period_to',
        'has_unpaid_transactions', 'updated_by_user_id',
    ];

    protected $casts = [
        'statement_months' => 'integer',
        'statement_period_from' => 'date:Y-m-d',
        'statement_period_to' => 'date:Y-m-d',
        'has_unpaid_transactions' => 'boolean',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * "Dates on entries" (Johan, 2026-09-10) — "Months covered" stops being
     * a typed number and becomes a from/to date range; this is the ONLY
     * place that number is derived. Calendar-month-inclusive count (a
     * statement running 15 Jan to 20 Mar covers 3 months' worth of
     * statements — Jan, Feb, Mar — regardless of which day within Jan or
     * Mar the range starts/ends on), never a raw day-count divided by ~30
     * (that would silently under/over-count on short months). Always at
     * least 1 once both dates are present — a range within the same
     * calendar month is still "1 month covered."
     *
     * SHORT-RANGE FLOOR (2026-09-13, cc5 — QA1 item 6 investigation, fixed
     * on Johan's go): the calendar-inclusive rule above is correct for a
     * genuine multi-month span, but on its own it also called a 6-day
     * range crossing a single month boundary (28 Jun–3 Jul) "2 months" —
     * proven live, a materially wrong "Monthly income" (halved) with no
     * dash and no warning. No calendar month has more than 31 days, so any
     * range of 31 days or fewer can never actually contain two distinct
     * whole months' worth of statement data — it is always safe to call
     * this "1", full stop, before the calendar-bucket rule below even
     * runs. This floor does NOT touch the genuine multi-month case (15
     * Jan–20 Mar is 65 days, well past the floor, still correctly "3" via
     * the calendar rule) — it only catches ranges too short to legitimately
     * be more than one month's statement in the first place.
     *
     * Returns null when either date is missing — the caller decides what
     * that means (for a fresh save it means "don't touch statement_months
     * yet"; it is never treated as zero).
     */
    public static function calculateStatementMonths(?string $from, ?string $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        $fromDate = \Illuminate\Support\Carbon::parse($from);
        $toDate = \Illuminate\Support\Carbon::parse($to);

        $totalDays = $fromDate->diffInDays($toDate) + 1;
        if ($totalDays <= 31) {
            return 1;
        }

        $months = ($toDate->year - $fromDate->year) * 12 + ($toDate->month - $fromDate->month) + 1;

        return max(1, $months);
    }

    public function incomeItems(): HasMany
    {
        return $this->hasMany(RentalApplicationIncomeItem::class, 'rental_application_assessment_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function expenseItems(): HasMany
    {
        return $this->hasMany(RentalApplicationExpenseItem::class, 'rental_application_assessment_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    // qualifyingResult() — the pre-capture-ledger-rework affordability
    // calculator (30%-of-gross-income rule, Rounds 9/12/16) — REMOVED
    // 2026-09-14, cc4, on Johan's explicit instruction. It computed on
    // every review/authorisation page load but its output had not been
    // read by review.blade.php since the 2026-09-11 rework (confirmed by
    // an exhaustive whole-application grep, not assumed — see this
    // model's own git history for the full method body and its worked-
    // example test coverage, and .ai/specs/rental-applications.md's
    // "qualifyingResult() removed" section for the full audit trail).
    // incomeItems()/expenseItems() above stay: the authoriser's own
    // add-item endpoints (RentalApplicationAuthorisationController::
    // addIncomeItem()/toggleStrikeIncomeItem()) still write to these
    // tables independently of this removal.

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }
}
