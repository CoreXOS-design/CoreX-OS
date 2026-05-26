<?php

namespace App\Services\Leave;

use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveEntitlement;
use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveBalanceService
{
    /**
     * Derive current balance from leave_transactions ledger.
     * Never trusts cached leave_entitlements — derives from source.
     */
    public function getBalance(
        PayrollEmployee $employee,
        LeaveType $type,
        ?Carbon $cycleStart = null
    ): array {
        $cycleStart = $cycleStart ?? $this->getCurrentCycleStart($employee, $type);
        $cycleEnd = $this->getCycleEnd($employee, $type, $cycleStart);

        // Sum transactions for this employee+type+cycle
        $transactions = LeaveTransaction::withoutGlobalScopes()
            ->where('payroll_employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('cycle_start_date', $cycleStart->toDateString())
            ->get();

        $accrued = '0.000';
        $carryover = '0.000';
        $taken = '0.000';

        foreach ($transactions as $txn) {
            if (in_array($txn->transaction_type, ['accrual', 'opening_balance'])) {
                $accrued = bcadd($accrued, (string) $txn->days_delta, 3);
            } elseif ($txn->transaction_type === 'carry_over') {
                $carryover = bcadd($carryover, (string) $txn->days_delta, 3);
            } elseif (in_array($txn->transaction_type, ['application_approved', 'forfeiture', 'termination_payout'])) {
                // These are negative deltas (deductions from balance)
                $taken = bcsub($taken, (string) $txn->days_delta, 3); // delta is negative, so sub makes taken positive
            } elseif ($txn->transaction_type === 'application_cancelled') {
                // Cancellation reverses a taken amount (positive delta)
                $taken = bcsub($taken, (string) $txn->days_delta, 3);
            } elseif ($txn->transaction_type === 'manual_adjustment') {
                // Positive or negative — treat as accrual adjustment
                $accrued = bcadd($accrued, (string) $txn->days_delta, 3);
            } elseif ($txn->transaction_type === 'reversal') {
                // Reversal offsets whatever it reverses — add to accrued
                $accrued = bcadd($accrued, (string) $txn->days_delta, 3);
            }
        }

        // Pending from submitted (not yet approved) applications
        $pending = LeaveApplication::withoutGlobalScopes()
            ->where('payroll_employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('status', 'submitted')
            ->sum('working_days_requested');
        $pendingStr = (string) ($pending ?: '0.000');

        $entitlement = $type->entitlementForPattern($employee->working_days_per_week ?? 5);

        $available = bcsub(
            bcadd($accrued, $carryover, 3),
            bcadd($taken, $pendingStr, 3),
            3
        );

        return [
            'leave_type'                    => $type,
            'cycle_start_date'              => $cycleStart,
            'cycle_end_date'                => $cycleEnd,
            'entitlement_days'              => number_format($entitlement, 3, '.', ''),
            'accrued_days'                  => $accrued,
            'carryover_from_previous_cycle' => $carryover,
            'taken_days'                    => $taken,
            'pending_days'                  => $pendingStr,
            'available_days'                => $available,
        ];
    }

    /**
     * Upsert leave_entitlements row from derived balance.
     */
    public function refreshEntitlement(
        PayrollEmployee $employee,
        LeaveType $type,
        ?Carbon $cycleStart = null
    ): LeaveEntitlement {
        $balance = $this->getBalance($employee, $type, $cycleStart);

        return LeaveEntitlement::updateOrCreate(
            [
                'payroll_employee_id' => $employee->id,
                'leave_type_id'       => $type->id,
                'cycle_start_date'    => $balance['cycle_start_date']->toDateString(),
            ],
            [
                'agency_id'                    => $employee->agency_id,
                'branch_id'                    => $employee->branch_id,
                'user_id'                      => $employee->user_id,
                'cycle_end_date'               => $balance['cycle_end_date']->toDateString(),
                'entitlement_days'             => $balance['entitlement_days'],
                'accrued_days'                 => $balance['accrued_days'],
                'carryover_from_previous_cycle' => $balance['carryover_from_previous_cycle'],
                'taken_days'                   => $balance['taken_days'],
                'pending_days'                 => $balance['pending_days'],
                'available_days'               => $balance['available_days'],
                'last_accrual_run_at'          => now(),
            ]
        );
    }

    /**
     * All balances for an employee across all active leave types.
     */
    public function getAllBalancesForEmployee(PayrollEmployee $employee): Collection
    {
        $types = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $types->map(fn(LeaveType $type) => $this->getBalance($employee, $type));
    }

    /**
     * Calculate current cycle start based on employment_date and cycle_months.
     */
    public function getCurrentCycleStart(PayrollEmployee $employee, LeaveType $type): Carbon
    {
        $empDate = $employee->employment_date->copy()->startOfDay();
        $today = Carbon::today();
        $cycleMonths = (int) $type->cycle_months;

        // Parental leave (cycle_months=0) — no recurring cycle
        if ($cycleMonths === 0) {
            return $empDate;
        }

        // Walk forward from employment_date by cycle_months until we find the
        // most recent boundary <= today
        $cursor = $empDate->copy();
        while ($cursor->copy()->addMonths($cycleMonths)->lte($today)) {
            $cursor->addMonths($cycleMonths);
        }

        return $cursor;
    }

    /**
     * Cycle end = cycle_start + cycle_months - 1 day.
     */
    public function getCycleEnd(PayrollEmployee $employee, LeaveType $type, Carbon $cycleStart): Carbon
    {
        $cycleMonths = (int) $type->cycle_months;

        if ($cycleMonths === 0) {
            return Carbon::parse('2099-12-31');
        }

        return $cycleStart->copy()->addMonths($cycleMonths)->subDay();
    }
}
