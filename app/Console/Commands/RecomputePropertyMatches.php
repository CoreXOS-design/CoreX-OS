<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\PropertyMatchScoringService;
use Illuminate\Console\Command;

class RecomputePropertyMatches extends Command
{
    protected $signature = 'matches:recompute {--buyer= : Specific buyer contact ID}';
    protected $description = 'Recompute property match scores for buyers against active listings';

    public function handle(): int
    {
        $service = app(PropertyMatchScoringService::class);

        $query = Contact::withoutGlobalScopes()->where('is_buyer', true)->whereNull('deleted_at');
        if ($id = $this->option('buyer')) {
            $query->where('id', (int) $id);
        }

        $buyers = $query->get();
        $this->info("Recomputing matches for {$buyers->count()} buyers...");
        $total = 0;

        foreach ($buyers as $buyer) {
            $count = $service->recomputeForBuyer($buyer->id);
            $total += $count;
        }

        $this->info("Done. {$total} match rows written.");
        return 0;
    }
}
