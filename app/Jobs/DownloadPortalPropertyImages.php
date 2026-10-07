<?php

namespace App\Jobs;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadPortalPropertyImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    private const BATCH_SIZE = 10;

    /**
     * @param int    $propertyId     CoreX property ID
     * @param int    $firstImageId   First P24 image ID (sequential)
     * @param int    $imageCount     Total number of images
     * @param int[]  $imageIds       The gallery's real image ids in the portal's own order.
     *                               When given they are downloaded as-is and firstImageId /
     *                               imageCount are not used to guess ids: P24 ids are NOT
     *                               consecutive (checked against six saved pages — gaps, a
     *                               later image with a LOWER id, a second batch weeks later),
     *                               so first+1, first+2 … fetched other listings' photos.
     *                               Empty = the old sequential guess (Pull, older extension).
     */
    public function __construct(
        public int $propertyId,
        public int $firstImageId,
        public int $imageCount,
        public array $imageIds = [],
    ) {
        // 2026-09-30 demo-reliability fix: this job had NO queue assignment
        // at all (defaulted to `default`), so a live import's photos could
        // sit behind whatever else was already queued there — confirmed
        // live: a 6-7 minute RegenerateBuyerMatchesJob blocked a real
        // property's images for minutes on QA1. Same dedicated queue
        // DownloadOtherAgencyStockGalleryJob already uses, which now also
        // has its own dedicated worker (corex-qa1-queue-images.service) —
        // never sharing a process with a long-running unrelated job again.
        $this->onQueue('p24images');
    }

    public function handle(): void
    {
        $property = Property::find($this->propertyId);
        if (!$property) {
            return;
        }

        $total = ! empty($this->imageIds) ? count($this->imageIds) : $this->imageCount;
        $cacheKey = "property_pull_images:{$this->propertyId}";
        $dir = "properties/{$this->propertyId}";

        Cache::put($cacheKey, [
            'total' => $total, 'downloaded' => 0, 'failed' => 0, 'complete' => false,
        ], 3600);

        // Build all image URLs: sequential IDs from firstImageId
        $imageUrls = [];
        for ($i = 0; $i < $total; $i++) {
            $imageId = ! empty($this->imageIds) ? (int) $this->imageIds[$i] : $this->firstImageId + $i;
            $imageUrls[] = "https://images.prop24.com/{$imageId}/Ensure1280x720";
        }

        $downloaded = 0;
        $failed = 0;
        $contentHashes = [];
        $chunks = array_chunk($imageUrls, self::BATCH_SIZE);
        // 2026-09-30 URGENT REGRESSION FIX (property #21098): every filename
        // this job writes includes a fresh Str::random(8) suffix, so a
        // second dispatch for the SAME property (a reimport, or simply this
        // job running twice) never overwrites the first run's files —
        // merging against $property->gallery_images_json (the DB's CURRENT
        // value, which still has the previous run's entries) just kept
        // appending forever. Confirmed live: 3 accumulated rounds, 69
        // entries for a genuinely 23-photo property. Track only what THIS
        // run has stored and REPLACE gallery_images_json with exactly that
        // — matches the contract PropertyPullController's own reimport path
        // already assumes ("Clear old images — the new pull will
        // re-download them"), just enforced here in the job itself so every
        // caller gets it, not only the one that remembered to clear first.
        $storedThisRun = [];

        foreach ($chunks as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                foreach ($chunk as $i => $url) {
                    $pool->as((string) $i)
                        ->timeout(10)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'Accept'     => 'image/*',
                        ])
                        ->get($url);
                }
            });

            $batchStored = [];

            foreach ($chunk as $i => $url) {
                try {
                    $resp = $responses[(string) $i];

                    if (!$resp || $resp instanceof \Throwable || !$resp->successful()) {
                        $failed++;
                        continue;
                    }

                    $body = $resp->body();
                    if (strlen($body) < 2000) {
                        $failed++;
                        continue;
                    }

                    // Content-hash dedup (skip identical images)
                    $hash = md5($body);
                    if (in_array($hash, $contentHashes)) {
                        $failed++;
                        continue;
                    }
                    $contentHashes[] = $hash;

                    $contentType = $resp->header('Content-Type') ?? 'image/jpeg';
                    $ext = match (true) {
                        str_contains($contentType, 'png')  => 'png',
                        str_contains($contentType, 'webp') => 'webp',
                        default                             => 'jpg',
                    };

                    $filename = sprintf('%s/%03d_%s.%s', $dir, $downloaded + 1, Str::random(8), $ext);
                    Storage::disk('public')->put($filename, $body);

                    // Same downscale + list-thumbnail treatment every other property
                    // image path gets (PropertyImageStorer/PropertyThumbnailService).
                    // P24 already constrains to ~1280x720 so downscale() is usually a
                    // no-op here, but it still normalises non-JPEG sources to JPEG —
                    // and the thumbnail was previously never generated at all for
                    // P24-pulled photos, so list views fell back to the full image.
                    app(\App\Services\Images\PropertyImageStorer::class)->downscale($filename);
                    $url = Storage::disk('public')->url($filename);
                    app(\App\Services\Images\PropertyThumbnailService::class)->generateForUrl($url);

                    $batchStored[] = $url;
                    $downloaded++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }

            if (count($batchStored) > 0) {
                $storedThisRun = array_merge($storedThisRun, $batchStored);
                $property->refresh();
                $property->gallery_images_json = $storedThisRun;
                $property->saveQuietly();
                // File the newly-added photos into gallery_categories_json —
                // every writer of gallery_images_json must, or the mobile app's
                // room-by-room gallery (built from categories alone) shows 0 photos.
                $property->syncGalleryCategoriesLocked();
            }

            $this->updateProgress($cacheKey, $total, $downloaded, $failed, false);
        }

        $this->updateProgress($cacheKey, $total, $downloaded, $failed, true);
    }

    private function updateProgress(string $cacheKey, int $total, int $downloaded, int $failed, bool $complete): void
    {
        Cache::put($cacheKey, [
            'total'      => $total,
            'downloaded' => $downloaded,
            'failed'     => $failed,
            'complete'   => $complete,
        ], 3600);
    }
}
