<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RichDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::transaction(function () use ($now) {
            // -----------------------------
            // 1) Branches (idempotent)
            // -----------------------------
            $branches = [
                ['name' => 'Demo Branch 1'],
                ['name' => 'Demo Branch 2'],
            ];

            foreach ($branches as $b) {
                DB::table('branches')->updateOrInsert(
                    ['name' => $b['name']],
                    [
                        'name' => $b['name'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $branch1Id = DB::table('branches')->where('name', 'Demo Branch 1')->value('id');
            $branch2Id = DB::table('branches')->where('name', 'Demo Branch 2')->value('id');

            // -----------------------------
            // 2) Users (BMs + Agents)
            // -----------------------------
            $demoPassword = Hash::make('demo12345');

            $users = [
                // Branch Managers
                [
                    'email' => 'bm1@demo.local',
                    'name'  => 'BM 1',
                    'role'  => 'branch_manager',
                    'branch_id' => $branch1Id,
                ],
                [
                    'email' => 'bm2@demo.local',
                    'name'  => 'BM 2',
                    'role'  => 'branch_manager',
                    'branch_id' => $branch2Id,
                ],

                // Agents - Branch 1: agent1..agent4
                ['email' => 'agent1@demo.local', 'name' => 'Agent 1', 'role' => 'agent', 'branch_id' => $branch1Id],
                ['email' => 'agent2@demo.local', 'name' => 'Agent 2', 'role' => 'agent', 'branch_id' => $branch1Id],
                ['email' => 'agent3@demo.local', 'name' => 'Agent 3', 'role' => 'agent', 'branch_id' => $branch1Id],
                ['email' => 'agent4@demo.local', 'name' => 'Agent 4', 'role' => 'agent', 'branch_id' => $branch1Id],

                // Agents - Branch 2: agent5..agent6
                ['email' => 'agent5@demo.local', 'name' => 'Agent 5', 'role' => 'agent', 'branch_id' => $branch2Id],
                ['email' => 'agent6@demo.local', 'name' => 'Agent 6', 'role' => 'agent', 'branch_id' => $branch2Id],
            ];

            foreach ($users as $u) {
                DB::table('users')->updateOrInsert(
                    ['email' => $u['email']],
                    [
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'role' => $u['role'],
                        'branch_id' => $u['branch_id'],
                        'password' => $demoPassword,
                        'remember_token' => Str::random(10),

                        // Safe defaults for NOT NULL columns in users
                        'is_admin' => 0,
                        'target_listings' => 0,
                        'is_active' => 1,
                        'sliding_enabled' => 0,

                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $agentEmails = [
                'agent1@demo.local',
                'agent2@demo.local',
                'agent3@demo.local',
                'agent4@demo.local',
                'agent5@demo.local',
                'agent6@demo.local',
            ];

            $agents = DB::table('users')
                ->whereIn('email', $agentEmails)
                ->select('id', 'email', 'branch_id', 'agency_id')
                ->get()
                ->keyBy('email');

            // -----------------------------
            // 3) Targets for 3 periods per agent (idempotent)
            // -----------------------------
            $periods = ['2025-12', '2026-01', '2026-02'];

            $targetsMatrix = [
                'agent1@demo.local' => [
                    '2025-12' => [8, 2, 2200000],
                    '2026-01' => [10, 3, 3500000],
                    '2026-02' => [9, 2, 2800000],
                ],
                'agent2@demo.local' => [
                    '2025-12' => [6, 1, 1400000],
                    '2026-01' => [7, 2, 2000000],
                    '2026-02' => [8, 2, 2400000],
                ],
                'agent3@demo.local' => [
                    '2025-12' => [5, 1, 1200000],
                    '2026-01' => [6, 1, 1500000],
                    '2026-02' => [7, 2, 2100000],
                ],
                // agent4 intentionally weaker + will have 0 deals
                'agent4@demo.local' => [
                    '2025-12' => [4, 0, 800000],
                    '2026-01' => [5, 1, 1200000],
                    '2026-02' => [5, 1, 1300000],
                ],
                'agent5@demo.local' => [
                    '2025-12' => [7, 2, 2600000],
                    '2026-01' => [8, 2, 3000000],
                    '2026-02' => [9, 3, 4200000],
                ],
                'agent6@demo.local' => [
                    '2025-12' => [6, 1, 1800000],
                    '2026-01' => [7, 2, 2400000],
                    '2026-02' => [7, 2, 2600000],
                ],
            ];

            foreach ($agentEmails as $email) {
                $agent = $agents[$email] ?? null;
                if (!$agent) continue;

                foreach ($periods as $p) {
                    [$listingsTarget, $dealsTarget, $valueTarget] = $targetsMatrix[$email][$p];

                    DB::table('targets')->updateOrInsert(
                        [
                            'user_id' => $agent->id,
                            'period'  => $p,
                        ],
                        [
                            'user_id' => $agent->id,
                            'agency_id' => $agent->agency_id ?? 1,
                            'branch_id' => $agent->branch_id,
                            'period' => $p,
                            'listings_target' => $listingsTarget,
                            'deals_target' => $dealsTarget,
                            'value_target' => $valueTarget,
                            'notes' => 'Rich demo targets (idempotent)',
                            'created_by' => null,
                            'updated_by' => null,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }

            // -----------------------------
            // 4) Deals across periods (idempotent via file_no)
            // deals.period is NOT NULL in your schema
            // deals.total_commission is NOT NULL in your schema
            // -----------------------------
            $deals = [
                // file_no, deal_date, property_value, branch_id
                ['file_no' => 'DEMO-202512-001', 'deal_date' => '2025-12-03', 'property_value' => 1150000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202512-002', 'deal_date' => '2025-12-10', 'property_value' => 1890000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202512-003', 'deal_date' => '2025-12-18', 'property_value' => 2450000, 'branch_id' => $branch2Id],
                ['file_no' => 'DEMO-202512-004', 'deal_date' => '2025-12-22', 'property_value' => 980000,  'branch_id' => $branch1Id],

                ['file_no' => 'DEMO-202601-001', 'deal_date' => '2026-01-05', 'property_value' => 1650000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202601-002', 'deal_date' => '2026-01-11', 'property_value' => 3100000, 'branch_id' => $branch2Id],
                ['file_no' => 'DEMO-202601-003', 'deal_date' => '2026-01-15', 'property_value' => 2250000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202601-004', 'deal_date' => '2026-01-21', 'property_value' => 4200000, 'branch_id' => $branch2Id],
                ['file_no' => 'DEMO-202601-005', 'deal_date' => '2026-01-27', 'property_value' => 1350000, 'branch_id' => $branch1Id],

                ['file_no' => 'DEMO-202602-001', 'deal_date' => '2026-02-02', 'property_value' => 2750000, 'branch_id' => $branch2Id],
                ['file_no' => 'DEMO-202602-002', 'deal_date' => '2026-02-07', 'property_value' => 990000,  'branch_id' => $branch2Id],
                ['file_no' => 'DEMO-202602-003', 'deal_date' => '2026-02-14', 'property_value' => 3650000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202602-004', 'deal_date' => '2026-02-19', 'property_value' => 1550000, 'branch_id' => $branch1Id],
                ['file_no' => 'DEMO-202602-005', 'deal_date' => '2026-02-24', 'property_value' => 5100000, 'branch_id' => $branch2Id],
            ];

            foreach ($deals as $d) {
                $period = substr($d['deal_date'], 0, 7); // YYYY-MM

                // Deterministic demo commission: 5% of property_value
                $totalCommission = round(((float)$d['property_value']) * 0.05, 2);

                DB::table('deals')->updateOrInsert(
                    ['file_no' => $d['file_no']],
                    [
                        'file_no' => $d['file_no'],
                        'period' => $period,
                        'deal_date' => $d['deal_date'],
                        'property_value' => $d['property_value'],
                        'total_commission' => $totalCommission,
                        'branch_id' => $d['branch_id'],

                        // ensure NOT NULLs are satisfied (these have defaults, but we keep it explicit-safe)
                        'listing_external' => 0,
                        'listing_our_share_percent' => 100,
                        'selling_external' => 0,
                        'selling_our_share_percent' => 100,

                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $dealIds = DB::table('deals')->whereIn('file_no', array_column($deals, 'file_no'))
                ->pluck('id', 'file_no');

            // -----------------------------
            // 5) deal_user links (idempotent by deal_id+user_id+side)
            // -----------------------------
            $links = [
                // 2025-12
                ['file_no' => 'DEMO-202512-001', 'email' => 'agent1@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202512-001', 'email' => 'agent2@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202512-002', 'email' => 'agent1@demo.local', 'side' => 'selling'],
                ['file_no' => 'DEMO-202512-002', 'email' => 'agent3@demo.local', 'side' => 'listing'],

                ['file_no' => 'DEMO-202512-003', 'email' => 'agent5@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202512-003', 'email' => 'agent6@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202512-004', 'email' => 'agent2@demo.local', 'side' => 'listing'],

                // 2026-01 (agent1 heavier)
                ['file_no' => 'DEMO-202601-001', 'email' => 'agent1@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202601-001', 'email' => 'agent6@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202601-002', 'email' => 'agent1@demo.local', 'side' => 'selling'],
                ['file_no' => 'DEMO-202601-002', 'email' => 'agent5@demo.local', 'side' => 'listing'],

                ['file_no' => 'DEMO-202601-003', 'email' => 'agent1@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202601-003', 'email' => 'agent2@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202601-004', 'email' => 'agent5@demo.local', 'side' => 'selling'],
                ['file_no' => 'DEMO-202601-004', 'email' => 'agent3@demo.local', 'side' => 'listing'],

                ['file_no' => 'DEMO-202601-005', 'email' => 'agent2@demo.local', 'side' => 'listing'],

                // 2026-02 (agent5 heavier)
                ['file_no' => 'DEMO-202602-001', 'email' => 'agent5@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202602-001', 'email' => 'agent1@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202602-002', 'email' => 'agent6@demo.local', 'side' => 'listing'],

                ['file_no' => 'DEMO-202602-003', 'email' => 'agent5@demo.local', 'side' => 'selling'],
                ['file_no' => 'DEMO-202602-003', 'email' => 'agent2@demo.local', 'side' => 'listing'],

                ['file_no' => 'DEMO-202602-004', 'email' => 'agent3@demo.local', 'side' => 'selling'],

                ['file_no' => 'DEMO-202602-005', 'email' => 'agent5@demo.local', 'side' => 'listing'],
                ['file_no' => 'DEMO-202602-005', 'email' => 'agent6@demo.local', 'side' => 'selling'],
            ];

            foreach ($links as $l) {
                $dealId = $dealIds[$l['file_no']] ?? null;
                $agent  = $agents[$l['email']] ?? null;
                if (!$dealId || !$agent) continue;

                DB::table('deal_user')->updateOrInsert(
                    [
                        'deal_id' => $dealId,
                        'user_id' => $agent->id,
                        'side'    => $l['side'],
                    ],
                    [
                        'deal_id' => $dealId,
                        'user_id' => $agent->id,
                        'side'    => $l['side'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            // -----------------------------
            // 6) Daily activities (idempotent by user_id+activity_date+period)
            // daily_activities.period is NOT NULL (you confirmed)
            // Many metric columns are NOT NULL with default 0, we leave them to defaults.
            // -----------------------------
            $activityPlan = [
                'agent1@demo.local' => ['2025-12' => 8, '2026-01' => 10, '2026-02' => 7],
                'agent2@demo.local' => ['2025-12' => 5, '2026-01' => 7,  '2026-02' => 6],
                'agent3@demo.local' => ['2025-12' => 4, '2026-01' => 5,  '2026-02' => 5],
                'agent4@demo.local' => ['2025-12' => 2, '2026-01' => 3,  '2026-02' => 2],
                'agent5@demo.local' => ['2025-12' => 6, '2026-01' => 6,  '2026-02' => 9],
                'agent6@demo.local' => ['2025-12' => 3, '2026-01' => 4,  '2026-02' => 4],
            ];

            foreach ($activityPlan as $email => $countsByPeriod) {
                $agent = $agents[$email] ?? null;
                if (!$agent) continue;

                foreach ($countsByPeriod as $period => $count) {
                    [$y, $m] = explode('-', $period);
                    $day = 1;

                    for ($i = 0; $i < $count; $i++) {
                        $date = Carbon::createFromDate((int)$y, (int)$m, min(28, $day))->format('Y-m-d');
                        $day += 2;

                        DB::table('daily_activities')->updateOrInsert(
                            [
                                'user_id' => $agent->id,
                                'activity_date' => $date,
                                'period' => $period,
                            ],
                            [
                                'user_id' => $agent->id,
                                'agency_id' => $agent->agency_id ?? 1,
                                'activity_date' => $date,
                                'period' => $period,
                                'branch_id' => $agent->branch_id,
                                'updated_at' => $now,
                                'created_at' => $now,
                            ]
                        );
                    }
                }
            }
        });
    }
}
