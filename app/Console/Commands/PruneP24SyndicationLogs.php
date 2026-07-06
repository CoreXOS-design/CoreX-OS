<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention prune for p24_syndication_logs. The table grows unbounded (one row
 * per P24 API call, some historically carrying large response payloads) and had
 * reached multiple GB — larger than the InnoDB buffer pool, so every write to it
 * evicts hot pages and drags the whole DB. This keeps only a recent window.
 *
 * Deletes in batches with a short pause so it never holds a long lock or churns
 * the buffer pool in one shot. Disk is reclaimed lazily as InnoDB reuses freed
 * pages; run OPTIMIZE TABLE in a maintenance window to shrink the file outright.
 *
 *   php artisan p24:prune-logs                 # keep 45 days, batches of 5000
 *   php artisan p24:prune-logs --days=30 --dry-run
 */
class PruneP24SyndicationLogs extends Command
{
    protected $signature = 'p24:prune-logs
        {--days=45 : Keep rows newer than this many days}
        {--max-kb= : Also delete rows whose request/response payload exceeds this size (KB), any age — the pre-strip-fix photo-base64 bloat}
        {--batch=200 : Rows deleted per batch}
        {--sleep=200 : Milliseconds to pause between batches}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old / oversized p24_syndication_logs rows in safe batches.';

    public function handle(): int
    {
        $batch = max(50, (int) $this->option('batch'));
        $sleep = max(0, (int) $this->option('sleep')) * 1000;
        $dry   = (bool) $this->option('dry-run');

        // Pass 1 — date retention.
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $this->pruneWhere(
            "older than {$days}d",
            fn ($q) => $q->where('created_at', '<', $cutoff),
            $batch, $sleep, $dry
        );

        // Pass 2 — oversized-payload rows (the photo-base64 bloat left by submits
        // logged before the strip fix). BLANK the payload but KEEP the audit row
        // (action, status_code, round_trip, created_at). Guarded to rows >1 day
        // old so nothing in flight is touched.
        if ($this->option('max-kb') !== null) {
            $bytes = max(10240, (int) $this->option('max-kb') * 1024);
            $this->blankOversized($bytes, $batch, $sleep, $dry);
        }

        return self::SUCCESS;
    }

    private function blankOversized(int $bytes, int $batch, int $sleep, bool $dry): void
    {
        $filter = fn ($q) => $q->where('created_at', '<', now()->subDay())
            ->whereRaw('(LENGTH(request_payload) + COALESCE(LENGTH(response_payload),0)) > ?', [$bytes]);

        $count = $filter(DB::table('p24_syndication_logs'))->count();
        $this->info('p24_syndication_logs oversized payload (>' . round($bytes / 1024) . 'KB, >1d old): ' . $count . ' rows.');

        if ($dry || $count === 0) {
            if ($dry) $this->line('  (dry run — payloads would be blanked, audit rows kept)');
            return;
        }

        $blanked = 0;
        $placeholder = json_encode(['_pruned' => 'payload removed by p24:prune-logs (photo-base64 bloat)']);
        do {
            // Grab a batch of ids, then blank them — LIMIT on UPDATE with subquery
            // avoids a single 6GB write and keeps locks short.
            $ids = $filter(DB::table('p24_syndication_logs'))->limit($batch)->pluck('id');
            if ($ids->isEmpty()) break;
            $n = DB::table('p24_syndication_logs')->whereIn('id', $ids)->update([
                'request_payload'  => $placeholder,
                'response_payload' => DB::raw("JSON_OBJECT('_pruned', 1)"),
            ]);
            $blanked += $ids->count();
            $this->line("  blanked {$blanked}/{$count}…");
            if ($sleep > 0) usleep($sleep);
        } while (true);

        $this->info("  done — blanked {$blanked} payloads (audit rows kept). OPTIMIZE TABLE in a maintenance window reclaims file space.");
    }

    private function pruneWhere(string $label, \Closure $filter, int $batch, int $sleep, bool $dry): void
    {
        $count = $filter(DB::table('p24_syndication_logs'))->count();
        $this->info("p24_syndication_logs {$label}: {$count} rows.");

        if ($dry || $count === 0) {
            if ($dry) $this->line('  (dry run — not deleted)');
            return;
        }

        $deleted = 0;
        do {
            $n = $filter(DB::table('p24_syndication_logs'))->limit($batch)->delete();
            $deleted += $n;
            if ($n > 0) {
                $this->line("  deleted {$deleted}/{$count}…");
                if ($sleep > 0) usleep($sleep);
            }
        } while ($n > 0);

        $this->info("  done — pruned {$deleted} rows. (OPTIMIZE TABLE in a maintenance window reclaims file space.)");
    }
}
