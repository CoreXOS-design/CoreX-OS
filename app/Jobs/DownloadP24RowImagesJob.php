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
 * Download a P24 listing's gallery — self-healing, idempotent, and incapable
 * of silently reporting success while short.
 *
 * History (see .ai/audits/p24-import-throughput-live-2026-07-17.md): the first
 * ~4000-property import lost ~11% of offered images because this job ran
 * tries=1, collected CDN rate-limit failures into a log line, and wrote
 * whatever it managed as "done". A gallery that stored 1 of 25 looked
 * successful and failed_jobs stayed empty.
 *
 * The contract now:
 *   - It fetches ONLY the ordinals not already on disk, so a retry is cheap and
 *     a re-import of an unchanged agency refetches nothing.
 *   - It rebuilds the gallery json from EVERY ordinal present on disk, so a
 *     recovered middle image closes the gap instead of leaving a hole.
 *   - If it is still short after this pass, it RELEASES itself to retry with
 *     backoff. Only when tries are exhausted does it stop — and then it records
 *     `incomplete` with a WARNING naming expected/stored/missing. It never
 *     reports `complete` while stored < expected.
 *
 * Concurrency is bounded at the WORKER level (a narrow p24images queue, ~4
 * procs × BATCH_SIZE ≈ 40 concurrent) precisely so this job does NOT re-create
 * the ~80-concurrent storm that tripped P24's per-IP limiter last time. Do not
 * widen BATCH_SIZE to buy speed — speed comes from the wide property lane.
 */
class DownloadP24RowImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 5;

    /** Escalating waits between retries — give a rate-limited CDN room to cool off. */
    public array $backoff = [30, 60, 180, 600];

    private const BATCH_SIZE = 10;

    /** Bytes under this are almost certainly a 1×1 placeholder or error page. */
    private const MIN_IMAGE_BYTES = 500;

    /**
     * Its own narrow lane; a worker must drain `p24images` or these strand. Set
     * via onQueue() (not a redeclared $queue property, which conflicts with the
     * Queueable trait).
     */
    /**
     * @param bool $force When the confirm job detects the inbound gallery
     *   CHANGED (a different P24 URL set from what produced the files on disk),
     *   it passes force=true so this job drops the stale ordinals and refetches
     *   the whole gallery instead of healing the new set against old files.
     */
    public function __construct(public int $propertyId, public array $urls, public bool $force = false)
    {
        $this->onQueue('p24images');
    }

    public function handle(): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);
        if (!$property) {
            Log::warning('DownloadP24RowImagesJob: property not found', ['property_id' => $this->propertyId]);
            return;
        }

        $urls = array_values(array_filter($this->urls));
        $expected = count($urls);

        // Genuine no-image listing — a real, terminal, complete state.
        if ($expected === 0) {
            $this->persist($property, 0, 0, 'complete');
            return;
        }

        // Skip-if-unchanged: an unchanged P24 gallery that is already fully
        // stored costs nothing on a re-import. Signature is the INBOUND set;
        // status guards against skipping a gallery that was short last time. A
        // forced refetch (the gallery changed) never short-circuits here.
        if (!$this->force
            && $property->gallery_import_status === 'complete'
            && $property->p24_source_image_signature === self::signatureFor($urls)) {
            return;
        }

        $dir = "properties/{$property->id}";

        // Changed gallery: the files on disk belong to the PREVIOUS URL set, so
        // fetch-only-missing would refetch nothing and leave the listing marked
        // complete while showing the old photos. Drop the old ordinals so every
        // position is refetched fresh. Guarded to the first attempt — a released
        // retry must never wipe the images it just downloaded. Numeric ordinal
        // files only; a generated thumbs/ subdir keeps its own lifecycle.
        if ($this->force && ($this->job === null || $this->attempts() <= 1)) {
            foreach ($this->presentOrdinals($dir) as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        // Which ordinals (1-based) already have a file on disk? Fetch only the rest.
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
            $this->fetchInto($property->id, $dir, $missing, $failures);
        }

        // Rebuild the ordered gallery from whatever is now on disk — this is the
        // source of truth, so a recovered ordinal 7 lands between 6 and 8 rather
        // than being appended out of order.
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
            // gallery_images_json drives the internal property UI; images_json is
            // kept in step for legacy public agency pages that still read it.
            $property->gallery_images_json = $ordered;
            $property->images_json = $ordered;
            $property->saveQuietly();
        }

        if ($stored >= $expected) {
            $this->persist($property, $expected, $stored, 'complete');
            return;
        }

        // Still short. Retry the stragglers if we have attempts left — this is
        // the guarantee that a rate-limited burst heals rather than sticks.
        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->persist($property, $expected, $stored, 'pending');
            $delay = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60;
            $this->release($delay);
            return;
        }

        // Attempts exhausted (or invoked outside a queue). Fail LOUD, never silent.
        $this->persist($property, $expected, $stored, 'incomplete');
        Log::warning('DownloadP24RowImagesJob: gallery INCOMPLETE after retries', [
            'property_id'   => $property->id,
            'expected'      => $expected,
            'stored'        => $stored,
            'missing'       => $expected - $stored,
            'attempts'      => $this->job !== null ? $this->attempts() : 'n/a (sync)',
            'sample_fail'   => array_slice($failures, 0, 5),
        ]);
    }

    /**
     * Terminal failure (uncaught throw after retries). Record it on the property
     * so the reconciliation query sees it — a dead job must not read as "pending
     * forever".
     */
    public function failed(\Throwable $e): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);
        if ($property) {
            $property->forceFill(['gallery_import_status' => 'failed'])->saveQuietly();
        }
        Log::error('DownloadP24RowImagesJob: terminally failed', [
            'property_id' => $this->propertyId,
            'error'       => $e->getMessage(),
        ]);
    }

    /**
     * Fetch the given ordinal=>url set 10-wide, writing each to
     * properties/{id}/{ordinal}.{ext}. Rejects sub-500-byte placeholders and
     * non-2xx. Failures are collected (not thrown) so the pass completes and
     * the shortfall drives the retry decision upstream.
     */
    private function fetchInto(int $propertyId, string $dir, array $ordinalUrls, array &$failures): void
    {
        foreach (array_chunk($ordinalUrls, self::BATCH_SIZE, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $reqs = [];
                foreach ($chunk as $ordinal => $url) {
                    $reqs[] = $pool->as((string) $ordinal)
                        ->timeout(15)
                        ->retry(3, 400, throw: false)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'Accept'     => 'image/*,*/*;q=0.8',
                            // P24's CDN hotlink-checks; a portal Referer keeps it
                            // serving full bytes rather than a placeholder.
                            'Referer'    => 'https://www.property24.com/',
                        ])
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

    /**
     * Map of ordinal => relative path for every gallery file currently on disk.
     * Filenames are `{ordinal}.{ext}`; the ordinal is the source of truth for
     * position, so a re-download of one image never reshuffles the rest.
     *
     * @return array<int,string>
     */
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
            'gallery_expected_count'     => $expected,
            'gallery_stored_count'       => $stored,
            'gallery_import_status'      => $status,
            'p24_source_image_signature' => self::signatureFor(array_values(array_filter($this->urls))),
        ])->saveQuietly();
    }

    /**
     * The INBOUND gallery signature — md5 of the offered P24 URL set. Must be
     * computed identically here and in ConfirmP24PropertyRowJob so the
     * skip-if-unchanged check agrees across the two jobs.
     */
    public static function signatureFor(array $urls): string
    {
        return md5(json_encode(array_values(array_filter($urls))));
    }
}
