<?php

declare(strict_types=1);

namespace App\Jobs\Prospecting;

use App\Models\AddressMatchSetting;
use App\Services\Prospecting\Matching\MatchingRules;
use App\Services\Prospecting\SameAddressSweep;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * AT-520 step 4.2 / property-identity.md 17.3: the after-import same-address sweep for ONE agency. Houses and stands that share number + street + unit
 * (+ suburb, or no suburb) merge; flats with no unit number and anything less certain go to the Possible matches list (SameAddressSweep).
 *
 * Debounced: `schedule()` is called by a listener on every property file the importers create or enrich - thousands of times in one import - so it
 * takes a per-agency cache key and queues ONE job a few minutes later; the job clears the key as it starts, so work that arrives while it runs
 * schedules the next one. Off when the agency's switch (Settings > Address matching) is off. Every moved row is journalled.
 */
final class RunSameAddressSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DELAY_MINUTES = 3;
    public const KEY_TTL_SECONDS = 900;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public readonly int $agencyId)
    {
    }

    public static function key(int $agencyId): string
    {
        return 'same-address-sweep:pending:' . $agencyId;
    }

    /** Queue the sweep once for this agency unless it is already queued or the agency switched it off. Returns true when a job was queued. */
    public static function schedule(int $agencyId): bool
    {
        if ($agencyId <= 0 || ! Cache::add(self::key($agencyId), 1, self::KEY_TTL_SECONDS)) {
            return false; // already waiting (or the switch was found off a moment ago - the key also spares the settings query)
        }
        if (! AddressMatchSetting::forAgency($agencyId)['same_address_sweep']) {
            return false;
        }
        self::dispatch($agencyId)->delay(now()->addMinutes(self::DELAY_MINUTES));

        return true;
    }

    public function handle(SameAddressSweep $sweep, TrackedPropertyMerger $merger, MatchingRules $rules): void
    {
        Cache::forget(self::key($this->agencyId));
        if (! AddressMatchSetting::forAgency($this->agencyId)['same_address_sweep']) {
            return;
        }

        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $merger->journalTo($dir . '/same-address-auto-' . now()->format('Ymd') . '.jsonl');
        try {
            $result = [];
            foreach (['no-suburb', 'exact'] as $scope) {
                $r = $sweep->run($this->agencyId, $scope, true, 'same-address-auto-' . $scope);
                $result[$scope] = ['groups' => $r['groups'], 'merged' => $r['merged_files'], 'review_pairs' => $r['review_pairs'], 'refused' => $r['merge_refused']];
            }
            // AT-520 4.4: P24 groups, same-agency twins, complexes and map points follow the same trigger, each behind its own agency switch
            $result['rules'] = $rules->run($this->agencyId, true);
            Log::info('same-address sweep (after import)', ['agency_id' => $this->agencyId] + $result);
        } finally {
            $merger->journalTo(null);
        }
    }
}
