<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds HFC's (agency_id=1) prospecting configuration with KZN South Coast
 * defaults. Idempotent — re-running does not create duplicates.
 *
 * Per spec Section 11 acceptance criteria items 2 + 3:
 *  - 8 towns, ~15 suburbs
 *  - 7 property types
 *  - 5 bedroom segments
 *  - 4 sale + 4 rental price bands
 */
class ProspectingSetupSeeder extends Seeder
{
    private const AGENCY_ID = 1;

    public function run(): void
    {
        $this->seedTownsAndSuburbs();
        $this->seedPropertyTypes();
        $this->seedBedroomSegments();
        $this->seedPriceBands();
    }

    private function seedTownsAndSuburbs(): void
    {
        // [town_name => [suburb_name, suburb_name, ...]]
        $townMap = [
            'Margate'         => ['Margate', 'Uvongo', 'Manaba Beach', 'Ramsgate'],
            'Shelly Beach'    => ['Shelly Beach', 'St Michaels-on-Sea', 'Southbroom'],
            'Port Shepstone'  => ['Port Shepstone', 'Oslo Beach', 'Umtentweni'],
            'Hibberdene'      => ['Hibberdene'],
            'Pumula'          => ['Pumula'],
            'Munster'         => ['Munster'],
            'Trafalgar'       => ['Trafalgar'],
            'Palm Beach'      => ['Palm Beach', 'Marina Beach'],
        ];

        $order = 0;
        foreach ($townMap as $townName => $suburbs) {
            $order++;
            $slug = Str::slug($townName);

            // Idempotent: lookup by (agency_id, slug). Restore from soft-delete if archived.
            $town = DB::table('towns')
                ->where('agency_id', self::AGENCY_ID)
                ->where('slug', $slug)
                ->first();

            if ($town === null) {
                $townId = DB::table('towns')->insertGetId([
                    'agency_id'     => self::AGENCY_ID,
                    'name'          => $townName,
                    'slug'          => $slug,
                    'region'        => 'KZN South Coast',
                    'display_order' => $order,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } else {
                $townId = $town->id;
                // If it was soft-deleted, restore it so the seeder is truly idempotent
                // even on a recovery path. Don't overwrite the agency's edits.
                if ($town->deleted_at !== null) {
                    DB::table('towns')->where('id', $townId)->update([
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach ($suburbs as $suburbName) {
                $normalised = strtolower(trim($suburbName));
                $existing = DB::table('town_suburbs')
                    ->where('agency_id', self::AGENCY_ID)
                    ->where('suburb_normalised', $normalised)
                    ->first();

                if ($existing === null) {
                    DB::table('town_suburbs')->insert([
                        'agency_id'          => self::AGENCY_ID,
                        'town_id'            => $townId,
                        'suburb_name'        => $suburbName,
                        'suburb_normalised'  => $normalised,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                } elseif ($existing->deleted_at !== null) {
                    // Soft-deleted: restore to its current town mapping.
                    DB::table('town_suburbs')->where('id', $existing->id)->update([
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                }
                // Existing-and-not-deleted: leave alone. Agency may have
                // remapped it to a different town; we don't override.
            }
        }
    }

    private function seedPropertyTypes(): void
    {
        $types = [
            ['name' => 'House',         'slug' => 'house',          'display_order' => 1],
            ['name' => 'Townhouse',     'slug' => 'townhouse',      'display_order' => 2],
            ['name' => 'Apartment',     'slug' => 'apartment',      'display_order' => 3],
            ['name' => 'Vacant Land',   'slug' => 'vacant-land',    'display_order' => 4],
            ['name' => 'Farm',          'slug' => 'farm',           'display_order' => 5],
            ['name' => 'Smallholding',  'slug' => 'smallholding',   'display_order' => 6],
            ['name' => 'Commercial',    'slug' => 'commercial',     'display_order' => 7],
        ];

        foreach ($types as $t) {
            $existing = DB::table('property_type_options')
                ->where('agency_id', self::AGENCY_ID)
                ->where('slug', $t['slug'])
                ->first();

            if ($existing === null) {
                DB::table('property_type_options')->insert([
                    'agency_id'     => self::AGENCY_ID,
                    'name'          => $t['name'],
                    'slug'          => $t['slug'],
                    'display_order' => $t['display_order'],
                    'is_active'     => true,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } elseif ($existing->deleted_at !== null) {
                DB::table('property_type_options')->where('id', $existing->id)->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedBedroomSegments(): void
    {
        $segments = [
            ['name' => '1 bed',  'beds_min' => 1, 'beds_max' => 1,    'display_order' => 1],
            ['name' => '2 bed',  'beds_min' => 2, 'beds_max' => 2,    'display_order' => 2],
            ['name' => '3 bed',  'beds_min' => 3, 'beds_max' => 3,    'display_order' => 3],
            ['name' => '4 bed',  'beds_min' => 4, 'beds_max' => 4,    'display_order' => 4],
            ['name' => '5+ bed', 'beds_min' => 5, 'beds_max' => null, 'display_order' => 5],
        ];

        foreach ($segments as $s) {
            $existing = DB::table('bedroom_segments')
                ->where('agency_id', self::AGENCY_ID)
                ->where('beds_min', $s['beds_min'])
                ->where(function ($q) use ($s) {
                    if ($s['beds_max'] === null) {
                        $q->whereNull('beds_max');
                    } else {
                        $q->where('beds_max', $s['beds_max']);
                    }
                })
                ->first();

            if ($existing === null) {
                DB::table('bedroom_segments')->insert(array_merge($s, [
                    'agency_id'  => self::AGENCY_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } elseif ($existing->deleted_at !== null) {
                DB::table('bedroom_segments')->where('id', $existing->id)->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedPriceBands(): void
    {
        // Prices stored as RAND (not cents) — see migration 5 + spec Section 4.5 note.
        $bands = [
            // Sale
            ['listing_type' => 'sale',   'name' => 'Entry',     'price_min' => 0,       'price_max' => 1200000, 'display_order' => 1],
            ['listing_type' => 'sale',   'name' => 'Mid',       'price_min' => 1200000, 'price_max' => 2500000, 'display_order' => 2],
            ['listing_type' => 'sale',   'name' => 'Upper-mid', 'price_min' => 2500000, 'price_max' => 5000000, 'display_order' => 3],
            ['listing_type' => 'sale',   'name' => 'Premium',   'price_min' => 5000000, 'price_max' => null,    'display_order' => 4],
            // Rental
            ['listing_type' => 'rental', 'name' => 'Budget',    'price_min' => 0,       'price_max' => 8000,    'display_order' => 1],
            ['listing_type' => 'rental', 'name' => 'Standard',  'price_min' => 8000,    'price_max' => 15000,   'display_order' => 2],
            ['listing_type' => 'rental', 'name' => 'Upper',     'price_min' => 15000,   'price_max' => 30000,   'display_order' => 3],
            ['listing_type' => 'rental', 'name' => 'Luxury',    'price_min' => 30000,   'price_max' => null,    'display_order' => 4],
        ];

        foreach ($bands as $b) {
            $existing = DB::table('price_bands')
                ->where('agency_id', self::AGENCY_ID)
                ->where('listing_type', $b['listing_type'])
                ->where('price_min', $b['price_min'])
                ->where(function ($q) use ($b) {
                    if ($b['price_max'] === null) {
                        $q->whereNull('price_max');
                    } else {
                        $q->where('price_max', $b['price_max']);
                    }
                })
                ->first();

            if ($existing === null) {
                DB::table('price_bands')->insert(array_merge($b, [
                    'agency_id'  => self::AGENCY_ID,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } elseif ($existing->deleted_at !== null) {
                DB::table('price_bands')->where('id', $existing->id)->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
