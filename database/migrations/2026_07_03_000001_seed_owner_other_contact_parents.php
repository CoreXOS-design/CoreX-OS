<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-79 follow-up — add two non-e-sign fixed parent types: Owner and Other.
 *
 * These sit alongside the 4 e-sign parents (Seller/Buyer/Lessor/Lessee) as
 * top-level contact types that simply categorise a contact; they carry no
 * esign_role, so they never participate in signing-role resolution. Idempotent:
 * only creates a live row when one doesn't already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $sort = 5; // after the 4 e-sign parents (sort 1-4)

        foreach (['Owner', 'Other'] as $name) {
            $exists = DB::table('contact_types')
                ->whereNull('deleted_at')
                ->where('name', $name)
                ->whereNull('esign_role')
                ->exists();

            if (!$exists) {
                DB::table('contact_types')->insert([
                    'name'       => $name,
                    'esign_role' => null,
                    'color'      => '#6366f1',
                    'is_active'  => 1,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $sort++;
        }
    }

    public function down(): void
    {
        // Soft-remove the two parents (no hard delete). Sub-tags / contacts that
        // reference them are left intact.
        DB::table('contact_types')
            ->whereIn('name', ['Owner', 'Other'])
            ->whereNull('esign_role')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }
};
