<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class MultiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $period = '2026-01';

        $cols = function(string $table) {
            $db = DB::getDatabaseName();
            return collect(DB::select(
                "SELECT COLUMN_NAME as name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                [$db, $table]
            ))->pluck('name')->all();
        };
        // Wave 3b: auto-stamp agency_id where the table requires it.
        // Demo seeder works against a single demo agency; resolve it once here.
        $demoAgencyId = (int) (DB::table('agencies')->value('id') ?? 1);
        $filter = function(array $data, array $columns) use ($demoAgencyId) {
            if (in_array('agency_id', $columns, true) && !array_key_exists('agency_id', $data)) {
                $data['agency_id'] = $demoAgencyId;
            }
            return array_intersect_key($data, array_flip($columns));
        };

        // Branch 1
        $branch1 = DB::table('branches')->where('name','Demo Branch')->value('id');
        if (!$branch1) {
            $branch1 = DB::table('branches')->insertGetId([
                'name' => 'Demo Branch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Branch 2
        $branch2 = DB::table('branches')->where('name','Demo Branch 2')->value('id');
        if (!$branch2) {
            $branch2 = DB::table('branches')->insertGetId([
                'name' => 'Demo Branch 2',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Users
        $usersCols = $cols('users');

        $ensureUser = function(string $email, string $name, string $role) {
            $u = User::where('email',$email)->first();
            if (!$u) {
                $u = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make('Password123!'),
                ]);
            }
            $u->role = $role;
            $u->save();
            return $u;
        };

        // Admin (Johan)
        $admin = $ensureUser('johan@hfcoastal.co.za','Johan (Admin)','admin');
        if (in_array('is_admin', $usersCols, true)) {
            DB::table('users')->where('id',$admin->id)->update(['is_admin'=>1,'updated_at'=>now()]);
        }

        // Branch managers
        $bm1 = $ensureUser('bm@demo.local','Demo Branch Manager','branch_manager');
        $bm2 = $ensureUser('bm2@demo.local','Demo Branch Manager 2','branch_manager');

        // Agents
        $a1 = $ensureUser('agent1@demo.local','Demo Agent 1','agent');
        $a2 = $ensureUser('agent2@demo.local','Demo Agent 2','agent');
        $a3 = $ensureUser('agent3@demo.local','Demo Agent 3','agent'); // will be moved to branch2
        $a4 = $ensureUser('agent4@demo.local','Demo Agent 4','agent'); // branch2

        // Assign branch_id if present
        if (in_array('branch_id', $usersCols, true)) {
            DB::table('users')->where('id',$bm1->id)->update(['branch_id'=>$branch1,'updated_at'=>now()]);
            DB::table('users')->where('id',$bm2->id)->update(['branch_id'=>$branch2,'updated_at'=>now()]);

            DB::table('users')->where('id',$a1->id)->update(['branch_id'=>$branch1,'updated_at'=>now()]);
            DB::table('users')->where('id',$a2->id)->update(['branch_id'=>$branch1,'updated_at'=>now()]);
            DB::table('users')->where('id',$a3->id)->update(['branch_id'=>$branch2,'updated_at'=>now()]);
            DB::table('users')->where('id',$a4->id)->update(['branch_id'=>$branch2,'updated_at'=>now()]);
        }

        // Targets
        $targetsCols = $cols('targets');
        $upsertTarget = function(User $u, int $branchId, int $deals, int $listings, int $value) use ($targetsCols, $filter, $period) {
            if (!in_array('user_id',$targetsCols,true) || !in_array('period',$targetsCols,true)) return;

            DB::table('targets')->updateOrInsert(
                ['user_id'=>$u->id,'period'=>$period],
                $filter([
                    'period'=>$period,
                    'user_id'=>$u->id,
                    'branch_id'=>in_array('branch_id',$targetsCols,true) ? $branchId : null,
                    'deals_target'=>$deals,
                    'listings_target'=>$listings,
                    'value_target'=>$value,
                    'notes'=>'Multi demo targets',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ], $targetsCols)
            );
        };

        $upsertTarget($a1, $branch1, 3, 5, 4500000);
        $upsertTarget($a2, $branch1, 2, 4, 3200000);

        // branch2 targets
        $upsertTarget($a3, $branch2, 4, 6, 6000000);
        $upsertTarget($a4, $branch2, 1, 3, 2000000);

        // Deals (idempotent)
        $dealCols = $cols('deals');
        $upsertDealByFileNo = function(array $data) use ($dealCols, $filter) {
            $fileNo = $data['file_no'] ?? null;
            if (!$fileNo) { throw new \Exception('file_no required'); }
            DB::table('deals')->updateOrInsert(['file_no'=>$fileNo], $filter($data,$dealCols));
            return (int) DB::table('deals')->where('file_no',$fileNo)->value('id');
        };

        $makeDeal = function(string $fileNo, string $dealNo, string $date, int $value, int $commission, string $addr, int $branchId, string $remarks) use ($period) {
            return [
                'period'=>$period,
                'deal_date'=>$date,
                'property_value'=>$value,
                'total_commission'=>$commission,
                'listing_external'=>0,
                'listing_our_share_percent'=>100,
                'selling_external'=>0,
                'selling_our_share_percent'=>100,
                'file_no'=>$fileNo,
                'branch_id'=>$branchId,
                'property_address'=>$addr,
                'seller_name'=>'Demo Seller',
                'buyer_name'=>'Demo Buyer',
                'attorney_name'=>'Demo Attorneys',
                'accepted_status'=>'G',
                'commission_status'=>'Not Paid',
                'remarks'=>$remarks,
                'created_at'=>now(),
                'updated_at'=>now(),
            ];
        };

        // Branch 1 deals
        $d1 = $upsertDealByFileNo($makeDeal('B1-A1-FILE-001','B1-A1-001','2026-01-10',1850000,92500,'123 Coastal Rd',$branch1,'A1 deal 1'));
        $d2 = $upsertDealByFileNo($makeDeal('B1-A1-FILE-002','B1-A1-002','2026-01-18',2200000,110000,'77 Ocean View',$branch1,'A1 deal 2'));
        $d3 = $upsertDealByFileNo($makeDeal('B1-A2-FILE-001','B1-A2-001','2026-01-12',1650000,82500,'9 Palm St',$branch1,'A2 deal 1'));

        // Branch 2 deals
        $d4 = $upsertDealByFileNo($makeDeal('B2-A3-FILE-001','B2-A3-001','2026-01-14',2500000,125000,'55 Lagoon Dr',$branch2,'A3 deal 1'));
        $d5 = $upsertDealByFileNo($makeDeal('B2-A4-FILE-001','B2-A4-001','2026-01-20',1950000,97500,'8 Ridge Way',$branch2,'A4 deal 1'));

        // deal_user links (required side)
        $duCols = $cols('deal_user');
        $link = function(int $dealId, User $u, string $side) use ($duCols, $filter) {
            DB::table('deal_user')->insertOrIgnore([
                $filter([
                    'deal_id'=>$dealId,
                    'user_id'=>$u->id,
                    'side'=>$side,
                    'agent_split_percent'=>50,
                    'agent_cut_percent'=>50,
                    'paye_method'=>'none',
                    'paye_value'=>0,
                    'deductions'=>0,
                    'deductions_description'=>null,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ], $duCols)
            ]);
        };

        $link($d1,$a1,'listing');
        $link($d2,$a1,'selling');
        $link($d3,$a2,'listing');

        $link($d4,$a3,'listing');
        $link($d5,$a4,'selling');

        // Daily activities (period NOT NULL)
        $dailyCols = $cols('daily_activities');
        $seedDaily = function(User $u, array $days) use ($dailyCols, $filter, $period) {
            foreach ($days as $d) {
                DB::table('daily_activities')->insertOrIgnore([
                    $filter([
                        'user_id'=>$u->id,
                        'period'=>$period,
                        'activity_date'=>$d,
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ], $dailyCols)
                ]);
            }
        };

        // Branch1
        $seedDaily($a1, ['2026-01-11','2026-01-12','2026-01-13','2026-01-14','2026-01-15']);
        $seedDaily($a2, ['2026-01-08','2026-01-09','2026-01-10']);

        // Branch2
        $seedDaily($a3, ['2026-01-05','2026-01-06','2026-01-07','2026-01-08']);
        $seedDaily($a4, ['2026-01-16','2026-01-17']);

        echo "MULTI DEMO SEEDED: branches={$branch1},{$branch2} BM={$bm1->id},{$bm2->id} agents={$a1->id},{$a2->id},{$a3->id},{$a4->id}\n";
echo "Logins (Password123!): bm@demo.local (branch1), bm2@demo.local (branch2), agent1-4@demo.local\n";


    }
}
