<?php

namespace App\Console\Commands;

use App\Jobs\DownloadListingThumbnail;
use App\Models\ProspectingListing;
use App\Services\Prospecting\PortalImageUrlResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-22 item 7 (+ item 2 recovery) — thumbnail rehydration backfill.
 *
 * ~4032 prospecting_listings rows carry a thumbnail_path but have ZERO files
 * on disk — Laravel 11 moved the `local` disk root to storage/app/private,
 * orphaning every thumbnail downloaded before the move. This command finds
 * those rows and re-fetches the image — no fresh portal capture required.
 *
 * TWO recovery sources, in priority order:
 *   1. thumbnail_source_url — the original portal image URL, when known.
 *   2. portal_url → og:image (--derive-from-portal) — for the 4032 rows whose
 *      source_url is null (the column post-dates them), fetch the Property24 /
 *      PrivateProperty listing page and read its Open Graph image. 100% of
 *      those rows have a portal_url, so this is the path that actually recovers
 *      live data. The resolved URL is persisted to thumbnail_source_url so the
 *      row is rehydratable thereafter. THROTTLED (--throttle-ms) because it is
 *      outbound traffic to the portals at scale.
 *
 * Every download flows through DownloadListingThumbnail → ListingImageValidator
 * content gate (AT-22 item 2), so a recovered image that is in fact a competitor
 * brand card is blocked, not shown.
 *
 * Idempotent: only acts on rows whose stored file is genuinely missing. A row
 * recovered once has a file on disk (and now a source_url) so re-runs skip it.
 * Chunked to keep memory flat. --dry-run reports counts without any network or
 * dispatch. --sync runs the downloads in-process (no queue worker required) —
 * recommended for a controlled backfill.
 */
class RehydrateProspectingThumbnails extends Command
{
    protected $signature = 'prospecting:rehydrate-thumbnails
        {--dry-run : Report what would be re-fetched without any network call or dispatch}
        {--derive-from-portal : For rows with no source_url, resolve the image from portal_url (og:image). Outbound; throttled.}
        {--sync : Run downloads synchronously in-process instead of queueing (no worker needed)}
        {--throttle-ms=1500 : Milliseconds to pause before each row that touches the network}
        {--limit=0 : Cap the number of network-touching rows processed this run (0 = no cap)}
        {--chunk=200 : Rows per chunk}';

    protected $description = 'Re-fetch missing prospecting thumbnails from thumbnail_source_url or (—derive-from-portal) the portal page og:image; throttled, content-gated (AT-22 items 2 + 7).';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $derive     = (bool) $this->option('derive-from-portal');
        $sync       = (bool) $this->option('sync');
        $throttleMs = max(0, (int) $this->option('throttle-ms'));
        $limit      = max(0, (int) $this->option('limit'));
        $chunkSize  = max(1, (int) $this->option('chunk'));

        $resolver = new PortalImageUrlResolver();

        $scanned       = 0;   // rows with a thumbnail_path examined
        $missingFile   = 0;   // path set but no file on disk
        $derived       = 0;   // source_url recovered from portal og:image
        $deriveFailed  = 0;   // portal fetch / og:image resolution failed
        $noSourceUrl   = 0;   // missing file AND no source URL AND not derivable
        $reDispatched  = 0;   // download jobs run/dispatched (or would-be in dry-run)
        $networkRows   = 0;   // rows that touched the network this run (for --limit)

        $this->info(($dryRun ? '[DRY RUN] ' : '')
            . 'Scanning prospecting_listings for orphaned thumbnails'
            . ($derive ? ' (deriving missing source URLs from portal og:image)' : '') . '...');

        ProspectingListing::query()
            ->whereNotNull('thumbnail_path')
            ->where('thumbnail_path', '!=', '')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($listings) use (
                $resolver, $dryRun, $derive, $sync, $throttleMs, $limit,
                &$scanned, &$missingFile, &$derived, &$deriveFailed,
                &$noSourceUrl, &$reDispatched, &$networkRows
            ) {
                foreach ($listings as $listing) {
                    $scanned++;

                    // File present → nothing to do (idempotent re-runs land here).
                    if (Storage::disk('local')->exists($listing->thumbnail_path)) {
                        continue;
                    }

                    $missingFile++;

                    $sourceUrl = $listing->thumbnail_source_url;
                    $needsDerive = empty($sourceUrl) && $derive;

                    // A row that has neither a source_url nor a derive path is a
                    // dead end — count and skip WITHOUT consuming the network cap.
                    if (empty($sourceUrl) && ! $needsDerive) {
                        $noSourceUrl++;
                        continue;
                    }

                    // From here the row WILL touch the portals (derive and/or
                    // download). Respect the per-run network cap and throttle.
                    if ($limit > 0 && $networkRows >= $limit) {
                        continue;
                    }
                    $this->pause($throttleMs, $networkRows);
                    $networkRows++;

                    // PATH 2 — derive the source URL from the portal page og:image.
                    if ($needsDerive) {
                        if ($dryRun) {
                            // No network in dry-run; portal_url presence is the proxy.
                            if (! empty($listing->portal_url)) {
                                $derived++;
                                $reDispatched++;
                            } else {
                                $noSourceUrl++;
                            }
                            continue;
                        }

                        $sourceUrl = $resolver->resolveForListing($listing);
                        if (empty($sourceUrl)) {
                            $deriveFailed++;
                            continue;
                        }

                        // Persist so the row is rehydratable from now on.
                        $listing->thumbnail_source_url = $sourceUrl;
                        $listing->save();
                        $derived++;
                    }

                    // Download (PATH 1, or the second leg of PATH 2).
                    $reDispatched++;
                    if (! $dryRun) {
                        if ($sync) {
                            DownloadListingThumbnail::dispatchSync($listing, $sourceUrl);
                        } else {
                            DownloadListingThumbnail::dispatch($listing, $sourceUrl);
                        }
                    }
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows with thumbnail_path scanned', $scanned],
                ['Missing file on disk',             $missingFile],
                ['Source URL derived from portal',   $derived],
                ['Derive failed (no og:image)',      $deriveFailed],
                ['Missing file, no source URL',      $noSourceUrl],
                [($dryRun ? 'Would re-fetch' : ($sync ? 'Downloaded (sync)' : 'Re-dispatched')), $reDispatched],
            ]
        );

        if ($noSourceUrl > 0 && ! $derive) {
            $this->warn("{$noSourceUrl} orphaned row(s) have no thumbnail_source_url — re-run with --derive-from-portal to recover them from the portal listing page.");
        }
        if ($deriveFailed > 0) {
            $this->warn("{$deriveFailed} row(s) could not be resolved from their portal page (delisted, blocked, or no og:image).");
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. {$reDispatched} download(s) "
            . ($dryRun ? 'would run' : ($sync ? 'completed' : 'dispatched')) . '.');

        return self::SUCCESS;
    }

    /** Pause before a network-touching row (skips the pause on the first one). */
    private function pause(int $throttleMs, int $networkRowsSoFar): void
    {
        if ($throttleMs > 0 && $networkRowsSoFar > 0) {
            usleep($throttleMs * 1000);
        }
    }
}
