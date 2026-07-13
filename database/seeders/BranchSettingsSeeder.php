<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Per-branch letterhead settings (branch_settings table).
 *
 * Each branch needs four canonical letterhead values used by document /
 * presentation rendering:
 *   - company_name
 *   - company_address
 *   - company_tel
 *   - company_ffc
 *
 * Shape captured from local nexus_os: 3 branches × ~4 keys = 12 rows.
 * Idempotent (DB::table->updateOrInsert keyed on branch_id + key).
 */
class BranchSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::withoutGlobalScopes()->whereNull('deleted_at')->get(['id', 'agency_id', 'name']);

        $companyName = 'Home Finders Coastal';
        $companyTel  = '039 315 0857';
        $companyFfc  = '2023116041';

        $addressByBranch = [
            // Per-branch address fragments. Matches the demo's TOWN_SUBURBS
            // (Margate, Shelly Beach, Port Shepstone). Any other branch
            // name falls through to a generic address line.
            'Margate'        => 'Home Finders Coastal, Margate branch, KZN South Coast',
            'Shelly Beach'   => 'Home Finders Coastal, Shop 5 The Emporium, 978 Kings Road, Shelly Beach',
            'Port Shepstone' => 'Home Finders Coastal, Gilbert on Point, Port Shepstone',
        ];

        $rows = 0;
        foreach ($branches as $b) {
            $address = $addressByBranch[$b->name] ?? ('Home Finders Coastal, ' . $b->name . ' branch');

            $kv = [
                'company_name'    => $companyName,
                'company_address' => $address,
                'company_tel'     => $companyTel,
                'company_ffc'     => $companyFfc,
            ];

            foreach ($kv as $key => $value) {
                DB::table('branch_settings')->updateOrInsert(
                    ['branch_id' => $b->id, 'key' => $key],
                    [
                        // branch_settings.agency_id is NOT NULL with no default.
                        // updateOrInsert only supplies the match keys + these
                        // values on INSERT, so omitting agency_id worked solely
                        // against databases where the rows already existed (the
                        // UPDATE path). On a fresh schema every insert failed with
                        // "Field 'agency_id' doesn't have a default value" — and
                        // because the demo wraps this seeder in safeSeed(), the
                        // failure was swallowed as a warning and branch letterhead
                        // silently never seeded. A branch's settings belong to that
                        // branch's agency by definition.
                        'agency_id'  => $b->agency_id,
                        'value'      => $value,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $rows++;
            }
        }

        $this->command?->info('Seeded ' . $rows . ' branch_settings rows across ' . $branches->count() . ' branches.');
    }
}
