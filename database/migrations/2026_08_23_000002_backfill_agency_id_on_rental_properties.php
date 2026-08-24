<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `rental_properties.agency_id` via `rental_properties.created_by`
 * → `users.agency_id`.
 *
 * `rental_properties` has no branch_id and no landlord/contact FK into an
 * already-scoped table — `created_by` (nullable unsignedBigInteger, FK-less,
 * set from auth()->id() in RentalPropertyController@store) is the only
 * column that ties an existing row back to an agency. This mirrors the
 * `contacts.agency_id via users.agency_id through created_by_user_id`
 * backfill in 2026_04_14_100000_add_agency_id_to_tenant_tables.php.
 *
 * Rows with no created_by, or a created_by pointing at a user with no
 * agency_id (e.g. a System Owner), are left NULL and reported — the
 * follow-up NOT NULL migration refuses to advance if any remain.
 */
return new class extends Migration {
    public function up(): void
    {
        $affected = DB::update(
            'UPDATE rental_properties rp '
            . 'INNER JOIN users u ON u.id = rp.created_by '
            . 'SET rp.agency_id = u.agency_id '
            . 'WHERE rp.agency_id IS NULL AND u.agency_id IS NOT NULL'
        );

        $stillNull = DB::table('rental_properties')->whereNull('agency_id')->count();

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "    -> rental_properties backfill: set agency_id via created_by/users on {$affected} row(s) (still-null: {$stillNull})" . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Reverse the backfill so the "add column" migration's down() can
        // drop the column cleanly.
        DB::table('rental_properties')->update(['agency_id' => null]);
    }
};
