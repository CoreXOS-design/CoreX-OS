<?php

declare(strict_types=1);

namespace App\Jobs\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Services\Geocoding\GeocodeRateLimitException;
use App\Services\Geocoding\PropertyGeoBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * GEO-SCRAPE — async geocode tracked-property GPS for the set of TPs
 * a Chrome scrape just touched.
 *
 * Dispatched ONCE per scrape batch from ProspectingApiController::import
 * after rows commit. NOT one job per listing — a 300-listing scrape would
 * thrash the queue + the rate-limiter; batching gives the limiter a clean
 * stop signal when the daily cap is hit so the remainder are simply left
 * for the next scrape (or for `php artisan geocoding:backfill --type=
 * tracked_properties`).
 *
 * Idempotent: skips TPs that already have non-zero GPS. Re-running the
 * job over the same set never re-charges Google because the underlying
 * AddressResolverService consults GeocodeCache + GeocodeRateLimiter
 * before each paid call.
 *
 * Cap-aware: stops the loop the moment GeocodeRateLimitException is
 * raised. The remaining TPs are LEFT — next scrape (or the nightly
 * backfill cron) will resolve them tomorrow. We deliberately do not
 * retry or back-off: a hard cap is a hard cap.
 *
 * Safety: every TP is wrapped in try/catch. One bad row never poisons
 * the batch; one transient resolver error never breaks the job. $tries=1
 * + no retry — if the worker dies mid-batch the unresolved TPs surface
 * again on the next scrape via the same dispatch path.
 *
 * NEVER called synchronously from the scrape HTTP request — would defeat
 * the entire point. Dispatch is fire-and-forget; if QUEUE_CONNECTION=sync
 * (dev), the job runs inline AT THE END of the response cycle, not inside
 * the foreach.
 */
final class GeocodeTrackedPropertyAddressesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;   // 5 min ceiling; cap will stop us well before this

    /**
     * @param int[]    $trackedPropertyIds  TPs to attempt geocoding for; the job
     *                                       filters out those already resolved
     *                                       in handle() to keep the dispatcher's
     *                                       hot path query-free.
     * @param ?string  $batchId             Optional UUID for trace; auto-generated
     *                                       if null. Logged on every geocoding_runs
     *                                       row so a single scrape's resolutions
     *                                       can be tallied after the fact.
     */
    public function __construct(
        public readonly array $trackedPropertyIds,
        public readonly ?string $batchId = null,
    ) {}

    public function handle(PropertyGeoBackfillService $svc): void
    {
        if (empty($this->trackedPropertyIds)) {
            return;
        }

        $batchId = $this->batchId ?? (string) Str::uuid();
        $tally = ['attempted' => 0, 'resolved' => 0, 'already_had_gps' => 0, 'failed' => 0, 'not_found' => 0, 'cap_reached' => false];

        foreach ($this->trackedPropertyIds as $id) {
            try {
                // Bypass AgencyScope — jobs run with no Auth context.
                $tp = TrackedProperty::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->find((int) $id);

                if ($tp === null) {
                    $tally['not_found']++;
                    continue;
                }

                // Idempotency guard: GeocodeCache also catches re-resolves
                // (no Google charge) but checking here saves the round-trip
                // entirely. Same shape as PropertyGeoBackfillService::hasGps.
                if ($tp->latitude !== null && $tp->longitude !== null
                    && (float) $tp->latitude !== 0.0 && (float) $tp->longitude !== 0.0
                ) {
                    $tally['already_had_gps']++;
                    continue;
                }

                $tally['attempted']++;
                $result = $svc->backfillTrackedProperty($tp, batchId: $batchId);
                if ($result['lat_lng_resolved']) {
                    $tally['resolved']++;
                } else {
                    $tally['failed']++;
                }
            } catch (GeocodeRateLimitException $e) {
                // Daily cap reached — stop processing. The remaining TPs
                // are intentionally LEFT for the next scrape / nightly
                // backfill. This is the prompt's "geocode up to cap, leave
                // rest" contract.
                $tally['cap_reached'] = true;
                Log::info('GeocodeTrackedPropertyAddressesJob daily cap reached, remainder left for next run', [
                    'batch_id'                => $batchId,
                    'stopped_at_tp_id'        => (int) $id,
                    'remaining_count'         => count($this->trackedPropertyIds) - ($tally['attempted'] + $tally['already_had_gps'] + $tally['not_found']),
                    'limit_kind'              => $e->limitKind ?? null,
                    'counted'                 => $e->counted ?? null,
                    'cap'                     => $e->cap ?? null,
                ]);
                break;
            } catch (Throwable $e) {
                // Per-row failure — swallow + continue. One bad TP must
                // never poison the batch.
                $tally['failed']++;
                Log::warning('GeocodeTrackedPropertyAddressesJob row failure (swallowed)', [
                    'batch_id'            => $batchId,
                    'tracked_property_id' => (int) $id,
                    'message'             => $e->getMessage(),
                ]);
            }
        }

        Log::info('GeocodeTrackedPropertyAddressesJob complete', [
            'batch_id'      => $batchId,
            'input_count'   => count($this->trackedPropertyIds),
            'tally'         => $tally,
        ]);
    }
}
