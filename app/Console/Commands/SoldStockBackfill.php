<?php

namespace App\Console\Commands;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\SoldStock;
use App\Services\SoldStock\SoldStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * sold-stock:backfill - freeze every sale that already exists (AT-512, .ai/specs/sold-stock.md section 7).
 * Idempotent: a property + cycle that already has a snapshot is counted, never duplicated.
 */
class SoldStockBackfill extends Command
{
    protected $signature = 'sold-stock:backfill {--agency= : Only this agency id} {--dry-run : Count only, write nothing}
        {--portals : ALSO dispatch the Property24 / Private Property take-down jobs and the website "removed" webhooks (production run only - a non-production copy of the data carries real portal credentials)}';

    protected $description = 'Create sold_stock snapshots for every Sold property and registered deal, and take Sold properties off the for-sale website/PP feeds.';

    public function handle(SoldStockService $service): int
    {
        $dry    = (bool) $this->option('dry-run');
        $agency = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $c = ['sold_properties' => 0, 'created' => 0, 'already_present' => 0, 'deals_checked' => 0, 'deal_snapshots' => 0,
              'website_switched_off' => 0, 'portal_jobs_dispatched' => 0, 'portal_listings_still_to_take_down' => 0, 'skipped' => 0];

        $props = Property::withoutGlobalScope(AgencyScope::class)
            ->whereRaw("LOWER(TRIM(status)) = 'sold'")
            ->when($agency, fn ($q) => $q->where('agency_id', $agency))
            ->orderBy('id');

        $props->chunkById(200, function ($chunk) use ($service, $dry, &$c) {
            foreach ($chunk as $property) {
                $c['sold_properties']++;
                $exists = $this->snapshotExists($property);
                $exists ? $c['already_present']++ : $c['created']++;
                if ($dry) {
                    continue;
                }
                $service->snapshot($property, null, 'backfill');
                $this->takeOffForSaleFeeds($property->fresh(), (bool) $this->option('portals'), $c);
            }
        });

        // Registered DR2 deals whose property has no snapshot yet.
        Deal::withoutGlobalScope(AgencyScope::class)->where('accepted_status', 'R')
            ->when($agency, fn ($q) => $q->where('agency_id', $agency))
            ->orderBy('id')->chunkById(200, function ($deals) use ($service, $dry, &$c) {
                foreach ($deals as $deal) {
                    $c['deals_checked']++;
                    $ids = DB::table('deal_properties')->where('deal_id', $deal->id)->whereNull('deleted_at')->pluck('property_id')->all();
                    if ($deal->property_id) {
                        $ids[] = $deal->property_id;
                    }
                    foreach (Property::withoutGlobalScope(AgencyScope::class)->whereIn('id', array_unique($ids))->get() as $property) {
                        if (SoldStock::withoutGlobalScope(AgencyScope::class)->withTrashed()->where('property_id', $property->id)->exists()
                            || Property::isSoldByThirdPartyStatus((string) $property->status)) {
                            $c['skipped']++;
                            continue;
                        }
                        $c['deal_snapshots']++;
                        if (! $dry) {
                            $service->snapshot($property, $deal, 'backfill');
                        }
                    }
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . 'sold-stock:backfill' . ($agency ? " agency={$agency}" : ' all agencies'));
        foreach ($c as $k => $v) {
            $this->line(sprintf('  %-18s %d', $k, $v));
        }

        return self::SUCCESS;
    }

    private function snapshotExists(Property $property): bool
    {
        return SoldStock::withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('property_id', $property->id)->where('cycle_no', (int) ($property->listing_cycle ?: 1))->exists();
    }

    /**
     * The website pivot goes off in the database (no external call). The portals (PP always, P24 once the grace window has
     * passed) only when --portals is given - this command may be run on a copy of live data that holds real portal credentials.
     */
    private function takeOffForSaleFeeds(Property $property, bool $portals, array &$c): void
    {
        $off = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)->where('enabled', true)
            ->update(['enabled' => false, 'status' => PropertyWebsiteSyndication::STATUS_DEACTIVATED]);
        $off > 0 && $c['website_switched_off']++;

        if (! $property->pp_syndication_enabled && ! $property->mayBeLiveOnP24()) {
            return;
        }
        if (! $portals) {
            $c['portal_listings_still_to_take_down']++;

            return;
        }
        $graceOpen = $property->portal_sold_until !== null && $property->portal_sold_until->toDateString() >= now()->toDateString();
        DesyndicatePropertyFromPortalsJob::dispatch($property, removeFromWebsite: true, keepPpForSold: false, keepP24ForSold: $graceOpen);
        $c['portal_jobs_dispatched']++;
    }
}
