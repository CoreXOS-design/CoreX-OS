<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Geocoding\GeocodeCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11a B — daily purge of expired geocoding_cache rows.
 *
 *   php artisan geo:cache-purge
 *
 * Wired into the scheduler at 03:00 SAST in routes/console.php. Safe to run
 * manually — purgeExpired() targets only rows past their expires_at. The
 * purge total is echoed to stdout and logged to the geocoding channel.
 */
final class GeoCachePurgeCommand extends Command
{
    protected $signature = 'geo:cache-purge';

    protected $description = 'Hard-delete expired geocoding_cache rows (past expires_at).';

    public function handle(GeocodeCache $cache): int
    {
        $deleted = $cache->purgeExpired();

        $this->line("geo:cache-purge — {$deleted} expired row(s) deleted.");

        Log::channel('geocoding')->info('geo:cache-purge command run', [
            'rows_deleted' => $deleted,
        ]);

        return self::SUCCESS;
    }
}
