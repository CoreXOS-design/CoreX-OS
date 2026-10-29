<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Support\Facades\DB;

/**
 * The shared pool of PUBLIC advert facts (.ai/specs/property-identity.md §3; Johan, 29 Oct: "the shared pool holds
 * public portal facts only"). Keyed by canonical advert reference, global (no agency_id), enriched by every agency's
 * scrapes. The ONLY reader/writer of `portal_advert_facts`.
 *
 * Privacy by construction: both directions go through ALLOWED, an explicit column allow-list. A fact outside it —
 * erf, title deed, SG code, owner, claim, status, deed price — is dropped on the way in and can never come out.
 * The pool does not record which agency contributed, and what comes back carries no tracked-property id.
 */
final class SharedPortalPool
{
    /** incoming fact key => pool column. Everything else is dropped. */
    private const ALLOWED = [
        'address'                 => 'address_text',
        'street_number'           => 'street_number',
        'street_name'             => 'street_name',
        'unit_number'             => 'unit_number',
        'complex_name'            => 'complex_name',
        'suburb'                  => 'suburb',
        'town'                    => 'town',
        'province'                => 'province',
        'latitude'                => 'latitude',
        'longitude'               => 'longitude',
        'geo_confidence'          => 'geo_confidence',
        'last_known_asking_price' => 'price',
        'bedrooms'                => 'bedrooms',
        'bathrooms'               => 'bathrooms',
        'garages'                 => 'garages',
        'floor_size_m2'           => 'floor_size_m2',
        'erf_size_m2'             => 'erf_size_m2',
        'property_type'           => 'property_type',
        'listing_agent_name'      => 'listing_agent_name',
        'listing_agency_name'     => 'listing_agency_name',
        'portal_status'           => 'status',
    ];

    private const STATUSES = ['active', 'sold', 'withdrawn', 'unknown'];

    /** Add what an agency's scrape saw of this advert. Existing values are never blanked; a changed price is logged. */
    public function contribute(string $canonicalRef, array $facts): void
    {
        $split = \App\Support\Property\PortalRef::split($canonicalRef);
        if ($split === null) {
            return;
        }

        $new = [];
        foreach (self::ALLOWED as $key => $column) {
            $v = $facts[$key] ?? null;
            if ($v === null || $v === '' || (is_string($v) && in_array(strtolower(trim($v)), ['-', 'n/a', 'na'], true))) {
                continue;
            }
            $new[$column] = is_string($v) ? trim($v) : $v;
        }
        if (isset($new['suburb'])) {
            $new['suburb_normalised'] = TrackedPropertyAddress::normaliseSuburb($new['suburb']);
        }
        if (isset($new['status']) && ! in_array($new['status'], self::STATUSES, true)) {
            unset($new['status']);
        }
        // a pin is only worth sharing together with how precise it is
        if ((isset($new['latitude']) || isset($new['longitude'])) && (! isset($new['latitude'], $new['longitude']) || ! isset($new['geo_confidence']))) {
            unset($new['latitude'], $new['longitude'], $new['geo_confidence']);
        }
        if (isset($new['price'])) {
            $new['price'] = (int) round((float) $new['price']);
        }

        DB::transaction(function () use ($canonicalRef, $split, $new) {
            $row = DB::table('portal_advert_facts')->where('canonical_ref', $canonicalRef)->lockForUpdate()->first();
            $now = now();

            if ($row === null) {
                $history = isset($new['price']) ? [['price' => $new['price'], 'at' => $now->toDateString()]] : [];
                DB::table('portal_advert_facts')->insert(array_merge($new, [
                    'canonical_ref' => $canonicalRef, 'portal' => $split[0], 'listing_id' => $split[1],
                    'status' => $new['status'] ?? 'unknown', 'first_seen_at' => $now, 'last_seen_at' => $now,
                    'price_history' => $history ? json_encode($history) : null, 'seen_count' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]));

                return;
            }

            $update = $new;
            if (isset($new['price']) && (int) $row->price !== $new['price']) {
                $history = json_decode((string) $row->price_history, true) ?: [];
                $history[] = ['price' => $new['price'], 'at' => $now->toDateString()];
                $update['price_history'] = json_encode(array_slice($history, -20));
            }
            // a pin and its precision move together: only replace an existing pin with an equally or more precise one
            if (isset($update['latitude']) && $row->latitude !== null && ! $this->atLeastAsPrecise((string) ($update['geo_confidence'] ?? ''), (string) $row->geo_confidence)) {
                unset($update['latitude'], $update['longitude'], $update['geo_confidence']);
            }
            DB::table('portal_advert_facts')->where('id', $row->id)->update(array_merge($update, [
                'last_seen_at' => $now, 'seen_count' => (int) $row->seen_count + 1, 'updated_at' => $now,
            ]));
        });
    }

    /**
     * The public facts held for an advert, as incoming-fact keys (ready to pre-fill a new tracked property).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $canonicalRef): ?array
    {
        $row = DB::table('portal_advert_facts')->where('canonical_ref', $canonicalRef)->first();
        if ($row === null) {
            return null;
        }

        $out = [];
        foreach (self::ALLOWED as $key => $column) {
            if ($key === 'portal_status') {
                continue;
            }
            if ($row->{$column} !== null && $row->{$column} !== '') {
                $out[$key] = $row->{$column};
            }
        }
        $out['portal_status'] = $row->status;

        return $out;
    }

    private function atLeastAsPrecise(string $incoming, string $existing): bool
    {
        $rank = ['' => 0, 'suburb' => 1, 'street' => 2, 'high' => 3, 'exact' => 4];

        return ($rank[$incoming] ?? 0) >= ($rank[$existing] ?? 0);
    }
}
