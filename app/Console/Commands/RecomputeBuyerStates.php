<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\BuyerStateService;
use Illuminate\Console\Command;

class RecomputeBuyerStates extends Command
{
    protected $signature = 'buyers:recompute-states {--dry-run : Show transitions without applying}';
    protected $description = 'Recompute buyer lifecycle states based on last_activity_at and agency thresholds';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $service = app(BuyerStateService::class);

        $buyers = Contact::withoutGlobalScopes()
            ->where('is_buyer', true)
            ->whereNull('deleted_at')
            ->whereNull('purged_at')
            ->get();

        $this->info("Processing {$buyers->count()} buyers...");
        $transitions = 0;
        $protected = 0;
        $won = 0;

        foreach ($buyers as $contact) {
            // AT-500 — every rule about what the recompute may touch (Won is terminal, a recent
            // manual placement is protected) lives in BuyerStateService::nextAutoState(); this
            // command no longer carries its own copy of the rules.
            if ($contact->buyer_state === BuyerStateService::WON) {
                $won++;
                continue;
            }
            if ($service->isManualPlacementProtected($contact)) {
                $protected++;
                continue;
            }

            $newState = $service->nextAutoState($contact);
            if ($newState === null) {
                continue;
            }

            if ($dryRun) {
                $this->line("  {$contact->full_name}: {$contact->buyer_state} → {$newState}");
            } else {
                $service->recomputeState($contact);
            }
            $transitions++;
        }

        $this->info("{$won} Won buyers left alone (Won is terminal).");
        $this->info("{$protected} buyers protected by a recent manual placement (skipped).");
        $this->info("{$transitions} state transitions " . ($dryRun ? 'would be applied.' : 'applied.'));
        if ($dryRun) {
            $this->warn('DRY RUN — no changes made.');
        }

        return 0;
    }
}
