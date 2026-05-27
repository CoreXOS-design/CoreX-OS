<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Use first existing user (you re-registered), else create one
        $agent = User::orderBy('id')->first();
        if (!$agent) {
            $agent = User::create([
                'name' => 'Demo Agent',
                'email' => 'agent@test.local',
                'password' => Hash::make('Password123!'),
            ]);
        }

        // Force agent role for demo
        $agent->role = 'agent';
        $agent->save();

        $period = '2026-01';

        // ---- Ensure branch exists ----
        $branchId = DB::table('branches')->value('id');
        if (!$branchId) {
            $branchId = DB::table('branches')->insertGetId([
                'name' => 'Demo Branch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign branch_id if users table has it
        $db = DB::getDatabaseName();
        $userCols = collect(DB::select(
            "SELECT COLUMN_NAME as name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$db, 'users']
        ))->pluck('name')->all();
        if (in_array('branch_id', $userCols, true)) {
            DB::table('users')->where('id', $agent->id)->update([
                'branch_id' => $branchId,
                'updated_at' => now(),
            ]);
        }

        // Helper: only insert columns that exist
        $cols = function(string $table) {
            $db = DB::getDatabaseName();
            return collect(DB::select(
                "SELECT COLUMN_NAME as name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                [$db, $table]
            ))->pluck('name')->all();
        };
        // Wave 3b: auto-stamp agency_id where the table requires it.
        $demoAgencyId = (int) (DB::table('agencies')->value('id') ?? 1);
        $filter = function(array $data, array $columns) use ($demoAgencyId) {
            if (in_array('agency_id', $columns, true) && !array_key_exists('agency_id', $data)) {
                $data['agency_id'] = $demoAgencyId;
            }
            return array_intersect_key($data, array_flip($columns));
        };

        // ---- Targets (schema: period/user_id/branch_id/listings_target/deals_target/value_target) ----
        $targetCols = $cols('targets');
        if (in_array('user_id', $targetCols, true) && in_array('period', $targetCols, true)) {
            DB::table('targets')->updateOrInsert(
                ['user_id' => $agent->id, 'period' => $period],
                $filter([
                    'period' => $period,
                    'user_id' => $agent->id,
                    'branch_id' => in_array('branch_id', $targetCols, true) ? $branchId : null,
                    'listings_target' => 5,
                    'deals_target' => 3,
                    'value_target' => 4500000,
                    'notes' => 'Demo seeded targets',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $targetCols)
            );
        }

        // ---- Deals (idempotent by file_no) ----
        $dealCols = $cols('deals');

        $upsertDealByFileNo = function(array $data) use ($dealCols, $filter) {
            $fileNo = $data['file_no'] ?? null;
            if (!$fileNo) { throw new \Exception('file_no required for demo deal upsert'); }
            DB::table('deals')->updateOrInsert(
                ['file_no' => $fileNo],
                $filter($data, $dealCols)
            );
            return (int) DB::table('deals')->where('file_no', $fileNo)->value('id');
        };

        $dealBase = [
            'period' => $period,
            'deal_date' => '2026-01-10',
            'property_value' => 1850000,
            'total_commission' => 92500,

            'listing_external' => 0,
            'listing_our_share_percent' => 100,
            'selling_external' => 0,
            'selling_our_share_percent' => 100,

            'file_no' => 'FILE-001',
            'branch_id' => $branchId,
            'property_address' => '123 Coastal Rd',
            'seller_name' => 'Seller One',
            'buyer_name' => 'Buyer One',
            'attorney_name' => 'Demo Attorneys',
            'accepted_status' => 'G',
            'commission_status' => 'Not Paid',
            'remarks' => 'Demo deal 1',

            'created_at' => now(),
            'updated_at' => now(),
        ];

        $deal1 = $upsertDealByFileNo($dealBase);

        $dealBase2 = $dealBase;
        $dealBase2['deal_date'] = '2026-01-18';
        $dealBase2['property_value'] = 2200000;
        $dealBase2['total_commission'] = 110000;
        $dealBase2['file_no'] = 'FILE-002';
        // deal_no omitted (unsignedInteger, nullable)
        $dealBase2['property_address'] = '77 Ocean View';
        $dealBase2['seller_name'] = 'Seller Two';
        $dealBase2['buyer_name'] = 'Buyer Two';
        $dealBase2['remarks'] = 'Demo deal 2';

        $deal2 = $upsertDealByFileNo($dealBase2);

        // ---- deal_user (schema requires side + split/cut/paye/deductions etc) ----
        $duCols = $cols('deal_user');

        $row1 = [
            'deal_id' => $deal1,
            'user_id' => $agent->id,
            'side' => 'listing',
            'agent_split_percent' => 50,
            'agent_cut_percent' => 50,
            'paye_method' => 'none',
            'paye_value' => 0,
            'deductions' => 0,
            'deductions_description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $row2 = [
            'deal_id' => $deal2,
            'user_id' => $agent->id,
            'side' => 'selling',
            'agent_split_percent' => 50,
            'agent_cut_percent' => 50,
            'paye_method' => 'none',
            'paye_value' => 0,
            'deductions' => 0,
            'deductions_description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // insertOrIgnore is fine; if duplicates happen, it won't crash
        DB::table('deal_user')->insertOrIgnore([
            $filter($row1, $duCols),
            $filter($row2, $duCols),
        ]);

        // ---- Daily activities (period is NOT NULL in your schema) ----
        $dailyCols = $cols('daily_activities');

        // Make it idempotent-ish by clearing existing demo days for this user+period (safe scope)
        if (in_array('user_id', $dailyCols, true) && in_array('period', $dailyCols, true) && in_array('activity_date', $dailyCols, true)) {
            DB::table('daily_activities')
                ->where('user_id', $agent->id)
                ->where('period', $period)
                ->whereBetween('activity_date', ['2026-01-11', '2026-01-15'])
                ->delete();
        }

        for ($i = 0; $i < 5; $i++) {
            $day = str_pad((string)(11 + $i), 2, '0', STR_PAD_LEFT);
            $row = [
                'user_id' => $agent->id,
                'period' => $period,
                'activity_date' => "2026-01-$day",
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('daily_activities')->insert($filter($row, $dailyCols));
        }

        echo "DEMO SEEDED: user_id={$agent->id}, period={$period}, deals=2, deal_user=2, daily=5, targets=1\n";
    }
}
