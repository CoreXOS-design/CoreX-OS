<?php

namespace App\Services\Leave;

use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveAccrualService
{
    private LeaveBalanceService $balanceService;
    private PublicHolidayService $holidayService;

    public function __construct()
    {
        $this->balanceService = new LeaveBalanceService();
        $this->holidayService = new PublicHolidayService();
    }

    /**
     * Accrue leave for a single employee up to asOfDate (default: yesterday).
     */
    public function accrueForEmployee(PayrollEmployee $employee, ?Carbon $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? Carbon::yesterday();
        $typesProcessed = [];
        $txnCount = 0;

        $types = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('is_active', true)
            ->get();

        foreach ($types as $type) {
            if ($type->accrual_method === 'none') {
                continue;
            }

            try {
                $cycleStart = $this->balanceService->getCurrentCycleStart($employee, $type);
                $cycleEnd = $this->balanceService->getCycleEnd($employee, $type, $cycleStart);

                // Don't accrue beyond cycle end
                $effectiveDate = $asOfDate->lt($cycleEnd) ? $asOfDate : $cycleEnd;

                $targetAccrued = $this->calculateTargetAccrued($employee, $type, $cycleStart, $effectiveDate);

                // Current accrued from transactions (accrual + opening_balance only)
                $currentAccrued = LeaveTransaction::withoutGlobalScopes()
                    ->where('payroll_employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('cycle_start_date', $cycleStart->toDateString())
                    ->whereIn('transaction_type', ['accrual', 'opening_balance'])
                    ->sum('days_delta');
                $currentAccruedStr = (string) ($currentAccrued ?: '0.000');

                $delta = bcsub($targetAccrued, $currentAccruedStr, 3);

                $before = $currentAccruedStr;
                $after = $targetAccrued;

                if (bccomp($delta, '0', 3) > 0) {
                    DB::transaction(function () use ($employee, $type, $cycleStart, $effectiveDate, $delta) {
                        LeaveTransaction::create([
                            'agency_id'            => $employee->agency_id,
                            'payroll_employee_id'  => $employee->id,
                            'user_id'              => $employee->user_id,
                            'leave_type_id'        => $type->id,
                            'cycle_start_date'     => $cycleStart->toDateString(),
                            'transaction_type'     => 'accrual',
                            'days_delta'           => $delta,
                            'effective_date'       => $effectiveDate->toDateString(),
                            'description'          => "Daily accrual: {$delta} days ({$type->label})",
                            'source_type'          => 'accrual_run',
                            'source_id'            => null,
                            'created_by_user_id'   => null,
                        ]);
                    });

                    $txnCount++;
                }

                // Refresh entitlement row
                $this->balanceService->refreshEntitlement($employee, $type, $cycleStart);

                $typesProcessed[] = [
                    'code'   => $type->code,
                    'before' => $before,
                    'after'  => $after,
                    'delta'  => $delta,
                ];
            } catch (\Throwable $e) {
                Log::error("Leave accrual failed for employee {$employee->id}, type {$type->code}", [
                    'error' => $e->getMessage(),
                ]);
                $typesProcessed[] = [
                    'code'   => $type->code,
                    'before' => '0.000',
                    'after'  => '0.000',
                    'delta'  => '0.000',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return [
            'employee_id'          => $employee->id,
            'transactions_created' => $txnCount,
            'types_processed'      => $typesProcessed,
        ];
    }

    /**
     * Calculate target accrued days based on accrual_method.
     */
    private function calculateTargetAccrued(
        PayrollEmployee $employee,
        LeaveType $type,
        Carbon $cycleStart,
        Carbon $asOfDate
    ): string {
        $entitlement = (string) $type->entitlementForPattern($employee->working_days_per_week ?? 5);
        $mask = $employee->workingDaysMaskArray();

        return match ($type->accrual_method) {
            'full_at_start' => $entitlement,

            'accrual_per_day_worked' => $this->accruePerDayWorked(
                $employee, $type, $cycleStart, $asOfDate, $entitlement, $mask
            ),

            'accrual_first_six_months' => $this->accrueFirstSixMonths(
                $employee, $type, $cycleStart, $asOfDate, $entitlement, $mask
            ),

            default => '0.000',
        };
    }

    /**
     * Annual leave: 1 day per N worked days.
     */
    private function accruePerDayWorked(
        PayrollEmployee $employee,
        LeaveType $type,
        Carbon $cycleStart,
        Carbon $asOfDate,
        string $entitlement,
        array $mask
    ): string {
        $effectiveEnd = $asOfDate->lt($this->balanceService->getCycleEnd($employee, $type, $cycleStart))
            ? $asOfDate
            : $this->balanceService->getCycleEnd($employee, $type, $cycleStart);

        $daysWorked = $this->holidayService->countWorkingDays($cycleStart, $effectiveEnd, $mask);
        $rate = (int) $type->accrual_rate_per_days;

        if ($rate <= 0) {
            return '0.000';
        }

        // target = daysWorked / rate, floored to 3dp
        $target = bcdiv((string) $daysWorked, (string) $rate, 3);

        // Cap at entitlement
        if (bccomp($target, $entitlement, 3) > 0) {
            $target = $entitlement;
        }

        return $target;
    }

    /**
     * Sick leave: first 6 months 1 per 26 worked, then full at start.
     * Per BCEA s22(2).
     */
    private function accrueFirstSixMonths(
        PayrollEmployee $employee,
        LeaveType $type,
        Carbon $cycleStart,
        Carbon $asOfDate,
        string $entitlement,
        array $mask
    ): string {
        $empDate = $employee->employment_date->copy();
        $sixMonthsMark = $empDate->copy()->addMonths(6);

        if ($asOfDate->lt($sixMonthsMark)) {
            // Still in first 6 months: 1 per 26 worked
            $daysWorked = $this->holidayService->countWorkingDays($empDate, $asOfDate, $mask);
            $rate = (int) $type->accrual_rate_per_days;
            if ($rate <= 0) return '0.000';
            $target = bcdiv((string) $daysWorked, (string) $rate, 3);
            // Cap at entitlement
            if (bccomp($target, $entitlement, 3) > 0) {
                $target = $entitlement;
            }
            return $target;
        }

        // Past 6 months: full entitlement
        return $entitlement;
    }

    /**
     * Process all active employees.
     */
    public function accrueAll(?Carbon $asOfDate = null): array
    {
        $employees = PayrollEmployee::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('termination_date')
            ->get();

        $totalTxns = 0;
        $errors = [];
        $processed = 0;

        foreach ($employees as $emp) {
            try {
                $result = $this->accrueForEmployee($emp, $asOfDate);
                $totalTxns += $result['transactions_created'];
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = ['employee_id' => $emp->id, 'error' => $e->getMessage()];
                Log::error("Leave accrual batch error for employee {$emp->id}", ['error' => $e->getMessage()]);
            }
        }

        return [
            'total_employees'    => $processed,
            'total_transactions' => $totalTxns,
            'errors'             => $errors,
        ];
    }

    /**
     * Process cycle rollovers for employees whose cycles have ended.
     */
    public function rolloverCycles(?Carbon $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? Carbon::today();
        $rollovers = [];
        $warnings = [];

        $employees = PayrollEmployee::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('termination_date')
            ->get();

        $types = [];

        foreach ($employees as $emp) {
            if (empty($types[$emp->agency_id])) {
                $types[$emp->agency_id] = LeaveType::withoutGlobalScopes()
                    ->where('agency_id', $emp->agency_id)
                    ->where('is_active', true)
                    ->where('cycle_months', '>', 0)
                    ->get();
            }

            foreach ($types[$emp->agency_id] as $type) {
                try {
                    $cycleStart = $this->balanceService->getCurrentCycleStart($emp, $type);
                    $cycleEnd = $this->balanceService->getCycleEnd($emp, $type, $cycleStart);

                    // Cycle hasn't ended yet
                    if ($cycleEnd->gte($asOfDate)) {
                        // Compliance warning: check accumulated balance
                        $balance = $this->balanceService->getBalance($emp, $type, $cycleStart);
                        $entitlement = (float) $balance['entitlement_days'];
                        $available = (float) $balance['available_days'];
                        if ($entitlement > 0 && $available > ($entitlement * 1.5)) {
                            $warnings[] = "Employee {$emp->user->name} ({$emp->id}): {$available} days {$type->code} accumulated — exceeds 1.5 cycles. Encourage taking leave.";
                        }
                        continue;
                    }

                    // Cycle has ended — process rollover
                    $balance = $this->balanceService->getBalance($emp, $type, $cycleStart);
                    $available = $balance['available_days'];

                    $newCycleStart = $cycleEnd->copy()->addDay();

                    DB::transaction(function () use ($emp, $type, $cycleStart, $newCycleStart, $available, $balance) {
                        // Carryover
                        if ($type->carries_over_to_next_cycle && bccomp($available, '0', 3) > 0) {
                            LeaveTransaction::create([
                                'agency_id'           => $emp->agency_id,
                                'payroll_employee_id' => $emp->id,
                                'user_id'             => $emp->user_id,
                                'leave_type_id'       => $type->id,
                                'cycle_start_date'    => $newCycleStart->toDateString(),
                                'transaction_type'    => 'carry_over',
                                'days_delta'          => $available,
                                'effective_date'      => $newCycleStart->toDateString(),
                                'description'         => "Carry-over of {$available} days from previous cycle",
                                'source_type'         => 'cycle_rollover',
                            ]);
                        }

                        // Forfeiture for non-carryover types with remaining balance
                        if (!$type->carries_over_to_next_cycle && bccomp($available, '0', 3) > 0) {
                            LeaveTransaction::create([
                                'agency_id'           => $emp->agency_id,
                                'payroll_employee_id' => $emp->id,
                                'user_id'             => $emp->user_id,
                                'leave_type_id'       => $type->id,
                                'cycle_start_date'    => $cycleStart->toDateString(),
                                'transaction_type'    => 'forfeiture',
                                'days_delta'          => bcmul($available, '-1', 3),
                                'effective_date'      => $newCycleStart->toDateString(),
                                'description'         => "Forfeiture of {$available} days at cycle end ({$type->label})",
                                'source_type'         => 'cycle_rollover',
                            ]);
                        }

                        // For full_at_start types, create opening accrual for new cycle
                        if ($type->accrual_method === 'full_at_start') {
                            $newEntitlement = (string) $type->entitlementForPattern($emp->working_days_per_week ?? 5);
                            LeaveTransaction::create([
                                'agency_id'           => $emp->agency_id,
                                'payroll_employee_id' => $emp->id,
                                'user_id'             => $emp->user_id,
                                'leave_type_id'       => $type->id,
                                'cycle_start_date'    => $newCycleStart->toDateString(),
                                'transaction_type'    => 'accrual',
                                'days_delta'          => $newEntitlement,
                                'effective_date'      => $newCycleStart->toDateString(),
                                'description'         => "Full entitlement at cycle start ({$type->label})",
                                'source_type'         => 'cycle_rollover',
                            ]);
                        }

                        // Refresh both old and new cycle entitlements
                        $this->balanceService->refreshEntitlement($emp, $type, $cycleStart);
                        $this->balanceService->refreshEntitlement($emp, $type, $newCycleStart);
                    });

                    $rollovers[] = [
                        'employee' => $emp->user->name,
                        'type'     => $type->code,
                        'old_cycle_end' => $cycleEnd->toDateString(),
                        'new_cycle_start' => $newCycleStart->toDateString(),
                        'carried_over' => $type->carries_over_to_next_cycle ? $available : '0.000',
                    ];
                } catch (\Throwable $e) {
                    Log::error("Leave cycle rollover failed for employee {$emp->id}, type {$type->code}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['rollovers' => $rollovers, 'warnings' => $warnings];
    }

    /**
     * Manual balance adjustment with full audit trail.
     */
    public function manualAdjustment(
        PayrollEmployee $employee,
        LeaveType $type,
        string $daysDelta,
        string $reason,
        User $adjustedBy,
        ?Carbon $effectiveDate = null
    ): LeaveTransaction {
        $effectiveDate = $effectiveDate ?? Carbon::today();
        $cycleStart = $this->balanceService->getCurrentCycleStart($employee, $type);

        $txn = LeaveTransaction::create([
            'agency_id'            => $employee->agency_id,
            'payroll_employee_id'  => $employee->id,
            'user_id'              => $employee->user_id,
            'leave_type_id'        => $type->id,
            'cycle_start_date'     => $cycleStart->toDateString(),
            'transaction_type'     => 'manual_adjustment',
            'days_delta'           => $daysDelta,
            'effective_date'       => $effectiveDate->toDateString(),
            'description'          => "Manual adjustment: {$reason}",
            'source_type'          => 'manual',
            'created_by_user_id'   => $adjustedBy->id,
        ]);

        $this->balanceService->refreshEntitlement($employee, $type, $cycleStart);

        return $txn;
    }
}
