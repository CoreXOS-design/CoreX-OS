<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RegenerateBuyerMatchesJob;
use Illuminate\Console\Command;

/**
 * Dispatch RegenerateBuyerMatchesJob to rebuild the match cache tables.
 *
 * Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 9.
 *
 * Default: dispatches to the queue (process via `php artisan queue:work`).
 * --sync: runs the job synchronously in-process. Useful for the post-Prompt-08
 *         master rebuild and for dev verification.
 */
class WishlistRegenerateMatches extends Command
{
    protected $signature = 'wishlist:regenerate-matches
                            {--agency= : Limit to a single agency_id}
                            {--contact= : Limit to a single contact_id}
                            {--no-truncate : Upsert without clearing existing rows first}
                            {--sync : Run synchronously in this process (default: dispatch to queue)}';

    protected $description = 'Rebuild prospecting_buyer_matches + property_buyer_matches against the current ContactMatch source of truth.';

    public function handle(): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $contactId = $this->option('contact') !== null ? (int) $this->option('contact') : null;
        $truncate = !$this->option('no-truncate');
        $sync = (bool) $this->option('sync');

        $job = new RegenerateBuyerMatchesJob(
            agencyId: $agencyId,
            contactId: $contactId,
            truncate: $truncate,
        );

        $this->line("Regen scope: " .
            ($contactId !== null ? "contact_id={$contactId}"
                : ($agencyId !== null ? "agency_id={$agencyId}" : 'all agencies'))
            . ($truncate ? ' (truncate scope first)' : ' (upsert only)'));

        if ($sync) {
            $this->line('Running synchronously...');
            $job->handle(app(\App\Services\PropertyMatchScoringService::class));
            $this->info('Done. Review the audit:');
            $this->line("  domain_event_log WHERE event_name LIKE 'wishlist.regeneration.%' ORDER BY id DESC LIMIT 2");
        } else {
            dispatch($job);
            $this->info('Dispatched to queue. Monitor via:');
            $this->line('  php artisan queue:work --once');
        }

        return self::SUCCESS;
    }
}
