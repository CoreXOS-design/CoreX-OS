<?php

namespace App\Console\Commands;

use App\Services\PropertyMatchScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecomputeProspectingMatches extends Command
{
    protected $signature = 'prospecting:recompute-matches
                            {--buyer= : Recompute for a specific contact_id only}
                            {--dry-run : Show what would be processed without writing}';

    protected $description = 'Recompute prospecting buyer matches for all buyers with preferences (or a specific buyer)';

    public function handle(): int
    {
        $start = microtime(true);
        $svc = app(PropertyMatchScoringService::class);

        $specificBuyer = $this->option('buyer');
        $dryRun = $this->option('dry-run');

        if ($specificBuyer) {
            $buyers = DB::table('buyer_preferences')
                ->where('contact_id', (int) $specificBuyer)
                ->get(['contact_id']);
        } else {
            $buyers = DB::table('buyer_preferences')
                ->join('contacts', 'contacts.id', '=', 'buyer_preferences.contact_id')
                ->where('contacts.is_buyer', true)
                ->whereNull('contacts.deleted_at')
                ->get(['buyer_preferences.contact_id']);
        }

        $this->info("Processing {$buyers->count()} buyer(s) with preferences...");

        if ($dryRun) {
            $this->warn('Dry run — no matches will be written.');
            foreach ($buyers as $b) {
                $this->line("  Would process contact_id={$b->contact_id}");
            }
            return self::SUCCESS;
        }

        $totalMatches = 0;
        $bar = $this->output->createProgressBar($buyers->count());

        foreach ($buyers as $buyer) {
            try {
                $count = $svc->recomputeProspectingMatchesForBuyer($buyer->contact_id);
                $totalMatches += $count;
            } catch (\Throwable $e) {
                $this->warn("  Failed for contact_id={$buyer->contact_id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $elapsed = round(microtime(true) - $start, 2);

        $this->info("Done. Buyers: {$buyers->count()}, Matches: {$totalMatches}, Time: {$elapsed}s");

        Log::info('Prospecting match recompute completed', [
            'buyers_processed' => $buyers->count(),
            'matches_created' => $totalMatches,
            'elapsed_seconds' => $elapsed,
        ]);

        return self::SUCCESS;
    }
}
