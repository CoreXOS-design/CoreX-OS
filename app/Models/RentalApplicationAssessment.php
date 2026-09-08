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
        'has_unpaid_transactions', 'updated_by_user_id',
    ];

    protected $casts = [
        'statement_months' => 'integer',
        'has_unpaid_transactions' => 'boolean',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
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

    /**
     * THE RULE, stated as the law states it — Johan, from his own reading:
     * "the law states you may not spend more than 30% of your gross
     * income on rentals... its not 3.5 or what you created it as of nett
     * disposable income. its of the gross income." Rent must not exceed
     * $maxRentPercent% of GROSS income. Not a multiplier of rent (the same
     * arithmetic wearing a disguise — nobody could check a "3.5x" figure
     * against the actual legal guideline at a glance), not net-of-expenses
     * (confirmed before this change: the OLD version already tested
     * income-before-expenses against the threshold, matching the law's own
     * "gross" basis by coincidence of arithmetic direction, not by
     * design — this version makes that basis explicit and correct).
     *
     * Round 12 — Johan, plainly, after being asked to confirm and finding
     * the question itself confusing: "agent captures income on right
     * panel. choses to say 3 months - so whatever the agent captured get
     * averaged by the months selected - 10000, 10000, 13000 tallies to
     * 33000, agent selected 3 months - so the avg income is? 11000?"
     * THE FIGURE THE 30% RULE RUNS AGAINST IS NOW THE MONTHLY AVERAGE
     * (captured total ÷ statement_months), never the raw multi-month lump
     * sum — a bank statement's total is meaningless against a MONTHLY
     * legal threshold without dividing it down first. Same arithmetic for
     * expenses, so net_income stays an apples-to-apples monthly figure.
     *
     * Requires BOTH captured income AND a valid statement_months (a
     * positive whole number — enforced at validation, defended again
     * here) to compute anything: neither alone is enough, and a missing/
     * zero months figure must never divide, silently show a wrong number,
     * or fall back to treating the raw total as if it were monthly.
     * Rounding: round-half-up to the nearest cent (PHP's own round($n, 2)
     * default), same as every other money figure this method returns.
     *
     * SUGGESTIVE, NEVER A RULE. Johan, verbatim: "The marking is only
     * suggestive to the agent to spot. not rule of thumb." This method
     * never blocks, never auto-declines, never writes a decision anywhere
     * — it only returns numbers and a label for the agent to read. Returns
     * null fields when there isn't enough input to compute anything
     * (never a misleading zero).
     *
     * `net_income` is still computed and returned — Johan's explicit call,
     * kept as genuinely useful context an agent typed in themselves (what's
     * left after existing debts/expenses), but it plays NO part in
     * `meets_threshold`. Any screen that displays it must label it
     * unmistakably as reference-only, separated from the pass/fail badge —
     * the OLD screen showed it unlabelled next to the badge, which is
     * exactly what made an agent reasonably assume it was being tested.
     *
     * Round 16 — Johan: "the affordability check is currently testing the
     * applicant's SELF-REPORTED CURRENT RENT, not the rent of the property
     * being applied for... If no property is linked, the check must say
     * it cannot run rather than testing the wrong number." `rent` now
     * comes from the LINKED PROPERTY's own rental_amount, never
     * current_rental_amount (that column is the applicant's own current,
     * pre-move residence — irrelevant to whether they can afford the
     * property they're actually applying for). `property_linked` is
     * returned explicitly so the view can say plainly why no pass/fail
     * exists yet, rather than silently showing nothing or (worse) falling
     * back to the wrong rent. The QUALIFYING CEILING itself
     * (max_affordable_rent) does NOT need a property — it's purely a
     * function of captured income — so it still computes and displays as
     * soon as income+months exist; only the pass/fail comparison against
     * an actual rent additionally needs the property link.
     */
    public function qualifyingResult(float $maxRentPercent): array
    {
        // AT-392 authoriser strike-out, 2026-09-08 — Johan: "remove im
        // thinking is just a strike out tick - which leaves the amount
        // there but removes it from the calcs." Struck lines stay in the
        // rendered collection (every view still iterates $this->incomeItems/
        // expenseItems unfiltered) — excluded ONLY here, from the totals
        // this method computes, which is the one place "counts toward the
        // calc" is decided. Composes with Round 16 untouched: rent still
        // comes from the linked property, has_unpaid_transactions is a
        // separate flat flag this method doesn't touch either way.
        $incomeItems = $this->incomeItems->reject(fn ($item) => $item->isStruckOut());
        $expenseItems = $this->expenseItems->reject(fn ($item) => $item->isStruckOut());
        $totalIncome = $incomeItems->isEmpty() ? null : (float) $incomeItems->sum('amount');
        $totalExpenses = (float) $expenseItems->sum('amount');

        $months = $this->statement_months;
        $hasValidMonths = $months !== null && $months > 0;

        // THE figure the affordability rule runs against — never the raw
        // total. Null (⇒ 'incomplete' below) whenever either half of the
        // division is missing, rather than a wrong number or a silent
        // fallback to the un-divided total.
        $grossIncome = ($totalIncome !== null && $hasValidMonths)
            ? round($totalIncome / $months, 2)
            : null;
        $monthlyExpenses = $hasValidMonths ? round($totalExpenses / $months, 2) : null;

        $netIncome = ($grossIncome === null || $monthlyExpenses === null)
            ? null
            : $grossIncome - $monthlyExpenses;

        $rentalApplication = $this->rentalApplication;
        $property = $rentalApplication?->property;
        $propertyLinked = $property !== null;
        $rent = $property?->rental_amount !== null ? (float) $property->rental_amount : null;

        $maxAffordableRent = $grossIncome !== null ? round($grossIncome * ($maxRentPercent / 100), 2) : null;

        $rentAsPercentOfGross = ($rent !== null && $grossIncome !== null && $grossIncome > 0)
            ? round(($rent / $grossIncome) * 100, 1)
            : null;

        $meetsThreshold = ($rent !== null && $maxAffordableRent !== null)
            ? $rent <= $maxAffordableRent
            : null;

        // Johan: "make sure the screen shows both clearly so the agent
        // can see the difference" — the applicant's own self-reported
        // figure, purely for comparison, never part of the decision.
        $applicantReportedIncome = $rentalApplication?->monthly_salary !== null
            ? (float) $rentalApplication->monthly_salary
            : null;

        // 'incomplete' — no income/months captured yet, nothing to show at
        // all. 'no_property' — the qualifying ceiling IS computable but
        // there's no linked property to test a real rent against yet.
        // 'sufficient' | 'insufficient' — the actual pass/fail, once both
        // exist.
        if ($maxAffordableRent === null) {
            $label = 'incomplete';
        } elseif (! $propertyLinked) {
            $label = 'no_property';
        } else {
            $label = $meetsThreshold ? 'sufficient' : 'insufficient';
        }

        return [
            'gross_income' => $grossIncome,
            'net_income' => $netIncome,
            'total_captured_income' => $totalIncome,
            'total_captured_expenses' => $this->expenseItems->isEmpty() ? null : $totalExpenses,
            'statement_months' => $months,
            'applicant_reported_income' => $applicantReportedIncome,
            'property_linked' => $propertyLinked,
            'rent' => $rent,
            'max_rent_percent' => $maxRentPercent,
            'max_affordable_rent' => $maxAffordableRent,
            'rent_as_percent_of_gross' => $rentAsPercentOfGross,
            'meets_threshold' => $meetsThreshold,
            'label' => $label,
        ];
    }

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }
}
