<?php

declare(strict_types=1);

namespace App\Jobs\MarketIntelligence;

use App\Services\MarketIntelligence\Pockets\MicStatsBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * MIC 5.5/5.7 (AT-519): re-count ONE file's stored stats and score after something that changes them (a claim, a price drop). Delayed and
 * unique per file, so a burst of changes (an import) collapses into one refresh. The nightly `mic:refresh-stats` stays the safety net.
 */
final class RefreshMicFileStatsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 1;

    public function __construct(public readonly int $agencyId, public readonly int $trackedPropertyId)
    {
    }

    public function uniqueId(): string
    {
        return $this->agencyId . ':' . $this->trackedPropertyId;
    }

    public function handle(MicStatsBuilder $builder): void
    {
        $builder->refreshFile($this->agencyId, $this->trackedPropertyId);
    }

    /** Config switch (default on; off in the test environment): `mic.refresh_on_change`. */
    public static function enabled(): bool
    {
        return (bool) (config('mic.refresh_on_change') ?? ! app()->environment('testing'));
    }

    /** Queue a refresh for the file behind a prospecting listing. Never throws: a bookkeeping job must not break a capture or a claim. */
    public static function forListing(?int $listingId): void
    {
        if ($listingId === null || ! self::enabled()) {
            return;
        }
        try {
            $row = DB::table('prospecting_listings')->where('id', $listingId)->first(['agency_id', 'tracked_property_id']);
            if ($row && $row->tracked_property_id !== null) {
                self::dispatch((int) $row->agency_id, (int) $row->tracked_property_id)->delay(now()->addSeconds(45))->afterCommit();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
