<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Expanded mandate (2026-09-02) — buyer wishlists (contact_matches — buyer
 * search-criteria records, not algorithmic match scores) only covered
 * 57/290 contacts (20%). Widens coverage among BUYER-role contacts
 * specifically (the ones a wishlist is actually for), leaving non-buyers
 * and a portion of buyers untouched — not uniform.
 *
 * IDEMPOTENT BY CONSTRUCTION — capped by a total-coverage target computed
 * fresh each run.
 */
class DemoContactWishlistSeeder
{
    private const COVERAGE_TARGET = 115;

    private const SUBURBS = ['Uvongo', 'Margate', 'Shelly Beach', 'Southbroom', 'Manaba Beach', 'Ramsgate', 'Port Shepstone'];
    private const PROPERTY_TYPES = ['House', 'Sectional Title', 'Vacant Land'];

    /** @return array{created:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $alreadyCovered = DB::table('contact_matches')
            ->whereIn('contact_id', DB::table('contacts')->where('agency_id', $agencyId)->pluck('id'))
            ->distinct('contact_id')->pluck('contact_id')->all();

        $need = max(0, self::COVERAGE_TARGET - count($alreadyCovered));
        if ($need === 0) {
            return ['created' => 0, 'note' => 'Skipped — wishlist coverage already at target.'];
        }

        $candidates = DB::table('contacts')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->where('is_buyer', true)
            ->whereNotIn('id', $alreadyCovered)
            ->orderBy('id')->limit($need)
            ->get(['id', 'first_name']);

        $agentIds = DB::table('users')->where('agency_id', $agencyId)->whereIn('role', ['agent', 'admin', 'branch_manager'])->orderBy('id')->pluck('id')->all();
        if ($candidates->isEmpty() || empty($agentIds)) {
            return ['created' => 0, 'note' => 'Skipped — no buyer candidates or no agents.'];
        }

        $created = 0;
        foreach ($candidates as $idx => $contact) {
            $type = self::PROPERTY_TYPES[$idx % count(self::PROPERTY_TYPES)];
            $priceBase = 900_000 + (($idx * 137_000) % 2_400_000);
            $priceMin = (int) (round($priceBase / 50_000) * 50_000);
            $priceMax = (int) (round(($priceBase * 1.35) / 50_000) * 50_000);
            $suburbCount = 1 + ($idx % 3);
            $suburbs = [];
            for ($s = 0; $s < $suburbCount; $s++) {
                $suburbs[] = self::SUBURBS[($idx + $s) % count(self::SUBURBS)];
            }

            DB::table('contact_matches')->insert([
                'agency_id'          => $agencyId,
                'contact_id'         => $contact->id,
                'created_by_user_id' => $agentIds[$idx % count($agentIds)],
                'name'               => trim($contact->first_name) . "'s search",
                'listing_type'       => 'sale',
                'status'             => 'active',
                'is_primary'         => 1,
                'category'           => 'residential',
                'property_type'      => $type,
                'property_types'     => json_encode([$type]),
                'price_min'          => $priceMin,
                'price_max'          => $priceMax,
                'beds_min'           => $type === 'Vacant Land' ? null : (2 + ($idx % 3)),
                'baths_min'          => $type === 'Vacant Land' ? null : (1 + ($idx % 2)),
                'suburbs'            => json_encode($suburbs),
                'last_engaged_at'    => now()->subDays(1 + ($idx % 30)),
                'created_at'         => now()->subDays(5 + ($idx % 60)),
                'updated_at'         => now()->subDays(1 + ($idx % 30)),
            ]);
            $created++;
        }

        return ['created' => $created, 'note' => "Wishlists: +{$created} buyer search-criteria records."];
    }
}
