<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AT-520 4.3 (mic-work-tab.md 11.3.B): adverts the importer re-activated (`is_active = 1`) but left labelled `withdrawn` (4,286 PP adverts on QA1,
 * 31 Oct 2026) go back to `active`: label cleared, off_market_at cleared. Soft only; every changed row is journalled (id, old status, old
 * off_market_at). Dry run by default.
 *
 *   php artisan mic:fix-stuck-withdrawn --dry-run | --apply [--agency=] [--journal-dir=]
 */
final class FixStuckWithdrawn extends Command
{
    protected $signature = 'mic:fix-stuck-withdrawn
        {--dry-run : Count and save nothing (the default)}
        {--apply : Clear the label on the stuck adverts}
        {--agency= : One agency id (default: every agency)}
        {--journal-dir= : Where the row journal is written (default: storage/app/reports)}';

    protected $description = 'Adverts that are active again but still labelled withdrawn go back to active (row journal)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $q = ProspectingListing::withoutGlobalScopes()->where('is_active', true)->where('portal_status', ProspectingListing::PORTAL_STATUS_WITHDRAWN);
        if ($this->option('agency')) {
            $q->where('agency_id', (int) $this->option('agency'));
        }
        $byAgency = (clone $q)->selectRaw('agency_id, portal_source, COUNT(*) as n')->groupBy('agency_id', 'portal_source')->get();
        foreach ($byAgency as $r) {
            $this->line("agency {$r->agency_id} {$r->portal_source}: {$r->n} stuck");
        }
        $total = (int) $byAgency->sum('n');
        if (! $apply) {
            $this->comment("Dry run: {$total} adverts would go back to active. Nothing was written.");

            return self::SUCCESS;
        }

        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $path = $dir . '/mic-fix-stuck-withdrawn-journal-' . now()->format('Ymd-His') . '.jsonl';
        $fh = fopen($path, 'ab');
        $done = 0;
        $q->orderBy('id')->chunkById(500, function ($rows) use ($fh, &$done) {
            DB::transaction(function () use ($rows, $fh, &$done) {
                foreach ($rows as $l) {
                    fwrite($fh, json_encode(['table' => 'prospecting_listings', 'id' => (int) $l->id, 'old_portal_status' => $l->portal_status,
                        'old_off_market_at' => $l->off_market_at ? (string) $l->off_market_at : null, 'old_portal_status_changed_at' => $l->portal_status_changed_at ? (string) $l->portal_status_changed_at : null]) . "\n");
                    ProspectingListing::withoutGlobalScopes()->whereKey($l->id)->update([
                        'portal_status' => ProspectingListing::PORTAL_STATUS_ACTIVE, 'portal_status_changed_at' => now(), 'off_market_at' => null,
                    ]);
                    $done++;
                }
            });
        });
        fclose($fh);
        $this->info("Applied: {$done} adverts back to active. Journal: {$path}");

        return self::SUCCESS;
    }
}
