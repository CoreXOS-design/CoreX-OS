<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works out what an agency owes CoreX.
 *
 * Spec: .ai/specs/agency-billing.md §6  (AT-11)
 *
 * This service is PURE: it reads, it computes, it returns a BillingQuote. It
 * never writes. That is what makes it safe to call on every page render and
 * trivial to test.
 *
 * The seat count is COUNTED LIVE on every call — never stored, never
 * incremented. So the bill is right no matter what changed a user: the admin
 * screen, a bulk import, tinker, raw SQL. Domain events and the nightly sweep
 * exist only to make the plan-change EMAIL prompt; they are not load-bearing
 * for the NUMBER. (Spec §7.3.)
 *
 * Every rand amount comes from config/corex-billing.php. Nothing here
 * hardcodes a price.
 */
class SubscriptionPricingService
{
    public function quoteFor(Agency $agency, ?Carbon $on = null): BillingQuote
    {
        $on = $on ?? Carbon::now();

        $seats    = $this->billableSeats($agency);
        $branches = $this->branchCount($agency);

        $derivedPlan = $this->derivePlan($seats);
        $subscription = AgencySubscription::forAgency((int) $agency->id);

        $lines    = $this->lines($derivedPlan, $seats, $branches);
        $computed = round(array_sum(array_column($lines, 'amount')), 2);

        // ── Price resolution order (spec §4). Custom amount beats discount,
        //    always — the invariant says they never coexist, and this ordering
        //    is the read-side ABSORB if a row somehow holds both.
        $hasCustom      = $subscription->hasCustomAmount();
        $discountActive = $subscription->discountIsActive($on);

        if ($hasCustom) {
            $payable = round((float) $subscription->custom_amount_zar, 2);
            $basis   = BillingQuote::BASIS_CUSTOM;
        } elseif ($discountActive) {
            $pct     = (float) $subscription->discount_percent;
            $payable = round($computed * (1 - ($pct / 100)), 2);
            $basis   = BillingQuote::BASIS_DISCOUNTED;
        } else {
            $payable = $computed;
            $basis   = BillingQuote::BASIS_AUTOMATIC;
        }

        $included         = (int) config('corex-billing.branches.included', 1);
        $billableBranches = max(0, $branches - $included);

        return new BillingQuote(
            agencyId:                (int) $agency->id,
            seats:                   $seats,
            branches:                $branches,
            billableBranches:        $billableBranches,
            derivedPlan:             $derivedPlan,
            storedPlan:              (string) ($subscription->plan ?? AgencySubscription::PLAN_TEAM),
            planLabel:               $this->planLabel($derivedPlan),
            lines:                   $lines,
            computedZar:             $computed,
            payableZar:              max(0.0, $payable),
            basis:                   $basis,
            customAmountZar:         $hasCustom ? (float) $subscription->custom_amount_zar : null,
            customAmountNote:        $hasCustom ? $subscription->custom_amount_note : null,
            discountActive:          $discountActive,
            discountPercent:         $discountActive ? (float) $subscription->discount_percent : null,
            discountMonthsRemaining: $subscription->discountMonthsRemaining($on),
            discountEndsOn:          $discountActive ? $subscription->discountEndsOn()?->toDateString() : null,
            discountNote:            $discountActive ? $subscription->discount_note : null,
        );
    }

    /**
     * A billable seat is ANY active, non-archived user on the agency —
     * agent, admin, principal, secretary alike (spec §3 D1). Role is
     * irrelevant: a login is a seat.
     *
     * Deactivated (is_active = 0) and archived (deleted_at) users do NOT count.
     * CoreX System Owners carry agency_id = NULL and so never fall into any
     * agency's count.
     *
     * Uses the query builder rather than Eloquent deliberately: this is called
     * from the owner-context developer page, which reads across every tenant,
     * and DB::table has no global scope to reason about.
     */
    public function billableSeats(Agency $agency): int
    {
        return (int) DB::table('users')
            ->where('agency_id', $agency->id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();
    }

    /** Live branches for the agency (archived branches are not billed). */
    public function branchCount(Agency $agency): int
    {
        return (int) DB::table('branches')
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->count();
    }

    /** The plan the headcount implies. The 11th seat moves them to Agency. */
    public function derivePlan(int $seats): string
    {
        $maxTeamSeats = (int) config('corex-billing.team.max_seats', 10);

        return $seats > $maxTeamSeats
            ? AgencySubscription::PLAN_AGENCY
            : AgencySubscription::PLAN_TEAM;
    }

    public function planLabel(string $plan): string
    {
        return (string) config("corex-billing.{$plan}.label", ucfirst($plan));
    }

    /**
     * The arithmetic, itemised — this is what the agency reads to check our
     * maths, so every rand of the total must be attributable to a line.
     *
     * @return list<array{label:string,qty:int,unit:float,amount:float}>
     */
    public function lines(string $plan, int $seats, int $branches): array
    {
        $lines = $plan === AgencySubscription::PLAN_AGENCY
            ? $this->agencyPlanLines($seats)
            : $this->teamPlanLines($seats);

        // Branches are plan-agnostic (spec §3 D2a): the first is included, each
        // one beyond that is charged — on Team as well as Agency.
        $included = (int) config('corex-billing.branches.included', 1);
        $rate     = (float) config('corex-billing.branches.rate', 750.00);
        $billable = max(0, $branches - $included);

        if ($billable > 0) {
            $lines[] = [
                'label'  => $billable === 1 ? 'Additional branch' : 'Additional branches',
                'qty'    => $billable,
                'unit'   => $rate,
                'amount' => round($billable * $rate, 2),
            ];
        }

        return $lines;
    }

    /** Team: flat headcount × rate. No base fee, no tiers. */
    private function teamPlanLines(int $seats): array
    {
        if ($seats === 0) {
            return [];
        }

        $rate = (float) config('corex-billing.team.seat_rate', 450.00);

        return [[
            'label'  => 'Users',
            'qty'    => $seats,
            'unit'   => $rate,
            'amount' => round($seats * $rate, 2),
        ]];
    }

    /**
     * Agency: base fee + GRADUATED seat tiers.
     *
     * Graduated — each band is charged at its own rate. 25 seats is
     * 10×295 + 10×250 + 5×195 = R6 425, NOT 25×195. Getting this wrong is the
     * single easiest way to misbill, so it is walked band by band and each band
     * is emitted as its own line the agency can check.
     */
    private function agencyPlanLines(int $seats): array
    {
        $lines = [[
            'label'  => 'Platform base fee',
            'qty'    => 1,
            'unit'   => (float) config('corex-billing.agency.base_fee', 1495.00),
            'amount' => round((float) config('corex-billing.agency.base_fee', 1495.00), 2),
        ]];

        foreach ((array) config('corex-billing.agency.seat_tiers', []) as $tier) {
            $from = (int) $tier['from'];
            $to   = $tier['to'] === null ? PHP_INT_MAX : (int) $tier['to'];
            $rate = (float) $tier['rate'];

            // How many of this agency's seats fall inside THIS band.
            $inBand = min($seats, $to) - ($from - 1);
            if ($inBand <= 0) {
                continue;
            }

            $lines[] = [
                'label'  => $this->tierLabel($from, $tier['to']),
                'qty'    => $inBand,
                'unit'   => $rate,
                'amount' => round($inBand * $rate, 2),
            ];
        }

        return $lines;
    }

    private function tierLabel(int $from, ?int $to): string
    {
        return $to === null
            ? "Users {$from}+"
            : "Users {$from}–{$to}";
    }
}
