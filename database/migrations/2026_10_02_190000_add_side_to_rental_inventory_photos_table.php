<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §14 — Johan's approved move-in-vs-now
 * mockup: "Where there is no photo on the current side, SHOW that rather
 * than hiding it." Every photo captured so far (the whole of §0b-§13) is a
 * MOVE-IN photo — there was no move-out photo concept until now. `side`
 * distinguishes the two without a second table or a second upload
 * pipeline: `move_in` (the default — every existing row is one, by
 * construction) and `move_out` (new — taken from the comparison screen,
 * tagged straight to the line the same way a move-in photo tags to a
 * room). Same `rental_inventory_photos` table, same PropertyImageStorer
 * pipeline, same many-to-many `rental_inventory_line_photos` pivot for
 * tagging — a column, not a fork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->string('side', 20)->default('move_in')->after('property_room_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_photos', function (Blueprint $table) {
            $table->dropColumn('side');
        });
    }
};
