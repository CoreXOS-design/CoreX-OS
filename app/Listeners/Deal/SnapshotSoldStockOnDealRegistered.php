<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealStageAdvanced;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\SoldStock\SoldStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sold stock (AT-512) - "marked Sold OR its deal registers, whichever first". When a deal reaches
 * Registered ('R') every property on it gets its sale frozen into sold_stock. Idempotent with the
 * status-change snapshot (one row per property + cycle; a second trigger only fills gaps).
 * Does NOT change the property's status - that stays the agency's own milestone setting.
 */
class SnapshotSoldStockOnDealRegistered
{
    public function handle(DealStageAdvanced $event): void
    {
        if (strtoupper((string) $event->toStage) !== 'R') {
            return;
        }

        try {
            $deal = $event->deal;
            $ids = DB::table('deal_properties')->where('deal_id', $deal->id)->whereNull('deleted_at')->pluck('property_id')->all();
            if ($deal->property_id) {
                $ids[] = $deal->property_id;
            }

            $service = app(SoldStockService::class);
            foreach (Property::withoutGlobalScope(AgencyScope::class)->whereIn('id', array_unique($ids))->get() as $property) {
                $service->snapshot($property, $deal, 'deal', $event->actorUserId);
            }
        } catch (\Throwable $e) {
            Log::warning("Sold-stock snapshot on deal registration failed for deal #{$event->deal->id}: {$e->getMessage()}");
        }
    }
}
