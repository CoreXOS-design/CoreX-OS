<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred foreign keys for the three migrations whose FK target table is
 * created by a LATER-dated migration than the FK-adding migration itself
 * — the "genuinely can't build from scratch" class of ordering defect
 * (as opposed to the cosmetic ->after() column-position class, fixed
 * separately by just dropping the position hint):
 *
 *   rental_inspection_room_notes.property_room_id -> property_rooms
 *     (column added 2026_09_21_160100, table created 2026_09_30_100400)
 *   rental_inspection_photos.property_room_id -> property_rooms
 *     (column added 2026_09_22_140000, table created 2026_09_30_100400)
 *   rental_fault_report_updates.rental_fault_report_id -> rental_fault_reports
 *     (column added 2026_09_22_100000, table created 2026_09_25_100000)
 *
 * Each of those three now creates its COLUMN only, no FK constraint — a
 * fresh migrate:fresh (filename order) would otherwise fail with "Failed
 * to open the referenced table" before the referenced table exists. This
 * migration adds the missing constraint once its target genuinely
 * exists — this file is dated after all three targets and after
 * rental_inspection_items.property_room_id (2026_09_30_100500), which
 * room_and_tray_support's own backfill also needed (see that file).
 *
 * Every add is guarded by a REAL information_schema check, never a
 * try/catch. QA1, Staging and live already have all three constraints —
 * applied out of band, over time, in the correct real order, long before
 * this fix (see the schema-comparison in this build's own report). On
 * every environment that already has one, the guard finds it and skips;
 * only a genuinely fresh environment — where the constraint has never
 * existed — actually adds it. Nothing here is a rename, so no
 * migrations-table entry anywhere is disturbed by this file.
 *
 * Column type, nullability, cascade behaviour, and constraint/index names
 * are UNCHANGED from what the three original migrations specified —
 * verified directly against QA1's live information_schema before writing
 * this file, not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->constraintExists('rental_inspection_room_notes', 'rental_inspection_room_notes_property_room_id_foreign')) {
            Schema::table('rental_inspection_room_notes', function (Blueprint $table) {
                $table->foreign('property_room_id', 'rental_inspection_room_notes_property_room_id_foreign')
                    ->references('id')->on('property_rooms')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->constraintExists('rental_inspection_photos', 'ri_photos_room_fk')) {
            Schema::table('rental_inspection_photos', function (Blueprint $table) {
                $table->foreign('property_room_id', 'ri_photos_room_fk')
                    ->references('id')->on('property_rooms')
                    ->nullOnDelete();
            });
        }

        if (! $this->constraintExists('rental_fault_report_updates', 'rfru_fault_report_fk')) {
            Schema::table('rental_fault_report_updates', function (Blueprint $table) {
                $table->foreign('rental_fault_report_id', 'rfru_fault_report_fk')
                    ->references('id')->on('rental_fault_reports')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->constraintExists('rental_inspection_room_notes', 'rental_inspection_room_notes_property_room_id_foreign')) {
            Schema::table('rental_inspection_room_notes', function (Blueprint $table) {
                $table->dropForeign('rental_inspection_room_notes_property_room_id_foreign');
            });
        }

        if ($this->constraintExists('rental_inspection_photos', 'ri_photos_room_fk')) {
            Schema::table('rental_inspection_photos', function (Blueprint $table) {
                $table->dropForeign('ri_photos_room_fk');
            });
        }

        if ($this->constraintExists('rental_fault_report_updates', 'rfru_fault_report_fk')) {
            Schema::table('rental_fault_report_updates', function (Blueprint $table) {
                $table->dropForeign('rfru_fault_report_fk');
            });
        }
    }

    /**
     * A real information_schema check — never a try/catch swallowing the
     * error. Swallowing here would hide a genuinely broken fresh build
     * (wrong column type, wrong referenced table, an actual problem)
     * behind the same silence as an already-applied constraint — exactly
     * the failure mode this whole fix exists to make loud, not quiet.
     */
    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $constraint]
        );

        return $row !== null;
    }
};
