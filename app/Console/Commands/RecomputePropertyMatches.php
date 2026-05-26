<?php

namespace App\Console\Commands;

use App\Models\ContactMatch;
use App\Services\PropertyMatchScoringService;
use Illuminate\Console\Command;

class RecomputePropertyMatches extends Command
{
    protected $signature = 'matches:recompute
                            {--buyer= : Specific buyer contact ID}
                            {--primary-only : Only iterate primary ContactMatches (faster, demand-intelligence parity)}';

    protected $description = 'Recompute property_buyer_matches for buyers with active ContactMatches';

    public function handle(): int
    {
        $service = app(PropertyMatchScoringService::class);

        // Iterate distinct contact_ids holding at least one active ContactMatch.
        // The service de-duplicates per (contact_id, property_id) internally
        // (best-score-wins across the contact's wishlists), so iterating per
        // contact rather than per match avoids redundant rescoring.
        $matchQuery = ContactMatch::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE);

        if ($this->option('primary-only')) {
            $matchQuery->where('is_primary', true);
        }

        if ($id = $this->option('buyer')) {
            $matchQuery->where('contact_id', (int) $id);
        }

        $contactIds = $matchQuery->distinct()->pluck('contact_id');

        $this->info("Recomputing matches for {$contactIds->count()} buyer(s)" .
            ($this->option('primary-only') ? ' (primary wishlists only)' : '') . '...');

        $total = 0;
        foreach ($contactIds as $contactId) {
            $count = $service->recomputeForBuyer((int) $contactId);
            $total += $count;
        }

        $this->info("Done. {$total} match rows written.");
        return self::SUCCESS;
    }
}
