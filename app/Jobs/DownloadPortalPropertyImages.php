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
     */
    public function __construct(
        public int $propertyId,
        public int $firstImageId,
        public int $imageCount,
    ) {}

    public function handle(): void
    {
        $property = Property::find($this->propertyId);
        if (!$property) {
            return;
        }

        $total = $this->imageCount;
        $cacheKey = "property_pull_images:{$this->propertyId}";
        $dir = "properties/{$this->propertyId}";

        Cache::put($cacheKey, [
            'total' => $total, 'downloaded' => 0, 'failed' => 0, 'complete' => false,
        ], 3600);

        // Build all image URLs: sequential IDs from firstImageId
        $imageUrls = [];
        for ($i = 0; $i < $total; $i++) {
            $imageId = $this->firstImageId + $i;
            $imageUrls[] = "https://images.prop24.com/{$imageId}/Ensure1280x720";
        }

        $downloaded = 0;
        $failed = 0;
        $contentHashes = [];
        $chunks = array_chunk($imageUrls, self::BATCH_SIZE);

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
                    $batchStored[] = Storage::disk('public')->url($filename);
                    $downloaded++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }

            if (count($batchStored) > 0) {
                $property->refresh();
                $existing = $property->gallery_images_json ?? [];
                $property->gallery_images_json = array_merge($existing, $batchStored);
                $property->saveQuietly();
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
