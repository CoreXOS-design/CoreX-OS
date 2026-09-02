<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webinar-eve gap fix (2026-09-02) — Johan found zero rental stock on demo.
 *
 * `properties.listing_type` ('sale'|'rental') is the ONLY distinguishing flag —
 * same table, same model as DemoPropertiesSeeder's sale stock (confirmed via
 * codebase read, not guessed). Rental-side statuses are drawn from Property's
 * own always-valid systemStatuses() vocabulary: 'to_let' (available),
 * 'under_offer' (an application/offer is pending — there is no literal
 * "under application" status in the system, this is the honest closest real
 * value), 'rented' (tenanted — Property::CONCLUDED_STATUSES, the rental
 * equivalent of 'sold').
 *
 * Deliberately skips complex_name/unit_number (sectional-title fields) to
 * sidestep the PropertyObserver::saving() address-rewrite gotcha that bit
 * DemoPropertiesSeeder (see that file's 2026-09-02 docblock note) — every row
 * here is a standalone house/cottage/apartment with a plain street address,
 * so the match key (agency_id, address, suburb) is exactly what gets stored,
 * no observer rewrite to chase.
 *
 * Idempotent: firstOrCreate on (agency_id, address, suburb), counts only
 * wasRecentlyCreated. Deterministic house numbers (crc32-seeded, 'rental|'
 * prefixed so they never collide with DemoPropertiesSeeder's sale addresses
 * on the same street/idx).
 */
final class DemoRentalPropertiesSeeder
{
    private const PLAN = [
        // [suburbIdx, type, streetIdx, status]
        [0, 'house',     0, 'to_let'],
        [0, 'apartment', 1, 'to_let'],
        [1, 'house',     0, 'to_let'],
        [1, 'cottage',   1, 'to_let'],
        [2, 'apartment', 0, 'to_let'],
        [2, 'house',     1, 'to_let'],
        [3, 'house',     0, 'under_offer'],
        [3, 'apartment', 1, 'under_offer'],
        [4, 'cottage',   0, 'under_offer'],
        [4, 'house',     1, 'under_offer'],
        [5, 'apartment', 0, 'under_offer'],
        [0, 'house',     2, 'rented'],
        [1, 'apartment', 2, 'rented'],
        [2, 'cottage',   2, 'rented'],
        [3, 'house',     3, 'rented'],
    ];

    public function run(int $agencyId): array
    {
        $gazetteer = array_values(require database_path('seeders/data/kzn_south_coast_suburbs.php'));

        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager', 'admin'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
        // PropertyController::index() defaults to "my listings" (agent_id = the
        // logged-in user) — Johan demos as the admin user, so at least one row
        // per status bucket must be owned by admin or the default screen shows
        // an incomplete/wrong-looking spread. Force the first to_let/under_offer/
        // rented row in PLAN to the admin user; everything else round-robins.
        $adminUser = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->orderBy('id')->first(['id', 'branch_id']);
        $firstIndexPerStatus = [];
        foreach (self::PLAN as $i => [, , , $planStatus]) {
            $firstIndexPerStatus[$planStatus] ??= $i;
        }
        $adminForcedIndexes = array_values($firstIndexPerStatus);
        $branchIds = DB::table('branches')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
        $contactIds = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(60)
            ->pluck('id')
            ->all();

        if (empty($agentIds) || empty($branchIds) || count($contactIds) < 2) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} lacks agents, branches, or contacts."];
        }

        $inserted = 0;
        $linked = 0;

        foreach (self::PLAN as $i => [$suburbIdx, $type, $streetIdx, $status]) {
            $suburb = $gazetteer[$suburbIdx % count($gazetteer)];
            if ($adminUser && in_array($i, $adminForcedIndexes, true)) {
                $agentId = $adminUser->id;
                $branchId = $adminUser->branch_id ?: $branchIds[$i % count($branchIds)];
            } else {
                $agentId = $agentIds[$i % count($agentIds)];
                $branchId = $branchIds[$i % count($branchIds)];
            }

            $row = $this->buildRow($suburb, $type, $streetIdx, $status, $i, $agencyId, $agentId, $branchId);
            $matchKey = ['agency_id' => $agencyId, 'address' => $row['address'], 'suburb' => $row['suburb']];

            $property = Property::firstOrCreate($matchKey, $row);
            if ($property->wasRecentlyCreated) {
                $inserted++;
            } else {
                // Re-run convergence: keep agent/branch/status/price aligned with the
                // current PLAN even if the row already existed (e.g. after the
                // admin-visibility fix above changed which agent owns which row).
                $property->fill(collect($row)->except(['external_id', 'is_demo'])->all());
                $property->save();
            }

            $landlordContactId = $contactIds[$i % count($contactIds)];
            $tenantContactId = $contactIds[($i + 7) % count($contactIds)];

            $links = [$landlordContactId => ['role' => 'landlord']];
            if ($status === 'rented') {
                $links[$tenantContactId] = ['role' => 'tenant'];
            }

            foreach ($links as $contactId => $pivot) {
                $exists = DB::table('contact_property')
                    ->where('property_id', $property->id)
                    ->where('contact_id', $contactId)
                    ->exists();
                if (! $exists) {
                    DB::table('contact_property')->insert([
                        'property_id' => $property->id,
                        'contact_id' => $contactId,
                        'role' => $pivot['role'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $linked++;
                }
            }
        }

        return ['inserted' => $inserted, 'note' => "{$linked} contact links added."];
    }

    private function buildRow(
        array $suburb,
        string $type,
        int $streetIdx,
        string $status,
        int $idx,
        int $agencyId,
        int $agentId,
        int $branchId,
    ): array {
        $bounds = $suburb['bounds'];
        $street = $suburb['streets'][$streetIdx % count($suburb['streets'])];
        $houseNumber = (string) (1 + (crc32('rental|' . $suburb['name'] . '|' . $type . '|' . $idx) % 200));
        $address = $houseNumber . ' ' . $street;

        $hash = crc32('rental-gps|' . $address . '|' . $suburb['name']);
        $cellX = $hash % 4;
        $cellY = intdiv($hash, 4) % 4;
        $cellWidth = ($bounds['east'] - $bounds['west']) / 4;
        $cellHeight = ($bounds['north'] - $bounds['south']) / 4;
        $jitterX = (($hash >> 8) & 0xFF) / 0xFF;
        $jitterY = (($hash >> 16) & 0xFF) / 0xFF;
        $lat = round($bounds['south'] + ($cellY * $cellHeight) + ($jitterY * $cellHeight), 7);
        $lng = round($bounds['west'] + ($cellX * $cellWidth) + ($jitterX * $cellWidth), 7);

        [$beds, $baths, $garages, $propertyType, $rentBand] = match ($type) {
            'apartment' => [random_int(1, 2), 1, 0, 'Apartment / Flat', [8500, 16000]],
            'cottage'   => [random_int(1, 2), 1, 1, 'House', [7500, 13000]],
            default     => [random_int(3, 4), 2, random_int(1, 2), 'House', [14000, 28000]],
        };
        $rent = (int) round(random_int($rentBand[0], $rentBand[1]) / 500) * 500;

        $publishedAt = now()->subDays(random_int(5, 90));
        $leaseStart = $status === 'rented' ? now()->subDays(random_int(30, 300)) : null;
        $leaseEnd = $leaseStart?->copy()->addYear();

        return [
            'external_id' => (string) Str::uuid(),
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'agent_id' => $agentId,
            'title' => $address . ', ' . $suburb['name'] . ' — To Let',
            'address' => $address,
            'suburb' => $suburb['name'],
            'town' => $suburb['town'],
            'city' => $suburb['town'],
            'region' => $suburb['municipality'],
            'province' => 'KwaZulu-Natal',
            'property_type' => $propertyType,
            'listing_type' => 'rental',
            'category' => 'residential',
            'mandate_type' => 'sole',
            'status' => $status,
            'beds' => $beds,
            'baths' => $baths,
            'garages' => $garages,
            'size_m2' => $type === 'apartment' ? random_int(45, 90) : null,
            'erf_size_m2' => $type !== 'apartment' ? random_int(300, 900) : null,
            'price' => $rent,
            'gross_price' => $rent,
            'net_price' => $rent,
            'primary_price_display' => 'gross',
            'rental_amount' => $rent,
            'deposit_amount' => $rent,
            'lease_start_date' => $leaseStart,
            'lease_end_date' => $leaseEnd,
            'latitude' => $lat,
            'longitude' => $lng,
            'geo_source' => 'demo_seed',
            'geo_confidence' => 'exact',
            'geo_resolved_at' => now(),
            'published_at' => $publishedAt,
            'listed_date' => $publishedAt->copy()->subDays(random_int(0, 10)),
            'is_demo' => true,
        ];
    }
}
