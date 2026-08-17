<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Payroll\PayrollDeductionType;
use App\Models\Payroll\PayrollEarningType;
use Illuminate\Console\Command;

/**
 * Payroll onboarding (#20) — provision the SA-standard default earning + deduction
 * types for an agency. New agencies get these automatically via AgencyObserver; this
 * command backfills EXISTING agencies that predate that wiring (or that lack types),
 * and is safe to re-run — PayrollEarning/DeductionType::seedDefaultsFor() is
 * idempotent (firstOrCreate on agency_id+code), so it never duplicates or overwrites.
 */
class PayrollSeedDefaultTypes extends Command
{
    protected $signature = 'payroll:seed-default-types
                            {agency? : The agency id to seed (omit and pass --all for every agency)}
                            {--all : Seed default types for every agency}';

    protected $description = 'Seed SA-standard default payroll earning + deduction types for an agency (idempotent).';

    public function handle(): int
    {
        $all      = (bool) $this->option('all');
        $agencyId = $this->argument('agency');

        if (! $all && ! $agencyId) {
            $this->error('Pass an agency id (e.g. `payroll:seed-default-types 7`) or --all.');
            return self::FAILURE;
        }

        if ($all) {
            $agencies = Agency::withoutGlobalScopes()->orderBy('id')->get();
        } else {
            $agency = Agency::withoutGlobalScopes()->find($agencyId);
            if (! $agency) {
                $this->error("Agency #{$agencyId} not found.");
                return self::FAILURE;
            }
            $agencies = collect([$agency]);
        }

        $rows = [];
        $totEarnNew = 0;
        $totDedNew  = 0;

        foreach ($agencies as $agency) {
            $earn = PayrollEarningType::seedDefaultsFor((int) $agency->id);
            $ded  = PayrollDeductionType::seedDefaultsFor((int) $agency->id);
            $totEarnNew += $earn['created'];
            $totDedNew  += $ded['created'];

            $rows[] = [
                $agency->id,
                (string) ($agency->name ?? ''),
                "{$earn['created']} new / {$earn['existing']} kept",
                "{$ded['created']} new / {$ded['existing']} kept",
            ];
        }

        $this->table(['Agency', 'Name', 'Earning types', 'Deduction types'], $rows);
        $this->info(sprintf(
            'Done. %d agenc%s processed — %d earning + %d deduction types newly created (existing rows untouched).',
            $agencies->count(),
            $agencies->count() === 1 ? 'y' : 'ies',
            $totEarnNew,
            $totDedNew
        ));

        return self::SUCCESS;
    }
}
