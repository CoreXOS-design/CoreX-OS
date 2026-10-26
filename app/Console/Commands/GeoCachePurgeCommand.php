<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Geocoding\GeocodeCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11a B — daily purge of expired geocoding_cache rows.
 *
 *   php artisan geo:cache-purge                       (expired rows — the scheduled run)
 *   php artisan geo:cache-purge --out-of-bbox         (DRY RUN: counts the google:out_of_bbox failures, deletes nothing)
 *   php artisan geo:cache-purge --out-of-bbox --apply (deletes ONLY those, nothing else)
 *
 * Wired into the scheduler at 03:00 SAST in routes/console.php (no options). The --out-of-bbox mode is
 * the one-off clean-up after the accepted-area fix (spec: geocoding-spec.md "Accepted area"): while the
 * area was a hard-coded KZN box, every address outside it was cached as a definitive failure and kept
 * being served. Those rows are deleted so the address is asked again. Rows with any other failure
 * reason, and every successful row, are never touched by it.
 */
final class GeoCachePurgeCommand extends Command
{
    protected $signature = 'geo:cache-purge
        {--out-of-bbox : Purge only the cached google:out_of_bbox failures (not the expired-rows purge)}
        {--apply : With --out-of-bbox: really delete (without it the command only counts)}
        {--dry-run : Count what would be deleted and delete nothing}';

    protected $description = 'Hard-delete expired geocoding_cache rows, or (--out-of-bbox) only the cached google:out_of_bbox failures.';

    public function handle(GeocodeCache $cache): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($this->option('out-of-bbox')) {
            // The clean-up is dry-run by default: it deletes only when told to with --apply.
            $dry = $dry || !$this->option('apply');
            $query = DB::table('geocoding_cache')->where('failure_reason', 'google:out_of_bbox');
            $count = (clone $query)->count();

            if ($dry) {
                $this->line("geo:cache-purge --out-of-bbox (dry run) — {$count} google:out_of_bbox row(s) would be deleted; nothing changed.");

                return self::SUCCESS;
            }

            $deleted = $query->delete();
            $this->line("geo:cache-purge --out-of-bbox — {$deleted} google:out_of_bbox row(s) deleted.");
            Log::channel('geocoding')->info('geo:cache-purge --out-of-bbox run', ['rows_deleted' => $deleted]);

            return self::SUCCESS;
        }

        if ($dry) {
            $count = DB::table('geocoding_cache')->whereNotNull('expires_at')->where('expires_at', '<', now())->count();
            $this->line("geo:cache-purge (dry run) — {$count} expired row(s) would be deleted; nothing changed.");

            return self::SUCCESS;
        }

        $deleted = $cache->purgeExpired();

        $this->line("geo:cache-purge — {$deleted} expired row(s) deleted.");

        Log::channel('geocoding')->info('geo:cache-purge command run', [
            'rows_deleted' => $deleted,
        ]);

        return self::SUCCESS;
    }
}
