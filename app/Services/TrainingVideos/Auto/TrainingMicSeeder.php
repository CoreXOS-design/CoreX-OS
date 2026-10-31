<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9.11) - the Market Intelligence (MIC) Work tab data of the CoreX Training Academy, for the "Market Intelligence:
 * where to start" how-to. EVERY value is invented: streets that exist nowhere ("Academy Lane", "Tutor Crescent", "Lesson Close", "Example Drive" - checked against the
 * portal street register and every agency's files), owners with the surname Example/Sample, agencies "Sample Realty" / "Harbour Demo Properties" / "Example Estates",
 * advert links on the .invalid domain. No real portal advert, deeds name or owner can appear in a recording (POPIA). Rows are written straight into the agency's own
 * tables (agency_id = the training agency), so nothing here is visible to, or counted for, any other agency.
 *
 * Idempotent: it does nothing when the agency already has adverts; reset() clears this agency's MIC rows first (hard delete is fine - they are throw-away demo
 * rows of a flagged training agency, never client data).
 */
class TrainingMicSeeder
{
    /** @return int number of files seeded (0 = already there) */
    public function seed(Agency $agency, User $agent): int
    {
        if (! $agency->is_training) {
            throw new \RuntimeException('The MIC training data is only ever written to a training agency.');
        }
        $aid = (int) $agency->id;
        if (DB::table('prospecting_listings')->where('agency_id', $aid)->exists()) {   // (not tracked_properties: the agency's own stock already files itself there)
            return 0;
        }
        $now = now();
        $subs = DB::table('p24_suburbs')->whereIn('name', ['Uvongo', 'Margate', 'Shelly Beach', 'Ramsgate'])->whereNull('deleted_at')->pluck('id', 'name');
        $owners = [];
        $owner = function (string $first) use ($aid, &$owners) {
            return $owners[$first] ??= Contact::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $aid, 'first_name' => $first, 'last_name' => 'Example', 'email' => strtolower($first) . '.example@' . TrainingSessionService::EMAIL_DOMAIN,
            ])->id;
        };

        $file = function (array $a) use ($aid, $subs, $now) {
            $suburb = $a['suburb'];

            return DB::table('tracked_properties')->insertGetId([
                'agency_id' => $aid, 'external_id' => (string) Str::uuid(), 'street_number' => $a['no'], 'street_name' => $a['street'], 'suburb' => $suburb, 'suburb_normalised' => mb_strtolower($suburb),
                'town' => 'Margate', 'province' => 'KwaZulu-Natal', 'p24_suburb_id' => $subs[$suburb] ?? null, 'property_type' => $a['type'] ?? 'house', 'bedrooms' => $a['beds'] ?? 3,
                'bathrooms' => $a['baths'] ?? 2, 'garages' => $a['gar'] ?? 1, 'floor_size_m2' => $a['floor'] ?? 180, 'erf_size_m2' => $a['erf'] ?? 800, 'status' => 'active',
                'first_seen_at' => $now->copy()->subDays($a['seen'] ?? 120), 'owner_contact_id' => $a['owner'] ?? null, 'deeds_captured_at' => isset($a['owner']) ? $now->copy()->subDays(30) : null,
                'deeds_registered_date' => $a['registered'] ?? null, 'last_known_sold_price' => $a['sold_price'] ?? null, 'last_known_sold_date' => $a['registered'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        };

        $n = 0;
        $advert = function (int $tp, array $a) use ($aid, $agent, $subs, $now, &$n) {
            $n++;
            $id = DB::table('prospecting_listings')->insertGetId([
                'agency_id' => $aid, 'branch_id' => $agent->branch_id, 'captured_by_user_id' => $agent->id, 'tracked_property_id' => $tp, 'portal_source' => $a['portal'] ?? 'p24',
                'portal_ref' => 'TRN-' . strtoupper($a['portal'] ?? 'p24') . '-' . (9000 + $n), 'canonical_ref' => 'TRN-' . strtoupper($a['portal'] ?? 'p24') . '-' . (9000 + $n),
                'listing_type' => 'sale', 'portal_url' => 'https://training.invalid/listing/' . (9000 + $n), 'address' => $a['address'], 'suburb' => $a['suburb'], 'p24_suburb_id' => $subs[$a['suburb']] ?? null,
                'price' => $a['price'], 'bedrooms' => $a['beds'] ?? 3, 'bathrooms' => $a['baths'] ?? 2, 'garages' => $a['gar'] ?? 1, 'property_size_m2' => $a['floor'] ?? 180, 'erf_size_m2' => $a['erf'] ?? 800,
                'property_type' => $a['type'] ?? 'house', 'agent_name' => $a['agent'] ?? 'Sam Sample', 'agency_name' => $a['agency'], 'advertiser_type' => $a['adv'] ?? 'agency',
                'first_seen_at' => $now->copy()->subDays($a['first']), 'last_seen_at' => $now->copy()->subDays($a['last'] ?? 1), 'listed_date' => $now->copy()->subDays($a['listed'] ?? $a['first'])->toDateString(),
                'listed_date_source' => 'portal', 'is_active' => 1, 'portal_status' => $a['status'] ?? 'active',
                'portal_status_changed_at' => isset($a['status_days']) ? $now->copy()->subDays($a['status_days']) : null, 'is_reduced' => ! empty($a['cuts']) ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $price = $a['price'];
            foreach (array_reverse($a['cuts'] ?? []) as $k => $cut) {   // newest cut first in the input: [[days ago, amount], ...]
                DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $id, 'old_price' => $price + $cut[1], 'new_price' => $price, 'changed_at' => $now->copy()->subDays($cut[0]), 'created_at' => $now, 'updated_at' => $now]);
                $price += $cut[1];
            }

            return $id;
        };

        // 1. Stuck on the market: long on the portal, two cuts, on both portals by different agencies
        $stuck = $file(['no' => '14', 'street' => 'Academy Lane', 'suburb' => 'Uvongo', 'seen' => 150, 'beds' => 4, 'baths' => 3, 'floor' => 240, 'erf' => 900]);
        $advert($stuck, ['address' => '14 Academy Lane, Uvongo', 'suburb' => 'Uvongo', 'price' => 2_150_000, 'beds' => 4, 'baths' => 3, 'floor' => 240, 'erf' => 900, 'agency' => 'Sample Realty',
            'first' => 140, 'listed' => 150, 'last' => 1, 'cuts' => [[20, 100_000], [75, 150_000]]]);
        $advert($stuck, ['portal' => 'pp', 'address' => '14 Academy Lane, Uvongo', 'suburb' => 'Uvongo', 'price' => 2_150_000, 'beds' => 4, 'baths' => 3, 'floor' => 240, 'erf' => 900,
            'agency' => 'Harbour Demo Properties', 'first' => 120, 'last' => 3]);

        // 2. Owner selling privately
        $priv = $file(['no' => '9', 'street' => 'Tutor Crescent', 'suburb' => 'Margate', 'seen' => 25, 'beds' => 3]);
        $advert($priv, ['address' => '9 Tutor Crescent, Margate', 'suburb' => 'Margate', 'price' => 1_295_000, 'agency' => 'Private seller', 'agent' => 'Owner', 'adv' => 'owner', 'first' => 25, 'last' => 12]);

        // 3. Under offer (a trigger for the Neighbours panel) - watched, so its change date is later than its first sighting
        $uo = $file(['no' => '22', 'street' => 'Lesson Close', 'suburb' => 'Shelly Beach', 'seen' => 70, 'owner' => $owner('Lerato')]);
        $advert($uo, ['address' => '22 Lesson Close, Shelly Beach', 'suburb' => 'Shelly Beach', 'price' => 1_780_000, 'agency' => 'Example Estates', 'first' => 60, 'last' => 2, 'status' => 'under_offer', 'status_days' => 6]);

        // 4. Sold (another trigger)
        $sold = $file(['no' => '5', 'street' => 'Example Drive', 'suburb' => 'Ramsgate', 'seen' => 90, 'owner' => $owner('Gert')]);
        $advert($sold, ['address' => '5 Example Drive, Ramsgate', 'suburb' => 'Ramsgate', 'price' => 1_620_000, 'agency' => 'Sample Realty', 'first' => 80, 'status' => 'sold', 'status_days' => 12,
            'last' => 9]);

        // 5. Deeds registered 9 days ago (the third kind of trigger) - not on the market, so it has an advert-less file; its advert (still showing on the portal until the next read) lets it show in the list too
        $reg = $file(['no' => '31', 'street' => 'Academy Lane', 'suburb' => 'Uvongo', 'seen' => 200, 'owner' => $owner('Naledi'), 'registered' => $now->copy()->subDays(9)->toDateString(), 'sold_price' => 2_040_000]);
        DB::table('tracked_property_external_refs')->insert(['agency_id' => $aid, 'tracked_property_id' => $reg, 'source_type' => 'deeds_capture', 'source_ref' => 'TRN-DEEDS-31', 'first_seen_at' => $now, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now]);
        $advert($reg, ['address' => '31 Academy Lane, Uvongo', 'suburb' => 'Uvongo', 'price' => 2_095_000, 'agency' => 'Example Estates', 'first' => 110, 'last' => 8]);

        // 6. Matched by a P24 alert email (the envelope) with a price drop the portal read has not caught yet
        $alert = $file(['no' => '17', 'street' => 'Tutor Crescent', 'suburb' => 'Margate', 'seen' => 40, 'beds' => 3]);
        $advert($alert, ['address' => '17 Tutor Crescent, Margate', 'suburb' => 'Margate', 'price' => 1_450_000, 'agency' => 'Harbour Demo Properties', 'first' => 38, 'last' => 12, 'cuts' => [[10, 50_000]]]);
        $alertId = DB::table('p24_listings')->insertGetId(['agency_id' => $aid, 'p24_listing_number' => 'TRN-ALERT-5001', 'canonical_ref' => 'TRN-P24-' . 9000 + $n, 'tracked_property_id' => $alert, 'asking_price' => 1_395_000,
            'original_price' => 1_450_000, 'property_type' => 'house', 'suburb' => 'Margate', 'bedrooms' => 3, 'bathrooms' => 2, 'garages' => 1, 'listing_status' => 'active', 'p24_url' => 'https://training.invalid/alert/5001',
            'first_seen_date' => $now->copy()->subDays(3)->toDateString(), 'last_seen_date' => $now->copy()->subDays(1)->toDateString(), 'times_seen' => 2, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('p24_price_changes')->insert(['listing_id' => $alertId, 'old_price' => 1_450_000, 'new_price' => 1_395_000, 'change_date' => $now->copy()->subDays(1)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);

        // 7. A property a colleague already claimed - what "Claimed" looks like in the list
        $claimed = $file(['no' => '3', 'street' => 'Example Drive', 'suburb' => 'Ramsgate', 'seen' => 55, 'beds' => 2, 'baths' => 1]);
        $claimedAdvert = $advert($claimed, ['address' => '3 Example Drive, Ramsgate', 'suburb' => 'Ramsgate', 'price' => 985_000, 'beds' => 2, 'baths' => 1, 'floor' => 110, 'erf' => 400,
            'agency' => 'Sample Realty', 'first' => 52, 'last' => 2]);
        $manager = User::withoutGlobalScopes()->where('agency_id', $aid)->where('role', 'branch_manager')->first();
        if ($manager) {
            DB::table('tracked_property_agency_work')->insert(['agency_id' => $aid, 'tracked_property_id' => $claimed, 'prospecting_status' => 'claimed', 'claimed_by_user_id' => $manager->id,
                'claimed_at' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('prospecting_claims')->insert(['agency_id' => $aid, 'prospecting_listing_id' => $claimedAdvert, 'user_id' => $manager->id, 'status' => 'claimed', 'claimed_at' => $now->copy()->subDays(2),
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        // The neighbours of the three triggers: their house numbers on the same street, with the (invented) owners we know from the deeds
        $neighbours = [
            ['Lesson Close', 'Shelly Beach', [16, 18, 20, 24, 26, 21, 23], 'Shelly Beach'],
            ['Example Drive', 'Ramsgate', [1, 7, 9, 11, 2, 4], 'Ramsgate'],
            ['Academy Lane', 'Uvongo', [27, 29, 33, 35, 28, 30], 'Uvongo'],
        ];
        $firstNames = ['Thandi', 'Pieter', 'Anele', 'Willem', 'Busisiwe', 'Johan', 'Nomsa', 'Riaan', 'Zodwa', 'Hennie', 'Palesa', 'Dumisani'];
        $i = 0;
        foreach ($neighbours as [$street, $suburb, $numbers]) {
            foreach ($numbers as $no) {
                $hasOwner = $i % 3 !== 2;   // some neighbours have no deeds owner yet - the list must show that honestly
                $tp = $file(['no' => (string) $no, 'street' => $street, 'suburb' => $suburb, 'seen' => 300, 'owner' => $hasOwner ? $owner($firstNames[$i % count($firstNames)]) : null]);
                if ($i === 1) {   // one neighbour was already phoned
                    DB::table('tracked_property_agency_work_events')->insert(['agency_id' => $aid, 'tracked_property_id' => $tp, 'kind' => 'status', 'user_id' => $agent->id,
                        'occurred_at' => $now->copy()->subDays(4), 'created_at' => $now, 'updated_at' => $now]);
                }
                $i++;
            }
        }

        // Market data last refreshed: a spread of ages (the oldest red) - the approximate "last read" is the newest advert seen per suburb and portal
        DB::table('prospecting_listings')->where('agency_id', $aid)->where('suburb', 'Margate')->update(['last_seen_at' => $now->copy()->subDays(12)]);
        DB::table('prospecting_listings')->where('agency_id', $aid)->where('suburb', 'Ramsgate')->update(['last_seen_at' => $now->copy()->subDays(5)]);

        try {
            \Illuminate\Support\Facades\Artisan::call('mic:refresh-stats', ['--agency' => $aid]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Training MIC seed: mic:refresh-stats failed', ['error' => $e->getMessage()]);
        }
        \Illuminate\Support\Facades\Cache::flush();

        return DB::table('tracked_properties')->where('agency_id', $aid)->count();
    }

    /** Remove this training agency's MIC rows (and only those) so seed() starts clean. */
    public function clear(Agency $agency): void
    {
        if (! $agency->is_training) {
            throw new \RuntimeException('Only a training agency\'s MIC rows may be cleared.');
        }
        $aid = (int) $agency->id;
        $alertIds = DB::table('p24_listings')->where('agency_id', $aid)->pluck('id');
        DB::table('p24_price_changes')->whereIn('listing_id', $alertIds)->delete();
        DB::table('p24_listings')->where('agency_id', $aid)->delete();
        $advertIds = DB::table('prospecting_listings')->where('agency_id', $aid)->pluck('id');
        DB::table('prospecting_price_history')->whereIn('prospecting_listing_id', $advertIds)->delete();
        foreach (['prospecting_claims', 'tracked_property_agency_work_events', 'tracked_property_agency_work', 'tracked_property_external_refs', 'mic_file_stats', 'prospecting_listings', 'tracked_properties'] as $t) {
            DB::table($t)->where('agency_id', $aid)->delete();
        }
    }
}
