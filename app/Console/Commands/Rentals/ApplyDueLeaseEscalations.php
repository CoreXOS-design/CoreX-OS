<?php

namespace App\Console\Commands\Rentals;

use App\Models\Lease;
use App\Models\LeaseEscalation;
use Illuminate\Console\Command;

/**
 * A rent escalation recorded with a future effective date is stored
 * immediately but only changes the lease's rental_amount once that date
 * arrives (LeaseController::escalate()). This daily scan applies the ones
 * that have come due. Idempotent; never touches cancelled/expired leases.
 */
class ApplyDueLeaseEscalations extends Command
{
    protected $signature = 'leases:apply-due-escalations';

    protected $description = 'Apply lease rent escalations whose effective date has arrived';

    public function handle(): int
    {
        $leaseIds = LeaseEscalation::whereDate('effective_date', '<=', now()->toDateString())
            ->distinct()->pluck('lease_id');

        $applied = 0;

        Lease::withoutGlobalScopes()
            ->whereIn('id', $leaseIds)
            ->whereIn('status', [Lease::STATUS_ACTIVE, Lease::STATUS_DRAFT])
            ->get()
            ->each(function (Lease $lease) use (&$applied) {
                if ($lease->applyDueEscalation()) {
                    $applied++;
                }
            });

        $this->info("Applied {$applied} due lease escalation(s).");

        return self::SUCCESS;
    }
}
