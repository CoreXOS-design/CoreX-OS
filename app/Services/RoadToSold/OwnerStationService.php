<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Road to Sold v4, station 3 "Owner found" and the gate (.ai/specs/road-to-sold.md 23.6).
 *
 *   ownerFound()   true when the property file has an owner contact linked AND (setting owner_requires_deed, default on) a deed record: a
 *                  deeds capture or a CMA Info capture. Read from the property file itself, so the answer never lags a stage move.
 *   evaluate()     the property file changed (owner linked, deed arrived): every open belt record of it that has not reached the station moves
 *                  to Owner found. Called by the listener on OwnerLinkedToTrackedProperty / TrackedPropertyEnriched and by rts:place-owner-stations.
 *   refusal()      the gate. Null when the agent may contact the owner; else the plain sentence to show. Outcomes that exist to report the owner
 *                  problem (find-owner outcomes, do-not-contact) always pass.
 */
class OwnerStationService
{
    public const MESSAGE = 'Find the owner first, then contact them.';

    public function __construct(private readonly ProspectService $prospects)
    {
    }

    /** CMA Info and deeds-office captures; a deeds_captured_at stamp counts on its own. */
    public const DEED_SOURCES = ['deeds_capture', 'cmainfo'];

    public function hasDeedRecord(int $agencyId, int $trackedPropertyId): bool
    {
        $tp = DB::table('tracked_properties')->where('agency_id', $agencyId)->where('id', $trackedPropertyId)->first(['deeds_captured_at']);
        if (! $tp) {
            return false;
        }
        if ($tp->deeds_captured_at !== null) {
            return true;
        }

        return DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)
            ->whereIn('source_type', self::DEED_SOURCES)->whereNull('deleted_at')->exists();
    }

    public function requiresDeed(int $agencyId): bool
    {
        return RtsSetting::hasOwnerRequiresDeedColumn() ? SettingsService::forAgency($agencyId)->ownerRequiresDeed() : true;
    }

    public function ownerFound(int $agencyId, int $trackedPropertyId): bool
    {
        $ownerId = DB::table('tracked_properties')->where('agency_id', $agencyId)->where('id', $trackedPropertyId)->value('owner_contact_id');
        if (! $ownerId) {
            return false;
        }

        return ! $this->requiresDeed($agencyId) || $this->hasDeedRecord($agencyId, $trackedPropertyId);
    }

    /** What is missing, in plain words: [] when the owner is found. @return list<string> */
    public function missing(int $agencyId, int $trackedPropertyId): array
    {
        $out = [];
        $ownerId = DB::table('tracked_properties')->where('agency_id', $agencyId)->where('id', $trackedPropertyId)->value('owner_contact_id');
        if (! $ownerId) {
            $out[] = 'the owner contact';
        }
        if ($this->requiresDeed($agencyId) && ! $this->hasDeedRecord($agencyId, $trackedPropertyId)) {
            $out[] = 'the deed record';
        }

        return $out;
    }

    /** Move every open belt record of the property that is before the Owner station onto it. Returns how many moved. */
    public function evaluate(int $agencyId, int $trackedPropertyId, ?User $actor = null, string $eventClass = 'OwnerLinked'): int
    {
        if (! $this->ownerFound($agencyId, $trackedPropertyId)) {
            return 0;
        }
        $moved = 0;
        $rows = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->where('tracked_property_id', $trackedPropertyId)
            ->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')->get();
        foreach ($rows as $p) {
            if (ProspectStages::order((string) $p->stage) >= ProspectStages::order(ProspectStages::OWNER_FOUND)) {
                continue;
            }
            try {
                if ($this->prospects->advance($p, ProspectStages::OWNER_FOUND, $actor, ['event_class' => $eventClass, 'note' => 'The owner is linked' . ($this->requiresDeed($agencyId) ? ' and the deed is on record' : '')])) {
                    $moved++;
                }
            } catch (\Throwable $e) {
                Log::warning('Belt: a prospect could not move to Owner found', ['prospect_id' => $p->id, 'message' => $e->getMessage()]);
            }
        }

        return $moved;
    }

    /**
     * The gate for many properties at once (the rows of a list): property id => what is still missing, for those the agent may NOT yet contact. A property
     * that is not in the answer is open. Same rule as refusal(); two queries for the whole page.
     *
     * @param  int[]  $trackedIds
     * @return array<int,list<string>>
     */
    public function blockedMany(User $actor, array $trackedIds): array
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedIds))));
        if ($agencyId <= 0 || $ids === [] || ! config('road-to-sold-belt.enforce_owner_gate', true)) {
            return [];
        }
        $needDeed = $this->requiresDeed($agencyId);
        $tps = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $ids)->get(['id', 'owner_contact_id', 'deeds_captured_at'])->keyBy('id');
        $withDeedRef = $needDeed ? DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)
            ->whereIn('source_type', self::DEED_SOURCES)->whereNull('deleted_at')->pluck('tracked_property_id')->flip() : collect();
        $mine = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->whereIn('tracked_property_id', $ids)
            ->where('user_id', (int) $actor->ownershipUserId())->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')->pluck('stage', 'tracked_property_id');
        $out = [];
        foreach ($ids as $id) {
            $past = isset($mine[$id]) && ProspectStages::order((string) $mine[$id]) >= ProspectStages::order(ProspectStages::OWNER_FOUND);
            if ($past) {
                continue;
            }
            $tp = $tps[$id] ?? null;
            $missing = [];
            if (! $tp || ! $tp->owner_contact_id) {
                $missing[] = 'the owner contact';
            }
            if ($needDeed && ! ($tp && ($tp->deeds_captured_at !== null || isset($withDeedRef[$id])))) {
                $missing[] = 'the deed record';
            }
            if ($missing !== []) {
                $out[$id] = $missing;
            }
        }

        return $out;
    }

    /** The outcomes that always pass: they exist to report that the owner is not known, or must not be contacted. */
    public function exempt(Def $def): bool
    {
        return $def->records_do_not_contact || $def->next_action === Def::ACTION_FIND_OWNER;
    }

    /**
     * The gate (23.6.3): contacting an owner needs the owner found - the file has its owner linked (and the deed on record when the agency
     * needs one), or the agent's belt record is already past the Owner station. Null = allowed.
     */
    public function refusal(User $actor, int $trackedPropertyId, ?Def $def = null): ?string
    {
        if (! config('road-to-sold-belt.enforce_owner_gate', true)) {
            return null;   // a test switch, like enforce_start: the tests written before the gate record outcomes on properties with no owner
        }
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0 || ($def && $this->exempt($def))) {
            return null;
        }
        $p = $this->prospects->find($agencyId, $trackedPropertyId, 'sales');
        $mine = $p && $p->closed_at === null && $p->released_at === null && (int) $p->user_id === (int) $actor->ownershipUserId();
        if ($mine && ProspectStages::order((string) $p->stage) >= ProspectStages::order(ProspectStages::OWNER_FOUND)) {
            return null;   // already past the station (a migrated or earlier record)
        }

        return $this->ownerFound($agencyId, $trackedPropertyId) ? null : self::MESSAGE;
    }
}
