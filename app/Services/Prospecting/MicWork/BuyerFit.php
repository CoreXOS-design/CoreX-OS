<?php

namespace App\Services\Prospecting\MicWork;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Services\Matching\MatchingService;

/**
 * "Buyer wants vs property has" for ONE selected buyer (mic-work-tab.md 13.18). Builds nothing of its own: the property file's facts go into the same
 * MatchingService::fit() the filters and the score use (BuyerMatchRules::evaluate), against each of the buyer's active wishlists; the best one is returned
 * (MIC scores every wishlist and keeps the best, so the panel shows the wishlist that made the score).
 *
 * Shape: { first_name, wishlist_id, score, tier, unverified[], fields: [{key,label,wants,has,state}], must_haves: [{label,state}], deal_breakers: [{label,state}] }
 * state: ok | miss | unknown | na.  fields come in the order price, type, suburb, beds, baths, garages, parking, erf, floor.
 */
final class BuyerFit
{
    private const ORDER = ['price', 'type', 'suburb', 'beds', 'baths', 'garages', 'parking', 'erf', 'floor'];

    /** @param \Illuminate\Support\Collection<int, ContactMatch> $wishlists */
    private function __construct(private readonly int $agencyId, private readonly string $firstName, private readonly \Illuminate\Support\Collection $wishlists)
    {
    }

    /** Null when there is no such buyer in this agency or he has no countable wishlist (nothing to compare against). */
    public static function for(int $agencyId, ?int $buyerId): ?self
    {
        if (! $buyerId) {
            return null;
        }
        $buyer = Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_buyer', true)->whereNull('deleted_at')->find($buyerId, ['id', 'first_name', 'last_name']);
        if (! $buyer) {
            return null;
        }
        $lists = ContactMatch::withoutGlobalScopes()->where('agency_id', $agencyId)->where('contact_id', $buyerId)->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)->orderByDesc('is_primary')->orderBy('id')->get()->filter(fn (ContactMatch $m) => $m->isCountable())->values();
        if ($lists->isEmpty()) {
            return null;
        }
        $first = trim((string) $buyer->first_name) !== '' ? trim((string) $buyer->first_name) : trim((string) $buyer->last_name);

        return new self($agencyId, $first, $lists);
    }

    /**
     * @param  array{price:int, suburb:?string, suburb_id:?int, type:?string, beds:mixed, baths:mixed, garages:mixed, erf:mixed, floor:mixed, parking:mixed, features:mixed, listing_type:?string}  $f
     * @return array<string, mixed>
     */
    public function forFacts(array $f): array
    {
        $p = new Property();
        $p->price = $f['price'] ?: null;
        $p->suburb = $f['suburb'] ?? null;
        $p->property_type = $f['type'] ?? null;
        $p->beds = $f['beds'] ?? null;
        $p->baths = $f['baths'] ?? null;
        $p->garages = $f['garages'] ?? null;
        $p->size_m2 = $f['floor'] ?? null;
        $p->erf_size_m2 = $f['erf'] ?? null;
        $p->features_json = $f['features'] ?? null;
        $p->listing_type = $f['listing_type'] ?? null;
        $p->agency_id = $this->agencyId;
        $p->p24_suburb_id = $f['suburb_id'] ?? null;
        $p->setAttribute('parking', $f['parking'] ?? null);

        $matcher = app(MatchingService::class);
        $best = null;
        foreach ($this->wishlists as $m) {
            $fit = $matcher->fit($p, $m);
            if ($best === null || $fit['score'] > $best['score']) {
                $best = $fit + ['wishlist_id' => (int) $m->id];
            }
        }

        $byKey = [];
        foreach ($best['fields'] as $row) {
            $byKey[$row['key']] = $row;
        }
        $fields = [];
        foreach (self::ORDER as $k) {
            if (isset($byKey[$k])) {
                $fields[] = $byKey[$k];
            }
        }

        return [
            'first_name' => $this->firstName, 'wishlist_id' => $best['wishlist_id'], 'score' => $best['score'], 'tier' => $best['tier'], 'unverified' => $best['unverified'],
            'fields' => $fields, 'must_haves' => $best['must_haves'], 'deal_breakers' => $best['deal_breakers'],
        ];
    }
}
