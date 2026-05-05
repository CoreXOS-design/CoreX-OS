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

        foreach ($buyers as $contact) {
            $currentState = $contact->buyer_state;
            $newState = $service->resolveState($contact);

            if ($newState && $newState !== $currentState) {
                if ($dryRun) {
                    $this->line("  {$contact->full_name}: {$currentState} → {$newState}");
                } else {
                    $service->transitionTo($contact, $newState, 'auto_recompute');
                }
                $transitions++;
            }
        }

        $this->info("{$transitions} state transitions " . ($dryRun ? 'would be applied.' : 'applied.'));
        if ($dryRun) {
            $this->warn('DRY RUN — no changes made.');
        }

        return 0;
    }
}
