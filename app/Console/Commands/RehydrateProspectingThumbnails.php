<?php

namespace App\Console\Commands;

use App\Jobs\DownloadListingThumbnail;
use App\Models\ProspectingListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-22 item 7 — thumbnail rehydration backfill.
 *
 * ~4032 prospecting_listings rows carry a thumbnail_path but have ZERO files
 * on disk — Laravel 11 moved the `local` disk root to storage/app/private,
 * orphaning every thumbnail downloaded before the move. This command finds
 * those rows and, where the original source URL is known
 * (thumbnail_source_url), re-dispatches DownloadListingThumbnail to re-fetch
 * the image — no fresh portal capture required.
 *
 * Idempotent: only acts on rows whose stored file is genuinely missing AND
 * which have a source URL to re-fetch from. Re-running after a successful
 * pass is a no-op (the files now exist). Chunked to keep memory flat over the
 * full table. --dry-run reports the counts without dispatching.
 */
class RehydrateProspectingThumbnails extends Command
{
    protected $signature = 'prospecting:rehydrate-thumbnails
        {--dry-run : Report what would be re-fetched without dispatching any jobs}
        {--chunk=200 : Rows per chunk}';

    protected $description = 'Re-dispatch thumbnail downloads for prospecting_listings whose stored file is missing but the source URL is known (AT-22 item 7).';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $scanned       = 0;   // rows with a thumbnail_path examined
        $missingFile   = 0;   // path set but no file on disk
        $noSourceUrl   = 0;   // missing file AND no source URL → cannot rehydrate
        $reDispatched  = 0;   // jobs dispatched (or would-be in dry-run)

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Scanning prospecting_listings for orphaned thumbnails...');

        // Only rows that claim a thumbnail are candidates. chunkById keeps the
        // cursor stable while we (potentially) write thumbnail_path back.
        ProspectingListing::query()
            ->whereNotNull('thumbnail_path')
            ->where('thumbnail_path', '!=', '')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($listings) use (
                $dryRun, &$scanned, &$missingFile, &$noSourceUrl, &$reDispatched
            ) {
                foreach ($listings as $listing) {
                    $scanned++;

                    // File present → nothing to do (idempotent re-runs land here).
                    if (Storage::disk('local')->exists($listing->thumbnail_path)) {
                        continue;
                    }

                    $missingFile++;

                    $sourceUrl = $listing->thumbnail_source_url;
                    if (empty($sourceUrl)) {
                        // Cannot re-fetch without the original URL — needs a
                        // fresh capture to repopulate thumbnail_source_url.
                        $noSourceUrl++;
                        continue;
                    }

                    $reDispatched++;
                    if (! $dryRun) {
                        DownloadListingThumbnail::dispatch($listing, $sourceUrl);
                    }
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows with thumbnail_path scanned', $scanned],
                ['Missing file on disk',             $missingFile],
                ['Missing file, no source URL',      $noSourceUrl],
                [($dryRun ? 'Would re-dispatch' : 'Re-dispatched'), $reDispatched],
            ]
        );

        if ($noSourceUrl > 0) {
            $this->warn("{$noSourceUrl} orphaned row(s) have no thumbnail_source_url — they need a fresh portal capture to repopulate the URL before they can be rehydrated.");
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. Re-dispatched {$reDispatched} download job(s).");

        return self::SUCCESS;
    }
}
