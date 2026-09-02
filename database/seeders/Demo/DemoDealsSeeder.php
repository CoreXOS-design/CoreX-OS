<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3h Step 6 — synthetic registered deals (demo agency sales history).
 *
 * Picks a subset of demo properties that the property seeder marked 'sold',
 * creates a Deal row per property with plausible commission, registration
 * date in the last 3 years, and round-robin agent assignment.
 *
 * Architectural call-out:
 *   - deals has no property_id FK in this codebase (Phase 3g audit). The
 *     link to a property is via property_address text. We deliberately
 *     match the demo property's address so MapPinService — if it ever
 *     enables the deals branch — can join back.
 *   - Required deals columns: period, deal_date, property_value,
 *     total_commission. We synthesise plausible values.
 *   - The Deal model's $fillable was updated in Step 1 to include is_demo.
 */
final class DemoDealsSeeder
{
    public function run(int $agencyId): array
    {
        // Pick demo properties tagged sold (these came from the
        // DemoPropertiesSeeder 30% sold distribution).
        $soldProps = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('is_demo', true)
            ->where('status', 'sold')
            ->orderBy('id')
            ->limit(120)
            ->get(['id', 'address', 'price', 'suburb', 'agent_id', 'branch_id']);

        if ($soldProps->isEmpty()) {
            return ['inserted' => 0, 'note' => 'No sold demo properties to seed deals from.'];
        }

        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['agent', 'admin', 'branch_manager'])
            ->pluck('id')
            ->all();

        $inserted = 0;
        $cursor = 0;
        // deal_no is an unsigned int — start above the current max to avoid
        // colliding with real deals.
        $startDealNo = (int) (DB::table('deals')->where('agency_id', $agencyId)->max('deal_no') ?? 0);
        $nextDealNo  = max(900_000, $startDealNo + 1_000); // 900k+ reserved for demo
        foreach ($soldProps as $prop) {
            $askingPrice  = (int) ($prop->price ?? 1_000_000);
            // 80% sell within ±5% of ask, 20% bigger discount of -5% to -15%.
            $discountPct  = random_int(1, 100) <= 80
                ? random_int(-5, 5)
                : -random_int(5, 15);
            $salePrice    = (int) round($askingPrice * (1 + $discountPct / 100));
            $salePrice    = (int) (round($salePrice / 10_000) * 10_000);

            $dealDate    = Carbon::now()->subDays(random_int(60, 1095));
            $regDate     = $dealDate->copy()->addDays(random_int(45, 120));
            $commissionRate = 6.0; // 6% gross, typical KZN South Coast
            $totalComm   = (int) round($salePrice * $commissionRate / 100);

            // 2026-09-02 — was a blind ::create(); a re-run duplicated every deal.
            // No property_id FK exists here (see docblock), so property_address
            // is the natural key — deterministic now that DemoPropertiesSeeder
            // itself is idempotent.
            $propertyAddress = $prop->address . ', ' . $prop->suburb;
            $existingDeal = Deal::where('agency_id', $agencyId)
                ->where('property_address', $propertyAddress)
                ->where('is_demo', true)
                ->first();
            if ($existingDeal) {
                continue;
            }

            Deal::create([
                'agency_id'        => $agencyId,
                'branch_id'        => $prop->branch_id,
                'period'           => $regDate->format('Y-m'),
                'deal_date'        => $dealDate,
                'deal_no'          => $nextDealNo + $inserted,
                'file_no'          => 'DEMO/' . $regDate->format('Y') . '/' . ($inserted + 1),
                'property_address' => $propertyAddress,
                'seller_name'      => DemoNames::name('seller:' . $prop->id),
                'buyer_name'       => DemoNames::name('buyer:'  . $prop->id),
                'attorney_name'    => DemoNames::name('attorney:' . $prop->id) . ' Attorneys',
                'property_value'   => $salePrice,
                'total_commission' => $totalComm,
                'accepted_status'  => 'A',
                'commission_status'=> 'registered',
                'registration_date'=> $regDate,
                'granted_at'       => $dealDate->copy()->addDays(7),
                'is_demo'          => true,
            ]);
            $inserted++;
            $cursor++;
            if ($inserted >= 100) break; // Cap at 100 per spec (80-120 band).
        }

        return ['inserted' => $inserted];
    }
}
