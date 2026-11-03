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
 *   presentation, results, draft, active  steps 6-9 (23.25): the property's Generate Presentation window, the feedback / Result form, and the property's draft and active gates -
 *               each ONE hop from the agent's own record (`road-to-sold/prospects/{id}/...`), so only a record they own has them; a step with no screen yet is null (a plain label)
 *   reached     step => bool: has the record's STATION been reached (23.25.3). The popup draws its ticks from this and from nothing else; a screen merely opened is not a tick.
 * Nothing is created here (no property, no claim, no lock): it only names screens that already exist.
 */
final class PitchStepsService
{
    /** The seven steps the popup draws, in order (23.30): Owner · Contact · Appointment · Presentation · Results · Draft · Active. The property card is the popup's header, not a step; choosing the seller is the top of Contact. */
    private const STEPS = ['owner', 'contact', 'evaluation', 'presentation', 'results', 'draft', 'active'];

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
        $out = ['property' => null, 'owner' => null, 'contact' => null, 'evaluation' => null, 'presentation' => null, 'results' => null, 'draft' => null, 'active' => null, 'address' => null, 'deed' => null, 'start' => 'owner', 'card' => null, 'reached' => array_fill_keys(self::STEPS, false)];
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
        $out['address'] = AdvertAddress::of($listing, $tp);

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

        // Contact: the composer on the owner; with the advert's property but no owner of record yet, the seller list (inside the popup it goes straight to the composer on the primary seller)
        if ($listing && $listing->matched_property_id && $property) {
            $out['contact'] = route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listing->id] + $carry);
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

        // the linked property's own (deed) wording of the address, only when it differs from the advert's - one small line under the header
        $out['deed'] = AdvertAddress::deedLine($property, $out['address']);
        $out['reached'] = $this->reached($prospect);
        $out['card'] = $listing ? $this->card($agencyId, $listing) : null;

        if ($prospect && ProspectStages::has((string) $prospect->stage)) {
            $at = ProspectStages::order((string) $prospect->stage);
            $atLeast = fn (string $stage): bool => $at >= ProspectStages::order($stage);
            $step = fn (string $name, array $params = []) => route($name, $params + ['prospect' => $prospect->id] + $carry);
            // 6 Presentation: the property is made from the advert's address when the record has none (the path does that), so it needs the property or the owner
            if ($property || $owner) {
                $out['presentation'] = $step('road-to-sold.prospects.presentation-path');
            }
            // 7 Presentation results: once an appointment is booked - the calendar's feedback form, then the Result form (the path picks by the record's station)
            if ($atLeast(ProspectStages::APPOINTMENT_SET)) {
                $out['results'] = $step('road-to-sold.prospects.results-path');
            }
            // 8 Draft and 9 Active: the property's own checklist and gates - a screen only once there is a property and the record has won / reached Draft
            if ($property && $atLeast(ProspectStages::MANDATE_SIGNED)) {
                $out['draft'] = $step('road-to-sold.prospects.property-step', ['step' => 'draft']);
            }
            if ($property && $atLeast(ProspectStages::DRAFT)) {
                $out['active'] = $step('road-to-sold.prospects.property-step', ['step' => 'active']);
            }
        }

        // where the popup opens when nobody names a step: the record's OWN stage (23.30); a step with no screen falls back to the nearest one before it
        $out['start'] = $this->startFor($prospect, $out);

        return $out;
    }

    /** Today's work / Claimed -> Owner; Owner found -> Contact; Contacted -> Appointment; Booked -> Presentation; Prepared / Held -> Results; Won / Draft -> Draft; Active -> Active. */
    private function startFor(?RtsProspect $prospect, array $plan): string
    {
        if (! $prospect || ! ProspectStages::has((string) $prospect->stage)) {
            return 'owner';
        }
        $at = ProspectStages::order((string) $prospect->stage);
        $want = match (true) {
            $at >= ProspectStages::order(ProspectStages::ACTIVE) => 'active',
            $at >= ProspectStages::order(ProspectStages::MANDATE_SIGNED) => 'draft',
            $at >= ProspectStages::order(ProspectStages::PRESENTATION_PREPARED) => 'results',
            $at >= ProspectStages::order(ProspectStages::APPOINTMENT_SET) => 'presentation',
            $at >= ProspectStages::order(ProspectStages::CONTACTED) => 'evaluation',
            $at >= ProspectStages::order(ProspectStages::OWNER_FOUND) => 'contact',
            default => 'owner',
        };
        $i = array_search($want, self::STEPS, true);
        for (; $i >= 0; $i--) {
            if (! empty($plan[self::STEPS[$i]])) {
                return self::STEPS[$i];
            }
        }

        return 'owner';
    }

    /**
     * The popup's header, folded to one line (23.30): photo, price, address, beds / baths / erf, listing agency, days on market, score. The same file record the advert card reads
     * (MicWorkList::forFiles), so the line and the card unfolded under it can never disagree. Null when the advert cannot be read: the header is then not shown.
     *
     * @return array<string,mixed>|null
     */
    private function card(int $agencyId, ProspectingListing $listing): ?array
    {
        try {
            $key = $listing->tracked_property_id ? (int) $listing->tracked_property_id : -(int) $listing->id;
            $rec = app(\App\Services\Prospecting\MicWork\MicWorkList::class)->forFiles([$key], $agencyId, null, null)[0] ?? null;
            if (! $rec) {
                return null;
            }
            $f = $rec['facts'] ?? [];
            $bits = array_values(array_filter([
                ! empty($f['beds']) ? $f['beds'] . ' bed' : null,
                ! empty($f['baths']) ? $f['baths'] . ' bath' : null,
                ! empty($f['erf']) ? number_format((float) $f['erf']) . ' m² erf' : null,
            ]));
            $photos = is_array($rec['photo_urls'] ?? null) ? array_values(array_filter($rec['photo_urls'], 'is_string')) : [];
            $since = ! empty($rec['listed_date']) ? \Carbon\Carbon::parse($rec['listed_date']) : ($listing->first_seen_at ?? null);

            return [
                'listing' => (int) $listing->id,
                'photo' => $photos[0] ?? null,
                'price' => ! empty($rec['price']) ? 'R ' . number_format((float) $rec['price'], 0, '.', ' ') : null,
                'address' => trim((string) ($rec['address'] ?? '') . (! empty($rec['address']) && ! empty($rec['suburb']) ? ', ' : '') . (string) ($rec['suburb'] ?? '')),
                'facts' => implode(' · ', $bits),
                'agency' => ! empty($rec['agencies'][0]) ? (string) $rec['agencies'][0] : null,
                'dom' => $since ? (int) \Carbon\Carbon::parse($since)->diffInDays(now()) . ' days' : null,
                'score' => isset($rec['score']) && $rec['score'] !== null ? (int) round((float) $rec['score']) : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * road-to-sold.md 23.25.3: a step is ticked when its station is reached, read from the agent's own record (`rts_prospects.stage`, the same order the funnel's
     * "reached" rule counts with: a record placed at a later stage counts the stations before it; a parked record keeps its stage and so its ticks). No record = a
     * MIC advert not yet claimed = nothing reached. Opening a screen ticks nothing.
     *
     * @return array<string,bool>
     */
    private function reached(?RtsProspect $prospect): array
    {
        $none = array_fill_keys(self::STEPS, false);
        if (! $prospect || ! ProspectStages::has((string) $prospect->stage)) {
            return $none;
        }
        $at = ProspectStages::order((string) $prospect->stage);
        $atLeast = fn (string $stage): bool => $at >= ProspectStages::order($stage);

        return [
            'owner' => $atLeast(ProspectStages::OWNER_FOUND),
            'contact' => $atLeast(ProspectStages::CONTACTED),
            'evaluation' => $atLeast(ProspectStages::APPOINTMENT_SET),
            'presentation' => $atLeast(ProspectStages::PRESENTATION_PREPARED),
            'results' => $atLeast(ProspectStages::EVALUATION_DONE),
            'draft' => $atLeast(ProspectStages::DRAFT),
            'active' => $atLeast(ProspectStages::ACTIVE),
        ];
    }
}
