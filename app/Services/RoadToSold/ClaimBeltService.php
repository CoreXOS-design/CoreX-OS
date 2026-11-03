<?php

namespace App\Services\RoadToSold;

use App\Models\Agency;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Road to Sold v4, station 2: a MIC claim IS the prospect (.ai/specs/road-to-sold.md 23.5, 23.9).
 *
 *   placeClaim()     a claim puts the property on the belt: the agent's prospect at "Claimed" (new, or moved forward from "Found"), the claim
 *                    pointing at it (rts_prospect_id) and on the hold clock (hold_until).
 *   prospectMoved()  the prospect moved to a station: the claim's status follows it (a mirror, never an input) and the hold restarts.
 *   prospectWorked() an outcome or an un-park: the hold restarts.
 *   holderOfFile()   who holds the property file right now (a belt record or a claim on any of its adverts), so a second agent is refused.
 *
 * Never throws into the caller: a claim stands even when the belt cannot take it (Road to Sold off for the agency, no property file yet, an
 * assistant, a story already closed). A claim that is not on the belt keeps the old rules (no hold_until).
 */
class ClaimBeltService
{
    /** Claim status each station mirrors to (23.9). A station that is not listed leaves the claim as it is. */
    private const MIRROR = [
        ProspectStages::FOUND                 => ProspectingClaim::STATUS_CLAIMED,
        ProspectStages::CLAIMED               => ProspectingClaim::STATUS_CLAIMED,
        ProspectStages::OWNER_FOUND           => ProspectingClaim::STATUS_CLAIMED,
        ProspectStages::CONTACTED             => ProspectingClaim::STATUS_CONTACTED,
        ProspectStages::APPOINTMENT_SET       => ProspectingClaim::STATUS_MEETING_SET,
        ProspectStages::PRESENTATION_PREPARED => ProspectingClaim::STATUS_MEETING_SET,
        ProspectStages::EVALUATION_DONE       => ProspectingClaim::STATUS_MEETING_SET,
        ProspectStages::PRESENTATION_GIVEN    => ProspectingClaim::STATUS_MEETING_SET,
        ProspectStages::PRESENTATION_FEEDBACK => ProspectingClaim::STATUS_MEETING_SET,
        ProspectStages::MANDATE_SIGNED        => ProspectingClaim::STATUS_LISTING,
        ProspectStages::DRAFT                 => ProspectingClaim::STATUS_LISTING,
        ProspectStages::ACTIVE                => ProspectingClaim::STATUS_LISTING,
        ProspectStages::LISTING_LIVE          => ProspectingClaim::STATUS_LISTING,
        ProspectStages::ADVERTISING           => ProspectingClaim::STATUS_LISTING,
    ];

    public function __construct(private readonly ProspectService $prospects, private readonly ClaimHoldService $hold)
    {
    }

    public static function claimStatusFor(string $stage): ?string
    {
        return self::MIRROR[$stage] ?? null;
    }

    /** The tracked property (property file) a claim's advert sits on. */
    public function trackedPropertyOf(ProspectingClaim $c): ?int
    {
        $id = DB::table('prospecting_listings')->where('id', $c->prospecting_listing_id)->value('tracked_property_id');

        return $id ? (int) $id : null;
    }

    private function roadToSoldOn(int $agencyId): bool
    {
        $agency = Agency::query()->withoutGlobalScopes()->find($agencyId);

        return $agency !== null && app(AgencyFeatureService::class)->enabled('road-to-sold', $agency);
    }

    /**
     * Put a claim on the belt. Returns the prospect, or null when the claim stays outside it (the claim itself is never touched then).
     * Safe to call again: the same claim twice changes nothing.
     */
    public function placeClaim(ProspectingClaim $c, string $stage = ProspectStages::CLAIMED, ?string $selection = 'claimed_mic', ?string $reason = null): ?RtsProspect
    {
        try {
            if (! $this->hold->available() || ! $c->is_active || $c->released_at !== null || $c->status === ProspectingClaim::STATUS_RESOLVED_OWN_STOCK) {
                return null;
            }
            $trackedId = $this->trackedPropertyOf($c);
            if ($trackedId === null || ! $this->roadToSoldOn((int) $c->agency_id)) {
                return null;
            }
            $agent = User::query()->withoutGlobalScopes()->where('agency_id', $c->agency_id)->find($c->user_id);
            if (! $agent || ! $agent->is_active || $agent->is_assistant) {
                return null;
            }
            $existing = $this->prospects->find((int) $c->agency_id, $trackedId, 'sales');
            if ($existing && $existing->closed_at !== null) {
                return null;   // that story is over (do not contact, registered...); a late claim does not restart it
            }

            // the record sits at Claimed since the day the claim was TAKEN (an older claim brought onto the belt now is not "0 days")
            $enteredAt = $stage === ProspectStages::CLAIMED && $c->claimed_at ? Carbon::instance($c->claimed_at) : null;
            $p = $this->prospects->place($agent, $trackedId, 'sales', [
                'prospecting_listing_id' => (int) $c->prospecting_listing_id, 'property_id' => $c->property_id,
                'selection' => $selection, 'stage' => $stage,
                'reason' => $reason ?? 'Claimed in Market Intelligence on ' . now()->format('j M Y'), 'actor' => $agent,
                'entered_at' => $enteredAt,
            ]);
            if (! $p) {
                return null;   // another agent's record (22.6)
            }
            if (ProspectStages::order((string) $p->stage) < ProspectStages::order($stage)) {
                $this->prospects->advance($p, $stage, $agent, [
                    'event_class' => 'ClaimCreated', 'subject_type' => 'prospecting_claim', 'subject_id' => (int) $c->id, 'note' => 'Claimed in Market Intelligence',
                    'entered_at' => $enteredAt,
                ]);
                $p->refresh();
            }
            $this->hold->start($c, $p);

            return $p;
        } catch (\Throwable $e) {
            Log::warning('Belt: a claim could not be put on the belt (the claim stands)', ['claim_id' => $c->id ?? null, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** The prospect moved to a station: mirror it onto the claim's status and restart the hold. */
    public function prospectMoved(RtsProspect $p): void
    {
        try {
            if (! $this->hold->available()) {
                return;
            }
            $status = self::claimStatusFor((string) $p->stage);
            if ($status !== null) {
                $claims = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $p->agency_id)->where('rts_prospect_id', $p->id)
                    ->where('is_active', true)->whereNull('released_at')->get();
                foreach ($claims as $c) {
                    if ($c->status !== $status) {
                        $c->update(['status' => $status, 'last_updated_at' => now()]);
                    }
                }
            }
            $this->hold->restartForProspect($p);
        } catch (\Throwable $e) {
            Log::warning('Belt: the claim could not follow the prospect (the move stands)', ['prospect_id' => $p->id ?? null, 'message' => $e->getMessage()]);
        }
    }

    /** An outcome was recorded or the prospect came back from parked: the hold counts from today again. */
    public function prospectWorked(RtsProspect $p): void
    {
        try {
            $this->hold->restartForProspect($p);
        } catch (\Throwable $e) {
            Log::warning('Belt: the claim hold could not restart (the work stands)', ['prospect_id' => $p->id ?? null, 'message' => $e->getMessage()]);
        }
    }

    /**
     * The agent who holds this property file, other than $exceptOwnerId: a live belt record, or an active claim on any advert of the file.
     * Null when it is free to take. A claim whose hold has ended does not hold it (it is released here, by the rule that applies to it).
     */
    public function holderOfFile(int $agencyId, int $trackedPropertyId, int ...$exceptUserIds): ?int
    {
        $this->releaseEndedOnFile($agencyId, $trackedPropertyId, $exceptUserIds);

        $owner = $this->prospects->ownerOf($agencyId, $trackedPropertyId, 'sales');
        if ($owner !== null && ! in_array($owner, $exceptUserIds, true)) {
            return $owner;
        }
        $other = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereNotIn('user_id', $exceptUserIds ?: [0])
            ->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')
            ->whereIn('prospecting_listing_id', DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->select('id'))
            ->value('user_id');

        return $other ? (int) $other : null;
    }

    /** @param int[] $exceptUserIds */
    private function releaseEndedOnFile(int $agencyId, int $trackedPropertyId, array $exceptUserIds): void
    {
        $claims = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->whereNotIn('user_id', $exceptUserIds ?: [0])
            ->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')
            ->whereIn('prospecting_listing_id', DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->select('id'))
            ->get();
        foreach ($claims as $c) {
            $this->hold->releaseIfExpired($c);
        }
    }
}
