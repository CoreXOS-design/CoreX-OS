<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Presentation\PropertyCmaPropagationService;
use Illuminate\Console\Command;

/**
 * One-shot backfill: run PropertyCmaPropagationService across every presentation
 * with extracted fields. Idempotent — safe to re-run.
 *
 * Use cases:
 *   - php artisan cma:backfill --agency=1
 *       (HFC only, address+erf matching enabled)
 *   - php artisan cma:backfill --no-address-match
 *       (only presentations with direct listing_id or snapshot-pivot linkage)
 *   - php artisan cma:backfill --dry-run
 *       (no writes; reports what would happen)
 */
final class BackfillPropertyCmaFromPresentations extends Command
{
    protected $signature = 'cma:backfill
                            {--agency= : Limit to a specific agency_id}
                            {--no-address-match : Disable address-based matching}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Backfill CMA-extracted fields from existing presentations to the Property pillar';

    public function handle(PropertyCmaPropagationService $service): int
    {
        $agencyId = $this->option('agency') ? (int) $this->option('agency') : null;
        $allowAddressMatch = !$this->option('no-address-match');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('CMA backfill' .
            ($agencyId !== null ? " · agency #{$agencyId}" : ' · all agencies') .
            ($allowAddressMatch ? ' · address matching ON' : ' · address matching OFF') .
            ($dryRun ? ' · DRY RUN' : ''));

        if ($dryRun) {
            // Dry-run path: count would-be candidates without invoking propagation
            // logic (since propagation always writes when a match is found).
            // To compute "would update" precisely, we'd need to dry-mode the service —
            // for v1, we report just the candidate set size.
            $candidates = \Illuminate\Support\Facades\DB::table('presentations')
                ->join('presentation_fields', 'presentation_fields.presentation_id', '=', 'presentations.id')
                ->whereNull('presentations.deleted_at')
                ->whereNull('presentation_fields.deleted_at')
                ->when($agencyId !== null, function ($q) use ($agencyId) {
                    $q->leftJoin('branches', 'branches.id', '=', 'presentations.branch_id')
                      ->where(function ($qq) use ($agencyId) {
                          $qq->where('presentations.agency_id', $agencyId)
                             ->orWhere('branches.agency_id', $agencyId);
                      });
                })
                ->distinct()
                ->count('presentations.id');

            $this->warn("Dry-run: {$candidates} presentation(s) have extraction data and would be evaluated.");
            $this->warn('No writes performed.');
            return self::SUCCESS;
        }

        $stats = $service->backfillAll($agencyId, $allowAddressMatch);

        $this->table(
            ['Total', 'Updated', 'Skipped', 'No Linked Property', 'No Extraction Data', 'Errors'],
            [[
                $stats['total'],
                $stats['updated'],
                $stats['skipped'],
                $stats['no_linked'],
                $stats['no_data'],
                $stats['errors'],
            ]]
        );

        if (!empty($stats['updated_property_ids'])) {
            $this->info('Properties updated: #' . implode(', #', $stats['updated_property_ids']));
        }

        return self::SUCCESS;
    }
}
