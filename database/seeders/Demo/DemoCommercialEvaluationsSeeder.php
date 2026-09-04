<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CommercialEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — orphan screen (nobody's slice all night, per
 * cc6's audit). `commercial-evaluations.*` is confirmed real, mature,
 * actively-maintained code (full resource controller + 7 child models +
 * 14 migrations through May 2026) with zero demo rows — a pure data gap,
 * not a half-built feature. `evaluation.index` (the deeds/prospecting
 * search tool) was investigated separately and needs no seeding — its
 * "0 rows" is the tool's normal pre-query state, not missing data.
 *
 * 6 evaluations, KZN South Coast, varied property_type and status so the
 * index doesn't read as one template repeated. Prices are integer cents
 * (Rand × 100) per the model's own cast.
 *
 * Idempotent: matched on (agency_id, property_name, suburb).
 */
final class DemoCommercialEvaluationsSeeder
{
    private const PLAN = [
        [
            'property_type' => 'commercial',
            'property_name' => 'Marine Drive Retail Centre',
            'address' => '212 Marine Drive', 'suburb' => 'Margate', 'town' => 'Margate',
            'erf_number' => 'Erf 1284', 'zoning' => 'Business 1',
            'land_m2' => 1850, 'building_m2' => 1120, 'year_built' => 2008,
            'condition' => 'good', 'asking' => 18500000, 'municipal' => 15200000,
            'status' => 'completed', 'range' => [17200000, 18100000, 19000000], 'method' => 'income',
        ],
        [
            'property_type' => 'industrial',
            'property_name' => 'Southport Industrial Warehouse',
            'address' => '14 Industria Road', 'suburb' => 'Southport', 'town' => 'Port Shepstone',
            'erf_number' => 'Erf 640', 'zoning' => 'Industrial 1',
            'land_m2' => 4200, 'building_m2' => 2600, 'year_built' => 1998,
            'condition' => 'fair', 'asking' => 9800000, 'municipal' => 7600000,
            'status' => 'draft', 'range' => null, 'method' => null,
        ],
        [
            'property_type' => 'hospitality',
            'property_name' => 'Ramsgate Guest Lodge',
            'address' => '8 Lagoon Drive', 'suburb' => 'Ramsgate', 'town' => 'Ramsgate',
            'erf_number' => 'Erf 91', 'zoning' => 'Special (Guest House)',
            'land_m2' => 2400, 'building_m2' => 980, 'year_built' => 2012,
            'condition' => 'excellent', 'asking' => 12500000, 'municipal' => 9800000,
            'status' => 'completed', 'range' => [11400000, 12200000, 13100000], 'method' => 'comparable',
        ],
        [
            'property_type' => 'agricultural',
            'property_name' => 'Oribi Gorge Macadamia Farm',
            'address' => 'Farm 212, Oribi Flats Road', 'suburb' => 'Oribi Gorge', 'town' => 'Paddock',
            'erf_number' => 'Portion 4 of Farm 212', 'zoning' => 'Agricultural',
            'land_ha' => 48.5, 'building_m2' => 340, 'year_built' => 1985,
            'condition' => 'fair', 'asking' => 6200000, 'municipal' => 4900000,
            'status' => 'draft', 'range' => null, 'method' => null,
        ],
        [
            'property_type' => 'commercial',
            'property_name' => 'St Michaels Office Park',
            'address' => '45 Compensation Beach Road', 'suburb' => 'St Michaels-on-Sea', 'town' => 'Margate',
            'erf_number' => 'Erf 733', 'zoning' => 'Business 2',
            'land_m2' => 2100, 'building_m2' => 1450, 'year_built' => 2015,
            'condition' => 'good', 'asking' => 21000000, 'municipal' => 17500000,
            'status' => 'draft', 'range' => null, 'method' => null,
        ],
        [
            'property_type' => 'industrial',
            'property_name' => 'Marburg Distribution Depot',
            'address' => '3 Freight Lane', 'suburb' => 'Marburg', 'town' => 'Port Shepstone',
            'erf_number' => 'Erf 55', 'zoning' => 'Industrial 2',
            'land_m2' => 5600, 'building_m2' => 3400, 'year_built' => 1992,
            'condition' => 'poor', 'asking' => 7400000, 'municipal' => 5200000,
            'status' => 'archived', 'range' => [6100000, 6600000, 7100000], 'method' => 'cost',
        ],
    ];

    public function run(int $agencyId): array
    {
        $branchId = DB::table('branches')->where('agency_id', $agencyId)->orderBy('id')->value('id');
        $agentId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->value('id')
            ?? DB::table('users')->where('agency_id', $agencyId)->value('id');

        if (! $branchId || ! $agentId) {
            return ['created' => 0, 'note' => "Skipped — agency {$agencyId} lacks a branch or user."];
        }

        $created = 0;
        foreach (self::PLAN as $plan) {
            $existing = CommercialEvaluation::where('agency_id', $agencyId)
                ->where('property_name', $plan['property_name'])
                ->where('suburb', $plan['suburb'])
                ->first();
            if ($existing) {
                continue;
            }

            $range = $plan['range'];
            CommercialEvaluation::create([
                'agency_id' => $agencyId,
                'created_by_user_id' => $agentId,
                'branch_id' => $branchId,
                'status' => $plan['status'],
                'property_type' => $plan['property_type'],
                'property_name' => $plan['property_name'],
                'address' => $plan['address'],
                'suburb' => $plan['suburb'],
                'town' => $plan['town'],
                'province' => 'KwaZulu-Natal',
                'erf_number' => $plan['erf_number'],
                'zoning' => $plan['zoning'],
                'total_land_size_m2' => $plan['land_m2'] ?? null,
                'total_land_size_ha' => $plan['land_ha'] ?? null,
                'total_building_size_m2' => $plan['building_m2'],
                'year_built' => $plan['year_built'],
                'condition' => $plan['condition'],
                'asking_price' => $plan['asking'],
                'municipal_evaluation' => $plan['municipal'],
                'seller_name' => '[DEMO] Property Owner',
                'notes' => 'Demonstration commercial evaluation for CoreX OS presentation purposes.',
                'recommended_range_low' => $range[0] ?? null,
                'recommended_range_mid' => $range[1] ?? null,
                'recommended_range_high' => $range[2] ?? null,
                'primary_method' => $plan['method'],
                'evaluated_at' => $plan['status'] !== 'draft' ? now()->subDays(random_int(5, 40)) : null,
            ]);
            $created++;
        }

        return ['created' => $created];
    }
}
