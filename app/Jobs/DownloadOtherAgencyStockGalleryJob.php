<?php

namespace App\Jobs;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Download an Other Agency Stock listing's photo gallery (every URL the
 * Chrome extension collected client-side — the full P24/PP gallery, not
 * just the handful of thumbnails a page has lazy-loaded) into this
 * property's own gallery, same storage convention as any other property
 * (properties/{id}/{ordinal}.{ext} on the `public` disk, gallery_images_json
 * + images_json kept in step). Modeled directly on the proven
 * DownloadP24RowImagesJob (self-healing, fetch-only-missing, retries with
 * backoff, never reports 'complete' while short) — deliberately its own
 * class rather than reused directly: that job is P24-specific (P24 Referer
 * header, p24_source_image_signature column) and this one must serve both
 * P24- and PP-sourced URLs.
 *
 * .ai/specs/other-agency-stock.md §4
 */
class DownloadOtherAgencyStockGalleryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 5;

    public array $backoff = [30, 60, 180, 600];

    private const BATCH_SIZE = 10;

    /** Bytes under this are almost certainly a 1×1 placeholder or error page. */
    private const MIN_IMAGE_BYTES = 500;

    public function __construct(public int $propertyId, public array $urls)
    {
        $this->onQueue('p24images');
    }

    public function handle(): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);
        if (!$property) {
            Log::warning('DownloadOtherAgencyStockGalleryJob: property not found', ['property_id' => $this->propertyId]);
            return;
        }

        $urls = array_values(array_unique(array_filter($this->urls)));
        $expected = count($urls);

        if ($expected === 0) {
            $this->persist($property, 0, 0, 'complete');
            return;
        }

        $dir = "properties/{$property->id}";

        $present = $this->presentOrdinals($dir);
        $missing = [];
        foreach ($urls as $idx => $url) {
            $ordinal = $idx + 1;
            if (!isset($present[$ordinal])) {
                $missing[$ordinal] = $url;
            }
        }

        $failures = [];
        if (!empty($missing)) {
            $this->fetchInto($dir, $missing, $failures);
        }

        $present = $this->presentOrdinals($dir);
        $paths = [];
        foreach (array_keys($present) as $ordinal) {
            if ($ordinal >= 1 && $ordinal <= $expected) {
                $paths[$ordinal] = Storage::disk('public')->url($present[$ordinal]);
            }
        }
        ksort($paths);
        $stored = count($paths);

        if ($stored > 0) {
            $property->refresh();
            $ordered = array_values($paths);
            $property->gallery_images_json = $ordered;
            $property->images_json = $ordered;
            $property->saveQuietly();
            $property->syncGalleryCategoriesLocked();
        }

        if ($stored >= $expected) {
            $this->persist($property, $expected, $stored, 'complete');
            return;
        }

        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->persist($property, $expected, $stored, 'pending');
            $delay = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60;
            $this->release($delay);
            return;
        }

        $this->persist($property, $expected, $stored, 'incomplete');
        Log::warning('DownloadOtherAgencyStockGalleryJob: gallery INCOMPLETE after retries', [
            'property_id' => $property->id,
            'expected'    => $expected,
            'stored'      => $stored,
            'missing'     => $expected - $stored,
            'sample_fail' => array_slice($failures, 0, 5),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);
        if ($property) {
            $property->forceFill(['gallery_import_status' => 'failed'])->saveQuietly();
        }
        Log::error('DownloadOtherAgencyStockGalleryJob: terminally failed', [
            'property_id' => $this->propertyId,
            'error'       => $e->getMessage(),
        ]);
    }

    private function fetchInto(string $dir, array $ordinalUrls, array &$failures): void
    {
        foreach (array_chunk($ordinalUrls, self::BATCH_SIZE, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $reqs = [];
                foreach ($chunk as $ordinal => $url) {
                    // P24's CDN hotlink-checks a Referer; PP's does not need one.
                    // Conditional rather than always-P24, since this job serves
                    // both portals' URLs.
                    $headers = [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept'     => 'image/*,*/*;q=0.8',
                    ];
                    if (str_contains($url, 'prop24.com')) {
                        $headers['Referer'] = 'https://www.property24.com/';
                    } elseif (str_contains($url, 'privateproperty.co.za')) {
                        $headers['Referer'] = 'https://www.privateproperty.co.za/';
                    }

                    $reqs[] = $pool->as((string) $ordinal)
                        ->timeout(15)
                        ->retry(3, 400, throw: false)
                        ->withHeaders($headers)
                        ->get($url);
                }
                return $reqs;
            });

            foreach ($chunk as $ordinal => $url) {
                $resp = $responses[(string) $ordinal] ?? null;

                if ($resp instanceof \Throwable) {
                    $failures[] = ['ordinal' => $ordinal, 'reason' => 'exception: ' . $resp->getMessage()];
                    continue;
                }
                if (!$resp) {
                    $failures[] = ['ordinal' => $ordinal, 'reason' => 'no response'];
                    continue;
                }

                $status = $resp->status();
                $body   = $resp->body();
                $len    = strlen($body);
                $ctype  = $resp->header('Content-Type');

                if ($status < 200 || $status >= 300) {
                    $failures[] = ['ordinal' => $ordinal, 'reason' => "http_status={$status} len={$len}"];
                    continue;
                }
                if ($len < self::MIN_IMAGE_BYTES) {
                    $failures[] = ['ordinal' => $ordinal, 'reason' => "body_too_small len={$len} ctype={$ctype}"];
                    continue;
                }

                $ext = match (true) {
                    is_string($ctype) && str_contains($ctype, 'png')  => 'png',
                    is_string($ctype) && str_contains($ctype, 'webp') => 'webp',
                    default                                            => 'jpg',
                };
                try {
                    Storage::disk('public')->put("{$dir}/{$ordinal}.{$ext}", $body);
                } catch (\Throwable $e) {
                    $failures[] = ['ordinal' => $ordinal, 'reason' => 'storage_put: ' . $e->getMessage()];
                }
            }
        }
    }

    /** @return array<int,string> */
    private function presentOrdinals(string $dir): array
    {
        $out = [];
        foreach (Storage::disk('public')->files($dir) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (ctype_digit($name)) {
                $out[(int) $name] = $path;
            }
        }
        ksort($out);
        return $out;
    }

    private function persist(Property $property, int $expected, int $stored, string $status): void
    {
        $property->forceFill([
            'gallery_expected_count' => $expected,
            'gallery_stored_count'   => $stored,
            'gallery_import_status'  => $status,
        ])->saveQuietly();
    }
}
