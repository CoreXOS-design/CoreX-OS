<?php

namespace App\Services\RoadToSold;

use App\Models\Property;
use App\Models\RoadToSold\RtsProspect;
use App\Services\Compliance\MarketingReadinessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Road to Sold v4, stations 9-11 (road-to-sold.md 23.11 "Derived blockers"): nobody "loses" a draft in a click, so the SYSTEM names why a record is stuck.
 *
 * A record that has sat at Draft or Active for `prospect_stall_days` (agency setting, default 14) is stuck on the FIRST thing that still fails:
 *   Draft   Mandate not signed -> Compliance incomplete -> Photos missing          (the marketing readiness checks of the property)
 *   Active  Portal rejected -> Not published                                       (publication and the portal's own answer)
 * The blocker is derived on every read and stored nowhere: it clears the moment the check passes, the record is never set aside because of it, and the stats
 * count it like any other reason. The keys are the blocker reasons of ParkReasonService::stationDefaults() (wording is the agency's).
 */
class BlockerService
{
    public function __construct(private readonly MarketingReadinessService $readiness)
    {
    }

    public function isStalled(RtsProspect $p, ?int $stallDays = null): bool
    {
        $days = $stallDays ?? SettingsService::forAgency((int) $p->agency_id, (string) $p->track)->prospectStallDays();

        return $p->stage_entered_at !== null && Carbon::parse($p->stage_entered_at)->lte(now()->subDays($days));
    }

    /** @return array{key:string,station:string,detail:?string}|null null = not at Draft / Active, not stuck yet, or nothing fails */
    public function firstBlocker(RtsProspect $p, ?int $stallDays = null): ?array
    {
        if (! in_array($p->stage, [ProspectStages::DRAFT, ProspectStages::ACTIVE], true) || $p->closed_at !== null || $p->released_at !== null || ! $p->property_id) {
            return null;
        }
        if (! $this->isStalled($p, $stallDays)) {
            return null;
        }
        try {
            $property = Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($p->property_id);
            if (! $property) {
                return null;
            }

            return $p->stage === ProspectStages::DRAFT ? $this->draftBlocker($property) : $this->activeBlocker($property);
        } catch (\Throwable $e) {
            Log::warning('Belt: a blocker could not be worked out (nothing is shown)', ['prospect_id' => $p->id, 'message' => $e->getMessage()]);

            return null;
        }
    }

    private function draftBlocker(Property $property): ?array
    {
        $report = $this->readiness->statusFor($property);
        if ($report->ready) {
            return null;
        }
        $failing = collect($report->checklist)->filter(fn ($row) => ! ($row['passed'] ?? true));
        $detail = $failing->pluck('detail')->filter()->first();
        // the first failing check, in the order an agent clears them: the mandate, then the other compliance documents, then the photos
        if ($failing->has('mandate')) {
            return ['key' => 'blk_mandate', 'station' => 'draft', 'detail' => $failing->get('mandate')['detail'] ?? $detail];
        }
        $docs = $failing->except(['photos']);
        if ($docs->isNotEmpty()) {
            return ['key' => 'blk_compliance', 'station' => 'draft', 'detail' => $docs->first()['detail'] ?? $detail];
        }

        return ['key' => 'blk_photos', 'station' => 'draft', 'detail' => $failing->get('photos')['detail'] ?? $detail];
    }

    private function activeBlocker(Property $property): ?array
    {
        foreach (['p24_syndication_status', 'pp_syndication_status'] as $col) {
            if (in_array(strtolower((string) $property->{$col}), ['rejected', 'failed', 'error'], true)) {
                return ['key' => 'blk_portal_rejected', 'station' => 'active', 'detail' => 'A portal turned the listing down.'];
            }
        }
        if ($property->published_at === null) {
            return ['key' => 'blk_not_published', 'station' => 'active', 'detail' => 'The listing is not published yet.'];
        }

        return null;
    }
}
