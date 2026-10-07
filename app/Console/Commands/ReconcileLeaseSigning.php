<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Lease;
use Illuminate\Console\Command;

/**
 * .ai/specs/leases.md §15.15 (Build L3b) — the nightly half of the safety net. The e-sign engine announces what
 * happens to a lease's agreement and a listener keeps the lease in step; this re-reads every lease whose agreement is
 * still in flight and repairs any announcement that was missed (a listener fault, a queue that was down, a path that
 * never announced). The same re-check runs whenever the Lease Hub, the Leases list or the Command Centre opens such a
 * lease; this catches the ones nobody opens.
 *
 * Agency by agency, never one query across all of them (the CheckLeaseExpiry rule). Idempotent: a lease already in
 * step with its envelope is left exactly as it is.
 */
class ReconcileLeaseSigning extends Command
{
    protected $signature = 'leases:reconcile-signing';

    protected $description = 'Re-check every lease whose e-sign agreement is still in flight and bring it in step with the envelope';

    public function handle(): int
    {
        $checked = 0;
        $changed = 0;

        foreach (Agency::all() as $agency) {
            Lease::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->whereIn('signing_status', [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW])
                ->whereNotNull('signature_template_id')
                ->orderBy('id')
                ->each(function (Lease $lease) use (&$checked, &$changed) {
                    $checked++;
                    if ($lease->reconcileSigning()) {
                        $changed++;
                        $this->line("  lease #{$lease->id}: now {$lease->signing_status}");
                    }
                });
        }

        $this->info("Checked {$checked} lease agreement(s) in flight; {$changed} brought in step.");

        return self::SUCCESS;
    }
}
