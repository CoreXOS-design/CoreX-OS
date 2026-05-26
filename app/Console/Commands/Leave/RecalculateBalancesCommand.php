<?php

namespace App\Console\Commands\Leave;

use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Leave\LeaveBalanceService;
use Illuminate\Console\Command;

class RecalculateBalancesCommand extends Command
{
    protected $signature = 'corex:leave:recalculate-balances {--employee=} {--all}';
    protected $description = 'Re-derive leave entitlement rows from transaction ledger';

    public function handle(): int
    {
        $service = new LeaveBalanceService();
        $employeeId = $this->option('employee');

        if ($employeeId) {
            $employee = PayrollEmployee::find($employeeId);
            if (!$employee) {
                $this->error("Employee {$employeeId} not found.");
                return self::FAILURE;
            }

            $this->recalcForEmployee($service, $employee);
            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $employees = PayrollEmployee::withoutGlobalScopes()
                ->where('is_active', true)
                ->whereNull('termination_date')
                ->get();

            $this->info("Recalculating balances for {$employees->count()} employees...");

            foreach ($employees as $emp) {
                $this->recalcForEmployee($service, $emp);
            }

            $this->info('Done.');
            return self::SUCCESS;
        }

        $this->error('Please specify --employee={id} or --all');
        return self::FAILURE;
    }

    private function recalcForEmployee(LeaveBalanceService $service, PayrollEmployee $employee): void
    {
        $types = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('is_active', true)
            ->get();

        foreach ($types as $type) {
            $ent = $service->refreshEntitlement($employee, $type);
            $this->line("  {$employee->user->name} — {$type->code}: accrued={$ent->accrued_days} taken={$ent->taken_days} available={$ent->available_days}");
        }
    }
}
