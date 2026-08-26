<?php

declare(strict_types=1);

namespace App\Console\Commands\Deals;

use App\Services\Deals\DealPropertyLinkService;
use Illuminate\Console\Command;

/**
 * Phase 3i E1 — bulk link deals to properties.
 *
 *   php artisan deals:link-properties --agency=1
 *   php artisan deals:link-properties --agency=1 --dry-run
 *   php artisan deals:link-properties --agency=1 --limit=50
 *
 * Idempotent. Re-running picks up only deals where property_id is still NULL.
 */
final class LinkPropertiesCommand extends Command
{
    protected $signature = 'deals:link-properties
        {--agency= : Restrict to a single agency_id (otherwise all agencies)}
        {--dry-run : Report counts without writing any links or queue rows}
        {--limit= : Cap the number of deals processed (useful for first runs)}';

    protected $description = 'Phase 3i — backfill deals.property_id via address normalisation match.';

    public function handle(DealPropertyLinkService $svc): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $limit    = $this->option('limit')   !== null ? (int) $this->option('limit')   : null;
        $dryRun   = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Linking deals → properties [%s%s%s]…',
            $agencyId !== null ? "agency={$agencyId} " : 'all-agencies ',
            $limit    !== null ? "limit={$limit} " : '',
            $dryRun ? '[DRY RUN]' : '[LIVE]',
        ));

        $summary = $svc->backfillAll($agencyId, $dryRun, $limit);

        $this->table(['Outcome', 'Count'], [
            ['Total processed',                $summary['total']],
            ['Already linked (skipped)',       $summary['already_linked']],
            ['Linked: exact confidence',       $summary['linked_exact']],
            ['Linked: high confidence',        $summary['linked_high']],
            ['Linked: medium confidence',      $summary['linked_with_review_flag']],
            ['Ambiguous / low confidence (not linked)', $summary['queued_for_review']],
            ['No candidates found',            $summary['no_candidates']],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }
}
