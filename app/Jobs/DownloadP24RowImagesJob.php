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

class DownloadP24RowImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    private const BATCH_SIZE = 10;

    public function __construct(public int $propertyId, public array $urls) {}

    public function handle(): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);
        if (!$property || empty($this->urls)) {
            Log::warning('DownloadP24RowImagesJob: skipped', [
                'property_id' => $this->propertyId,
                'reason'      => !$property ? 'property not found' : 'no urls',
                'url_count'   => count($this->urls ?? []),
            ]);
            return;
        }

        $urls = array_values(array_filter($this->urls));
        Log::info('DownloadP24RowImagesJob: start', [
            'property_id' => $property->id,
            'url_count'   => count($urls),
            'first_url'   => $urls[0] ?? null,
        ]);

        $stored = [];
        $failures = [];

        foreach (array_chunk($urls, self::BATCH_SIZE, true) as $batch) {
            $responses = Http::pool(function ($pool) use ($batch) {
                $reqs = [];
                foreach ($batch as $idx => $url) {
                    $reqs[] = $pool->as((string) $idx)
                        ->timeout(15)
                        // Retry transient CDN throttling/timeouts. The original
                        // bulk import ran with tries=1 and no retry, so a burst
                        // of rate-limited responses from images.prop24.com
                        // silently dropped most of each gallery (0-1 survived).
                        ->retry(3, 400, throw: false)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'Accept'     => 'image/*,*/*;q=0.8',
                            // P24's image CDN hotlink-checks; a portal Referer
                            // keeps it serving full-size bytes rather than a
                            // placeholder/redirect.
                            'Referer'    => 'https://www.property24.com/',
                        ])
                        ->get($url);
                }
                return $reqs;
            });

            foreach ($batch as $idx => $url) {
                $resp = $responses[(string) $idx] ?? null;

                if ($resp instanceof \Throwable) {
                    $failures[] = ['idx' => $idx, 'url' => $url, 'reason' => 'exception: ' . $resp->getMessage()];
                    continue;
                }
                if (!$resp) {
                    $failures[] = ['idx' => $idx, 'url' => $url, 'reason' => 'no response'];
                    continue;
                }

                $status = $resp->status();
                $body   = $resp->body();
                $len    = strlen($body);
                $ctype  = $resp->header('Content-Type');

                if ($status < 200 || $status >= 300) {
                    $failures[] = [
                        'idx' => $idx, 'url' => $url,
                        'reason' => "http_status={$status} len={$len} ctype={$ctype}",
                    ];
                    continue;
                }
                // Anything under 500 bytes is almost certainly a 1×1 placeholder or error page.
                if ($len < 500) {
                    $failures[] = [
                        'idx' => $idx, 'url' => $url,
                        'reason' => "body_too_small len={$len} ctype={$ctype}",
                    ];
                    continue;
                }

                $ordinal = $idx + 1;
                $ext = match (true) {
                    is_string($ctype) && str_contains($ctype, 'png')  => 'png',
                    is_string($ctype) && str_contains($ctype, 'webp') => 'webp',
                    default                                            => 'jpg',
                };
                $dest = "properties/{$property->id}/{$ordinal}.{$ext}";
                try {
                    Storage::disk('public')->put($dest, $body);
                    // Store as a public URL (/storage/...) so the property UI
                    // can render it directly via <img src>.
                    $stored[$idx] = Storage::disk('public')->url($dest);
                } catch (\Throwable $e) {
                    $failures[] = ['idx' => $idx, 'url' => $url, 'reason' => 'storage_put: ' . $e->getMessage()];
                }
            }
        }

        if (!empty($stored)) {
            ksort($stored);
            $paths = array_values($stored);
            $property->refresh();
            // gallery_images_json is what the internal property UI renders from
            // (show, index, match, contacts). images_json is kept populated for
            // legacy public agency pages that still read it.
            $property->gallery_images_json = $paths;
            $property->images_json = $paths;
            $property->saveQuietly();
        }

        Log::info('DownloadP24RowImagesJob: done', [
            'property_id'  => $property->id,
            'url_count'    => count($urls),
            'stored_count' => count($stored),
            'failed_count' => count($failures),
            'sample_failures' => array_slice($failures, 0, 5),
        ]);
    }
}
