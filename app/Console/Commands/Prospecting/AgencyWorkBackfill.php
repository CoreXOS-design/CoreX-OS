<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lane B (property-identity.md section 12): fill each agency's own work record from what already exists - the open claims, the own-stock
 * link and the "Not selling" status already set on its properties. Idempotent: re-running changes nothing that is already right.
 */
final class AgencyWorkBackfill extends Command
{
    protected $signature = 'agency-work:backfill {--agency= : Only this agency id} {--dry-run : Count what would be written, write nothing}';
    protected $description = "Fill every agency's own tracked-property work record (claim, own-stock flag, Not selling) from existing data";

    public function handle(AgencyWorkService $work, OnMarketStockService $stock): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->when($this->option('agency'), fn ($q, $id) => $q->where('id', (int) $id))->pluck('id');

        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            $claimTps = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNotNull('tracked_property_id')
                ->whereIn('id', ProspectingClaim::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')->select('prospecting_listing_id'))
                ->distinct()->pluck('tracked_property_id')->all();

            $ownTps = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNotNull('promoted_to_property_id')->pluck('id')->all();
            $advertQuery = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNotNull('tracked_property_id')->whereNull('deleted_at');
            $ownTps = array_values(array_unique(array_merge($ownTps, $stock->applyIsStock($advertQuery, $agencyId)->distinct()->pluck('tracked_property_id')->all())));

            $notSelling = TrackedProperty::withoutGlobalScopes()->from('tracked_properties as tp')
                ->join('properties as p', 'p.id', '=', 'tp.promoted_to_property_id')
                ->where('tp.agency_id', $agencyId)->where('p.agency_id', $agencyId)->whereNull('p.deleted_at')
                ->where('p.status', Property::STATUS_NOT_SELLING)->pluck('tp.id')->all();

            $this->line("agency {$agencyId}: claims " . count($claimTps) . ', own stock ' . count($ownTps) . ', not selling ' . count($notSelling) . ($dry ? ' (dry run, nothing written)' : ''));
            if ($dry) {
                continue;
            }
            foreach ($claimTps as $id) {
                $work->syncClaim($agencyId, (int) $id);
            }
            foreach ($ownTps as $id) {
                $work->syncOwnStock($agencyId, (int) $id);
            }
            foreach ($notSelling as $id) {
                $existing = $work->find($agencyId, (int) $id);
                if (! $existing || $existing->prospecting_status === null) {
                    $work->setStatus($agencyId, (int) $id, 'not_selling', 'Already marked Not selling before this record existed', null);
                }
            }
        }

        return self::SUCCESS;
    }
}
