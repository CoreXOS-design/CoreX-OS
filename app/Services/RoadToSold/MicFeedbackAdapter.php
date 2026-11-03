<?php

namespace App\Services\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\User;

/**
 * Road to Sold v4 (road-to-sold.md 23.7.3): Market Intelligence feedback is retired into the station-4 outcome. POST market-intelligence.feedback stays for old
 * tabs and the API, but on a claim that is on the belt it builds an outcome through OutcomeService::record() - the SAME record "Log contact" writes - with the
 * channel phone and defaults that are agency settings or the outcome's own rule:
 *
 *   contacted      -> "Spoke - call back", dated rts.default_callback_days working days ahead
 *   not_interested -> "Spoke - not now, maybe later" (its own 6-month rule); the property stays on the agent's belt and comes back on that date
 *   meeting_set    -> refused: "Book the evaluation from the belt" (it needs a date and time)
 *   listing        -> refused: "Record the result from the belt" (it needs a result)
 *   lost           -> not handled here: the claim closes and releases as before (the proper result handling is the result service, 23.9)
 *
 * The owner gate has already been applied by the caller; the outcome service applies it again, so the answer is the same either way.
 */
class MicFeedbackAdapter
{
    public function __construct(private readonly OutcomeService $outcomes, private readonly WorkdayService $workdays)
    {
    }

    /** True when this status is handled as an outcome (the claim is on the belt and the status maps to one or is refused with a pointer). */
    public function handles(ProspectingClaim $claim, string $status): bool
    {
        return $claim->rts_prospect_id !== null && in_array($status, [
            ProspectingClaim::STATUS_CONTACTED, ProspectingClaim::STATUS_NOT_INTERESTED, ProspectingClaim::STATUS_MEETING_SET, ProspectingClaim::STATUS_LISTING,
        ], true);
    }

    /** @return array{ok:bool,message:string} */
    public function apply(User $actor, ProspectingClaim $claim, ProspectingListing $listing, string $status, ?string $notes): array
    {
        if ($status === ProspectingClaim::STATUS_MEETING_SET) {
            return ['ok' => false, 'message' => 'Book the appointment from Road to Sold - it needs a date and a time.'];
        }
        if ($status === ProspectingClaim::STATUS_LISTING) {
            return ['ok' => false, 'message' => 'Record the result from Road to Sold - it needs the result of the appointment.'];
        }
        $trackedId = (int) $listing->tracked_property_id;
        $agencyId = (int) $claim->agency_id;
        if ($trackedId <= 0) {
            return ['ok' => false, 'message' => 'This advert has no property file yet, so the contact cannot be logged here.'];
        }

        $key = $status === ProspectingClaim::STATUS_CONTACTED ? 'spoke_call_back' : 'spoke_not_interested';
        app(OutcomeDefinitionService::class)->ensureDefaults($agencyId, 'sales');
        $def = Def::query()->where('agency_id', $agencyId)->where('track', 'sales')->where('key', $key)->where('is_active', true)->whereNull('deleted_at')->first();
        if (! $def) {
            return ['ok' => false, 'message' => 'This agency has switched that outcome off. Use Contact in Road to Sold.'];
        }

        $input = ['channel' => 'phone', 'note' => $notes ?: null, 'prospecting_listing_id' => (int) $listing->id];
        if ($status === ProspectingClaim::STATUS_CONTACTED) {
            $days = SettingsService::forAgency($agencyId)->defaultCallbackDays();
            $input['next_action_at'] = $this->workdays->addWorkdays(now(), $days, SettingsService::forAgency($agencyId)->holidayCountry(), $actor)->toDateString();
        }
        try {
            $o = $this->outcomes->record($actor, $trackedId, (int) $def->id, $input);
        } catch (RoadToSoldException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Logged: ' . $o->outcome_label . '.' . ($o->next_action_at ? ' It comes back on ' . $o->next_action_at->format('j M Y') . '.' : '')];
    }
}
