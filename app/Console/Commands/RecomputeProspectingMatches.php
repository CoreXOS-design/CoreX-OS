<?php

namespace App\Console\Commands;

use App\Models\ContactMatch;
use App\Services\PropertyMatchScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecomputeProspectingMatches extends Command
{
    protected $signature = 'prospecting:recompute-matches
                            {--buyer= : Recompute for a specific contact_id only}
                            {--primary-only : Only iterate primary ContactMatches (faster)}
                            {--stale : Only buyers with a cached match NOT on the current matching rules (BuyerMatchRules::VERSION): restartable, it skips buyers already rebuilt}
                            {--dry-run : Show what would be processed without writing}';

    protected $description = 'Recompute prospecting_buyer_matches for buyers with active ContactMatches';

    public function handle(): int
    {
        $start = microtime(true);
        $svc = app(PropertyMatchScoringService::class);

        $dryRun = $this->option('dry-run');

        // Enumerate distinct contact_ids holding an active wishlist (spec D1).
        // The service de-duplicates per (listing_id, contact_id) internally
        // via best-score-wins across the contact's wishlists, so iterating
        // per contact (not per match) is sufficient and faster.
        $matchQuery = ContactMatch::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->whereHas('contact', fn ($q) => $q->where('is_buyer', true)->whereNull('deleted_at'));

        if ($this->option('primary-only')) {
            $matchQuery->where('is_primary', true);
        }

        if ($id = $this->option('buyer')) {
            $matchQuery->where('contact_id', (int) $id);
        }

        $contactIds = $matchQuery->distinct()->orderBy('contact_id')->pluck('contact_id');

        if ($this->option('stale')) {
            // A buyer is stale when ANY of his cached rows lacks the current rules marker (mic-work-tab.md 13.15 addendum). Every row a rebuild writes carries the marker,
            // so a buyer that was done drops out of this list: stop the command and start it again and it carries on with the ones that are left.
            $staleIds = \Illuminate\Support\Facades\DB::table('prospecting_buyer_matches')->whereIn('contact_id', $contactIds->all())
                ->where(fn ($q) => $q->whereNull('matched_features')
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(matched_features, '$.rules')) IS NULL")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(matched_features, '$.rules')) <> ?", [\App\Services\Matching\BuyerMatchRules::VERSION]))
                ->distinct()->pluck('contact_id')->map(fn ($v) => (int) $v)->flip();
            $contactIds = $contactIds->filter(fn ($id) => $staleIds->has((int) $id))->values();
        }

        $this->info("Processing {$contactIds->count()} buyer(s) with active wishlists" .
            ($this->option('primary-only') ? ' (primary only)' : '') . '...');

        if ($dryRun) {
            $this->warn('Dry run — no matches will be written.');
            foreach ($contactIds as $contactId) {
                $this->line("  Would process contact_id={$contactId}");
            }
            return self::SUCCESS;
        }

        $totalMatches = 0;
        $lines = (bool) $this->option('stale');   // a log you can tail: one line per buyer
        $bar = $lines ? null : $this->output->createProgressBar($contactIds->count());
        $n = 0;

        foreach ($contactIds as $contactId) {
            $t = microtime(true);
            try {
                $count = $svc->recomputeProspectingMatchesForBuyer((int) $contactId);
                $totalMatches += $count;
                if ($lines) {
                    $this->line(sprintf('%s  %d/%d  contact %d  %d matches  %.1fs', now()->format('H:i:s'), ++$n, $contactIds->count(), $contactId, $count, microtime(true) - $t));
                }
            } catch (\Throwable $e) {
                $this->warn("  Failed for contact_id={$contactId}: {$e->getMessage()}");
                ++$n;
            }
            $bar?->advance();
        }

        $bar?->finish();
        $this->newLine();

        $elapsed = round(microtime(true) - $start, 2);

        $this->info("Done. Buyers: {$contactIds->count()}, Matches: {$totalMatches}, Time: {$elapsed}s");

        Log::info('Prospecting match recompute completed', [
            'buyers_processed' => $contactIds->count(),
            'matches_created'  => $totalMatches,
            'elapsed_seconds'  => $elapsed,
            'primary_only'     => (bool) $this->option('primary-only'),
        ]);

        return self::SUCCESS;
    }
}
