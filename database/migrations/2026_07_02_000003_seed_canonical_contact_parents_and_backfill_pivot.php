<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-79 — canonical 4 parents + non-destructive pivot backfill.
 *
 * This migration is intentionally ADDITIVE and SAFE to run unattended on prod:
 *   1. Guarantees the four fixed parents exist with the correct esign_role.
 *   2. Mirrors each contact's existing single contact_type_id into the new
 *      contact_contact_type pivot, BUT ONLY when that type is one of the four
 *      canonical parents.
 *
 * It does NOT touch, merge, or delete any "extra" contact types and does NOT
 * re-point contacts that currently sit on an extra type. That destructive
 * normalisation (extras -> sub-tags / drop zero-contact orphans) is the
 * reviewed `contacts:normalise-types` command, run after a signed-off dry-run.
 */
return new class extends Migration
{
    /** name => esign_role, in display/sort order. */
    private array $canonical = [
        'Seller' => 'seller',
        'Buyer'  => 'buyer',
        'Lessor' => 'lessor',
        'Lessee' => 'lessee',
    ];

    public function up(): void
    {
        $now = now();
        $sort = 1;

        foreach ($this->canonical as $name => $role) {
            // Prefer an existing live row matching the esign_role (authoritative),
            // then by canonical name, before creating a fresh one. This avoids
            // duplicating a parent that already exists under either key.
            $existing = DB::table('contact_types')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($name, $role) {
                    $q->where('esign_role', $role)->orWhere('name', $name);
                })
                ->orderByRaw('CASE WHEN esign_role = ? THEN 0 ELSE 1 END', [$role])
                ->orderBy('id')
                ->first();

            if ($existing) {
                DB::table('contact_types')->where('id', $existing->id)->update([
                    'name'       => $name,
                    'esign_role' => $role,
                    'is_active'  => 1,
                    'sort_order' => $sort,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('contact_types')->insert([
                    'name'       => $name,
                    'esign_role' => $role,
                    'color'      => '#6366f1',
                    'is_active'  => 1,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $sort++;
        }

        // Backfill the pivot from the existing single-type mirror — only for the
        // four canonical parents. Skip rows already present (idempotent).
        $canonicalIds = DB::table('contact_types')
            ->whereNull('deleted_at')
            ->whereIn('esign_role', array_values($this->canonical))
            ->pluck('id')
            ->all();

        if (!empty($canonicalIds)) {
            DB::table('contacts')
                ->whereNull('deleted_at')
                ->whereIn('contact_type_id', $canonicalIds)
                ->orderBy('id')
                ->chunkById(500, function ($contacts) use ($now) {
                    $rows = [];
                    foreach ($contacts as $c) {
                        $rows[] = [
                            'contact_id'      => $c->id,
                            'contact_type_id' => $c->contact_type_id,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                    }
                    if ($rows) {
                        // insertOrIgnore respects the (contact_id, contact_type_id) unique key.
                        DB::table('contact_contact_type')->insertOrIgnore($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        // Non-destructive up() — nothing to reverse beyond the pivot rows it
        // added, which are removed when the pivot table is dropped by its own
        // migration. Parents are left in place (other data references them).
    }
};
