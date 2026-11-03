<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Support\Facades\DB;

/**
 * road-to-sold.md 23.23 drop 5: the screen behind each pill of the pitch popup, for THIS record.
 *
 * A pill is a control only when its screen exists for the record, so the popup asks once, when it opens, and every pill whose answer is null stays a plain
 * label. The popup knows one of: the advert (listing), the agent's Road to Sold record (prospect) or the tracked property. Property is the MIC card (when
 * the record has an advert); the rest:
 *   owner       the compose screen with the live deed picker (the advert's capture, or the tracked property's when the advert has already been pitched)
 *   pitch       "pitch to sellers" - only when the advert has its CoreX property
 *   contact     the outreach screen on the owner and the property
 *   evaluation  the calendar's own form (PitchBookService); the agent reads it as "Appointment" (road-to-sold.md 23.25), the key is a stored name and stays
 *   reached     step => bool: has the record's STATION been reached (23.25.3). The popup draws its ticks from this and from nothing else; a screen merely opened is not a tick.
 * Nothing is created here (no property, no claim, no lock): it only names screens that already exist.
 */
final class PitchStepsService
{
    /** The five steps the popup draws today, in order. */
    private const STEPS = ['property', 'owner', 'pitch', 'contact', 'evaluation'];

    public function __construct(private readonly PitchBeltResolver $belt, private readonly PitchBookService $book)
    {
    }

    /**
     * @param array<string,string> $carry the popup parameters to keep (embed, rts_prospect, rts_stage)
     * @return array<string,mixed> property|owner|pitch|contact|evaluation => screen address or null, `address` (the property's, for the title) and `reached` (step => bool, the ticks)
     */
    public function plan(User $actor, ?int $listingId, ?int $prospectId, ?int $trackedId, array $carry = []): array
    {
        $agencyId = (int) ($actor->effectiveAgencyId() ?: 0);
        $out = ['property' => null, 'owner' => null, 'pitch' => null, 'contact' => null, 'evaluation' => null, 'address' => null, 'reached' => array_fill_keys(self::STEPS, false)];
        if ($agencyId <= 0) {
            return $out;
        }
        $carry = ['embed' => '1'] + array_filter($carry, fn ($v) => is_string($v) && $v !== '');

        $prospect = $prospectId
            ? RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('user_id', (int) $actor->ownershipUserId())->whereNull('deleted_at')->find($prospectId)
            : null;
        $listing = null;
        $lid = $listingId ?: ($prospect?->prospecting_listing_id ? (int) $prospect->prospecting_listing_id : null);
        if ($lid) {
            $listing = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($lid);
        }
        $tpId = $prospect ? (int) $prospect->tracked_property_id : ($trackedId ?: ($listing?->tracked_property_id ? (int) $listing->tracked_property_id : null));
        $tp = $tpId ? TrackedProperty::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->find($tpId) : null;
        // opened from Market Intelligence the popup knows the advert, not the record: the agent's own open record on that file is the one
        $prospect ??= $tpId ? $this->belt->openProspectFor($actor, (int) $tpId) : null;
        if ($prospect && ! isset($carry['rts_prospect'])) {
            $carry['rts_prospect'] = (string) $prospect->id;
        }

        // the property's address, for the popup's title (the same wording on every step)
        $out['address'] = trim((string) ($listing?->address ?? '')) !== '' ? trim((string) $listing->address) : (trim((string) $tp?->displayAddress()) ?: null);

        // the MIC card (the same one-card page the popup builds for itself when it knows the advert)
        if ($listing) {
            $out['property'] = route('market-intelligence.work', ['_pitch_card' => $listing->id, 'embed' => 1]);
        }

        // the screen where the owner and the deed are found
        if ($listing && empty($listing->pitched_at)) {
            $out['owner'] = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listing->id] + $carry);
        } elseif ($tp) {
            $out['owner'] = route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => $tp->id] + $carry);
        }

        $propertyId = $prospect?->property_id ?: ($listing?->matched_property_id ?: ($tp?->promoted_to_property_id));
        $property = $propertyId ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $propertyId) : null;

        if ($listing && $listing->matched_property_id && $property) {
            $out['pitch'] = route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listing->id] + $carry);
        }

        $ownerId = $tp?->owner_contact_id;
        $owner = $ownerId ? Contact::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $ownerId) : null;
        if ($owner && $property) {
            $out['contact'] = route('seller-outreach.composer.show', ['contact' => $owner->id, 'property_id' => $property->id, 'channel' => 'whatsapp'] + $carry);
            $out['evaluation'] = $this->book->url($property, $owner, $carry);
        } elseif ($owner && $prospect && app(\App\Services\RoadToSold\OwnerStationService::class)->ownerFound($agencyId, (int) $tpId)) {
            // the owner is on the file and the deed is on record but the record has no CoreX property yet: the belt's own steps make it (once, for the record) and open the screen
            $out['contact'] = route('road-to-sold.prospects.step', ['prospect' => $prospect->id, 'step' => 'pitch'] + $carry);
            $out['evaluation'] = route('road-to-sold.prospects.step', ['prospect' => $prospect->id, 'step' => 'evaluation'] + $carry);
        }

        $out['reached'] = $this->reached($actor, $agencyId, $prospect, $propertyId ? (int) $propertyId : null);

        return $out;
    }

    /**
     * road-to-sold.md 23.25.3: a step is ticked when its station is reached, read from the agent's own record (`rts_prospects.stage`, the same order the funnel's
     * "reached" rule counts with: a record placed at a later stage counts the stations before it; a parked record keeps its stage and so its ticks). No record = a
     * MIC advert not yet claimed = nothing reached. Opening a screen ticks nothing.
     *
     * @return array<string,bool>
     */
    private function reached(User $actor, int $agencyId, ?RtsProspect $prospect, ?int $propertyId): array
    {
        $none = array_fill_keys(self::STEPS, false);
        if (! $prospect || ! ProspectStages::has((string) $prospect->stage)) {
            return $none;
        }
        $at = ProspectStages::order((string) $prospect->stage);
        $atLeast = fn (string $stage): bool => $at >= ProspectStages::order($stage);
        $pitched = $atLeast(ProspectStages::CONTACTED) || ($propertyId && DB::table('seller_outreach_sends')->where('agency_id', $agencyId)->where('property_id', $propertyId)
            ->whereIn('agent_id', array_unique([(int) $actor->id, (int) $actor->ownershipUserId()]))->whereNull('deleted_at')->exists());

        return [
            'property' => $atLeast(ProspectStages::FOUND),
            'owner' => $atLeast(ProspectStages::OWNER_FOUND),
            'pitch' => (bool) $pitched,
            'contact' => $atLeast(ProspectStages::CONTACTED),
            'evaluation' => $atLeast(ProspectStages::APPOINTMENT_SET),
        ];
    }
}
