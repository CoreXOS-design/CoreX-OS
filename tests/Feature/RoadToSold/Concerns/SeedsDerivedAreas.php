<?php

namespace Tests\Feature\RoadToSold\Concerns;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use Illuminate\Support\Facades\DB;

/**
 * Johan's ruling (13 Oct 2026): an agency's working areas are DERIVED from its own stock and its Market Intelligence data.
 * A test that needs a suburb in an agency's pool therefore gives the agency something there.
 */
trait SeedsDerivedAreas
{
    private int $derivedSeedCounter = 0;

    /**
     * Put a suburb in the agency's pool: one property of the agency's own there (a stock row carries no capture date, so it
     * never changes a "last captured" answer), plus - when $adverts > 0 - a tracked property with that many captured adverts.
     *
     * @param  string|null $seen last_seen_at of the adverts (default: now)
     */
    protected function putInPool(int $agencyId, int $suburbId, int $adverts = 0, string $portal = 'p24', ?string $seen = null, bool $stock = true): void
    {
        $sub = DB::table('p24_suburbs')->where('id', $suburbId)->first();
        $capturer = DB::table('users')->where('agency_id', $agencyId)->value('id')
            ?? \App\Models\User::factory()->create(['agency_id' => $agencyId, 'role' => 'agent', 'is_active' => true])->id;
        $branch = DB::table('users')->where('id', $capturer)->value('branch_id')
            ?? DB::table('branches')->where('agency_id', $agencyId)->value('id')
            ?? \App\Models\Branch::create(['agency_id' => $agencyId, 'name' => 'Seed branch'])->id;

        if ($stock) {
            Property::withoutEvents(fn () => Property::withoutGlobalScopes()->forceCreate([
                'agency_id' => $agencyId, 'agent_id' => $capturer, 'branch_id' => $branch, 'external_id' => substr(md5(uniqid('', true)), 0, 20), 'title' => 'Seed ' . $sub->name . ' ' . (++$this->derivedSeedCounter), 'status' => 'active', 'listing_type' => 'sale',
                'suburb' => $sub->name, 'p24_suburb_id' => $suburbId, 'p24_city_id' => $sub->p24_city_id,
            ]));
        }
        if ($adverts <= 0) {
            return;
        }

        $tp = TrackedProperty::withoutEvents(fn () => TrackedProperty::withoutGlobalScopes()->forceCreate([
            'agency_id' => $agencyId, 'external_id' => substr(md5(uniqid('', true)), 0, 20), 'street_number' => (string) (++$this->derivedSeedCounter), 'street_name' => 'Seed Road',
            'suburb' => $sub->name, 'p24_suburb_id' => $suburbId, 'p24_city_id' => $sub->p24_city_id,
        ]));
        for ($i = 0; $i < $adverts; $i++) {
            DB::table('prospecting_listings')->insert([
                'agency_id' => $agencyId, 'captured_by_user_id' => $capturer, 'tracked_property_id' => $tp->id, 'portal_source' => $portal,
                'portal_ref' => 'seed-' . $suburbId . '-' . $agencyId . '-' . $portal . '-' . (++$this->derivedSeedCounter),
                'suburb' => $sub->name, 'first_seen_at' => $seen ?? now(), 'last_seen_at' => $seen ?? now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
