<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\LeaveAccrualService;
use Illuminate\Console\Command;

class AccrueDailyCommand extends Command
{
    protected $signature = 'corex:leave:accrue-daily {--employee=} {--dry-run}';
    protected $description = 'Run daily leave accrual for all active employees';

    public function handle(): int
    {
        $service = new LeaveAccrualService();
        $isDryRun = $this->option('dry-run');
        $employeeId = $this->option('employee');

        if ($isDryRun) {
            $this->info('[DRY RUN] No transactions will be written.');
        }

        if ($employeeId) {
            $employee = \App\Models\Payroll\PayrollEmployee::find($employeeId);
            if (!$employee) {
                $this->error("Employee {$employeeId} not found.");
                return self::FAILURE;
            }

            if ($isDryRun) {
                $this->info("Would process: {$employee->user->name} ({$employee->id})");
                return self::SUCCESS;
            }

            $result = $service->accrueForEmployee($employee);
            $this->info("{$employee->user->name}: {$result['transactions_created']} transactions");
            foreach ($result['types_processed'] as $t) {
                $this->line("  {$t['code']}: {$t['before']} -> {$t['after']} (delta {$t['delta']})");
            }

            return self::SUCCESS;
        }

        $employees = \App\Models\Payroll\PayrollEmployee::withoutGlobalScopes()
            ->where('is_active', true)->whereNull('termination_date')->count();
        $this->info("[INFO] Starting daily accrual for {$employees} employees as of " . now()->subDay()->format('Y-m-d'));

        if ($isDryRun) {
            $this->info("[DRY RUN] Would process {$employees} employees.");
            return self::SUCCESS;
        }

        $result = $service->accrueAll();
        $this->info("[SUCCESS] Done. {$result['total_employees']} employees, {$result['total_transactions']} transactions, " . count($result['errors']) . " errors");

        foreach ($result['errors'] as $err) {
            $this->error("  Employee {$err['employee_id']}: {$err['error']}");
        }

        return self::SUCCESS;
    }
}
